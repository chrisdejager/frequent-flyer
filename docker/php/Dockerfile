FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip icu-dev linux-headers \
 && docker-php-ext-install intl opcache \
 && apk del linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
