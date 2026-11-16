<?php

namespace App\Services\Chauffeur;

use App\DTOs\ChauffeurDTO;
use App\Models\Chauffeurs\Chauffeurs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile as HttpUploadedFile;

/**
 * Chauffeurs de la compagnie de l'admin connecté. Toutes les recherches sont
 * limitées à cette compagnie : un chauffeur d'une autre compagnie est
 * introuvable, même avec son identifiant.
 */
class ChauffeurService
{
    /** Champs qu'une modification peut changer (jamais la compagnie). */
    private const CHAMPS_MODIFIABLES = ['chauff_nom', 'chauff_prenom', 'chauff_age', 'chauff_cin', 'chauff_permis', 'chauff_phone', 'chauff_statut'];

    private function compagnieId(): int
    {
        $compId = (int) (Auth::user()?->comp_id);
        if (!$compId) {
            throw new \Exception('Utilisateur non associé à une compagnie');
        }
        return $compId;
    }

    public function ajouterChauffeur(ChauffeurDTO $chauffeurDto, ?HttpUploadedFile $photoChauffeur = null): Chauffeurs
    {
        $donneesChauffeur = $chauffeurDto->convertionDonneesEnTableau();
        // La compagnie est celle de l'admin connecté, quelle que soit la requête
        $donneesChauffeur['comp_id'] = $this->compagnieId();

        // Photo enregistrée dans storage/app/public/chauffeurs
        if ($photoChauffeur) {
            $donneesChauffeur['chauff_photo'] = $photoChauffeur->store('chauffeurs', 'public');
        }

        return Chauffeurs::create($donneesChauffeur);
    }


    public function trouverUnChauffeur(int $idChauffeur): ?Chauffeurs
    {
        return Chauffeurs::where('comp_id', $this->compagnieId())->find($idChauffeur);
    }


    /**
     * Modifie les champs envoyés (validés), et eux seuls.
     */
    public function modifierChauffeur(int $idChauffeur, array $donneesValidees, ?HttpUploadedFile $photoChauffeurAjour = null): ?Chauffeurs
    {
        $chauffeur = $this->trouverUnChauffeur($idChauffeur);
        if (!$chauffeur) {
            return null;
        }

        $donneesChauffeur = array_intersect_key($donneesValidees, array_flip(self::CHAMPS_MODIFIABLES));

        // Gestion de la photo
        if ($photoChauffeurAjour) {
            if ($chauffeur->chauff_photo) {     // Suppression de l'ancienne photo si elle existe
                Storage::disk('public')->delete($chauffeur->chauff_photo);
            }
            $donneesChauffeur['chauff_photo'] = $photoChauffeurAjour->store('chauffeurs', 'public');
        }

        $chauffeur->update($donneesChauffeur);
        return $chauffeur->fresh();
    }

    public function supprimerChauffeur(int $idChauffeur): ?Chauffeurs
    {
        $chauffeur = $this->trouverUnChauffeur($idChauffeur);

        if (!$chauffeur) {
            return null;
        }

        // Suppression de la photo du chauffeur si elle existe
        if ($chauffeur->chauff_photo) {
            Storage::disk('public')->delete($chauffeur->chauff_photo);
        }

        $chauffeur->chauff_statut = 3; // Désactivation du chauffeur au lieu de la suppression
        $chauffeur->update();

        return $chauffeur;
    }
}
