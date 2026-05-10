<?php

namespace App\Services\voyage;

use App\Models\Voyages\Voyage;
use App\Models\Trajet\Trajet;
use App\Models\Voitures\Voitures;
use App\Models\Reservation\Reservation;
use App\Services\Notification\NotificationService;
use App\DTOs\VoyageDTO;
use App\Helpers\DateFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;



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
                ->findOrFail($voyageDTO->trajId);

            // Vérifier que la voiture appartient à la compagnie et est active
            $voiture = Voitures::where('comp_id', $compagnieId)
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
     * Programmer plusieurs voyages en une seule opération.
     */
    public function programmerVoyagesMultiples(array $voyagesData): array
    {
        return DB::transaction(function () use ($voyagesData) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyagesCrees = [];
            $erreurs = [];

            // Pré-charger les trajets et voitures de la compagnie
            $trajetsCompagnie = Trajet::where('comp_id', $compagnieId)->pluck('traj_id')->toArray();
            $voituresCompagnie = Voitures::where('comp_id', $compagnieId)
                ->where('voit_statut', 1)
                ->get()
                ->keyBy('voit_id');

            foreach ($voyagesData as $index => $data) {
                $numero = $index + 1;

                try {
                    $voyageDTO = VoyageDTO::fromRequest($data);
                    $voyageDTO->validate();

                    // Vérifier que le trajet appartient à la compagnie
                    if (!in_array($voyageDTO->trajId, $trajetsCompagnie)) {
                        throw new \Exception("Le trajet sélectionné n'appartient pas à votre compagnie");
                    }

                    // Vérifier que la voiture appartient à la compagnie
                    $voiture = $voituresCompagnie->get($voyageDTO->voitId);
                    if (!$voiture) {
                        throw new \Exception("La voiture sélectionnée n'appartient pas à votre compagnie ou est inactive");
                    }

                    // Vérifier la disponibilité de la voiture
                    if (!$voiture->estDisponiblePourDate($voyageDTO->voyageDate)) {
                        throw new \Exception("La voiture n'est pas disponible pour la date {$voyageDTO->voyageDate}");
                    }

                    // Vérifier la capacité
                    if ($voyageDTO->placesDisponibles > $voiture->voit_places) {
                        throw new \Exception("Le nombre de places ({$voyageDTO->placesDisponibles}) dépasse la capacité du véhicule ({$voiture->voit_places})");
                    }

                    $voyage = Voyage::create([
                        'voyage_date' => $voyageDTO->voyageDate,
                        'voyage_heure_depart' => $voyageDTO->voyageHeureDepart,
                        'voyage_type' => $voyageDTO->voyageType,
                        'traj_id' => $voyageDTO->trajId,
                        'voit_id' => $voyageDTO->voitId,
                        'voyage_statut' => 1,
                        'places_disponibles' => $voyageDTO->placesDisponibles,
                        'places_reservees' => 0,
                    ]);

                    $voyagesCrees[] = $this->formaterVoyageComplet($voyage);
                } catch (\Exception $e) {
                    $erreurs[] = "Voyage #{$numero} : {$e->getMessage()}";
                }
            }

            if (empty($voyagesCrees) && !empty($erreurs)) {
                throw new \Exception("Aucun voyage n'a pu être créé. " . implode(' | ', $erreurs));
            }

            $total = count($voyagesData);
            $crees = count($voyagesCrees);
            $message = $crees === $total
                ? "{$crees} voyage(s) programmé(s) avec succès"
                : "{$crees}/{$total} voyage(s) programmé(s) avec succès";

            return [
                'voyages' => $voyagesCrees,
                'total_demande' => $total,
                'total_crees' => $crees,
                'erreurs' => $erreurs,
                'message' => $message,
            ];
        });
    }


    /**
     * Met à jour automatiquement les statuts des voyages d'une compagnie (mode "lazy",
     * appelé à chaque listage). Ne gère QUE les transitions Programmé (1) → En cours (2) :
     * - Toutes les places sont prises
     * - OU l'heure de départ est atteinte aujourd'hui
     *
     * IMPORTANT : Les transitions vers Terminé (3) et Annulé (4) sont gérées
     * EXCLUSIVEMENT par la commande planifiée `voyages:gestion-statuts`
     * (App\Console\Commands\GestionStatutVoyagesCommand) car elles nécessitent :
     *   - Application du seuil de réservations (≥ 5 = terminé, sinon annulé+remboursement)
     *   - Envoi de notifications à la compagnie
     *   - Logique de remboursement
     * Faire ces transitions ici dupliquerait la logique sans notifications/remboursement
     * et provoquerait des incohérences (voyages marqués Terminé alors qu'ils auraient dû
     * être Annulés faute de réservations suffisantes).
     */
    public static function autoCompleterVoyages(int $compagnieId): void
    {
        // 1. Programmé → En cours : places toutes réservées (complet)
        Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })
            ->where('voyage_statut', 1)
            ->whereRaw('places_reservees >= places_disponibles')
            ->update(['voyage_statut' => 2]);

        // 2. Programmé → En cours : heure de départ atteinte aujourd'hui
        Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })
            ->where('voyage_statut', 1)
            ->where('voyage_date', '=', now()->toDateString())
            ->where('voyage_heure_depart', '<=', now()->format('H:i:s'))
            ->update(['voyage_statut' => 2]);
    }

    /**
     * Récupère la liste des voyages de la compagnie
     */
    public function listerVoyages(array $filtres = []): array 
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        // Mise à jour automatique des statuts
        self::autoCompleterVoyages($compagnieId);

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
            if ($voyageDTO->trajId != $voyage->traj_id) {
                $trajet = Trajet::where('comp_id', $compagnieId)
                    ->findOrFail($voyageDTO->trajId);
            }

            // Vérifier que la nouvelle voiture appartient à la compagnie
            if ($voyageDTO->voitId != $voyage->voit_id) {
                $voiture = Voitures::where('comp_id', $compagnieId)
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

            return $this->formaterVoyageComplet($voyage);
        });
    }


    /**
     * Annule un voyage manuellement (par l'admin compagnie).
     *
     * Comportement (aligné sur le cron) :
     *  - Toutes les réservations actives (statut 1 ou 2) sont passées à statut 4 (annulée).
     *    → le trigger SQL décrémente automatiquement places_reservees.
     *  - Les sièges réservés sont remis à disponible (siege_statut = 2).
     *  - Le voyage est marqué statut=4 et is_active=false.
     *  - Les clients concernés sont notifiés (avec mention remboursement à venir).
     *  - Les admins de la compagnie sont notifiés.
     *
     * Conditions :
     *  - Le voyage ne doit pas déjà être annulé (statut 4) ni terminé (statut 3).
     */
    public function annulerVoyage(int $voyageId): array
    {
        return DB::transaction(function () use ($voyageId) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee'])
                ->whereHas('trajet', function($q) use ($compagnieId) {
                    $q->where('comp_id', $compagnieId);
                })
                ->findOrFail($voyageId);

            if ($voyage->voyage_statut === 4) {
                throw new \Exception('Ce voyage est déjà annulé');
            }
            if ($voyage->voyage_statut === 3) {
                throw new \Exception('Impossible d\'annuler un voyage terminé');
            }

            // 1. Récupérer les réservations actives avant changement (pour notifier les clients)
            $reservationsActives = Reservation::where('voyage_id', $voyageId)
                ->whereIn('res_statut', [1, 2])
                ->get();

            // 2. Annuler les réservations (le trigger SQL gère places_reservees)
            Reservation::where('voyage_id', $voyageId)
                ->whereIn('res_statut', [1, 2])
                ->update(['res_statut' => 4]);

            // 3. Libérer les sièges réservés (siege_statut = 2 disponible)
            DB::table('fandrio_app.sieges_reserves')
                ->where('voyage_id', $voyageId)
                ->whereIn('siege_statut', [1, 3])
                ->update([
                    'siege_statut' => 2,
                    'res_id' => null,
                    'utilisateur_id' => null,
                    'expire_lock' => null,
                ]);

            // 4. Annuler le voyage
            $voyage->update([
                'voyage_statut' => 4,
                'voyage_is_active' => false,
            ]);

            // 5. Notifications (best-effort)
            try {
                $depart = $voyage->trajet?->provinceDepart?->pro_nom ?? 'N/A';
                $arrivee = $voyage->trajet?->provinceArrivee?->pro_nom ?? 'N/A';
                $voyageInfo = "{$depart} → {$arrivee} le " . $voyage->voyage_date->format('d/m/Y');

                // Notifier les clients concernés
                foreach ($reservationsActives as $reservation) {
                    NotificationService::notifierClientVoyageAnnule(
                        (int) $reservation->util_id,
                        (int) $reservation->res_id,
                        $voyageInfo
                    );
                }

                // Notifier les admins de la compagnie (seulement s'il y avait des réservations)
                if ($reservationsActives->isNotEmpty()) {
                    NotificationService::notifierVoyageAnnule(
                        (int) $compagnieId,
                        $voyageInfo,
                        $reservationsActives->count()
                    );
                }
            } catch (\Exception $notifError) {
                Log::warning('Notifications annulation voyage failed: ' . $notifError->getMessage());
            }

            return $this->formaterVoyageComplet($voyage->fresh(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture']));
        });
    }

    /**
     * Réactive un voyage annulé (statut 4 → 1).
     *
     * Conditions strictes :
     *  - voyage_statut == 4
     *  - voyage_date >= aujourd'hui (pas de sens de réactiver un voyage passé)
     *  - La voiture est toujours disponible pour cette date (sinon utiliser reprogrammerVoyage)
     *
     * NOTE : les réservations annulées ne sont PAS automatiquement restaurées
     * (les clients ont été notifiés ; remboursements possiblement déjà émis).
     * Les clients devront refaire leur réservation.
     */
    public function reactiverVoyage(int $voyageId): array
    {
        return DB::transaction(function () use ($voyageId) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::with(['trajet', 'voiture'])
                ->whereHas('trajet', function($q) use ($compagnieId) {
                    $q->where('comp_id', $compagnieId);
                })
                ->findOrFail($voyageId);

            if ($voyage->voyage_statut !== 4) {
                throw new \Exception('Seuls les voyages annulés peuvent être réactivés');
            }

            if ($voyage->voyage_date->isPast() && !$voyage->voyage_date->isToday()) {
                throw new \Exception('Impossible de réactiver un voyage dont la date est passée. Utilisez la fonction \"reprogrammer\" pour créer un nouveau voyage à une nouvelle date.');
            }

            // Vérifier que la voiture est toujours dispo à cette date
            $voiture = Voitures::where('comp_id', $compagnieId)
                ->where('voit_id', $voyage->voit_id)
                ->where('voit_statut', 1)
                ->first();

            if (!$voiture) {
                throw new \Exception('La voiture associée à ce voyage n\'est plus active');
            }

            if (!$voiture->estDisponiblePourDate($voyage->voyage_date)) {
                throw new \Exception('La voiture n\'est plus disponible pour cette date. Utilisez la fonction \"reprogrammer\" pour choisir une autre voiture ou date.');
            }

            $voyage->update([
                'voyage_statut' => 1,
                'voyage_is_active' => true,
            ]);

            return $this->formaterVoyageComplet($voyage->fresh(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture']));
        });
    }

    /**
     * Bascule la visibilité du voyage pour les réservations clients (pause / reprise).
     *
     * Cas d'usage : voiture en maintenance courte, ajustement temporaire des informations,
     * etc. Le voyage existe toujours mais n'apparait plus en recherche client.
     *
     * Conditions :
     *  - voyage_statut IN (1, 2)  (impossible de modifier l'activation d'un voyage
     *    terminé ou annulé ; ces états forcent is_active=false par invariant)
     */
    public function togglerActivationVoyage(int $voyageId): array
    {
        return DB::transaction(function () use ($voyageId) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->findOrFail($voyageId);

            if (!in_array($voyage->voyage_statut, [1, 2], true)) {
                throw new \Exception('Seuls les voyages programmés ou en cours peuvent être mis en pause / réactivés. Pour un voyage annulé, utilisez \"réactiver\" ou \"reprogrammer\".');
            }

            $voyage->update([
                'voyage_is_active' => !$voyage->voyage_is_active,
            ]);

            return $this->formaterVoyageComplet($voyage->fresh(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture']));
        });
    }

    /**
     * Reprogramme un voyage existant en en créant un NOUVEAU à une date différente.
     *
     * Pattern « Cloner et reprogrammer » : permet de réutiliser le paramétrage
     * d'un voyage (trajet, voiture, type, places, heure) sans avoir à tout re-saisir,
     * tout en préservant l'historique du voyage source.
     *
     * Le voyage source est inchangé. Un nouveau voyage est créé (statut=1, is_active=true,
     * places_reservees=0).
     *
     * Paramètres requis :
     *  - voyage_date : nouvelle date (>= aujourd'hui)
     * Paramètres optionnels (sinon copiés du voyage source) :
     *  - voyage_heure_depart
     *  - voit_id
     *  - places_disponibles
     */
    public function reprogrammerVoyage(int $voyageSourceId, array $data): array
    {
        return DB::transaction(function () use ($voyageSourceId, $data) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $source = Voyage::with(['trajet', 'voiture'])
                ->whereHas('trajet', function($q) use ($compagnieId) {
                    $q->where('comp_id', $compagnieId);
                })
                ->findOrFail($voyageSourceId);

            // Validation date
            if (empty($data['voyage_date'])) {
                throw new \Exception('La nouvelle date du voyage est requise');
            }
            $nouvelleDate = $data['voyage_date'];
            if (strtotime($nouvelleDate) === false) {
                throw new \Exception('Format de date invalide');
            }
            $dateCarbon = \Carbon\Carbon::parse($nouvelleDate)->startOfDay();
            if ($dateCarbon->isBefore(now()->startOfDay())) {
                throw new \Exception('La nouvelle date doit être aujourd\'hui ou dans le futur');
            }

            // Récupération des champs (avec valeurs par défaut héritées du source)
            $heureDepart = $data['voyage_heure_depart'] ?? $source->voyage_heure_depart;
            $voitId = isset($data['voit_id']) ? (int) $data['voit_id'] : (int) $source->voit_id;
            $placesDisponibles = isset($data['places_disponibles'])
                ? (int) $data['places_disponibles']
                : (int) $source->places_disponibles;

            // Vérifier la voiture
            $voiture = Voitures::where('comp_id', $compagnieId)
                ->where('voit_id', $voitId)
                ->where('voit_statut', 1)
                ->first();

            if (!$voiture) {
                throw new \Exception('La voiture sélectionnée n\'est pas active ou n\'appartient pas à votre compagnie');
            }

            if (!$voiture->estDisponiblePourDate($nouvelleDate)) {
                throw new \Exception('Cette voiture n\'est pas disponible pour la date sélectionnée');
            }

            if ($placesDisponibles > $voiture->voit_places) {
                throw new \Exception('Le nombre de places ne peut pas dépasser la capacité du véhicule (' . $voiture->voit_places . ')');
            }
            if ($placesDisponibles <= 0) {
                throw new \Exception('Le nombre de places doit être supérieur à 0');
            }

            // Création du nouveau voyage (clone)
            $nouveau = Voyage::create([
                'voyage_date' => $nouvelleDate,
                'voyage_heure_depart' => $heureDepart,
                'voyage_type' => $source->voyage_type,
                'traj_id' => $source->traj_id,
                'voit_id' => $voitId,
                'voyage_statut' => 1,
                'voyage_is_active' => true,
                'places_disponibles' => $placesDisponibles,
                'places_reservees' => 0,
            ]);

            return [
                'voyage_source_id' => (int) $source->voyage_id,
                'voyage' => $this->formaterVoyageComplet($nouveau->fresh(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture'])),
            ];
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
            'date' => DateFormatter::formatDate($voyage->voyage_date),
            'heure_depart' => $voyage->voyage_heure_depart,
            'type' => $voyage->voyage_type,
            'statut' => $voyage->voyage_statut,
            'is_active' => $voyage->voyage_is_active,
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