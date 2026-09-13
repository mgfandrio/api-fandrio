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
        public array $modesPaiement = [],
        public ?string $commFrequenceCollecte = null,
        public ?string $commJourCollecte = null,
        public ?string $papiApiKey = null,
        public ?string $papiWebhookSecret = null,
        public ?bool $papiActif = null
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
            modesPaiement: $data['modes_paiement'] ?? [],
            commFrequenceCollecte: $data['comm_frequence_collecte'] ?? null,
            commJourCollecte: $data['comm_jour_collecte'] ?? null,
            papiApiKey: $data['papi_api_key'] ?? null,
            papiWebhookSecret: $data['papi_webhook_secret'] ?? null,
            papiActif: array_key_exists('papi_actif', $data) ? (bool) $data['papi_actif'] : null
        );
    }
}