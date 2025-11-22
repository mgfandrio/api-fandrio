<?php

namespace App\Services\voyage;

use App\Models\Voyages\Voyage;
use App\Models\Trajet\Trajet;
use App\Models\Voiture\Voiture;
use App\DTOs\VoyageDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


class VoyageService
{
    /**
     *  Récupère la compagnie de l'utilisateur authentifié.
     */
    private function getCompagnieUtilisateur()
    {
        $utilisateur = Auth::user();

        if (!$utilisateur || !$utilisateur->comp_id) {
            throw new \Exception('Utilisateur non associé à une compagnie');
        }

        return $utilisateur->comp_id;
    }


    /**
     * Programmer un nouveau voyage.
     */
    public function programmerVoyage(VoyageDTO $voyageDTO): array 
    {
        return DB::transaction(function () use ($voyageDTO) {
            $compagnieId = $this->getCompagnieUtilisateur();

            // Valider le DTO
            $voyageDTO->validate();

            // Vérifier que le trajet appartient à la compagnie
            $trajet =  Trajet::where('comp_id', $compagnieId)
                ->findOrFail($voyageDTO->trajetId);

            // Vérifier que la voiture appartient à la compagnie et est active
            $voiture = Voiture::where('comp_id', $compagnieId)
                ->where('voit_statut', 1)
                ->findOrFail($voyageDTO->voitId);

            // Vérifier que la voiture est disponible pour cette date
            if (!$voiture->estDisponiblePourDate($voyageDTO->voyageDate)) {
                throw new \Exception('Cette voiture n\'est pas disponible pour la date sélectionnée');
            }

            // Vérifier que le nombre de places ne dépasse pas la capacité du véhicule
            if ($voyageDTO->placesDisponibles > $voiture->voit_places) {
                throw new \Exception('Le nombre de places disponibles ne peut pas dépasser la capacité du véhicule');
            }

            $voyage = Voyage::create([
                'voyage_date' => $voyageDTO->voyageDate,
                'voyage_heure_depart' => $voyageDTO->voyageHeureDepart,
                'voyage_type' => $voyageDTO->voyageType,
                'traj_id' => $voyageDTO->trajId,
                'voit_id' => $voyageDTO->voitId,
                'voyage_statut' => 1, // Programmé
                'places_disponibles' => $voyageDTO->placesDisponibles,
                'places_reservees' => 0
            ]);

            return $this->formaterVoyageComplet($voyage);
        });
    }

    /**
     * Récupère la liste des voyages de la compagnie
     */
    public function listerVoyages(array $filtres = []): array 
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        $query = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture'])
            ->whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            });
        
        // Filtrage par date
        if (isset($filtres['date_debut'])) {
            $query->where('voyage_date', '>=', $filtres['date_debut']);
        }

        if (isset($filtres['date_fin'])) {
            $query->where('voyage_date', '<=', $filtres['date_fin']);
        }

        // Filtrage par statut
        if (isset($filtres['statut'])) {
            $query->where('voyage_statut', $filtres['statut']);
        }

        // Filtrage par trajet
        if (isset($filtres['traj_id'])) {
            $query->where('traj_id', $filtres['traj_id']);
        }

        // Tri par défaut : date de voyage
        $sortField = $filtres['sort_field'] ?? 'voyage_date';
        $sortDirection = $filtres['sort_direction'] ?? 'asc';
        $query->orderBy($sortField, $sortDirection);

        $voyages = $query->paginate($filtres['per_page'] ?? 15);

        return [
            'voyages' => $voyages->map(function($voyage) {
                return $this->formaterVoyageComplet($voyage);
            }),
            'pagination' => [
                'total' => $voyages->total(),
                'per_page' => $voyages->perPage(),
                'current_page' => $voyages->currentPage(),
                'last_page' => $voyages->lastPage()
            ]
        ];

    }


    /**
     * Récupère un voyage spécifique de la compagnie
     */
    public function getVoyage(int $voyageId): array 
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        $voyage =  Voyage::with([
            'trajet.provinceDepart', 
            'trajet.provinceArrivee', 
            'voiture',
            'reservations.utilisateur'
        ])
        ->whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        })
        ->findOrFail($voyageId);

        return $this->formaterVoyageDetaille($voyage);
    }


    /**
     * Met à jour un voyage
     */
    public function mettreAjourVoyage(int $voyageId, VoyageDTO $voyageDTO): array 
    {
        return DB::transaction(function () use ($voyageId, $voyageDTO) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->findOrFail($voyageId);

            // Valider le DTO
            $voyageDTO->validate();

            // Vérifier que le nouveau trajet appartient à la compagnie
            if ($voyageDTO->tarjId != $voyage->traj_id) {
                $trajet = Trajet::where('comp_id', $compagnieId)
                    ->findOrFail($voyageDTO->trajId);
            }

            // Vérifier que la nouvelle voiture appartient à la compagnie
            if ($voyageDTO->voitId != $voyage->voit_id) {
                $voiture = Voiture::where('comp_id', $compagnieId)
                    ->where('voit_statut', 1)
                    ->findOrFail($voyageDTO->voitId);

                // Vérifier disponibilité de la voiture
                if (!$voiture->estDisponiblePourDate($voyageDTO->voyageDate)) {
                    throw new \Exception('La nouvelle voiture n\'est pas disponible pour cette date');
                }
            }

            // Vérifier que le nouveau nombre de places est suffisant pour les réservations existantes
            if ($voyageDTO->placesDisponibles < $voyage->places_reservees) {
                throw new \Exception('Le nombre de places disponibles ne peut pas être inférieur aux places déjà réservées');
            }

            $voyage->update([
                'voyage_date' => $voyageDTO->voyageDate,
                'voyage_heure_depart' => $voyageDTO->voyageHeureDepart,
                'voyage_type' => $voyageDTO->voyageType,
                'traj_id' => $voyageDTO->trajId,
                'voit_id' => $voyageDTO->voitId,
                'places_disponibles' => $voyageDTO->placesDisponibles
            ]);

        });
    }


    /**
     * Annule un voyage
     */
    public function annulerVoyage(int $voyageId): array
    {
        return DB::transaction(function () use ($voyageId) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->findOrFail($voyageId);

            // Vérifier qu'il n'y a pas de réservations
            if ($voyage->places_reservees > 0) {
                throw new \Exception('Impossible d\'annuler un voyage avec des réservations existantes');
            }

            $voyage->update(['voyage_statut' => 4]); // Annulé

            return $this->formaterVoyageComplet($voyage);
        });
    }


    /**
     * Récupère les statistiques des voyages
     */
    public function getStatistiques(): array 
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        $totalVoyages = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        })->count();

        $voyagesProgrammes = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        })->where('voyage_statut', 1)->count();

        $voyagesComplets = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        })->whereRaw('places_reservees >= places_disponibles')->count();

        // Taux de remplissage moyen
        $tauxRemplissage = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        })->where('voyage_date', '<', now()->toDateString())
          ->avg(DB::raw('(places_reservees / places_disponibles) * 100'));

        return [
            'total_voyages' => $totalVoyages,
            'voyages_programmes' => $voyagesProgrammes,
            'voyages_complets' => $voyagesComplets,
            'taux_remplissage_moyen' => round($tauxRemplissage ?: 0, 2)
        ];
    }


    /**
     *  Formate les informations complètes d'un voyage
     */
    private function formaterVoyageComplet(Voyage $voyage): array
    {
        return [
            'id' => $voyage->voyage_id,
            'date' => $voyage->voyage_date->format('Y-m-d'),
            'heure_depart' => $voyage->voyage_heure_depart,
            'type' => $voyage->voyage_type,
            'statut' => $voyage->voyage_statut,
            'places_disponibles' => $voyage->places_disponibles,
            'places_reservees' => $voyage->places_reservees,
            'places_libres' => $voyage->getPlacesLibres(),
            'est_complet' => $voyage->estComplet(),
            'trajet' => [
                'id' => $voyage->trajet->traj_id,
                'nom' => $voyage->trajet->traj_nom,
                'tarif' => (float) $voyage->trajet->traj_tarif,
                'province_depart' => $voyage->trajet->provinceDepart->pro_nom,
                'province_arrivee' => $voyage->trajet->provinceArrivee->pro_nom
            ],
            'voiture' => [
                'id' => $voyage->voiture->voit_id,
                'matricule' => $voyage->voiture->voit_matricule,
                'marque' => $voyage->voiture->voit_marque,
                'modele' => $voyage->voiture->voit_modele,
                'capacite' => $voyage->voiture->voit_places
            ]
        ];
    }

    /**
     * Formate les informations détaillées d'un voyage
     */
    private function formaterVoyageDetaille(Voyage $voyage): array
    {
        $formatted = $this->formaterVoyageComplet($voyage);

        // Ajouter les reservations
        $formatted['reservations'] = $voyage->reservations->map(function($reservation) {
            return [
                'id_reservation' => $reservation->res_id,
                'numero' => $reservation->res_numero,
                'statut' => $reservation->res_statut,
                'nombre_voyageurs' => $reservation->nb_voyageurs,
                'montant_total' => (float) $reservation->montant_total,
                'client' => [
                    'nom' => $reservation->utilisateur->util_nom,
                    'prenom' => $reservation->utilisateur->util_prenom,
                    'telephone' => $reservation->utilisateur->util_phone
                ]
            ];
        });

        return $formatted;
    }
}