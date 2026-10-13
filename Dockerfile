# =============================================================================
# Étape "web" : nginx + fichiers publics (utilisée par docker-compose.prod.yml,
# où le code n'est plus monté en volume). Placée AVANT l'étape app pour que
# `docker build` sans --target produise toujours l'image PHP (dev inchangé).
# =============================================================================
FROM nginx:alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/public

# =============================================================================
# Étape "app" : PHP-FPM (Laravel) — cible par défaut
# =============================================================================
FROM php:8.2-fpm-alpine AS app

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    mbstring \
    bcmath \
    opcache \
    gd \
    pcntl

# Extension Redis (phpredis) : cache, rate-limiter et queue
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# PHP-FPM : pool dimensionné (l'image officielle plafonne à 5 workers)
# Valeurs surchargeables via l'environnement du conteneur.
ENV PHP_FPM_MAX_CHILDREN=30     PHP_FPM_START_SERVERS=8     PHP_FPM_MIN_SPARE_SERVERS=4     PHP_FPM_MAX_SPARE_SERVERS=12
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-fandrio.conf

# php.ini de production (remplacé par docker/php/php.ini en dev via volume)
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/custom.ini

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /var/www

# Copy composer files first for layer caching
COPY composer.json composer.lock ./

# Install dependencies (no dev by default)
ARG INSTALL_DEV=false
RUN if [ "$INSTALL_DEV" = "true" ]; then \
        composer install --no-scripts --no-autoloader; \
    else \
        composer install --no-dev --no-scripts --no-autoloader; \
    fi

# Copy application files
COPY . .

# Generate autoloader
RUN if [ "$INSTALL_DEV" = "true" ]; then \
        composer dump-autoload --optimize; \
    else \
        composer dump-autoload --optimize --no-dev; \
    fi

# Discover packages (registers service providers like Reverb)
RUN php artisan package:discover --ansi || true

# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Copy entrypoint script
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
