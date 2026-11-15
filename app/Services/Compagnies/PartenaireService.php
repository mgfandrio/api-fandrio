<?php

namespace App\Services\Compagnies;

use App\Models\Compagnies\Compagnie;
use App\Models\Trajet\Trajet;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Partenariats entre compagnies.
 *
 * Un partenariat est un lien réciproque (une ligne par paire, comp_a < comp_b),
 * actif dès l'ajout et retirable par l'une ou l'autre compagnie. La compagnie
 * partenaire située dans la province d'arrivée d'un voyage peut être choisie comme
 * « compagnie de réception » : elle reçoit le suivi de la voiture en route.
 */
class PartenaireService
{
    private const TABLE = 'fandrio_app.compagnie_partenaires';

    /** Identifiants des compagnies partenaires de $compId. */
    public function idsPartenaires(int $compId): array
    {
        return DB::table(self::TABLE)
            ->where('comp_a', $compId)->pluck('comp_b')
            ->merge(DB::table(self::TABLE)->where('comp_b', $compId)->pluck('comp_a'))
            ->map(fn($id) => (int) $id)
            ->all();
    }

    public function sontPartenaires(int $x, int $y): bool
    {
        [$a, $b] = $this->paire($x, $y);
        return DB::table(self::TABLE)->where('comp_a', $a)->where('comp_b', $b)->exists();
    }

    /** Liste des partenaires de $compId, avec leur province. */
    public function lister(int $compId): array
    {
        $liens = DB::table(self::TABLE)
            ->where('comp_a', $compId)->orWhere('comp_b', $compId)
            ->get()
            ->keyBy(fn($l) => (int) ($l->comp_a == $compId ? $l->comp_b : $l->comp_a));

        return Compagnie::with('localisation')
            ->whereIn('comp_id', $liens->keys())
            ->orderBy('comp_nom')
            ->get()
            ->map(fn(Compagnie $c) => $this->formater($c) + [
                'depuis' => optional($liens[$c->comp_id]->created_at ? \Carbon\Carbon::parse($liens[$c->comp_id]->created_at) : null)->toIso8601String(),
                'ajoute_par' => match (true) {
                    $liens[$c->comp_id]->cp_cree_par === null => 'admin',
                    (int) $liens[$c->comp_id]->cp_cree_par === $compId => 'moi',
                    default => 'partenaire',
                },
            ])
            ->values()
            ->all();
    }

    /** Compagnies actives qui peuvent être ajoutées comme partenaires de $compId. */
    public function disponibles(int $compId, ?string $recherche = null): array
    {
        $exclus = array_merge([$compId], $this->idsPartenaires($compId));

        return Compagnie::with('localisation')
            ->where('comp_statut', 1)
            ->whereNotIn('comp_id', $exclus)
            ->when($recherche, function ($q) use ($recherche) {
                $terme = '%' . mb_strtolower(trim($recherche)) . '%';
                $q->where(fn($q2) => $q2->whereRaw('LOWER(comp_nom) LIKE ?', [$terme])
                    ->orWhereHas('localisation', fn($p) => $p->whereRaw('LOWER(pro_nom) LIKE ?', [$terme])));
            })
            ->orderBy('comp_nom')
            ->limit(100)
            ->get()
            ->map(fn(Compagnie $c) => $this->formater($c))
            ->values()
            ->all();
    }

    /**
     * Partenaires actifs situés dans une province (destination d'un trajet) :
     * ce sont les seules compagnies de réception possibles pour ce trajet.
     */
    public function pourProvince(int $compId, int $provinceId): array
    {
        return Compagnie::with('localisation')
            ->whereIn('comp_id', $this->idsPartenaires($compId))
            ->where('comp_statut', 1)
            ->where('comp_localisation', $provinceId)
            ->orderBy('comp_nom')
            ->get()
            ->map(fn(Compagnie $c) => $this->formater($c))
            ->values()
            ->all();
    }

    /**
     * Ajoute un partenariat. $creePar = compagnie à l'origine du lien (null = admin système).
     */
    public function ajouter(int $compId, int $autreId, ?int $creePar, bool $notifier = true): array
    {
        if ($compId === $autreId) {
            throw new \Exception('Une compagnie ne peut pas être sa propre partenaire.');
        }
        $autre = Compagnie::where('comp_statut', 1)->find($autreId);
        if (!$autre) {
            throw new \Exception('Cette compagnie est introuvable ou inactive.');
        }
        if ($this->sontPartenaires($compId, $autreId)) {
            throw new \Exception("{$autre->comp_nom} est déjà votre partenaire.");
        }

        [$a, $b] = $this->paire($compId, $autreId);
        DB::table(self::TABLE)->insert([
            'comp_a' => $a,
            'comp_b' => $b,
            'cp_cree_par' => $creePar,
            'created_at' => now(),
        ]);

        if ($notifier && $creePar !== null) {
            $nom = Compagnie::whereKey($compId)->value('comp_nom');
            NotificationService::notifierAdminsCompagnie(
                $autreId,
                17,
                'Nouvelle compagnie partenaire',
                "{$nom} vous a ajoutée à ses compagnies partenaires. Vous serez prévenus des voitures qu'elle envoie vers votre province (menu Suivi des voyages). Vous pouvez retirer ce partenariat dans Paramètres > Compagnies partenaires."
            );
        }

        return $this->formater($autre->load('localisation'));
    }

    /**
     * Retire un partenariat. La compagnie qui retire doit garder au moins un partenaire.
     * Les voyages pas encore partis qui désignaient l'autre compagnie comme réception
     * repassent en suivi interne.
     */
    public function retirer(int $compId, int $autreId): void
    {
        if (!$this->sontPartenaires($compId, $autreId)) {
            throw new \Exception("Cette compagnie n'est pas votre partenaire.");
        }
        if (count($this->idsPartenaires($compId)) <= 1) {
            throw new \Exception('Votre compagnie doit garder au moins une compagnie partenaire. Ajoutez-en une autre avant de retirer celle-ci.');
        }

        DB::transaction(function () use ($compId, $autreId) {
            [$a, $b] = $this->paire($compId, $autreId);
            DB::table(self::TABLE)->where('comp_a', $a)->where('comp_b', $b)->delete();

            // Voyages pas encore partis, dans un sens comme dans l'autre
            foreach ([[$compId, $autreId], [$autreId, $compId]] as [$depart, $reception]) {
                \App\Models\Voyages\Voyage::whereNull('voyage_suivi')
                    ->where('comp_reception', $reception)
                    ->whereHas('trajet', fn($q) => $q->where('comp_id', $depart))
                    ->update(['comp_reception' => null]);
            }
        });

        $nom = Compagnie::whereKey($compId)->value('comp_nom');
        NotificationService::notifierAdminsCompagnie(
            $autreId,
            17,
            'Partenariat retiré',
            "{$nom} a retiré votre compagnie de ses partenaires. Vous ne recevrez plus le suivi de ses voitures, et vos voyages pas encore partis vers sa province ne la désignent plus comme compagnie de réception."
        );
    }

    /** Liens choisis par l'admin système à la création d'une compagnie. */
    public function lierALaCreation(int $compId, array $partenaires): void
    {
        foreach (array_unique(array_map('intval', $partenaires)) as $autreId) {
            if ($autreId !== $compId && !$this->sontPartenaires($compId, $autreId)) {
                $this->ajouter($compId, $autreId, null, false);
            }
        }
    }

    /**
     * Vérifie la compagnie de réception choisie pour un voyage : partenaire active,
     * située dans la province d'arrivée du trajet. Renvoie l'identifiant ou null.
     */
    public function verifierReception(int $compId, $receptionId, Trajet $trajet): ?int
    {
        if ($receptionId === null || $receptionId === '' || (int) $receptionId === 0) {
            return null;
        }
        $receptionId = (int) $receptionId;

        $reception = Compagnie::where('comp_statut', 1)->find($receptionId);
        if (!$reception || !$this->sontPartenaires($compId, $receptionId)) {
            throw new \Exception("La compagnie de réception doit être une de vos compagnies partenaires.");
        }
        if ((int) $reception->comp_localisation !== (int) $trajet->pro_arrivee) {
            throw new \Exception("{$reception->comp_nom} n'est pas située dans la province d'arrivée de ce trajet.");
        }
        return $receptionId;
    }

    public function formater(Compagnie $c): array
    {
        return [
            'id' => $c->comp_id,
            'nom' => $c->comp_nom,
            'logo' => $c->comp_logo,
            'telephone' => $c->comp_phone,
            'province' => $c->localisation ? ['id' => $c->localisation->pro_id, 'nom' => $c->localisation->pro_nom] : null,
        ];
    }

    private function paire(int $x, int $y): array
    {
        return [min($x, $y), max($x, $y)];
    }
}
