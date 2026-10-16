# Image chạy MEnglish trên Docker: PHP 8.4 FPM + Nginx (serversideup/php), asset build sẵn trong image.

# --- Composer (vendor production) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

# --- Vite build (Vue/Inertia) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# --- Runtime ---
FROM serversideup/php:8.4-fpm-nginx

USER root
RUN install-php-extensions gd intl bcmath exif
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public/uploads \
    && rm -f public/hot

USER www-data
RUN php artisan package:discover --ansi
