#!/bin/bash
set -e

cd /var/www

# Install dependencies if vendor directory is missing or incomplete
if [ ! -f "vendor/autoload.php" ]; then
    echo "=== FANDRIO: Installing Composer dependencies... ==="
    composer install --no-interaction --optimize-autoloader
fi

# Discover packages (registers Reverb, etc.)
php artisan package:discover --ansi 2>/dev/null || true

# Generate app key if not set
php artisan key:generate --no-interaction 2>/dev/null || true

# Cache config
php artisan config:clear 2>/dev/null || true

# Set permissions
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

exec "$@"
