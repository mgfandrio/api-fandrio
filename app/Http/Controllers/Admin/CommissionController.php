<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commissions\Commission;
use App\Models\Commissions\Collecte;
use App\Models\Compagnies\Compagnie;
use App\Models\Reservation\Reservation;
use App\Models\Voyages\Voyage;
use App\Models\Paiements\TypePaiement;
use App\Models\Utilisateurs\Utilisateur;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    private float $tauxCommission = 0.05; // 5%

    /**
     * Évolution mois par mois de la plateforme (graphiques de l'accueil admin système).
     *
     * Pour chaque mois des N derniers (défaut 12, mois en cours compris) :
     *   - commission, brut, billets       : réservations EN LIGNE confirmées (res_statut 2, et 6 : annulées par le client sans remboursement) du mois ; le guichet (res_canal 2) est exclu
     *   - clients_nouveaux                : comptes clients créés dans le mois
     *   - clients_actifs                  : clients distincts ayant payé au moins un billet dans le mois
     *   - clients_perdus                  : clients sans achat depuis 3 mois, comptés le mois où ils
     *                                       atteignent ces 3 mois (dernier achat en M-3, rien en M-2, M-1, M)
     *   - compagnies_nouvelles            : compagnies créées dans le mois
     *   - compagnies_actives              : compagnies distinctes ayant vendu au moins un billet dans le mois
     *
     * GET /admin/commissions/evolution?mois=12 — mis en cache 10 minutes.
     */
    public function evolution(Request $request): JsonResponse
    {
        try {
            $nbMois = max(3, min(24, (int) $request->query('mois', 12)));

            $donnees = Cache::remember("admin:evolution:v2:{$nbMois}", 600, function () use ($nbMois) {
                $debut = now()->startOfMonth()->subMonths($nbMois);
                // 3 mois de plus en amont pour les clients perdus des premiers mois affichés
                $debutAchats = $debut->copy()->subMonths(3);
                $res = (new Reservation)->getTable();
                $voy = (new Voyage)->getTable();
                $traj = (new \App\Models\Trajet\Trajet)->getTable();
                $util = (new Utilisateur)->getTable();
                $comp = (new Compagnie)->getTable();
                $mois = "TO_CHAR(%s, 'YYYY-MM')";

                $ventes = DB::table($res)
                    ->whereIn('res_statut', [2, 6])->where('res_canal', 1)->where('created_at', '>=', $debut)
                    ->selectRaw(sprintf($mois, 'created_at') . ' as mois, SUM(montant_total) as brut, SUM(nb_voyageurs) as billets')
                    ->groupBy('mois')->get()->keyBy('mois');

                // Paires (client, mois) d'achat : sert aux actifs et aux perdus
                $achats = DB::table($res)
                    ->whereIn('res_statut', [2, 6])->where('res_canal', 1)->where('created_at', '>=', $debutAchats)
                    ->selectRaw(sprintf($mois, 'created_at') . ' as mois, util_id')
                    ->distinct()->get()
                    ->groupBy('mois')->map(fn($l) => $l->pluck('util_id')->all());

                $nouveauxClients = DB::table($util)
                    ->where('util_role', 1)->where('created_at', '>=', $debut)
                    ->selectRaw(sprintf($mois, 'created_at') . ' as mois, COUNT(*) as n')
                    ->groupBy('mois')->pluck('n', 'mois');

                $nouvellesCompagnies = DB::table($comp)
                    ->where('created_at', '>=', $debut)
                    ->selectRaw(sprintf($mois, 'created_at') . ' as mois, COUNT(*) as n')
                    ->groupBy('mois')->pluck('n', 'mois');

                $compagniesActives = DB::table("{$res} as r")
                    ->join("{$voy} as v", 'v.voyage_id', '=', 'r.voyage_id')
                    ->join("{$traj} as t", 't.traj_id', '=', 'v.traj_id')
                    ->whereIn('r.res_statut', [2, 6])->where('r.res_canal', 1)->where('r.created_at', '>=', $debut)
                    ->selectRaw(sprintf($mois, 'r.created_at') . ' as mois, COUNT(DISTINCT t.comp_id) as n')
                    ->groupBy('mois')->pluck('n', 'mois');

                $resultat = [];
                for ($i = $nbMois - 1; $i >= 0; $i--) {
                    $m = now()->startOfMonth()->subMonths($i);
                    $cle = $m->format('Y-m');
                    $actifs = $achats->get($cle, []);
                    // Perdus : achat en M-3, puis plus rien en M-2, M-1 et M
                    $actifsM3 = $achats->get($m->copy()->subMonths(3)->format('Y-m'), []);
                    $depuis = array_merge(
                        $achats->get($m->copy()->subMonths(2)->format('Y-m'), []),
                        $achats->get($m->copy()->subMonth()->format('Y-m'), []),
                        $actifs
                    );
                    $brut = (float) ($ventes[$cle]->brut ?? 0);
                    $resultat[] = [
                        'mois'                 => $cle,
                        'brut'                 => $brut,
                        'commission'           => round($brut * $this->tauxCommission, 2),
                        'billets'              => (int) ($ventes[$cle]->billets ?? 0),
                        'clients_nouveaux'     => (int) ($nouveauxClients[$cle] ?? 0),
                        'clients_actifs'       => count($actifs),
                        'clients_perdus'       => count(array_diff($actifsM3, $depuis)),
                        'compagnies_nouvelles' => (int) ($nouvellesCompagnies[$cle] ?? 0),
                        'compagnies_actives'   => (int) ($compagniesActives[$cle] ?? 0),
                    ];
                }
                return $resultat;
            });

            return response()->json([
                'statut' => true,
                'data'   => ['taux_commission' => $this->tauxCommission * 100, 'mois' => $donnees],
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Tableau de bord global des commissions (vue d'ensemble)
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            // ── Toutes les réservations confirmées (statut 2) ──
            $reservationsBase = Reservation::whereIn('res_statut', [2, 6])->where('res_canal', 1);

            // ── Totaux globaux ──
            $revenuBrut     = (float)(clone $reservationsBase)->sum('montant_total');
            $totalBillets   = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $nbReservations = (int)(clone $reservationsBase)->count();
            $commissionTotale = round($revenuBrut * $this->tauxCommission, 2);

            // ── Statut des commissions (source de vérité : table collectes) ──
            // 3 buckets mutuellement exclusifs :
            //   calculee = dû à échoir (en attente, échéance non dépassée)
            //   facturee = dû en retard (en attente, échéance dépassée)
            //   payee    = encaissé (collecte confirmée)
            $montantCalculee = (float) Collecte::where('coll_statut', Collecte::EN_ATTENTE)
                ->whereDate('coll_date_prevue', '>=', today())
                ->sum('coll_montant_commission');
            $montantFacturee = (float) Collecte::where('coll_statut', Collecte::EN_ATTENTE)
                ->whereDate('coll_date_prevue', '<', today())
                ->sum('coll_montant_commission');
            $montantPayee    = (float) Collecte::where('coll_statut', Collecte::CONFIRMEE)
                ->sum('coll_montant_commission');

            // ── Solde par période ──
            $periodes = [
                'aujourdhui'    => (clone $reservationsBase)->whereDate('created_at', today()),
                'cette_semaine' => (clone $reservationsBase)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
                'ce_mois'       => (clone $reservationsBase)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
                'cette_annee'   => (clone $reservationsBase)->whereYear('created_at', now()->year),
            ];

            $parPeriode = [];
            foreach ($periodes as $key => $query) {
                $brut = (float)(clone $query)->sum('montant_total');
                $parPeriode[$key] = [
                    'brut'       => $brut,
                    'commission' => round($brut * $this->tauxCommission, 2),
                    'billets'    => (int)(clone $query)->sum('nb_voyageurs'),
                    'reservations' => (int)(clone $query)->count(),
                ];
            }

            // ── Évolution mensuelle (12 derniers mois) ──
            $evolutionMensuelle = (clone $reservationsBase)
                ->where('created_at', '>=', now()->subMonths(12))
                ->select(
                    DB::raw("TO_CHAR(created_at, 'YYYY-MM') as mois"),
                    DB::raw('SUM(montant_total) as brut'),
                    DB::raw('SUM(nb_voyageurs) as billets'),
                    DB::raw('COUNT(*) as nb_reservations')
                )
                ->groupBy('mois')
                ->orderBy('mois')
                ->get()
                ->map(fn($item) => [
                    'mois'            => $item->mois,
                    'brut'            => (float)$item->brut,
                    'commission'      => round((float)$item->brut * $this->tauxCommission, 2),
                    'billets'         => (int)$item->billets,
                    'nb_reservations' => (int)$item->nb_reservations,
                ]);

            // ── Top compagnies par commission ──
            $topCompagnies = DB::table('fandrio_app.reservations as r')
                ->join('fandrio_app.voyages as v', 'r.voyage_id', '=', 'v.voyage_id')
                ->join('fandrio_app.trajets as t', 'v.traj_id', '=', 't.traj_id')
                ->join('fandrio_app.compagnies as c', 't.comp_id', '=', 'c.comp_id')
                ->whereIn('r.res_statut', [2, 6])->where('r.res_canal', 1)
                ->select(
                    'c.comp_id',
                    'c.comp_nom',
                    'c.comm_frequence_collecte',
                    DB::raw('SUM(r.montant_total) as brut'),
                    DB::raw('COUNT(r.res_id) as nb_reservations'),
                    DB::raw('SUM(r.nb_voyageurs) as billets')
                )
                ->groupBy('c.comp_id', 'c.comp_nom', 'c.comm_frequence_collecte')
                ->orderByDesc('brut')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'comp_id'              => $item->comp_id,
                    'comp_nom'             => $item->comp_nom,
                    'frequence_collecte'   => $item->comm_frequence_collecte ?? 'mensuelle',
                    'brut'                 => (float)$item->brut,
                    'commission'           => round((float)$item->brut * $this->tauxCommission, 2),
                    'nb_reservations'      => (int)$item->nb_reservations,
                    'billets'              => (int)$item->billets,
                ]);

            // ── Répartition par opérateur ──
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $repartitionOperateur = (clone $reservationsBase)
                ->select('type_paie_id', DB::raw('SUM(montant_total) as brut'), DB::raw('COUNT(*) as nb'))
                ->groupBy('type_paie_id')
                ->get()
                ->map(fn($item) => [
                    'operateur'  => $typesPaiement[$item->type_paie_id] ?? 'Non défini',
                    'type_id'    => $item->type_paie_id,
                    'brut'       => (float)$item->brut,
                    'commission' => round((float)$item->brut * $this->tauxCommission, 2),
                    'nb'         => (int)$item->nb,
                ]);

            return response()->json([
                'statut' => true,
                'data'   => [
                    'taux_commission' => $this->tauxCommission * 100,
                    'totaux' => [
                        'brut'            => $revenuBrut,
                        'commission'      => $commissionTotale,
                        'total_billets'   => $totalBillets,
                        'nb_reservations' => $nbReservations,
                        'nb_compagnies'   => Compagnie::active()->count(),
                    ],
                    'commissions_statut' => [
                        'calculee' => $montantCalculee,
                        'facturee' => $montantFacturee,
                        'payee'    => $montantPayee,
                    ],
                    'par_periode'          => $parPeriode,
                    'evolution_mensuelle'  => $evolutionMensuelle,
                    'top_compagnies'       => $topCompagnies,
                    'repartition_operateur' => $repartitionOperateur,
                    'collectes_en_attente' => Collecte::dueAujourdhui()->count(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Détail des commissions pour une compagnie donnée
     */
    public function detailCompagnie(Request $request, int $compagnieId): JsonResponse
    {
        try {
            $compagnie = Compagnie::findOrFail($compagnieId);

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            $reservationsBase = Reservation::whereIn('voyage_id', $voyageIds)->whereIn('res_statut', [2, 6])->where('res_canal', 1);

            $revenuBrut     = (float)(clone $reservationsBase)->sum('montant_total');
            $totalBillets   = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $nbReservations = (int)(clone $reservationsBase)->count();
            $commissionTotale = round($revenuBrut * $this->tauxCommission, 2);

            // ── Commissions enregistrées pour cette compagnie ──
            $commissions = Commission::where('comp_id', $compagnieId)
                ->orderByDesc('comm_periode')
                ->get()
                ->map(fn($c) => [
                    'comm_id'     => $c->comm_id,
                    'periode'     => $c->comm_periode,
                    'montant'     => (float)$c->comm_montant,
                    'taux'        => (float)$c->comm_taux,
                    'nb_reservations' => $c->nb_reservations,
                    'statut'      => $c->comm_statut,
                    'statut_label' => Commission::statutLabel($c->comm_statut),
                    'date_calcul' => $c->date_calcul?->format('d/m/Y H:i'),
                ]);

            // ── Évolution mensuelle ──
            $evolutionMensuelle = (clone $reservationsBase)
                ->where('created_at', '>=', now()->subMonths(12))
                ->select(
                    DB::raw("TO_CHAR(created_at, 'YYYY-MM') as mois"),
                    DB::raw('SUM(montant_total) as brut'),
                    DB::raw('COUNT(*) as nb_reservations')
                )
                ->groupBy('mois')
                ->orderBy('mois')
                ->get()
                ->map(fn($item) => [
                    'mois'            => $item->mois,
                    'brut'            => (float)$item->brut,
                    'commission'      => round((float)$item->brut * $this->tauxCommission, 2),
                    'nb_reservations' => (int)$item->nb_reservations,
                ]);

            return response()->json([
                'statut' => true,
                'data'   => [
                    'compagnie' => [
                        'comp_id'            => $compagnie->comp_id,
                        'comp_nom'           => $compagnie->comp_nom,
                        'frequence_collecte' => $compagnie->comm_frequence_collecte ?? 'mensuelle',
                    ],
                    'totaux' => [
                        'brut'            => $revenuBrut,
                        'commission'      => $commissionTotale,
                        'total_billets'   => $totalBillets,
                        'nb_reservations' => $nbReservations,
                    ],
                    'commissions'         => $commissions,
                    'evolution_mensuelle' => $evolutionMensuelle,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Liste de toutes les compagnies avec leur résumé de commissions
     */
    public function listeCompagnies(Request $request): JsonResponse
    {
        try {
            $query = DB::table('fandrio_app.reservations as r')
                ->join('fandrio_app.voyages as v', 'r.voyage_id', '=', 'v.voyage_id')
                ->join('fandrio_app.trajets as t', 'v.traj_id', '=', 't.traj_id')
                ->join('fandrio_app.compagnies as c', 't.comp_id', '=', 'c.comp_id')
                ->whereIn('r.res_statut', [2, 6])->where('r.res_canal', 1)
                ->select(
                    'c.comp_id',
                    'c.comp_nom',
                    'c.comp_statut',
                    'c.comp_suspendu_commission',
                    'c.comm_frequence_collecte',
                    DB::raw('SUM(r.montant_total) as brut'),
                    DB::raw('COUNT(r.res_id) as nb_reservations'),
                    DB::raw('SUM(r.nb_voyageurs) as billets')
                )
                ->groupBy('c.comp_id', 'c.comp_nom', 'c.comp_statut', 'c.comp_suspendu_commission', 'c.comm_frequence_collecte');

            // Filtres
            if ($request->filled('recherche')) {
                $query->where('c.comp_nom', 'ILIKE', '%' . $request->recherche . '%');
            }
            if ($request->filled('frequence')) {
                $query->where('c.comm_frequence_collecte', $request->frequence);
            }
            if ($request->boolean('suspendu')) {
                $query->where('c.comp_suspendu_commission', true);
            }

            $compagnies = $query->orderByDesc('brut')->get()->map(fn($item) => [
                'comp_id'            => $item->comp_id,
                'comp_nom'           => $item->comp_nom,
                'comp_statut'        => $item->comp_statut,
                'suspendu'           => (bool)$item->comp_suspendu_commission,
                'frequence_collecte' => $item->comm_frequence_collecte ?? 'mensuelle',
                'brut'               => (float)$item->brut,
                'commission'         => round((float)$item->brut * $this->tauxCommission, 2),
                'nb_reservations'    => (int)$item->nb_reservations,
                'billets'            => (int)$item->billets,
            ]);

            return response()->json([
                'statut' => true,
                'data'   => ['compagnies' => $compagnies]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Confirmer la réception de la commission (changer le statut)
     */
    public function confirmerReception(Request $request, int $commId): JsonResponse
    {
        try {
            $commission = Commission::findOrFail($commId);

            $request->validate([
                'statut' => 'required|integer|in:2,3',
            ]);

            $nouveauStatut = $request->statut;

            // Vérifier la transition valide : 1 → 2, 2 → 3
            if ($commission->comm_statut >= $nouveauStatut) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Transition de statut invalide. Statut actuel: ' . Commission::statutLabel($commission->comm_statut),
                ], 422);
            }

            $commission->comm_statut = $nouveauStatut;
            $commission->save();

            return response()->json([
                'statut'  => true,
                'message' => 'Commission marquée comme "' . Commission::statutLabel($nouveauStatut) . '".',
                'data'    => [
                    'comm_id'      => $commission->comm_id,
                    'statut'       => $commission->comm_statut,
                    'statut_label' => Commission::statutLabel($commission->comm_statut),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Mettre à jour la fréquence et le jour de collecte pour une compagnie
     */
    public function updateFrequence(Request $request, int $compagnieId): JsonResponse
    {
        try {
            $compagnie = Compagnie::findOrFail($compagnieId);

            $request->validate([
                'frequence'     => 'sometimes|string|in:hebdomadaire,mensuelle',
                'jour_collecte' => 'sometimes|nullable|string',
            ]);

            if ($request->filled('frequence')) {
                $compagnie->comm_frequence_collecte = $request->frequence;
            }
            if ($request->has('jour_collecte')) {
                $compagnie->comm_jour_collecte = $request->jour_collecte;
            }
            $compagnie->save();

            return response()->json([
                'statut'  => true,
                'message' => 'Configuration de collecte mise à jour.',
                'data'    => [
                    'comp_id'            => $compagnie->comp_id,
                    'comp_nom'           => $compagnie->comp_nom,
                    'frequence_collecte' => $compagnie->comm_frequence_collecte,
                    'jour_collecte'      => $compagnie->comm_jour_collecte,
                    'commission_active'  => $compagnie->comm_actif,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Activer/Désactiver la commission pour une compagnie
     */
    public function toggleCommission(Request $request, int $compagnieId): JsonResponse
    {
        try {
            $compagnie = Compagnie::findOrFail($compagnieId);

            $request->validate([
                'actif' => 'required|boolean',
            ]);

            $compagnie->comm_actif = $request->actif;
            $compagnie->save();

            $etat = $request->actif ? 'activée' : 'désactivée';

            return response()->json([
                'statut'  => true,
                'message' => "Commission {$etat} pour {$compagnie->comp_nom}.",
                'data'    => [
                    'comp_id'           => $compagnie->comp_id,
                    'comp_nom'          => $compagnie->comp_nom,
                    'commission_active' => $compagnie->comm_actif,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Liste des collectes (avec filtres)
     */
    public function listeCollectes(Request $request): JsonResponse
    {
        try {
            $query = Collecte::with('compagnie')
                ->orderByDesc('coll_date_prevue');

            if ($request->filled('statut')) {
                $query->where('coll_statut', $request->statut);
            }
            if ($request->filled('comp_id')) {
                $query->where('comp_id', $request->comp_id);
            }
            if ($request->filled('dues')) {
                $query->where('coll_date_prevue', '<=', today())
                      ->where('coll_statut', Collecte::EN_ATTENTE);
            }

            $collectes = $query->paginate($request->get('per_page', 20));

            $data = $collectes->getCollection()->map(fn($c) => $this->formaterCollecte($c));

            return response()->json([
                'statut' => true,
                'data'   => [
                    'collectes' => $data,
                    'pagination' => [
                        'total'        => $collectes->total(),
                        'current_page' => $collectes->currentPage(),
                        'last_page'    => $collectes->lastPage(),
                        'per_page'     => $collectes->perPage(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Détail d'une collecte (facture)
     */
    public function detailCollecte(int $collecteId): JsonResponse
    {
        try {
            $collecte = Collecte::with(['compagnie', 'confirmePar'])->findOrFail($collecteId);

            $data = $this->formaterCollecte($collecte);

            // Ajouter les détails de facturation
            $data['compagnie_detail'] = [
                'nom'     => $collecte->compagnie->comp_nom,
                'nif'     => $collecte->compagnie->comp_nif,
                'stat'    => $collecte->compagnie->comp_stat,
                'email'   => $collecte->compagnie->comp_email,
                'phone'   => $collecte->compagnie->comp_phone,
                'adresse' => $collecte->compagnie->comp_adresse,
            ];

            if ($collecte->confirmePar) {
                $data['confirme_par_detail'] = [
                    'nom'    => $collecte->confirmePar->util_nom,
                    'prenom' => $collecte->confirmePar->util_prenom,
                ];
            }

            return response()->json(['statut' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Confirmer une collecte
     */
    public function confirmerCollecte(Request $request, int $collecteId): JsonResponse
    {
        try {
            $collecte = Collecte::with('compagnie')->findOrFail($collecteId);

            if ($collecte->coll_statut !== Collecte::EN_ATTENTE) {
                return response()->json([
                    'statut'  => false,
                    'message' => 'Cette collecte a déjà été confirmée.'
                ], 422);
            }

            $adminId = $request->user()->util_id;

            $collecte->update([
                'coll_statut'            => Collecte::CONFIRMEE,
                'coll_date_confirmation' => now(),
                'coll_confirme_par'      => $adminId,
            ]);

            // Réactiver la compagnie si elle n'a plus aucune collecte en retard (source unique de vérité)
            app(\App\Services\Commission\CommissionService::class)
                ->reactiverCompagnieSiAJour($collecte->comp_id);

            // Envoyer une notification aux admins de la compagnie
            $admins = Utilisateur::where('comp_id', $collecte->comp_id)
                ->where('util_role', 2)
                ->where('util_statut', 1)
                ->get();

            $periodeDebut = $collecte->coll_periode_debut->format('d/m/Y');
            $periodeFin   = $collecte->coll_periode_fin->format('d/m/Y');
            $montant      = number_format((float)$collecte->coll_montant_commission, 0, ',', ' ') . ' Ar';

            foreach ($admins as $admin) {
                NotificationService::envoyer([
                    'type'              => 8,
                    'destinataire_type' => 2,
                    'destinataire_id'   => $admin->util_id,
                    'titre'             => 'Collecte de commission effectuée',
                    'message'           => "La collecte du {$periodeDebut} au {$periodeFin} d'un montant de {$montant} a été effectuée aujourd'hui avec succès. Consultez le détail dans votre espace.",
                ]);
            }

            return response()->json([
                'statut'  => true,
                'message' => 'Collecte confirmée avec succès. Notification envoyée à la compagnie.',
                'data'    => $this->formaterCollecte($collecte->fresh()->load('compagnie')),
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Relancer la compagnie pour une collecte non payée (en attente ou en retard).
     * POST /admin/commissions/collectes/{collecteId}/relancer
     * Une relance au plus toutes les 12 heures par collecte.
     */
    public function relancerCollecte(int $collecteId): JsonResponse
    {
        try {
            $collecte = Collecte::with('compagnie')->findOrFail($collecteId);

            if ($collecte->coll_statut !== Collecte::EN_ATTENTE) {
                return response()->json(['statut' => false, 'message' => 'Cette collecte est déjà payée.'], 422);
            }

            $cle = "relance_collecte_{$collecteId}";
            if (\Illuminate\Support\Facades\Cache::has($cle)) {
                return response()->json(['statut' => false, 'message' => 'La compagnie a déjà été relancée il y a moins de 12 heures.'], 429);
            }

            $admins = Utilisateur::where('comp_id', $collecte->comp_id)
                ->where('util_role', 2)
                ->where('util_statut', 1)
                ->get();
            if ($admins->isEmpty()) {
                return response()->json(['statut' => false, 'message' => 'Aucun administrateur actif à prévenir pour cette compagnie.'], 422);
            }

            $periodeDebut = $collecte->coll_periode_debut->format('d/m/Y');
            $periodeFin   = $collecte->coll_periode_fin->format('d/m/Y');
            $montant      = number_format((float) $collecte->coll_montant_commission, 0, ',', ' ') . ' Ar';
            $enRetard     = $collecte->coll_date_prevue && $collecte->coll_date_prevue->lt(today());
            $echeance     = $collecte->coll_date_prevue ? $collecte->coll_date_prevue->format('d/m/Y') : null;

            $titre   = $enRetard ? 'Rappel : commission en retard' : 'Rappel : commission à régler';
            $message = "La commission FANDRIO du {$periodeDebut} au {$periodeFin} ({$montant}) "
                . ($enRetard ? "devait être réglée le {$echeance}. " : ($echeance ? "est à régler avant le {$echeance}. " : 'est à régler. '))
                . 'Réglez-la depuis Portefeuille > Payer la commission'
                . ($enRetard ? ' pour que vos voyages restent visibles des clients.' : '.');

            foreach ($admins as $admin) {
                NotificationService::envoyer([
                    'type'              => 8, // commission
                    'destinataire_type' => 2, // compagnie
                    'destinataire_id'   => $admin->util_id,
                    'titre'             => $titre,
                    'message'           => $message,
                ]);
            }

            \Illuminate\Support\Facades\Cache::put($cle, now()->toIso8601String(), now()->addHours(12));

            return response()->json([
                'statut'  => true,
                'message' => $admins->count() > 1
                    ? "Relance envoyée aux {$admins->count()} administrateurs de la compagnie."
                    : 'Relance envoyée à la compagnie.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Collectes en attente (rappels pour l'admin)
     */
    public function collectesDues(): JsonResponse
    {
        try {
            $collectes = Collecte::with('compagnie')
                ->dueAujourdhui()
                ->orderBy('coll_date_prevue')
                ->get()
                ->map(fn($c) => $this->formaterCollecte($c));

            return response()->json([
                'statut' => true,
                'data'   => ['collectes' => $collectes, 'total' => $collectes->count()]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Historique des collectes pour une compagnie
     */
    public function historiqueCollectes(int $compagnieId): JsonResponse
    {
        try {
            Compagnie::findOrFail($compagnieId);

            $collectes = Collecte::where('comp_id', $compagnieId)
                ->orderByDesc('coll_date_prevue')
                ->get()
                ->map(fn($c) => $this->formaterCollecte($c));

            return response()->json([
                'statut' => true,
                'data'   => ['collectes' => $collectes]
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $this->messageErreur($e)], 500);
        }
    }

    /**
     * Formater une collecte pour la réponse JSON
     */
    private function formaterCollecte(Collecte $collecte): array
    {
        return [
            'coll_id'              => $collecte->coll_id,
            'comp_id'              => $collecte->comp_id,
            'comp_nom'             => $collecte->compagnie?->comp_nom,
            'periode_debut'        => $collecte->coll_periode_debut->format('d/m/Y'),
            'periode_fin'          => $collecte->coll_periode_fin->format('d/m/Y'),
            'periode_debut_raw'    => $collecte->coll_periode_debut->toDateString(),
            'periode_fin_raw'      => $collecte->coll_periode_fin->toDateString(),
            'montant_brut'         => (float)$collecte->coll_montant_brut,
            'montant_commission'   => (float)$collecte->coll_montant_commission,
            'taux'                 => (float)$collecte->coll_taux,
            'nb_reservations'      => $collecte->coll_nb_reservations,
            'nb_billets'           => $collecte->coll_nb_billets,
            'statut'               => $collecte->coll_statut,
            'statut_label'         => $collecte->coll_statut === Collecte::CONFIRMEE ? 'Confirmée' : 'En attente',
            'date_prevue'          => $collecte->coll_date_prevue->format('d/m/Y'),
            'date_confirmation'    => $collecte->coll_date_confirmation?->format('d/m/Y H:i'),
            'created_at'           => $collecte->created_at?->format('d/m/Y H:i'),
        ];
    }
}
