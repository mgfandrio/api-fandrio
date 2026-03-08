<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Helpers\DateFormatter;

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
}
