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

        if (!$utilisateur || !Hash::check($connexionDTO->motDePasse, $utilisateur->util_password)) {
            throw new \Exception('Identifiants incorrects');
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
     * Rafraîchit le token JWT
     */
    public function rafraichirToken(): array
    {
        try {
            $nouveauToken = JWTAuth::refresh();
            $utilisateur = JWTAuth::user();

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
        return $connexionDTO->identifiant === 'fandrioAdmin.mg' && 
               $connexionDTO->motDePasse === 'fandrio.Admin1019!';
    }

    /**
     * Génère la réponse pour l'admin système
     */
    private function genererReponseAdminSysteme(): array
    {
        $admin = Utilisateur::where('util_email', 'admin@fandrio.mg')->first();

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
        return [
            'id' => $utilisateur->util_id,
            'nom' => $utilisateur->util_nom,
            'prenom' => $utilisateur->util_prenom,
            'email' => $utilisateur->util_email,
            'telephone' => $utilisateur->util_phone,
            'role' => $utilisateur->util_role,
            'compagnie_id' => $utilisateur->comp_id,
            'statut' => $utilisateur->util_statut
        ];
    }
}