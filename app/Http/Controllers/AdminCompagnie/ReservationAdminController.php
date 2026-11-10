<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Models\Paiements\TypePaiement;
use App\Models\Voyages\Voyage;
use App\Models\Compagnies\Compagnie;
use App\Models\Commissions\Collecte;
use App\Services\Voiture\SiegeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Events\SiegeUpdated;
use App\Models\Reservation\ReservationVoyageur;
use App\Models\Voitures\SiegeReserve;
use App\Models\Voyageur\Voyageur;
use App\Services\Reservation\ReservationExpireeService;
use Illuminate\Support\Facades\Log;

class ReservationAdminController extends Controller
{
    /**
     * Statut de réservation considéré comme « validé » pour la compagnie :
     *  2 = confirmée (l'utilisateur a saisi son code de paiement)
     * Le workflow est entièrement automatisé côté client : pas de validation manuelle
     * du paiement par l'admin compagnie.
     */
    private const STATUTS_VALIDES = [2];
    // Argent encaissé par la compagnie : billets confirmés + billets annulés par le
    // client sans remboursement (6). La commission FANDRIO reste due sur ces derniers.
    private const STATUTS_ENCAISSES = [2, 6];

    public function __construct(private SiegeService $siegeService) {}

    /**
     * Récupère la compagnie de l'utilisateur authentifié
     */
    private function getCompagnieId(): int
    {
        return Auth::user()->comp_id;
    }

    /**
     * Liste les voyages ayant au moins une réservation validée (statut 2)
     * Inclut les données financières (revenus par voyage)
     * GET /api/adminCompagnie/reservations/voyages
     */
    public function voyagesAvecReservations(Request $request): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            // Statuts des voyages (complet / départ atteint) mis à jour par la
            // tâche planifiée `voyages:gestion-statuts` (chaque minute).

            $query = Voyage::with([
                'trajet.provinceDepart',
                'trajet.provinceArrivee',
                'voiture'
            ])
            ->whereHas('trajet', function ($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })
            ->whereHas('reservations', function ($q) {
                $q->whereIn('res_statut', self::STATUTS_VALIDES);
            })
            ->withCount(['reservations as reservations_validees_count' => function ($q) {
                $q->whereIn('res_statut', self::STATUTS_VALIDES);
            }])
            ->withSum(['reservations as total_voyageurs' => function ($q) {
                $q->whereIn('res_statut', self::STATUTS_VALIDES);
            }], 'nb_voyageurs')
            ->withSum(['reservations as revenu_total' => function ($q) {
                $q->whereIn('res_statut', self::STATUTS_VALIDES);
            }], 'montant_total')
            ->orderBy('voyage_date', 'desc')
            ->orderBy('voyage_heure_depart', 'desc');

            if ($request->has('statut')) {
                $query->where('voyage_statut', $request->statut);
            }

            $voyages = $query->paginate($request->get('per_page', 15));

            // Calcul du résumé global
            $allVoyagesQuery = Voyage::whereHas('trajet', function ($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->whereHas('reservations', function ($q) {
                $q->whereIn('res_statut', self::STATUTS_VALIDES);
            });

            $totalRevenu = Reservation::whereIn('voyage_id',
                Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->pluck('voyage_id')
            )->whereIn('res_statut', self::STATUTS_VALIDES)->sum('montant_total');

            $totalReservations = Reservation::whereIn('voyage_id',
                Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->pluck('voyage_id')
            )->whereIn('res_statut', self::STATUTS_VALIDES)->count();

            $data = [
                'resume' => [
                    'revenu_total' => (float) $totalRevenu,
                    'total_reservations' => $totalReservations,
                    'total_voyages' => $voyages->total(),
                ],
                'voyages' => $voyages->getCollection()->map(function ($voyage) {
                    return [
                        'voyage_id' => $voyage->voyage_id,
                        'date' => $voyage->voyage_date->format('d/m/Y'),
                        'date_raw' => $voyage->voyage_date->format('Y-m-d'),
                        'heure_depart' => $voyage->voyage_heure_depart,
                        'statut' => $voyage->voyage_statut,
                        'places_disponibles' => $voyage->places_disponibles,
                        'places_reservees' => $voyage->places_reservees,
                        'est_complet' => $voyage->places_reservees >= $voyage->places_disponibles,
                        'reservations_count' => $voyage->reservations_validees_count ?? 0,
                        'total_voyageurs' => (int) ($voyage->total_voyageurs ?? 0),
                        'revenu_total' => (float) ($voyage->revenu_total ?? 0),
                        'trajet' => $voyage->trajet ? [
                            'nom' => $voyage->trajet->traj_nom,
                            'province_depart' => $voyage->trajet->provinceDepart->pro_nom ?? null,
                            'province_arrivee' => $voyage->trajet->provinceArrivee->pro_nom ?? null,
                            'tarif' => $voyage->trajet->traj_tarif,
                        ] : null,
                        'voiture' => $voyage->voiture ? [
                            'matricule' => $voyage->voiture->voit_matricule,
                            'marque' => $voyage->voiture->voit_marque,
                            'modele' => $voyage->voiture->voit_modele,
                        ] : null,
                    ];
                }),
                'pagination' => [
                    'total' => $voyages->total(),
                    'current_page' => $voyages->currentPage(),
                    'last_page' => $voyages->lastPage(),
                    'per_page' => $voyages->perPage(),
                ],
            ];

            return response()->json([
                'statut' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Récupère le plan de sièges en temps réel pour un voyage
     * GET /api/adminCompagnie/reservations/voyages/{voyageId}/plan-sieges
     */
    public function planSieges(int $voyageId): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyage = Voyage::whereHas('trajet', function ($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->findOrFail($voyageId);

            $planSieges = $this->siegeService->getPlanSieges($voyageId);

            return response()->json([
                'statut' => true,
                'data' => $planSieges
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 400);
        }
    }

    /**
     * Récupère les voyageurs des réservations validées pour un voyage
     * GET /api/adminCompagnie/reservations/voyages/{voyageId}/voyageurs
     */
    public function voyageurs(int $voyageId): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyage = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
                ->whereHas('trajet', function ($q) use ($compagnieId) {
                    $q->where('comp_id', $compagnieId);
                })->findOrFail($voyageId);

            $reservations = Reservation::with(['voyageurs', 'utilisateur'])
                ->where('voyage_id', $voyageId)
                ->whereIn('res_statut', self::STATUTS_VALIDES)
                ->orderBy('created_at', 'asc')
                ->get();

            $voyageurs = [];
            foreach ($reservations as $reservation) {
                foreach ($reservation->voyageurs as $voyageur) {
                    $voyageurs[] = [
                        'reservation_numero' => $reservation->res_numero,
                        'reservation_id' => $reservation->res_id,
                        'voyageur_id' => $voyageur->voya_id,
                        'nom' => $voyageur->voya_nom,
                        'prenom' => $voyageur->voya_prenom,
                        'age' => $voyageur->voya_age,
                        'cin' => $voyageur->voya_cin,
                        'phone' => $voyageur->voya_phone,
                        'phone2' => $voyageur->voya_phone2,
                        'siege' => $voyageur->pivot->place_numero,
                        'embarque' => ($voyageur->pivot->res_voya_statut ?? 1) >= 2,
                        'canal' => (int) $reservation->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                        'client' => $reservation->utilisateur ? [
                            'nom' => $reservation->utilisateur->util_nom,
                            'prenom' => $reservation->utilisateur->util_prenom,
                            'telephone' => $reservation->utilisateur->util_phone,
                        ] : null,
                    ];
                }
            }

            return response()->json([
                'statut' => true,
                'data' => [
                    'voyage' => [
                        'voyage_id' => $voyage->voyage_id,
                        'date' => $voyage->voyage_date->format('d/m/Y'),
                        'heure' => $voyage->voyage_heure_depart,
                        'trajet' => $voyage->trajet ? ($voyage->trajet->provinceDepart->pro_nom . ' → ' . $voyage->trajet->provinceArrivee->pro_nom) : null,
                    ],
                    'voyageurs' => $voyageurs,
                    'total' => count($voyageurs),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 400);
        }
    }

    /**
     * Détail des réservations (billets) pour un voyage - vue financière
     * GET /api/adminCompagnie/reservations/voyages/{voyageId}/billets
     */
    public function billets(int $voyageId): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyage = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
                ->whereHas('trajet', function ($q) use ($compagnieId) {
                    $q->where('comp_id', $compagnieId);
                })->findOrFail($voyageId);

            $reservations = Reservation::with(['utilisateur', 'voyageurs'])
                ->where('voyage_id', $voyageId)
                ->whereIn('res_statut', self::STATUTS_VALIDES)
                ->orderBy('created_at', 'desc')
                ->get();

            // Charger les types de paiement
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');

            $billets = $reservations->map(function ($res) use ($typesPaiement) {
                return [
                    'res_id' => $res->res_id,
                    'res_numero' => $res->res_numero,
                    'date_reservation' => $res->created_at->format('d/m/Y H:i'),
                    'date_reservation_raw' => $res->created_at->toISOString(),
                    'nb_voyageurs' => $res->nb_voyageurs,
                    'montant_total' => (float) $res->montant_total,
                    'montant_avance' => (float) ($res->montant_avance ?? 0),
                    'montant_restant' => (float) ($res->montant_restant ?? 0),
                    'type_paiement' => [
                        'id' => $res->type_paie_id,
                        'nom' => $typesPaiement[$res->type_paie_id] ?? 'Non défini',
                    ],
                    'numero_paiement' => $res->numero_paiement,
                    'canal' => (int) $res->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                    'client' => $res->utilisateur ? [
                        'nom' => $res->utilisateur->util_nom,
                        'prenom' => $res->utilisateur->util_prenom,
                        'telephone' => $res->utilisateur->util_telephone,
                    ] : null,
                    'sieges' => $res->voyageurs->map(fn($v) => $v->pivot->place_numero)->values(),
                ];
            });

            $revenuTotal = $reservations->sum('montant_total');

            // Répartition par type de paiement
            $repartitionPaiement = $reservations->groupBy('type_paie_id')->map(function ($group) use ($typesPaiement) {
                $typeId = $group->first()->type_paie_id;
                return [
                    'type' => $typesPaiement[$typeId] ?? 'Non défini',
                    'count' => $group->count(),
                    'montant' => (float) $group->sum('montant_total'),
                ];
            })->values();

            return response()->json([
                'statut' => true,
                'data' => [
                    'voyage' => [
                        'voyage_id' => $voyage->voyage_id,
                        'date' => $voyage->voyage_date->format('d/m/Y'),
                        'heure' => $voyage->voyage_heure_depart,
                        'trajet' => $voyage->trajet ? ($voyage->trajet->provinceDepart->pro_nom . ' → ' . $voyage->trajet->provinceArrivee->pro_nom) : null,
                        'tarif_unitaire' => $voyage->trajet->traj_tarif ?? null,
                    ],
                    'resume' => [
                        'revenu_total' => (float) $revenuTotal,
                        'total_billets' => $reservations->count(),
                        'total_voyageurs' => $reservations->sum('nb_voyageurs'),
                        'repartition_paiement' => $repartitionPaiement,
                    ],
                    'billets' => $billets,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 400);
        }
    }

    /**
     * Statistiques globales des réservations de la compagnie
     * GET /api/adminCompagnie/reservations/statistiques
     */
    public function statistiques(): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            // Réservations validées (confirmées + payées)
            $reservationsValidees = Reservation::whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES);

            $revenuTotal = (float) (clone $reservationsValidees)->sum('montant_total');
            $totalReservations = (clone $reservationsValidees)->count();
            $totalVoyageurs = (int) (clone $reservationsValidees)->sum('nb_voyageurs');

            // Répartition par type de paiement
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $repartitionPaiement = (clone $reservationsValidees)
                ->select('type_paie_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(montant_total) as montant'))
                ->groupBy('type_paie_id')
                ->get()
                ->map(function ($item) use ($typesPaiement) {
                    return [
                        'type' => $typesPaiement[$item->type_paie_id] ?? 'Non défini',
                        'count' => (int) $item->count,
                        'montant' => (float) $item->montant,
                    ];
                });

            // Réservations récentes (10 dernières)
            $reservationsRecentes = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                ->whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($res) use ($typesPaiement) {
                    return [
                        'res_numero' => $res->res_numero,
                        'date_reservation' => $res->created_at->format('d/m/Y H:i'),
                        'montant_total' => (float) $res->montant_total,
                        'nb_voyageurs' => $res->nb_voyageurs,
                        'type_paiement' => $typesPaiement[$res->type_paie_id] ?? 'Non défini',
                        'canal' => (int) $res->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                        'client' => $res->utilisateur ? ($res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom) : null,
                        'trajet' => $res->voyage?->trajet ? ($res->voyage->trajet->provinceDepart->pro_nom . ' → ' . $res->voyage->trajet->provinceArrivee->pro_nom) : null,
                        'voyage_date' => $res->voyage?->voyage_date?->format('d/m/Y'),
                    ];
                });

            // Voyages complets vs incomplets
            $voyagesComplets = Voyage::whereIn('voyage_id', $voyageIds)
                ->whereRaw('places_reservees >= places_disponibles')
                ->count();

            return response()->json([
                'statut' => true,
                'data' => [
                    'revenu_total' => $revenuTotal,
                    'total_reservations' => $totalReservations,
                    'total_voyageurs' => $totalVoyageurs,
                    'voyages_complets' => $voyagesComplets,
                    'repartition_paiement' => $repartitionPaiement,
                    'reservations_recentes' => $reservationsRecentes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Tableau de bord financier enrichi
     * GET /api/adminCompagnie/reservations/tableau-bord-financier
     */
    public function tableauBordFinancier(): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            $reservationsBase = Reservation::whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES);

            // CA par période
            $aujourdhui = (float)(clone $reservationsBase)->whereDate('created_at', today())->sum('montant_total');
            $cetteSemaine = (float)(clone $reservationsBase)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('montant_total');
            $ceMois = (float)(clone $reservationsBase)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('montant_total');
            $cetteAnnee = (float)(clone $reservationsBase)->whereYear('created_at', now()->year)->sum('montant_total');

            // Mois dernier (pour comparaison)
            $moisDernier = (float)(clone $reservationsBase)
                ->whereMonth('created_at', now()->subMonth()->month)
                ->whereYear('created_at', now()->subMonth()->year)
                ->sum('montant_total');

            $evolutionMois = ($moisDernier > 0) ? round((($ceMois - $moisDernier) / $moisDernier) * 100, 1) : null;

            // Évolution CA sur les 30 derniers jours
            $evolutionJournaliere = (clone $reservationsBase)
                ->where('created_at', '>=', now()->subDays(30))
                ->select(
                    DB::raw("created_at::date as jour"),
                    DB::raw("SUM(montant_total) as ca"),
                    DB::raw("COUNT(*) as nb_reservations")
                )
                ->groupBy('jour')
                ->orderBy('jour')
                ->get();

            // Évolution CA sur les 12 derniers mois
            $evolutionMensuelle = (clone $reservationsBase)
                ->where('created_at', '>=', now()->subMonths(12))
                ->select(
                    DB::raw("TO_CHAR(created_at, 'YYYY-MM') as mois"),
                    DB::raw("SUM(montant_total) as ca"),
                    DB::raw("COUNT(*) as nb_reservations"),
                    DB::raw("SUM(nb_voyageurs) as nb_voyageurs")
                )
                ->groupBy('mois')
                ->orderBy('mois')
                ->get();

            // Taux de remplissage moyen
            $tauxData = Voyage::whereIn('voyage_id', $voyageIds)
                ->where('places_disponibles', '>', 0)
                ->whereIn('voyage_statut', [1, 2, 3])
                ->select(DB::raw('AVG(CASE WHEN places_disponibles > 0 THEN (places_reservees * 100.0 / places_disponibles) ELSE 0 END) as taux_moyen'))
                ->first();
            $tauxRemplissage = round($tauxData->taux_moyen ?? 0, 1);

            // Répartition paiements
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $repartitionPaiement = (clone $reservationsBase)
                ->select('type_paie_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(montant_total) as montant'))
                ->groupBy('type_paie_id')
                ->get()
                ->map(fn($item) => [
                    'type' => $typesPaiement[$item->type_paie_id] ?? 'Non défini',
                    'type_id' => $item->type_paie_id,
                    'count' => (int)$item->count,
                    'montant' => (float)$item->montant,
                ]);

            // Totaux
            $totalReservations = (clone $reservationsBase)->count();
            $totalVoyageurs = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $totalVoyages = Voyage::whereIn('voyage_id', $voyageIds)->count();
            $voyagesComplets = Voyage::whereIn('voyage_id', $voyageIds)
                ->whereRaw('places_reservees >= places_disponibles')
                ->where('places_disponibles', '>', 0)
                ->count();

            // Réservations aujourd'hui
            $reservationsAujourdhui = (clone $reservationsBase)->whereDate('created_at', today())->count();

            // Prochains voyages (5 prochains)
            $prochainsVoyages = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture'])
                ->whereIn('voyage_id', $voyageIds)
                ->whereIn('voyage_statut', [1, 2])
                ->where('voyage_date', '>=', today())
                ->orderBy('voyage_date')
                ->orderBy('voyage_heure_depart')
                ->limit(5)
                ->get()
                ->map(fn($v) => [
                    'voyage_id' => $v->voyage_id,
                    'date' => $v->voyage_date->format('d/m/Y'),
                    'heure' => $v->voyage_heure_depart,
                    'trajet' => $v->trajet ? ($v->trajet->provinceDepart->pro_nom . ' → ' . $v->trajet->provinceArrivee->pro_nom) : null,
                    'places_reservees' => $v->places_reservees,
                    'places_disponibles' => $v->places_disponibles,
                    'statut' => $v->voyage_statut,
                    'voiture' => $v->voiture?->voit_matricule,
                ]);

            // Réservations récentes (5 dernières)
            $reservationsRecentes = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                ->whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($res) => [
                    'res_numero' => $res->res_numero,
                    'date' => $res->created_at->format('d/m/Y H:i'),
                    'montant' => (float)$res->montant_total,
                    'nb_voyageurs' => $res->nb_voyageurs,
                    'canal' => (int) $res->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                    'client' => $res->utilisateur ? ($res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom) : null,
                    'trajet' => $res->voyage?->trajet ? ($res->voyage->trajet->provinceDepart->pro_nom . ' → ' . $res->voyage->trajet->provinceArrivee->pro_nom) : null,
                    'type_paiement' => $typesPaiement[$res->type_paie_id] ?? null,
                ]);

            return response()->json([
                'statut' => true,
                'data' => [
                    'ca' => [
                        'aujourdhui' => $aujourdhui,
                        'cette_semaine' => $cetteSemaine,
                        'ce_mois' => $ceMois,
                        'cette_annee' => $cetteAnnee,
                        'mois_dernier' => $moisDernier,
                        'evolution_mois' => $evolutionMois,
                    ],
                    'evolution_journaliere' => $evolutionJournaliere,
                    'evolution_mensuelle' => $evolutionMensuelle,
                    'taux_remplissage' => $tauxRemplissage,
                    'totaux' => [
                        'reservations' => $totalReservations,
                        'reservations_aujourdhui' => $reservationsAujourdhui,
                        'voyageurs' => $totalVoyageurs,
                        'voyages' => $totalVoyages,
                        'voyages_complets' => $voyagesComplets,
                    ],
                    'repartition_paiement' => $repartitionPaiement,
                    'prochains_voyages' => $prochainsVoyages,
                    'reservations_recentes' => $reservationsRecentes,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Registre global des factures/réservations
     * GET /api/adminCompagnie/reservations/factures
     */
    public function factures(Request $request): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            $query = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                ->whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES);

            // Filtre par dates
            if ($request->filled('date_debut')) {
                $query->whereDate('created_at', '>=', $request->date_debut);
            }
            if ($request->filled('date_fin')) {
                $query->whereDate('created_at', '<=', $request->date_fin);
            }

            // Filtre par type de paiement
            if ($request->filled('type_paie_id')) {
                $query->where('type_paie_id', $request->type_paie_id);
            }

            // Recherche par nom/telephone/numero
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('res_numero', 'ILIKE', "%{$search}%")
                      ->orWhere('numero_paiement', 'ILIKE', "%{$search}%")
                      ->orWhereHas('utilisateur', function ($uq) use ($search) {
                          $uq->where('util_nom', 'ILIKE', "%{$search}%")
                             ->orWhere('util_prenom', 'ILIKE', "%{$search}%")
                             ->orWhere('util_telephone', 'ILIKE', "%{$search}%");
                      });
                });
            }

            // Résumé avant pagination
            $totalMontant = (float)(clone $query)->sum('montant_total');
            $totalCount = (clone $query)->count();
            $totalVoyageurs = (int)(clone $query)->sum('nb_voyageurs');

            // Pagination
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $factures = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $data = $factures->getCollection()->map(function ($res) use ($typesPaiement) {
                return [
                    'res_id' => $res->res_id,
                    'res_numero' => $res->res_numero,
                    'date' => $res->created_at->format('d/m/Y H:i'),
                    'date_raw' => $res->created_at->toISOString(),
                    'canal' => (int) $res->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                    'client' => $res->utilisateur ? [
                        'nom' => $res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom,
                        'telephone' => $res->utilisateur->util_phone,
                    ] : null,
                    'trajet' => $res->voyage?->trajet ? ($res->voyage->trajet->provinceDepart->pro_nom . ' → ' . $res->voyage->trajet->provinceArrivee->pro_nom) : null,
                    'voyage_date' => $res->voyage?->voyage_date?->format('d/m/Y'),
                    'nb_voyageurs' => $res->nb_voyageurs,
                    'montant_total' => (float)$res->montant_total,
                    'montant_avance' => (float)($res->montant_avance ?? 0),
                    'montant_restant' => (float)($res->montant_restant ?? 0),
                    'type_paiement' => $typesPaiement[$res->type_paie_id] ?? 'Non défini',
                    'type_paie_id' => $res->type_paie_id,
                    'numero_paiement' => $res->numero_paiement,
                ];
            });

            return response()->json([
                'statut' => true,
                'data' => [
                    'resume' => [
                        'total_montant' => $totalMontant,
                        'total_factures' => $totalCount,
                        'total_voyageurs' => $totalVoyageurs,
                    ],
                    'factures' => $data,
                    'pagination' => [
                        'total' => $factures->total(),
                        'current_page' => $factures->currentPage(),
                        'last_page' => $factures->lastPage(),
                        'per_page' => $factures->perPage(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Scanner QR - Validation d'un billet
     * POST /api/adminCompagnie/reservations/scanner-qr
     */
    public function scannerQR(Request $request): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $request->validate([
                'qr_data' => 'required|string'
            ]);

            // Le QR affiché par l'application contient le JSON en clair ; on accepte
            // aussi l'ancien format encodé en base64.
            $brut = trim((string) $request->qr_data);
            $qrInfo = json_decode($brut, true);
            if (!is_array($qrInfo)) {
                $decoded = base64_decode($brut, true);
                $qrInfo = $decoded ? json_decode($decoded, true) : null;
            }
            if (!is_array($qrInfo)) {
                return response()->json(['statut' => false, 'message' => 'QR Code invalide.', 'validation' => 'invalide'], 400);
            }

            if (!$qrInfo || ($qrInfo['app'] ?? '') !== 'FANDRIO') {
                return response()->json(['statut' => false, 'message' => 'Ce QR Code n\'est pas un billet FANDRIO.', 'validation' => 'invalide'], 400);
            }

            $resId = $qrInfo['res_id'] ?? null;
            if (!$resId) {
                return response()->json(['statut' => false, 'message' => 'QR Code incomplet.', 'validation' => 'invalide'], 400);
            }

            $reservation = Reservation::with([
                'utilisateur',
                'voyage.trajet.provinceDepart',
                'voyage.trajet.provinceArrivee',
                'voyage.trajet',
                'voyageurs'
            ])->find($resId);

            if (!$reservation) {
                return response()->json(['statut' => false, 'message' => 'Réservation introuvable.', 'validation' => 'invalide'], 400);
            }

            // Vérifier que la réservation appartient à cette compagnie
            $voyage = $reservation->voyage;
            if (!$voyage || !$voyage->trajet || $voyage->trajet->comp_id !== $compagnieId) {
                return response()->json(['statut' => false, 'message' => 'Cette réservation ne concerne pas votre compagnie.', 'validation' => 'invalide'], 403);
            }

            // Vérifier le statut : 2 (confirmée) requis pour embarquer
            if (!in_array($reservation->res_statut, self::STATUTS_VALIDES, true)) {
                $statutLabel = match ($reservation->res_statut) {
                    1 => 'en attente de paiement',
                    4 => 'annulée',
                    5 => 'abandonnée',
                    6 => 'annulé par le client',
                    default => 'dans un état inconnu'
                };
                return response()->json([
                    'statut' => false,
                    'message' => "Ce billet est {$statutLabel}.",
                    'validation' => 'invalide'
                ], 400);
            }

            // Vérifier la date du voyage
            $estAujourdhui = $voyage->voyage_date->isToday();
            $estPasse = $voyage->voyage_date->isPast() && !$estAujourdhui;

            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');

            // Voyageurs avec statut embarquement
            $voyageurs = $reservation->voyageurs->map(fn($v) => [
                'id' => $v->voya_id,
                'nom' => $v->voya_prenom . ' ' . $v->voya_nom,
                'siege' => $v->pivot->place_numero,
                'embarque' => ($v->pivot->res_voya_statut ?? 1) >= 2,
            ]);

            $tousEmbarques = $voyageurs->every(fn($v) => $v['embarque']);

            return response()->json([
                'statut' => true,
                'validation' => $estAujourdhui ? 'valide' : ($estPasse ? 'passe' : 'futur'),
                'message' => $estAujourdhui
                    ? 'Billet valide pour aujourd\'hui !'
                    : ($estPasse
                        ? 'Ce voyage est déjà passé (' . $voyage->voyage_date->format('d/m/Y') . ')'
                        : 'Billet valide mais le voyage est prévu le ' . $voyage->voyage_date->format('d/m/Y')),
                'data' => [
                    'reservation' => [
                        'res_id' => $reservation->res_id,
                        'res_numero' => $reservation->res_numero,
                        'date_reservation' => $reservation->created_at->format('d/m/Y H:i'),
                        'montant_total' => (float)$reservation->montant_total,
                        'nb_voyageurs' => $reservation->nb_voyageurs,
                        'type_paiement' => $typesPaiement[$reservation->type_paie_id] ?? null,
                        'numero_paiement' => $reservation->numero_paiement,
                        'tous_embarques' => $tousEmbarques,
                    ],
                    'voyage' => [
                        'voyage_id' => $voyage->voyage_id,
                        'date' => $voyage->voyage_date->format('d/m/Y'),
                        'heure' => $voyage->voyage_heure_depart,
                        'trajet' => ($voyage->trajet->provinceDepart->pro_nom ?? '') . ' → ' . ($voyage->trajet->provinceArrivee->pro_nom ?? ''),
                    ],
                    'canal' => (int) $reservation->res_canal === Reservation::CANAL_GUICHET ? 'guichet' : 'en_ligne',
                    'client' => $reservation->utilisateur ? [
                        'nom' => ($reservation->utilisateur->util_prenom ?? '') . ' ' . ($reservation->utilisateur->util_nom ?? ''),
                        'telephone' => $reservation->utilisateur->util_phone ?? '',
                    ] : null,
                    'voyageurs' => $voyageurs,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e),
                'validation' => 'erreur'
            ], 500);
        }
    }

    /**
     * Marquer les voyageurs comme embarqués
     * POST /api/adminCompagnie/reservations/{resId}/embarquer
     */
    public function embarquer(Request $request, int $resId): JsonResponse
    {
        try {
            // voyageurs : identifiants (voya_id) des voyageurs présents ; absent = tout le billet
            $request->validate([
                'voyageurs' => 'sometimes|array|min:1',
                'voyageurs.*' => 'integer',
            ]);
            $compagnieId = $this->getCompagnieId();

            $reservation = Reservation::with(['voyage.trajet'])->findOrFail($resId);

            if ($reservation->voyage->trajet->comp_id !== $compagnieId) {
                return response()->json(['statut' => false, 'message' => 'Non autorisé.'], 403);
            }

            if (!in_array($reservation->res_statut, self::STATUTS_VALIDES, true)) {
                return response()->json(['statut' => false, 'message' => 'Réservation non confirmée.'], 400);
            }

            // Marquer comme embarqués (statut 2) les voyageurs présents, ou tout le billet
            $requete = DB::table('fandrio_app.reservation_voyageurs')->where('res_id', $resId);
            if ($request->filled('voyageurs')) {
                $requete->whereIn('voya_id', $request->input('voyageurs'));
            }
            $nb = $requete->update(['res_voya_statut' => 2]);

            $restants = DB::table('fandrio_app.reservation_voyageurs')
                ->where('res_id', $resId)
                ->where('res_voya_statut', '<', 2)
                ->count();

            return response()->json([
                'statut' => true,
                'message' => $nb > 1 ? "{$nb} voyageurs embarqués." : 'Voyageur embarqué.',
                'data' => ['embarques' => $nb, 'restants' => $restants],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Vente au guichet : réservation faite sur place par la compagnie, payée en espèces.
     * Créée directement confirmée (statut 2), canal guichet, SANS commission FANDRIO.
     * POST /api/adminCompagnie/reservations/guichet
     *
     * Corps : voyage_id, voyageurs[{siege, nom, prenom?, telephone?}], embarquer? (bool)
     */
    public function venteGuichet(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'voyage_id' => 'required|integer',
                'voyageurs' => 'required|array|min:1|max:60',
                'voyageurs.*.siege' => 'required|string|max:10|distinct',
                'voyageurs.*.nom' => 'required|string|max:100',
                'voyageurs.*.prenom' => 'nullable|string|max:100',
                'voyageurs.*.telephone' => 'nullable|string|max:20',
                'embarquer' => 'sometimes|boolean',
            ]);

            $compagnieId = $this->getCompagnieId();
            $admin = Auth::user();
            $voyageId = (int) $request->voyage_id;
            $sieges = array_map(fn($v) => (string) $v['siege'], $request->voyageurs);
            $siegesTries = $sieges;
            sort($siegesTries);

            // Libérer d'abord les réservations en ligne expirées de ce voyage
            app(ReservationExpireeService::class)->liberer($voyageId);

            $resultat = DB::transaction(function () use ($request, $compagnieId, $admin, $voyageId, $sieges, $siegesTries) {
                $voyage = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture.planSiege'])
                    ->where('voyage_id', $voyageId)
                    ->lockForUpdate()
                    ->first();

                if (!$voyage || !$voyage->trajet || (int) $voyage->trajet->comp_id !== $compagnieId) {
                    throw new \RuntimeException('Voyage introuvable.');
                }
                // Programmé ou en cours (embarquement), actif, et pas un jour passé
                if (!in_array((int) $voyage->voyage_statut, [1, 2], true)
                    || !$voyage->voyage_is_active
                    || $voyage->voyage_date->lt(today())) {
                    throw new \RuntimeException("Ce voyage n'est plus ouvert à la vente.");
                }

                $planSiege = $voyage->voiture?->planSiege;
                if (!$planSiege) {
                    throw new \RuntimeException('Aucun plan de sièges configuré pour ce véhicule.');
                }
                $codesPlan = array_map(fn($s) => (string) $s['code'], $planSiege->getSiegesParses());
                $inconnus = array_diff($sieges, $codesPlan);
                if (!empty($inconnus)) {
                    throw new \RuntimeException('Siège(s) inexistant(s) : ' . implode(', ', $inconnus) . '.');
                }

                // Verrouiller les sièges (ordre stable : pas d'interblocage)
                $maintenant = now();
                SiegeReserve::insertOrIgnore(array_map(fn($numero) => [
                    'voyage_id' => $voyageId,
                    'siege_numero' => $numero,
                    'siege_statut' => 2,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ], $siegesTries));

                $siegesVerrouilles = SiegeReserve::where('voyage_id', $voyageId)
                    ->whereIn('siege_numero', $siegesTries)
                    ->orderBy('siege_numero')
                    ->lockForUpdate()
                    ->get();

                foreach ($siegesVerrouilles as $siege) {
                    if (!$siege->estDisponible()) {
                        throw new \RuntimeException("Le siège {$siege->siege_numero} n'est plus disponible.");
                    }
                }

                $montantTotal = round((float) $voyage->trajet->traj_tarif * count($sieges), 2);
                $typeEspeces = TypePaiement::whereIn('type_paie_nom', ['Cash', 'Espèces'])->value('type_paie_id');

                $reservation = Reservation::create([
                    'res_numero' => 'GUI-' . strtoupper(substr(uniqid(), -8)),
                    'util_id' => $admin->util_id, // vendeur (colonne NOT NULL)
                    'voyage_id' => $voyageId,
                    'res_statut' => 2, // payée sur place
                    'nb_voyageurs' => count($sieges),
                    'montant_total' => $montantTotal,
                    'montant_avance' => $montantTotal,
                    'montant_restant' => 0,
                    'type_paie_id' => $typeEspeces,
                    'res_canal' => Reservation::CANAL_GUICHET,
                    'res_commission' => 0, // pas de commission FANDRIO au guichet
                    'res_commission_taux' => 0,
                ]);
                $reservation->refresh(); // res_numero est attribué par un trigger SQL

                $embarquer = $request->boolean('embarquer');
                $voyageurs = [];
                foreach ($request->voyageurs as $v) {
                    $voyageur = Voyageur::create([
                        'voya_nom' => trim($v['nom']),
                        'voya_prenom' => trim($v['prenom'] ?? ''),
                        'voya_phone' => $v['telephone'] ?? null,
                        'util_id' => $admin->util_id,
                    ]);
                    ReservationVoyageur::create([
                        'res_id' => $reservation->res_id,
                        'voya_id' => $voyageur->voya_id,
                        'place_numero' => $v['siege'],
                        'res_voya_statut' => $embarquer ? 2 : 1,
                    ]);
                    $voyageurs[] = [
                        'id' => $voyageur->voya_id,
                        'nom' => trim(($v['prenom'] ?? '') . ' ' . $v['nom']),
                        'siege' => (string) $v['siege'],
                        'embarque' => $embarquer,
                    ];
                }

                SiegeReserve::where('voyage_id', $voyageId)
                    ->whereIn('siege_numero', $siegesTries)
                    ->update([
                        'siege_statut' => 1,
                        'res_id' => $reservation->res_id,
                        'utilisateur_id' => $admin->util_id,
                        'expire_lock' => null,
                    ]);

                $trajet = ($voyage->trajet->provinceDepart->pro_nom ?? '') . ' → ' . ($voyage->trajet->provinceArrivee->pro_nom ?? '');

                return [
                    'reservation' => $reservation,
                    'voyageurs' => $voyageurs,
                    'trajet' => $trajet,
                    'voyage' => $voyage,
                ];
            });

            $reservation = $resultat['reservation'];

            // Temps réel : les sièges vendus disparaissent du plan des clients
            foreach ($sieges as $numero) {
                try {
                    broadcast(new SiegeUpdated(
                        $voyageId,
                        $numero,
                        'reserve',
                        $admin->util_id,
                        ['siege' => $numero, 'statut' => 1, 'disponible' => false, 'utilisateur_id' => $admin->util_id, 'expire_lock' => null]
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Broadcast siège (guichet) échoué : ' . $e->getMessage());
                }
            }

            // Même format de QR que les billets en ligne : scannable à l'embarquement
            $qrData = base64_encode(json_encode([
                'app' => 'FANDRIO',
                'res_numero' => $reservation->res_numero,
                'res_id' => $reservation->res_id,
                'client' => ['nom' => $resultat['voyageurs'][0]['nom'] ?? '', 'telephone' => ''],
                'voyage_id' => $voyageId,
                'trajet' => $resultat['trajet'],
            ]));

            return response()->json([
                'statut' => true,
                'message' => count($sieges) > 1 ? count($sieges) . ' places vendues.' : 'Place vendue.',
                'data' => [
                    'res_id' => $reservation->res_id,
                    'res_numero' => $reservation->res_numero,
                    'montant_total' => (float) $reservation->montant_total,
                    'trajet' => $resultat['trajet'],
                    'date' => $resultat['voyage']->voyage_date->format('d/m/Y'),
                    'heure' => $resultat['voyage']->voyage_heure_depart,
                    'voyageurs' => $resultat['voyageurs'],
                    'qr_data' => $qrData,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la vente : ' . $this->messageErreur($e),
            ], 500);
        }
    }

    /**
     * Portefeuille de la compagnie — vue financière avec commission plateforme
     * Commission FANDRIO : 5% par billet vendu (par voyageur)
     * GET /api/adminCompagnie/reservations/portefeuille
     */
    public function portefeuille(Request $request): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();
            $tauxCommission = 0.05; // 5%

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            $reservationsBase = Reservation::whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES);

            // ── Solde global ──
            // Commission FANDRIO : sur les ventes EN LIGNE uniquement (le guichet n'en paie pas)
            $enLigne = fn($q) => (float)(clone $q)->where('res_canal', Reservation::CANAL_EN_LIGNE)->sum('montant_total');
            $revenuBrut = (float)(clone $reservationsBase)->sum('montant_total');
            $revenuGuichet = round($revenuBrut - $enLigne($reservationsBase), 2);
            $totalBillets = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $commissionTotale = round($enLigne($reservationsBase) * $tauxCommission, 2);
            $revenuNet = round($revenuBrut - $commissionTotale, 2);

            // ── Solde par période ──
            $periodes = [
                'aujourdhui' => (clone $reservationsBase)->whereDate('created_at', today()),
                'cette_semaine' => (clone $reservationsBase)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
                'ce_mois' => (clone $reservationsBase)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year),
                'cette_annee' => (clone $reservationsBase)->whereYear('created_at', now()->year),
            ];

            $soldeParPeriode = [];
            foreach ($periodes as $key => $query) {
                $brut = (float)(clone $query)->sum('montant_total');
                $billets = (int)(clone $query)->sum('nb_voyageurs');
                $commission = round($enLigne($query) * $tauxCommission, 2);
                $soldeParPeriode[$key] = [
                    'brut' => $brut,
                    'guichet' => round($brut - $enLigne($query), 2),
                    'commission' => $commission,
                    'net' => round($brut - $commission, 2),
                    'billets' => $billets,
                ];
            }

            // ── Évolution mensuelle (12 derniers mois) ──
            $evolutionMensuelle = (clone $reservationsBase)
                ->where('created_at', '>=', now()->subMonths(12))
                ->select(
                    DB::raw("TO_CHAR(created_at, 'YYYY-MM') as mois"),
                    DB::raw("SUM(montant_total) as brut"),
                    DB::raw("SUM(CASE WHEN res_canal = 1 THEN montant_total ELSE 0 END) as brut_en_ligne"),
                    DB::raw("SUM(nb_voyageurs) as billets"),
                    DB::raw("COUNT(*) as nb_reservations")
                )
                ->groupBy('mois')
                ->orderBy('mois')
                ->get()
                ->map(fn($item) => [
                    'mois' => $item->mois,
                    'brut' => (float)$item->brut,
                    'commission' => round((float)$item->brut_en_ligne * $tauxCommission, 2),
                    'net' => round((float)$item->brut - (float)$item->brut_en_ligne * $tauxCommission, 2),
                    'billets' => (int)$item->billets,
                    'nb_reservations' => (int)$item->nb_reservations,
                ]);

            // ── Répartition par opérateur ──
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $repartitionOperateur = (clone $reservationsBase)
                ->select('type_paie_id', DB::raw('SUM(montant_total) as brut'), DB::raw('SUM(CASE WHEN res_canal = 1 THEN montant_total ELSE 0 END) as brut_en_ligne'), DB::raw('SUM(nb_voyageurs) as billets'), DB::raw('COUNT(*) as nb_transactions'))
                ->groupBy('type_paie_id')
                ->get()
                ->map(fn($item) => [
                    'operateur' => $typesPaiement[$item->type_paie_id] ?? 'Non défini',
                    'type_id' => $item->type_paie_id,
                    'brut' => (float)$item->brut,
                    'commission' => round((float)$item->brut_en_ligne * $tauxCommission, 2),
                    'net' => round((float)$item->brut - (float)$item->brut_en_ligne * $tauxCommission, 2),
                    'billets' => (int)$item->billets,
                    'nb_transactions' => (int)$item->nb_transactions,
                ]);

            // ── Historique des transactions (paginé) ──
            $query = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                ->whereIn('voyage_id', $voyageIds)
                ->whereIn('res_statut', self::STATUTS_ENCAISSES);

            // Filtres optionnels
            if ($request->filled('date_debut')) {
                $query->whereDate('created_at', '>=', $request->date_debut);
            }
            if ($request->filled('date_fin')) {
                $query->whereDate('created_at', '<=', $request->date_fin);
            }
            if ($request->filled('type_paie_id')) {
                $query->where('type_paie_id', $request->type_paie_id);
            }

            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            $transactionsData = $transactions->getCollection()->map(function ($res) use ($typesPaiement, $tauxCommission) {
                $brut = (float)$res->montant_total;
                $guichet = (int) $res->res_canal === Reservation::CANAL_GUICHET;
                $commission = $guichet ? 0.0 : round($brut * $tauxCommission, 2);
                return [
                    'res_id' => $res->res_id,
                    'res_numero' => $res->res_numero,
                    'date' => $res->created_at->format('d/m/Y H:i'),
                    'date_raw' => $res->created_at->toISOString(),
                    'client' => $guichet
                        ? 'Vente au guichet'
                        : ($res->utilisateur ? ($res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom) : null),
                    'canal' => $guichet ? 'guichet' : 'en_ligne',
                    'trajet' => $res->voyage?->trajet
                        ? ($res->voyage->trajet->provinceDepart->pro_nom . ' → ' . $res->voyage->trajet->provinceArrivee->pro_nom)
                        : null,
                    'voyage_date' => $res->voyage?->voyage_date?->format('d/m/Y'),
                    'nb_voyageurs' => $res->nb_voyageurs,
                    'brut' => $brut,
                    'commission' => $commission,
                    'net' => round($brut - $commission, 2),
                    'operateur' => $typesPaiement[$res->type_paie_id] ?? 'Non défini',
                    'type_paie_id' => $res->type_paie_id,
                    'numero_paiement' => $res->numero_paiement,
                ];
            });

            return response()->json([
                'statut' => true,
                'data' => [
                    'taux_commission' => $tauxCommission * 100, // 5
                    'solde' => [
                        'brut' => $revenuBrut,
                        'commission' => $commissionTotale,
                        'net' => $revenuNet,
                        'guichet' => $revenuGuichet,
                        'total_billets' => $totalBillets,
                        'total_reservations' => (int)(clone $reservationsBase)->count(),
                    ],
                    'par_periode' => $soldeParPeriode,
                    'evolution_mensuelle' => $evolutionMensuelle,
                    'repartition_operateur' => $repartitionOperateur,
                    'transactions' => $transactionsData,
                    'pagination' => [
                        'total' => $transactions->total(),
                        'current_page' => $transactions->currentPage(),
                        'last_page' => $transactions->lastPage(),
                        'per_page' => $transactions->perPage(),
                    ],
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }

    /**
     * Informations de collecte de commission pour la compagnie
     * GET /api/adminCompagnie/reservations/ma-collecte
     */
    public function maCollecte(): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();
            $compagnie = Compagnie::findOrFail($compagnieId);

            // Configuration de collecte
            $config = [
                'frequence' => $compagnie->comm_frequence_collecte ?? 'mensuelle',
                'jour_collecte' => $compagnie->comm_jour_collecte,
                'commission_active' => $compagnie->comm_actif ?? true,
                'taux' => 5,
            ];

            // Prochaine collecte prévue
            $prochaineCollecte = Collecte::where('comp_id', $compagnieId)
                ->where('coll_statut', Collecte::EN_ATTENTE)
                ->orderBy('coll_date_prevue')
                ->first();

            // Historique des collectes
            $collectes = Collecte::where('comp_id', $compagnieId)
                ->orderByDesc('coll_date_prevue')
                ->limit(20)
                ->get()
                ->map(fn($c) => [
                    'coll_id'            => $c->coll_id,
                    'periode_debut'      => $c->coll_periode_debut->format('d/m/Y'),
                    'periode_fin'        => $c->coll_periode_fin->format('d/m/Y'),
                    'montant_brut'       => (float)$c->coll_montant_brut,
                    'montant_commission' => (float)$c->coll_montant_commission,
                    'taux'               => (float)$c->coll_taux,
                    'nb_reservations'    => $c->coll_nb_reservations,
                    'nb_billets'         => $c->coll_nb_billets,
                    'statut'             => $c->coll_statut,
                    'statut_label'       => $c->coll_statut === Collecte::CONFIRMEE ? 'Confirmée' : 'En attente',
                    'date_prevue'        => $c->coll_date_prevue->format('d/m/Y'),
                    'date_confirmation'  => $c->coll_date_confirmation?->format('d/m/Y H:i'),
                    'payable'            => $c->coll_statut === Collecte::EN_ATTENTE,
                    'en_retard'          => $c->coll_statut === Collecte::EN_ATTENTE && $c->coll_date_prevue->lt(today()),
                ]);

            // Totaux
            $totalCollecte = Collecte::where('comp_id', $compagnieId)
                ->where('coll_statut', Collecte::CONFIRMEE)
                ->sum('coll_montant_commission');

            $totalEnAttente = Collecte::where('comp_id', $compagnieId)
                ->where('coll_statut', Collecte::EN_ATTENTE)
                ->sum('coll_montant_commission');

            return response()->json([
                'statut' => true,
                'data' => [
                    'config' => $config,
                    'suspendu' => (bool) $compagnie->comp_suspendu_commission,
                    'prochaine_collecte' => $prochaineCollecte ? [
                        'date_prevue' => $prochaineCollecte->coll_date_prevue->format('d/m/Y'),
                        'montant' => (float)$prochaineCollecte->coll_montant_commission,
                        'periode' => $prochaineCollecte->coll_periode_debut->format('d/m/Y') . ' → ' . $prochaineCollecte->coll_periode_fin->format('d/m/Y'),
                    ] : null,
                    'totaux' => [
                        'total_collecte' => (float)$totalCollecte,
                        'total_en_attente' => (float)$totalEnAttente,
                        'nb_collectes' => $collectes->count(),
                    ],
                    'collectes' => $collectes,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $this->messageErreur($e)
            ], 500);
        }
    }
}
