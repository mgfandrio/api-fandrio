<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Models\Paiements\TypePaiement;
use App\Models\Voyages\Voyage;
use App\Services\Voiture\SiegeService;
use App\Services\Voyage\VoyageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReservationAdminController extends Controller
{
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

            // Auto-complétion des voyages (places pleines, heure départ atteinte, date passée)
            VoyageService::autoCompleterVoyages($compagnieId);

            $query = Voyage::with([
                'trajet.provinceDepart',
                'trajet.provinceArrivee',
                'voiture'
            ])
            ->whereHas('trajet', function ($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })
            ->whereHas('reservations', function ($q) {
                $q->where('res_statut', 2);
            })
            ->withCount(['reservations as reservations_validees_count' => function ($q) {
                $q->where('res_statut', 2);
            }])
            ->withSum(['reservations as total_voyageurs' => function ($q) {
                $q->where('res_statut', 2);
            }], 'nb_voyageurs')
            ->withSum(['reservations as revenu_total' => function ($q) {
                $q->where('res_statut', 2);
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
                $q->where('res_statut', 2);
            });

            $totalRevenu = Reservation::whereIn('voyage_id',
                Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->pluck('voyage_id')
            )->where('res_statut', 2)->sum('montant_total');

            $totalReservations = Reservation::whereIn('voyage_id',
                Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->pluck('voyage_id')
            )->where('res_statut', 2)->count();

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
                'message' => 'Erreur: ' . $e->getMessage()
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
                'message' => 'Erreur: ' . $e->getMessage()
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
                ->where('res_statut', 2)
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
                        'client' => $reservation->utilisateur ? [
                            'nom' => $reservation->utilisateur->util_nom,
                            'prenom' => $reservation->utilisateur->util_prenom,
                            'telephone' => $reservation->utilisateur->util_telephone,
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
                'message' => 'Erreur: ' . $e->getMessage()
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
                ->where('res_statut', 2)
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
                'message' => 'Erreur: ' . $e->getMessage()
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

            // Auto-complétion des voyages
            VoyageService::autoCompleterVoyages($compagnieId);

            $voyageIds = Voyage::whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->pluck('voyage_id');

            // Réservations validées
            $reservationsValidees = Reservation::whereIn('voyage_id', $voyageIds)
                ->where('res_statut', 2);

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
                ->where('res_statut', 2)
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
                'message' => 'Erreur: ' . $e->getMessage()
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
                ->where('res_statut', 2);

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
                ->where('res_statut', 2)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($res) => [
                    'res_numero' => $res->res_numero,
                    'date' => $res->created_at->format('d/m/Y H:i'),
                    'montant' => (float)$res->montant_total,
                    'nb_voyageurs' => $res->nb_voyageurs,
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
                'message' => 'Erreur: ' . $e->getMessage()
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
                ->where('res_statut', 2);

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
                    'client' => $res->utilisateur ? [
                        'nom' => $res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom,
                        'telephone' => $res->utilisateur->util_telephone,
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
                'message' => 'Erreur: ' . $e->getMessage()
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

            // Décoder le QR (base64 → JSON)
            $decoded = base64_decode($request->qr_data, true);
            if (!$decoded) {
                return response()->json(['statut' => false, 'message' => 'QR Code invalide.', 'validation' => 'invalide'], 400);
            }

            $qrInfo = json_decode($decoded, true);
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

            // Vérifier le statut
            if ($reservation->res_statut !== 2) {
                $statutLabel = match ($reservation->res_statut) {
                    1 => 'en attente de paiement',
                    3 => 'terminée',
                    4 => 'annulée',
                    5 => 'abandonnée',
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
                    'client' => $reservation->utilisateur ? [
                        'nom' => ($reservation->utilisateur->util_prenom ?? '') . ' ' . ($reservation->utilisateur->util_nom ?? ''),
                        'telephone' => $reservation->utilisateur->util_telephone ?? '',
                    ] : null,
                    'voyageurs' => $voyageurs,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
                'validation' => 'erreur'
            ], 500);
        }
    }

    /**
     * Marquer les voyageurs comme embarqués
     * POST /api/adminCompagnie/reservations/{resId}/embarquer
     */
    public function embarquer(int $resId): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieId();

            $reservation = Reservation::with(['voyage.trajet'])->findOrFail($resId);

            if ($reservation->voyage->trajet->comp_id !== $compagnieId) {
                return response()->json(['statut' => false, 'message' => 'Non autorisé.'], 403);
            }

            if ($reservation->res_statut !== 2) {
                return response()->json(['statut' => false, 'message' => 'Réservation non confirmée.'], 400);
            }

            // Marquer tous les voyageurs comme embarqués (statut 2)
            DB::table('fandrio_app.reservation_voyageurs')
                ->where('res_id', $resId)
                ->update(['res_voya_statut' => 2]);

            return response()->json([
                'statut' => true,
                'message' => 'Voyageurs marqués comme embarqués avec succès.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur: ' . $e->getMessage()
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
                ->where('res_statut', 2);

            // ── Solde global ──
            $revenuBrut = (float)(clone $reservationsBase)->sum('montant_total');
            $totalBillets = (int)(clone $reservationsBase)->sum('nb_voyageurs');
            $commissionTotale = round($revenuBrut * $tauxCommission, 2);
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
                $commission = round($brut * $tauxCommission, 2);
                $soldeParPeriode[$key] = [
                    'brut' => $brut,
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
                    DB::raw("SUM(nb_voyageurs) as billets"),
                    DB::raw("COUNT(*) as nb_reservations")
                )
                ->groupBy('mois')
                ->orderBy('mois')
                ->get()
                ->map(fn($item) => [
                    'mois' => $item->mois,
                    'brut' => (float)$item->brut,
                    'commission' => round((float)$item->brut * $tauxCommission, 2),
                    'net' => round((float)$item->brut * (1 - $tauxCommission), 2),
                    'billets' => (int)$item->billets,
                    'nb_reservations' => (int)$item->nb_reservations,
                ]);

            // ── Répartition par opérateur ──
            $typesPaiement = TypePaiement::pluck('type_paie_nom', 'type_paie_id');
            $repartitionOperateur = (clone $reservationsBase)
                ->select('type_paie_id', DB::raw('SUM(montant_total) as brut'), DB::raw('SUM(nb_voyageurs) as billets'), DB::raw('COUNT(*) as nb_transactions'))
                ->groupBy('type_paie_id')
                ->get()
                ->map(fn($item) => [
                    'operateur' => $typesPaiement[$item->type_paie_id] ?? 'Non défini',
                    'type_id' => $item->type_paie_id,
                    'brut' => (float)$item->brut,
                    'commission' => round((float)$item->brut * $tauxCommission, 2),
                    'net' => round((float)$item->brut * (1 - $tauxCommission), 2),
                    'billets' => (int)$item->billets,
                    'nb_transactions' => (int)$item->nb_transactions,
                ]);

            // ── Historique des transactions (paginé) ──
            $query = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                ->whereIn('voyage_id', $voyageIds)
                ->where('res_statut', 2);

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
                $commission = round($brut * $tauxCommission, 2);
                return [
                    'res_id' => $res->res_id,
                    'res_numero' => $res->res_numero,
                    'date' => $res->created_at->format('d/m/Y H:i'),
                    'date_raw' => $res->created_at->toISOString(),
                    'client' => $res->utilisateur
                        ? ($res->utilisateur->util_prenom . ' ' . $res->utilisateur->util_nom)
                        : null,
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
                'message' => 'Erreur: ' . $e->getMessage()
            ], 500);
        }
    }
}
