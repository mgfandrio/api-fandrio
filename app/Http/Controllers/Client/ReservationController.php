<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Helpers\DateFormatter;
use App\Models\Reservation\ReservationVoyageur;
use App\Models\Voitures\SiegeReserve;
use App\Models\Voyageur\Voyageur;
use App\Models\Voyages\Voyage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class ReservationController extends Controller
{
    /**
     * Récupère les données du tableau de bord de réservation pour l'utilisateur connecté
     * GET /api/client/reservation/dashboard
     */
    public function dashboard(): JsonResponse
    {
        try {
            $utilisateur = Auth::user();
            $reservations = Reservation::with(['voyage.trajet.depart', 'voyage.trajet.arrivee'])
                ->where('util_id', $utilisateur->util_id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Calcul des statistiques
            $stats = [
                'total_reservations' => $reservations->count(),
                'voyages_en_cours' => $reservations->where('res_statut', 2)->filter(function($res) {
                    return $res->voyage && $res->voyage->voyage_date->isToday();
                })->count(),
                'voyages_annules' => $reservations->where('res_statut', 4)->count(),
            ];

            // Historique récent (3 derniers)
            $historique = $reservations->take(3)->map(function($res) {
                return [
                    'id' => $res->res_id,
                    'numero' => $res->res_numero,
                    'trajet' => ($res->voyage && $res->voyage->trajet) ? 
                        ($res->voyage->trajet->depart->pro_nom . ' → ' . $res->voyage->trajet->arrivee->pro_nom) : 'Trajet inconnu',
                    'date' => $res->voyage ? $res->voyage->voyage_date->format('d/m/Y') : 'N/A',
                    'heure' => $res->voyage ? $res->voyage->voyage_heure : 'N/A',
                    'montant' => $res->montant_total,
                    'statut' => $res->res_statut, // 1: En attente, 2: Confirmé, 3: Terminé, 4: Annulé
                    'nb_voyageurs' => $res->nb_voyageurs
                ];
            });

            return response()->json([
                'statut' => true,
                'message' => 'Dashboard de réservation récupéré avec succès',
                'data' => [
                    'stats' => $stats,
                    'historique' => $historique
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération du dashboard: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crée une nouvelle réservation (Étape 1-4 du Wizard)
     * POST /api/client/reservation
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'voyage_id' => 'required|integer|exists:fandrio_app.voyages,voyage_id',
                'nb_voyageurs' => 'required|integer|min:1',
                'montant_total' => 'required|numeric|min:0',
                'sieges' => 'required|array|min:1',
                'sieges.*' => 'string',
                'voyageurs' => 'required|array|min:1',
                'voyageurs.*.nom' => 'required|string',
                'voyageurs.*.prenom' => 'required|string',
                'voyageurs.*.phone' => 'nullable|string',
                'voyageurs.*.siege_numero' => 'required|string',
            ]);

            return DB::transaction(function () use ($request) {
                $utilisateur = Auth::user();
                $voyageId = $request->voyage_id;
                $siegesNumeros = $request->sieges;

                // 1. Vérifier la disponibilité des sièges
                foreach ($siegesNumeros as $numero) {
                    $siege = SiegeReserve::firstOrCreate(
                        ['voyage_id' => $voyageId, 'siege_numero' => $numero],
                        ['siege_statut' => 2]
                    );

                    if (!$siege->estDisponible()) {
                        throw new \Exception("Le siège $numero n'est plus disponible.");
                    }
                }

                // 2. Créer la réservation (Statut 1: En attente)
                $reservation = Reservation::create([
                    'res_numero' => 'RES-' . strtoupper(substr(uniqid(), -8)),
                    'util_id' => $utilisateur->util_id,
                    'voyage_id' => $voyageId,
                    'res_statut' => 1,
                    'nb_voyageurs' => $request->nb_voyageurs,
                    'montant_total' => $request->montant_total,
                    'date_limite_paiement' => now()->addMinutes(2),
                ]);

                // 3. Associer les voyageurs
                foreach ($request->voyageurs as $vData) {
                    $voyageur = Voyageur::create([
                        'voya_nom' => $vData['nom'],
                        'voya_prenom' => $vData['prenom'],
                        'voya_phone' => $vData['phone'] ?? null,
                        'util_id' => $utilisateur->util_id
                    ]);

                    ReservationVoyageur::create([
                        'res_id' => $reservation->res_id,
                        'voya_id' => $voyageur->voya_id,
                        'place_numero' => $vData['siege_numero'],
                        'res_voya_statut' => 1
                    ]);

                    // 4. Verrouiller le siège (Statut 3: Sélectionné/En attente)
                    SiegeReserve::where('voyage_id', $voyageId)
                        ->where('siege_numero', $vData['siege_numero'])
                        ->update([
                            'siege_statut' => 3,
                            'res_id' => $reservation->res_id,
                            'utilisateur_id' => $utilisateur->util_id,
                            'expire_lock' => $reservation->date_limite_paiement
                        ]);
                }

                // Mise à jour du nombre de places réservées dans le voyage (optionnel, selon la logique métier)
                // Voyage::where('voyage_id', $voyageId)->increment('places_reservees', $request->nb_voyageurs);

                return response()->json([
                    'statut' => true,
                    'message' => 'Réservation créée avec succès. Vous avez 2 minutes pour confirmer.',
                    'data' => [
                        'res_id' => $reservation->res_id,
                        'res_numero' => $reservation->res_numero,
                        'date_limite_paiement' => $reservation->date_limite_paiement->toIso8601String()
                    ]
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la création de la réservation: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Confirme le paiement et la réservation (Étape 5)
     * POST /api/client/reservation/{id}/confirm
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        try {
            $reservation = Reservation::findOrFail($id);

            if ($reservation->res_statut != 1) {
                return response()->json(['statut' => false, 'message' => 'Cette réservation ne peut plus être confirmée.'], 400);
            }

            if (now()->greaterThan($reservation->date_limite_paiement)) {
                // Optionnel: libérer les sièges ici si pas fait par un cron
                return response()->json(['statut' => false, 'message' => 'Le délai de confirmation est dépassé.'], 400);
            }

            $request->validate([
                'type_paie_id' => 'required|integer|exists:fandrio_app.types_paiement,type_paie_id',
                'numero_paiement' => 'nullable|string'
            ]);

            DB::transaction(function() use ($reservation, $request) {
                $reservation->update([
                    'res_statut' => 2, // Confirmé
                    'type_paie_id' => $request->type_paie_id,
                    'numero_paiement' => $request->numero_paiement,
                    'date_limite_paiement' => null // Délai levé
                ]);

                // Marquer les sièges comme définitivement réservés (Statut 1)
                SiegeReserve::where('res_id', $reservation->res_id)->update([
                    'siege_statut' => 1,
                    'expire_lock' => null
                ]);

                // Incrémenter les places réservées dans le voyage
                Voyage::where('voyage_id', $reservation->voyage_id)->increment('places_reservees', $reservation->nb_voyageurs);
            });

            return response()->json([
                'statut' => true,
                'message' => 'Réservation confirmée avec succès.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la confirmation: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Récupère les données de la facture / Ticket (Étape 6)
     * GET /api/client/reservation/{id}/invoice
     */
    public function getInvoice(int $id): JsonResponse
    {
        try {
            $reservation = Reservation::with([
                'voyage.trajet.depart',
                'voyage.trajet.arrivee',
                'voyage.trajet.compagnie',
                'voyage.voiture.chauffeur',
                'voyageurs'
            ])->findOrFail($id);

            // Génération d'une chaîne pour le QR Code
            $qrData = json_encode([
                'res' => $reservation->res_numero,
                'client' => $reservation->utilisateur->util_nom ?? 'Client',
                'voyage' => $reservation->voyage_id,
                'date' => $reservation->voyage->voyage_date->format('Y-m-d')
            ]);

            return response()->json([
                'statut' => true,
                'data' => [
                    'reservation' => [
                        'id' => $reservation->res_id,
                        'numero' => $reservation->res_numero,
                        'statut' => $reservation->res_statut,
                        'montant' => $reservation->montant_total,
                        'date_reservation' => $reservation->created_at->format('d/m/Y H:i'),
                        'qr_string' => base64_encode($qrData) // On envoie le JSON encodé pour le QR
                    ],
                    'voyage' => [
                        'depart' => $reservation->voyage->trajet->depart->pro_nom,
                        'arrivee' => $reservation->voyage->trajet->arrivee->pro_nom,
                        'date' => $reservation->voyage->voyage_date->format('d/m/Y'),
                        'heure' => $reservation->voyage->voyage_heure_depart,
                        'compagnie' => $reservation->voyage->trajet->compagnie->comp_nom,
                        'matricule' => $reservation->voyage->voiture->voit_matricule
                    ],
                    'voyageurs' => $reservation->voyageurs->map(function($v) {
                        return [
                            'nom' => $v->voya_nom . ' ' . $v->voya_prenom,
                            'siege' => $v->pivot->place_numero
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération de la facture: ' . $e->getMessage()
            ], 404);
        }
    }
}
