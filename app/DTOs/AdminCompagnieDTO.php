<?php

namespace App\DTOs;

class AdminCompagnieDTO
{
    public function __construct(
        public string $nom,
        public string $prenom,
        public string $email,
        public string $telephone,
        public string $motDePasse
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            nom: $data['admin_nom'],
            prenom: $data['admin_prenom'],
            email: $data['admin_email'],
            telephone: $data['admin_telephone'],
            motDePasse: $data['admin_mot_de_passe']
        );
    }
}