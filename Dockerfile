FROM composer:2 AS composer
FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg unzip libsqlite3-dev libxml2-dev \
    && docker-php-ext-install pdo_sqlite dom \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p /app/var && chown -R www-data:www-data /app/var
COPY docker/php.ini /usr/local/etc/php/conf.d/neo.ini
USER www-data
CMD ["php-fpm"]
