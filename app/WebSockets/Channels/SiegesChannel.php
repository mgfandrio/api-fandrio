<?php 

namespace App\WebSockets\Channels;

use Reverb\WebSockets\Channels\PrivateChannel;
use Ratchet\ConnectionInterface;

class SiegesChannel extends PrivateChannel 
{
    public function subscribe (ConnectionInterface $connection, $payload)
    {
        // Valider le token
        $token = $payload->token ?? null;
        if (!$this->validateToken($token)) {
            $connection->send(json_encode([
                'event' => 'error',
                'data' => ['message' => 'Token invalide']
            ]));
            return;
        }
        parent::subscribe($connection, $payload);

        // Envoyer l'état initial
        $this->sendInitialState($connection, $payload->channel);
    }

    private function validateToken($token)
    {
        try {
            $decoded = json_decode(base64_decode($token), true);

            if (!isset($decoded['voyage_id'], $decoded['utilisateur_id'], $decoded['exp'])) {
                return false;
            }

            if ($decoded['exp'] < time()) {
                return false;
            }

            // Vérifier que l'utilisateur existe
            $utilisateur  = \App\Models\Utilisateurs\Utilisateur::find($decoded['utilisateur_id']);
            return $utilisateur !== null;
        } catch (\Exception $e) {
            return false;
        }
    }


    private function sendInitialState(ConnectionInterface $connection, string $channel)
    {
        // Extraire le voyage_id du channel (sieges_update_{voyage_id})
        $voyageId = str_replace('sieges_update_', '', $channel);

        if (is_numeric($voyageId)) {
            $siegeService = app(\App\Services\Voiture\SiegeService::class);
            $planSieges = $siegeService->getPlanSieges((int)$voyageId);

            $connection->send(json_encode([
                'event' => 'initial_state',
                'channel' => $channel,
                'data' => $planSieges
            ]));
        }
    }
}
