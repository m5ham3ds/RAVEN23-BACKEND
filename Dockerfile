FROM php:8.4-cli-bookworm AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libsqlite3-dev libxml2-dev libonig-dev libcurl4-openssl-dev libzip-dev unzip git ca-certificates \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring xml curl zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/raven
RUN composer create-project laravel/laravel app "^13.0" --no-interaction --prefer-dist
WORKDIR /opt/raven/app
RUN composer require laravel/sanctum:^4.2 google/apiclient:^2.18 --no-interaction --prefer-dist
COPY . /opt/raven-source
RUN mkdir -p app/Http/Controllers/Api app/Support tests/Feature \
    && cp /opt/raven-source/config/services.php config/services.php \
    && cp /opt/raven-source/config/database.php config/database.php \
    && cp /opt/raven-source/config/cache.php config/cache.php \
    && sed -i "s/'default' => env('CACHE_STORE', 'database')/'default' => env('CACHE_STORE', 'file')/" config/cache.php \
    && cp /opt/raven-source/bootstrap/app.php bootstrap/app.php \
    && cp /opt/raven-source/app/Models/User.php app/Models/User.php \
    && cp /opt/raven-source/app/Support/Raven23AuthSchema.php app/Support/Raven23AuthSchema.php \
    && cp /opt/raven-source/app/Support/GoogleIdTokenVerifier.php app/Support/GoogleIdTokenVerifier.php \
    && cp /opt/raven-source/app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/AuthController.php \
    && cp /opt/raven-source/routes/api.php routes/api.php \
    && cp /opt/raven-source/tests/Feature/AuthLifecycleTest.php tests/Feature/AuthLifecycleTest.php \
    && cp /opt/raven-source/database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
    && cp /opt/raven-source/database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
    && cp /opt/raven-source/database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
    && cp /opt/raven-source/database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
    && php -l app/Models/User.php \
    && php -l app/Support/Raven23AuthSchema.php \
    && php -l app/Support/GoogleIdTokenVerifier.php \
    && php -l config/database.php \
    && php -l config/cache.php \
    && APP_ENV=production php -r '$config = require "config/cache.php"; if (($config["default"] ?? null) !== "file" || ($config["stores"]["file"]["driver"] ?? null) !== "file") { fwrite(STDERR, "RAVEN23 must use the local file cache store.\\n"); exit(1); } echo "RAVEN23 file cache configuration check passed.\\n";' \
    && DB_URL='postgresql://raven23-test:placeholder@db.invalid:5432/neondb?sslmode=require' php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $config = require "config/database.php"; if (($config["connections"]["pgsql"]["url"] ?? null) !== getenv("DB_URL")) { fwrite(STDERR, "PostgreSQL must use the resolved DB_URL.\\n"); exit(1); } echo "PostgreSQL DB_URL configuration check passed.\\n";' \
    && php -l app/Http/Controllers/Api/AuthController.php \
    && php -l bootstrap/app.php \
    && php -l routes/api.php \
    && php -l tests/Feature/AuthLifecycleTest.php \
    && php -l database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
    && php -l database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
    && php -l database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
    && php -l database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
    && php -l /opt/raven-source/docker/check-database-target.php \
    && php -l /opt/raven-source/docker/verify-auth-schema.php \
    && php -l /opt/raven-source/docker/router.php \
    && composer dump-autoload --optimize \
    && APP_ENV=testing php artisan test --filter=AuthLifecycleTest \
    && php artisan route:list --path=api/v1 >/dev/null

FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libxml2-dev libonig-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install pdo_pgsql mbstring xml curl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=build /opt/raven/app /var/www/html
COPY docker/start.sh /usr/local/bin/raven23-start
COPY docker/check-database-target.php /usr/local/bin/raven23-check-database-target.php
COPY docker/verify-auth-schema.php /usr/local/bin/raven23-verify-auth-schema.php
COPY docker/router.php /usr/local/bin/raven23-router.php
RUN chmod +x /usr/local/bin/raven23-start \
    && chown -R www-data:www-data storage bootstrap/cache
ENV PORT=10000 DB_CONNECTION=pgsql
EXPOSE 10000
CMD ["/usr/local/bin/raven23-start"]
