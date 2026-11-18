FROM php:8.4-cli-bookworm AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libxml2-dev libonig-dev libcurl4-openssl-dev libzip-dev unzip git ca-certificates \
    && docker-php-ext-install pdo_pgsql mbstring xml curl zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/raven
RUN composer create-project laravel/laravel app "^13.0" --no-interaction --prefer-dist
WORKDIR /opt/raven/app
RUN composer require laravel/sanctum:^4.2 google/apiclient:^2.18 --no-interaction --prefer-dist
COPY . /opt/raven-source
RUN mkdir -p app/Http/Controllers/Api \
    && cp /opt/raven-source/config/services.php config/services.php \
    && cp /opt/raven-source/bootstrap/app.php bootstrap/app.php \
    && cp /opt/raven-source/app/Models/User.php app/Models/User.php \
    && cp /opt/raven-source/app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/AuthController.php \
    && cp /opt/raven-source/routes/api.php routes/api.php \
    && cp /opt/raven-source/database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
    && php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider" --force \
    && composer dump-autoload --optimize \
    && php artisan route:list --path=api/v1 >/dev/null

FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libxml2-dev libonig-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install pdo_pgsql mbstring xml curl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=build /opt/raven/app /var/www/html
COPY docker/start.sh /usr/local/bin/raven23-start
RUN chmod +x /usr/local/bin/raven23-start \
    && chown -R www-data:www-data storage bootstrap/cache
ENV PORT=10000 DB_CONNECTION=pgsql
EXPOSE 10000
CMD ["/usr/local/bin/raven23-start"]
