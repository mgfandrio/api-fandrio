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
 * Caisse du guichet : espèces encaissées par chaque vendeur sur une journée,
 * et clôture du soir (montant compté, écart, remarque).
 *
 * Attendu = ventes au guichet du vendeur ce jour-là (toutes, même si le voyage
 * a été annulé depuis : l'argent a bien été reçu ce jour-là).
 * N'importe quel admin de la compagnie peut clôturer n'importe quelle caisse ;
 * l'auteur est enregistré. Une vente après la clôture rend la caisse
 * « à reclôturer » : la nouvelle clôture remplace l'ancienne.
 */
class CaisseController extends Controller
{
    private const JOURS_HISTORIQUE = 14;
    private const BILLETS = [20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    /** GET /api/adminCompagnie/caisse?date=AAAA-MM-JJ */
    public function jour(Request $request): JsonResponse
    {
        try {
            $request->validate(['date' => 'nullable|date_format:Y-m-d']);
            $date = $request->filled('date') ? Carbon::parse($request->date) : today();
            if ($date->isAfter(today())) {
                return response()->json(['statut' => false, 'message' => 'Choisissez un jour passé ou aujourd’hui.'], 422);
            }
            $compId = $this->compagnieId();
            $jour = $date->toDateString();

            // Ventes du jour par vendeur
            $ventes = $this->ventesGuichet($compId)
                ->whereDate('r.created_at', $jour)
                ->groupBy('r.util_id')
                ->selectRaw('r.util_id, COUNT(*) AS nb_ventes, SUM(r.nb_voyageurs) AS nb_billets, SUM(r.montant_total) AS attendu, MAX(r.created_at) AS derniere_vente, '
                    // Part des chauffeurs (voitures de particuliers) : pas de commission FANDRIO au guichet
                    . 'COALESCE(SUM(r.montant_total * (1 - COALESCE(ve.taux_compagnie, CASE WHEN vo.voit_proprietaire = 2 THEN vo.voit_taux_compagnie END) / 100)), 0) AS part_chauffeurs')
                ->get()->keyBy('util_id');

            $clotures = DB::table('fandrio_app.caisse_clotures as c')
                ->leftJoin('fandrio_app.utilisateurs as p', 'p.util_id', '=', 'c.clot_par')
                ->where('c.comp_id', $compId)->where('c.clot_date', $jour)
                ->get(['c.*', 'p.util_prenom as par_prenom', 'p.util_nom as par_nom'])
                ->keyBy('vendeur_id');

            $ids = $ventes->keys()->merge($clotures->keys())->unique()->values();
            $noms = DB::table('fandrio_app.utilisateurs')->whereIn('util_id', $ids)
                ->get(['util_id', 'util_prenom', 'util_nom'])->keyBy('util_id');

            $caisses = $ids->map(function ($id) use ($ventes, $clotures, $noms) {
                $v = $ventes->get($id);
                $c = $clotures->get($id);
                $attendu = (float) ($v->attendu ?? 0);
                $nbVentes = (int) ($v->nb_ventes ?? 0);
                $n = $noms->get($id);
                return [
                    'vendeur_id' => (int) $id,
                    'vendeur' => $n ? trim("{$n->util_prenom} {$n->util_nom}") : 'Vendeur supprimé',
                    'nb_ventes' => $nbVentes,
                    'nb_billets' => (int) ($v->nb_billets ?? 0),
                    'attendu' => $attendu,
                    'part_chauffeurs' => round((float) ($v->part_chauffeurs ?? 0), 2),
                    'cloture' => $c ? $this->formaterCloture($c) : null,
                    // Ventes faites après la clôture : à recompter
                    'a_recloturer' => $c !== null && ((float) $c->clot_attendu !== $attendu || (int) $c->clot_nb_ventes !== $nbVentes),
                ];
            })->sortBy('vendeur')->values();

            $cloturees = $caisses->filter(fn($c) => $c['cloture'] && !$c['a_recloturer']);

            return response()->json([
                'statut' => true,
                'data' => [
                    'date' => $jour,
                    'billets' => self::BILLETS,
                    'totaux' => [
                        'attendu' => (float) $caisses->sum('attendu'),
                        'part_chauffeurs' => round((float) $caisses->sum('part_chauffeurs'), 2),
                        'nb_ventes' => (int) $caisses->sum('nb_ventes'),
                        'nb_billets' => (int) $caisses->sum('nb_billets'),
                        'nb_caisses' => $caisses->count(),
                        'nb_cloturees' => $cloturees->count(),
                        'compte' => (float) $caisses->sum(fn($c) => $c['cloture']['compte'] ?? 0),
                        'ecart' => (float) $cloturees->sum(fn($c) => $c['cloture']['ecart']),
                    ],
                    'caisses' => $caisses,
                    'historique' => $this->historique($compId, $date),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /** GET /api/adminCompagnie/caisse/ventes?date=&vendeur_id= : ventes d'un vendeur sur le jour */
    public function ventes(Request $request): JsonResponse
    {
        try {
            $request->validate(['date' => 'required|date_format:Y-m-d', 'vendeur_id' => 'required|integer']);
            $lignes = $this->ventesGuichet($this->compagnieId())
                ->where('r.util_id', (int) $request->vendeur_id)
                ->whereDate('r.created_at', $request->date)
                ->join('fandrio_app.provinces as pd', 'pd.pro_id', '=', 't.pro_depart')
                ->join('fandrio_app.provinces as pa', 'pa.pro_id', '=', 't.pro_arrivee')
                ->orderBy('r.created_at')
                ->get(['r.res_numero', 'r.created_at', 'r.nb_voyageurs', 'r.montant_total', 'r.res_statut', 'pd.pro_nom as depart', 'pa.pro_nom as arrivee', 'v.voyage_date', 'v.voyage_heure_depart'])
                ->map(fn($l) => [
                    'heure' => Carbon::parse($l->created_at)->format('H:i'),
                    'billet' => $l->res_numero,
                    'trajet' => "{$l->depart} → {$l->arrivee}",
                    'depart' => Carbon::parse($l->voyage_date)->format('d/m') . ' ' . substr((string) $l->voyage_heure_depart, 0, 5),
                    'places' => (int) $l->nb_voyageurs,
                    'montant' => (float) $l->montant_total,
                    'annule' => (int) $l->res_statut === 4,
                ]);

            return response()->json(['statut' => true, 'data' => ['ventes' => $lignes]]);
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    /**
     * POST /api/adminCompagnie/caisse/cloturer
     * { date, vendeur_id, compte, billetage?: {"20000": 8, ...}, note? }
     */
    public function cloturer(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date_format:Y-m-d',
                'vendeur_id' => 'required|integer',
                'compte' => 'required|numeric|min:0|max:1000000000',
                'billetage' => 'nullable|array',
                'billetage.*' => 'integer|min:0|max:100000',
                'note' => 'nullable|string|max:500',
            ]);
            $compId = $this->compagnieId();
            $date = Carbon::parse($request->date);
            if ($date->isAfter(today())) {
                return response()->json(['statut' => false, 'message' => 'On ne peut pas clôturer une caisse d’un jour à venir.'], 422);
            }
            // Le vendeur doit appartenir à la compagnie
            $vendeur = DB::table('fandrio_app.utilisateurs')->where('util_id', (int) $request->vendeur_id)->where('comp_id', $compId)->first(['util_id', 'util_prenom', 'util_nom']);
            if (!$vendeur) {
                return response()->json(['statut' => false, 'message' => 'Ce vendeur n’appartient pas à votre compagnie.'], 404);
            }

            $billetage = null;
            if ($request->filled('billetage')) {
                $billetage = collect($request->billetage)
                    ->only(array_map('strval', self::BILLETS))
                    ->map(fn($n) => (int) $n)->filter(fn($n) => $n > 0)->all();
                $billetage = $billetage ?: null;
            }

            $resultat = DB::transaction(function () use ($request, $compId, $date, $vendeur, $billetage) {
                // Attendu recalculé côté serveur, au moment de la clôture
                $v = $this->ventesGuichet($compId)
                    ->where('r.util_id', $vendeur->util_id)
                    ->whereDate('r.created_at', $date->toDateString())
                    ->selectRaw('COUNT(*) AS nb_ventes, COALESCE(SUM(r.nb_voyageurs), 0) AS nb_billets, COALESCE(SUM(r.montant_total), 0) AS attendu')
                    ->first();
                if ((int) $v->nb_ventes === 0) {
                    throw new \InvalidArgumentException('Aucune vente au guichet pour ce vendeur ce jour-là : rien à clôturer.');
                }
                $attendu = round((float) $v->attendu, 2);
                $compte = round((float) $request->compte, 2);
                $ecart = round($compte - $attendu, 2);
                $note = trim((string) $request->note);
                if ($ecart != 0 && mb_strlen($note) < 3) {
                    throw new \InvalidArgumentException('Il y a un écart : expliquez-le en quelques mots dans la remarque.');
                }

                $valeurs = [
                    'clot_attendu' => $attendu,
                    'clot_compte' => $compte,
                    'clot_ecart' => $ecart,
                    'clot_nb_ventes' => (int) $v->nb_ventes,
                    'clot_nb_billets' => (int) $v->nb_billets,
                    'clot_billetage' => $billetage ? json_encode($billetage) : null,
                    'clot_note' => $note !== '' ? $note : null,
                    'clot_par' => Auth::id(),
                    'updated_at' => now(),
                ];
                $cle = ['comp_id' => $compId, 'vendeur_id' => $vendeur->util_id, 'clot_date' => $date->toDateString()];
                $existe = DB::table('fandrio_app.caisse_clotures')->where($cle)->lockForUpdate()->exists();
                if ($existe) {
                    DB::table('fandrio_app.caisse_clotures')->where($cle)->update($valeurs);
                } else {
                    DB::table('fandrio_app.caisse_clotures')->insert($cle + $valeurs + ['created_at' => now()]);
                }
                return DB::table('fandrio_app.caisse_clotures as c')
                    ->leftJoin('fandrio_app.utilisateurs as p', 'p.util_id', '=', 'c.clot_par')
                    ->where('c.comp_id', $compId)->where('c.vendeur_id', $vendeur->util_id)->where('c.clot_date', $date->toDateString())
                    ->first(['c.*', 'p.util_prenom as par_prenom', 'p.util_nom as par_nom']);
            });

            return response()->json([
                'statut' => true,
                'message' => 'La caisse est clôturée.',
                'data' => [
                    'vendeur' => trim("{$vendeur->util_prenom} {$vendeur->util_nom}"),
                    'date' => $date->toDateString(),
                    'cloture' => $this->formaterCloture($resultat),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['statut' => false, 'message' => $e->getMessage()], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['statut' => false, 'message' => $this->messageErreur($e)], 500);
        }
    }

    private function compagnieId(): int
    {
        return (int) Auth::user()->comp_id;
    }

    /** Ventes au guichet de la compagnie (payées en espèces à la vente). */
    private function ventesGuichet(int $compId): Builder
    {
        return DB::table('fandrio_app.reservations as r')
            ->join('fandrio_app.voyages as v', 'v.voyage_id', '=', 'r.voyage_id')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->leftJoin('fandrio_app.voitures as vo', 'vo.voit_id', '=', 'v.voit_id')
            ->leftJoin('fandrio_app.versements as ve', 've.voyage_id', '=', 'v.voyage_id')
            ->where('t.comp_id', $compId)
            ->where('r.res_canal', Reservation::CANAL_GUICHET);
    }

    private function formaterCloture(object $c): array
    {
        return [
            'attendu' => (float) $c->clot_attendu,
            'compte' => (float) $c->clot_compte,
            'ecart' => (float) $c->clot_ecart,
            'nb_ventes' => (int) $c->clot_nb_ventes,
            'nb_billets' => (int) $c->clot_nb_billets,
            'billetage' => $c->clot_billetage ? json_decode($c->clot_billetage, true) : null,
            'note' => $c->clot_note,
            'par' => trim(($c->par_prenom ?? '') . ' ' . ($c->par_nom ?? '')) ?: null,
            'le' => Carbon::parse($c->updated_at ?? $c->created_at)->format('Y-m-d H:i'),
        ];
    }

    /** Les jours précédents : attendu, compté, écart, caisses clôturées. */
    private function historique(int $compId, Carbon $date): array
    {
        $debut = $date->copy()->subDays(self::JOURS_HISTORIQUE)->toDateString();
        $fin = $date->copy()->subDay()->toDateString();

        $ventes = $this->ventesGuichet($compId)
            ->whereRaw('DATE(r.created_at) BETWEEN ? AND ?', [$debut, $fin])
            ->groupByRaw('DATE(r.created_at), r.util_id')
            ->selectRaw('DATE(r.created_at) AS jour, r.util_id, SUM(r.montant_total) AS attendu, COUNT(*) AS nb')
            ->get();
        $clotures = DB::table('fandrio_app.caisse_clotures')
            ->where('comp_id', $compId)->whereBetween('clot_date', [$debut, $fin])
            ->get(['clot_date', 'vendeur_id', 'clot_attendu', 'clot_compte', 'clot_ecart', 'clot_nb_ventes'])
            ->keyBy(fn($c) => Carbon::parse($c->clot_date)->toDateString() . '|' . $c->vendeur_id);

        return $ventes->groupBy(fn($v) => Carbon::parse($v->jour)->toDateString())
            ->map(function ($lignes, $jour) use ($clotures) {
                $nbCloturees = 0;
                $compte = 0.0;
                $ecart = 0.0;
                foreach ($lignes as $l) {
                    $c = $clotures->get("{$jour}|{$l->util_id}");
                    if ($c && (float) $c->clot_attendu === (float) $l->attendu && (int) $c->clot_nb_ventes === (int) $l->nb) {
                        $nbCloturees++;
                        $compte += (float) $c->clot_compte;
                        $ecart += (float) $c->clot_ecart;
                    }
                }
                return [
                    'date' => $jour,
                    'attendu' => (float) $lignes->sum('attendu'),
                    'compte' => $compte,
                    'ecart' => round($ecart, 2),
                    'nb_caisses' => $lignes->count(),
                    'nb_cloturees' => $nbCloturees,
                ];
            })
            ->sortKeysDesc()->values()->all();
    }
}
