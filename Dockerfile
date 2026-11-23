# syntax=docker/dockerfile:1.7

# Build and validate the committed Laravel application with the same PHP
# minor version used by the production runtime.
FROM php:8.4-cli-bookworm AS build

# Avoid saturating GitHub's package archive endpoint during parallel CI image builds.
ENV COMPOSER_MAX_PARALLEL_HTTP=4

RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev libsqlite3-dev libxml2-dev libonig-dev libcurl4-openssl-dev \
      libzip-dev unzip git ca-certificates \
    && docker-php-ext-install curl dom mbstring opcache pdo_pgsql pdo_sqlite xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /opt/raven
COPY . .

RUN set -eu; \
    for file in composer.json composer.lock artisan bootstrap/app.php bootstrap/providers.php \
      public/index.php routes/api.php routes/web.php \
      app/Http/Controllers/Api/AuthController.php app/Http/Controllers/Api/HealthController.php \
      app/Http/Middleware/RequestCorrelationId.php app/Providers/AppServiceProvider.php \
      app/Models/UserProfile.php app/Models/UserPreference.php app/Models/UserDevice.php \
      app/Models/UserMedia.php app/Jobs/DeletePrivateObjectJob.php app/Http/Controllers/Api/UserMediaController.php \
      database/migrations/2026_11_23_000014_create_user_media_table.php \
      config/cors.php config/security.php \
      app/Support/GoogleIdTokenVerifier.php app/Support/Raven23AuthSchema.php \
      database/migrations/0001_01_01_000000_create_users_table.php \
      database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php \
      database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php \
      database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php \
      database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php \
      database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php \
      database/migrations/2026_11_22_000006_create_email_verification_tokens_table.php \
      database/migrations/2026_11_22_000007_create_user_data_foundation_tables.php \
      app/Notifications/EmailVerificationNotification.php app/Notifications/PasswordResetNotification.php \
      tests/TestCase.php tests/Feature/AuthLifecycleTest.php tests/Feature/UserDataFoundationTest.php \
      tests/Feature/UserDataFoundationMigrationTest.php \
      tests/Feature/LaravelFoundationTest.php tests/Feature/RuntimeHealthTest.php \
      tests/Feature/SecurityResponseTest.php tests/Feature/QueuedNotificationsTest.php tests/Feature/PrivateObjectStorageTest.php tests/Feature/UserMediaTest.php \
      tests/Integration/PostgresAuthLifecycleTest.php tests/Integration/PostgresUserMediaTest.php docker/Caddyfile docker/entrypoint.sh \
      docker/queue-worker.sh docker/php-production.ini; do \
        test -s "$file" || { echo "Required project file missing or empty: $file" >&2; exit 1; }; \
    done

RUN composer validate --strict \
    && composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    && composer audit --no-interaction \
    && php -r '$required = ["curl","ctype","dom","fileinfo","filter","hash","mbstring","openssl","pcre","PDO","session","tokenizer","xml","pdo_pgsql","pdo_sqlite"]; foreach ($required as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing required PHP extension: ".$extension.PHP_EOL); exit(1); } }'

# Source-level checks and deterministic SQLite Feature suite.
RUN set -eu; \
    touch .env; \
    find app bootstrap config database docker public routes tests -type f -name '*.php' -print0 \
      | xargs -0 -r -n1 php -l; \
    php artisan --version; \
    sh -n docker/queue-worker.sh; \
    APP_ENV=testing APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
      DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
      CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
      php artisan migrate:fresh --force; \
    APP_ENV=testing APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
      DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
      CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
      php artisan test tests/Feature --display-warnings; \
    php artisan route:list --except-vendor > /tmp/raven23-routes.txt; \
    for route in 'auth/register' 'auth/login' 'auth/google' \
      'auth/guest' 'user/profile' 'user/media' 'user/change-password' 'ready' 'api/v1/health'; do \
        grep -Fq "$route" /tmp/raven23-routes.txt || { echo "Missing route: $route" >&2; exit 1; }; \
    done

# Isolated PostgreSQL 16 integration target used by GitHub Actions only.
FROM build AS postgres-test

WORKDIR /opt/raven
CMD ["php", "artisan", "test", "tests/Integration/PostgresAuthLifecycleTest.php"]

# Production runtime: pinned FrankenPHP/PHP 8.4 image, Caddy HTTP server,
# PHP production settings, and no Render/Neon-specific startup behaviour.
FROM dunglas/frankenphp:1.13.6-php8.4-trixie@sha256:bbe6057527f8dd9e270d9e2a33315f26b264ce8b0dbd19b67b0855a7899960ec AS runtime

ENV COMPOSER_MAX_PARALLEL_HTTP=4

ARG RAVEN23_SOURCE_COMMIT=unknown
ENV RAVEN23_SOURCE_COMMIT=${RAVEN23_SOURCE_COMMIT}
ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=build /opt/raven /var/www/html
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/raven23-entrypoint
COPY docker/queue-worker.sh /usr/local/bin/raven23-queue-worker
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-raven23-production.ini

RUN set -eu; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    install-php-extensions curl dom mbstring opcache pdo_pgsql xml; \
    composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader; \
    apt-get update && \
    apt-get upgrade -y --no-install-recommends && \
    apt-get install -y --no-install-recommends libcap2-bin; \
    groupadd --gid 10001 raven; \
    useradd --uid 10001 --gid 10001 --create-home --no-log-init --shell /usr/sbin/nologin raven; \
    setcap -r /usr/local/bin/frankenphp; \
    mkdir -p /data /config storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    chown -R raven:raven /data /config storage bootstrap/cache; \
    chmod 755 /usr/local/bin/raven23-entrypoint /usr/local/bin/raven23-queue-worker; \
    apt-get purge -y libcap2-bin linux-libc-dev; \
    apt-get autoremove -y --purge; \
    rm -rf /var/lib/apt/lists/*

USER raven:raven

EXPOSE 10000
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD ["php", "-r", "$headers = @get_headers('http://127.0.0.1:' . (getenv('PORT') ?: '10000') . '/up'); exit(is_array($headers) && str_contains($headers[0] ?? '', ' 200 ') ? 0 : 1);"]

ENTRYPOINT ["/usr/local/bin/raven23-entrypoint"]
