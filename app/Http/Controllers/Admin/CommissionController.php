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
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    private float $tauxCommission = 0.05; // 5%

    /**
     * Tableau de bord global des commissions (vue d'ensemble)
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            // ── Toutes les réservations confirmées (statut 2) ──
            $reservationsBase = Reservation::where('res_statut', 2);

            // ── Totaux globaux ──
            $revenuBrut     = (float)(clone $reservationsBase)->sum('montant_total');
            $totalBillets   = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $nbReservations = (int)(clone $reservationsBase)->count();
            $commissionTotale = round($revenuBrut * $this->tauxCommission, 2);

            // ── Commissions par statut (depuis la table commissions) ──
            $commissionsParStatut = Commission::select('comm_statut', DB::raw('COUNT(*) as nb'), DB::raw('SUM(comm_montant) as total'))
                ->groupBy('comm_statut')
                ->get()
                ->keyBy('comm_statut');

            $montantCalculee = (float)($commissionsParStatut[1]->total ?? 0);
            $montantFacturee = (float)($commissionsParStatut[2]->total ?? 0);
            $montantPayee    = (float)($commissionsParStatut[3]->total ?? 0);

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
                ->where('r.res_statut', 2)
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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

            $reservationsBase = Reservation::whereIn('voyage_id', $voyageIds)->where('res_statut', 2);

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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
                ->where('r.res_statut', 2)
                ->select(
                    'c.comp_id',
                    'c.comp_nom',
                    'c.comp_statut',
                    'c.comm_frequence_collecte',
                    DB::raw('SUM(r.montant_total) as brut'),
                    DB::raw('COUNT(r.res_id) as nb_reservations'),
                    DB::raw('SUM(r.nb_voyageurs) as billets')
                )
                ->groupBy('c.comp_id', 'c.comp_nom', 'c.comp_statut', 'c.comm_frequence_collecte');

            // Filtres
            if ($request->filled('recherche')) {
                $query->where('c.comp_nom', 'ILIKE', '%' . $request->recherche . '%');
            }
            if ($request->filled('frequence')) {
                $query->where('c.comm_frequence_collecte', $request->frequence);
            }

            $compagnies = $query->orderByDesc('brut')->get()->map(fn($item) => [
                'comp_id'            => $item->comp_id,
                'comp_nom'           => $item->comp_nom,
                'comp_statut'        => $item->comp_statut,
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
            return response()->json(['statut' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
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
