# Multi-arch base image -- pulls the linux/arm/v7 variant automatically when
# built on/for the target Raspberry Pi (32-bit) hardware. See architecture.md
# -- Stack Overview.
#
# 8.5 rather than 8.2: composer.lock (generated locally against the dev
# machine's actual PHP 8.5) resolved symfony/config, event-dispatcher,
# filesystem, mime, and string to v8.1.x -- a transitive pull-in via
# robmorgan/phinx -- all of which require PHP >=8.4.1. Verified 8.5-fpm-alpine
# still publishes an arm/v7 image, so this doesn't cost the Pi target.
FROM php:8.5-fpm-alpine

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first so this layer is cached across code-only
# changes -- meaningful on the Pi's limited build CPU (see architecture.md,
# Deploy process: `git pull` + rebuild in place, no external build service).
#
# --ignore-platform-req=php-64bit: the target hardware is a 32-bit ARM Pi, so
# composer's virtual `php-64bit` package is absent, and robmorgan/phinx 0.16
# declares `php-64bit >=8.1`. Phinx's 64-bit guard concerns big-integer
# *data* handling; this project's migrations are pure DDL (CREATE TABLE),
# which is unaffected on 32-bit. Everything else in the lock installs fine.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
    --ignore-platform-req=php-64bit

COPY . .

RUN composer dump-autoload --optimize --ignore-platform-req=php-64bit \
    && mkdir -p storage/uploads storage/cache \
    && chown -R www-data:www-data storage

USER www-data

CMD ["php-fpm"]
