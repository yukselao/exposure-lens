# syntax=docker/dockerfile:1
# PHP-FPM runtime for the web UI. Only the runtime and its libraries are
# installed here; the application in ./wwwroot is bind-mounted at runtime.
FROM php:8.3.36-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libzip-dev tini unzip; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql zip; \
    rm -rf /var/lib/apt/lists/*; \
    usermod -u 1000 www-data; groupmod -g 1000 www-data

# Twig lives outside /var/www/html so the bind mount does not hide it.
RUN --mount=from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    --mount=type=bind,source=composer.json,target=/opt/app/composer.json \
    set -eux; cd /opt/app; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress

RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /proc/self/fd/2'; \
      echo 'memory_limit = 256M'; \
      echo 'session.cookie_httponly = 1'; \
      echo 'session.cookie_samesite = Lax'; \
      echo 'session.use_strict_mode = 1'; \
    } > /usr/local/etc/php/conf.d/exposure-lens.ini

WORKDIR /var/www/html
ENTRYPOINT ["tini", "--"]
CMD ["php-fpm"]
