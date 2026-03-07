<?php

namespace App\DTOs;

class CompagnieDTO
{
    public function __construct(
        public string $compNom,
        public string $compNif,
        public string $compStat,
        public string $compDescription,
        public string $compPhone,
        public string $compEmail,
        public string $compAdresse,
        public ?int $compLocalisation = null,
        public array $provincesDesservies = [],
        public array $modesPaiement = []
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            compNom: $data['comp_nom'],
            compNif: $data['comp_nif'],
            compStat: $data['comp_stat'],
            compDescription: $data['comp_description'],
            compPhone: $data['comp_phone'],
            compEmail: $data['comp_email'],
            compAdresse: $data['comp_adresse'],
            compLocalisation: $data['comp_localisation'] ?? null,
            provincesDesservies: $data['provinces_desservies'] ?? [],
            modesPaiement: $data['modes_paiement'] ?? []
        );
    }
}