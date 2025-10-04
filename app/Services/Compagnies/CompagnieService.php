<?php

namespace App\Services\Compagnies;

use App\Models\Compagnies\Compagnie;
use App\Models\Utilisateurs\Utilisateur;
use App\DTOs\CompagnieDTO;
use App\DTOs\AdminCompagnieDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompagnieService
{
    /**
     * Crée une nouvelle compagnie avec son administrateur
     */
    public function creerCompagnie(CompagnieDTO $compagnieDTO, AdminCompagnieDTO $adminDTO): array
    {
        return DB::transaction(function () use ($compagnieDTO, $adminDTO) {
            // Vérifier si la compagnie existe déjà (NIF ou email)
            if ($this->compagnieExiste($compagnieDTO->compNif, $compagnieDTO->compEmail)) {
                throw new \Exception('Une compagnie avec ce NIF ou email existe déjà');
            }

            // Vérifier si l'admin existe déjà
            if ($this->adminExiste($adminDTO->email, $adminDTO->telephone)) {
                throw new \Exception('Un administrateur avec cet email ou téléphone existe déjà');
            }

            // Créer la compagnie
            $compagnie = Compagnie::create([
                'comp_nom' => $compagnieDTO->compNom,
                'comp_nif' => $compagnieDTO->compNif,
                'comp_stat' => $compagnieDTO->compStat,
                'comp_description' => $compagnieDTO->compDescription,
                'comp_phone' => $compagnieDTO->compPhone,
                'comp_email' => $compagnieDTO->compEmail,
                'comp_adresse' => $compagnieDTO->compAdresse,
                'comp_statut' => 1 // Actif par défaut
            ]);

            // Créer l'administrateur de la compagnie
            $admin = $this->creerAdminCompagnie($adminDTO, $compagnie->comp_id);

            // Associer les provinces desservies
            if (!empty($compagnieDTO->provincesDesservies)) {
                $this->associerProvinces($compagnie->comp_id, $compagnieDTO->provincesDesservies);
            }

            // Associer les modes de paiement
            if (!empty($compagnieDTO->modesPaiement)) {
                $this->associerModesPaiement($compagnie->comp_id, $compagnieDTO->modesPaiement);
            }

            return [
                'compagnie' => $this->formaterCompagnie($compagnie),
                'admin' => $this->formaterAdmin($admin)
            ];
        });
    }

    /**
     * Récupère la liste des compagnies avec pagination
     */
    public function listerCompagnies(array $filtres = []): array
    {
        $query = Compagnie::with(['utilisateurs' => function($q) {
            $q->where('util_role', 2); // Seulement les admins compagnie
        }]);

        // Filtrage par statut
        if (isset($filtres['statut'])) {
            $query->where('comp_statut', $filtres['statut']);
        }

        // Filtrage par recherche
        if (isset($filtres['recherche'])) {
            $recherche = $filtres['recherche'];
            $query->where(function($q) use ($recherche) {
                $q->where('comp_nom', 'ILIKE', "%{$recherche}%")
                  ->orWhere('comp_email', 'ILIKE', "%{$recherche}%")
                  ->orWhere('comp_phone', 'ILIKE', "%{$recherche}%");
            });
        }

        $compagnies = $query->orderBy('comp_nom')
                           ->paginate($filtres['per_page'] ?? 15);

        return [
            'compagnies' => $compagnies->map(function($compagnie) {
                return $this->formaterCompagnie($compagnie);
            }),
            'pagination' => [
                'total' => $compagnies->total(),
                'per_page' => $compagnies->perPage(),
                'current_page' => $compagnies->currentPage(),
                'last_page' => $compagnies->lastPage()
            ]
        ];
    }

    /**
     * Récupère les statistiques des compagnies
     */
    public function getStatistiques(): array
    {
        $total = Compagnie::count();
        $actives = Compagnie::where('comp_statut', 1)->count();
        $inactives = Compagnie::where('comp_statut', 2)->count();
        $supprimees = Compagnie::where('comp_statut', 3)->count();

        return [
            'total' => $total,
            'actives' => $actives,
            'inactives' => $inactives,
            'supprimees' => $supprimees
        ];
    }

    /**
     * Récupère une compagnie par son ID
     */
    public function getCompagnie(int $compagnieId): array
    {
        $compagnie = Compagnie::with([
            'utilisateurs' => function($q) {
                $q->where('util_role', 2);
            },
            'provincesDesservies',
            'modesPaiement'
        ])->findOrFail($compagnieId);

        return $this->formaterCompagnieDetaillee($compagnie);
    }

    /**
     * Met à jour une compagnie
     */
    public function mettreAJourCompagnie(int $compagnieId, CompagnieDTO $compagnieDTO): array
    {
        return DB::transaction(function () use ($compagnieId, $compagnieDTO) {
            $compagnie = Compagnie::findOrFail($compagnieId);

            // Vérifier les doublons (exclure la compagnie actuelle)
            if ($this->compagnieExiste($compagnieDTO->compNif, $compagnieDTO->compEmail, $compagnieId)) {
                throw new \Exception('Une autre compagnie avec ce NIF ou email existe déjà');
            }

            $compagnie->update([
                'comp_nom' => $compagnieDTO->compNom,
                'comp_nif' => $compagnieDTO->compNif,
                'comp_stat' => $compagnieDTO->compStat,
                'comp_description' => $compagnieDTO->compDescription,
                'comp_phone' => $compagnieDTO->compPhone,
                'comp_email' => $compagnieDTO->compEmail,
                'comp_adresse' => $compagnieDTO->compAdresse,
            ]);

            // Mettre à jour les provinces desservies
            if (isset($compagnieDTO->provincesDesservies)) {
                $this->synchroniserProvinces($compagnieId, $compagnieDTO->provincesDesservies);
            }

            // Mettre à jour les modes de paiement
            if (isset($compagnieDTO->modesPaiement)) {
                $this->synchroniserModesPaiement($compagnieId, $compagnieDTO->modesPaiement);
            }

            return $this->formaterCompagnie($compagnie->fresh());
        });
    }

    /**
     * Active/désactive une compagnie
     */
    public function changerStatutCompagnie(int $compagnieId, int $nouveauStatut): array
    {
        $compagnie = Compagnie::findOrFail($compagnieId);

        if (!in_array($nouveauStatut, [1, 2])) {
            throw new \Exception('Statut invalide. Utilisez 1 pour actif ou 2 pour inactif.');
        }

        $compagnie->update(['comp_statut' => $nouveauStatut]);

        return $this->formaterCompagnie($compagnie);
    }

    /**
     * Supprime une compagnie (soft delete)
     */
    public function supprimerCompagnie(int $compagnieId): bool
    {
        $compagnie = Compagnie::findOrFail($compagnieId);
        
        return $compagnie->update(['comp_statut' => 3]); // Statut supprimé
    }

    /**
     * Crée un administrateur pour la compagnie
     */
    private function creerAdminCompagnie(AdminCompagnieDTO $adminDTO, int $compagnieId): Utilisateur
    {
        return Utilisateur::create([
            'util_nom' => $adminDTO->nom,
            'util_prenom' => $adminDTO->prenom,
            'util_email' => $adminDTO->email,
            'util_phone' => $adminDTO->telephone,
            'util_password' => Hash::make($adminDTO->motDePasse),
            'util_role' => 2, // Admin compagnie
            'comp_id' => $compagnieId,
            'util_statut' => 1 // Actif
        ]);
    }

    /**
     * Associe des provinces à une compagnie
     */
    private function associerProvinces(int $compagnieId, array $provincesIds): void
    {
        foreach ($provincesIds as $provinceId) {
            DB::table('fandrio_app.compagnie_provinces')->insert([
                'comp_id' => $compagnieId,
                'pro_id' => $provinceId,
                'comp_pro_statut' => 1,
                'created_at' => now()
            ]);
        }
    }

    /**
     * Associe des modes de paiement à une compagnie
     */
    private function associerModesPaiement(int $compagnieId, array $modesPaiementIds): void
    {
        foreach ($modesPaiementIds as $modePaiementId) {
            DB::table('fandrio_app.compagnie_paiements')->insert([
                'comp_id' => $compagnieId,
                'type_paie_id' => $modePaiementId,
                'comp_paie_statut' => 1,
                'created_at' => now()
            ]);
        }
    }

    /**
     * Synchronise les provinces desservies
     */
    private function synchroniserProvinces(int $compagnieId, array $nouvellesProvinces): void
    {
        DB::table('fandrio_app.compagnie_provinces')
            ->where('comp_id', $compagnieId)
            ->delete();

        $this->associerProvinces($compagnieId, $nouvellesProvinces);
    }

    /**
     * Synchronise les modes de paiement
     */
    private function synchroniserModesPaiement(int $compagnieId, array $nouveauxModesPaiement): void
    {
        DB::table('fandrio_app.compagnie_paiements')
            ->where('comp_id', $compagnieId)
            ->delete();

        $this->associerModesPaiement($compagnieId, $nouveauxModesPaiement);
    }

    /**
     * Vérifie si une compagnie existe déjà
     */
    private function compagnieExiste(string $nif, string $email, ?int $excludeId = null): bool
    {
        $query = Compagnie::where(function($q) use ($nif, $email) {
            $q->where('comp_nif', $nif)
              ->orWhere('comp_email', $email);
        });

        if ($excludeId) {
            $query->where('comp_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Vérifie si un admin existe déjà
     */
    private function adminExiste(string $email, string $telephone): bool
    {
        return Utilisateur::where('util_email', $email)
            ->orWhere('util_phone', $telephone)
            ->exists();
    }

    /**
     * Formate les informations d'une compagnie
     */
    private function formaterCompagnie(Compagnie $compagnie): array
    {
        return [
            'id' => $compagnie->comp_id,
            'nom' => $compagnie->comp_nom,
            'nif' => $compagnie->comp_nif,
            'stat' => $compagnie->comp_stat,
            'description' => $compagnie->comp_description,
            'telephone' => $compagnie->comp_phone,
            'email' => $compagnie->comp_email,
            'adresse' => $compagnie->comp_adresse,
            'statut' => $compagnie->comp_statut,
            'logo' => $compagnie->comp_logo,
            'date_creation' => $compagnie->created_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Formate les informations détaillées d'une compagnie
     */
    private function formaterCompagnieDetaillee(Compagnie $compagnie): array
    {
        $formatted = $this->formaterCompagnie($compagnie);

        $formatted['administrateurs'] = $compagnie->utilisateurs->map(function($admin) {
            return $this->formaterAdmin($admin);
        });

        $formatted['provinces_desservies'] = $compagnie->provincesDesservies->map(function($province) {
            return [
                'id' => $province->pro_id,
                'nom' => $province->pro_nom,
                'orientation' => $province->pro_orientation
            ];
        });

        $formatted['modes_paiement_acceptes'] = $compagnie->modesPaiement->map(function($modePaiement) {
            return [
                'id' => $modePaiement->type_paie_id,
                'nom' => $modePaiement->type_paie_nom,
                'type' => $modePaiement->type_paie_type
            ];
        });

        return $formatted;
    }

    /**
     * Formate les informations d'un administrateur
     */
    private function formaterAdmin(Utilisateur $admin): array
    {
        return [
            'id' => $admin->util_id,
            'nom' => $admin->util_nom,
            'prenom' => $admin->util_prenom,
            'email' => $admin->util_email,
            'telephone' => $admin->util_phone,
            'statut' => $admin->util_statut
        ];
    }
}