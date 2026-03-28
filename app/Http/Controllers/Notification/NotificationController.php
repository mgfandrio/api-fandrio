<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notifications\Notification;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Liste des notifications de l'utilisateur connecté
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $perPage = $request->get('per_page', 20);

        $notifications = Notification::where('notif_destinataire_id', $user->util_id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'statut' => true,
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Nombre de notifications non lues
     */
    public function unreadCount(): JsonResponse
    {
        $user = auth()->user();

        $count = Notification::where('notif_destinataire_id', $user->util_id)
            ->whereIn('notif_statut', [1, 2]) // en attente ou envoyée (pas lue)
            ->count();

        return response()->json([
            'statut' => true,
            'count' => $count,
        ]);
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(int $id): JsonResponse
    {
        $user = auth()->user();

        $notification = Notification::where('notif_id', $id)
            ->where('notif_destinataire_id', $user->util_id)
            ->firstOrFail();

        $notification->update(['notif_statut' => 3]);

        return response()->json([
            'statut' => true,
            'message' => 'Notification marquée comme lue.',
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(): JsonResponse
    {
        $user = auth()->user();

        Notification::where('notif_destinataire_id', $user->util_id)
            ->whereIn('notif_statut', [1, 2])
            ->update(['notif_statut' => 3]);

        return response()->json([
            'statut' => true,
            'message' => 'Toutes les notifications marquées comme lues.',
        ]);
    }

    /**
     * Enregistrer le push token de l'utilisateur
     */
    public function registerPushToken(Request $request): JsonResponse
    {
        $request->validate([
            'push_token' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        Utilisateur::where('util_id', $user->util_id)
            ->update(['push_token' => $request->push_token]);

        return response()->json([
            'statut' => true,
            'message' => 'Push token enregistré.',
        ]);
    }

    /**
     * Supprimer le push token (déconnexion)
     */
    public function unregisterPushToken(): JsonResponse
    {
        $user = auth()->user();
        Utilisateur::where('util_id', $user->util_id)
            ->update(['push_token' => null]);

        return response()->json([
            'statut' => true,
            'message' => 'Push token supprimé.',
        ]);
    }
}
