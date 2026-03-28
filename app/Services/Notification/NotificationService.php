<?php

namespace App\Services\Notification;

use App\Models\Notifications\Notification;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Créer et envoyer une notification
     */
    public static function envoyer(array $data): Notification
    {
        $notification = Notification::create([
            'notif_type' => $data['type'],
            'notif_destinataire_type' => $data['destinataire_type'],
            'notif_destinataire_id' => $data['destinataire_id'],
            'notif_titre' => $data['titre'],
            'notif_message' => $data['message'],
            'notif_statut' => 1,
            'res_id' => $data['res_id'] ?? null,
        ]);

        // Envoyer la push notification
        self::envoyerPush($data['destinataire_id'], $data['titre'], $data['message'], $data);

        return $notification;
    }

    /**
     * Notifier l'admin compagnie d'une nouvelle réservation confirmée
     */
    public static function notifierAdminReservation(int $compId, int $resId, string $clientNom, string $voyageInfo): void
    {
        // Trouver les admins de la compagnie (role = 2)
        $admins = Utilisateur::where('comp_id', $compId)
            ->where('util_role', 2)
            ->where('util_statut', 1)
            ->get();

        foreach ($admins as $admin) {
            self::envoyer([
                'type' => 4, // nouvelle réservation
                'destinataire_type' => 2, // compagnie
                'destinataire_id' => $admin->util_id,
                'titre' => 'Nouvelle réservation confirmée',
                'message' => "{$clientNom} a confirmé une réservation pour {$voyageInfo}.",
                'res_id' => $resId,
            ]);
        }
    }

    /**
     * Envoyer un rappel de voyage au client
     */
    public static function envoyerRappelVoyage(int $utilId, int $resId, string $voyageInfo, int $joursRestants): void
    {
        $titre = $joursRestants === 0
            ? 'Votre voyage est aujourd\'hui !'
            : "Rappel : voyage dans {$joursRestants} jour" . ($joursRestants > 1 ? 's' : '');

        $message = $joursRestants === 0
            ? "Votre voyage {$voyageInfo} est prévu aujourd'hui. Bon voyage !"
            : "Votre voyage {$voyageInfo} est prévu dans {$joursRestants} jour" . ($joursRestants > 1 ? 's' : '') . ". Préparez-vous !";

        self::envoyer([
            'type' => 2, // rappel
            'destinataire_type' => 1, // utilisateur
            'destinataire_id' => $utilId,
            'titre' => $titre,
            'message' => $message,
            'res_id' => $resId,
        ]);
    }

    /**
     * Envoyer la push notification via Expo Push API
     */
    private static function envoyerPush(int $utilisateurId, string $titre, string $message, array $data = []): void
    {
        try {
            $user = Utilisateur::find($utilisateurId);
            if (!$user || !$user->push_token) {
                return;
            }

            $token = $user->push_token;
            if (!str_starts_with($token, 'ExponentPushToken[')) {
                return;
            }

            $response = Http::post('https://exp.host/--/api/v2/push/send', [
                'to' => $token,
                'title' => $titre,
                'body' => $message,
                'sound' => 'default',
                'data' => [
                    'type' => $data['type'] ?? null,
                    'res_id' => $data['res_id'] ?? null,
                ],
            ]);

            if ($response->successful()) {
                Notification::where('notif_destinataire_id', $utilisateurId)
                    ->where('notif_statut', 1)
                    ->latest('notif_id')
                    ->first()
                    ?->update(['notif_statut' => 2]);
            }
        } catch (\Exception $e) {
            Log::warning('Push notification failed: ' . $e->getMessage());
        }
    }
}
