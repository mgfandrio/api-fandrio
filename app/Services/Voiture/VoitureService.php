<?php

namespace App\Services\Voiture;

use App\DTOs\VoitureDTO;
use App\Models\Compagnies\Compagnie;
use App\Models\Voitures\Voitures;
use App\Models\Chauffeurs\Chauffeurs;
use Illuminate\Support\Facades\Auth;

class VoitureService
{
    public function __construct(private PlanSiegeService $planSiegeService) {}

    /** Champs qu'une modification peut changer (jamais la compagnie). */
    private const CHAMPS_MODIFIABLES = ['voit_matricule', 'voit_marque', 'voit_modele', 'voit_places', 'voit_statut', 'chauff_id', 'voit_categorie'];

    private function compagnieId(): int
    {
        $compId = (int) (Auth::user()?->comp_id);
        if (!$compId) {
            throw new \Exception('Utilisateur non associé à une compagnie');
        }
        return $compId;
    }

    /** Le chauffeur affecté doit appartenir à la même compagnie que le véhicule. */
    private function assertChauffeurDeLaCompagnie(?int $chauffId, int $compId): void
    {
        if ($chauffId && !Chauffeurs::where('comp_id', $compId)->whereKey($chauffId)->exists()) {
            throw new \Exception("Ce chauffeur n'appartient pas à votre compagnie.");
        }
    }

    public function ajouterVoiture(VoitureDTO $voitureDto): Voitures
    {
        // La compagnie est celle de l'admin connecté, quelle que soit la requête
        $compId = $this->compagnieId();
        $this->assertCategorieAutorisee($compId, $voitureDto->voit_categorie);
        $this->assertChauffeurDeLaCompagnie($voitureDto->chauff_id, $compId);

        $donneesVoiture = $voitureDto->convertionDonneesEnTableau();
        $donneesVoiture['comp_id'] = $compId;

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
        return Voitures::where('comp_id', $this->compagnieId())->find($idVoiture);
    }

    /**
     * Modifie les champs envoyés (validés), et eux seuls.
     */
    public function modifierVoiture(int $idVoiture, array $donneesValidees): Voitures
    {
        $voiture = Voitures::where('comp_id', $this->compagnieId())->findOrFail($idVoiture);
        $anciennesPlaces = $voiture->voit_places;
        $ancienneCategorie = $voiture->voit_categorie ?? 'classique';

        $donneesUpdate = array_intersect_key($donneesValidees, array_flip(self::CHAMPS_MODIFIABLES));

        $this->assertCategorieAutorisee((int) $voiture->comp_id, $donneesUpdate['voit_categorie'] ?? $ancienneCategorie);
        if (array_key_exists('chauff_id', $donneesUpdate)) {
            $this->assertChauffeurDeLaCompagnie((int) $donneesUpdate['chauff_id'], (int) $voiture->comp_id);
        }

        $voiture->update($donneesUpdate);

        // Si le nombre de places ou la catégorie a changé, on regénère le plan automatiquement
        $placesChangees = isset($donneesUpdate['voit_places']) && $donneesUpdate['voit_places'] != $anciennesPlaces;
        $categorieChangee = isset($donneesUpdate['voit_categorie']) && $donneesUpdate['voit_categorie'] !== $ancienneCategorie;

        // Un plan dessiné par la compagnie est gardé si seule la catégorie change
        $planDessine = (bool) $voiture->planSiege?->getGrille();
        if ($placesChangees || ($categorieChangee && !$planDessine)) {
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
