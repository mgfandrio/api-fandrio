<?php

namespace App\Services\Admin;

use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class UtilisateurService
{
    /**
     * Récupère la liste des utilisateurs clients avec pagination et filtres
     */
    public function listerUtilisateurs(array $filtres = []): array
    {
        $query = Utilisateur::where('util_role', 1); // Seulement les clients

        // Filtrage par statut
        if (isset($filtres['statut'])) {
            $query->where('util_statut', $filtres['statut']);
        }

        // Filtrage par recherche
        if (isset($filtres['recherche'])) {
            $recherche = $filtres['recherche'];
            $query->where(function($q) use ($recherche) {
                $q->where('util_nom', 'ILIKE', "%{$recherche}%")
                  ->orWhere('util_prenom', 'ILIKE', "%{$recherche}%")
                  ->orWhere('util_email', 'ILIKE', "%{$recherche}%")
                  ->orWhere('util_phone', 'ILIKE', "%{$recherche}%");
            });
        }

        // Tri
        $sortField = $filtres['sort_field'] ?? 'created_at';
        $sortDirection = $filtres['sort_direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        $utilisateurs = $query->paginate($filtres['per_page'] ?? 15);

        return [
            'utilisateurs' => $utilisateurs->map(function($utilisateur) {
                return $this->formaterUtilisateur($utilisateur);
            }),
            'pagination' => [
                'total' => $utilisateurs->total(),
                'per_page' => $utilisateurs->perPage(),
                'current_page' => $utilisateurs->currentPage(),
                'last_page' => $utilisateurs->lastPage()
            ]
        ];
    }

    /**
     * Récupère les statistiques des utilisateurs
     */
    public function getStatistiques(): array
    {
        $total = Utilisateur::where('util_role', 1)->count();
        $actifs = Utilisateur::where('util_role', 1)->where('util_statut', 1)->count();
        $inactifs = Utilisateur::where('util_role', 1)->where('util_statut', 2)->count();
        $supprimes = Utilisateur::where('util_role', 1)->where('util_statut', 3)->count();

        // Utilisateurs créés ce mois-ci
        $ceMois = Utilisateur::where('util_role', 1)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return [
            'total' => $total,
            'actifs' => $actifs,
            'inactifs' => $inactifs,
            'supprimes' => $supprimes,
            'nouveaux_ce_mois' => $ceMois
        ];
    }

    /**
     * Récupère un utilisateur par son ID
     */
    public function getUtilisateur(int $utilisateurId): array
    {
        $utilisateur = Utilisateur::with(['voyageurs', 'reservations'])
            ->where('util_role', 1)
            ->findOrFail($utilisateurId);

        return $this->formaterUtilisateurDetaille($utilisateur);
    }

    /**
     * Active un utilisateur
     */
    public function activerUtilisateur(int $utilisateurId): array
    {
        $utilisateur = Utilisateur::where('util_role', 1)->findOrFail($utilisateurId);
        
        $utilisateur->update(['util_statut' => 1]);

        return $this->formaterUtilisateur($utilisateur);
    }

    /**
     * Désactive un utilisateur
     */
    public function desactiverUtilisateur(int $utilisateurId): array
    {
        $utilisateur = Utilisateur::where('util_role', 1)->findOrFail($utilisateurId);
        
        $utilisateur->update(['util_statut' => 2]);

        return $this->formaterUtilisateur($utilisateur);
    }

    /**
     * Supprime un utilisateur (soft delete)
     */
    public function supprimerUtilisateur(int $utilisateurId): bool
    {
        $utilisateur = Utilisateur::where('util_role', 1)->findOrFail($utilisateurId);
        
        return $utilisateur->update(['util_statut' => 3]);
    }

    /**
     * Réactive un utilisateur supprimé
     */
    public function reactiverUtilisateur(int $utilisateurId): array
    {
        $utilisateur = Utilisateur::where('util_role', 1)->findOrFail($utilisateurId);
        
        $utilisateur->update(['util_statut' => 1]);

        return $this->formaterUtilisateur($utilisateur);
    }

    /**
     * Change le statut d'un utilisateur
     */
    public function changerStatutUtilisateur(int $utilisateurId, int $nouveauStatut): array
    {
        $utilisateur = Utilisateur::where('util_role', 1)->findOrFail($utilisateurId);

        if (!in_array($nouveauStatut, [1, 2, 3])) {
            throw new \Exception('Statut invalide. Utilisez 1 pour actif, 2 pour inactif ou 3 pour supprimé.');
        }

        $utilisateur->update(['util_statut' => $nouveauStatut]);

        return $this->formaterUtilisateur($utilisateur);
    }

    /**
     * Formate les informations d'un utilisateur
     */
    private function formaterUtilisateur(Utilisateur $utilisateur): array
    {
        return [
            'id' => $utilisateur->util_id,
            'nom' => $utilisateur->util_nom,
            'prenom' => $utilisateur->util_prenom,
            'email' => $utilisateur->util_email,
            'telephone' => $utilisateur->util_phone,
            'date_naissance' => $utilisateur->util_anniv?->format('Y-m-d'),
            'statut' => $utilisateur->util_statut,
            'photo' => $utilisateur->util_photo,
            'date_creation' => $utilisateur->created_at->format('Y-m-d H:i:s'),
            'date_modification' => $utilisateur->updated_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Formate les informations détaillées d'un utilisateur
     */
    private function formaterUtilisateurDetaille(Utilisateur $utilisateur): array
    {
        $formatted = $this->formaterUtilisateur($utilisateur);

        // Statistiques des réservations
        $reservations = $utilisateur->reservations;
        $reservationsTotal = $reservations->count();
        $reservationsConfirmees = $reservations->where('res_statut', 2)->count();
        $reservationsAnnulees = $reservations->where('res_statut', 4)->count();

        $formatted['statistiques'] = [
            'total_reservations' => $reservationsTotal,
            'reservations_confirmees' => $reservationsConfirmees,
            'reservations_annulees' => $reservationsAnnulees,
            'taux_confirmation' => $reservationsTotal > 0 ? 
                round(($reservationsConfirmees / $reservationsTotal) * 100, 2) : 0
        ];

        // Voyageurs associés
        $formatted['voyageurs_associes'] = $utilisateur->voyageurs->map(function($voyageur) {
            return [
                'id' => $voyageur->voya_id,
                'nom' => $voyageur->voya_nom,
                'prenom' => $voyageur->voya_prenom,
                'age' => $voyageur->voya_age,
                'cin' => $voyageur->voya_cin,
                'telephone' => $voyageur->voya_phone
            ];
        });

        // Dernières réservations
        $formatted['dernieres_reservations'] = $reservations->take(5)->map(function($reservation) {
            return [
                'id' => $reservation->res_id,
                'numero' => $reservation->res_numero,
                'statut' => $reservation->res_statut,
                'montant_total' => $reservation->montant_total,
                'date_reservation' => $reservation->res_date->format('Y-m-d H:i:s')
            ];
        });

        return $formatted;
    }
}