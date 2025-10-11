<?php

namespace App\DTOs;

class ChauffeurDTO
{
    public function __construct(
        public string $chauff_nom,
        public string $chauff_prenom,
        public int $chauff_age,
        public string $chauff_cin,
        public string $chauff_permis,
        public string $chauff_phone,
        public int $chauff_statut,
        public ?string $chauff_photo = null,
        public int $comp_id
    ) {}


    # Creation du DTO a partir d' une requete
    public static function creationObjet(array $donnees): self
    {
        return new self(
            chauff_nom: $donnees['chauff_nom'],
            chauff_prenom: $donnees['chauff_prenom'],
            chauff_age: (int) $donnees['chauff_age'],
            chauff_cin: $donnees['chauff_cin'],
            chauff_permis: $donnees['chauff_permis'],
            chauff_phone: $donnees['chauff_phone'],
            chauff_statut: (int) $donnees['chauff_statut'],
            chauff_photo: $donnees['chauff_photo'] ?? null,
            comp_id: (int) $donnees['comp_id']
        );
    }


    # Creation du DTO a partir d' une requete de modification
    public static function creationObjetAmodifier(array $donnees, string $actuelCIN): self
    {
        return new self(
            chauff_nom: $donnees['chauff_nom'] ?? '',
            chauff_prenom: $donnees['chauff_prenom'] ?? '',
            chauff_age: isset($donnees['chauff_age']) ? (int) $donnees['chauff_age'] : 0,
            chauff_cin: $donnees['chauff_cin'] ?? $actuelCIN,
            chauff_permis: $donnees['chauff_permis'] ?? '',
            chauff_phone: $donnees['chauff_phone'] ?? '',
            chauff_statut: isset($donnees['chauff_statut']) ? (int) $donnees['chauff_statut'] : 0,
            chauff_photo: $donnees['chauff_photo'] ?? null,
            comp_id: isset($donnees['comp_id']) ? (int) $donnees['comp_id'] : 0
        );
    }


    # Regle de validation des donnees
    public static function validationDonnees(): array
    {
        return [
            'chauff_nom'    => 'required|string|max:100',
            'chauff_prenom' => 'required|string|max:100',
            'chauff_age'    => 'required|integer|min:18',
            'chauff_cin'    => 'required|string|unique:chauffeurs,chauff_cin',
            'chauff_permis' => 'required|string|in:A,B,C,D',
            'chauff_phone'  => 'required|string|max:20',
            'chauff_statut' => 'required|integer',
            'chauff_photo'  => 'nullable|image|max:2048',
            'comp_id'       => 'required|integer|exists:compagnies,comp_id'
        ];
    }


    # Regle de validation des donnees a modifier
    public static function validationDonneesAmodifier(int $idChauffeur): array
    {
        return [
            'chauff_nom'    => 'sometimes|required|string|max:100',
            'chauff_prenom' => 'sometimes|required|string|max:100',
            'chauff_age'    => 'sometimes|required|integer|min:18',
            'chauff_cin'    => 'sometimes|required|string|unique:chauffeurs,chauff_cin' . $idChauffeur . ',chauff_id',
            'chauff_permis' => 'sometimes|required|string|in:A,B,C,D',
            'chauff_phone'  => 'sometimes|required|string|max:20',
            'chauff_statut' => 'sometimes|required|integer',
            'chauff_photo'  => 'sometimes|nullable|image|max:2048',
            'comp_id'       => 'sometimes|required|integer|exists:compagnies,comp_id'
        ];
    }


    # Convertion du DTO en tableau pour l' ajout|modification d' un chauffeur
    public function convertionDonneesEnTableau(): array
    {
        return array_filter([
            'chauff_nom'    => $this->chauff_nom,
            'chauff_prenom' => $this->chauff_prenom,
            'chauff_age'    => $this->chauff_age,
            'chauff_cin'    => $this->chauff_cin,
            'chauff_permis' => $this->chauff_permis,
            'chauff_phone'  => $this->chauff_phone,
            'chauff_statut' => $this->chauff_statut,
            'chauff_photo'  => $this->chauff_photo,
            'comp_id'       => $this->comp_id
        ], function ($valeur) {
            return $valeur !== null && $valeur !== '';
        });
    }
}
