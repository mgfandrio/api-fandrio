<?php

namespace App\Services\Voiture;

use App\DTOs\VoitureDTO;
use App\Models\Compagnies\Compagnie;
use App\Models\Voitures\Voitures;

class VoitureService
{
    public function __construct(private PlanSiegeService $planSiegeService) {}

    public function ajouterVoiture(VoitureDTO $voitureDto): Voitures
    {
        $this->assertCategorieAutorisee($voitureDto->comp_id, $voitureDto->voit_categorie);

        $donneesVoiture = $voitureDto->convertionDonneesEnTableau();

        $voiture = Voitures::create($donneesVoiture);

        // Générer automatiquement le plan de sièges
        if ($voiture->voit_places > 0) {
            $this->planSiegeService->genererPlanAutomatique(
                $voiture->voit_id,
                $voiture->voit_places,
                $voiture->voit_categorie ?? 'classique'
            );
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
        $ancienneCategorie = $voiture->voit_categorie ?? 'classique';

        $this->assertCategorieAutorisee(
            $voitureDto->comp_id ?: $voiture->comp_id,
            $voitureDto->voit_categorie
        );

        $donneesUpdate = $voitureDto->convertionDonneesEnTableau();
        $voiture->update($donneesUpdate);

        // Si le nombre de places ou la catégorie a changé, on regénère le plan automatiquement
        $placesChangees = isset($donneesUpdate['voit_places']) && $donneesUpdate['voit_places'] != $anciennesPlaces;
        $categorieChangee = isset($donneesUpdate['voit_categorie']) && $donneesUpdate['voit_categorie'] !== $ancienneCategorie;

        if ($placesChangees || $categorieChangee) {
            $this->planSiegeService->genererPlanAutomatique(
                $voiture->voit_id,
                $voiture->voit_places,
                $voiture->voit_categorie ?? 'classique'
            );
        }

        return $voiture;
    }

    /**
     * Vérifie que la compagnie possède le mode correspondant activé.
     */
    private function assertCategorieAutorisee(int $compagnieId, string $categorie): void
    {
        if ($categorie === 'classique') {
            return;
        }

        $compagnie = Compagnie::find($compagnieId);
        if (!$compagnie) {
            throw new \Exception('Compagnie introuvable pour la validation de catégorie.');
        }

        if ($categorie === 'vip' && !$compagnie->comp_mode_vip) {
            throw new \Exception('Le mode VIP n\'est pas activé pour votre compagnie.');
        }
        if ($categorie === 'premium' && !$compagnie->comp_mode_premium) {
            throw new \Exception('Le mode Premium n\'est pas activé pour votre compagnie.');
        }
    }
}
