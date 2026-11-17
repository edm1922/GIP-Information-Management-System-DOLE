# GIMS Laravel API image (Ubuntu VM, Docker).
#
# Build context is the repository root:
#   docker build -f deploy/selfhosted/app/Dockerfile -t gims-app .
#
# The same image runs php-fpm (default), the queue worker and the scheduler;
# only the command differs (see docker-compose.yml).

FROM php:8.4-fpm-bookworm

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency layer: only invalidated when the lock file changes.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# Application code.
COPY . .

# Runtime directories must exist BEFORE any artisan command: package:discover
# (run below and again by composer's post-autoload-dump hook) writes
# bootstrap/cache/packages.php and throws if the directory is missing. These
# paths are excluded from the build context by .dockerignore, so create them.
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# dump-autoload runs package:discover (post-autoload-dump) and bakes the
# optimized classmap. Runtime config caching happens in scripts/deploy.sh so
# environment changes never require an image rebuild.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi

COPY deploy/selfhosted/app/php.ini /usr/local/etc/php/conf.d/zz-gims.ini
COPY deploy/selfhosted/app/www.conf /usr/local/etc/php-fpm.d/zz-gims.conf

EXPOSE 9000

# php-fpm is PID 1; SIGQUIT is its graceful shutdown signal.
STOPSIGNAL SIGQUIT
CMD ["php-fpm"]
