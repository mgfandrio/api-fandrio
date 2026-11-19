<?php

namespace App\Services\Versement;

use App\Models\Reservation\Reservation;
use App\Models\Voyages\Voyage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Versements aux chauffeurs des voitures de particuliers.
 *
 * Pour chaque siège vendu (billets encaissés : statuts 2 et 6, guichet et en ligne) :
 *   - la commission FANDRIO (5 %) est retirée des ventes en ligne ;
 *   - la compagnie prend son pourcentage sur le reste ;
 *   - le chauffeur reçoit le solde.
 * Le versement d'un voyage est recalculé tant que la voiture n'est pas partie,
 * puis FIGÉ au départ (ou une fois le voyage passé) avec la photo de l'instant :
 * matricule, chauffeur, trajet, pourcentage. Il devient réglable une fois figé
 * si la voiture est partie remplie à 50 % ou plus, ou une fois le voyage passé.
 */
class VersementService
{
    public const TAUX_FANDRIO = 0.05;
    public const SEUIL_REMPLISSAGE = 0.5;
    private const STATUTS_ENCAISSES = [2, 6];
    private const JOURS_RATTRAPAGE = 60;

    /**
     * Met à jour (ou crée, ou supprime) le versement d'un voyage. Un versement
     * figé ou réglé n'est plus jamais recalculé.
     */
    public function synchroniser(int $voyageId): ?object
    {
        $existant = DB::table('fandrio_app.versements')->where('voyage_id', $voyageId)->first();
        if ($existant && ($existant->vers_fige_le || (int) $existant->vers_statut === 2)) {
            return $existant;
        }

        $voyage = Voyage::with(['voiture.chauffeur', 'trajet.provinceDepart', 'trajet.provinceArrivee'])->find($voyageId);
        $voiture = $voyage?->voiture;
        $estParticulier = $voiture && (int) $voiture->voit_proprietaire === 2 && $voiture->voit_taux_compagnie !== null;

        // Voyage annulé, ou voiture (re)devenue celle de la compagnie avant le départ : pas de versement
        if (!$voyage || (int) $voyage->voyage_statut === 4 || !$estParticulier) {
            if ($existant) {
                DB::table('fandrio_app.versements')->where('vers_id', $existant->vers_id)->delete();
            }
            return null;
        }

        $taux = (float) $voiture->voit_taux_compagnie;
        $montants = $this->calculer($voyageId, $taux);
        $chauffeur = $voiture->chauffeur;
        $parti = $voyage->voyage_suivi !== null || (int) $voyage->voyage_statut === 3;
        $passe = Carbon::parse($voyage->voyage_date)->lt(today());

        $valeurs = [
            'comp_id' => (int) $voyage->trajet->comp_id,
            'voit_id' => $voiture->voit_id,
            'voit_matricule' => $voiture->voit_matricule,
            'chauff_id' => $chauffeur?->chauff_id,
            'chauffeur_nom' => $chauffeur ? trim("{$chauffeur->chauff_prenom} {$chauffeur->chauff_nom}") : null,
            'chauffeur_phone' => $chauffeur?->chauff_phone,
            'traj_id' => $voyage->traj_id,
            'trajet_libelle' => trim(($voyage->trajet->provinceDepart->pro_nom ?? '?') . ' → ' . ($voyage->trajet->provinceArrivee->pro_nom ?? '?')),
            'voyage_date' => Carbon::parse($voyage->voyage_date)->toDateString(),
            'voyage_heure' => substr((string) $voyage->voyage_heure_depart, 0, 8),
            'taux_compagnie' => $taux,
            'nb_places' => (int) ($voyage->places_disponibles ?: $voiture->voit_places),
            'vers_fige_le' => $parti || $passe ? now() : null,
            'updated_at' => now(),
        ] + $montants;

        if ($existant) {
            DB::table('fandrio_app.versements')->where('vers_id', $existant->vers_id)->update($valeurs);
        } else {
            DB::table('fandrio_app.versements')->insertOrIgnore($valeurs + ['voyage_id' => $voyageId, 'vers_statut' => 1, 'created_at' => now()]);
        }
        return DB::table('fandrio_app.versements')->where('voyage_id', $voyageId)->first();
    }

    /**
     * Met à jour les versements non figés d'une compagnie (ou de toutes) :
     * voyages des voitures de particuliers, à venir ou récents.
     */
    public function synchroniserCompagnie(?int $compId = null): void
    {
        $depuis = today()->subDays(self::JOURS_RATTRAPAGE)->toDateString();
        $voyages = DB::table('fandrio_app.voyages as v')
            ->join('fandrio_app.trajets as t', 't.traj_id', '=', 'v.traj_id')
            ->join('fandrio_app.voitures as vo', 'vo.voit_id', '=', 'v.voit_id')
            ->leftJoin('fandrio_app.versements as ve', 've.voyage_id', '=', 'v.voyage_id')
            ->when($compId, fn($q) => $q->where('t.comp_id', $compId))
            ->where('v.voyage_date', '>=', $depuis)
            ->whereNull('ve.vers_fige_le')
            ->where(fn($q) => $q->where('vo.voit_proprietaire', 2)->orWhereNotNull('ve.vers_id'))
            ->pluck('v.voyage_id');

        foreach ($voyages as $id) {
            $this->synchroniser((int) $id);
        }
    }

    /** Montants d'un voyage pour un pourcentage donné. */
    public function calculer(int $voyageId, float $taux): array
    {
        $r = DB::table('fandrio_app.reservations')
            ->where('voyage_id', $voyageId)
            ->whereIn('res_statut', self::STATUTS_ENCAISSES)
            ->selectRaw('COALESCE(SUM(montant_total), 0) AS ventes,
                COALESCE(SUM(CASE WHEN res_canal <> ? THEN montant_total ELSE 0 END), 0) AS en_ligne,
                COALESCE(SUM(nb_voyageurs), 0) AS sieges', [Reservation::CANAL_GUICHET])
            ->first();

        $ventes = round((float) $r->ventes, 2);
        $commission = round((float) $r->en_ligne * self::TAUX_FANDRIO, 2);
        $aPartager = $ventes - $commission;
        $partCompagnie = round($aPartager * $taux / 100, 2);

        return [
            'nb_sieges' => (int) $r->sieges,
            'montant_ventes' => $ventes,
            'commission_fandrio' => $commission,
            'part_compagnie' => $partCompagnie,
            'part_chauffeur' => round($aPartager - $partCompagnie, 2),
        ];
    }

    /** État d'un versement : en_vente, en_attente, a_regler, retard, regle. */
    public function etat(object $v): string
    {
        if ((int) $v->vers_statut === 2) {
            return 'regle';
        }
        if (!$v->vers_fige_le) {
            return 'en_vente';
        }
        if (Carbon::parse($v->voyage_date)->lt(today())) {
            return 'retard';
        }
        return $this->remplissage($v) >= self::SEUIL_REMPLISSAGE ? 'a_regler' : 'en_attente';
    }

    public function reglable(object $v): bool
    {
        return in_array($this->etat($v), ['a_regler', 'retard'], true);
    }

    public function remplissage(object $v): float
    {
        return (int) $v->nb_places > 0 ? (int) $v->nb_sieges / (int) $v->nb_places : 0.0;
    }
}
