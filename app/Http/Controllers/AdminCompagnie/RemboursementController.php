<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestion des remboursements clients par la compagnie.
 *
 * Cycle de vie d'un remboursement :
 *   res_remb_statut = 0 : non applicable (pas de paiement reçu)
 *                  = 1 : en attente (à traiter par la compagnie)
 *                  = 2 : traité (référence transaction enregistrée)
 *                  = 3 : refusé (cas exceptionnel + motif)
 */
class RemboursementController extends Controller
{
    /**
     * Récupère l'identifiant de la compagnie de l'utilisateur connecté.
     */
    private function getCompagnieUtilisateur(): int
    {
        return (int) Auth::user()->comp_id;
    }

    /**
     * GET /adminCompagnie/remboursements
     * Liste paginée des remboursements de la compagnie, avec filtres.
     *
     * Query params :
     *   - statut : 'en_attente' | 'traites' | 'refuses' | 'all'  (défaut : en_attente)
     *   - search : recherche sur res_numero ou nom client
     *   - per_page : défaut 15
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieUtilisateur();
            $statut = $request->query('statut', 'en_attente');
            $search = trim((string) $request->query('search', ''));
            $perPage = (int) $request->query('per_page', 15);

            $query = Reservation::with([
                'utilisateur:util_id,util_nom,util_prenom,util_email,util_tel',
                'voyage.trajet.provinceDepart:pro_id,pro_nom',
                'voyage.trajet.provinceArrivee:pro_id,pro_nom',
            ])
                ->whereHas('voyage.trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->where('res_remb_statut', '>', 0);

            if ($statut === 'en_attente') {
                $query->where('res_remb_statut', 1);
            } elseif ($statut === 'traites') {
                $query->where('res_remb_statut', 2);
            } elseif ($statut === 'refuses') {
                $query->where('res_remb_statut', 3);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('res_numero', 'ILIKE', "%{$search}%")
                      ->orWhereHas('utilisateur', function ($q2) use ($search) {
                          $q2->where('util_nom', 'ILIKE', "%{$search}%")
                             ->orWhere('util_prenom', 'ILIKE', "%{$search}%")
                             ->orWhere('util_email', 'ILIKE', "%{$search}%");
                      });
                });
            }

            $page = $query->orderByRaw('CASE WHEN res_remb_statut = 1 THEN 0 ELSE 1 END')
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            $items = $page->getCollection()->map(function (Reservation $r) {
                $depart = $r->voyage?->trajet?->provinceDepart?->pro_nom ?? 'N/A';
                $arrivee = $r->voyage?->trajet?->provinceArrivee?->pro_nom ?? 'N/A';
                return [
                    'res_id' => $r->res_id,
                    'res_numero' => $r->res_numero,
                    'client' => [
                        'util_id' => $r->utilisateur?->util_id,
                        'nom' => trim(($r->utilisateur?->util_prenom ?? '') . ' ' . ($r->utilisateur?->util_nom ?? '')),
                        'email' => $r->utilisateur?->util_email,
                        'telephone' => $r->utilisateur?->util_tel,
                    ],
                    'voyage' => [
                        'trajet' => "{$depart} → {$arrivee}",
                        'date' => $r->voyage?->voyage_date?->format('d/m/Y'),
                        'heure' => $r->voyage?->voyage_heure_depart,
                    ],
                    'montant' => (float) ($r->res_remb_montant ?? $r->montant_avance),
                    'statut' => $r->res_remb_statut,
                    'date_traitement' => $r->res_remb_date?->format('d/m/Y H:i'),
                    'reference' => $r->res_remb_reference,
                    'note' => $r->res_remb_note,
                    'date_annulation' => $r->updated_at?->format('d/m/Y H:i'),
                ];
            });

            return response()->json([
                'statut' => true,
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'total' => $page->total(),
                        'current_page' => $page->currentPage(),
                        'last_page' => $page->lastPage(),
                        'per_page' => $page->perPage(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Remboursements index error: ' . $e->getMessage());
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /adminCompagnie/remboursements/statistiques
     * Compteurs et totaux à afficher en haut de l'écran.
     */
    public function statistiques(): JsonResponse
    {
        try {
            $compagnieId = $this->getCompagnieUtilisateur();

            $base = Reservation::whereHas('voyage.trajet', fn($q) => $q->where('comp_id', $compagnieId))
                ->where('res_remb_statut', '>', 0);

            $stats = [
                'en_attente' => [
                    'nombre' => (clone $base)->where('res_remb_statut', 1)->count(),
                    'montant' => (float) (clone $base)->where('res_remb_statut', 1)->sum('res_remb_montant'),
                ],
                'traites' => [
                    'nombre' => (clone $base)->where('res_remb_statut', 2)->count(),
                    'montant' => (float) (clone $base)->where('res_remb_statut', 2)->sum('res_remb_montant'),
                ],
                'refuses' => [
                    'nombre' => (clone $base)->where('res_remb_statut', 3)->count(),
                    'montant' => (float) (clone $base)->where('res_remb_statut', 3)->sum('res_remb_montant'),
                ],
            ];

            return response()->json(['statut' => true, 'data' => $stats]);
        } catch (\Exception $e) {
            Log::error('Remboursements stats error: ' . $e->getMessage());
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /adminCompagnie/remboursements/{resId}/marquer-traite
     * Body : { reference: string, note?: string }
     */
    public function marquerTraite(Request $request, int $resId): JsonResponse
    {
        $request->validate([
            'reference' => 'required|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $compagnieId = $this->getCompagnieUtilisateur();

            return DB::transaction(function () use ($request, $resId, $compagnieId) {
                $reservation = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                    ->whereHas('voyage.trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->where('res_id', $resId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($reservation->res_remb_statut !== 1) {
                    return response()->json([
                        'statut' => false,
                        'message' => 'Ce remboursement n\'est pas en attente.',
                    ], 422);
                }

                $reservation->update([
                    'res_remb_statut' => 2,
                    'res_remb_date' => now(),
                    'res_remb_reference' => $request->input('reference'),
                    'res_remb_note' => $request->input('note'),
                ]);

                // Notifier le client
                try {
                    $depart = $reservation->voyage?->trajet?->provinceDepart?->pro_nom ?? 'N/A';
                    $arrivee = $reservation->voyage?->trajet?->provinceArrivee?->pro_nom ?? 'N/A';
                    $voyageInfo = "{$depart} → {$arrivee} le " . ($reservation->voyage?->voyage_date?->format('d/m/Y') ?? '');

                    NotificationService::notifierClientRemboursementTraite(
                        (int) $reservation->util_id,
                        (int) $reservation->res_id,
                        (float) $reservation->res_remb_montant,
                        $voyageInfo,
                        $request->input('reference')
                    );
                } catch (\Exception $notifError) {
                    Log::warning('Notif remboursement traité failed: ' . $notifError->getMessage());
                }

                return response()->json([
                    'statut' => true,
                    'message' => 'Remboursement marqué comme traité.',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('marquerTraite error: ' . $e->getMessage());
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /adminCompagnie/remboursements/{resId}/refuser
     * Body : { motif: string }
     */
    public function refuser(Request $request, int $resId): JsonResponse
    {
        $request->validate([
            'motif' => 'required|string|min:5|max:500',
        ]);

        try {
            $compagnieId = $this->getCompagnieUtilisateur();

            return DB::transaction(function () use ($request, $resId, $compagnieId) {
                $reservation = Reservation::with(['utilisateur', 'voyage.trajet.provinceDepart', 'voyage.trajet.provinceArrivee'])
                    ->whereHas('voyage.trajet', fn($q) => $q->where('comp_id', $compagnieId))
                    ->where('res_id', $resId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($reservation->res_remb_statut !== 1) {
                    return response()->json([
                        'statut' => false,
                        'message' => 'Ce remboursement n\'est pas en attente.',
                    ], 422);
                }

                $reservation->update([
                    'res_remb_statut' => 3,
                    'res_remb_date' => now(),
                    'res_remb_note' => $request->input('motif'),
                ]);

                try {
                    $depart = $reservation->voyage?->trajet?->provinceDepart?->pro_nom ?? 'N/A';
                    $arrivee = $reservation->voyage?->trajet?->provinceArrivee?->pro_nom ?? 'N/A';
                    $voyageInfo = "{$depart} → {$arrivee} le " . ($reservation->voyage?->voyage_date?->format('d/m/Y') ?? '');

                    NotificationService::notifierClientRemboursementRefuse(
                        (int) $reservation->util_id,
                        (int) $reservation->res_id,
                        $voyageInfo,
                        $request->input('motif')
                    );
                } catch (\Exception $notifError) {
                    Log::warning('Notif remboursement refusé failed: ' . $notifError->getMessage());
                }

                return response()->json([
                    'statut' => true,
                    'message' => 'Remboursement refusé.',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('refuser remboursement error: ' . $e->getMessage());
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
