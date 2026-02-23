<?php

namespace App\DTOs;

class PlanSiegeDTO
{
    public function __construct(
        public string $planNom,
        public array $configSieges,
        public int $planStatut,
        public int $voitId
    ) {}

    /**
     * Créer le DTO à partir d'une requête
     */
    public static function fromRequest(array $donnees): self
    {
        return new self(
            planNom: $donnees['plan_nom'],
            configSieges: $donnees['config_sieges'],
            planStatut: $donnees['plan_statut'] ?? 1,
            voitId: $donnees['voit_id']
        );
    }

    /**
     * Créer le DTO pour modification
     */
    public static function fromRequestModification(array $donnees): self
    {
        return new self(
            planNom: $donnees['plan_nom'] ?? '',
            configSieges: $donnees['config_sieges'] ?? [],
            planStatut: $donnees['plan_statut'] ?? 1,
            voitId: $donnees['voit_id'] ?? 0
        );
    }

    /**
     * Valider les données du plan
     */
    public static function validationDonnees(): array
    {
        return [
            'plan_nom' => 'required|string|max:100',
            'config_sieges' => 'required|array',
            'config_sieges.rangees' => 'required|array|min:1',
            'config_sieges.rangees.*.lettre' => 'required|string|size:1|regex:/^[A-Z]$/',
            'config_sieges.rangees.*.sieges' => 'required|array|min:1',
            'config_sieges.rangees.*.sieges.*' => 'required|string|in:normal,couloir,fenetre,handicape',
            'plan_statut' => 'required|integer|in:1,2',
            'voit_id' => 'required|integer|exists:fandrio_app.voitures,voit_id'
        ];
    }

    /**
     * Convertir le DTO en tableau pour la base de données
     */
    public function convertionDonneesEnTableau(): array
    {
        return [
            'plan_nom' => $this->planNom,
            'config_sieges' => $this->configSieges,
            'plan_statut' => $this->planStatut,
            'voit_id' => $this->voitId
        ];
    }

    /**
     * Valider la structure de config_sieges
     */
    public function validerConfigSieges(): void
    {
        if (!isset($this->configSieges['rangees']) || !is_array($this->configSieges['rangees'])) {
            throw new \Exception('La configuration doit contenir des rangées');
        }

        if (empty($this->configSieges['rangees'])) {
            throw new \Exception('Au moins une rangée est requise');
        }

        $lettresUtilisees = [];
        $totalSieges = 0;

        foreach ($this->configSieges['rangees'] as $index => $rangee) {
            if (!isset($rangee['lettre']) || !isset($rangee['sieges'])) {
                throw new \Exception("Rangée {$index}: 'lettre' et 'sieges' sont obligatoires");
            }

            $lettre = $rangee['lettre'];

            // Vérifier les doublons de lettres
            if (in_array($lettre, $lettresUtilisees)) {
                throw new \Exception("La lettre de rangée '{$lettre}' est utilisée plusieurs fois");
            }
            $lettresUtilisees[] = $lettre;

            // Vérifier les sièges
            if (!is_array($rangee['sieges']) || empty($rangee['sieges'])) {
                throw new \Exception("Rangée {$lettre}: au moins un siège est requis");
            }

            foreach ($rangee['sieges'] as $numero => $type) {
                $typesValides = ['normal', 'couloir', 'fenetre', 'handicape'];
                if (!in_array($type, $typesValides)) {
                    throw new \Exception("Rangée {$lettre}, siège {$numero}: type de siège '{$type}' invalide");
                }
                $totalSieges++;
            }
        }

        if ($totalSieges === 0) {
            throw new \Exception('Au moins un siège est requis');
        }
    }
}
