<?php

namespace App\Models\Commissions;

use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal des actions sur les commissions FANDRIO : qui a fait quoi, et quand.
 * Écriture seule (jamais modifié ni supprimé par l'application).
 */
class CollecteJournal extends Model
{
    protected $table = 'fandrio_app.collecte_journal';
    protected $primaryKey = 'cj_id';
    public const UPDATED_AT = null;

    protected $fillable = ['comp_id', 'coll_id', 'util_id', 'cj_acteur', 'cj_action', 'cj_message'];

    protected $casts = ['created_at' => 'datetime'];

    public const ACTEUR_SYSTEME = 'systeme';
    public const ACTEUR_COMPAGNIE = 'admin_compagnie';
    public const ACTEUR_ADMIN = 'admin_systeme';
    public const ACTEUR_PAIEMENT = 'paiement';

    public function utilisateur()
    {
        return $this->belongsTo(Utilisateur::class, 'util_id', 'util_id');
    }

    /** Format commun aux écrans (web et mobile). */
    public function enTableau(): array
    {
        $u = $this->utilisateur;
        $nom = $u ? trim(($u->util_prenom ?? '') . ' ' . ($u->util_nom ?? '')) : null;

        return [
            'id'       => $this->cj_id,
            'coll_id'  => $this->coll_id,
            'action'   => $this->cj_action,
            'acteur'   => $this->cj_acteur,
            'par'      => $nom ?: match ($this->cj_acteur) {
                self::ACTEUR_PAIEMENT => 'Paiement en ligne',
                self::ACTEUR_ADMIN    => 'Administrateur FANDRIO',
                default               => 'FANDRIO (automatique)',
            },
            'message'  => $this->cj_message,
            'date'     => $this->created_at?->format('d/m/Y H:i'),
            'date_iso' => $this->created_at?->toIso8601String(),
        ];
    }
}
