<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Services\Versement\VersementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Versements aux chauffeurs (voitures de particuliers) : liste filtrable,
 * règlement (« Marquer comme réglé ») et compteur des retards.
 */
class VersementController extends Controller
{
    private const MAX_EXPORT = 5000;

    public function __construct(private VersementService $service) {}

    /**
     * GET /api/adminCompagnie/versements
     * Filtres : etat (a_regler | retard | en_cours | regles | tous), voit_id, matricule,
     * chauffeur (nom ou téléphone), traj_id, du, au (date du voyage), page, per_page, tout=1 (export).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'etat' => 'nullable|in:a_regler,retard,en_cours,regles,tous',
                'voit_id' => 'nullable|integer',
                'matricule' => 'nullable|string|max:20',
                'chauffeur' => 'nullable|string|max:100',
                'traj_id' => 'nullable|integer',
                'du' => 'nullable|date_format:Y-m-d',
                'au' => 'nullable|date_format:Y-m-d',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
            $compId = $this->compagnieId();
            $this->service->synchroniserCompagnie($compId);

            $etat = $request->input('etat', 'en_cours');
            $q = DB::table('fandrio_app.versements as ve')->where('ve.comp_id', $compId);
            $aujourdhui = today()->toDateString();

            match ($etat) {
                // Réglables maintenant (figés : partis ou passés), non réglés
                'a_regler' => $q->where('ve.vers_statut', 1)->whereNotNull('ve.vers_fige_le'),
                'retard' => $q->where('ve.vers_statut', 1)->whereNotNull('ve.vers_fige_le')->where('ve.voyage_date', '<', $aujourdhui),
                // Tout ce qui n'est pas réglé (en vente compris)
                'en_cours' => $q->where('ve.vers_statut', 1),
                'regles' => $q->where('ve.vers_statut', 2),
                default => null,
            };
            if ($request->filled('voit_id')) {
                $q->where('ve.voit_id', (int) $request->voit_id);
            }
            if ($request->filled('matricule')) {
                $q->where('ve.voit_matricule', 'ILIKE', '%' . trim($request->matricule) . '%');
            }
            if ($request->filled('chauffeur')) {
                $c = '%' . trim($request->chauffeur) . '%';
                $q->where(fn($w) => $w->where('ve.chauffeur_nom', 'ILIKE', $c)->orWhere('ve.chauffeur_phone', 'ILIKE', $c));
            }
            if ($request->filled('traj_id')) {
                $q->where('ve.traj_id', (int) $request->traj_id);
            }
            if ($request->filled('du')) {
                $q->where('ve.voyage_date', '>=', $request->du);
            }
            if ($request->filled('au')) {
                $q->where('ve.voyage_date', '<=', $request->au);
            }

            $totauxFiltres = (clone $q)->selectRaw('COUNT(*) AS nb, COALESCE(SUM(ve.part_chauffeur), 0) AS chauffeur, COALESCE(SUM(ve.part_compagnie), 0) AS compagnie, COALESCE(SUM(ve.montant_ventes), 0) AS ventes')->first();
            $q->orderByRaw('CASE WHEN ve.vers_statut = 1 AND ve.vers_fige_le IS NOT NULL THEN 0 WHEN ve.vers_statut = 1 THEN 1 ELSE 2 END')
                ->orderBy('ve.voyage_date', $etat === 'regles' ? 'desc' : 'asc')
                ->orderBy('ve.voyage_heure');

            if ($request->boolean('tout')) {
                $lignes = $q->limit(self::MAX_EXPORT)->get();
                $pagination = null;
            } else {
                $page = $q->paginate((int) $request->input('per_page', 30));
                $lignes = collect($page->items());
                $pagination = ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()];
            }

            return response()->json([
                'statut' => true,
                'data' => [
                    'resume' => $this->resume($compId),
                    'totaux_filtre' => [
                        'nb' => (int) $totauxFiltres->nb,
                        'part_chauffeur' => (float) $totauxFiltres->chauffeur,
                        'part_compagnie' => (float) $totauxFiltres->compagnie,
                        'ventes' => (float) $totauxFiltres->ventes,
                    ],
                    'versements' => $lignes->map(fn($v) => $this->formater($v))->values(),
                    'pagination' => $pagination,
                    'filtres' => $this->optionsFiltres($compId),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** POST /api/adminCompagnie/versements/{id}/regler : « Marquer comme réglé ». */
    public function regler(int $id): JsonResponse
    {
        try {
            $compId = $this->compagnieId();
            $ligne = DB::table('fandrio_app.versements')->where('vers_id', $id)->where('comp_id', $compId)->first();
            if (!$ligne) {
                return response()->json(['statut' => false, 'message' => 'Versement introuvable.'], 404);
            }
            $ligne = $this->service->synchroniser((int) $ligne->voyage_id) ?? $ligne;
            if ((int) $ligne->vers_statut === 2) {
                return response()->json(['statut' => false, 'message' => 'Ce versement est déjà réglé.'], 422);
            }
            if (!$this->service->reglable($ligne)) {
                return response()->json(['statut' => false, 'message' => 'Ce versement pourra être réglé une fois la voiture partie avec au moins 50 % de remplissage, ou une fois la date du voyage passée.'], 422);
            }

            $moi = Auth::user();
            $maj = DB::table('fandrio_app.versements')->where('vers_id', $ligne->vers_id)->where('vers_statut', 1)->update([
                'vers_statut' => 2,
                'vers_regle_le' => now(),
                'vers_regle_par' => $moi->util_id,
                'vers_regle_par_nom' => trim("{$moi->util_prenom} {$moi->util_nom}"),
            ]);
            if (!$maj) {
                return response()->json(['statut' => false, 'message' => 'Ce versement vient d’être réglé par quelqu’un d’autre.'], 409);
            }

            $v = DB::table('fandrio_app.versements')->where('vers_id', $ligne->vers_id)->first();
            return response()->json([
                'statut' => true,
                'message' => 'Versement réglé : ' . number_format((float) $v->part_chauffeur, 0, ',', ' ') . " Ar versés à " . ($v->chauffeur_nom ?: 'le chauffeur') . '.',
                'data' => ['versement' => $this->formater($v)],
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** GET /api/adminCompagnie/versements/compteur : pastille du menu (versements en retard). */
    public function compteur(): JsonResponse
    {
        return response()->json(['statut' => true, 'data' => ['retard' => $this->resume($this->compagnieId())['nb_retard']]]);
    }

    private function compagnieId(): int
    {
        return (int) Auth::user()->comp_id;
    }

    private function resume(int $compId): array
    {
        $aujourdhui = today()->toDateString();
        $r = DB::table('fandrio_app.versements')->where('comp_id', $compId)->selectRaw("
            COUNT(*) FILTER (WHERE vers_statut = 1 AND vers_fige_le IS NOT NULL) AS nb_a_regler,
            COALESCE(SUM(part_chauffeur) FILTER (WHERE vers_statut = 1 AND vers_fige_le IS NOT NULL), 0) AS a_regler,
            COUNT(*) FILTER (WHERE vers_statut = 1 AND vers_fige_le IS NOT NULL AND voyage_date < ?) AS nb_retard,
            COALESCE(SUM(part_chauffeur) FILTER (WHERE vers_statut = 1 AND vers_fige_le IS NOT NULL AND voyage_date < ?), 0) AS retard,
            COUNT(*) FILTER (WHERE vers_statut = 1 AND vers_fige_le IS NULL) AS nb_en_vente,
            COUNT(*) FILTER (WHERE vers_statut = 2 AND vers_regle_le >= ?) AS nb_regles_mois,
            COALESCE(SUM(part_chauffeur) FILTER (WHERE vers_statut = 2 AND vers_regle_le >= ?), 0) AS regles_mois,
            COALESCE(SUM(part_compagnie) FILTER (WHERE vers_statut = 2 AND vers_regle_le >= ?), 0) AS compagnie_mois
        ", [$aujourdhui, $aujourdhui, now()->startOfMonth(), now()->startOfMonth(), now()->startOfMonth()])->first();

        return [
            'nb_a_regler' => (int) $r->nb_a_regler,
            'a_regler' => (float) $r->a_regler,
            'nb_retard' => (int) $r->nb_retard,
            'retard' => (float) $r->retard,
            'nb_en_vente' => (int) $r->nb_en_vente,
            'nb_regles_mois' => (int) $r->nb_regles_mois,
            'regles_mois' => (float) $r->regles_mois,
            'part_compagnie_mois' => (float) $r->compagnie_mois,
        ];
    }

    /** Listes des filtres : voitures (matricules), chauffeurs et trajets présents dans les versements. */
    private function optionsFiltres(int $compId): array
    {
        $base = fn() => DB::table('fandrio_app.versements')->where('comp_id', $compId);
        return [
            'voitures' => $base()->whereNotNull('voit_id')->select('voit_id', 'voit_matricule')->distinct()->orderBy('voit_matricule')->get()
                ->map(fn($v) => ['id' => (int) $v->voit_id, 'matricule' => $v->voit_matricule])->unique('id')->values(),
            'chauffeurs' => $base()->whereNotNull('chauffeur_nom')->select('chauffeur_nom')->distinct()->orderBy('chauffeur_nom')->pluck('chauffeur_nom'),
            'trajets' => $base()->whereNotNull('traj_id')->select('traj_id', 'trajet_libelle')->distinct()->orderBy('trajet_libelle')->get()
                ->map(fn($t) => ['id' => (int) $t->traj_id, 'libelle' => $t->trajet_libelle])->unique('id')->values(),
        ];
    }

    private function formater(object $v): array
    {
        $etat = $this->service->etat($v);
        return [
            'id' => (int) $v->vers_id,
            'voyage_id' => (int) $v->voyage_id,
            'etat' => $etat,
            'reglable' => $this->service->reglable($v),
            'voiture' => ['id' => $v->voit_id ? (int) $v->voit_id : null, 'matricule' => $v->voit_matricule],
            'chauffeur' => ['id' => $v->chauff_id ? (int) $v->chauff_id : null, 'nom' => $v->chauffeur_nom, 'telephone' => $v->chauffeur_phone],
            'trajet' => ['id' => $v->traj_id ? (int) $v->traj_id : null, 'libelle' => $v->trajet_libelle],
            'date' => Carbon::parse($v->voyage_date)->toDateString(),
            'heure' => substr((string) $v->voyage_heure, 0, 5),
            'taux_compagnie' => (float) $v->taux_compagnie,
            'nb_places' => (int) $v->nb_places,
            'nb_sieges' => (int) $v->nb_sieges,
            'remplissage' => (int) round($this->service->remplissage($v) * 100),
            'montant_ventes' => (float) $v->montant_ventes,
            'commission_fandrio' => (float) $v->commission_fandrio,
            'part_compagnie' => (float) $v->part_compagnie,
            'part_chauffeur' => (float) $v->part_chauffeur,
            'fige_le' => $v->vers_fige_le ? Carbon::parse($v->vers_fige_le)->format('Y-m-d H:i') : null,
            'regle_le' => $v->vers_regle_le ? Carbon::parse($v->vers_regle_le)->format('Y-m-d H:i') : null,
            'regle_par' => $v->vers_regle_par_nom,
            'rappel_le' => $v->vers_rappel_le ? Carbon::parse($v->vers_rappel_le)->format('Y-m-d H:i') : null,
        ];
    }
}
