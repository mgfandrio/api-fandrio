<?php

namespace App\DTOs;

class VoyageurDTO
{
    public function __construct(
        public string $voya_nom,
        public string $voya_prenom,
        public ?int $voya_age = null,
        public ?string $voya_cin = null,
        public ?string $voya_phone = null,
        public ?string $voya_phone2 = null,
        public int $util_id
    ) {}


    /**
     * Création du DTO à partir d'une requête d'ajout
     */
    public static function creationObjet(array $donnees): self
    {
        return new self(
            voya_nom: $donnees['voya_nom'],
            voya_prenom: $donnees['voya_prenom'],
            voya_age: isset($donnees['voya_age']) ? (int) $donnees['voya_age'] : null,
            voya_cin: $donnees['voya_cin'] ?? null,
            voya_phone: $donnees['voya_phone'] ?? null,
            voya_phone2: $donnees['voya_phone2'] ?? null,
            util_id: (int) $donnees['util_id']
        );
    }


    /**
     * Création du DTO à partir d'une requête de modification
     */
    public static function creationObjetAmodifier(array $donnees, int $utilId): self
    {
        return new self(
            voya_nom: $donnees['voya_nom'] ?? '',
            voya_prenom: $donnees['voya_prenom'] ?? '',
            voya_age: isset($donnees['voya_age']) ? (int) $donnees['voya_age'] : null,
            voya_cin: $donnees['voya_cin'] ?? null,
            voya_phone: $donnees['voya_phone'] ?? null,
            voya_phone2: $donnees['voya_phone2'] ?? null,
            util_id: $utilId
        );
    }


    /**
     * Règles de validation pour l'ajout d'un voyageur
     */
    public static function validationDonnees(): array
    {
        return [
            'voya_nom'    => 'required|string|max:100',
            'voya_prenom' => 'required|string|max:100',
            'voya_age'    => 'nullable|integer|min:1|max:149',
            'voya_cin'    => 'nullable|string|max:20|unique:fandrio_app.voyageurs,voya_cin',
            'voya_phone'  => 'nullable|string|max:20',
            'voya_phone2' => 'nullable|string|max:20',
            'util_id'     => 'required|integer|exists:fandrio_app.utilisateurs,util_id'
        ];
    }


    /**
     * Règles de validation pour l'ajout de plusieurs voyageurs
     */
    public static function validationDonneesMultiple(): array
    {
        return [
            'voyageurs'              => 'required|array|min:1',
            'voyageurs.*.voya_nom'    => 'required|string|max:100',
            'voyageurs.*.voya_prenom' => 'required|string|max:100',
            'voyageurs.*.voya_age'    => 'nullable|integer|min:1|max:149',
            'voyageurs.*.voya_cin'    => 'nullable|string|max:20',
            'voyageurs.*.voya_phone'  => 'nullable|string|max:20',
            'voyageurs.*.voya_phone2' => 'nullable|string|max:20',
        ];
    }


    /**
     * Règles de validation pour la modification d'un voyageur
     */
    public static function validationDonneesAmodifier(int $idVoyageur): array
    {
        return [
            'voya_nom'    => 'sometimes|required|string|max:100',
            'voya_prenom' => 'sometimes|required|string|max:100',
            'voya_age'    => 'sometimes|nullable|integer|min:1|max:149',
            'voya_cin'    => 'sometimes|nullable|string|max:20|unique:fandrio_app.voyageurs,voya_cin,' . $idVoyageur . ',voya_id',
            'voya_phone'  => 'sometimes|nullable|string|max:20',
            'voya_phone2' => 'sometimes|nullable|string|max:20',
        ];
    }


    /**
     * Conversion du DTO en tableau pour l'ajout/modification
     */
    public function convertionDonneesEnTableau(): array
    {
        return array_filter([
            'voya_nom'    => $this->voya_nom,
            'voya_prenom' => $this->voya_prenom,
            'voya_age'    => $this->voya_age,
            'voya_cin'    => $this->voya_cin,
            'voya_phone'  => $this->voya_phone,
            'voya_phone2' => $this->voya_phone2,
            'util_id'     => $this->util_id
        ], function ($valeur) {
            return $valeur !== null && $valeur !== '';
        });
    }
}
