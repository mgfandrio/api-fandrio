<?php

namespace App\DTOs;

class InscriptionDTO
{
    public function __construct(
        public string $nom,
        public string $prenom,
        public string $email,
        public string $telephone,
        public string $motDePasse,
        public ?string $dateNaissance = null
    ) {}

    /**
     * Crée un DTO à partir des données de requête
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            nom: $data['nom'],
            prenom: $data['prenom'],
            email: $data['email'],
            telephone: $data['telephone'],
            motDePasse: $data['motDePasse'],
            dateNaissance: $data['dateNaissance'] ?? null
        );
    }
}