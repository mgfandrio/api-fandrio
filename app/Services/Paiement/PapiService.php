<?php

namespace App\Services\Paiement;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Intégration PAPI.mg (paiement mobile money).
 *
 * ⚠️ SCAFFOLD — prêt à brancher : les détails exacts de l'API PAPI (chemins,
 * noms de champs requête/réponse, header + algorithme de signature du webhook)
 * sont balisés `TODO(PAPI)` et à confirmer via https://docs.papi.mg.
 */
class PapiService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.papi.base_url', 'https://app.papi.mg'), '/');
        $this->apiKey = config('services.papi.api_key');
        $this->webhookSecret = config('services.papi.webhook_secret');
    }

    /** Vrai si la clé API PAPI de Fandrio est configurée. */
    public function estConfigure(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Crée un lien de paiement PAPI. Fandrio est le marchand encaisseur.
     * Retourne ['url' => string|null, 'transaction_id' => string|null, 'raw' => array].
     *
     * TODO(PAPI): confirmer le chemin et les noms de champs. Endpoint connu (doc) :
     *   POST {base_url}/dashboard/api/payment-links
     */
    public function creerLienPaiement(array $params): array
    {
        if (!$this->estConfigure()) {
            throw new \RuntimeException('PAPI non configuré (PAPI_API_KEY manquant).');
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->post($this->baseUrl . '/dashboard/api/payment-links', [
                // TODO(PAPI): ajuster les clés selon la doc réelle
                'amount'          => $params['montant'],
                'currency'        => 'MGA',
                'reference'       => $params['reference'],        // ex : "COLLECTE-42"
                'description'     => $params['description'] ?? null,
                'notificationUrl' => $params['notification_url'],
            ]);

        if (!$response->successful()) {
            Log::error('PAPI creerLienPaiement échec', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Échec création lien PAPI (HTTP ' . $response->status() . ').');
        }

        $data = $response->json() ?? [];

        // TODO(PAPI): adapter aux vrais noms de champs de la réponse
        return [
            'url'            => $data['url'] ?? $data['payment_url'] ?? $data['link'] ?? null,
            'transaction_id' => $data['id'] ?? $data['transaction_id'] ?? ($params['reference'] ?? null),
            'raw'            => $data,
        ];
    }

    /**
     * Vérifie la signature d'un webhook PAPI.
     *
     * TODO(PAPI): confirmer le nom du header de signature et l'algorithme.
     * Par défaut : HMAC-SHA256 du corps brut avec PAPI_WEBHOOK_SECRET, comparé
     * au header 'X-Papi-Signature'.
     */
    public function verifierSignatureWebhook(Request $request): bool
    {
        // Aucun secret configuré : refus en prod, toléré en local pour les tests.
        if (empty($this->webhookSecret)) {
            Log::warning('PAPI webhook : PAPI_WEBHOOK_SECRET non configuré.');
            return app()->environment('local');
        }

        $signatureRecue = $request->header('X-Papi-Signature'); // TODO(PAPI): vrai header
        if (empty($signatureRecue)) {
            return false;
        }

        $calculee = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        return hash_equals($calculee, (string) $signatureRecue);
    }
}
