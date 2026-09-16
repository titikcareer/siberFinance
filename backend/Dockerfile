FROM php:8.2-fpm

# pdo_pgsql butuh libpq-dev; git+unzip untuk Composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Install dependensi produksi (lock file menjamin versi deterministik)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY src ./src
COPY public ./public

# Memastikan variabel env (DB_*, JWT_*, SYNC_*) terlihat di $_ENV.
# php-fpm resmi sudah `clear_env = no`; kita tambahkan 'E' ke variables_order.
RUN echo 'variables_order = "EGPCS"' > /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000
# CMD default image php:8.2-fpm = php-fpm (port 9000)