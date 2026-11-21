# syntax=docker/dockerfile:1.7

# Build and test the exact Laravel application committed in this repository.
FROM php:8.4-cli-bookworm AS build

RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libsqlite3-dev libxml2-dev libonig-dev libcurl4-openssl-dev \
      libzip-dev unzip git ca-certificates \
    && docker-php-ext-install curl dom mbstring opcache pdo_pgsql pdo_sqlite xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/raven
COPY . .

# Fail immediately when a required manifest or framework entrypoint is absent.
RUN set -eu; \
    for file in composer.json composer.lock artisan bootstrap/app.php bootstrap/providers.php \
      public/index.php routes/api.php app/Http/Controllers/Api/AuthController.php \
      app/Support/GoogleIdTokenVerifier.php app/Support/Raven23AuthSchema.php \
      database/migrations/0001_01_01_000000_create_users_table.php \
      database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
      database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
      database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
      database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
      database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php \
      tests/TestCase.php tests/Feature/AuthLifecycleTest.php \
      tests/Feature/LaravelFoundationTest.php tests/Integration/PostgresAuthLifecycleTest.php \
      docker/check-database-target.php docker/verify-auth-schema.php docker/router.php docker/start.sh; do \
        test -s "$file" || { echo "Required project file missing or empty: $file" >&2; exit 1; }; \
    done

RUN composer validate --strict \
    && composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && php -r '$required = ["curl","ctype","dom","fileinfo","filter","hash","mbstring","openssl","pcre","PDO","session","tokenizer","xml","pdo_pgsql","pdo_sqlite"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing required PHP extension: ".$extension.PHP_EOL); exit(1); } }'

# PHP syntax, boot, route registration and isolated SQLite schema migration checks.
RUN set -eu; \
    find app bootstrap config database docker public routes tests -type f -name '*.php' -print0 \
      | xargs -0 -r -n1 php -l; \
    php artisan --version; \
    APP_ENV=testing APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
      DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_MIGRATION_URL= \
      CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
      php artisan migrate:fresh --force; \
    APP_ENV=testing APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
      DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= DB_MIGRATION_URL= \
      CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
      php artisan test tests/Feature; \
    php artisan route:list --path=api/v1 --except-vendor > /tmp/raven23-routes.txt; \
    for route in 'api/v1/auth/register' 'api/v1/auth/login' 'api/v1/auth/google' \
      'api/v1/auth/guest' 'api/v1/user/profile' 'api/v1/user/change-password'; do \
        grep -Fq "$route" /tmp/raven23-routes.txt || { echo "Missing API route: $route" >&2; exit 1; }; \
    done

# Run this image against the ephemeral PostgreSQL 16 service supplied by CI only.
# It must never be pointed at the Neon deployment database.
FROM build AS postgres-test

WORKDIR /opt/raven
CMD ["php", "artisan", "test", "tests/Integration/PostgresAuthLifecycleTest.php"]

# Production runtime: install only production dependencies and retain the
# existing RAVEN23 startup validation and direct PHP router behavior.
FROM php:8.4-cli-bookworm AS runtime

ARG RAVEN23_SOURCE_COMMIT=unknown
ENV RAVEN23_SOURCE_COMMIT=${RAVEN23_SOURCE_COMMIT}

RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libxml2-dev libonig-dev libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install curl dom mbstring opcache pdo_pgsql xml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=build /opt/raven /var/www/html
COPY docker/start.sh /usr/local/bin/raven23-start
COPY docker/check-database-target.php /usr/local/bin/raven23-check-database-target.php
COPY docker/verify-auth-schema.php /usr/local/bin/raven23-verify-auth-schema.php
COPY docker/router.php /usr/local/bin/raven23-router.php

RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && chmod +x artisan /usr/local/bin/raven23-start \
    && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=10000 DB_CONNECTION=pgsql
EXPOSE 10000
CMD ["/usr/local/bin/raven23-start"]
