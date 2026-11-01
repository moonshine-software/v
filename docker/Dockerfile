# syntax=docker/dockerfile:1.7

FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
    curl \
    fcgi \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    sqlite-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
    zip \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------- Dependencies stage ----------
FROM base AS deps

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM node:24-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY --from=deps /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY vite.config.js ./
RUN npm run build

# ---------- Application stage ----------
FROM base AS app

# Copy vendor from deps stage
COPY --from=deps /var/www/html/vendor ./vendor

# Copy application
COPY . .
COPY --from=frontend /build/public/build ./public/build

RUN mkdir -p \
    storage/api-admin \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Generate optimized autoload
RUN composer dump-autoload --optimize

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Make reload script executable
RUN chmod +x docker/reload.sh

COPY docker/php/health.conf /usr/local/etc/php-fpm.d/zz-health.conf

EXPOSE 9000

CMD ["php-fpm"]

# Runtime state lives in a single backed-up volume. Keep the source tree immutable.
FROM php:8.5-fpm-alpine AS production
RUN apk add --no-cache fcgi libzip icu-libs oniguruma libpq sqlite-libs
COPY --from=app /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=app /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY docker/php/health.conf /usr/local/etc/php-fpm.d/zz-health.conf
WORKDIR /var/www/html
COPY --from=app /var/www/html /var/www/html
RUN php docker/prepare-runtime.php
USER www-data
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s CMD ["sh", "docker/healthcheck.sh"]

FROM docker.angie.software/angie:1.12.1-minimal AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/angie/production.conf /etc/angie/angie.conf
USER 82:82
CMD ["angie", "-g", "daemon off;"]

# The interactive installer serves loopback HTTP without a TLS ingress.
# Keep the production web target's HTTPS forwarding unchanged.
FROM web AS web-local
USER root
RUN sed -i 's/fastcgi_param HTTPS on;/fastcgi_param HTTPS off;/' /etc/angie/angie.conf
USER 82:82

# Keep an untargeted build an application image.
FROM production AS release
