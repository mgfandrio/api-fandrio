<?php

namespace App\Services\Voiture;

use App\DTOs\VoitureDTO;
use App\Models\Voitures\Voitures;

class VoitureService
{
    public function __construct(private PlanSiegeService $planSiegeService) {}

    public function ajouterVoiture(VoitureDTO $voitureDto): Voitures
    {
        $donneesVoiture = $voitureDto->convertionDonneesEnTableau();

        $voiture = Voitures::create($donneesVoiture);

        // Générer automatiquement le plan de sièges
        if ($voiture->voit_places > 0) {
            $this->planSiegeService->genererPlanAutomatique($voiture->voit_id, $voiture->voit_places);
        }

        return $voiture;
    }

    public function trouverUneVoiture(int $idVoiture): ?Voitures
    {
        return Voitures::find($idVoiture);
    }

    public function modifierVoiture(int $idVoiture, VoitureDTO $voitureDto): Voitures
    {
        $voiture = Voitures::findOrFail($idVoiture);
        $anciennesPlaces = $voiture->voit_places;
        
        $donneesUpdate = $voitureDto->convertionDonneesEnTableau();
        $voiture->update($donneesUpdate);

        // Si le nombre de places a changé, on regénère le plan automatiquement
        if (isset($donneesUpdate['voit_places']) && $donneesUpdate['voit_places'] != $anciennesPlaces) {
            $this->planSiegeService->genererPlanAutomatique($voiture->voit_id, $voiture->voit_places);
        }

        return $voiture;
    }
}
