<?php

namespace App\Http\Controllers\Voiture;

use App\Http\Controllers\Controller;
use App\Services\Voiture\VoitureService;

class ConsulterDetailsVoitureController extends Controller
{
    public function detailVoiture(int $idVoiture)
    {
        $voitureService = new VoitureService();
        $voiture = $voitureService->trouverUneVoiture($idVoiture);

        if (!$voiture) {
            return response()->json(
                [
                    'status' => false,
                    'message' => 'Voiture non trouvée'
                ],
                404
            );
        }

        return response()->json(
            [
                'status' => true,
                'data' => $voiture
            ],
            200
        );
    }
}
