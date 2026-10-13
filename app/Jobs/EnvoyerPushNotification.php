<?php

namespace App\Jobs;

use App\Models\Notifications\Notification;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie une push notification via l'API Expo, hors de la requête HTTP.
 *
 * Avant : appel Expo synchrone (timeout 30 s par défaut) pendant la requête
 * du client => un worker PHP-FPM bloqué par admin notifié.
 */
class EnvoyerPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /** Nombre de tentatives (erreurs réseau / 5xx Expo) */
    public int $tries = 3;

    /** Délai (s) avant chaque nouvelle tentative */
    public array $backoff = [10, 60];

    /** Durée max d'une tentative (s) */
    public int $timeout = 20;

    public function __construct(
        private int $notifId,
        private int $utilisateurId,
        private string $titre,
        private string $message,
        private array $data = [],
    ) {}

    public function handle(): void
    {
        $user = Utilisateur::find($this->utilisateurId);
        if (!$user || !$user->push_token) {
            return;
        }

        $token = $user->push_token;
        if (!str_starts_with($token, 'ExponentPushToken[')) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->post(self::EXPO_PUSH_URL, [
                    'to' => $token,
                    'title' => $this->titre,
                    'body' => $this->message,
                    'sound' => 'default',
                    'data' => [
                        'type' => $this->data['type'] ?? null,
                        'res_id' => $this->data['res_id'] ?? null,
                    ],
                ]);
        } catch (ConnectionException $e) {
            // Réseau indisponible : nouvelle tentative (cf. $tries / $backoff)
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            return;
        }

        if ($response->serverError()) {
            // Expo momentanément indisponible : nouvelle tentative
            $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            return;
        }

        if (!$response->successful()) {
            // 4xx : requête invalide, inutile de réessayer
            Log::warning('Push notification refusée par Expo', [
                'notif_id' => $this->notifId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }

        Notification::where('notif_id', $this->notifId)
            ->where('notif_statut', 1)
            ->update(['notif_statut' => 2]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Push notification failed: ' . $e->getMessage(), [
            'notif_id' => $this->notifId,
        ]);
    }
}
