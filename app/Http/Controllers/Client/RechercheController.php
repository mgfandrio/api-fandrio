<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Client\RechercheService;
use App\DTOs\RechercheVoyageDTO;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RechercheController extends Controller 
{
    public function __construct(private RechercheService $rechercheService) {}

    /**
     * Recherche de voyages avec filtres
     */
    public function rechercher(Request $request): JsonResponse
    {
        try {
            // Validation des critères
            $request->validate([
                'pro_depart' => 'nullable|integer|exists:provinces,pro_id',
                'pro_arrivee' => 'nullable|integer|exists:provinces,pro_id',
                'compagnie_id' => 'nullable|integer|exists:compagnies,comp_id',
                'date_exacte' => 'nullable|date|after_or_equal:today',
                'date_debut' => 'nullable|date|after_or_equal:today',
                'date_fin' => 'nullable|date|after_or_equal:date_debut',
                'type_voyage' => 'nullable|integer|in:1,2',
                'places_min' => 'nullable|integer|min:1|max:50',
                'prix_max' => 'nullable|numeric|min:0',
                'heure_depart_min' => 'nullable|date_format:H:i',
                'heure_depart_max' => 'nullable|date_format:H:i|after:heure_depart_min',
                'per_page' => 'nullable|integer|min:1|max:100'
            ]);

            $rechercheDTO = RechercheVoyageDTO::fromRequest($request->all());
            $rechercheDTO->validateDates();

            // Vérifier qu'au moins un critère est fourni
            if(!$rechercheDTO->hasCriteria()) {
                throw new \Exception('Veuillez fournir au moins un critère de recherche');
            }

            $resultat = $this->rechercheService->rechercherVoyages($request->all());

            return response()->json([
                'status' => 'true',
                'message' => 'Recherche effectuée avec succès',
                'data' => $resultat
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'erreurs' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Récupère les suggestions de recherche
     */
    public function suggestions(): JsonResponse
    {
        try {
            $suggestions = $this->rechercheService->getSuggestions();

            return response()->json([
                'statut' => true,
                'data' => $suggestions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Erreur lors de la récupération des suggestions'
            ], 500);
        }
    }

    /**
     * Récupère les détails d'un voyage spécifique
     */
    public function details(int $id): JsonResponse
    {
        try {
            $voyage = $this->rechercheService->getVoyageDetail($id);

            return response()->json([
                'statut' => true,
                'data' => $voyage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }


    /***
     * Recherche rapide par destination
     */
    public function rechercheRapide(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'pro_arrivee' => 'required|integer|exists:provinces,pro_id',
                'date' => 'nullable|date|after_or_equal:today',
            ]);

            $resultat = $this->rechercheService->rechercheRapide(
                $request->pro_arrivee,
                $request->date
            );

            return response()->json([
                'statut' => true,
                'data' => $resultat
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'statut' => false,
                'message' => 'Données invalides',
                'erreurs' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'statut' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}