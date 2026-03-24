<?php

namespace App\Models\Notifications;

use Illuminate\Database\Eloquent\Model;
use App\Models\Reservation\Reservation;

class Notification extends Model
{
    protected $table = 'fandrio_app.notifications';
    protected $primaryKey = 'notif_id';

    protected $fillable = [
        'notif_type',
        'notif_destinataire_type',
        'notif_destinataire_id',
        'notif_titre',
        'notif_message',
        'notif_date_envoi',
        'notif_statut',
        'res_id',
    ];

    protected $casts = [
        'notif_type' => 'integer',
        'notif_destinataire_type' => 'integer',
        'notif_destinataire_id' => 'integer',
        'notif_statut' => 'integer',
        'notif_date_envoi' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Types: 1=confirmation, 2=rappel, 3=annulation, 4=nouvelle réservation
    // Destinataire types: 1=utilisateur(client), 2=compagnie(admin), 3=admin plateforme
    // Statuts: 1=en attente, 2=envoyée, 3=lue

    public function reservation()
    {
        return $this->belongsTo(Reservation::class, 'res_id', 'res_id');
    }
}
