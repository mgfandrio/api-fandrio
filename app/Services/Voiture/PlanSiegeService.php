<?php

namespace App\Services\Voiture;

use App\DTOs\PlanSiegeDTO;
use App\Models\Voitures\PlanSiege;
use App\Models\Voitures\Voitures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PlanSiegeService
{
    private const CACHE_DURATION = 60;
    private const CACHE_KEY_PREFIX = 'plan_siege_';

    /**
     * Crée un nouveau plan de sièges
     */
    public function creerPlan(PlanSiegeDTO $planSiegeDTO): array
    {
        return DB::transaction(function () use ($planSiegeDTO) {
            // Valider la structure
            $planSiegeDTO->validerConfigSieges();

            // Vérifier que la voiture existe et appartient à la compagnie
            $voiture = $this->verifierVoitureAppartientCompagnie($planSiegeDTO->voitId);

            // Vérifier si un plan existe déjà pour cette voiture
            $planExistant = PlanSiege::where('voit_id', $planSiegeDTO->voitId)->first();
            if ($planExistant) {
                throw new \Exception('Un plan de sièges existe déjà pour cette voiture. Veuillez le modifier ou le supprimer.');
            }

            // Créer le plan
            $donnees = $planSiegeDTO->convertionDonneesEnTableau();
            $plan = PlanSiege::create($donnees);

            // Invalider le cache
            $this->invaliderCacheVoiture($planSiegeDTO->voitId);

            return $this->formaterPlan($plan);
        });
    }

    /**
     * Met à jour un plan de sièges existant
     */
    public function mettreAJourPlan(int $planId, PlanSiegeDTO $planSiegeDTO): array
    {
        return DB::transaction(function () use ($planId, $planSiegeDTO) {
            $plan = PlanSiege::findOrFail($planId);

            // Vérifier que la voiture appartient à la compagnie
            $this->verifierVoitureAppartientCompagnie($plan->voit_id);

            // Valider la nouvelle configuration si elle est fournie
            if (!empty($planSiegeDTO->configSieges)) {
                $planSiegeDTO->validerConfigSieges();
            }

            // Préparer les données à mettre à jour
            $donneesUpdate = [];
            if ($planSiegeDTO->planNom) {
                $donneesUpdate['plan_nom'] = $planSiegeDTO->planNom;
            }
            if (!empty($planSiegeDTO->configSieges)) {
                $donneesUpdate['config_sieges'] = $planSiegeDTO->configSieges;
            }
            if ($planSiegeDTO->planStatut) {
                $donneesUpdate['plan_statut'] = $planSiegeDTO->planStatut;
            }

            $plan->update($donneesUpdate);

            // Invalider le cache
            $this->invaliderCacheVoiture($plan->voit_id);

            return $this->formaterPlan($plan);
        });
    }

    /**
     * Récupère un plan de sièges
     */
    public function obtenirPlan(int $planId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $planId;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $plan = PlanSiege::findOrFail($planId);

        // Vérifier que la voiture appartient à la compagnie
        $this->verifierVoitureAppartientCompagnie($plan->voit_id);

        $formatte = $this->formaterPlan($plan);
        Cache::put($cacheKey, $formatte, self::CACHE_DURATION);

        return $formatte;
    }

    /**
     * Récupère tous les plans d'une compagnie
     */
    public function obtenirPlansCompagnie(array $filtres = []): array
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        $query = PlanSiege::whereHas('voiture', function ($q) use ($compagnieId) {
            $q->where('comp_id', $compagnieId);
        });

        // Filtrer par statut
        if (isset($filtres['statut'])) {
            $query->where('plan_statut', $filtres['statut']);
        }

        // Filtrer par voiture
        if (isset($filtres['voit_id'])) {
            $query->where('voit_id', $filtres['voit_id']);
        }

        $plans = $query->with('voiture')->paginate($filtres['per_page'] ?? 15);

        return [
            'plans' => $plans->map(function ($plan) {
                return $this->formaterPlan($plan);
            }),
            'pagination' => [
                'total' => $plans->total(),
                'per_page' => $plans->perPage(),
                'current_page' => $plans->currentPage(),
                'last_page' => $plans->lastPage()
            ]
        ];
    }

    /**
     * Récupère le plan d'une voiture spécifique
     */
    public function obtenirPlanParVoiture(int $voitureId): array
    {
        $voiture = $this->verifierVoitureAppartientCompagnie($voitureId);

        $plan = PlanSiege::where('voit_id', $voitureId)->first();

        if (!$plan) {
            throw new \Exception('Aucun plan de sièges configuré pour cette voiture');
        }

        return $this->formaterPlan($plan);
    }

    /**
     * Supprime un plan de sièges
     */
    public function supprimerPlan(int $planId): array
    {
        return DB::transaction(function () use ($planId) {
            $plan = PlanSiege::findOrFail($planId);

            // Vérifier que la voiture appartient à la compagnie
            $this->verifierVoitureAppartientCompagnie($plan->voit_id);

            // Vérifier qu'il n'y a pas de voyages associés
            $voyagesAssocies = DB::table('fandrio_app.voyages')
                ->where('voit_id', $plan->voit_id)
                ->where('voyage_statut', 1)
                ->count();

            if ($voyagesAssocies > 0) {
                throw new \Exception('Impossible de supprimer ce plan: il existe des voyages programmés avec ce véhicule');
            }

            $voitureId = $plan->voit_id;
            $plan->delete();

            // Invalider le cache
            $this->invaliderCacheVoiture($voitureId);

            return [
                'success' => true,
                'message' => 'Plan de sièges supprimé avec succès'
            ];
        });
    }

    /**
     * Obtient les statistiques d'un plan
     */
    public function obtenirStatistiquesPlan(int $planId): array
    {
        $plan = PlanSiege::findOrFail($planId);

        // Vérifier que la voiture appartient à la compagnie
        $this->verifierVoitureAppartientCompagnie($plan->voit_id);

        $totalSieges = $plan->getNombreTotalSieges();
        $siegesParses = $plan->getSiegesParses();

        // Compter par type
        $compteurTypes = [];
        foreach ($siegesParses as $siege) {
            $type = $siege['type'];
            $compteurTypes[$type] = ($compteurTypes[$type] ?? 0) + 1;
        }

        return [
            'plan_id' => $plan->plan_id,
            'plan_nom' => $plan->plan_nom,
            'total_sieges' => $totalSieges,
            'types_sieges' => $compteurTypes,
            'nombre_rangees' => count($plan->config_sieges['rangees'] ?? []),
            'statut' => $plan->plan_statut
        ];
    }

    /**
     * Formate les informations d'un plan
     */
    private function formaterPlan(PlanSiege $plan): array
    {
        return [
            'id' => $plan->plan_id,
            'nom' => $plan->plan_nom,
            'voiture' => [
                'id' => $plan->voiture->voit_id,
                'matricule' => $plan->voiture->voit_matricule,
                'marque' => $plan->voiture->voit_marque,
                'modele' => $plan->voiture->voit_modele
            ],
            'config_sieges' => $plan->config_sieges,
            'nombre_total_sieges' => $plan->getNombreTotalSieges(),
            'statut' => $plan->plan_statut,
            'date_creation' => $plan->created_at->format('Y-m-d H:i:s'),
            'date_modification' => $plan->updated_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Vérifie que la voiture appartient à la compagnie de l'utilisateur
     */
    private function verifierVoitureAppartientCompagnie(int $voitureId): Voitures
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        $voiture = Voitures::where('voit_id', $voitureId)
            ->where('comp_id', $compagnieId)
            ->first();

        if (!$voiture) {
            throw new \Exception('Voiture non trouvée ou non autorisée');
        }

        return $voiture;
    }

    /**
     * Obtient la compagnie de l'utilisateur authentifié
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
     * Invalide le cache pour une voiture
     */
    private function invaliderCacheVoiture(int $voitureId): void
    {
        $plansVoiture = PlanSiege::where('voit_id', $voitureId)->get();
        foreach ($plansVoiture as $plan) {
            Cache::forget(self::CACHE_KEY_PREFIX . $plan->plan_id);
        }
    }
}
