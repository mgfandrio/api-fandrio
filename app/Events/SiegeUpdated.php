<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class SiegeUpdated implements ShouldBroadcast
{
    use SerializesModels;

    public int $voyageId;
    public string $siegeNumero;
    public string $action;
    public ?int $utilisateurId;
    public array $data;

    public function __construct(int $voyageId, string $siegeNumero, string $action, ?int $utilisateurId, array $data)
    {
        $this->voyageId = $voyageId;
        $this->siegeNumero = $siegeNumero;
        $this->action = $action;
        $this->utilisateurId = $utilisateurId;
        $this->data = $data;
    }

    /**
     * Canal de diffusion
     */
    public function broadcastOn(): Channel
    {
        return new Channel('sieges.voyage.' . $this->voyageId);
    }

    /**
     * Nom de l'événement broadcasté
     */
    public function broadcastAs(): string
    {
        return 'siege.updated';
    }

    /**
     * Données envoyées au client
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'voyage_id' => $this->voyageId,
            'siege_numero' => $this->siegeNumero,
            'utilisateur_id' => $this->utilisateurId,
            'timestamp' => time(),
            'data' => $this->data
        ];
    }
}
