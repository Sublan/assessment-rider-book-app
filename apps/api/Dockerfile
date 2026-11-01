# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Dependencies: install PHP packages once, in a throwaway Composer image.
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

COPY apps/api/ ./
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------
# Runtime: PHP-FPM with pdo_mysql. No .env is baked into the image; every value
# comes from the environment (see docker-compose.yml), which is also why the
# image is identical between staging and production.
# ---------------------------------------------------------------------------
FROM php:8.2-fpm-alpine

RUN apk add --no-cache $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql opcache \
    && apk del $PHPIZE_DEPS

# OPcache + realpath cache tuned for an immutable container.
COPY apps/api/docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# Writable runtime directories; the rest of the code stays read-only.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY apps/api/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
