<?php

namespace App\Services\Client;

use App\Models\Voyages\Voyage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DisponibiliteService
{
    private const CACHE_DURATION = 30; // 30 secondes pour la fraîcheur des données
    private const CACHE_KEY_PREFIX = 'disponibilite_voyage_';
    private const LOCK_TIMEOUT = 5; // 5 secondes pour le verrouillage
    private const PLACES_ALERT_THRESHOLD = [
        'CRITIQUE' => 0.10,  // < 10% = dernières places
        'URGENT' => 0.30,    // < 30% = presque complet
        'NORMAL' => 1.00     // 30-100% = normal
    ];


    /**
     * Récupère les disponibilités d'un voyage (avec cache).
     */
    public function getDisponibilite(int $voyageId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $voyageId;

        // Vérifier le cache d'abord
        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);

            // Vérifier la fraîcheur des données
            if (time() - $cachedData['timestamp'] < 5) {
                return $cachedData;
            }
        }

        // Récupère les données fraîches depuis la base
        return $this->rafraichirDisponibilite($voyageId);
    }


    /**
     * Rafraîchit les disponibilités d'un voyage
     */
    public function rafraichirDisponibilite(int $voyageId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $voyageId;

        $voyage = Voyage::with(['trajet', 'voiture'])
            ->where('voyage_statut', 1) // Seulement les voyages programmés
            ->findOrFail($voyageId);

        $disponibilite = $this->calculerDisponibilite($voyage);

        // Mettre en cache avec timestamp
        $disponibilite['timestamp'] = time();
        $disponibilite['fraicheur'] = 'realtime';

        Cache::put($cacheKey, $disponibilite, self::CACHE_DURATION);

        return $disponibilite;
    }


    /**
     * Vérifie la disponibilité pour un nombre spécifique de places
     */
    public function verifierDisponibilite(int $voyageId, int $nbPlaces): array
    {
        return DB::transaction(function () use ($voyageId, $nbPlaces) {
            // Verrouillage pour eviter les conflits
            $voyage = Voyage::where('voyage_id', $voyageId)
                ->lockForUpdate()
                ->firstOrFail();

            // Vérification préalables
            $this->validerVoyagePourReservation($voyage);

            $placesLibres = $voyage->places_disponibles - $voyage->places_reservees;
            $disponible = $placesLibres >= $nbPlaces;

            return [
                'disponible' => $disponible,
                'places_demandees' => $nbPlaces,
                'places_libres' => $placesLibres,
                'peut_reserver' => $disponible,
                'message' => $disponible 
                    ? "{$nbPlaces} place(s) disponible(s)" 
                    : "Il ne reste que {$placesLibres} place(s) libre(s)",
                'timestamp' => time()
            ];
        });
    }


    /**
     * Récupère les disponibilités pour plusieurs voyages
     */
    public function getDisponibilitesMultiple(array $voyageIds): array
    {
        $resultat = [];
        $voyagesARafraichir = [];

        // Premier passage : récupèrer du cache
        foreach ($voyageIds as $voyageId) {
            $cacheKey = self::CACHE_KEY_PREFIX . $voyageId;
            
            if (Cache::has($cacheKey)) {
                $resultats[$voyageId] = Cache::get($cacheKey);
            } else {
                $voyagesARafraichir[] = $voyageId;
            }
        }

        // Deuxième passage : rafraîchir ceux qui ne sont pas en cache
        if (!empty($voyagesARafraichir)) {

            $voyages = Voyage::with(['trajet', 'voiture'])
                ->whereIn('voyage_id', $voyagesARafraichir)
                ->where('voyage_statut', 1)
                ->get();

            foreach ($voyages as $voyage) {
                $disponibilite = $this->calculerDisponibilite($voyage);
                $disponibilite['timestamp'] = time();
                $disponibilite['fraicheur'] = 'realtime';

                $cacheKey = self::CACHE_KEY_PREFIX . $voyage->voyage_id;
                Cache::put($cacheKey, $disponibilite, self::CACHE_DURATION);

                $resultats[$voyage->voyage_id] = $disponibilite;
            }
        }
        return $resultats;
    }


    /**
     * Met à jour les places après une réservation/annulation 
     */
    public function mettreAJourPlaces(int $voyageId, int $deltaPlaces): void
    {
        DB::transaction(function () use ($voyageId, $deltaPlaces) {
            // Verrouillage strict
            $voyage = Voyage::where('voyage_id', $voyageId)
                ->lockForUpdate()
                ->firstOrFail();

            $nouvellesPlacesReservees = $voyage->places_reservees + $deltaPlaces;

            // Validation des contraintes
            if ($nouvellesPlacesReservees < 0) {
                throw new \Exception("Le nombre de places réservées ne peut pas être négatif.");
            }

            if ($nouvellesPlacesReservees > $voyage->places_disponibles) {
                throw new \Exception('Dépassement de capacité');
            }

            // Mise à jour
            $voyage->update(['places_reservees' => $nouvellesPlacesReservees]);

            // Invalider le cache
            $this->invaliderCache($voyageId);

            // Journaliser l'opération
            $this->journaliserOperation($voyageId, $voyage->places_reservees, $nouvellesPlacesReservees, $deltaPlaces);
        });
    }


    /**
     * Récupère l'historique des modifications de places
     */
    public function getHistoriquePlaces(int $voyageId, int $limit = 10): array
    {
        return DB::table('audit_places')
            ->where('voyage_id', $voyageId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($audit) {
                return [
                    'operation' => $audit->operation,
                    'anciennes_places' => $audit->anciennes_places,
                    'nouvelles_places' => $audit->nouvelles_places,
                    'delta' => $audit->nouvelles_places - $audit->anciennes_places,
                    'utilisateur_id' => $audit->utilisateur_id,
                    'date_operation' => $audit->created_at
                ];
            })
            ->toArray();
    }


    /**
     *  Calcule les disponibilités avec tous les métadonnées
     */
    private function calculerDisponibilite(Voyage $voyage): array
    {
        $placesDisponibles = $voyage->places_disponibles;
        $placesReservees = $voyage->places_reservees;
        $placesLibres = $placesDisponibles - $placesReservees;

        // Pourcentage de remplissage
        $pourcentageRemplissage = $placesDisponibles > 0 
            ? round(($placesReservees / $placesDisponibles) * 100, 1)
            : 0;
        
        // Niveau d'urgence
        $tauxDisponibilite = $placesDisponibles > 0 
            ? $placesLibres / $placesDisponibles 
            : 0;
        
        $niveauUrgence = $this->determinerNiveauUrgence($tauxDisponibilite);

        // Status textuel
        $statut = $this->determinerStatut($placesLibres, $pourcentageRemplissage);

        // Alertes
        $alertes = $this->genererAlertes($placesLibres, $pourcentageRemplissage);

        return [
            'voyage_id' => $voyage->voyage_id,
            'voyage_date' => $voyage->voyage_date->format('Y-m-d'),
            'heure_depart' => $voyage->voyage_heure_depart,
            'trajet_nom' => $voyage->trajet->traj_nom ?? '',
            'compagnie_nom' => $voyage->trajet->compagnie->comp_nom ?? '',
            
            // Chiffres bruts
            'places_disponibles' => $placesDisponibles,
            'places_reservees' => $placesReservees,
            'places_libres' => $placesLibres,
            
            // Métriques calculées
            'pourcentage_remplissage' => $pourcentageRemplissage,
            'pourcentage_disponible' => 100 - $pourcentageRemplissage,
            
            // Indicateurs
            'est_complet' => $placesLibres <= 0,
            'est_disponible' => $placesLibres > 0,
            'niveau_urgence' => $niveauUrgence,
            'statut' => $statut,
            'couleur_statut' => $this->getCouleurStatut($niveauUrgence),
            
            // Alertes
            'alertes' => $alertes,
            'recommandation' => $this->getRecommandation($placesLibres),
            
            // Véhicule
            'vehicule' => [
                'matricule' => $voyage->voiture->voit_matricule ?? '',
                'marque' => $voyage->voiture->voit_marque ?? '',
                'modele' => $voyage->voiture->voit_modele ?? '',
                'capacite' => $voyage->voiture->voit_places ?? 0
            ],
            
            // Métadonnées
            'timestamp' => time(),
            'fraicheur' => 'calculated'
        ];
    }


    /**
     * Valide qu'un voyage peut être réservé
     */
    private function validerVoyagePourReservation(Voyage $voyage): void 
    {
        //  1.  Voyage doit $etre programmé
        if ($voyage->voyage_statut !== 1) {
            throw new \Exception('Ce voyage n\'est pas disponible pour réservation');
        }

        // 2. Date doit être dans le futur
        if (!$voyage->estFutur()) {
            throw new \Exception('Ce voyage a déjà eu lieu');
        }

        // 3. Compagnie doit être active
        if ($voyage->trajet->compagnie->comp_statut != 1) {
            throw new \Exception('La compagnie de ce voyage n\'est pas active');
        }
    }


    /**
     * Determine le niveau d'urgence
     */
    private function determinerNiveauUrgence(float $tauxDisponibilite): string
    {
        if ($tauxDisponibilite <= self::PLACES_ALERT_THRESHOLDS['CRITIQUE']) {
            return 'CRITIQUE';
        } elseif ($tauxDisponibilite <= self::PLACES_ALERT_THRESHOLDS['URGENT']) {
            return 'URGENT';
        } else {
            return 'NORMAL';
        }
    }


    /**
     * Détermine le statut textuel
     */
    private function determinerStatut(int $placesLibres, float $pourcentageRemplissage): string
    {
        if ($placesLibres <= 0) {
            return 'COMPLET';
        } elseif ($placesLibres <= 2) {
            return 'DERNIÈRES PLACES';
        } elseif ($pourcentageRemplissage >= 80) {
            return 'PRESQUE COMPLET';
        } elseif ($placesLibres >= 10) {
            return 'BONNE DISPONIBILITÉ';
        } else {
            return 'DISPONIBLE';
        }
    }


    /**
     * Génère des alertes basées sur la disponibilité
     */
    private function genererAlertes(int $placesLibres, float $pourcentageRemplissage): array
    {
        $alertes = [];

        if ($placesLibres <= 0) {
            $alertes[] = [
                'type' => 'danger',
                'message' => 'Voyage complet',
                'icone' => '⛔'
            ];
        } elseif ($placesLibres <= 2) {
            $alertes[] = [
                'type' => 'warning',
                'message' => "Plus que {$placesLibres} place(s) !",
                'icone' => '⚠️'
            ];
        } elseif ($pourcentageRemplissage >= 80) {
            $alertes[] = [
                'type' => 'info',
                'message' => 'Presque complet',
                'icone' => '📢'
            ];
        }

        return $alertes;
    }


    /**
     *  Retourne la couleur du statut
     */
    private function getCouleurStatut(string $niveauUrgence): string
    {
        return match($niveauUrgence) {
            'CRITIQUE' => '#dc3545', // Rouge
            'URGENT' => '#ffc107',   // Orange
            'NORMAL' => '#28a745',   // Vert
            default => '#6c757d'     // Gris
        };
    }


    /**
     * Retourne une recommandation
     */
    private function getRecommandation(int $placesLibres): string
    {
        if ($placesLibres <= 0) {
            return 'Voyage complet - Cherchez une autre date';
        } elseif ($placesLibres <= 2) {
            return 'Réservez rapidement avant que les dernières places ne partent !';
        } elseif ($placesLibres <= 5) {
            return 'Plus que quelques places disponibles';
        } else {
            return 'Bonnes disponibilités - Réservez à tout moment';
        }
    }


    /**
     * Journalise une opération sur les places
     */
    private function journaliserOperation(int $voyageId, int $ancien, int $nouveau, int $delta): void
    {
        $operation = $delta > 0 ? 'reservation' : ($delta < 0 ? 'annulation' : 'modification');
        
        DB::table('audit_places')->insert([
            'voyage_id' => $voyageId,
            'anciennes_places' => $ancien,
            'nouvelles_places' => $nouveau,
            'operation' => $operation,
            'utilisateur_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }


    /**
     * Invalide le cache d'un voyage
     */
    private function invaliderCache(int $voyageId): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $voyageId;
        Cache::forget($cacheKey);
    }
}