<?php

namespace App\Services\voyage;

use App\Models\Voyages\Voyage;
use App\Models\Trajet\Trajet;
use App\Models\Voitures\Voitures;
use App\Models\Reservation\Reservation;
use App\Services\Notification\NotificationService;
use App\Services\Compagnies\PartenaireService;
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
    public function programmerVoyage(VoyageDTO $voyageDTO, array $publication = [], $reception = null): array 
    {
        return DB::transaction(function () use ($voyageDTO, $publication, $reception) {
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

            // Cohérence catégorie : la voiture doit être de la même catégorie que le trajet
            $this->assertCoherenceCategorie($trajet, $voiture);

            $voyage = Voyage::create([
                'voyage_date' => $voyageDTO->voyageDate,
                'voyage_heure_depart' => $voyageDTO->voyageHeureDepart,
                'voyage_type' => $voyageDTO->voyageType,
                'traj_id' => $voyageDTO->trajId,
                'voit_id' => $voyageDTO->voitId,
                'voyage_statut' => 1, // Programmé
                'places_disponibles' => $voyageDTO->placesDisponibles,
                'places_reservees' => 0,
                // Compagnie partenaire qui accueille la voiture à destination (null = suivi interne)
                'comp_reception' => app(PartenaireService::class)->verifierReception($compagnieId, $reception, $trajet),
            ] + $this->champsPublication($publication, $voyageDTO->voyageDate, $voyageDTO->voyageHeureDepart));

            return $this->formaterVoyageComplet($voyage);
        });
    }


    /**
     * Programmer plusieurs voyages en une seule opération.
     */
    public function programmerVoyagesMultiples(array $voyagesData, array $publication = [], $receptionLot = null): array
    {
        return DB::transaction(function () use ($voyagesData, $publication, $receptionLot) {
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

                    // Cohérence catégorie : trajet et voiture doivent être de la même catégorie
                    $trajetItem = Trajet::find($voyageDTO->trajId);
                    if ($trajetItem) {
                        $this->assertCoherenceCategorie($trajetItem, $voiture);
                    }

                    // Compagnie de réception propre à ce voyage si elle est fournie, sinon celle du lot
                    $receptionId = app(PartenaireService::class)->verifierReception(
                        $compagnieId,
                        array_key_exists('comp_reception', $data) ? $data['comp_reception'] : $receptionLot,
                        $trajetItem
                    );

                    $voyage = Voyage::create([
                        'voyage_date' => $voyageDTO->voyageDate,
                        'voyage_heure_depart' => $voyageDTO->voyageHeureDepart,
                        'voyage_type' => $voyageDTO->voyageType,
                        'traj_id' => $voyageDTO->trajId,
                        'voit_id' => $voyageDTO->voitId,
                        'voyage_statut' => 1,
                        'places_disponibles' => $voyageDTO->placesDisponibles,
                        'places_reservees' => 0,
                        'comp_reception' => $receptionId,
                    ] + $this->champsPublication(
                        // Publication propre à ce voyage si elle est fournie, sinon celle du lot
                        isset($data['publication'])
                            ? ['mode' => $data['publication'], 'date' => $data['publication_date'] ?? null]
                            : $publication,
                        $voyageDTO->voyageDate,
                        $voyageDTO->voyageHeureDepart
                    ));

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
     * Champs de publication d'un voyage à partir du choix de la compagnie.
     *
     * $publication['mode'] :
     *   - 'maintenant' (défaut) : publié tout de suite ;
     *   - 'plus_tard'           : planifié sans être publié, publication manuelle plus tard ;
     *   - 'programmee'          : non publié, publié automatiquement à
     *                             $publication['date'] (instant ISO 8601 avec fuseau).
     */
    private function champsPublication(array $publication, string $date, string $heure): array
    {
        $mode = $publication['mode'] ?? 'maintenant';

        if ($mode === 'plus_tard') {
            return ['voyage_publie' => false, 'voyage_is_active' => false, 'voyage_publication_prevue' => null];
        }

        if ($mode === 'programmee') {
            if (empty($publication['date'])) {
                throw new \Exception('Indiquez la date de publication.');
            }
            // Instant envoyé par l'app (avec son décalage) ramené au fuseau de l'API,
            // celui dans lequel la colonne (sans fuseau) est enregistrée et relue
            $instant = \Carbon\Carbon::parse($publication['date'])->setTimezone(config('app.timezone'));
            if ($instant->lte(now())) {
                throw new \Exception('La date de publication doit être dans le futur.');
            }
            // Le départ est saisi en heure locale des voyages
            $depart = \Carbon\Carbon::parse("{$date} {$heure}", config('app.fuseau_voyages', 'Indian/Antananarivo'));
            if ($instant->gte($depart)) {
                throw new \Exception("La publication doit avoir lieu avant le départ du {$depart->format('d/m/Y')} à {$depart->format('H:i')}.");
            }
            return ['voyage_publie' => false, 'voyage_is_active' => false, 'voyage_publication_prevue' => $instant];
        }

        return ['voyage_publie' => true, 'voyage_is_active' => true, 'voyage_publication_prevue' => null];
    }

    /**
     * Publie tout de suite un voyage planifié : il devient visible et réservable.
     */
    public function publierVoyage(int $voyageId): array
    {
        return DB::transaction(function () use ($voyageId) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::whereHas('trajet', function ($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->lockForUpdate()->findOrFail($voyageId);

            if ($voyage->voyage_publie) {
                throw new \Exception('Ce voyage est déjà publié.');
            }
            if ((int) $voyage->voyage_statut !== 1) {
                throw new \Exception('Seul un voyage programmé peut être publié.');
            }
            if (!$voyage->estFutur()) {
                throw new \Exception('La date de ce voyage est passée : modifiez-la avant de le publier.');
            }

            $voyage->update([
                'voyage_publie' => true,
                'voyage_is_active' => true,
                'voyage_publication_prevue' => null,
            ]);

            return $this->formaterVoyageComplet($voyage->fresh(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture']));
        });
    }

    /**
     * Publie les voyages planifiés dont la date de publication est atteinte
     * (tâche planifiée `voyages:publier`). Renvoie le nombre de voyages publiés.
     */
    public function publierVoyagesProgrammes(): int
    {
        $aPublier = Voyage::where('voyage_publie', false)
            ->whereNotNull('voyage_publication_prevue')
            ->where('voyage_publication_prevue', '<=', now())
            ->where('voyage_statut', 1)
            ->get();

        $publies = 0;
        foreach ($aPublier as $voyage) {
            // Départ déjà passé : on ne publie pas, le voyage reste non publié, sans date
            $futur = $voyage->estFutur();
            $voyage->update($futur
                ? ['voyage_publie' => true, 'voyage_is_active' => true, 'voyage_publication_prevue' => null]
                : ['voyage_publication_prevue' => null]);
            $publies += $futur ? 1 : 0;
        }

        return $publies;
    }

    /**
     * Rappels de publication pour l'onglet Planning : voyages planifiés mais pas
     * encore publiés qui demandent l'attention de la compagnie.
     *
     *  - 'bientot_publies' : publication automatique programmée dans les
     *    $jours prochains jours (dernier moment pour vérifier date, heure,
     *    véhicule et chauffeur) ;
     *  - 'a_publier' : aucune date de publication alors que le départ a lieu
     *    dans les $jours prochains jours (à publier à la main, sinon personne
     *    ne pourra réserver).
     */
    public function rappelsPublication(int $jours = 3): array
    {
        $compagnieId = $this->getCompagnieUtilisateur();
        $fuseau = config('app.fuseau_voyages', 'Indian/Antananarivo');
        $aujourdhui = now($fuseau)->toDateString();
        $limiteDepart = now($fuseau)->addDays($jours)->toDateString();

        $base = fn() => Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture.chauffeur', 'compagnieReception'])
            ->whereHas('trajet', fn($q) => $q->where('comp_id', $compagnieId))
            ->where('voyage_statut', 1)
            ->where('voyage_publie', false)
            ->where('voyage_date', '>=', $aujourdhui);

        $bientotPublies = $base()
            ->whereNotNull('voyage_publication_prevue')
            ->where('voyage_publication_prevue', '<=', now()->addDays($jours))
            ->orderBy('voyage_publication_prevue')
            ->limit(50)
            ->get();

        $aPublier = $base()
            ->whereNull('voyage_publication_prevue')
            ->where('voyage_date', '<=', $limiteDepart)
            ->orderBy('voyage_date')
            ->orderBy('voyage_heure_depart')
            ->limit(50)
            ->get();

        return [
            'jours' => $jours,
            'total' => $bientotPublies->count() + $aPublier->count(),
            'bientot_publies' => $bientotPublies->map(fn($v) => $this->formaterVoyageComplet($v))->values(),
            'a_publier' => $aPublier->map(fn($v) => $this->formaterVoyageComplet($v))->values(),
        ];
    }

    /**
     * Récupère la liste des voyages de la compagnie
     */
    public function listerVoyages(array $filtres = []): array 
    {
        $compagnieId = $this->getCompagnieUtilisateur();

        // Statuts (complet / départ atteint → En cours ; Terminé ; Annulé) mis à
        // jour par la tâche planifiée `voyages:gestion-statuts` (chaque minute).

        $query = Voyage::with(['trajet.provinceDepart', 'trajet.provinceArrivee', 'voiture.chauffeur', 'compagnieReception'])
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

        // Planning : voyages programmés ou en cours (publiés ou non)
        if (filter_var($filtres['a_venir'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('voyage_statut', [1, 2]);
        }

        // Historique : voyages terminés ou annulés
        if (filter_var($filtres['passes'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereIn('voyage_statut', [3, 4]);
        }

        // Publication : 1 = publiés, 0 = planifiés non publiés
        if (isset($filtres['publie']) && $filtres['publie'] !== '') {
            $query->where('voyage_publie', filter_var($filtres['publie'], FILTER_VALIDATE_BOOLEAN));
        }

        // Filtrage par trajet
        if (isset($filtres['traj_id'])) {
            $query->where('traj_id', $filtres['traj_id']);
        }

        // Filtrage par véhicule
        if (isset($filtres['voit_id'])) {
            $query->where('voit_id', $filtres['voit_id']);
        }

        // Filtrage par chauffeur (celui du véhicule affecté)
        if (isset($filtres['chauff_id'])) {
            $query->whereHas('voiture', fn($q) => $q->where('chauff_id', $filtres['chauff_id']));
        }

        // Tri : colonnes autorisées seulement, puis heure de départ
        $sortField = in_array($filtres['sort_field'] ?? null, ['voyage_date', 'voyage_heure_depart', 'created_at', 'places_reservees'], true)
            ? $filtres['sort_field']
            : 'voyage_date';
        $sortDirection = strtolower((string) ($filtres['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDirection);
        if ($sortField !== 'voyage_heure_depart') {
            $query->orderBy('voyage_heure_depart', $sortDirection);
        }

        $perPage = max(1, min((int) ($filtres['per_page'] ?? 15), 200));
        $voyages = $query->paginate($perPage);

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
    public function mettreAjourVoyage(int $voyageId, VoyageDTO $voyageDTO, ?array $publication = null, bool $receptionFournie = false, $reception = null): array 
    {
        return DB::transaction(function () use ($voyageId, $voyageDTO, $publication, $receptionFournie, $reception) {
            $compagnieId = $this->getCompagnieUtilisateur();

            $voyage = Voyage::whereHas('trajet', function($q) use ($compagnieId) {
                $q->where('comp_id', $compagnieId);
            })->findOrFail($voyageId);

            if ($voyage->voyage_suivi !== null) {
                throw new \Exception('Cette voiture est déjà partie : le voyage ne peut plus être modifié.');
            }

            // Valider le DTO
            $voyageDTO->validate();

            // Le trajet (nouveau ou inchangé) appartient à la compagnie
            $trajet = Trajet::where('comp_id', $compagnieId)->findOrFail($voyageDTO->trajId);

            // Compagnie de réception : celle demandée, sinon l'actuelle si elle convient
            // toujours à la destination (le trajet a pu changer), sinon aucune
            $partenaires = app(PartenaireService::class);
            if ($receptionFournie) {
                $receptionId = $partenaires->verifierReception($compagnieId, $reception, $trajet);
            } else {
                try {
                    $receptionId = $partenaires->verifierReception($compagnieId, $voyage->comp_reception, $trajet);
                } catch (\Exception) {
                    $receptionId = null;
                }
            }

            $voitureChangee = $voyageDTO->voitId != $voyage->voit_id;
            $dateChangee = $voyageDTO->voyageDate !== $voyage->voyage_date->toDateString();

            // Le véhicule (nouveau ou inchangé) : un nouveau véhicule doit être actif
            $voiture = Voitures::where('comp_id', $compagnieId)
                ->when($voitureChangee, fn($q) => $q->where('voit_statut', 1))
                ->findOrFail($voyageDTO->voitId);

            // Véhicule ou date modifié : le véhicule ne doit pas déjà rouler ce jour-là
            // (sans compter ce voyage lui-même)
            if ($voitureChangee || $dateChangee) {
                $dejaPris = Voyage::where('voit_id', $voiture->voit_id)
                    ->where('voyage_date', $voyageDTO->voyageDate)
                    ->whereIn('voyage_statut', [1, 2])
                    ->where('voyage_id', '!=', $voyage->voyage_id)
                    ->exists();
                if ($dejaPris) {
                    throw new \Exception("Le véhicule {$voiture->voit_matricule} a déjà un voyage le " . \Carbon\Carbon::parse($voyageDTO->voyageDate)->format('d/m/Y') . '.');
                }
            }

            if ($voyageDTO->placesDisponibles > $voiture->voit_places) {
                throw new \Exception("Le véhicule {$voiture->voit_matricule} n'a que {$voiture->voit_places} places.");
            }

            // Cohérence catégorie : le véhicule doit être de la même catégorie que le trajet
            $this->assertCoherenceCategorie($trajet, $voiture);

            // Changement de véhicule avec des billets déjà vendus : leurs sièges doivent exister
            if ($voitureChangee && $voyage->places_reservees > 0) {
                $plusGrandSiege = (int) DB::table('fandrio_app.reservation_voyageurs as rv')
                    ->join('fandrio_app.reservations as r', 'r.res_id', '=', 'rv.res_id')
                    ->where('r.voyage_id', $voyage->voyage_id)
                    ->whereIn('r.res_statut', [1, 2])
                    ->max('rv.place_numero');
                $placesPlan = $voiture->planSiege?->getNombreTotalSieges() ?? $voiture->voit_places;
                if ($plusGrandSiege > $placesPlan) {
                    throw new \Exception("Le siège {$plusGrandSiege} est déjà vendu sur ce voyage, mais le véhicule {$voiture->voit_matricule} n'a que {$placesPlan} places.");
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
                'places_disponibles' => $voyageDTO->placesDisponibles,
                'comp_reception' => $receptionId,
            ]
                // La publication ne se règle que tant que le voyage n'est pas publié
                + ($publication !== null && !$voyage->voyage_publie
                    ? $this->champsPublication($publication, $voyageDTO->voyageDate, $voyageDTO->voyageHeureDepart)
                    : []));

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
            if ($voyage->voyage_suivi !== null) {
                throw new \Exception('Cette voiture est déjà partie : le voyage ne peut plus être annulé.');
            }

            // 1. Récupérer les réservations actives avant changement (pour notifier les clients)
            $reservationsActives = Reservation::where('voyage_id', $voyageId)
                ->whereIn('res_statut', [1, 2])
                ->get();

            // 2. Annuler les réservations + ouvrir un remboursement pour celles déjà payées
            foreach ($reservationsActives as $resa) {
                $aPaye = (float) $resa->montant_avance > 0;
                $resa->update([
                    'res_statut' => 4,
                    'res_remb_statut' => $aPaye ? 1 : 0,
                    'res_remb_montant' => $aPaye ? $resa->montant_avance : null,
                ]);
            }

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
                'voyage_is_active' => (bool) $voyage->voyage_publie, // un voyage non publié reste invisible
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

            if (!$voyage->voyage_publie) {
                throw new \Exception('Ce voyage n\'est pas encore publié : publiez-le pour l\'ouvrir aux réservations.');
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
            // Publication : false = planifié mais non publié (invisible des clients)
            'publie' => (bool) ($voyage->voyage_publie ?? true),
            'publication_prevue' => $voyage->voyage_publication_prevue?->toIso8601String(),
            'places_disponibles' => $voyage->places_disponibles,
            'places_reservees' => $voyage->places_reservees,
            'places_libres' => $voyage->getPlacesLibres(),
            'est_complet' => $voyage->estComplet(),
            'trajet' => [
                'id' => $voyage->trajet->traj_id,
                'nom' => $voyage->trajet->traj_nom,
                'tarif' => (float) $voyage->trajet->traj_tarif,
                'categorie' => $voyage->trajet->traj_categorie ?? 'classique',
                'province_depart' => $voyage->trajet->provinceDepart->pro_nom,
                'province_arrivee' => $voyage->trajet->provinceArrivee->pro_nom
            ],
            'voiture' => [
                'id' => $voyage->voiture->voit_id,
                'matricule' => $voyage->voiture->voit_matricule,
                'marque' => $voyage->voiture->voit_marque,
                'modele' => $voyage->voiture->voit_modele,
                'capacite' => $voyage->voiture->voit_places,
                'categorie' => $voyage->voiture->voit_categorie ?? 'classique',
                // Chauffeur affecté au véhicule (null si aucun)
                'chauffeur' => $voyage->voiture->chauffeur ? [
                    'id' => $voyage->voiture->chauffeur->chauff_id,
                    'nom' => $voyage->voiture->chauffeur->chauff_nom,
                    'prenom' => $voyage->voiture->chauffeur->chauff_prenom,
                    'telephone' => $voyage->voiture->chauffeur->chauff_phone,
                ] : null,
            ],
            'categorie' => $voyage->voyage_categorie,
            // Compagnie partenaire qui accueille la voiture à destination (null = suivi interne)
            'reception' => $voyage->compagnieReception ? [
                'id' => $voyage->compagnieReception->comp_id,
                'nom' => $voyage->compagnieReception->comp_nom,
            ] : null,
            // Suivi de la voiture : null (pas partie), 'en_route' ou 'arrive'
            'suivi' => match ($voyage->voyage_suivi) {
                Voyage::SUIVI_EN_ROUTE => 'en_route',
                Voyage::SUIVI_ARRIVE => 'arrive',
                default => null,
            },
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

    /**
     * Vérifie que la voiture et le trajet appartiennent à la même catégorie.
     */
    private function assertCoherenceCategorie(Trajet $trajet, Voitures $voiture): void
    {
        $catTrajet  = $trajet->traj_categorie ?? 'classique';
        $catVoiture = $voiture->voit_categorie ?? 'classique';

        if ($catTrajet !== $catVoiture) {
            throw new \Exception(
                "Incohérence de catégorie : le trajet est « {$catTrajet} » mais la voiture est « {$catVoiture} »."
            );
        }
    }
}