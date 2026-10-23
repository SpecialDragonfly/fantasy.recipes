# Multi-arch base image -- pulls the linux/arm64 variant automatically when
# built on the target Raspberry Pi 4 (64-bit OS). See architecture.md --
# Stack Overview.
#
# 8.5 rather than 8.2: composer.lock (generated locally against the dev
# machine's actual PHP 8.5) resolved symfony/config, event-dispatcher,
# filesystem, mime, and string to v8.1.x -- a transitive pull-in via
# robmorgan/phinx -- all of which require PHP >=8.4.1.
FROM php:8.5-fpm-alpine AS base

RUN apk add --no-cache \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo_mysql \
        pdo_sqlite \
        intl \
        mbstring \
        zip \
    && apk del $PHPIZE_DEPS
# No docker-php-ext-enable opcache here: on this image opcache is already
# compiled into PHP core rather than a separate loadable module (confirmed
# 2026-08-09 -- docker-php-ext-enable's own module list on this image
# doesn't include it, and erroring "'opcache' does not exist" was breaking
# the build). docker/php/conf.d/opcache.ini below still configures it.

COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php-fpm.d/pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# One-shot migration image (docker compose run --rm migrate). Phinx is a dev
# dependency, so this stage installs require-dev; the runtime stage below
# stays --no-dev. Not the default target -- the last stage is.
FROM base AS migrate
COPY composer.json composer.lock* ./
RUN composer install --prefer-dist --no-scripts --no-autoloader \
    && composer dump-autoload --optimize
COPY phinx.php ./
COPY db ./db
CMD ["vendor/bin/phinx", "migrate", "-e", "production"]

FROM base AS runtime

# Install dependencies first so this layer is cached across code-only
# changes -- meaningful on the Pi's limited build CPU (see architecture.md,
# Deploy process: `git pull` + rebuild in place, no external build service).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && mkdir -p storage/uploads storage/cache \
    && chown -R www-data:www-data storage

USER www-data

CMD ["php-fpm"]
