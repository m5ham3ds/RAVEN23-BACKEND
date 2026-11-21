# syntax=docker/dockerfile:1.7
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

RUN set -eu; \
    mkdir -p app/Http/Controllers/Api app/Support tests/Feature tests/Integration; \
    for file in \
      config/services.php config/database.php config/cache.php bootstrap/app.php routes/api.php \
      app/Models/User.php app/Support/Raven23AuthSchema.php app/Support/GoogleIdTokenVerifier.php \
      app/Http/Controllers/Api/AuthController.php \
      tests/Feature/AuthLifecycleTest.php tests/Integration/PostgresAuthLifecycleTest.php \
      database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
      database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
      database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
      database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
      database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php \
      database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php \
      docker/check-database-target.php docker/verify-auth-schema.php docker/router.php docker/start.sh; do \
        test -s "/opt/raven-source/$file" || { echo "Required source file missing or empty: $file" >&2; exit 1; }; \
    done; \
    cp /opt/raven-source/config/services.php config/services.php; \
    cp /opt/raven-source/config/database.php config/database.php; \
    cp /opt/raven-source/config/cache.php config/cache.php; \
    cp /opt/raven-source/bootstrap/app.php bootstrap/app.php; \
    cp /opt/raven-source/routes/api.php routes/api.php; \
    cp /opt/raven-source/app/Models/User.php app/Models/User.php; \
    cp /opt/raven-source/app/Support/Raven23AuthSchema.php app/Support/Raven23AuthSchema.php; \
    cp /opt/raven-source/app/Support/GoogleIdTokenVerifier.php app/Support/GoogleIdTokenVerifier.php; \
    cp /opt/raven-source/app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/AuthController.php; \
    cp /opt/raven-source/tests/Feature/AuthLifecycleTest.php tests/Feature/AuthLifecycleTest.php; \
    cp /opt/raven-source/tests/Integration/PostgresAuthLifecycleTest.php tests/Integration/PostgresAuthLifecycleTest.php; \
    cp /opt/raven-source/database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php; \
    cp /opt/raven-source/database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php; \
    cp /opt/raven-source/database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php; \
    cp /opt/raven-source/database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php; \
    cp /opt/raven-source/database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php; \
    cp /opt/raven-source/docker/check-database-target.php /usr/local/bin/raven23-check-database-target.php; \
    cp /opt/raven-source/docker/verify-auth-schema.php /usr/local/bin/raven23-verify-auth-schema.php; \
    cp /opt/raven-source/docker/router.php /usr/local/bin/raven23-router.php; \
    cp /opt/raven-source/docker/start.sh /usr/local/bin/raven23-start; \
    chmod +x /usr/local/bin/raven23-start; \
    for file in \
      app/Models/User.php app/Support/Raven23AuthSchema.php app/Support/GoogleIdTokenVerifier.php \
      config/database.php config/cache.php app/Http/Controllers/Api/AuthController.php \
      bootstrap/app.php routes/api.php tests/Feature/AuthLifecycleTest.php \
      tests/Integration/PostgresAuthLifecycleTest.php database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
      database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
      database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
      database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
      /usr/local/bin/raven23-check-database-target.php /usr/local/bin/raven23-verify-auth-schema.php \
      /usr/local/bin/raven23-router.php /usr/local/bin/raven23-start; do php -l "$file"; done; \
    grep -Fq "'default' => env('APP_ENV') === 'testing' ? 'array' : 'file'" config/cache.php; \
    DB_URL='postgresql://raven23-test:placeholder@db.invalid:5432/neondb?sslmode=require' php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $config = require "config/database.php"; if (($config["connections"]["pgsql"]["url"] ?? null) !== getenv("DB_URL")) { fwrite(STDERR, "PostgreSQL DB_URL configuration check failed.\\n"); exit(1); } echo "PostgreSQL DB_URL configuration check passed.\\n";'; \
    composer dump-autoload --optimize; \
    APP_ENV=testing php artisan test tests/Feature/AuthLifecycleTest.php; \
    php artisan route:list --path=api/v1 --except-vendor > /tmp/raven23-routes.txt; \
    for route in 'api/v1/auth/register' 'api/v1/auth/login' 'api/v1/auth/guest' 'api/v1/user/profile'; do grep -Fq "$route" /tmp/raven23-routes.txt || { echo "Missing API route: $route" >&2; exit 1; }; done

# Run this image against an ephemeral PostgreSQL service supplied by CI.
# Tests must never write to the Neon deployment database.
FROM build AS postgres-test

WORKDIR /opt/raven/app
CMD ["php", "artisan", "test", "tests/Integration/PostgresAuthLifecycleTest.php"]

FROM php:8.4-cli-bookworm AS runtime

ARG RAVEN23_SOURCE_COMMIT=unknown
ENV RAVEN23_SOURCE_COMMIT=${RAVEN23_SOURCE_COMMIT}

RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libxml2-dev libonig-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install pdo_pgsql mbstring xml curl opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=build /opt/raven/app /var/www/html
COPY --from=build /usr/local/bin/raven23-start /usr/local/bin/raven23-start
COPY --from=build /usr/local/bin/raven23-check-database-target.php /usr/local/bin/raven23-check-database-target.php
COPY --from=build /usr/local/bin/raven23-verify-auth-schema.php /usr/local/bin/raven23-verify-auth-schema.php
COPY --from=build /usr/local/bin/raven23-router.php /usr/local/bin/raven23-router.php

RUN chown -R www-data:www-data storage bootstrap/cache

ENV PORT=10000 DB_CONNECTION=pgsql
EXPOSE 10000
CMD ["/usr/local/bin/raven23-start"]
