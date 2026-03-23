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
}
