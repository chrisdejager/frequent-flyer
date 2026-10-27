# --- base: PHP + extensions + composer. Used as-is for development, where the
# code is bind-mounted from ./api.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache git unzip icu-dev linux-headers \
 && docker-php-ext-install intl opcache \
 && apk del linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# --- prod: the code is baked into the image, no dev dependencies.
# Build from the repo root: docker build -f docker/php/Dockerfile --target prod .
FROM base AS prod

LABEL org.opencontainers.image.source=https://github.com/chrisdejager/flight-planner

ENV APP_ENV=prod APP_DEBUG=0
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

# Dependencies first, so this layer is reused when only the code changes.
COPY api/composer.json api/composer.lock api/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY api/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && php bin/console cache:warmup \
 && chown -R www-data:www-data var
