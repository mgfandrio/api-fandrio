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
     * Une carte du fil = un trajet à une date. Si la compagnie programme
     * plusieurs départs ce jour-là (matin, soir…), ils sont regroupés dans
     * `departs` et le client choisit son heure avant de réserver.
     *
     * Ordre des cartes :
     *  1. avec géolocalisation : départ de la province du client, puis arrivée
     *     vers cette province, puis le reste ;
     *  2. diversité : la prochaine date de CHAQUE trajet passe avant la date
     *     suivante d'un même trajet ;
     *  3. ordre aléatoire stable (rotation toutes les 2 h).
     *
     * Seule la liste ordonnée des cartes est mise en cache (5 min au plus,
     * invalidée dès qu'un voyage ou une compagnie change) ; le détail est lu
     * en direct, avec les places et l'état actuels.
     */
    public function getVoyagesPage(?Province $provinceProche, int $page = 1, int $parPage = 10): array
    {
        $parPage = max(1, min($parPage, 30));
        $groupes = $this->getOrdreVoyages($provinceProche);
        $total = count($groupes);
        $tranche = array_slice($groupes, ($page - 1) * $parPage, $parPage);

        $cartes = [];
        if (!empty($tranche)) {
            $paires = array_map(fn($g) => explode('|', $g), $tranche);
            $voyages = $this->voyagesVisibles()
                ->with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'trajet.compagnie.modesPaiement', 'voiture'])
                ->where(function ($q) use ($paires) {
                    foreach ($paires as [$trajId, $date]) {
                        $q->orWhere(fn($q) => $q->where('voyages.traj_id', (int) $trajId)->where('voyages.voyage_date', $date));
                    }
                })
                ->orderBy('voyages.voyage_heure_depart')
                ->get()
                ->groupBy(fn($v) => $v->traj_id . '|' . $v->voyage_date->toDateString());

            // Garder l'ordre du fil ; une carte dont tous les départs sont devenus
            // complets / en pause entre-temps est omise
            foreach ($tranche as $cle) {
                $departs = $voyages[$cle] ?? null;
                if (!$departs || $departs->isEmpty()) {
                    continue;
                }
                $carte = $this->formaterVoyage($departs->first());
                $carte['departs'] = $departs->map(fn(Voyage $v) => $this->formaterDepart($v))->values()->all();
                $cartes[] = $carte;
            }
        }

        return [
            'voyages' => $cartes,
            'pagination' => [
                'page' => $page,
                'par_page' => $parPage,
                'total' => $total,
                'a_suivre' => $page * $parPage < $total,
            ],
        ];
    }

    /** Un départ d'une carte du fil (ce qui change d'une heure à l'autre). */
    private function formaterDepart(Voyage $voyage): array
    {
        $voiture = $voyage->voiture;
        return [
            'voyage_id'          => $voyage->voyage_id,
            'heure_depart'       => $voyage->voyage_heure_depart,
            'type'               => $voyage->voyage_type == 1 ? 'jour' : 'nuit',
            'places_disponibles' => max(0, $voyage->places_disponibles - $voyage->places_reservees),
            'est_complet'        => $voyage->estComplet(),
            'voiture' => $voiture ? [
                'matricule' => $voiture->voit_matricule,
                'marque'    => $voiture->voit_marque,
                'modele'    => $voiture->voit_modele,
                'capacite'  => $voiture->voit_places,
            ] : null,
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

    /** Liste ordonnée des cartes du fil, « traj_id|date » (mise en cache). */
    private function getOrdreVoyages(?Province $provinceProche): array
    {
        $slot = $this->getRotationSlot();
        $cle = 'accueil_ordre:' . self::version() . ':' . ($provinceProche?->pro_id ?? 'aucune') . ':' . $slot;

        return Cache::remember($cle, 300, function () use ($provinceProche, $slot) {
            // Une ligne par trajet et par date
            $groupes = $this->voyagesVisibles()
                ->reorder()
                ->select('voyages.traj_id', 'voyages.voyage_date', 't.pro_depart', 't.pro_arrivee')
                ->selectRaw('MIN(voyages.voyage_id) AS id_ref')
                ->groupBy('voyages.traj_id', 'voyages.voyage_date', 't.pro_depart', 't.pro_arrivee');

            $base = DB::query()->fromSub($groupes, 'g')
                ->select('g.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY g.traj_id ORDER BY g.voyage_date) AS rang');

            $query = DB::query()->fromSub($base, 'x')->select('x.traj_id', 'x.voyage_date');
            if ($provinceProche) {
                $query->orderByRaw(
                    'CASE WHEN x.pro_depart = ? THEN 0 WHEN x.pro_arrivee = ? THEN 1 ELSE 2 END',
                    [$provinceProche->pro_id, $provinceProche->pro_id]
                );
            }
            $query->orderBy('x.rang')->orderByRaw('hashint4extended(x.id_ref, ?)', [$slot]);

            return $query->limit(500)->get()
                ->map(fn($g) => (int) $g->traj_id . '|' . substr((string) $g->voyage_date, 0, 10))
                ->all();
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
