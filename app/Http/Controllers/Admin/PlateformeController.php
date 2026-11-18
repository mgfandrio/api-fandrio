<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin système : chiffre d'affaires de la plateforme et contrôle des paiements.
 *
 * L'argent des billets va directement sur le compte PAPI de chaque compagnie ;
 * FANDRIO gagne une commission de 5 % sur les ventes EN LIGNE, encaissée par
 * les collectes. Les ventes au guichet sont données pour information.
 */
class PlateformeController extends Controller
{
    private const TAUX_COMMISSION = 0.05;
    private const STATUTS_ENCAISSES = [2, 6];
    private const MAX_JOURS = 366;
    private const MAX_LIGNES = 30000;
    private const HEURES_RETARD_REMBOURSEMENT = 48;

    /** GET /api/admin/chiffre-affaires?du=&au=&comp_id= */
    public function chiffreAffaires(Request $request): JsonResponse
    {
        try {
            [$du, $au] = $this->periode($request);
            $compId = $request->filled('comp_id') ? (int) $request->comp_id : null;
            $base = fn(Carbon $d, Carbon $a) => $this->ventes($d, $a, $compId);
            $guichet = Reservation::CANAL_GUICHET;

            // ── Totaux ──
            $t = $base($du, $au)->selectRaw("
                COALESCE(SUM(CASE WHEN r.res_canal <> ? THEN r.montant_total ELSE 0 END), 0) AS en_ligne,
                COALESCE(SUM(CASE WHEN r.res_canal = ? THEN r.montant_total ELSE 0 END), 0) AS guichet,
                COALESCE(SUM(CASE WHEN r.res_canal <> ? THEN r.nb_voyageurs ELSE 0 END), 0) AS billets_en_ligne,
                COALESCE(SUM(CASE WHEN r.res_canal = ? THEN r.nb_voyageurs ELSE 0 END), 0) AS billets_guichet,
                COUNT(CASE WHEN r.res_canal <> ? THEN 1 END) AS reservations_en_ligne
            ", [$guichet, $guichet, $guichet, $guichet, $guichet])->first();
            $enLigne = (float) $t->en_ligne;
            $commission = round($enLigne * self::TAUX_COMMISSION, 2);

            // ── Période précédente (même logique que le chiffre d'affaires compagnie) ──
            $jours = (int) $du->diffInDays($au) + 1;
            if ($du->day === 1 && $jours <= 31) {
                $precDu = $du->copy()->subMonthNoOverflow();
                $precAu = $au->copy()->subMonthNoOverflow()->endOfDay();
            } else {
                $precDu = $du->copy()->subDays($jours);
                $precAu = $du->copy()->subDay()->endOfDay();
            }
            $enLignePrec = (float) $base($precDu, $precAu)->where('r.res_canal', '<>', $guichet)->sum('r.montant_total');
            $commissionPrec = round($enLignePrec * self::TAUX_COMMISSION, 2);

            // ── Jour par jour (ventes en ligne) ──
            $parJour = $base($du, $au)->where('r.res_canal', '<>', $guichet)
                ->selectRaw("TO_CHAR(r.created_at, 'YYYY-MM-DD') AS jour, SUM(r.montant_total) AS en_ligne, SUM(r.nb_voyageurs) AS billets")
                ->groupBy('jour')->orderBy('jour')->get()
                ->map(fn($l) => ['jour' => $l->jour, 'en_ligne' => (float) $l->en_ligne, 'commission' => round((float) $l->en_ligne * self::TAUX_COMMISSION, 2), 'billets' => (int) $l->billets]);

            // ── Collectes : encaissé sur la période, reste à encaisser (toutes périodes) ──
            $collectes = fn() => DB::table('fandrio_app.collectes as co')->when($compId, fn($q) => $q->where('co.comp_id', $compId));
            $encaisse = $collectes()->where('co.coll_statut', 2)
                ->whereBetween('co.coll_date_confirmation', [$du, $au])
                ->selectRaw('COALESCE(SUM(co.coll_montant_commission), 0) AS montant, COUNT(*) AS nb')->first();
            $attente = $collectes()->where('co.coll_statut', 1)
                ->selectRaw('co.comp_id, SUM(co.coll_montant_commission) AS montant, MIN(co.coll_date_prevue) AS echeance,
                    SUM(CASE WHEN co.coll_date_prevue < CURRENT_DATE THEN co.coll_montant_commission ELSE 0 END) AS en_retard')
                ->groupBy('co.comp_id')->get()->keyBy('comp_id');

            // ── Par compagnie ──
            $ventesCie = $base($du, $au)
                ->groupBy('t.comp_id')
                ->selectRaw('t.comp_id,
                    SUM(CASE WHEN r.res_canal <> ? THEN r.montant_total ELSE 0 END) AS en_ligne,
                    SUM(CASE WHEN r.res_canal = ? THEN r.montant_total ELSE 0 END) AS guichet,
                    SUM(CASE WHEN r.res_canal <> ? THEN r.nb_voyageurs ELSE 0 END) AS billets_en_ligne', [$guichet, $guichet, $guichet])
                ->get()->keyBy('comp_id');
            $compagnies = DB::table('fandrio_app.compagnies')
                ->where('comp_statut', '<>', 3)
                ->when($compId, fn($q) => $q->where('comp_id', $compId))
                ->get(['comp_id', 'comp_nom', 'comp_statut', 'comp_suspendu_commission']);
            $parCompagnie = $compagnies->map(function ($c) use ($ventesCie, $attente) {
                $v = $ventesCie->get($c->comp_id);
                $a = $attente->get($c->comp_id);
                $enLigne = (float) ($v->en_ligne ?? 0);
                return [
                    'comp_id' => (int) $c->comp_id,
                    'nom' => $c->comp_nom,
                    'statut' => (int) $c->comp_statut,
                    'suspendu' => (bool) $c->comp_suspendu_commission,
                    'en_ligne' => $enLigne,
                    'guichet' => (float) ($v->guichet ?? 0),
                    'billets_en_ligne' => (int) ($v->billets_en_ligne ?? 0),
                    'commission' => round($enLigne * self::TAUX_COMMISSION, 2),
                    'a_encaisser' => (float) ($a->montant ?? 0),
                    'en_retard' => (float) ($a->en_retard ?? 0),
                    'echeance' => $a?->echeance ? Carbon::parse($a->echeance)->toDateString() : null,
                ];
            })
                // Compagnies sans activité ni dette en bas de liste
                ->sortByDesc(fn($c) => [$c['en_ligne'] + $c['guichet'] > 0 || $c['a_encaisser'] > 0 ? 1 : 0, $c['commission'], $c['guichet']])
                ->values();

            // ── Par opérateur (ventes en ligne) ──
            $typesPaiement = DB::table('fandrio_app.types_paiement')->pluck('type_paie_nom', 'type_paie_id');
            $parOperateur = $base($du, $au)->where('r.res_canal', '<>', $guichet)
                ->groupBy('r.type_paie_id')
                ->selectRaw('r.type_paie_id, SUM(r.montant_total) AS montant, COUNT(*) AS nb')
                ->orderByRaw('SUM(r.montant_total) DESC')->get()
                ->map(fn($l) => ['nom' => $typesPaiement[$l->type_paie_id] ?? 'Non défini', 'montant' => (float) $l->montant, 'nb' => (int) $l->nb]);

            return response()->json([
                'statut' => true,
                'data' => [
                    'periode' => ['du' => $du->toDateString(), 'au' => $au->toDateString(), 'jours' => $jours],
                    'taux_commission' => self::TAUX_COMMISSION * 100,
                    'totaux' => [
                        'en_ligne' => $enLigne,
                        'guichet' => (float) $t->guichet,
                        'commission' => $commission,
                        'billets_en_ligne' => (int) $t->billets_en_ligne,
                        'billets_guichet' => (int) $t->billets_guichet,
                        'reservations_en_ligne' => (int) $t->reservations_en_ligne,
                        'encaisse' => (float) $encaisse->montant,
                        'nb_collectes_encaissees' => (int) $encaisse->nb,
                        'a_encaisser' => (float) $attente->sum('montant'),
                        'en_retard' => (float) $attente->sum('en_retard'),
                    ],
                    'precedent' => [
                        'du' => $precDu->toDateString(),
                        'au' => $precAu->toDateString(),
                        'commission' => $commissionPrec,
                        'variation' => $commissionPrec > 0 ? round(($commission - $commissionPrec) / $commissionPrec * 100, 1) : null,
                    ],
                    'par_jour' => $parJour,
                    'par_compagnie' => $parCompagnie,
                    'par_operateur' => $parOperateur,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** GET /api/admin/chiffre-affaires/ventes : détail pour l'export Excel. */
    public function detail(Request $request): JsonResponse
    {
        try {
            [$du, $au] = $this->periode($request);
            $compId = $request->filled('comp_id') ? (int) $request->comp_id : null;

            $ventes = $this->ventes($du, $au, $compId)
                ->where('r.res_canal', '<>', Reservation::CANAL_GUICHET)
                ->join('fandrio_app.compagnies as c', 'c.comp_id', '=', 't.comp_id')
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->leftJoin('fandrio_app.types_paiement as tp', 'tp.type_paie_id', '=', 'r.type_paie_id')
                ->orderBy('r.created_at')
                ->limit(self::MAX_LIGNES + 1)
                ->get(['r.res_numero', 'r.created_at', 'r.nb_voyageurs', 'r.montant_total', 'r.numero_paiement', 'c.comp_nom', 'pd.pro_nom as depart', 'pa.pro_nom as arrivee', 'tp.type_paie_nom as operateur']);
            if ($ventes->count() > self::MAX_LIGNES) {
                throw new \InvalidArgumentException('Trop de ventes sur cette période pour un seul fichier : choisissez une période plus courte.');
            }

            $collectes = DB::table('fandrio_app.collectes as co')
                ->join('fandrio_app.compagnies as c', 'c.comp_id', '=', 'co.comp_id')
                ->where('co.coll_statut', 2)
                ->whereBetween('co.coll_date_confirmation', [$du, $au])
                ->when($compId, fn($q) => $q->where('co.comp_id', $compId))
                ->orderBy('co.coll_date_confirmation')
                ->get(['c.comp_nom', 'co.coll_periode_debut', 'co.coll_periode_fin', 'co.coll_montant_brut', 'co.coll_montant_commission', 'co.coll_date_confirmation', 'co.coll_mode']);

            return response()->json([
                'statut' => true,
                'data' => [
                    'ventes' => $ventes->map(fn($l) => [
                        'date' => Carbon::parse($l->created_at)->format('Y-m-d H:i'),
                        'billet' => $l->res_numero,
                        'compagnie' => $l->comp_nom,
                        'trajet' => "{$l->depart} → {$l->arrivee}",
                        'operateur' => $l->operateur ?: 'Non défini',
                        'reference' => $l->numero_paiement,
                        'billets' => (int) $l->nb_voyageurs,
                        'montant' => (float) $l->montant_total,
                        'commission' => round((float) $l->montant_total * self::TAUX_COMMISSION, 2),
                    ]),
                    'collectes' => $collectes->map(fn($c) => [
                        'compagnie' => $c->comp_nom,
                        'periode' => Carbon::parse($c->coll_periode_debut)->format('d/m/Y') . ' – ' . Carbon::parse($c->coll_periode_fin)->format('d/m/Y'),
                        'ventes' => (float) $c->coll_montant_brut,
                        'commission' => (float) $c->coll_montant_commission,
                        'payee_le' => Carbon::parse($c->coll_date_confirmation)->format('Y-m-d H:i'),
                        'mode' => $c->coll_mode === 'papi' ? 'En ligne (PAPI)' : 'Manuel',
                    ]),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /**
     * GET /api/admin/controle-paiements
     * Cas à vérifier : paiements arrivés trop tard à rembourser, clients de voyages
     * annulés à rembourser, billets en ligne confirmés sans référence de paiement.
     */
    public function controlePaiements(): JsonResponse
    {
        try {
            $typesPaiement = DB::table('fandrio_app.types_paiement')->pluck('type_paie_nom', 'type_paie_id');
            $base = fn() => DB::table('fandrio_app.reservations as r')
                ->join('fandrio_app.voyages as v', 'v.voyage_id', '=', 'r.voyage_id')
                ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
                ->join('fandrio_app.compagnies as c', 'c.comp_id', '=', 't.comp_id')
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->leftJoin('fandrio_app.utilisateurs as u', 'u.util_id', '=', 'r.util_id');
            $colonnes = ['r.res_id', 'r.res_numero', 'r.montant_total', 'r.montant_avance', 'r.res_remb_montant', 'r.res_remb_motif', 'r.numero_paiement', 'r.type_paie_id', 'r.created_at', 'r.updated_at',
                'v.voyage_statut', 'v.voyage_date', 'v.voyage_heure_depart', 'c.comp_id', 'c.comp_nom', 'pd.pro_nom as depart', 'pa.pro_nom as arrivee', 'u.util_prenom', 'u.util_nom', 'u.util_phone'];

            $formater = function ($l, string $cas) use ($typesPaiement) {
                // Depuis quand : dernière mise à jour de la réservation (passage en « à rembourser »)
                $depuis = Carbon::parse($cas === 'sans_reference' ? $l->created_at : $l->updated_at);
                return [
                    'cas' => $cas,
                    'res_id' => (int) $l->res_id,
                    'billet' => $l->res_numero,
                    'compagnie' => $l->comp_nom,
                    'comp_id' => (int) $l->comp_id,
                    'trajet' => "{$l->depart} → {$l->arrivee}",
                    'depart' => Carbon::parse($l->voyage_date)->format('d/m/Y') . ' ' . substr((string) $l->voyage_heure_depart, 0, 5),
                    'client' => trim("{$l->util_prenom} {$l->util_nom}"),
                    'telephone' => $l->util_phone,
                    'montant' => (float) ($cas === 'sans_reference' ? $l->montant_total : ($l->res_remb_montant ?? $l->montant_avance)),
                    'operateur' => $l->type_paie_id ? ($typesPaiement[$l->type_paie_id] ?? null) : null,
                    'reference' => $l->numero_paiement,
                    'depuis' => $depuis->format('Y-m-d H:i'),
                    'heures' => (int) $depuis->diffInHours(now()),
                ];
            };

            // Remboursements en attente, classés par motif (enregistré, sinon déduit)
            $aRembourser = $base()->where('r.res_remb_statut', 1)->orderBy('r.updated_at')->get($colonnes)
                ->map(function ($l) use ($formater) {
                    $motif = (int) $l->res_remb_motif ?: ((int) $l->voyage_statut === 4 ? Reservation::REMB_VOYAGE_ANNULE : Reservation::REMB_PAIEMENT_TARDIF);
                    return $formater($l, $motif === Reservation::REMB_PAIEMENT_TARDIF ? 'paiement_tardif' : 'voyage_annule');
                });

            // Billets en ligne confirmés sans référence de transaction PAPI
            $sansReference = $base()
                ->whereIn('r.res_statut', self::STATUTS_ENCAISSES)
                ->where('r.res_canal', '<>', Reservation::CANAL_GUICHET)
                ->where(fn($q) => $q->whereNull('r.numero_paiement')->orWhere('r.numero_paiement', ''))
                ->orderBy('r.created_at')
                ->get($colonnes)
                ->map(fn($l) => $formater($l, 'sans_reference'));

            $cas = $aRembourser->concat($sansReference)->values();
            $resume = fn(string $c) => [
                'nb' => $cas->where('cas', $c)->count(),
                'montant' => (float) $cas->where('cas', $c)->sum('montant'),
                'en_retard' => $cas->where('cas', $c)->where('heures', '>=', self::HEURES_RETARD_REMBOURSEMENT)->count(),
                'plus_ancien_heures' => (int) ($cas->where('cas', $c)->max('heures') ?? 0),
            ];

            return response()->json([
                'statut' => true,
                'data' => [
                    'total' => $cas->count(),
                    'seuil_retard_heures' => self::HEURES_RETARD_REMBOURSEMENT,
                    'resume' => [
                        'paiement_tardif' => $resume('paiement_tardif'),
                        'voyage_annule' => $resume('voyage_annule'),
                        'sans_reference' => $resume('sans_reference'),
                    ],
                    'cas' => $cas,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** Ventes encaissées (statuts 2 et 6), toutes compagnies ou une seule. */
    private function ventes(Carbon $du, Carbon $au, ?int $compId): Builder
    {
        return DB::table('fandrio_app.reservations as r')
            ->join('fandrio_app.voyages as v', 'v.voyage_id', '=', 'r.voyage_id')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->whereIn('r.res_statut', self::STATUTS_ENCAISSES)
            ->whereBetween('r.created_at', [$du, $au])
            ->when($compId, fn($q) => $q->where('t.comp_id', $compId));
    }

    private function periode(Request $request): array
    {
        $request->validate([
            'du' => 'nullable|date_format:Y-m-d',
            'au' => 'nullable|date_format:Y-m-d',
            'comp_id' => 'nullable|integer',
        ]);
        $du = $request->filled('du') ? Carbon::parse($request->du)->startOfDay() : now()->startOfMonth();
        $au = $request->filled('au') ? Carbon::parse($request->au)->endOfDay() : now()->endOfDay();
        if ($au->lt($du)) {
            throw new \InvalidArgumentException('La date de fin doit être après la date de début.');
        }
        if ($du->diffInDays($au) + 1 > self::MAX_JOURS) {
            throw new \InvalidArgumentException('Choisissez une période d’un an au maximum.');
        }
        return [$du, $au];
    }
}
