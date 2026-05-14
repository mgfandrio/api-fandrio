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
     * Notifier les admins compagnie qu'un voyage est terminé et prêt pour le contrôle
     */
    public static function notifierVoyageTermine(int $compId, string $voyageInfo, int $nbReservations): void
    {
        $admins = Utilisateur::where('comp_id', $compId)
            ->where('util_role', 2)
            ->where('util_statut', 1)
            ->get();

        foreach ($admins as $admin) {
            self::envoyer([
                'type' => 9,
                'destinataire_type' => 2,
                'destinataire_id' => $admin->util_id,
                'titre' => 'Voyage arrivé à échéance',
                'message' => "Le voyage {$voyageInfo} ({$nbReservations} réservation(s)) est arrivé à échéance. Il est temps de procéder au contrôle des voyageurs.",
            ]);
        }
    }

    /**
     * Notifier les admins compagnie qu'un voyage a été annulé (< 5 réservations)
     */
    public static function notifierVoyageAnnule(int $compId, string $voyageInfo, int $nbReservations): void
    {
        $admins = Utilisateur::where('comp_id', $compId)
            ->where('util_role', 2)
            ->where('util_statut', 1)
            ->get();

        foreach ($admins as $admin) {
            self::envoyer([
                'type' => 10,
                'destinataire_type' => 2,
                'destinataire_id' => $admin->util_id,
                'titre' => 'Voyage annulé automatiquement',
                'message' => "Le voyage {$voyageInfo} a été annulé car il n'a que {$nbReservations} réservation(s) (minimum requis : 5). Veuillez procéder au remboursement des clients concernés.",
            ]);
        }
    }

    /**
     * Notifier un client que son voyage réservé a été annulé par la compagnie
     * (annulation manuelle ou automatique). La réservation est aussi passée à statut 4
     * et un remboursement sera effectué par la compagnie.
     */
    public static function notifierClientVoyageAnnule(int $utilId, int $resId, string $voyageInfo): void
    {
        self::envoyer([
            'type' => 3, // annulation
            'destinataire_type' => 1, // utilisateur
            'destinataire_id' => $utilId,
            'titre' => 'Voyage annulé',
            'message' => "Votre voyage {$voyageInfo} a été annulé par la compagnie. Un remboursement sera effectué prochainement.",
            'res_id' => $resId,
        ]);
    }

    /**
     * Avertissement J-1 : voyage risque d'être annulé (< 5 réservations)
     */
    public static function notifierAvertissementAnnulation(int $compId, string $voyageInfo, int $nbReservations): void
    {
        $admins = Utilisateur::where('comp_id', $compId)
            ->where('util_role', 2)
            ->where('util_statut', 1)
            ->get();

        foreach ($admins as $admin) {
            self::envoyer([
                'type' => 11,
                'destinataire_type' => 2,
                'destinataire_id' => $admin->util_id,
                'titre' => '⚠️ Voyage en risque d\'annulation',
                'message' => "Le voyage {$voyageInfo} n'a que {$nbReservations} réservation(s). Si le nombre reste inférieur à 5 demain, le voyage sera automatiquement annulé et les clients devront être remboursés.",
            ]);
        }
    }

    /**
     * Prévenir un client à l'avance (8h avant le départ) que son voyage risque d'être
     * annulé faute de réservations suffisantes (< 5). Lui suggérer de chercher un autre voyage.
     */
    public static function notifierClientRisqueAnnulation(int $utilId, int $resId, string $voyageInfo, int $heuresRestantes): void
    {
        self::envoyer([
            'type' => 3, // annulation (catégorie)
            'destinataire_type' => 1,
            'destinataire_id' => $utilId,
            'titre' => '⚠️ Votre voyage risque d\'être annulé',
            'message' => "Votre voyage {$voyageInfo} pourrait être annulé dans environ {$heuresRestantes}h faute de réservations suffisantes. Nous vous conseillons de chercher dès maintenant un voyage alternatif. Si l'annulation est confirmée, vous serez intégralement remboursé.",
            'res_id' => $resId,
        ]);
    }

    /**
     * Notifier un client que son remboursement a été effectué par la compagnie.
     */
    public static function notifierClientRemboursementTraite(int $utilId, int $resId, float $montant, string $voyageInfo, ?string $reference = null): void
    {
        $montantFormate = number_format($montant, 0, ',', ' ');
        $refTxt = $reference ? " Réf. : {$reference}." : '';
        self::envoyer([
            'type' => 3,
            'destinataire_type' => 1,
            'destinataire_id' => $utilId,
            'titre' => '✅ Remboursement effectué',
            'message' => "Votre remboursement de {$montantFormate} Ar pour le voyage {$voyageInfo} a été traité.{$refTxt}",
            'res_id' => $resId,
        ]);
    }

    /**
     * Notifier un client que sa demande de remboursement a été refusée.
     */
    public static function notifierClientRemboursementRefuse(int $utilId, int $resId, string $voyageInfo, string $motif): void
    {
        self::envoyer([
            'type' => 3,
            'destinataire_type' => 1,
            'destinataire_id' => $utilId,
            'titre' => '❌ Remboursement refusé',
            'message' => "Votre demande de remboursement pour le voyage {$voyageInfo} a été refusée. Motif : {$motif}. Pour plus d'informations, contactez la compagnie.",
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
