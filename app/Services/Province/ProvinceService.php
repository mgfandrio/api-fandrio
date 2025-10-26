<?php

namespace App\Services\Province;

use App\Models\Provinces\Province;
use App\DTOs\ProvinceDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ProvinceService
{
    private const CACHE_DURATION = 3600; // 1 heure
    private const CACHE_KEY_ALL = 'provinces_all';
    private const CACHE_KEY_STATS = 'provinces_statistiques';

    /**
     * Crée une nouvelle province
     */
    public function creerProvince(ProvinceDTO $provinceDTO): array
    {
        return DB::transaction(function () use ($provinceDTO) {
            // Vérifier si la province existe déjà
            if ($this->provinceExiste($provinceDTO->proNom)) {
                throw new \Exception('Une province avec ce nom existe déjà');
            }

            // Valider l'orientation
            if (!$provinceDTO->estOrientationValide()) {
                throw new \Exception('Orientation invalide. Valeurs acceptées: Nord, Sud, Est, Ouest, Centre');
            }

            $province = Province::create([
                'pro_nom' => $provinceDTO->proNom,
                'pro_orientation' => $provinceDTO->proOrientation
            ]);

            // Invalider le cache
            $this->invaliderCache();

            return $this->formaterProvince($province);
        });
    }

    /**
     * Crée plusieurs provinces en lot
     */
    public function creerProvincesEnLot(array $provincesData): array
    {
        return DB::transaction(function () use ($provincesData) {
            $provincesCreees = [];
            $erreurs = [];

            foreach ($provincesData as $index => $data) {
                try {
                    $provinceDTO = ProvinceDTO::fromRequest($data);
                    
                    // Vérifier si la province existe déjà
                    if ($this->provinceExiste($provinceDTO->proNom)) {
                        $erreurs[] = "Ligne {$index}: Une province avec le nom '{$provinceDTO->proNom}' existe déjà";
                        continue;
                    }

                    // Valider l'orientation
                    if (!$provinceDTO->estOrientationValide()) {
                        $erreurs[] = "Ligne {$index}: Orientation '{$provinceDTO->proOrientation}' invalide";
                        continue;
                    }

                    $province = Province::create([
                        'pro_nom' => $provinceDTO->proNom,
                        'pro_orientation' => $provinceDTO->proOrientation
                    ]);

                    $provincesCreees[] = $this->formaterProvince($province);

                } catch (\Exception $e) {
                    $erreurs[] = "Ligne {$index}: {$e->getMessage()}";
                }
            }

            // Invalider le cache seulement si des provinces ont été créées
            if (!empty($provincesCreees)) {
                $this->invaliderCache();
            }

            return [
                'provinces_creees' => $provincesCreees,
                'erreurs' => $erreurs,
                'total_creees' => count($provincesCreees),
                'total_erreurs' => count($erreurs)
            ];
        });
    }

    /**
     * Récupère la liste des provinces avec filtres
     */
    public function listerProvinces(array $filtres = []): array
    {
        $cacheKey = $this->genererCleCache($filtres);

        // Utiliser le cache pour les requêtes sans pagination
        if (empty($filtres) && Cache::has(self::CACHE_KEY_ALL)) {
            return Cache::get(self::CACHE_KEY_ALL);
        }

        $query = Province::query();

        // Filtrage par nom
        if (isset($filtres['pro_nom'])) {
            $query->where('pro_nom', 'ILIKE', "%{$filtres['pro_nom']}%");
        }

        // Filtrage par orientation
        if (isset($filtres['pro_orientation'])) {
            $query->where('pro_orientation', $filtres['pro_orientation']);
        }

        // Tri par défaut
        $query->orderBy('pro_nom');

        // Pagination ou récupération de tous les résultats
        if (isset($filtres['paginer']) && $filtres['paginer'] === false) {
            $provinces = $query->get();
            
            // Mettre en cache seulement si pas de filtres
            if (empty($filtres)) {
                Cache::put(self::CACHE_KEY_ALL, $provinces->toArray(), self::CACHE_DURATION);
            }
            
            return $provinces->toArray();
        }

        $provinces = $query->paginate($filtres['per_page'] ?? 15);

        return [
            'provinces' => $provinces->map(function($province) {
                return $this->formaterProvince($province);
            }),
            'pagination' => [
                'total' => $provinces->total(),
                'per_page' => $provinces->perPage(),
                'current_page' => $provinces->currentPage(),
                'last_page' => $provinces->lastPage()
            ]
        ];
    }

    /**
     * Récupère une province par son ID
     */
    public function getProvince(int $provinceId): array
    {
        $province = Province::findOrFail($provinceId);
        return $this->formaterProvinceDetaillee($province);
    }

    /**
     * Met à jour une province
     */
    public function mettreAJourProvince(int $provinceId, ProvinceDTO $provinceDTO): array
    {
        return DB::transaction(function () use ($provinceId, $provinceDTO) {
            $province = Province::findOrFail($provinceId);

            // Vérifier les doublons (exclure la province actuelle)
            if ($this->provinceExiste($provinceDTO->proNom, $provinceId)) {
                throw new \Exception('Une autre province avec ce nom existe déjà');
            }

            // Valider l'orientation
            if (!$provinceDTO->estOrientationValide()) {
                throw new \Exception('Orientation invalide. Valeurs acceptées: Nord, Sud, Est, Ouest, Centre');
            }

            $province->update([
                'pro_nom' => $provinceDTO->proNom,
                'pro_orientation' => $provinceDTO->proOrientation
            ]);

            // Invalider le cache
            $this->invaliderCache();

            return $this->formaterProvince($province);
        });
    }

    /**
     * Supprime une province
     */
    public function supprimerProvince(int $provinceId): bool
    {
        return DB::transaction(function () use ($provinceId) {
            $province = Province::findOrFail($provinceId);

            // Vérifier si la province est utilisée dans des trajets
            if ($this->provinceEstUtilisee($provinceId)) {
                throw new \Exception('Impossible de supprimer cette province car elle est utilisée dans des trajets');
            }

            $resultat = $province->delete();

            // Invalider le cache
            $this->invaliderCache();

            return $resultat;
        });
    }

    /**
     * Supprime plusieurs provinces en lot
     */
    public function supprimerProvincesEnLot(array $provinceIds): array
    {
        return DB::transaction(function () use ($provinceIds) {
            $suppressionsReussies = [];
            $erreurs = [];

            foreach ($provinceIds as $provinceId) {
                try {
                    $province = Province::find($provinceId);

                    if (!$province) {
                        $erreurs[] = "Province ID {$provinceId} non trouvée";
                        continue;
                    }

                    // Vérifier si la province est utilisée
                    if ($this->provinceEstUtilisee($provinceId)) {
                        $erreurs[] = "Province '{$province->pro_nom}' est utilisée dans des trajets";
                        continue;
                    }

                    $province->delete();
                    $suppressionsReussies[] = $provinceId;

                } catch (\Exception $e) {
                    $erreurs[] = "Province ID {$provinceId}: {$e->getMessage()}";
                }
            }

            // Invalider le cache seulement si des suppressions ont réussi
            if (!empty($suppressionsReussies)) {
                $this->invaliderCache();
            }

            return [
                'suppressions_reussies' => $suppressionsReussies,
                'erreurs' => $erreurs,
                'total_supprimees' => count($suppressionsReussies),
                'total_erreurs' => count($erreurs)
            ];
        });
    }

    /**
     * Récupère les statistiques des provinces
     */
    public function getStatistiques(): array
    {
        if (Cache::has(self::CACHE_KEY_STATS)) {
            return Cache::get(self::CACHE_KEY_STATS);
        }

        $total = Province::count();
        
        $parOrientation = Province::select('pro_orientation', DB::raw('count(*) as total'))
            ->groupBy('pro_orientation')
            ->get()
            ->pluck('total', 'pro_orientation')
            ->toArray();

        $statistiques = [
            'total' => $total,
            'par_orientation' => $parOrientation
        ];

        Cache::put(self::CACHE_KEY_STATS, $statistiques, self::CACHE_DURATION);

        return $statistiques;
    }

    /**
     * Récupère les orientations disponibles
     */
    public function getOrientations(): array
    {
        return [
            'Nord', 'Sud', 'Est', 'Ouest', 'Centre'
        ];
    }

    /**
     * Vérifie si une province existe déjà
     */
    private function provinceExiste(string $nom, ?int $excludeId = null): bool
    {
        $query = Province::where('pro_nom', $nom);

        if ($excludeId) {
            $query->where('pro_id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Vérifie si une province est utilisée dans des trajets
     */
    private function provinceEstUtilisee(int $provinceId): bool
    {
        return DB::table('fandrio_app.trajets')
            ->where('pro_depart', $provinceId)
            ->orWhere('pro_arrivee', $provinceId)
            ->exists();
    }

    /**
     * Formate les informations d'une province
     */
    private function formaterProvince(Province $province): array
    {
        return [
            'id' => $province->pro_id,
            'nom' => $province->pro_nom,
            'orientation' => $province->pro_orientation,
            'date_creation' => $province->created_at->format('Y-m-d H:i:s'),
            'date_modification' => $province->updated_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Formate les informations détaillées d'une province
     */
    private function formaterProvinceDetaillee(Province $province): array
    {
        $formatted = $this->formaterProvince($province);

        // Statistiques d'utilisation
        $trajetsDepart = DB::table('fandrio_app.trajets')
            ->where('pro_depart', $province->pro_id)
            ->count();

        $trajetsArrivee = DB::table('fandrio_app.trajets')
            ->where('pro_arrivee', $province->pro_id)
            ->count();

        $compagniesDesservies = DB::table('fandrio_app.compagnie_provinces')
            ->where('pro_id', $province->pro_id)
            ->count();

        $formatted['statistiques'] = [
            'trajets_depart' => $trajetsDepart,
            'trajets_arrivee' => $trajetsArrivee,
            'compagnies_desservies' => $compagniesDesservies,
            'total_trajets' => $trajetsDepart + $trajetsArrivee
        ];

        return $formatted;
    }

    /**
     * Génère une clé de cache basée sur les filtres
     */
    private function genererCleCache(array $filtres): string
    {
        return 'provinces_' . md5(serialize($filtres));
    }

    /**
     * Invalide le cache des provinces
     */
    private function invaliderCache(): void
    {
        Cache::forget(self::CACHE_KEY_ALL);
        Cache::forget(self::CACHE_KEY_STATS);
        
        // Invalider également les caches avec filtres si nécessaire
        Cache::forget('provinces_nom_*');
        Cache::forget('provinces_orientation_*');
    }
}