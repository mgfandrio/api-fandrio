<?php

namespace App\Services\Voyage;

use App\Models\Compagnies\Compagnie;
use App\Models\Voyages\Voyage;
use App\Services\Compagnies\PartenaireService;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des voitures en route entre la compagnie de départ et la compagnie de
 * réception (partenaire située dans la province d'arrivée).
 *
 *  - Départ : l'admin clôture l'embarquement (au plus tôt 3 h avant l'heure de départ).
 *    Le voyage passe « en route » et la compagnie de réception reçoit le détail du
 *    voyage, du véhicule, du chauffeur et la liste des voyageurs embarqués.
 *    Sans clôture, passage automatique 1 h après l'heure de départ, avec la liste
 *    des billets confirmés (« embarquement non clôturé »).
 *  - Arrivée : marquée par la compagnie de réception ; la compagnie de départ peut
 *    le faire s'il n'y a pas de réception, ou 1 h après l'arrivée prévue.
 *  - Retard : 1 h après l'arrivée prévue (départ + durée du trajet), les deux
 *    compagnies sont alertées une fois.
 *  - La liste nominative n'est plus servie 7 jours après l'arrivée.
 */
class SuiviVoyageService
{
    /** Clôture possible à partir de 3 h avant l'heure de départ. */
    private const CLOTURE_AVANT_HEURES = 3;
    /** Passage automatique en route 1 h après l'heure de départ. */
    private const AUTO_APRES_MINUTES = 60;
    /** Les départs plus anciens ne sont plus clôturés (voyages d'avant le suivi). */
    private const FENETRE_ANCIENS_HEURES = 6;
    /** Alerte de retard et arrivée par la compagnie de départ : 1 h après l'arrivée prévue. */
    private const MARGE_RETARD_MINUTES = 60;
    /** Durée retenue si le trajet n'a pas de durée (pas d'alerte de retard dans ce cas). */
    private const DUREE_DEFAUT_HEURES = 12;
    /** Liste nominative visible 7 jours après l'arrivée. */
    private const CONSERVATION_LISTE_JOURS = 7;
    /** Historique des voyages arrivés affiché dans le suivi. */
    private const HISTORIQUE_JOURS = 30;

    private const RELATIONS = [
        'trajet.provinceDepart', 'trajet.provinceArrivee', 'trajet.compagnie.localisation',
        'voiture.chauffeur', 'compagnieReception.localisation',
    ];

    public function __construct(private PartenaireService $partenaires) {}

    // ------------------------------------------------------------------ listes

    /**
     * Onglet Départs : mes voyages à clôturer, en route et arrivés récemment.
     */
    public function departs(int $compId): array
    {
        $maintenant = now();

        $aCloturer = $this->aCloturer($compId, $maintenant);

        $suivis = Voyage::with(self::RELATIONS)
            ->whereHas('trajet', fn($q) => $q->where('comp_id', $compId))
            ->where(fn($q) => $this->filtreSuiviRecent($q))
            ->orderByDesc('voyage_depart_le')
            ->get();

        return $this->grouper($aCloturer->concat($suivis), $compId);
    }

    /**
     * Onglet Arrivées attendues : voitures que mes partenaires m'envoient
     * (prévues aujourd'hui et demain, en route, arrivées récemment).
     */
    public function arrivees(int $compId): array
    {
        $attendus = Voyage::with(self::RELATIONS)
            ->where('comp_reception', $compId)
            ->whereNull('voyage_suivi')
            ->whereIn('voyage_statut', [1, 2, 3])
            ->where('voyage_publie', true)
            ->whereBetween('voyage_date', [now()->toDateString(), now()->addDay()->toDateString()])
            ->orderBy('voyage_date')->orderBy('voyage_heure_depart')
            ->get()
            // Un départ du jour déjà passé depuis longtemps sans clôture n'est plus « attendu »
            ->filter(fn(Voyage $v) => $this->departPrevu($v)->gte(now()->subHours(self::FENETRE_ANCIENS_HEURES)));

        $suivis = Voyage::with(self::RELATIONS)
            ->where('comp_reception', $compId)
            ->where(fn($q) => $this->filtreSuiviRecent($q))
            ->orderByDesc('voyage_depart_le')
            ->get();

        return $this->grouper($attendus->concat($suivis), $compId);
    }

    /** Pastille du menu : départs à clôturer (heure passée) et retards me concernant. */
    public function compteurs(int $compId): array
    {
        $maintenant = now();

        $aCloturer = $this->aCloturer($compId, $maintenant)
            ->filter(fn(Voyage $v) => $this->departPrevu($v)->lte($maintenant))
            ->count();

        $enRoute = Voyage::with('trajet')
            ->where('voyage_suivi', Voyage::SUIVI_EN_ROUTE)
            ->where(fn($q) => $q->where('comp_reception', $compId)
                ->orWhereHas('trajet', fn($t) => $t->where('comp_id', $compId)))
            ->get();

        $retards = $enRoute->filter(fn(Voyage $v) => $this->enRetard($v))->count();
        $versMoi = $enRoute->where('comp_reception', $compId)->count();

        return [
            'a_cloturer' => $aCloturer,
            'en_retard' => $retards,
            'en_route_vers_moi' => $versMoi,
            'total' => $aCloturer + $retards,
        ];
    }

    /** Détail d'un voyage suivi, avec la liste des voyageurs quand elle est visible. */
    public function detail(int $voyageId, int $compId): array
    {
        $voyage = $this->voyageVisible($voyageId, $compId);
        $item = $this->formater($voyage, $compId, $this->comptes(collect([$voyage->voyage_id]))[$voyage->voyage_id] ?? null);

        $item['liste_visible'] = $this->listeVisible($voyage, $compId);
        $item['voyageurs'] = $item['liste_visible'] ? $this->voyageurs($voyage) : [];

        return $item;
    }

    // ------------------------------------------------------------------ actions

    /**
     * Clôture de l'embarquement par la compagnie de départ : la voiture part.
     */
    public function cloturer(int $voyageId, int $compId): array
    {
        $voyage = DB::transaction(function () use ($voyageId, $compId) {
            $voyage = Voyage::with(self::RELATIONS)
                ->whereHas('trajet', fn($q) => $q->where('comp_id', $compId))
                ->lockForUpdate()
                ->findOrFail($voyageId);

            if ($voyage->voyage_suivi !== null) {
                throw new \Exception('Ce voyage est déjà parti.');
            }
            $this->verifierCloturable($voyage, now());

            $voyage->update([
                'voyage_suivi' => Voyage::SUIVI_EN_ROUTE,
                'voyage_depart_le' => now(),
                'voyage_depart_auto' => false,
                // Plus aucune vente une fois la voiture partie
                'voyage_is_active' => false,
            ]);
            return $voyage;
        });

        $this->notifierDepart($voyage);
        return $this->detail($voyage->voyage_id, $compId);
    }

    /**
     * Arrivée à destination, marquée par la compagnie de réception (ou, en secours,
     * par la compagnie de départ).
     */
    public function marquerArrive(int $voyageId, int $compId): array
    {
        $voyage = DB::transaction(function () use ($voyageId, $compId) {
            $voyage = Voyage::with(self::RELATIONS)->lockForUpdate()->findOrFail($voyageId);

            $depart = (int) $voyage->trajet->comp_id === $compId;
            $reception = $voyage->comp_reception !== null && (int) $voyage->comp_reception === $compId;
            if (!$depart && !$reception) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Ce voyage ne concerne pas votre compagnie.');
            }
            if ($voyage->voyage_suivi === Voyage::SUIVI_ARRIVE) {
                throw new \Exception('Ce voyage est déjà marqué arrivé.');
            }
            if ($voyage->voyage_suivi !== Voyage::SUIVI_EN_ROUTE) {
                throw new \Exception("Ce voyage n'est pas encore parti.");
            }
            if (!$reception) {
                $des = $this->arriveePossibleDes($voyage);
                if ($des && now()->lt($des)) {
                    $nom = $voyage->compagnieReception?->comp_nom ?? 'La compagnie de réception';
                    throw new \Exception("{$nom} doit confirmer l'arrivée. Si elle ne l'a pas fait, vous pourrez la confirmer vous-même à partir du {$des->format('d/m/Y')} à {$des->format('H:i')}.");
                }
            }

            $voyage->update([
                'voyage_suivi' => Voyage::SUIVI_ARRIVE,
                'voyage_arrivee_le' => now(),
                'voyage_arrivee_par' => $compId,
            ]);
            return $voyage;
        });

        $this->notifierArrivee($voyage, $compId);
        return $this->detail($voyage->voyage_id, $compId);
    }

    /**
     * Tâche planifiée (chaque minute) : départs automatiques et alertes de retard.
     */
    public function traiterAutomatique(): array
    {
        $maintenant = now();

        // 1. Départ automatique 1 h après l'heure de départ, embarquement non clôturé.
        //    Seuls les voyages réellement partis (statut Terminé posé à l'heure de départ).
        $candidats = Voyage::with(self::RELATIONS)
            ->whereNull('voyage_suivi')
            ->where('voyage_statut', 3)
            ->whereBetween('voyage_date', [$maintenant->copy()->subDay()->toDateString(), $maintenant->toDateString()])
            ->get()
            ->filter(function (Voyage $v) use ($maintenant) {
                $depart = $this->departPrevu($v);
                return $depart->copy()->addMinutes(self::AUTO_APRES_MINUTES)->lte($maintenant)
                    && $depart->gte($maintenant->copy()->subHours(self::FENETRE_ANCIENS_HEURES));
            });

        $auto = 0;
        foreach ($candidats as $voyage) {
            $maj = Voyage::whereKey($voyage->voyage_id)->whereNull('voyage_suivi')->update([
                'voyage_suivi' => Voyage::SUIVI_EN_ROUTE,
                'voyage_depart_le' => $this->departPrevu($voyage),
                'voyage_depart_auto' => true,
                'voyage_is_active' => false,
            ]);
            if ($maj) {
                $auto++;
                $this->notifierDepart($voyage->refresh()->load(self::RELATIONS));
            }
        }

        // 2. Alerte de retard, une seule fois par voyage
        $retards = 0;
        $enRoute = Voyage::with(self::RELATIONS)
            ->where('voyage_suivi', Voyage::SUIVI_EN_ROUTE)
            ->where('voyage_retard_notifie', false)
            ->get()
            ->filter(fn(Voyage $v) => $this->enRetard($v));

        foreach ($enRoute as $voyage) {
            if (Voyage::whereKey($voyage->voyage_id)->where('voyage_retard_notifie', false)->update(['voyage_retard_notifie' => true])) {
                $retards++;
                $this->notifierRetard($voyage);
            }
        }

        return ['departs_auto' => $auto, 'retards' => $retards];
    }

    // ------------------------------------------------------------------ calculs

    public function departPrevu(Voyage $v): Carbon
    {
        $date = $v->voyage_date instanceof \DateTimeInterface ? $v->voyage_date->format('Y-m-d') : (string) $v->voyage_date;
        return Carbon::parse("{$date} {$v->voyage_heure_depart}", config('app.fuseau_voyages', config('app.timezone')))
            ->setTimezone(config('app.timezone'));
    }

    /** Durée du trajet en secondes (intervalle PostgreSQL « HH:MM:SS » ou « N day(s) HH:MM:SS »). */
    private function dureeSecondes(?string $duree): ?int
    {
        if (!$duree) {
            return null;
        }
        if (!preg_match('/^(?:(\d+)\s+days?\s*)?(\d+):(\d{2})(?::(\d{2}))?/', trim($duree), $m)) {
            return null;
        }
        $secondes = ((int) ($m[1] ?? 0)) * 86400 + ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) ($m[4] ?? 0);
        return $secondes > 0 ? $secondes : null;
    }

    /** Arrivée prévue : départ (réel, sinon prévu) + durée du trajet (12 h par défaut). */
    public function arriveePrevue(Voyage $v): Carbon
    {
        $base = $this->departPrevu($v);
        if ($v->voyage_depart_le && $v->voyage_depart_le->gt($base)) {
            $base = $v->voyage_depart_le->copy();
        }
        $duree = $this->dureeSecondes($v->trajet?->traj_duree) ?? self::DUREE_DEFAUT_HEURES * 3600;
        return $base->copy()->addSeconds($duree);
    }

    public function enRetard(Voyage $v): bool
    {
        return $v->voyage_suivi === Voyage::SUIVI_EN_ROUTE
            && $this->dureeSecondes($v->trajet?->traj_duree) !== null
            && now()->gt($this->arriveePrevue($v)->addMinutes(self::MARGE_RETARD_MINUTES));
    }

    /** Pour la compagnie de départ : à partir de quand elle peut confirmer l'arrivée (null = tout de suite). */
    private function arriveePossibleDes(Voyage $v): ?Carbon
    {
        if ($v->comp_reception === null) {
            return null;
        }
        return $this->arriveePrevue($v)->addMinutes(self::MARGE_RETARD_MINUTES);
    }

    private function verifierCloturable(Voyage $voyage, Carbon $maintenant): void
    {
        if ((int) $voyage->voyage_statut === 4) {
            throw new \Exception('Ce voyage est annulé.');
        }
        if (!$voyage->voyage_publie) {
            throw new \Exception("Ce voyage n'a jamais été publié.");
        }
        $depart = $this->departPrevu($voyage);
        if ($maintenant->lt($depart->copy()->subHours(self::CLOTURE_AVANT_HEURES))) {
            $des = $depart->copy()->subHours(self::CLOTURE_AVANT_HEURES);
            throw new \Exception("L'embarquement pourra être clôturé à partir du {$des->format('d/m/Y')} à {$des->format('H:i')} (3 h avant le départ).");
        }
        if ($depart->lt($maintenant->copy()->subHours(self::FENETRE_ANCIENS_HEURES))) {
            throw new \Exception('Ce départ est trop ancien pour être clôturé.');
        }
    }

    /** Voyages de la compagnie à clôturer : pas encore partis, départ entre il y a 6 h et dans 3 h. */
    private function aCloturer(int $compId, Carbon $maintenant): Collection
    {
        return Voyage::with(self::RELATIONS)
            ->whereHas('trajet', fn($q) => $q->where('comp_id', $compId))
            ->whereNull('voyage_suivi')
            ->whereIn('voyage_statut', [1, 2, 3])
            ->where('voyage_publie', true)
            ->where('places_reservees', '>', 0)
            ->whereBetween('voyage_date', [$maintenant->copy()->subDay()->toDateString(), $maintenant->copy()->addDay()->toDateString()])
            ->get()
            ->filter(function (Voyage $v) use ($maintenant) {
                $depart = $this->departPrevu($v);
                return $depart->lte($maintenant->copy()->addHours(self::CLOTURE_AVANT_HEURES))
                    && $depart->gte($maintenant->copy()->subHours(self::FENETRE_ANCIENS_HEURES));
            })
            ->sortBy(fn(Voyage $v) => $this->departPrevu($v)->timestamp)
            ->values();
    }

    /** En route (quel que soit l'âge) ou arrivé depuis moins de 30 jours. */
    private function filtreSuiviRecent($q): void
    {
        $q->where('voyage_suivi', Voyage::SUIVI_EN_ROUTE)
            ->orWhere(fn($q2) => $q2->where('voyage_suivi', Voyage::SUIVI_ARRIVE)
                ->where('voyage_arrivee_le', '>=', now()->subDays(self::HISTORIQUE_JOURS)));
    }

    private function voyageVisible(int $voyageId, int $compId): Voyage
    {
        $voyage = Voyage::with(self::RELATIONS)
            ->where(fn($q) => $q->where('comp_reception', $compId)
                ->orWhereHas('trajet', fn($t) => $t->where('comp_id', $compId)))
            ->find($voyageId);
        if (!$voyage) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Voyage introuvable.');
        }
        return $voyage;
    }

    /** La compagnie de réception ne voit les noms qu'une fois la voiture partie, et 7 jours après l'arrivée au plus. */
    private function listeVisible(Voyage $v, int $compId): bool
    {
        if ((int) $v->trajet->comp_id === $compId) {
            return true; // ses propres voyageurs
        }
        if ($v->voyage_suivi === Voyage::SUIVI_EN_ROUTE) {
            return true;
        }
        return $v->voyage_suivi === Voyage::SUIVI_ARRIVE
            && $v->voyage_arrivee_le
            && $v->voyage_arrivee_le->gte(now()->subDays(self::CONSERVATION_LISTE_JOURS));
    }

    /**
     * Voyageurs du voyage : embarqués si l'embarquement a été clôturé, sinon
     * (départ automatique ou pas encore parti) tous les billets confirmés.
     */
    private function voyageurs(Voyage $v): array
    {
        $embarquesSeulement = $v->voyage_suivi !== null && !$v->voyage_depart_auto;

        return DB::table('fandrio_app.reservation_voyageurs as rv')
            ->join('fandrio_app.reservations as r', 'r.res_id', '=', 'rv.res_id')
            ->join('fandrio_app.voyageurs as vo', 'vo.voya_id', '=', 'rv.voya_id')
            ->where('r.voyage_id', $v->voyage_id)
            ->where('r.res_statut', 2)
            ->when($embarquesSeulement, fn($q) => $q->where('rv.res_voya_statut', 2))
            ->orderBy('rv.place_numero')
            ->get(['vo.voya_nom', 'vo.voya_prenom', 'rv.place_numero', 'rv.res_voya_statut'])
            ->map(fn($x) => [
                'nom' => $x->voya_nom,
                'prenom' => $x->voya_prenom,
                'siege' => (int) $x->place_numero,
                'embarque' => (int) $x->res_voya_statut === 2,
            ])
            ->all();
    }

    /** Voyageurs confirmés et embarqués, par voyage. */
    private function comptes(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }
        return DB::table('fandrio_app.reservation_voyageurs as rv')
            ->join('fandrio_app.reservations as r', 'r.res_id', '=', 'rv.res_id')
            ->whereIn('r.voyage_id', $ids->unique()->values())
            ->where('r.res_statut', 2)
            ->groupBy('r.voyage_id')
            ->selectRaw('r.voyage_id, COUNT(*) AS confirmes, COUNT(*) FILTER (WHERE rv.res_voya_statut = 2) AS embarques')
            ->get()
            ->keyBy('voyage_id')
            ->map(fn($x) => ['confirmes' => (int) $x->confirmes, 'embarques' => (int) $x->embarques])
            ->all();
    }

    private function grouper(Collection $voyages, int $compId): array
    {
        $voyages = $voyages->unique('voyage_id')->values();
        $comptes = $this->comptes($voyages->pluck('voyage_id'));
        return $voyages->map(fn(Voyage $v) => $this->formater($v, $compId, $comptes[$v->voyage_id] ?? null))->values()->all();
    }

    private function formater(Voyage $v, int $compId, ?array $comptes): array
    {
        $comptes ??= ['confirmes' => 0, 'embarques' => 0];
        $estDepart = (int) $v->trajet->comp_id === $compId;
        $depart = $this->departPrevu($v);

        $statut = match ($v->voyage_suivi) {
            Voyage::SUIVI_EN_ROUTE => 'en_route',
            Voyage::SUIVI_ARRIVE => 'arrive',
            default => $estDepart ? 'a_cloturer' : 'attendu',
        };

        $nbVoyageurs = match (true) {
            $v->voyage_suivi === null, (bool) $v->voyage_depart_auto => $comptes['confirmes'],
            default => $comptes['embarques'],
        };

        $possibleDes = $estDepart && $v->voyage_suivi === Voyage::SUIVI_EN_ROUTE ? $this->arriveePossibleDes($v) : null;
        $estReception = $v->comp_reception !== null && (int) $v->comp_reception === $compId;

        $chauffeur = $v->voiture?->chauffeur;
        $compDepart = $v->trajet->compagnie;

        return [
            'id' => $v->voyage_id,
            'sens' => $estDepart ? 'depart' : 'arrivee',
            'statut_suivi' => $statut,
            'en_retard' => $this->enRetard($v),
            'date' => $depart->toDateString(),
            'heure_depart' => substr((string) $v->voyage_heure_depart, 0, 5),
            'depart_prevu' => $depart->toIso8601String(),
            'depart_le' => $v->voyage_depart_le?->toIso8601String(),
            'depart_auto' => (bool) $v->voyage_depart_auto,
            'arrivee_prevue' => $this->arriveePrevue($v)->toIso8601String(),
            'duree_connue' => $this->dureeSecondes($v->trajet?->traj_duree) !== null,
            'arrivee_le' => $v->voyage_arrivee_le?->toIso8601String(),
            'arrivee_confirmee_par' => $v->voyage_arrivee_par === null ? null
                : ((int) $v->voyage_arrivee_par === (int) $v->trajet->comp_id ? 'depart' : 'reception'),
            'trajet' => [
                'depart' => $v->trajet->provinceDepart?->pro_nom,
                'arrivee' => $v->trajet->provinceArrivee?->pro_nom,
            ],
            'compagnie_depart' => $compDepart ? $this->partenaires->formater($compDepart) : null,
            'compagnie_reception' => $v->compagnieReception ? $this->partenaires->formater($v->compagnieReception) : null,
            'voiture' => $v->voiture ? [
                'matricule' => $v->voiture->voit_matricule,
                'marque' => $v->voiture->voit_marque,
                'modele' => $v->voiture->voit_modele,
            ] : null,
            'chauffeur' => $chauffeur ? [
                'nom' => $chauffeur->chauff_nom,
                'prenom' => $chauffeur->chauff_prenom,
                'telephone' => $chauffeur->chauff_phone,
            ] : null,
            'nb_voyageurs' => $nbVoyageurs,
            'embarques' => $comptes['embarques'],
            'confirmes' => $comptes['confirmes'],
            'peut_cloturer' => $estDepart && $v->voyage_suivi === null
                && $depart->copy()->subHours(self::CLOTURE_AVANT_HEURES)->lte(now()),
            'peut_marquer_arrive' => $v->voyage_suivi === Voyage::SUIVI_EN_ROUTE
                && ($estReception || ($estDepart && ($possibleDes === null || now()->gte($possibleDes)))),
            'arrivee_possible_des' => $possibleDes?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ notifications

    private function resume(Voyage $v): string
    {
        $depart = $this->departPrevu($v);
        $trajet = ($v->trajet->provinceDepart?->pro_nom ?? '?') . ' → ' . ($v->trajet->provinceArrivee?->pro_nom ?? '?');
        return "{$trajet}, départ du {$depart->format('d/m/Y')} à {$depart->format('H:i')}";
    }

    private function vehicule(Voyage $v): string
    {
        $voiture = $v->voiture;
        $texte = $voiture ? trim("{$voiture->voit_matricule} ({$voiture->voit_marque} {$voiture->voit_modele})") : 'véhicule non renseigné';
        $c = $voiture?->chauffeur;
        return $texte . ($c ? ", chauffeur {$c->chauff_prenom} {$c->chauff_nom} ({$c->chauff_phone})" : ', chauffeur non renseigné');
    }

    private function notifierDepart(Voyage $v): void
    {
        $nb = $this->comptes(collect([$v->voyage_id]))[$v->voyage_id] ?? ['confirmes' => 0, 'embarques' => 0];
        $nbListe = $v->voyage_depart_auto ? $nb['confirmes'] : $nb['embarques'];
        $arrivee = $this->arriveePrevue($v);
        $compDepart = $v->trajet->compagnie?->comp_nom ?? 'La compagnie de départ';
        $pluriel = $nbListe > 1 ? 's' : '';

        if ($v->comp_reception) {
            $mention = $v->voyage_depart_auto
                ? " L'embarquement n'a pas été clôturé : la liste reprend les {$nbListe} billet{$pluriel} confirmé{$pluriel}."
                : " {$nbListe} voyageur{$pluriel} à bord.";
            NotificationService::notifierAdminsCompagnie(
                (int) $v->comp_reception,
                13,
                '🚌 Voiture en route vers vous',
                "{$compDepart} : {$this->resume($v)}. Véhicule {$this->vehicule($v)}.{$mention} Arrivée prévue vers {$arrivee->format('H:i')}"
                    . ($arrivee->isSameDay($this->departPrevu($v)) ? '' : " le {$arrivee->format('d/m')}")
                    . '. Liste des voyageurs et confirmation d\'arrivée : Menu > Suivi des voyages.'
            );
        }

        if ($v->voyage_depart_auto) {
            $qui = $v->compagnieReception ? "{$v->compagnieReception->comp_nom} a reçu la liste des billets confirmés." : 'Pensez à confirmer son arrivée.';
            NotificationService::notifierAdminsCompagnie(
                (int) $v->trajet->comp_id,
                16,
                'Embarquement non clôturé',
                "Le voyage {$this->resume($v)} est passé « En route » automatiquement, une heure après l'heure de départ. {$qui}"
            );
        }
    }

    private function notifierArrivee(Voyage $v, int $parCompId): void
    {
        $heure = $v->voyage_arrivee_le?->format('H:i') ?? now()->format('H:i');
        $compDepartId = (int) $v->trajet->comp_id;

        if ($parCompId !== $compDepartId) {
            $nom = $v->compagnieReception?->comp_nom ?? 'La compagnie de réception';
            NotificationService::notifierAdminsCompagnie(
                $compDepartId,
                14,
                '✅ Voiture arrivée à destination',
                "{$nom} confirme l'arrivée à {$heure} du voyage {$this->resume($v)} (véhicule {$v->voiture?->voit_matricule})."
            );
        } elseif ($v->comp_reception) {
            $nom = $v->trajet->compagnie?->comp_nom ?? 'La compagnie de départ';
            NotificationService::notifierAdminsCompagnie(
                (int) $v->comp_reception,
                14,
                'Arrivée confirmée par la compagnie de départ',
                "{$nom} a confirmé à {$heure} l'arrivée du voyage {$this->resume($v)} (véhicule {$v->voiture?->voit_matricule})."
            );
        }
    }

    private function notifierRetard(Voyage $v): void
    {
        $prevue = $this->arriveePrevue($v);
        $c = $v->voiture?->chauffeur;
        $message = "La voiture {$v->voiture?->voit_matricule} ({$this->resume($v)}) devait arriver vers {$prevue->format('H:i')} et n'est pas encore marquée arrivée."
            . ($c ? " Contactez le chauffeur {$c->chauff_prenom} {$c->chauff_nom} au {$c->chauff_phone}." : '');

        NotificationService::notifierAdminsCompagnie((int) $v->trajet->comp_id, 15, '⚠️ Voiture en retard', $message);
        if ($v->comp_reception) {
            NotificationService::notifierAdminsCompagnie((int) $v->comp_reception, 15, '⚠️ Voiture en retard', $message);
        }
    }
}
