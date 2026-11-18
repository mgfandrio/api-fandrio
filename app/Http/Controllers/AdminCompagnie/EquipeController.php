<?php

namespace App\Http\Controllers\AdminCompagnie;

use App\Http\Controllers\Controller;
use App\Models\Utilisateurs\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Équipe d'une compagnie : les administrateurs qui ont accès au back-office.
 *
 * Tous les admins de la compagnie voient la liste ; seul l'admin principal
 * (créé par FANDRIO) ajoute une personne, la désactive, la réactive, lui
 * redonne un mot de passe ou la retire. Un mot de passe donné par un autre est
 * « provisoire » : la personne doit le changer à sa prochaine connexion.
 */
class EquipeController extends Controller
{
    /** Alphabet du mot de passe provisoire : sans caractères ambigus (0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** GET /api/adminCompagnie/equipe */
    public function index(): JsonResponse
    {
        $moi = Auth::user();
        $membres = Utilisateur::where('comp_id', $moi->comp_id)
            ->where('util_role', 2)
            ->where('util_statut', '<>', 3)
            ->orderByDesc('util_admin_principal')
            ->orderBy('util_prenom')
            ->get()
            ->map(fn(Utilisateur $u) => $this->formater($u, $moi));

        return response()->json([
            'statut' => true,
            'data' => [
                'je_suis_principal' => (bool) $moi->util_admin_principal,
                'membres' => $membres,
            ],
        ]);
    }

    /** POST /api/adminCompagnie/equipe { prenom, nom, telephone, email } */
    public function ajouter(Request $request): JsonResponse
    {
        if ($refus = $this->exigerPrincipal()) {
            return $refus;
        }
        $donnees = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'telephone' => 'required|string|max:20',
            'email' => 'required|email|max:150',
        ], [], ['prenom' => 'prénom', 'telephone' => 'téléphone', 'email' => 'e-mail']);

        $telephone = preg_replace('/\s+/', '', $donnees['telephone']);
        $email = mb_strtolower(trim($donnees['email']));
        if (Utilisateur::where('util_email', $email)->exists()) {
            return $this->erreur('Cet e-mail est déjà utilisé par un autre compte FANDRIO.', 422);
        }
        if (Utilisateur::where('util_phone', $telephone)->exists()) {
            return $this->erreur('Ce numéro de téléphone est déjà utilisé par un autre compte FANDRIO.', 422);
        }

        $motDePasse = $this->motDePasseProvisoire();
        $membre = Utilisateur::create([
            'util_prenom' => trim($donnees['prenom']),
            'util_nom' => trim($donnees['nom']),
            'util_email' => $email,
            'util_phone' => $telephone,
            'util_password' => Hash::make($motDePasse),
            'util_role' => 2,
            'comp_id' => Auth::user()->comp_id,
            'util_statut' => 1,
            'util_admin_principal' => false,
            'util_mdp_provisoire' => true,
        ]);

        return response()->json([
            'statut' => true,
            'message' => "{$membre->util_prenom} {$membre->util_nom} a été ajouté à l'équipe.",
            'data' => [
                'membre' => $this->formater($membre->fresh(), Auth::user()),
                // Affiché une seule fois : jamais stocké en clair
                'mot_de_passe_provisoire' => $motDePasse,
            ],
        ], 201);
    }

    /** PATCH /api/adminCompagnie/equipe/{id}/desactiver */
    public function desactiver(int $id): JsonResponse
    {
        return $this->changerStatut($id, 2, 'Le compte est désactivé : la personne ne peut plus se connecter.');
    }

    /** PATCH /api/adminCompagnie/equipe/{id}/reactiver */
    public function reactiver(int $id): JsonResponse
    {
        return $this->changerStatut($id, 1, 'Le compte est réactivé.');
    }

    /** DELETE /api/adminCompagnie/equipe/{id} : retire la personne de l'équipe (suppression logique). */
    public function retirer(int $id): JsonResponse
    {
        return $this->changerStatut($id, 3, 'La personne a été retirée de l\'équipe.');
    }

    /** POST /api/adminCompagnie/equipe/{id}/mot-de-passe : nouveau mot de passe provisoire. */
    public function nouveauMotDePasse(int $id): JsonResponse
    {
        if ($refus = $this->exigerPrincipal()) {
            return $refus;
        }
        $membre = $this->membreModifiable($id);
        if ($membre instanceof JsonResponse) {
            return $membre;
        }

        $motDePasse = $this->motDePasseProvisoire();
        $membre->update(['util_password' => Hash::make($motDePasse), 'util_mdp_provisoire' => true]);

        return response()->json([
            'statut' => true,
            'message' => "Nouveau mot de passe provisoire pour {$membre->util_prenom} {$membre->util_nom}.",
            'data' => ['membre' => $this->formater($membre->fresh(), Auth::user()), 'mot_de_passe_provisoire' => $motDePasse],
        ]);
    }

    // ── Outils ──

    private function changerStatut(int $id, int $statut, string $message): JsonResponse
    {
        if ($refus = $this->exigerPrincipal()) {
            return $refus;
        }
        $membre = $this->membreModifiable($id);
        if ($membre instanceof JsonResponse) {
            return $membre;
        }
        $membre->update(['util_statut' => $statut]);

        return response()->json(['statut' => true, 'message' => $message, 'data' => ['membre' => $this->formater($membre->fresh(), Auth::user())]]);
    }

    /** Un membre de la même compagnie, autre que soi et que l'admin principal. */
    private function membreModifiable(int $id): Utilisateur|JsonResponse
    {
        $moi = Auth::user();
        $membre = Utilisateur::where('util_id', $id)->where('comp_id', $moi->comp_id)->where('util_role', 2)->where('util_statut', '<>', 3)->first();
        if (!$membre) {
            return $this->erreur('Cette personne ne fait pas partie de votre équipe.', 404);
        }
        if ($membre->util_id === $moi->util_id || $membre->util_admin_principal) {
            return $this->erreur('Le compte de l\'admin principal ne peut pas être modifié ici.', 422);
        }
        return $membre;
    }

    private function exigerPrincipal(): ?JsonResponse
    {
        return Auth::user()->util_admin_principal ? null : $this->erreur('Seul l\'admin principal de la compagnie peut gérer l\'équipe.', 403);
    }

    private function erreur(string $message, int $code): JsonResponse
    {
        return response()->json(['statut' => false, 'message' => $message], $code);
    }

    /** « K7M4-Q9TP » : 8 caractères faciles à lire et à dicter. */
    private function motDePasseProvisoire(): string
    {
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return substr($c, 0, 4) . '-' . substr($c, 4);
    }

    private function formater(Utilisateur $u, Utilisateur $moi): array
    {
        return [
            'id' => $u->util_id,
            'prenom' => $u->util_prenom,
            'nom' => $u->util_nom,
            'email' => $u->util_email,
            'telephone' => $u->util_phone,
            'statut' => (int) $u->util_statut, // 1 actif, 2 désactivé
            'admin_principal' => (bool) $u->util_admin_principal,
            'mdp_provisoire' => (bool) $u->util_mdp_provisoire,
            'c_est_moi' => $u->util_id === $moi->util_id,
            'ajoute_le' => $u->created_at?->format('Y-m-d'),
        ];
    }
}
