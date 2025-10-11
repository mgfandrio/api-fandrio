<?php

namespace App\Services\Chauffeur;

use App\DTOs\ChauffeurDTO;
use App\Models\Chauffeurs\Chauffeurs;
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
}
