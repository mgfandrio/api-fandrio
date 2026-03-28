# FANDRIO API

API back-end du projet **FANDRIO** — plateforme de réservation de taxi brousse à Madagascar.

- **Framework** : Laravel 10
- **PHP** : 8.1+
- **Base de données** : PostgreSQL (schéma `fandrio_app`)
- **Authentification** : JWT (`tymon/jwt-auth`)
- **WebSockets** : Laravel Reverb
- **Stockage d'images** : Cloudinary (`cloudinary/cloudinary_php`)

---

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
```

### Variables d'environnement requises

Configurer dans le fichier `.env` :

```dotenv
# Base de données
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fandrio
DB_USERNAME=...
DB_PASSWORD=...

# JWT
JWT_SECRET=...

# Cloudinary (upload logos compagnies)
CLOUDINARY_CLOUD_NAME=...
CLOUDINARY_API_KEY=...
CLOUDINARY_API_SECRET=...
```

---

## ⚠️ Configuration SSL obligatoire — `cacert.pem` (Windows)

> **IMPORTANT** : Sur Windows, PHP n'inclut pas de bundle de certificats CA par défaut.
> Sans cette configuration, **l'upload des images vers Cloudinary échouera** avec l'erreur :
>
> ```
> cURL error 60: SSL certificate problem: unable to get local issuer certificate
> ```

### Étapes à suivre :

1. **Télécharger le bundle `cacert.pem`** depuis le site officiel de cURL :
   👉 https://curl.se/docs/caextract.html
   (Télécharger le fichier `cacert.pem`)

2. **Placer le fichier** dans un répertoire accessible, par exemple :
   ```
   C:\php\extras\ssl\cacert.pem
   ```

3. **Modifier `php.ini`** et ajouter/décommenter ces lignes :
   ```ini
   [curl]
   curl.cainfo = "C:\php\extras\ssl\cacert.pem"

   [openssl]
   openssl.cafile = "C:\php\extras\ssl\cacert.pem"
   ```

4. **Redémarrer le serveur PHP** (`php artisan serve`).

5. **Vérifier** que la configuration est prise en compte :
   ```bash
   php -i | findstr "curl.cainfo"
   ```
   Doit afficher le chemin vers `cacert.pem`.

> **Note** : Cette configuration est nécessaire pour **toute requête HTTPS** effectuée par PHP via cURL (Cloudinary, APIs externes, etc.).

---

## Lancer le serveur

```bash
# API
php artisan serve --host=0.0.0.0 --port=8000

# WebSockets (Reverb)
php artisan reverb:start
```

---

## Tâches planifiées (Scheduler)

Le projet utilise le **scheduler Laravel** pour automatiser plusieurs tâches critiques.

### En développement (Windows)

Lancer dans un terminal dédié :

```bash
php artisan schedule:work
```

Ce processus reste actif et exécute les tâches planifiées automatiquement.

### En production (Linux)

Ajouter **une seule entrée cron** sur le serveur :

```cron
* * * * * cd /chemin/vers/fandrio_api && php artisan schedule:run >> /dev/null 2>&1
```

### Tâches enregistrées

| Commande                      | Fréquence         | Description                                                  |
|-------------------------------|--------------------|--------------------------------------------------------------|
| `sieges:nettoyer-locks`       | Chaque minute      | Libère les sièges verrouillés (expiration temporaire)        |
| `voyages:gestion-statuts`    | Toutes les 5 min   | Met à jour automatiquement les statuts des voyages           |
| `voyages:rappels`            | Tous les jours à 7h | Envoie les notifications de rappel aux clients               |

### Détail des transitions automatiques (`voyages:gestion-statuts`)

| Condition                                        | Transition                        |
|--------------------------------------------------|-----------------------------------|
| Places toutes réservées                          | Programmé → En cours              |
| Date aujourd'hui + heure de départ dépassée     | Programmé → En cours              |
| Date/heure dépassée + a des réservations        | Programmé/En cours → **Terminé**  |
| Date/heure dépassée + aucune réservation        | Programmé → **Annulé**            |

> **Important** : Sans le scheduler actif, les voyages ne changeront pas de statut automatiquement et les rappels ne seront pas envoyés.

---

## Structure du projet

```
app/
├── Http/Controllers/    # Contrôleurs API
├── Models/              # Modèles Eloquent
├── Services/            # Logique métier
├── DTOs/                # Data Transfer Objects
├── Events/              # Événements WebSocket
├── Helpers/             # Fonctions utilitaires
└── WebSockets/          # Configuration WebSocket
config/
├── cloudinary.php       # Configuration Cloudinary
routes/
├── api.php              # Routes API
├── channels.php         # Canaux WebSocket
```
