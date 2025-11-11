<?php

namespace App\Services\Voiture;

use App\DTOs\VoitureDTO;
use App\Models\Voitures\Voitures;

class VoitureService
{
    public function ajouterVoiture(VoitureDTO $voitureDto): Voitures
    {
        $donneesChauffeur = $voitureDto->convertionDonneesEnTableau();

        return Voitures::create($donneesChauffeur);
    }
}
