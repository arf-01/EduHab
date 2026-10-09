# syntax=docker/dockerfile:1

FROM composer:2 AS php-dependencies

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-intl \
    --ignore-platform-req=ext-pdo_mysql

FROM node:20-alpine AS frontend-assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
COPY postcss.config.js ./
COPY tailwind.config.js ./
RUN npm run build

FROM php:8.2-fpm-alpine AS runtime

WORKDIR /var/www/html

RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && apk del .build-deps

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

COPY . .
COPY --from=php-dependencies /app/vendor ./vendor
COPY --from=frontend-assets /app/public ./public

RUN mkdir -p \
        storage/app \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
