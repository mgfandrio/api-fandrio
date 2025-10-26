<?php

namespace App\DTOs;

class ProvinceDTO
{
    public function __construct(
        public string $proNom,
        public string $proOrientation
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            proNom: $data['pro_nom'],
            proOrientation: $data['pro_orientation']
        );
    }

    /**
     * Valide l'orientation de la province
     */
    public function estOrientationValide(): bool
    {
        $orientationsValides = ['Nord', 'Sud', 'Est', 'Ouest', 'Centre'];
        return in_array($this->proOrientation, $orientationsValides);
    }
}