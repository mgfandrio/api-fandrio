<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Consultation des remboursements côté client.
 */
class RemboursementClientController extends Controller
{
    /**
     * GET /client/remboursements
     * Liste paginée des remboursements (en attente, traités, refusés) du client connecté.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $utilId = (int) Auth::id();
            $perPage = (int) $request->query('per_page', 15);

            $page = Reservation::with([
                'voyage.trajet.provinceDepart:pro_id,pro_nom',
                'voyage.trajet.provinceArrivee:pro_id,pro_nom',
                'voyage.trajet.compagnie:comp_id,comp_nom',
            ])
                ->where('util_id', $utilId)
                ->where('res_remb_statut', '>', 0)
                ->orderByRaw('CASE WHEN res_remb_statut = 1 THEN 0 ELSE 1 END')
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            $items = $page->getCollection()->map(function (Reservation $r) {
                $depart = $r->voyage?->trajet?->provinceDepart?->pro_nom ?? 'N/A';
                $arrivee = $r->voyage?->trajet?->provinceArrivee?->pro_nom ?? 'N/A';

                return [
                    'res_id' => $r->res_id,
                    'res_numero' => $r->res_numero,
                    'voyage' => [
                        'trajet' => "{$depart} → {$arrivee}",
                        'date' => $r->voyage?->voyage_date?->format('d/m/Y'),
                        'heure' => $r->voyage?->voyage_heure_depart,
                        'compagnie' => $r->voyage?->trajet?->compagnie?->comp_nom,
                    ],
                    'montant' => (float) ($r->res_remb_montant ?? $r->montant_avance),
                    'statut' => $r->res_remb_statut, // 1, 2, 3
                    'statut_label' => match ($r->res_remb_statut) {
                        1 => 'En attente',
                        2 => 'Traité',
                        3 => 'Refusé',
                        default => 'Inconnu',
                    },
                    'date_traitement' => $r->res_remb_date?->format('d/m/Y H:i'),
                    'reference' => $r->res_remb_reference,
                    'note' => $r->res_remb_note,
                    'date_annulation' => $r->updated_at?->format('d/m/Y H:i'),
                ];
            });

            // Statistiques rapides
            $base = Reservation::where('util_id', $utilId)->where('res_remb_statut', '>', 0);
            $stats = [
                'en_attente' => (clone $base)->where('res_remb_statut', 1)->count(),
                'total_a_recevoir' => (float) (clone $base)->where('res_remb_statut', 1)->sum('res_remb_montant'),
                'total_recu' => (float) (clone $base)->where('res_remb_statut', 2)->sum('res_remb_montant'),
            ];

            return response()->json([
                'statut' => true,
                'data' => [
                    'items' => $items,
                    'stats' => $stats,
                    'pagination' => [
                        'total' => $page->total(),
                        'current_page' => $page->currentPage(),
                        'last_page' => $page->lastPage(),
                        'per_page' => $page->perPage(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Client remboursements index error: ' . $e->getMessage());
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
