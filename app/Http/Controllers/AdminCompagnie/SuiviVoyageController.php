<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Services\Compagnies\PartenaireService;
use App\Services\Voyage\SuiviVoyageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compagnies partenaires et suivi des voitures en route (admin compagnie).
 */
class SuiviVoyageController extends Controller
{
    public function __construct(
        private SuiviVoyageService $suivi,
        private PartenaireService $partenaires,
    ) {}

    private function compId(Request $request): int
    {
        return (int) $request->user()->comp_id;
    }

    private function reponse(callable $action, ?string $message = null): JsonResponse
    {
        try {
            $data = $action();
            return response()->json(array_filter(['statut' => true, 'message' => $message, 'data' => $data], fn($v) => $v !== null));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['statut' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'Données invalides', 'erreurs' => $e->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['statut' => false, 'message' => 'Voyage introuvable.'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 400);
        }
    }

    // ---------------------------------------------------------------- partenaires

    /** GET /adminCompagnie/partenaires */
    public function partenaires(Request $request): JsonResponse
    {
        return $this->reponse(fn() => $this->partenaires->lister($this->compId($request)));
    }

    /** GET /adminCompagnie/partenaires/disponibles?recherche= */
    public function disponibles(Request $request): JsonResponse
    {
        return $this->reponse(fn() => $this->partenaires->disponibles($this->compId($request), $request->query('recherche')));
    }

    /** GET /adminCompagnie/partenaires/destination/{provinceId} : compagnies de réception possibles */
    public function pourDestination(Request $request, int $provinceId): JsonResponse
    {
        return $this->reponse(fn() => $this->partenaires->pourProvince($this->compId($request), $provinceId));
    }

    /** POST /adminCompagnie/partenaires {comp_id} */
    public function ajouter(Request $request): JsonResponse
    {
        return $this->reponse(function () use ($request) {
            $request->validate(['comp_id' => 'required|integer']);
            return $this->partenaires->ajouter($this->compId($request), (int) $request->input('comp_id'), $this->compId($request));
        }, 'Compagnie partenaire ajoutée.');
    }

    /** DELETE /adminCompagnie/partenaires/{compId} */
    public function retirer(Request $request, int $compId): JsonResponse
    {
        return $this->reponse(function () use ($request, $compId) {
            $this->partenaires->retirer($this->compId($request), $compId);
            return null;
        }, 'Partenariat retiré.');
    }

    // ---------------------------------------------------------------- suivi

    /** GET /adminCompagnie/suivi?onglet=departs|arrivees */
    public function index(Request $request): JsonResponse
    {
        return $this->reponse(function () use ($request) {
            $compId = $this->compId($request);
            return [
                'voyages' => $request->query('onglet') === 'arrivees'
                    ? $this->suivi->arrivees($compId)
                    : $this->suivi->departs($compId),
                'compteurs' => $this->suivi->compteurs($compId),
            ];
        });
    }

    /** GET /adminCompagnie/suivi/compteurs : pastille du menu */
    public function compteurs(Request $request): JsonResponse
    {
        return $this->reponse(fn() => $this->suivi->compteurs($this->compId($request)));
    }

    /** GET /adminCompagnie/suivi/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->reponse(fn() => $this->suivi->detail($id, $this->compId($request)));
    }

    /** POST /adminCompagnie/suivi/{id}/cloturer : fin de l'embarquement, la voiture part */
    public function cloturer(Request $request, int $id): JsonResponse
    {
        return $this->reponse(fn() => $this->suivi->cloturer($id, $this->compId($request)), 'Embarquement clôturé : la voiture est en route.');
    }

    /** POST /adminCompagnie/suivi/{id}/arrive */
    public function arrive(Request $request, int $id): JsonResponse
    {
        return $this->reponse(fn() => $this->suivi->marquerArrive($id, $this->compId($request)), 'Arrivée confirmée.');
    }
}
