<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Client\AccueilService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccueilController extends Controller
{
    private AccueilService $accueilService;

    public function __construct(AccueilService $accueilService)
    {
        $this->accueilService = $accueilService;
    }

    /**
     * Données de la page d'accueil client.
     * GET /api/accueil?lat=X&lng=Y (lat/lng optionnels)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $latitude  = null;
            $longitude = null;

            if ($request->has('lat') && $request->has('lng')) {
                $validator = Validator::make($request->only(['lat', 'lng']), [
                    'lat' => 'numeric|between:-90,90',
                    'lng' => 'numeric|between:-180,180',
                ]);

                if ($validator->passes()) {
                    $latitude  = (float) $request->input('lat');
                    $longitude = (float) $request->input('lng');
                }
            }

            $data = $this->accueilService->getDonneesAccueil($latitude, $longitude);

            return response()->json([
                'statut' => true,
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'statut'  => false,
                'message' => 'Erreur lors de la récupération des données d\'accueil',
            ], 500);
        }
    }
}
