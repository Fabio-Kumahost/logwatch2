# Logwatch2 panel — php-fpm app image (also used by the worker service)
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev libsodium-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_pgsql sodium pcntl opcache \
 && apk del $PHPIZE_DEPS

# Production php settings
RUN { \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'memory_limit=256M'; \
      echo 'post_max_size=2M'; \
      echo 'upload_max_filesize=2M'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/logwatch2.ini

WORKDIR /var/www/app
COPY --from=deps --chown=www-data:www-data /app .

# Writable paths only where needed; container runs read-only in compose.
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
