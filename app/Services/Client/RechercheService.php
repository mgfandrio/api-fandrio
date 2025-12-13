<?php

namespace App\Services\Client;

use App\Models\Voyages\Voyage;
use App\Models\Provinces\Province;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RechercherService
{
    private const CACHE_DURATION = 300; // 5 minutes pour les résultats de recherche
    private const CACHE_KEY_PREFIX = 'recherche_voyages_';

    /**
     * Recherche des voyages disponibles
     */
    public function rechercherVoyages(array $criteres): array
    {
        $cacheKey = $this->genererCleCache($criteres);

        // Utiliser le cache pour les recherches fréquentes
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = Voyage::with([
            'trajet.provinceDepart',
            'trajet.provinceArrivee',
            'trajet.compagnie',
            'voiture'
        ])
        ->where('voyage_statut', 1) // Seulement les voyages programmés (actif)
        ->where('voyage_date', '>=', now()->toDateString()) // Voyages à venir
        ->whereRaw('places_disponibles > places_reservees') // Places disponibles
        ->join('fandrio_app.trajets as t', 'voyages.traj_id', '=', 't.traj_id')
        ->join('fandrio_app.compagnies as c', 't.comp_id', '=', 'c.comp_id')
        ->where('c.comp_statut', 1); // Compagnies actives

        // Appliquer les filtres
        $this->appliquerFiltres($query, $criteres);

        // Tri par défaut : date la plus proche
        $query->orderBy('voyage_date', 'asc')
              ->orderBy('voyage_heure_depart', 'asc');

        // Pagination
        $perPage = $criteres['per_page'] ?? 15;
        $voyages = $query->paginate($perPage);

        $resultat = [
            'voyages'   => $voyages->map(function($voyage) {
                return $this->formaterVoyageRecherche($voyage);
            }),
            'pagination' => [
                'total' => $voyages->total(),
                'per_page' => $voyages->perPage(),
                'current_page' => $voyages->currentPage(),
                'last_page' => $voyages->lastPage(),
            ],
            'filtres_appliques' => $criteres
        ];

        // Mettre en cache seulement les recherches sans pagination spécifique
        if($perPage == 15 && count($criteres) <= 3) {
            Cache::put($cacheKey, $resultat, self::CACHE_DURATION);
        }

        return $resultat;
    }


    /**
     * Récupère les suggestions de recherche
     */
    public function getSuggestions(): array
    {
        $cacheKey = 'suggestions_recherche';

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Destinations populaires (provinces avec le plus de trajets)
        $destinationsPopulaires = DB::table('fandrio_app.trajets as t')
            ->select('p.pro_id', 'p.pro_nom', DB::raw('COUNT(*) as nombre_trajets'))
            ->join('fandrio_app.provinces as p', 't.pro_arrivee', '=', 'p.pro_id')
            ->groupBy('p.pro_id', 'p.pro_nom')
            ->orderBy('nombre_trajets', 'desc')
            ->limit(10)
            ->get()
            ->map(function($item) {
                return [
                    'id' => $item->pro_id,
                    'nom' => $item->pro_nom,
                    'type' => 'destination'
                ];
            });
        
        // Compagnies populaires 
        $compagniesPopulaires = DB::table('fandrio_app.compagnies as c')
            ->select('c.comp_id', 'c.comp_nom', DB::raw('COUNT(DISTINCT t.traj_id) as nombre_trajets'))
            ->leftJoin('fandrio_app.trajets as t', 'c.comp_id', '=', 't.comp_id')
            ->where('c.comp_statut', 1)
            ->groupBy('c.comp_id', 'c.comp_nom')
            ->orderBy('nombre_trajets', 'desc')
            ->limit(10)
            ->get()
            ->map(function($item) {
                return [
                    'id' => $item->comp_id,
                    'nom' => $item->comp_nom,
                    'type' => 'compagnie'
                ];
            });
        
        // Dates avec des voyages disponibles (7 prochains jours)
        $datesDisponibles = [];
        for ($i = 0; $i < 7; $i++) {
            $date = now()->addDays($i)->format('Y-m-d');
            $count = Voyage::where('voyage_date', $date)
                ->where('voyage_statut', 1)
                ->whereRaw('places_disponibles > places_reservees')
                ->count();

            if ($count > 0) {
                $datesDisponibles[] = [
                    'date' => $date,
                    'jour' => now()->addDays($i)->locale('fr')->dayName,
                    'nombre_voyages' => $count
                ];
            }
        }

        $suggestions = [
            'destinations_populaires' => $destinationsPopulaires,
            'compagnies_populaires' => $compagniesPopulaires,
            'dates_disponibles' => $datesDisponibles
        ];

        Cache::put($cacheKey, $suggestions, 3600); // 1 heure

        return $suggestions;
    }



    /**
     * Recupère un voyage avec tous les détails pour la reservation
     */
    public function getVoyageDetail(int $voyageId): array
    {
        $voyage = Voyage::with([
            'trajet.provinceDepart',
            'trajet.provinceArrivee',
            'trajet.compagnie',
            'voiture',
            'voiture.chauffeur'
        ])
        ->where('voyage_statut', 1)
        ->findOrFail($voyageId);

        // Vérifier qu'il y a des place disponibles
        if ($voyage->estComplet()) {
            throw new \Exception('Ce voyage est complet');
        }

        // Vérifier que le voyage est à venir
        if (!$voyage->estFutur()) {
            throw new \Exception('Ce voyage a déjà eu lieu');
        }

        return $this->formaterVoyageDetail($voyage);
    }


    /**
     * Recherche rapise par destination
     */
    public function rechercheRapide(int $provinceArriveeId, ?string $date = null): array
    {
        $query = Voyage::with([
            'trajet.provinceDepart',
            'trajet.provinceArrivee',
            'trajet.compagnie'
        ])
        ->where('voyage_statut', 1)
        ->whereHas('trajet', function($q) use ($provinceArriveeId) {
            $q->where('pro_arrivee', $provinceArriveeId);
        })
        ->where('voyage_date', '>=', now()->toDateString())
        ->whereRaw('places_disponibles > places_reservees');

        if ($date) {
            $query->where('voyage_date', $date);
        } else {
            // Par défaut, les 3 prochains jours
            $query->where('voyage_date', '<=', now()->addDays(3)->format('Y-m-d'));
        }

        $query->orderBy('voyage_date', 'asc')
              ->orderBy('voyage_heure_depart', 'asc')
              ->limit(20);

        $voyages = $query->get();

        return [
            'voyages'   => $voyages->map(function($voyage) {
                return $this->formaterVoyageRecherche($voyage);
            }),
            'total' => $voyages->count()
        ];
    }


    /**
     * Appliquer les filtres de recherche
     */
    private function appliquerFiltres($query, array $criteres): void
    {
        // Filtre par province de départ
        if (isset($criteres['pro_depart'])) {
            $query->whereHas('trajet', function($q) use ($criteres) {
                $q->where('pro_depart', $criteres['pro_depart']);
            });
        }

        // Filtre par province d'arrivée
        if (isset($criteres['pro_arrivee'])) {
            $query->whereHas('trajet', function($q) use ($criteres) {
                $q->where('pro_arrivee', $criteres['pro_arrivee']);
            });
        }

        // Filtre par compagnie
        if(isset($criteres['compagnie_id'])) {
            $query->whereHas('trajet.compagnie', function($q) use ($criteres) {
                $q->where('comp_id', $criteres['compagnie_id']);
            });
        }

        // Filtre par date exacte
        if (isset($criteres['date_exacte'])) {
            $query->where('voyage_date', $criteres['date_exacte']);
        }

        // Filtre par plage de dates
        if (isset($criteres['date_debut'])) {
            $query->where('voyage_date', '>=', $criteres['date_debut']);
        }

        if (isset($criteres['date_fin'])) {
            $query->where('voyage_date', '<=', $criteres['date_fin']);
        }

        // Filtre par type de voyage (jour/nuit)
        if (isset($criteres['type_voyage'])) {
            $query->where('voyage_type', $criteres['type_voyage']);
        }

        // Filtre par nombre de places minimum
        if (isset($criteres['places_min'])) {
            $query->whereRaw('(places_disponibles - places_reservees) >= ?', [$criteres['places_min']]);
        }

        // Filtre par prix maximum
        if (isset($criteres['prix_max'])) {
            $query->whereHas('trajet', function($q) use ($criteres) {
                $q->where('traj_tarif', '<=', $criteres['prix_max']);
            });
        }

        // Filtre par heure de départ
        if (isset($criteres['heure_depart_min'])) {
            $query->where('voyage_heure_depart', '>=', $criteres['heure_depart_min']);
        }

        if (isset($criteres['heure_depart_max'])) {
            $query->where('voyage_heure_depart', '<=', $criteres['heure_depart_max']);
        }
    }


    /**
     *  Formate un voyage pour la recherche
     */
    private function formaterVoyageRecherche(Voyage $voyage): array
    {
        $trajet = $voyage->trajet;
        $compagnie = $trajet->compagnie;
        $voiture = $voyage->voiture;

        return [
            'voyage_id' => $voyage->voyage_id,
            'date' => $voyage->voyage_date->format('Y-m-d'),
            'heure_depart' => $voyage->voyage_heure_depart,
            'type' => $voyage->voyage_type == 1 ? 'jour' : 'nuit',
            'places_disponibles' => $voyage->places_disponibles - $voyage->places_reservees,
            'est_complet' => $voyage->estComplet(),
            'trajet' => [
                'id' => $trajet->traj_id,
                'nom' => $trajet->traj_nom,
                'tarif' => (float) $trajet->traj_tarif,
                'distance_km' => $trajet->traj_km,
                'duree' => $trajet->getDureeFormatee(),
                'province_depart' => [
                    'id' => $trajet->provinceDepart->pro_id,
                    'nom' => $trajet->provinceDepart->pro_nom
                ],
                'province_arrivee' => [
                    'id' => $trajet->provinceArrivee->pro_id,
                    'nom' => $trajet->provinceArrivee->pro_nom
                ]
            ],
            'compagnie' => [
                'id' => $compagnie->comp_id,
                'nom' => $compagnie->comp_nom,
                'logo' => $compagnie->comp_logo,
                'note' => null // À implémenter avec un système de notation
            ],
            'voiture' => [
                'matricule' => $voiture->voit_matricule,
                'marque' => $voiture->voit_marque,
                'modele' => $voiture->voit_modele,
                'capacite' => $voiture->voit_places
            ]
        ];
    }


    /***
     * Formate les détails d'un voyage
     */
    private function formaterVoyageDetail(Voyage $voyage): array
    {
        $trajet = $voyage->trajet;
        $compagnie = $trajet->compagnie;
        $voiture = $voyage->voiture;

        $formatted = $this->formaterVoyageRecherche($voyage);

        // Ajouter les informations supplémentaires
        $formatted['details'] = [
            'date_complete' => $voyage->voyage_date->format('d/m/Y'),
            'jour_semaine' => $voyage->voyage_date->locale('fr')->dayName,
            'heure_depart_12h' => date('h:i A', strtotime($voyage->voyage_heure_depart)),
            'peut_etre_annule' => $voyage->peutEtreAnnule(),
            'delai_annulation' => '24h avant le départ'
        ];

        // Informations sur le chauffeur
        if ($voiture->chauffeur) {
            $formatted['chauffeur'] = [
                'nom'   => $voiture->chauffeur->chauff_nom . ' ' . $voiture->chauffeur->chauff_prenom,
                'permis'=> $voiture->chauffeur->chauff_permis,
                'experience' => null // À implémenter
            ];
        }

        // Modes de paiements acceptés par la compagnie
        $formatted['compagnie']['modes_paiement'] = $compagnie->modesPaiement->map(function($mode) {
            return [
                'type_paie_id' => $mode->type_paie_id,
                'nom_paie' => $mode->type_paie_nom,
                'type_paie' => $mode->type_paie_type == 1 ? 'mobile_money' : 'cash'
            ];
        });

        return $formatted;
    }


    /**
     * Génère une clé de cache basée sur les critères de recherche
     */
    private function genererCleCache(array $criteres): string
    {
        // Ne pas inclure la pagination dans la clé de cache
        unset($criteres['page'], $criteres['per_page']);
        ksort($criteres);

        return self::CACHE_KEY_PREFIX . md5(serialize($criteres));
    }
}
