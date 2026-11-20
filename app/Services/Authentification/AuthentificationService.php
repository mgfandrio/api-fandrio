<?php

namespace App\Services\Authentification;

use App\Models\Utilisateurs\Utilisateur;
use App\DTOs\ConnexionDTO;
use App\DTOs\InscriptionDTO;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class AuthentificationService
{
    /** Message unique en cas d'échec : ne dit jamais si c'est l'identifiant ou le mot de passe. */
    public const MESSAGE_ECHEC_CONNEXION = 'Identifiant ou mot de passe incorrect.';

    /** Empreinte bcrypt d'un mot de passe aléatoire, vérifiée quand le compte n'existe pas. */
    private const EMPREINTE_FACTICE = '$2y$12$1XFRibjA.eyw.KGG7kv8AeUrQa6uzFk5t4IJfmKgtEM2uDWN4vhVq';

    /**
     * Traite la connexion d'un utilisateur
     */
    public function connexion(ConnexionDTO $connexionDTO): array
    {
        // Vérification des identifiants administrateur système
        if ($this->estAdminSysteme($connexionDTO)) {
            return $this->genererReponseAdminSysteme();
        }

        // Recherche de l'utilisateur par email ou téléphone
        $utilisateur = $this->trouverUtilisateurParIdentifiant($connexionDTO->identifiant);

        // Même message et même durée que le compte existe ou non : aucune piste sur
        // l'identifiant (on vérifie une empreinte factice quand le compte est inconnu).
        $empreinte = $utilisateur?->util_password ?? self::EMPREINTE_FACTICE;
        $motDePasseValide = Hash::check($connexionDTO->motDePasse, $empreinte);
        if (!$utilisateur || !$motDePasseValide) {
            throw new \Exception(self::MESSAGE_ECHEC_CONNEXION);
        }

        if (!$utilisateur->estActif()) {
            throw new \Exception('Votre compte est désactivé. Veuillez contacter l\'administrateur.');
        }

        $token = JWTAuth::fromUser($utilisateur);

        return $this->formaterReponseConnexion($utilisateur, $token);
    }

    /**
     * Inscription d'un nouvel utilisateur (client)
     */
    public function inscription(InscriptionDTO $inscriptionDTO): array
    {
        return DB::transaction(function () use ($inscriptionDTO) {
            // Vérifier si l'email ou le téléphone existe déjà
            if ($this->utilisateurExiste($inscriptionDTO->email, $inscriptionDTO->telephone)) {
                throw new \Exception('Un utilisateur avec cet email ou téléphone existe déjà');
            }

            // Créer le nouvel utilisateur
            $utilisateur = Utilisateur::create([
                'util_nom' => $inscriptionDTO->nom,
                'util_prenom' => $inscriptionDTO->prenom,
                'util_email' => $inscriptionDTO->email,
                'util_phone' => $inscriptionDTO->telephone,
                'util_password' => Hash::make($inscriptionDTO->motDePasse),
                'util_role' => 1, // Rôle client par défaut
                'util_statut' => 1, // Statut actif
                'util_anniv' => $inscriptionDTO->dateNaissance,
            ]);

            $token = JWTAuth::fromUser($utilisateur);

            return $this->formaterReponseConnexion($utilisateur, $token);
        });
    }

    /**
     * Rafraîchit le token JWT.
     *
     * Note : cette méthode doit pouvoir s'exécuter même si le token courant
     * est expiré (tant qu'on est encore dans la fenêtre refresh_ttl).
     * C'est pourquoi la route associée NE DOIT PAS être protégée par
     * le middleware `auth:api`, qui rejette les tokens expirés avant
     * d'atteindre le contrôleur.
     */
    public function rafraichirToken(): array
    {
        try {
            // parseToken() lit le token depuis l'en-tête Authorization.
            // refresh() accepte les tokens expirés tant que refresh_ttl
            // n'est pas dépassé.
            $nouveauToken = JWTAuth::parseToken()->refresh();

            // Récupérer l'utilisateur depuis le NOUVEAU token (l'ancien
            // n'est plus exploitable via JWTAuth::user()).
            $utilisateur = JWTAuth::setToken($nouveauToken)->toUser();

            return [
                'statut' => true,
                'token' => $nouveauToken,
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'utilisateur' => $this->formaterUtilisateur($utilisateur)
            ];
        } catch (\Exception $e) {
            throw new \Exception('Impossible de rafraîchir le token');
        }
    }

    /**
     * Déconnexion de l'utilisateur
     */
    public function deconnexion(): void
    {
        JWTAuth::invalidate();
    }

    /**
     * Vérifie si les identifiants correspondent à l'admin système
     */
    private function estAdminSysteme(ConnexionDTO $connexionDTO): bool
    {
        // Identifiants dans le .env (config/api.php), mot de passe sous forme d'empreinte bcrypt
        $identifiant = (string) config('api.admin_systeme.identifiant');
        $empreinte   = (string) config('api.admin_systeme.mot_de_passe_hash');

        if ($identifiant === '' || $empreinte === '') {
            return false; // connexion admin système désactivée
        }

        // Empreinte vérifiée à chaque tentative, quel que soit l'identifiant : sinon la
        // réponse plus lente trahirait l'identifiant de l'admin système.
        $motDePasseAdmin = Hash::check($connexionDTO->motDePasse, $empreinte);
        return hash_equals($identifiant, (string) $connexionDTO->identifiant) && $motDePasseAdmin;
    }

    /**
     * Génère la réponse pour l'admin système
     */
    private function genererReponseAdminSysteme(): array
    {
        $admin = Utilisateur::where('util_email', config('api.admin_systeme.email'))->first();

        if (!$admin) {
            throw new \Exception('Compte administrateur système non trouvé');
        }

        $token = JWTAuth::fromUser($admin);

        return $this->formaterReponseConnexion($admin, $token);
    }

    /**
     * Trouve un utilisateur par email ou téléphone
     */
    private function trouverUtilisateurParIdentifiant(string $identifiant): ?Utilisateur
    {
        $query = Utilisateur::where(function($q) use ($identifiant) {
            $q->where('util_email', $identifiant)
              ->orWhere('util_phone', $identifiant);
        });

        return $query->first();
    }

    /**
     * Vérifie si un utilisateur existe déjà avec cet email ou téléphone
     */
    private function utilisateurExiste(string $email, string $telephone): bool
    {
        return Utilisateur::where('util_email', $email)
            ->orWhere('util_phone', $telephone)
            ->exists();
    }

    /**
     * Formate la réponse de connexion
     */
    private function formaterReponseConnexion(Utilisateur $utilisateur, string $token): array
    {
        return [
            'statut' => true,
            'message' => 'Connexion réussie',
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'utilisateur' => $this->formaterUtilisateur($utilisateur)
        ];
    }

    /**
     * Formate les informations utilisateur pour la réponse
     */
    private function formaterUtilisateur(Utilisateur $utilisateur): array
    {
        $compInfo = null;
        if ($utilisateur->comp_id && $utilisateur->compagnie) {
            $compInfo = [
                'id' => $utilisateur->compagnie->comp_id,
                'nom' => $utilisateur->compagnie->comp_nom,
                'logo' => $utilisateur->compagnie->comp_logo,
                'localisation' => $utilisateur->compagnie->localisation ? $utilisateur->compagnie->localisation->pro_nom : null
            ];
        }

        return [
            'id' => $utilisateur->util_id,
            'nom' => $utilisateur->util_nom,
            'prenom' => $utilisateur->util_prenom,
            'email' => $utilisateur->util_email,
            'telephone' => $utilisateur->util_phone,
            'role' => $utilisateur->util_role,
            'compagnie_id' => $utilisateur->comp_id,
            'compagnie' => $compInfo,
            'statut' => $utilisateur->util_statut,
            'photo' => $utilisateur->util_photo,
            'admin_principal' => (bool) $utilisateur->util_admin_principal,
            'mdp_provisoire' => (bool) $utilisateur->util_mdp_provisoire,
        ];
    }
}