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
    public function getDonneesAccueil(?float $latitude = null, ?float $longitude = null, int $page = 1): array
    {
        $provinceProche = null;

        if ($latitude !== null && $longitude !== null) {
            $provinceProche = $this->trouverProvinceProche($latitude, $longitude);
        }

        $fil = $this->getVoyagesPage($provinceProche, max(1, $page));

        // Pages suivantes : seulement la suite du fil
        if ($page > 1) {
            return $fil;
        }

        $slot = $this->getRotationSlot();
        $cleCompagnies = 'accueil_compagnies:' . self::version() . ':' . ($provinceProche?->pro_id ?? 'aucune') . ':' . $slot;

        return [
            'compagnies' => Cache::remember($cleCompagnies, self::CACHE_DURATION, fn () => $this->getCompagnies($provinceProche)),
            'voyages'    => $fil['voyages'],
            'pagination' => $fil['pagination'],
            'province_detectee' => $provinceProche ? [
                'id'  => $provinceProche->pro_id,
                'nom' => $provinceProche->pro_nom,
            ] : null,
        ];
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
            ->where('comp_statut', 1)
            ->where('comp_suspendu_commission', false); // Exclure compagnies suspendues (commission impayée)

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
            $query->orderByRaw("hashint4extended(comp_id, ?)", [$slot]);
        } else {
            // Pas de localisation — rotation pure toutes les 2h
            $query->orderByRaw("hashint4extended(comp_id, ?)", [$slot]);
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
     * Fil des voyages de l'accueil, paginé (défilement infini côté app).
     *
     * Ordre :
     *  1. avec géolocalisation : voyages au départ de la province du client,
     *     puis à destination de cette province, puis les autres ;
     *  2. diversité : le prochain départ de CHAQUE trajet passe avant le
     *     deuxième départ d'un même trajet (un trajet très programmé ne
     *     monopolise pas le fil) ;
     *  3. ordre aléatoire stable (rotation toutes les 2 h).
     *
     * Seule la liste ordonnée des identifiants est mise en cache (5 min au plus,
     * invalidée dès qu'un voyage ou une compagnie change) ; le détail de la page
     * est lu en direct, avec les places et l'état actuels.
     */
    public function getVoyagesPage(?Province $provinceProche, int $page = 1, int $parPage = 10): array
    {
        $parPage = max(1, min($parPage, 30));
        $ids = $this->getOrdreVoyages($provinceProche);
        $total = count($ids);
        $tranche = array_slice($ids, ($page - 1) * $parPage, $parPage);

        $voyages = [];
        if (!empty($tranche)) {
            $charges = $this->voyagesVisibles()
                ->with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'trajet.compagnie.modesPaiement', 'voiture'])
                ->whereIn('voyages.voyage_id', $tranche)
                ->get()
                ->keyBy('voyage_id');
            // Garder l'ordre du fil ; un voyage devenu complet / en pause entre-temps est omis
            foreach ($tranche as $id) {
                if (isset($charges[$id])) {
                    $voyages[] = $this->formaterVoyage($charges[$id]);
                }
            }
        }

        return [
            'voyages' => $voyages,
            'pagination' => [
                'page' => $page,
                'par_page' => $parPage,
                'total' => $total,
                'a_suivre' => $page * $parPage < $total,
            ],
        ];
    }

    /** Voyages réservables maintenant : programmés, actifs, pas encore partis, avec des places. */
    private function voyagesVisibles()
    {
        $maintenant = now();
        return Voyage::query()
            ->join('fandrio_app.trajets as t', 'voyages.traj_id', '=', 't.traj_id')
            ->join('fandrio_app.compagnies as c', 't.comp_id', '=', 'c.comp_id')
            ->where('voyages.voyage_statut', 1)
            ->where('voyages.voyage_is_active', true)
            ->where(function ($q) use ($maintenant) {
                $q->where('voyages.voyage_date', '>', $maintenant->toDateString())
                    ->orWhere(function ($q) use ($maintenant) {
                        $q->where('voyages.voyage_date', $maintenant->toDateString())
                            ->where('voyages.voyage_heure_depart', '>', $maintenant->format('H:i:s'));
                    });
            })
            ->whereRaw('voyages.places_disponibles > voyages.places_reservees')
            ->where('c.comp_statut', 1)
            ->where('c.comp_suspendu_commission', false) // compagnies suspendues (commission impayée) exclues
            ->select('voyages.*');
    }

    /** Liste ordonnée des identifiants du fil (mise en cache). */
    private function getOrdreVoyages(?Province $provinceProche): array
    {
        $slot = $this->getRotationSlot();
        $cle = 'accueil_ordre:' . self::version() . ':' . ($provinceProche?->pro_id ?? 'aucune') . ':' . $slot;

        return Cache::remember($cle, 300, function () use ($provinceProche, $slot) {
            $base = $this->voyagesVisibles()
                ->addSelect('t.pro_depart', 't.pro_arrivee')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY voyages.traj_id ORDER BY voyages.voyage_date, voyages.voyage_heure_depart) AS rang');

            $query = DB::query()->fromSub($base, 'x')->select('x.voyage_id');
            if ($provinceProche) {
                $query->orderByRaw(
                    'CASE WHEN x.pro_depart = ? THEN 0 WHEN x.pro_arrivee = ? THEN 1 ELSE 2 END',
                    [$provinceProche->pro_id, $provinceProche->pro_id]
                );
            }
            $query->orderBy('x.rang')->orderByRaw('hashint4extended(x.voyage_id, ?)', [$slot]);

            return $query->limit(500)->pluck('voyage_id')->map(fn($id) => (int) $id)->all();
        });
    }

    /** Version du fil : change dès qu'un voyage ou une compagnie est modifié. */
    private static function version(): string
    {
        return (string) Cache::rememberForever('accueil_version', fn() => (string) now()->getTimestampMs());
    }

    /** À appeler quand un voyage ou une compagnie change : le fil est recalculé. */
    public static function invalider(): void
    {
        Cache::forever('accueil_version', (string) now()->getTimestampMs() . random_int(0, 999));
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
                'categorie'       => $trajet->traj_categorie ?? 'classique',
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
                'modes_paiement' => $compagnie->modesPaiement->map(function($mode) {
                    return [
                        'type_paie_id' => $mode->type_paie_id,
                        'nom_paie' => $mode->type_paie_nom,
                        'type_paie' => $mode->type_paie_type == 1 ? 'mobile_money' : 'cash',
                        'numero' => $mode->pivot->comp_paie_numero ?? null,
                        'titulaire' => $mode->pivot->comp_paie_titulaire ?? null,
                    ];
                }),
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
