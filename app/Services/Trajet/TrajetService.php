<?php

namespace App\Services\Trajet;

use App\Models\Trajet\Trajet;
use App\Models\Compagnies\Compagnie;
use App\DTOs\TrajetDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TrajetService
{
    /**
     *  Récupérer la compagnie de l'utilisateur authentifié
     */
    private function getCompagnieUtilisateur(): Compagnie
    {
        $utilisateur = Auth::user();

        if (!$utilisateur || !$utilisateur->comp_id) {
            throw new \Exception('Utilisateur non associé à une compagnie');
        }

        $compagnie = Compagnie::find($utilisateur->comp_id);

        if (!$compagnie) {
            throw new \Exception('Compagnie non trouvée');
        }

        return $compagnie;
    }

    /**
     * Créer un nouveau trajet pour la compagnie
     */
    public function creerTrajet(TrajetDTO $trajetDTO): array
    {
        return DB::transaction(function () use ($trajetDTO) {
            $compagnie = $this->getCompagnieUtilisateur();

            // Valider le DTO
            $trajetDTO->validate();

            // Vérifier si un trajet similaire existe déjà pour la compagnie
            if ($this->trajetExiste($compagnie->comp_id, $trajetDTO->proDepart, $trajetDTO->proArrivee )) {
                throw new \Exception('Un trajet avec le même départ et arrivée existe déjà pour votre compagnie');
            }

            $trajet = Trajet::create([
                'traj_nom'  => $trajetDTO->trajNom,
                'pro_depart'=> $trajetDTO->proDepart,
                'pro_arrivee'=> $trajetDTO->proArrivee,
                'traj_tarif' => $trajetDTO->trajTarif,
                'traj_km'    => $trajetDTO->trajKm,
                'traj_duree'  => $trajetDTO->trajDuree,
                'comp_id'    => $compagnie->comp_id,
                'traj_statut' => 1 // Actif par défaut
            ]);

            return $this->formaterTrajet($trajet);
        });
    }


    /**
     *  Récupère la liste des trajets de la compagnie
     */
    public function listerTrajets(array $filtres = []): array 
    {
        $compagnie = $this->getCompagnieUtilisateur();

        $query = Trajet::with(['provinceDepart', 'provinceArrivee', 'voyages'])
            ->where('comp_id', $compagnie->comp_id);
        
        // Filtrage par statut
        if (isset($filtres['statut'])) {
            $query->where('traj_statut', $filtres['statut']);
        }

        // Filtrage par province de départ
        if (isset($filtres['pro_depart'])) {
            $query->where('pro_depart', $filtres['pro_depart']);
        }

        // Filtrage par province d'arrivée
        if (isset($filtres['pro_arrivee'])) {
            $query->where('pro_arrivee', $filtres['pro_arrivee']);
        }

        // Tri
        $sortField = $filtres['sort_field'] ?? 'created_at';
        $sortDirection = $filtres['sort_direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $trajets = $query->paginate($filtres['per_page'] ?? 15);

        return [
            'trajets' => $trajets->map(function($trajet) {
                return $this->formaterTrajetComplet($trajet);
            }),
            'pagination' => [
                'total' => $trajets->total(),
                'per_page' => $trajets->perPage(),
                'current_page' => $trajets->currentPage(),
                'last_page' => $trajets->lastPage()
            ]
        ];
    }

    /**
     * Récupère un trajet spécifique de la compagnie
     */
    public function getTrajet(int $trajetId): array
    {
        $compagnie = $this->getCompagnieUtilisateur();

        $trajet = Trajet::with(['provinceDepart', 'provinceArrivee', 'voyages.voiture'])
            ->where('comp_id', $compagnie->comp_id)
            ->findOrFail($trajetId);

        return $this->formaterTrajetComplet($trajet);
    }


    /**
     * Met à jour un trajet
     */
    public function mettreAJourTrajet(int $trajetId, TrajetDTO $trajetDTO): array
    {
        return DB::transaction(function () use ($trajetId, $trajetDTO) {
            
            $compagnie = $this->getCompagnieUtilisateur();

            $trajet = Trajet::where('comp_id', $compagnie->comp_id)
                ->findOrFail($trajetId);

            // Valider le DTO
            $trajetDTO->validate();

            // Vérifier les doublons (exclure le trajet actuel)
            if ($this->trajetExiste($compagnie->comp_id, $trajetDTO->proDepart, $trajetDTO->proArrivee, $trajetId)) {
                throw new \Exception('Un autre trajet avec le même départ et arrivée existe déjà');
            }

            $trajet->update([
                'traj_nom'  => $trajetDTO->trajNom,
                'pro_depart'=> $trajetDTO->proDepart,
                'pro_arrivee'=> $trajetDTO->proArrivee,
                'traj_tarif' => $trajetDTO->trajTarif,
                'traj_km'    => $trajetDTO->trajKm,
                'traj_duree'  => $trajetDTO->trajDuree,
            ]);

            return $this->formaterTrajet($trajet->fresh());
        });
    }


    /**
     * Active / désactive un trajet
     */
    public function changerStatutTrajet(int $trajetId, int $statut): array
    {
        $compagnie = $this->getCompagnieUtilisateur();

        $trajet = Trajet::where('comp_id', $compagnie->comp_id)
            ->findOrFail($trajetId);
        
        if (!in_array($statut, [1, 2])) {
            throw new \Exception('Statut invalide. Utilisez 1 pour actif et 2 pour inactif.');
        }

        $trajet->update(['traj_statut' => $statut]);

        return $this->formaterTrajet($trajet);
    }


    /**
     * Récupère les statistiques des trajets
     */
    public function getStatistiques(): array 
    {
        $compagnie = $this->getCompagnieUtilisateur();

        $totalTrajets = Trajet::where('comp_id', $compagnie->comp_id)->count();
        $trajetsActifs = Trajet::where('comp_id', $compagnie->comp_id)->where('traj_statut', 1)->count();
        $trajetsInactifs = Trajet::where('comp_id', $compagnie->comp_id)->where('traj_statut', 2)->count();

        // Trajets avec le plus de voyages
        $trajetsPopulaires = Trajet::where('comp_id', $compagnie->comp_id)
            ->withCount('voyages')
            ->orderBy('voyages_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function($trajet) {
                return [
                    'id_trajet' => $trajet->traj_id,
                    'nom_trajet' => $trajet->traj_nom,
                    'nombre_voyages' => $trajet->voyages_count
                ];
            });
        
        return [
            'total_trajets' => $totalTrajets,
            'trajets_actifs' => $trajetsActifs,
            'trajets_inactifs' => $trajetsInactifs,
            'trajets_populaires' => $trajetsPopulaires->toArray()
        ];
    }


    /**
     *  Vérifie si un trajet existe déjà
     */
    private function trajetExiste(int $compagnieId, int $proDepart, int $proArrivee, ?int $excludeId = null): bool 
    {
        $query = Trajet::where('comp_id', $compagnieId)
            ->where('pro_depart', $proDepart)
            ->where('pro_arrivee', $proArrivee);

        if ($excludeId) {
            $query->where('traj_id', '!=', $excludeId);
        }

        return $query->exists();
    }


    /**
     * Formate les informations d'un trajet
     */
    private function formaterTrajet(Trajet $trajet): array 
    {
        return [
            'id_trajet' => $trajet->traj_id,
            'nom_trajet' => $trajet->traj_nom,
            'tarif' => $trajet->traj_tarif,
            'distance_km' => $trajet->traj_km,
            'duree' => $trajet->traj_duree,
            'duree_formatee' => $trajet->getDureeFormatee(),
            'statut' => $trajet->traj_statut,
            'date_creation' => $trajet->created_at->format('Y-m-d H:i:s')
        ];
    }


    /**
     * Formate les informations complètes d'un trajet
     */
    private function formaterTrajetComplet(Trajet $trajet): array
    {
        $formatted = $this->formaterTrajet($trajet);

        $formatted['province_depart'] = [
            'id_province' => $trajet->provinceDepart->pro_id,
            'nom_province' => $trajet->provinceDepart->pro_nom,
            'orientation' => $trajet->provinceDepart->pro_orientation
        ];

        $formatted['province_arrivee'] = [
            'id_province' => $trajet->provinceArrivee->pro_id,
            'nom_province' => $trajet->provinceArrivee->pro_nom,
            'orientation' => $trajet->provinceArrivee->pro_orientation
        ];

        // Statistiques des voyages
        $voygesFuturs = $trajet->voyages->where('voyage_date', '>=', now()->toDateString())->count();
        $voygesPasses = $trajet->voyages->where('voyage_date', '<', now()->toDateString())->count();

        $formatted['statistiques'] = [
            'total_voyages' => $trajet->voyages->count(),
            'voyages_futurs' => $voygesFuturs,
            'voyages_passes' => $voygesPasses
        ];

        return $formatted;

    }

}