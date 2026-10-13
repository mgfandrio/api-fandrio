#!/bin/bash
set -e

cd /var/www

# Install dependencies if vendor directory is missing or incomplete
# (dev uniquement : en prod, vendor/ est déjà dans l'image)
if [ ! -f "vendor/autoload.php" ] || [ ! -d "vendor/laravel/reverb" ]; then
    echo "=== FANDRIO: Installing Composer dependencies... ==="
    composer install --no-interaction --optimize-autoloader
fi

# Discover packages (registers Reverb, etc.)
php artisan package:discover --ansi 2>/dev/null || true

# Generate app key ONLY if not set.
# Hors production, `key:generate` écrase une clé existante sans confirmation :
# cela rendrait illisibles les données chiffrées (ex. comp_papi_api_key).
if [ -z "$APP_KEY" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    php artisan key:generate --no-interaction 2>/dev/null || true
fi

if [ "$APP_ENV" = "production" ]; then
    # Production : compile config/routes/events une fois au démarrage
    # (évite de relire .env et de reconstruire les routes à chaque requête).
    echo "=== FANDRIO: Caching config/routes/events (production) ==="
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
else
    # Dev : clear any stale compiled config (so .env values are re-read)
    rm -f /var/www/bootstrap/cache/config.php 2>/dev/null || true
    php artisan config:clear 2>/dev/null || true
    php artisan route:clear 2>/dev/null || true
    php artisan event:clear 2>/dev/null || true
fi

# Set permissions
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

exec "$@"
