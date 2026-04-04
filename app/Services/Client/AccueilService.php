<?php

namespace App\Services\Client;

use App\Models\Compagnies\Compagnie;
use App\Models\Provinces\Province;
use App\Models\Voyages\Voyage;
use App\Helpers\DateFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AccueilService
{
    /**
     * Durée du cache : 15 minutes
     */
    private const CACHE_DURATION = 900;

    /**
     * Récupère les données de la page d'accueil client.
     * Priorise le contenu selon la géolocalisation du client.
     * Rotation toutes les 2h si pas de géolocalisation.
     */
    public function getDonneesAccueil(?float $latitude = null, ?float $longitude = null): array
    {
        $provinceProche = null;

        if ($latitude !== null && $longitude !== null) {
            $provinceProche = $this->trouverProvinceProche($latitude, $longitude);
        }

        $slot = $this->getRotationSlot();
        $cacheKey = $provinceProche
            ? "accueil_province_{$provinceProche->pro_id}_{$slot}"
            : "accueil_aleatoire_{$slot}";

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($provinceProche) {
            return [
                'compagnies' => $this->getCompagnies($provinceProche),
                'voyages'    => $this->getVoyages($provinceProche),
                'province_detectee' => $provinceProche ? [
                    'id'  => $provinceProche->pro_id,
                    'nom' => $provinceProche->pro_nom,
                ] : null,
            ];
        });
    }

    /**
     * Trouve la province la plus proche des coordonnées GPS.
     * Calcul euclidien simple — suffisant pour 6 provinces.
     */
    private function trouverProvinceProche(float $lat, float $lng): ?Province
    {
        $provinces = Cache::remember('provinces_avec_coords', 3600, function () {
            return Province::whereNotNull('pro_latitude')
                ->whereNotNull('pro_longitude')
                ->get();
        });

        if ($provinces->isEmpty()) {
            return null;
        }

        $plusProche = null;
        $distanceMin = PHP_FLOAT_MAX;

        foreach ($provinces as $province) {
            // Distance euclidienne simple (pas Haversine — overkill pour 6 points)
            $dLat = $lat - $province->pro_latitude;
            $dLng = $lng - $province->pro_longitude;
            $distance = ($dLat * $dLat) + ($dLng * $dLng);

            if ($distance < $distanceMin) {
                $distanceMin = $distance;
                $plusProche = $province;
            }
        }

        return $plusProche;
    }

    /**
     * Slot de rotation toutes les 2 heures.
     * Garantit un ordre stable pendant 2h, puis change.
     */
    private function getRotationSlot(): int
    {
        return intdiv(time(), 7200); // 7200 secondes = 2 heures
    }

    /**
     * Récupère les compagnies pour l'accueil.
     * - Si province connue : compagnies de cette province en premier, puis les autres
     * - Sinon : ordre aléatoire stable par slot de 2h
     */
    private function getCompagnies(?Province $provinceProche): array
    {
        $slot = $this->getRotationSlot();

        $query = Compagnie::with(['localisation'])
            ->where('comp_statut', 1);

        if ($provinceProche) {
            // Compagnies localisées dans la province du client en premier,
            // puis celles qui desservent cette province, puis les autres
            $query->orderByRaw("
                CASE
                    WHEN comp_localisation = ? THEN 0
                    WHEN comp_id IN (
                        SELECT comp_id FROM fandrio_app.compagnie_provinces
                        WHERE pro_id = ? AND comp_pro_statut = 1
                    ) THEN 1
                    ELSE 2
                END ASC
            ", [$provinceProche->pro_id, $provinceProche->pro_id]);

            // Rotation au sein de chaque groupe
            $query->orderByRaw("MD5(comp_id::text || ?::text)", [$slot]);
        } else {
            // Pas de localisation — rotation pure toutes les 2h
            $query->orderByRaw("MD5(comp_id::text || ?::text)", [$slot]);
        }

        $compagnies = $query->limit(10)->get();

        return $compagnies->map(function (Compagnie $compagnie) {
            return [
                'id'           => $compagnie->comp_id,
                'nom'          => $compagnie->comp_nom,
                'logo'         => $compagnie->comp_logo,
                'description'  => $compagnie->comp_description,
                'telephone'    => $compagnie->comp_phone,
                'localisation' => $compagnie->localisation ? [
                    'id'  => $compagnie->localisation->pro_id,
                    'nom' => $compagnie->localisation->pro_nom,
                ] : null,
            ];
        })->toArray();
    }

    /**
     * Récupère les voyages à venir pour l'accueil.
     * - Si province connue : voyages partant de cette province en priorité,
     *   puis provinces voisines, puis le reste
     * - Sinon : rotation aléatoire stable par slot de 2h
     */
    private function getVoyages(?Province $provinceProche): array
    {
        $slot = $this->getRotationSlot();

        $query = Voyage::with([
            'trajet.provinceDepart',
            'trajet.provinceArrivee',
            'trajet.compagnie',
            'voiture'
        ])
            ->where('voyage_statut', 1)
            ->where('voyage_date', '>=', now()->toDateString())
            ->whereRaw('places_disponibles > places_reservees')
            ->join('fandrio_app.trajets as t', 'voyages.traj_id', '=', 't.traj_id')
            ->join('fandrio_app.compagnies as c', 't.comp_id', '=', 'c.comp_id')
            ->where('c.comp_statut', 1)
            ->select('voyages.*');

        if ($provinceProche) {
            // Prioriser : départ depuis la province du client, puis arrivée vers cette province
            $query->orderByRaw("
                CASE
                    WHEN t.pro_depart = ? THEN 0
                    WHEN t.pro_arrivee = ? THEN 1
                    ELSE 2
                END ASC
            ", [$provinceProche->pro_id, $provinceProche->pro_id]);

            // Puis par date
            $query->orderBy('voyage_date', 'asc');
            $query->orderBy('voyage_heure_depart', 'asc');

            // Rotation au sein de chaque groupe de priorité
            $query->orderByRaw("MD5(voyage_id::text || ?::text)", [$slot]);
        } else {
            // Pas de localisation — rotation aléatoire par slot de 2h
            $query->orderByRaw("MD5(voyage_id::text || ?::text)", [$slot]);
        }

        $voyages = $query->limit(10)->get();

        return $voyages->map(function (Voyage $voyage) {
            return $this->formaterVoyage($voyage);
        })->toArray();
    }

    /**
     * Formate un voyage pour la réponse API.
     */
    private function formaterVoyage(Voyage $voyage): array
    {
        $trajet = $voyage->trajet;
        $compagnie = $trajet->compagnie;
        $voiture = $voyage->voiture;

        return [
            'voyage_id'          => $voyage->voyage_id,
            'date'               => DateFormatter::formatDate($voyage->voyage_date),
            'heure_depart'       => $voyage->voyage_heure_depart,
            'type'               => $voyage->voyage_type == 1 ? 'jour' : 'nuit',
            'places_disponibles' => $voyage->places_disponibles - $voyage->places_reservees,
            'est_complet'        => $voyage->estComplet(),
            'trajet' => [
                'id'              => $trajet->traj_id,
                'nom'             => $trajet->traj_nom,
                'tarif'           => (float) $trajet->traj_tarif,
                'distance_km'     => $trajet->traj_km,
                'duree'           => $trajet->getDureeFormatee(),
                'province_depart' => [
                    'id'  => $trajet->provinceDepart->pro_id,
                    'nom' => $trajet->provinceDepart->pro_nom,
                ],
                'province_arrivee' => [
                    'id'  => $trajet->provinceArrivee->pro_id,
                    'nom' => $trajet->provinceArrivee->pro_nom,
                ],
            ],
            'compagnie' => [
                'id'   => $compagnie->comp_id,
                'nom'  => $compagnie->comp_nom,
                'logo' => $compagnie->comp_logo,
            ],
            'voiture' => $voiture ? [
                'matricule' => $voiture->voit_matricule,
                'marque'    => $voiture->voit_marque,
                'modele'    => $voiture->voit_modele,
                'capacite'  => $voiture->voit_places,
            ] : null,
        ];
    }
}
