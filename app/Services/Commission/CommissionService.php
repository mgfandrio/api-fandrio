<?php

namespace App\Services\Commission;

use App\Models\Commissions\Collecte;
use App\Models\Commissions\CollecteJournal;
use App\Models\Compagnies\Compagnie;
use App\Models\Reservation\Reservation;
use App\Models\Utilisateurs\Utilisateur;
use App\Services\Notification\NotificationService;
use App\Services\Paiement\PapiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commission FANDRIO versée par tranche de voyages.
 *
 * - La commission (5 %) est figée sur chaque billet vendu en ligne quand le voyage est terminé.
 * - Un voyage « compte » quand il est terminé et porte au moins un billet en ligne non encore facturé.
 * - Dès que la compagnie atteint N voyages (comm_seuil_voyages, 5 par défaut), la commission
 *   cumulée de ces N voyages devient une tranche « à verser » ; la tranche suivante démarre aussitôt.
 * - Si N n'est pas atteint, la tranche est due 30 jours après son premier voyage.
 * - Non versée : rappel chaque jour, avertissement à 7 jours, suspension à 14 jours.
 *
 * Source unique de vérité, utilisée par les commandes planifiées, les écrans et le webhook PAPI.
 */
class CommissionService
{
    /** Jours après la tranche avant l'avertissement. */
    public const AVERTISSEMENT_JOURS = 7;

    /** Jours après la tranche avant la suspension (voyages masqués des clients). */
    public const GRACE_DAYS = 14;

    /** Une tranche incomplète est due au bout de ce nombre de jours après son 1er voyage. */
    public const DELAI_MAX_JOURS = 30;

    public const SEUIL_DEFAUT = 5;

    public function __construct(private PapiService $papi)
    {
    }

    // ---------------------------------------------------------------------
    // Journal
    // ---------------------------------------------------------------------

    public function journaliser(int $compId, string $action, string $message, ?int $collId = null, ?int $utilId = null, string $acteur = CollecteJournal::ACTEUR_SYSTEME): void
    {
        try {
            CollecteJournal::create([
                'comp_id'    => $compId,
                'coll_id'    => $collId,
                'util_id'    => $utilId,
                'cj_acteur'  => $acteur,
                'cj_action'  => $action,
                'cj_message' => $message,
            ]);
        } catch (\Throwable $e) {
            // Le journal ne doit jamais bloquer une opération financière
            Log::error('Journal commission : écriture impossible', ['erreur' => $e->getMessage()]);
        }
    }

    public function journal(int $compId, int $limite = 50): array
    {
        return CollecteJournal::with('utilisateur:util_id,util_nom,util_prenom')
            ->where('comp_id', $compId)
            ->orderByDesc('created_at')->orderByDesc('cj_id')
            ->limit($limite)
            ->get()
            ->map(fn (CollecteJournal $j) => $j->enTableau())
            ->all();
    }

    // ---------------------------------------------------------------------
    // Tranches
    // ---------------------------------------------------------------------

    /**
     * Voyages terminés de la compagnie, pas encore comptés, avec au moins un billet en ligne
     * dont la commission est figée et pas encore facturée. Triés du plus ancien au plus récent.
     */
    private function voyagesACompter(int $compId)
    {
        return DB::table('fandrio_app.voyages as v')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->where('t.comp_id', $compId)
            ->where('v.voyage_statut', 3)
            ->whereNull('v.coll_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('fandrio_app.reservations as r')
                    ->whereColumn('r.voyage_id', 'v.voyage_id')
                    ->whereIn('r.res_statut', [2, 6])
                    ->where('r.res_canal', 1)
                    ->whereNull('r.coll_id')
                    ->whereNotNull('r.res_commission');
            })
            ->orderBy('v.voyage_date')->orderBy('v.voyage_heure_depart')->orderBy('v.voyage_id')
            ->get(['v.voyage_id', 'v.voyage_date']);
    }

    /** Tranche en cours (pas encore à verser) : voyages comptés, commission cumulée, échéance. */
    public function trancheEnCours(Compagnie $compagnie): array
    {
        $voyages = $this->voyagesACompter($compagnie->comp_id);
        $seuil = (int) ($compagnie->comm_seuil_voyages ?: self::SEUIL_DEFAUT);

        $commission = $voyages->isEmpty() ? 0.0 : (float) Reservation::whereIn('voyage_id', $voyages->pluck('voyage_id'))
            ->whereIn('res_statut', [2, 6])->where('res_canal', 1)
            ->whereNull('coll_id')->whereNotNull('res_commission')
            ->sum('res_commission');

        $premier = $voyages->first()?->voyage_date;
        $limite = $premier ? Carbon::parse($premier)->addDays(self::DELAI_MAX_JOURS) : null;

        return [
            'nb_voyages'          => min($voyages->count(), $seuil),
            'seuil'               => $seuil,
            'commission_cumulee'  => round($commission, 2),
            'premier_voyage'      => $premier ? Carbon::parse($premier)->format('d/m/Y') : null,
            'date_limite'         => $limite?->format('d/m/Y'),
        ];
    }

    /**
     * Crée les tranches dues pour une compagnie (idempotent). Retourne les tranches créées.
     * @return Collecte[]
     */
    public function genererTranches(Compagnie $compagnie): array
    {
        if (!$compagnie->comm_actif) {
            return [];
        }

        $seuil = (int) ($compagnie->comm_seuil_voyages ?: self::SEUIL_DEFAUT);
        $creees = [];

        // Verrou par compagnie : deux exécutions simultanées ne facturent jamais deux fois
        DB::transaction(function () use ($compagnie, $seuil, &$creees) {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [4242, $compagnie->comp_id]);

            $voyages = $this->voyagesACompter($compagnie->comp_id);

            // 1. Tranches complètes de N voyages (rattrape aussi un éventuel retard)
            while ($voyages->count() >= $seuil) {
                $creees[] = $this->creerTranche($compagnie, $voyages->take($seuil), Collecte::MOTIF_SEUIL);
                $voyages = $voyages->slice($seuil)->values();
            }

            // 2. Tranche incomplète dont le 1er voyage date de 30 jours ou plus
            if ($voyages->isNotEmpty()
                && Carbon::parse($voyages->first()->voyage_date)->addDays(self::DELAI_MAX_JOURS)->lte(today())) {
                $creees[] = $this->creerTranche($compagnie, $voyages, Collecte::MOTIF_DELAI);
            }
        });

        // Notifications et lien de paiement après validation de la transaction
        foreach ($creees as $collecte) {
            $this->apresCreation($compagnie, $collecte);
        }

        return $creees;
    }

    private function creerTranche(Compagnie $compagnie, $voyages, string $motif): Collecte
    {
        $ids = $voyages->pluck('voyage_id')->all();

        $billets = Reservation::whereIn('voyage_id', $ids)
            ->whereIn('res_statut', [2, 6])->where('res_canal', 1)
            ->whereNull('coll_id')->whereNotNull('res_commission')
            ->get(['res_id', 'montant_total', 'nb_voyageurs', 'res_commission']);

        $collecte = Collecte::create([
            'comp_id'                 => $compagnie->comp_id,
            'coll_periode_debut'      => $voyages->first()->voyage_date,
            'coll_periode_fin'        => $voyages->last()->voyage_date,
            'coll_montant_brut'       => round($billets->sum(fn ($r) => (float) $r->montant_total), 2),
            'coll_montant_commission' => round($billets->sum(fn ($r) => (float) $r->res_commission), 2),
            'coll_taux'               => 5,
            'coll_nb_reservations'    => $billets->count(),
            'coll_nb_billets'         => (int) $billets->sum(fn ($r) => (int) $r->nb_voyageurs),
            'coll_nb_voyages'         => count($ids),
            'coll_motif'              => $motif,
            'coll_statut'             => Collecte::EN_ATTENTE,
            'coll_date_prevue'        => today()->toDateString(),
            'coll_mode'               => Collecte::MODE_MANUEL,
        ]);

        // Composition figée : voyages et billets rattachés à la tranche
        DB::table('fandrio_app.voyages')->whereIn('voyage_id', $ids)->update(['coll_id' => $collecte->coll_id]);
        Reservation::whereIn('res_id', $billets->pluck('res_id'))->update(['coll_id' => $collecte->coll_id]);

        $raison = $motif === Collecte::MOTIF_SEUIL
            ? count($ids) . ' voyages atteints'
            : self::DELAI_MAX_JOURS . ' jours écoulés (' . count($ids) . ' voyage' . (count($ids) > 1 ? 's' : '') . ')';
        $this->journaliser($compagnie->comp_id, 'tranche_creee',
            "Commission à verser : {$this->ariary($collecte->coll_montant_commission)} — {$raison}.", $collecte->coll_id);

        return $collecte;
    }

    private function apresCreation(Compagnie $compagnie, Collecte $collecte): void
    {
        $montant = $this->ariary($collecte->coll_montant_commission);
        $nb = $collecte->coll_nb_voyages;
        $auto = $compagnie->comm_mode_versement === 'automatique';

        // Mode automatique : le lien de paiement est préparé tout de suite
        if ($auto && $this->papi->estConfigure()) {
            try {
                $this->creerLien($collecte);
                $this->journaliser($compagnie->comp_id, 'lien_envoye',
                    "Lien de paiement envoyé automatiquement ({$montant}).", $collecte->coll_id);
            } catch (\Throwable $e) {
                Log::warning('Commission : lien automatique impossible', ['coll_id' => $collecte->coll_id, 'erreur' => $e->getMessage()]);
            }
        }

        $this->notifierAdmins($compagnie->comp_id,
            'Commission FANDRIO à verser',
            "Vos {$nb} dernier" . ($nb > 1 ? 's' : '') . " voyage" . ($nb > 1 ? 's' : '') . " sont terminés : la commission de {$montant} est à verser. "
            . ($auto ? 'Votre lien de paiement est prêt dans Portefeuille, réglez-le en un clic.' : 'Allez dans Portefeuille puis touchez « Verser la commission ».'));
    }

    /** Crée (ou recrée) le lien de paiement PAPI de la tranche. */
    public function creerLien(Collecte $collecte): array
    {
        $lien = $this->papi->creerLienPaiement([
            'montant'          => (float) $collecte->coll_montant_commission,
            'reference'        => 'COLLECTE-' . $collecte->coll_id,
            'description'      => 'Commission FANDRIO — ' . $collecte->coll_nb_voyages . ' voyage(s) du '
                . $collecte->coll_periode_debut->format('d/m/Y') . ' au ' . $collecte->coll_periode_fin->format('d/m/Y'),
            'notification_url' => url('/api/papi/webhook/commission'),
        ]);

        $collecte->update([
            'coll_mode'                => Collecte::MODE_PAPI,
            'coll_papi_transaction_id' => $lien['transaction_id'],
            'coll_papi_url'            => $lien['url'],
        ]);

        return $lien;
    }

    // ---------------------------------------------------------------------
    // Rappels, avertissement, suspension
    // ---------------------------------------------------------------------

    /** Jours écoulés depuis que la tranche est à verser. */
    public static function joursEcoules(Collecte $c): int
    {
        return (int) $c->coll_date_prevue->copy()->startOfDay()->diffInDays(today());
    }

    /** État lisible d'une tranche non versée : a_verser | averti | suspendu. */
    public static function etat(Collecte $c): string
    {
        if ((int) $c->coll_statut === Collecte::CONFIRMEE) {
            return 'verse';
        }
        $jours = self::joursEcoules($c);
        return $jours > self::GRACE_DAYS ? 'suspendu' : ($jours >= self::AVERTISSEMENT_JOURS ? 'averti' : 'a_verser');
    }

    /**
     * Rappel quotidien de chaque tranche non versée, avertissement à 7 jours.
     * Une seule relance par jour et par tranche.
     */
    public function envoyerRappels(): array
    {
        $bilan = ['rappels' => 0, 'avertissements' => 0];

        $collectes = Collecte::with('compagnie')->where('coll_statut', Collecte::EN_ATTENTE)->get();
        foreach ($collectes as $c) {
            $jours = self::joursEcoules($c);
            if ($jours < 1) {
                continue; // la notification du jour de création suffit
            }
            $montant = $this->ariary($c->coll_montant_commission);
            $suspension = $c->coll_date_prevue->copy()->addDays(self::GRACE_DAYS + 1)->format('d/m/Y');

            if ($jours >= self::AVERTISSEMENT_JOURS && !$c->coll_avertie_le) {
                $this->notifierAdmins($c->comp_id, 'Avertissement : commission non versée',
                    "La commission de {$montant} n'est toujours pas versée depuis {$jours} jours. Sans versement, vos voyages seront masqués aux clients à partir du {$suspension}.");
                $c->update(['coll_avertie_le' => now(), 'coll_derniere_relance' => today()]);
                $this->journaliser($c->comp_id, 'avertissement', "Avertissement envoyé : {$montant} non versés depuis {$jours} jours (suspension prévue le {$suspension}).", $c->coll_id);
                $bilan['avertissements']++;
                continue;
            }

            if ($c->coll_derniere_relance && $c->coll_derniere_relance->isToday()) {
                continue;
            }
            $this->notifierAdmins($c->comp_id, 'Rappel : commission à verser',
                "La commission FANDRIO de {$montant} est à verser depuis {$jours} jour" . ($jours > 1 ? 's' : '') . ". Versez-la depuis Portefeuille.");
            $c->update(['coll_derniere_relance' => today()]);
            $this->journaliser($c->comp_id, 'rappel', "Rappel envoyé : {$montant} à verser depuis {$jours} jour" . ($jours > 1 ? 's' : '') . '.', $c->coll_id);
            $bilan['rappels']++;
        }

        return $bilan;
    }

    /** Compagnies ayant une tranche non versée depuis plus de 14 jours. */
    public function compagniesEnRetard(): array
    {
        return Collecte::where('coll_statut', Collecte::EN_ATTENTE)
            ->whereDate('coll_date_prevue', '<', now()->subDays(self::GRACE_DAYS)->toDateString())
            ->distinct()->pluck('comp_id')->all();
    }

    /** Réactive la compagnie si elle n'a plus aucune tranche en retard. */
    public function reactiverCompagnieSiAJour(int $compId): void
    {
        $encoreEnRetard = Collecte::where('comp_id', $compId)
            ->where('coll_statut', Collecte::EN_ATTENTE)
            ->whereDate('coll_date_prevue', '<', now()->subDays(self::GRACE_DAYS)->toDateString())
            ->exists();

        if (!$encoreEnRetard) {
            $n = Compagnie::where('comp_id', $compId)
                ->where('comp_suspendu_commission', true)
                ->update(['comp_suspendu_commission' => false]);
            if ($n) {
                $this->journaliser($compId, 'reactivation', 'Compagnie réactivée : ses voyages sont de nouveau visibles des clients.');
            }
        }
    }

    /**
     * Marque une tranche comme versée et réactive la compagnie si à jour.
     * Idempotent : ne fait rien si elle est déjà versée.
     */
    public function marquerCollecteReglee(Collecte $collecte, string $mode, ?string $papiTransactionId = null, ?int $confirmePar = null): bool
    {
        if ((int) $collecte->coll_statut === Collecte::CONFIRMEE) {
            return false;
        }

        $collecte->update([
            'coll_statut'              => Collecte::CONFIRMEE,
            'coll_date_confirmation'   => now(),
            'coll_paye_le'             => now(),
            'coll_mode'                => $mode,
            'coll_confirme_par'        => $confirmePar ?? $collecte->coll_confirme_par,
            'coll_papi_transaction_id' => $papiTransactionId ?? $collecte->coll_papi_transaction_id,
        ]);

        $montant = $this->ariary($collecte->coll_montant_commission);
        if ($confirmePar) {
            $this->journaliser($collecte->comp_id, 'versement_confirme',
                "Versement de {$montant} confirmé par FANDRIO (reçu hors paiement en ligne).", $collecte->coll_id, $confirmePar, CollecteJournal::ACTEUR_ADMIN);
        } else {
            $lancePar = $collecte->initiePar;
            $qui = $lancePar ? ' (lancé par ' . trim($lancePar->util_prenom . ' ' . $lancePar->util_nom) . ')' : '';
            $this->journaliser($collecte->comp_id, 'versement_recu',
                "Versement de {$montant} reçu par paiement en ligne{$qui}.", $collecte->coll_id, $collecte->coll_initie_par, CollecteJournal::ACTEUR_PAIEMENT);
        }

        $this->notifierAdmins($collecte->comp_id, 'Commission versée — merci',
            "Votre versement de {$montant} a bien été reçu par FANDRIO.");

        $this->reactiverCompagnieSiAJour($collecte->comp_id);

        return true;
    }

    // ---------------------------------------------------------------------
    // Outils
    // ---------------------------------------------------------------------

    public function notifierAdmins(int $compId, string $titre, string $message): int
    {
        $admins = Utilisateur::where('comp_id', $compId)->where('util_role', 2)->where('util_statut', 1)->pluck('util_id');
        foreach ($admins as $id) {
            try {
                NotificationService::envoyer([
                    'type'              => 8, // commission
                    'destinataire_type' => 2, // compagnie
                    'destinataire_id'   => $id,
                    'titre'             => $titre,
                    'message'           => $message,
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        return $admins->count();
    }

    public function ariary($montant): string
    {
        return number_format((float) $montant, 0, ',', ' ') . ' Ar';
    }

    /** Format commun d'une tranche pour les écrans. */
    public function formater(Collecte $c): array
    {
        $jours = (int) $c->coll_statut === Collecte::EN_ATTENTE ? self::joursEcoules($c) : 0;
        $initie = $c->relationLoaded('initiePar') ? $c->initiePar : null;
        $confirme = $c->relationLoaded('confirmePar') ? $c->confirmePar : null;

        return [
            'coll_id'            => $c->coll_id,
            'comp_id'            => $c->comp_id,
            'comp_nom'           => $c->relationLoaded('compagnie') ? $c->compagnie?->comp_nom : null,
            'periode_debut'      => $c->coll_periode_debut->format('d/m/Y'),
            'periode_fin'        => $c->coll_periode_fin->format('d/m/Y'),
            'periode_debut_raw'  => $c->coll_periode_debut->toDateString(),
            'periode_fin_raw'    => $c->coll_periode_fin->toDateString(),
            'nb_voyages'         => (int) $c->coll_nb_voyages,
            'motif'              => $c->coll_motif,
            'montant_brut'       => (float) $c->coll_montant_brut,
            'montant_commission' => (float) $c->coll_montant_commission,
            'taux'               => (float) $c->coll_taux,
            'nb_reservations'    => $c->coll_nb_reservations,
            'nb_billets'         => $c->coll_nb_billets,
            'statut'             => $c->coll_statut,
            'statut_label'       => (int) $c->coll_statut === Collecte::CONFIRMEE ? 'Versée' : 'À verser',
            'etat'               => self::etat($c),
            'jours_ecoules'      => $jours,
            'date_prevue'        => $c->coll_date_prevue->format('d/m/Y'),
            'avertissement_le'   => $c->coll_date_prevue->copy()->addDays(self::AVERTISSEMENT_JOURS)->format('d/m/Y'),
            'suspension_le'      => $c->coll_date_prevue->copy()->addDays(self::GRACE_DAYS + 1)->format('d/m/Y'),
            'date_confirmation'  => $c->coll_date_confirmation?->format('d/m/Y H:i'),
            'mode'               => $c->coll_mode,
            'lien_paiement'      => (int) $c->coll_statut === Collecte::EN_ATTENTE ? $c->coll_papi_url : null,
            'initie_par'         => $initie ? trim($initie->util_prenom . ' ' . $initie->util_nom) : null,
            'confirme_par'       => $confirme ? trim($confirme->util_prenom . ' ' . $confirme->util_nom) : null,
            'payable'            => (int) $c->coll_statut === Collecte::EN_ATTENTE,
            'en_retard'          => (int) $c->coll_statut === Collecte::EN_ATTENTE && $jours >= self::AVERTISSEMENT_JOURS,
            'created_at'         => $c->created_at?->format('d/m/Y H:i'),
        ];
    }
}
