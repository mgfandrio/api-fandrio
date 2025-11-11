<?php

namespace App\DTOs;

class VoitureDTO
{
    public function __construct(
        public string $voit_matricule,
        public string $voit_marque,
        public string $voit_modele,
        public int $voit_places,
        public int $voit_statut,
        public int $comp_id,
        public int $chauff_id
    ) {}


    # Creation du DTO a partir d' une requete
    public static function creationObjet(array $donnees): self
    {
        return new self(
            voit_matricule: $donnees['voit_matricule'],
            voit_marque: $donnees['voit_marque'],
            voit_modele: $donnees['voit_modele'],
            voit_places: (int) $donnees['voit_places'],
            voit_statut: (int) $donnees['voit_statut'],
            comp_id: (int) $donnees['comp_id'],
            chauff_id: (int) $donnees['chauff_id'],
        );
    }


    # Creation du DTO a partir d' une requete de modification
    public static function creationObjetAmodifier(array $donnees): self
    {
        return new self(
            voit_matricule: $donnees['voit_matricule'] ?? '',
            voit_marque: $donnees['voit_marque'] ?? '',
            voit_modele: $donnees['voit_modele'] ?? '',
            voit_places: isset($donnees['voit_places']) ? (int) $donnees['voit_places'] : 0,
            voit_statut: isset($donnees['voit_statut']) ? (int) $donnees['voit_statut'] : 0,
            comp_id: isset($donnees['comp_id']) ? (int) $donnees['comp_id'] : 0,
            chauff_id: isset($donnees['chauff_id']) ? (int) $donnees['chauff_id'] : 0
        );
    }


    # Regle de validation des donnees
    public static function validationDonnees(): array
    {
        return [
            'voit_matricule'    => 'required|string|max:100',
            'voit_marque' => 'required|string|max:100',
            'voit_modele' => 'required|string|max:100',
            'voit_places'    => 'required|integer',
            'voit_statut' => 'required|integer',
            'comp_id'       => 'required|integer|exists:compagnies,comp_id',
            'chauff_id'       => 'required|integer|exists:chauffeurs,chauff_id'
        ];
    }


    # Regle de validation des donnees a modifier
    public static function validationDonneesAmodifier(int $idVoiture): array
    {
        return [
            'voit_matricule'    => 'sometimes|required|string|max:100',
            'voit_marque' => 'sometimes|required|string|max:100',
            'voit_modele' => 'sometimes|required|string|max:100',
            'voit_places'    => 'sometimes|required|integer',
            'voit_statut' => 'sometimes|required|integer',
            'comp_id'       => 'sometimes|required|integer|exists:compagnies,comp_id',
            'chauff_id'       => 'sometimes|required|integer|exists:chauffeurs,chauff_id'
        ];
    }


    # Convertion du DTO en tableau pour l' ajout|modification d' un chauffeur
    public function convertionDonneesEnTableau(): array
    {
        return array_filter([
            'voit_matricule'    => $this->voit_matricule,
            'voit_marque' => $this->voit_marque,
            'voit_modele' => $this->voit_modele,
            'voit_places'    => $this->voit_places,
            'voit_statut' => $this->voit_statut,
            'comp_id'       => $this->comp_id,
            'chauff_id'       => $this->chauff_id
        ], function ($valeur) {
            return $valeur !== null && $valeur !== '';
        });
    }
}
