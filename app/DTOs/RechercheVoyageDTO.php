<?php

namespace App\DTOs;

class RechercheVoyageDTO
{
    public function __construct(
        public ?int $proDepart = null,
        public ?int $proArrivee = null,
        public ?int $compagnieId = null,
        public ?string $dateExacte = null,
        public ?string $dateDebut = null,
        public ?string $dateFin = null,
        public ?int $typeVoyage = null,
        public ?int $placesMin = null,
        public ?float $prixMax = null,
        public ?string $heureDepartMin = null,
        public ?string $heureDepartMax = null
    ) {}


    public static function fromRequest(array $data): self 
    {
        return new self(
            proDepart: $data['pro_depart'] ?? null,
            proArrivee: $data['pro_arrivee'] ?? null,
            compagnieId: $data['compagnie_id'] ?? null,
            dateExacte: $data['date_exacte'] ?? null,
            dateDebut: $data['date_debut'] ?? null,
            dateFin: $data['date_fin'] ?? null,
            typeVoyage: $data['type_voyage'] ?? null,
            placesMin: $data['places_min'] ?? null,
            prixMax: $data['prix_max'] ?? null,
            heureDepartMin: $data['heure_depart_min'] ?? null,
            heureDepartMax: $data['heure_depart_max'] ?? null
        );
    }


    /**
     * Valide les dates
     */
    public function validateDates(): void
    {
        if ($this->dateExacte && strtotime($this->dateExacte) < strtotime('today')) {
            throw new \Exception('La date de recherche ne peut pas être dans le passé');
        }

        if ($this->dateDebut && strtotime($this->dateDebut) < strtotime('today')) {
            throw new \Exception('La date de début ne peut pas être dans le passé');
        }

        if ($this->dateFin && $this->dateDebut && strtotime($this->dateFin) < strtotime($this->dateDebut)) {
            throw new \Exception('La date de fin ne peut pas être antérieure à la date de début');
        }
    }
    
    /**
     * Vérifie si au moins un critère de recherche est fourni
     */
    public function hasCriteria(): bool 
    {
        return !empty(array_filter(get_object_vars($this)));
    }
}