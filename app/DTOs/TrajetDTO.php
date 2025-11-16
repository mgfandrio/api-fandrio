<?php

namespace App\DTOs;

class TrajetDTO
{
    public function __construct(
        public string $trajNom,
        public int $proDepart,
        public int $proArrivee,
        public float $trajTarif,
        public ?int $trajKm = null,
        public ?string $trajDuree = null
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            trajNom: $data['traj_nom'],
            proDepart: $data['pro_depart'],
            proArrivee: $data['pro_arrivee'],
            trajTarif: $data['traj_tarif'],
            trajKm: $data['traj_km'] ?? null,
            trajDuree: $data['traj_duree'] ?? null
        );
    }

    public function validate(): void
    {
        if($this->proDepart === $this->proArrivee) {
            throw new \Exception('La province de départ et d\'arrivée doivent être différentes');
        }

        if($this->trajTarif <= 0) {
            throw new \Exception('Le tarif doit être supérieur à 0');
        }

        if($this->trajKm !== null && $this->trajKm <= 0) {
            throw new \Exception('La distance doit être supérieure à 0');
        }
    }
}