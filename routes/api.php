<?php

use App\Http\Controllers\Chauffeur\ModificationChauffeurController;
use App\Http\Controllers\Chauffeur\AjoutChauffeurController;
use App\Http\Controllers\Voiture\AjoutVoitureController;
use App\Http\Controllers\Auth\AuthentificationController;
use App\Http\Controllers\Compagnies\CompagnieController;
use App\Http\Controllers\Provinces\ProvinceController;
use App\Http\Controllers\Admin\UtilisateurController;
use Illuminate\Support\Facades\Route;

// Routes publiques (nécessitent seulement la clé API)
Route::middleware(['api.key'])->group(function () {
    // Connexion pour tous les types d'utilisateurs
    Route::post('/connexion', [AuthentificationController::class, 'connexion']);

    // Inscription seulement pour les clients
    Route::post('/inscription', [AuthentificationController::class, 'inscription']);
});

// Routes protégées (nécessitent token JWT + clé API) / pour tous les types utilisateurs
Route::middleware(['api.key', 'auth:api'])->group(function () {
    // Routes d'authentification
    Route::post('/rafraichir-token', [AuthentificationController::class, 'rafraichir']);
    Route::post('/deconnexion', [AuthentificationController::class, 'deconnexion']);
    Route::get('/moi', [AuthentificationController::class, 'moi']);

});

// Routes pour l'administrateur de la plateforme
Route::middleware(['api.key', 'auth:api', 'role:3'])->prefix('admin')->group(function () {
    // Gestion des utilisateurs
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

    // Gestion des compagnies
    Route::prefix('compagnies')->group(function () {
        Route::get('/recupListecompagnie', [CompagnieController::class, 'index']);
        Route::get('/statistiques', [CompagnieController::class, 'statistiques']);
        Route::post('/creerCompagnie', [CompagnieController::class, 'store']);
        Route::get('/detailCompagnie/{id}', [CompagnieController::class, 'show']);
        Route::put('/updateCompagnie/{id}', [CompagnieController::class, 'update']);
        Route::patch('/{id}/statut', [CompagnieController::class, 'changerStatut']);
        Route::delete('/{id}', [CompagnieController::class, 'destroy']);
    });

    // Gestion des provinces
    Route::prefix('provinces')->group(function () {
        Route::get('/recuperListeProvince', [ProvinceController::class, 'index']);
        Route::get('/statistiques', [ProvinceController::class, 'statistiques']);
        Route::get('/orientations', [ProvinceController::class, 'orientations']);
        Route::post('/ajoutProvince', [ProvinceController::class, 'store']);
        Route::post('/AjoutPlusieursProvince', [ProvinceController::class, 'storeMultiple']);
        Route::get('/recupererProvince/{id}', [ProvinceController::class, 'show']);
        Route::put('/miseAjourProvince/{id}', [ProvinceController::class, 'update']);
        Route::delete('/supprimerProvince/{id}', [ProvinceController::class, 'destroy']);
        Route::delete('/supprimerPlusieursProvince', [ProvinceController::class, 'destroyMultiple']);
    });
});


// Routes pour la gestion des chauffeurs (role:2 => admin compagnie)
Route::middleware(['api.key', 'auth:api', 'role:2'])->prefix('adminCompagnie')->group(function () {
    Route::prefix('chauffeur')->group(function () {
        Route::post('/ajout', [AjoutChauffeurController::class, 'ajouterChauffeur']);                       // ajout nouveau chauffeur

        Route::put('/modification/{id}', [ModificationChauffeurController::class, 'modifierChauffeur']);    // modif chauffeur existant
        Route::patch('/modification/{id}', [ModificationChauffeurController::class, 'modifierChauffeur']);
    });
});

// Routes pour la gestion des voitures (role: 2 => admin compagnie)
Route::middleware(['api.key', 'auth:api', 'role: 2'])->prefix('adminCompagnie')->group(function () {
    Route::prefix('voiture')->group(function () {
        Route::post('/ajout', [AjoutVoitureController::class, 'ajouterVoiture']);
    });
});
