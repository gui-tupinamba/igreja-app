FROM php:8.4-fpm-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libonig-dev \
        libfcgi-bin \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_pgsql \
        opcache \
        zip \
        mbstring \
        gd \
        exif \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/check-fpm.sh /usr/local/bin/check-fpm
COPY docker/php/app-entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/php/run-tests.sh /usr/local/bin/run-tests

WORKDIR /var/www/api
ENTRYPOINT ["sh", "/usr/local/bin/app-entrypoint"]

FROM base AS source
COPY --chown=www-data:www-data apps/api/ ./

FROM source AS test
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer check-platform-reqs \
    && mkdir -p var/cache var/log .phpunit.cache \
    && chown -R www-data:www-data var .phpunit.cache
USER www-data
CMD ["sh", "/usr/local/bin/run-tests"]

FROM source AS runtime
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var
USER www-data
CMD ["php-fpm"]
