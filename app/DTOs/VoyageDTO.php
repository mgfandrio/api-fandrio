<?php

namespace App\DTOs;

class VoyageDTO 
{
    public function __construct(
        public string $voyageDate,
        public string $voyageHeureDepart,
        public int $trajetId,
        public int $voitId,
        public int $voyageType = 1,
        public int $placesDisponibles
    ) {}

    public static function fromRequest(array $data): self 
    {
        return new self(
            voyageDate: $data['voyage_date'],
            voyageHeureDepart: $data['voyage_heure_depart'],
            trajId : $data['traj_id'],
            voitId : $data['voit_id'],
            voyageType: $data['voyage_type'] ?? 1,
            placesDisponibles: $data['places_disponibles']
        );
    }

    public function validate(): void 
    {
        $dateTimeDepart = $this->voyageDate . ' ' . $this->voyageHeureDepart;

        if(strtotime($dateTimeDepart) <= time()) {
            throw new \Exception('La date et heure de départ doivent être dans le futur');
        }

        if ($this->placeDisponibles <= 0) {
            throw new \Exception('Le nombre de places disponibles doit être supérieur à 0');
        }

        if (!in_array($this->voyageType, [1, 2])) {
            throw new \Exception('Le type de voyage doit être 1 (jour) ou 2 (nuit)');
        }
    }
}