<?php

namespace App\Services\Chauffeur;

use App\DTOs\ChauffeurDTO;
use App\Models\Chauffeurs\Chauffeurs;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile as HttpUploadedFile;

class ChauffeurService
{
    public function ajouterChauffeur(ChauffeurDTO $chauffeurDto, ?HttpUploadedFile $photoChauffeur = null): Chauffeurs
    {
        $donneesChauffeur = $chauffeurDto->convertionDonneesEnTableau();

        // Stockage de la photo du chauffeyr dans public/chauffeurs
        // ainsi que ce chemin dans la base de donnees
        if ($photoChauffeur) {
            $donneesChauffeur['chauff_photo'] = $photoChauffeur->store('chauffeurs', 'public');
        }

        return Chauffeurs::create($donneesChauffeur);
    }


    public function trouverUnChauffeur(int $idChauffeur): ?Chauffeurs
    {
        return Chauffeurs::find($idChauffeur);
    }


    public function modifierChauffeur(int $idChauffeur, ChauffeurDTO $chauffeurDto, ?HttpUploadedFile $photoChauffeurAjour = null): ?Chauffeurs
    {
        $chauffeur = $this->trouverUnChauffeur($idChauffeur);

        if (!$idChauffeur) return null;

        $donneesChauffeur = $chauffeurDto->convertionDonneesEnTableau();

        // Gestion de la photo
        if ($photoChauffeurAjour) {

            if ($chauffeur->chauff_photo) {     //Suppression de l' ancienne photo si elle existe
                Storage::disk('public')->delete($chauffeur->chauff_photo);
            }

            $donneesChauffeur['chauff_photo'] = $photoChauffeurAjour->store('public', 'chauffeurs');
        }

        $chauffeur->update();
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

        $chauffeur->chauff_statut = 2; // Désactivation du chauffeur au lieu de la suppression
        $chauffeur->update();

        return $chauffeur;
    }
}
