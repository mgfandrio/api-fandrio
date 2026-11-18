<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Reservation\Reservation;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Chiffre d'affaires de la compagnie sur une période libre.
 *
 * Ventes = billets encaissés (statuts 2 et 6), datés du jour de la vente.
 * Un billet remboursé est annulé (statut 4) : il n'est donc PAS compté
 * dans les ventes ; les remboursements sont donnés à titre d'information.
 * Commission FANDRIO : 5 % des ventes en ligne (le guichet n'en paie pas).
 */
class ChiffreAffairesController extends Controller
{
    private const TAUX_COMMISSION = 0.05;
    private const STATUTS_ENCAISSES = [2, 6];
    private const MAX_JOURS = 366;
    private const MAX_LIGNES = 20000;

    /** GET /api/adminCompagnie/chiffre-affaires?du=&au=&traj_id=&canal= */
    public function resume(Request $request): JsonResponse
    {
        try {
            [$du, $au] = $this->periode($request);
            $base = fn(Carbon $d, Carbon $a) => $this->base($request, $d, $a);

            // ── Totaux ──
            $t = $base($du, $au)->selectRaw("
                COALESCE(SUM(r.montant_total), 0) AS brut,
                COALESCE(SUM(CASE WHEN r.res_canal = ? THEN r.montant_total ELSE 0 END), 0) AS guichet,
                COALESCE(SUM(r.nb_voyageurs), 0) AS billets,
                COALESCE(SUM(CASE WHEN r.res_canal = ? THEN r.nb_voyageurs ELSE 0 END), 0) AS billets_guichet,
                COUNT(*) AS reservations,
                COUNT(DISTINCT r.voyage_id) AS voyages
            ", [Reservation::CANAL_GUICHET, Reservation::CANAL_GUICHET])->first();

            $brut = (float) $t->brut;
            $guichet = (float) $t->guichet;
            $enLigne = round($brut - $guichet, 2);
            $commission = round($enLigne * self::TAUX_COMMISSION, 2);

            // ── Période précédente de même durée (comparaison) ──
            // Période commençant un 1er du mois : mêmes dates le mois précédent
            // (ex. 1er–18 nov. comparé au 1er–18 oct.) ; sinon la durée juste avant.
            $jours = (int) $du->diffInDays($au) + 1;
            if ($du->day === 1 && $jours <= 31) {
                $precDu = $du->copy()->subMonthNoOverflow();
                $precAu = $au->copy()->subMonthNoOverflow()->endOfDay();
            } else {
                $precDu = $du->copy()->subDays($jours);
                $precAu = $du->copy()->subDay()->endOfDay();
            }
            $brutPrecedent = (float) $base($precDu, $precAu)->sum('r.montant_total');

            // ── Jour par jour ──
            $parJour = $base($du, $au)
                ->selectRaw("TO_CHAR(r.created_at, 'YYYY-MM-DD') AS jour,
                    SUM(CASE WHEN r.res_canal = ? THEN r.montant_total ELSE 0 END) AS guichet,
                    SUM(CASE WHEN r.res_canal = ? THEN 0 ELSE r.montant_total END) AS en_ligne,
                    SUM(r.nb_voyageurs) AS billets", [Reservation::CANAL_GUICHET, Reservation::CANAL_GUICHET])
                ->groupBy('jour')->orderBy('jour')->get()
                ->map(fn($l) => ['jour' => $l->jour, 'guichet' => (float) $l->guichet, 'en_ligne' => (float) $l->en_ligne, 'billets' => (int) $l->billets]);

            // ── Par trajet (avec remplissage des voyages vendus) ──
            $placesParTrajet = DB::table('fandrio_app.voyages as v2')
                ->join('fandrio_app.voitures as vo', 'vo.voit_id', '=', 'v2.voit_id')
                ->whereIn('v2.voyage_id', $base($du, $au)->select('r.voyage_id'))
                ->groupBy('v2.traj_id')
                ->selectRaw('v2.traj_id, SUM(vo.voit_places) AS places')
                ->pluck('places', 'v2.traj_id');

            $parTrajet = $base($du, $au)
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->groupBy('t.traj_id', 'pd.pro_nom', 'pa.pro_nom', 't.traj_tarif')
                ->selectRaw("t.traj_id, pd.pro_nom AS depart, pa.pro_nom AS arrivee, t.traj_tarif AS tarif,
                    COUNT(DISTINCT r.voyage_id) AS voyages, SUM(r.nb_voyageurs) AS billets,
                    SUM(CASE WHEN r.res_canal = ? THEN r.montant_total ELSE 0 END) AS guichet,
                    SUM(CASE WHEN r.res_canal = ? THEN 0 ELSE r.montant_total END) AS en_ligne", [Reservation::CANAL_GUICHET, Reservation::CANAL_GUICHET])
                ->orderByRaw('SUM(r.montant_total) DESC')->get()
                ->map(function ($l) use ($placesParTrajet) {
                    $places = (int) ($placesParTrajet[$l->traj_id] ?? 0);
                    return [
                        'traj_id' => (int) $l->traj_id,
                        'trajet' => "{$l->depart} → {$l->arrivee}",
                        'tarif' => (float) $l->tarif,
                        'voyages' => (int) $l->voyages,
                        'billets' => (int) $l->billets,
                        'remplissage' => $places > 0 ? (int) round($l->billets / $places * 100) : null,
                        'guichet' => (float) $l->guichet,
                        'en_ligne' => (float) $l->en_ligne,
                        'total' => (float) $l->guichet + (float) $l->en_ligne,
                    ];
                });

            // ── Par moyen de paiement ──
            $parPaiement = $base($du, $au)
                ->leftJoin('fandrio_app.types_paiement as tp', 'tp.type_paie_id', '=', 'r.type_paie_id')
                ->groupBy('r.type_paie_id', 'tp.type_paie_nom')
                ->selectRaw('r.type_paie_id, tp.type_paie_nom AS nom, SUM(r.montant_total) AS montant, COUNT(*) AS nb, MAX(r.res_canal) AS canal')
                ->orderByRaw('SUM(r.montant_total) DESC')->get()
                ->map(fn($l) => ['type_id' => $l->type_paie_id, 'nom' => $this->libellePaiement($l->nom), 'montant' => (float) $l->montant, 'nb' => (int) $l->nb]);

            // ── Ventes au guichet par vendeur ──
            $parVendeur = $base($du, $au)
                ->where('r.res_canal', Reservation::CANAL_GUICHET)
                ->join('fandrio_app.utilisateurs as u', 'u.util_id', '=', 'r.util_id')
                ->groupBy('u.util_id', 'u.util_nom', 'u.util_prenom')
                ->selectRaw('u.util_id, u.util_nom AS nom, u.util_prenom AS prenom, SUM(r.nb_voyageurs) AS billets, SUM(r.montant_total) AS montant')
                ->orderByRaw('SUM(r.montant_total) DESC')->get()
                ->map(fn($l) => ['util_id' => (int) $l->util_id, 'nom' => trim("{$l->prenom} {$l->nom}"), 'billets' => (int) $l->billets, 'montant' => (float) $l->montant]);

            // ── Remboursements (information : billets déjà exclus des ventes) ──
            $rembourse = $this->remboursements($request)
                ->where('r.res_remb_statut', 2)
                ->whereBetween('r.res_remb_date', [$du, $au])
                ->selectRaw('COALESCE(SUM(COALESCE(r.res_remb_montant, r.montant_total)), 0) AS montant, COUNT(*) AS nb, COALESCE(SUM(r.nb_voyageurs), 0) AS billets')
                ->first();
            $aRembourser = $this->remboursements($request)
                ->where('r.res_remb_statut', 1)
                ->selectRaw('COALESCE(SUM(COALESCE(r.res_remb_montant, r.montant_total)), 0) AS montant, COUNT(*) AS nb')
                ->first();

            return response()->json([
                'statut' => true,
                'data' => [
                    'periode' => ['du' => $du->toDateString(), 'au' => $au->toDateString(), 'jours' => $jours],
                    'taux_commission' => self::TAUX_COMMISSION * 100,
                    'totaux' => [
                        'brut' => $brut,
                        'guichet' => $guichet,
                        'en_ligne' => $enLigne,
                        'commission' => $commission,
                        'net' => round($brut - $commission, 2),
                        'billets' => (int) $t->billets,
                        'billets_guichet' => (int) $t->billets_guichet,
                        'billets_en_ligne' => (int) $t->billets - (int) $t->billets_guichet,
                        'reservations' => (int) $t->reservations,
                        'voyages' => (int) $t->voyages,
                    ],
                    'precedent' => [
                        'du' => $precDu->toDateString(),
                        'au' => $precAu->toDateString(),
                        'brut' => $brutPrecedent,
                        'variation' => $brutPrecedent > 0 ? round(($brut - $brutPrecedent) / $brutPrecedent * 100, 1) : null,
                    ],
                    'remboursements' => [
                        'montant' => (float) $rembourse->montant,
                        'nb' => (int) $rembourse->nb,
                        'billets' => (int) $rembourse->billets,
                        'en_attente_montant' => (float) $aRembourser->montant,
                        'en_attente_nb' => (int) $aRembourser->nb,
                    ],
                    'par_jour' => $parJour,
                    'par_trajet' => $parTrajet,
                    'par_paiement' => $parPaiement,
                    'par_vendeur' => $parVendeur,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /**
     * GET /api/adminCompagnie/chiffre-affaires/ventes : détail de chaque vente
     * et de chaque remboursement de la période (export Excel).
     */
    public function ventes(Request $request): JsonResponse
    {
        try {
            [$du, $au] = $this->periode($request);
            $ventes = $this->base($request, $du, $au)
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->leftJoin('fandrio_app.types_paiement as tp', 'tp.type_paie_id', '=', 'r.type_paie_id')
                ->leftJoin('fandrio_app.utilisateurs as u', 'u.util_id', '=', 'r.util_id')
                ->orderBy('r.created_at')
                ->limit(self::MAX_LIGNES + 1)
                ->get([
                    'r.res_numero', 'r.created_at', 'r.res_canal', 'r.nb_voyageurs', 'r.montant_total',
                    'v.voyage_date', 'v.voyage_heure_depart', 'pd.pro_nom as depart', 'pa.pro_nom as arrivee',
                    'tp.type_paie_nom as paiement', 'u.util_nom', 'u.util_prenom',
                ]);
            if ($ventes->count() > self::MAX_LIGNES) {
                throw new \InvalidArgumentException('Trop de ventes sur cette période pour un seul fichier : choisissez une période plus courte.');
            }

            $guichet = Reservation::CANAL_GUICHET;
            $lignes = $ventes->map(function ($l) use ($guichet) {
                $estGuichet = (int) $l->res_canal === $guichet;
                $montant = (float) $l->montant_total;
                $commission = $estGuichet ? 0 : round($montant * self::TAUX_COMMISSION, 2);
                return [
                    'date' => Carbon::parse($l->created_at)->format('Y-m-d H:i'),
                    'billet' => $l->res_numero,
                    'trajet' => "{$l->depart} → {$l->arrivee}",
                    'depart' => trim(Carbon::parse($l->voyage_date)->format('Y-m-d') . ' ' . substr((string) $l->voyage_heure_depart, 0, 5)),
                    'canal' => $estGuichet ? 'Guichet' : 'En ligne',
                    'paiement' => $this->libellePaiement($l->paiement),
                    // Guichet : le vendeur ; en ligne : le client
                    'personne' => trim("{$l->util_prenom} {$l->util_nom}"),
                    'billets' => (int) $l->nb_voyageurs,
                    'montant' => $montant,
                    'commission' => $commission,
                    'net' => round($montant - $commission, 2),
                ];
            });

            $remboursements = $this->remboursements($request)
                ->where('r.res_remb_statut', 2)
                ->whereBetween('r.res_remb_date', [$du, $au])
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->orderBy('r.res_remb_date')
                ->get(['r.res_numero', 'r.res_remb_date', 'r.res_remb_montant', 'r.montant_total', 'r.res_remb_reference', 'r.nb_voyageurs', 'pd.pro_nom as depart', 'pa.pro_nom as arrivee'])
                ->map(fn($l) => [
                    'date' => Carbon::parse($l->res_remb_date)->format('Y-m-d H:i'),
                    'billet' => $l->res_numero,
                    'trajet' => "{$l->depart} → {$l->arrivee}",
                    'billets' => (int) $l->nb_voyageurs,
                    'montant' => (float) ($l->res_remb_montant ?? $l->montant_total),
                    'reference' => $l->res_remb_reference,
                ]);

            return response()->json([
                'statut' => true,
                'data' => [
                    'periode' => ['du' => $du->toDateString(), 'au' => $au->toDateString()],
                    'ventes' => $lignes,
                    'remboursements' => $remboursements,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** Ventes encaissées de la compagnie sur la période, filtres appliqués. */
    private function base(Request $request, Carbon $du, Carbon $au): Builder
    {
        $q = DB::table('fandrio_app.reservations as r')
            ->join('fandrio_app.voyages as v', 'v.voyage_id', '=', 'r.voyage_id')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->where('t.comp_id', $this->compagnieId())
            ->whereIn('r.res_statut', self::STATUTS_ENCAISSES)
            ->whereBetween('r.created_at', [$du, $au]);
        return $this->filtrer($q, $request);
    }

    /** « Cash » (nom technique en base) est affiché « Espèces ». */
    private function libellePaiement(?string $nom): string
    {
        if (!$nom) {
            return 'Non défini';
        }
        return in_array(mb_strtolower($nom), ['cash', 'espèces', 'especes'], true) ? 'Espèces' : $nom;
    }

    private function compagnieId(): int
    {
        return (int) Auth::user()->comp_id;
    }

    /** Période demandée (défaut : du 1er du mois à aujourd'hui), bornes incluses. */
    private function periode(Request $request): array
    {
        $request->validate([
            'du' => 'nullable|date_format:Y-m-d',
            'au' => 'nullable|date_format:Y-m-d',
            'traj_id' => 'nullable|integer',
            'canal' => 'nullable|in:guichet,en_ligne',
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

    /** Filtres trajet et canal. */
    private function filtrer(Builder $q, Request $request): Builder
    {
        if ($request->filled('traj_id')) {
            $q->where('t.traj_id', (int) $request->traj_id);
        }
        if ($request->canal === 'guichet') {
            $q->where('r.res_canal', Reservation::CANAL_GUICHET);
        } elseif ($request->canal === 'en_ligne') {
            $q->where('r.res_canal', '<>', Reservation::CANAL_GUICHET);
        }
        return $q;
    }

    /** Réservations de la compagnie concernées par un remboursement. */
    private function remboursements(Request $request): Builder
    {
        $q = DB::table('fandrio_app.reservations as r')
            ->join('fandrio_app.voyages as v', 'v.voyage_id', '=', 'r.voyage_id')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->where('t.comp_id', $this->compagnieId());
        return $this->filtrer($q, $request);
    }
}
