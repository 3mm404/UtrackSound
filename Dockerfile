FROM php:8.5-fpm-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor curl unzip ffmpeg \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd intl mbstring pcntl pdo_mysql pdo_sqlite zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
WORKDIR /var/www/html

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && composer check-platform-reqs --no-dev

FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

FROM php-base AS app
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --no-interaction \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf 'upload_max_filesize=100M\npost_max_size=110M\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/uploads.ini
EXPOSE 80 8080
ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
