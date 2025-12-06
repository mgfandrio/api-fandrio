<?php

use App\Http\Controllers\Chauffeur\ChangementEtatChauffeurController;
use App\Http\Controllers\Chauffeur\ModificationChauffeurController;
use App\Http\Controllers\Chauffeur\SuppressionChauffeurController;
use App\Http\Controllers\Chauffeur\RecuperationListeChauffeur;
use App\Http\Controllers\Chauffeur\ConsulterDetailsChauffeur;
use App\Http\Controllers\Chauffeur\AjoutChauffeurController;
use App\Http\Controllers\Voiture\AjoutVoitureController;
use App\Http\Controllers\Auth\AuthentificationController;
use App\Http\Controllers\Compagnies\CompagnieController;
use App\Http\Controllers\Admin\UtilisateurController;
use Illuminate\Support\Facades\Route;

// Routes publiques (nécessitent seulement la clé API)
Route::middleware(['api.key'])->group(function () {
    // Connexion pour tous les types d'utilisateurs
    Route::post('/connexion', [AuthentificationController::class, 'connexion']);

    // Inscription seulement pour les clients
    Route::post('/inscription', [AuthentificationController::class, 'inscription']);
});

// Routes protégées (nécessitent token JWT + clé API)
Route::middleware(['api.key', 'auth:api'])->group(function () {
    // Routes d'authentification
    Route::post('/rafraichir-token', [AuthentificationController::class, 'rafraichir']);
    Route::post('/deconnexion', [AuthentificationController::class, 'deconnexion']);
    Route::get('/moi', [AuthentificationController::class, 'moi']);
});

// Routes pour la gestion des compagnies (admin système seulement)
Route::middleware(['api.key', 'auth:api', 'role:3'])->prefix('admin')->group(function () {
    Route::prefix('compagnies')->group(function () {
        Route::get('/recupListecompagnie', [CompagnieController::class, 'index']);
        Route::get('/statistiques', [CompagnieController::class, 'statistiques']);
        Route::post('/creerCompagnie', [CompagnieController::class, 'store']);
        Route::get('/detailCompagnie/{id}', [CompagnieController::class, 'show']);
        Route::put('/updateCompagnie/{id}', [CompagnieController::class, 'update']);
        Route::patch('/{id}/statut', [CompagnieController::class, 'changerStatut']);
        Route::delete('/{id}', [CompagnieController::class, 'destroy']);
    });
});


// Routes pour la gestion des utilisateurs (admin système seulement)
Route::middleware(['api.key', 'auth:api', 'role:3'])->prefix('admin')->group(function () {
    Route::prefix('utilisateurs')->group(function () {
        Route::get('/recupListeUtilisateur', [UtilisateurController::class, 'index']);
        Route::get('/statistiques', [UtilisateurController::class, 'statistiques']);
        Route::get('/detailUtilisateur/{id}', [UtilisateurController::class, 'show']);
        Route::patch('/{id}/activer', [UtilisateurController::class, 'activer']);
        Route::patch('/{id}/desactiver', [UtilisateurController::class, 'desactiver']);
        Route::patch('/{id}/reactiver', [UtilisateurController::class, 'reactiver']);
        Route::patch('/{id}/statut', [UtilisateurController::class, 'changerStatut']);
        Route::delete('/delete/{id}', [UtilisateurController::class, 'supprimer']);
    });
});


Route::middleware(['api.key', 'auth:api', 'role:2'])->prefix('adminCompagnie')->group(function () {
    // Routes pour la gestion des chauffeurs
    Route::prefix('chauffeur')->group(function () {
        Route::get('/liste', [RecuperationListeChauffeur::class, 'listeChauffeurs']);                     // liste des chauffeurs
        Route::get('/details/{id}', [ConsulterDetailsChauffeur::class, 'detailChauffeur']);               // detail chauffeur

        Route::post('/ajout', [AjoutChauffeurController::class, 'ajouterChauffeur']);                       // ajout nouveau chauffeur

        Route::put('/modification/{id}', [ModificationChauffeurController::class, 'modifierChauffeur']);    // modif chauffeur existant
        Route::patch('/modification/{id}', [ModificationChauffeurController::class, 'modifierChauffeur']);

        Route::put('/suppression/{id}', [SuppressionChauffeurController::class, 'supprimerChauffeur']);     // Suppression chauffeur existant

        Route::put('changement_etat/{id}', [ChangementEtatChauffeurController::class, 'changerEtatChauffeur']);        // Activation chauffeur
    });

    // Routes pour la gestion des voitures
    Route::prefix('voiture')->group(function () {
        Route::post('/ajout', [AjoutVoitureController::class, 'ajouterVoiture']);
    });
});
