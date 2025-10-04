<?php

namespace App\DTOs;

class ConnexionDTO
{
    public function __construct(
        public string $identifiant,
        public string $motDePasse
    ) {}

    /**
     * Crée un DTO à partir des données de requête
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            identifiant: $data['identifiant'],
            motDePasse: $data['motDePasse']
        );
    }

    /**
     * Détermine si l'identifiant est un email
     */
    public function estEmail(): bool
    {
        return filter_var($this->identifiant, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Détermine si l'identifiant est un numéro de téléphone
     */
    public function estTelephone(): bool
    {
        return preg_match('/^[0-9+\-\s()]+$/', $this->identifiant) === 1;
    }
}