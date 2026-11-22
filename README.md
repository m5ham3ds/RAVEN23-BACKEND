# RAVEN23 Backend

Laravel 13 API for the RAVEN23 Android application.

## Project and dependencies

This repository contains the Laravel application itself: framework bootstrap, Artisan entrypoint, routes, configuration, migrations, tests and Composer manifests.

- Framework: Laravel 13
- PHP: 8.4 in CI and the production image
- HTTP runtime: FrankenPHP + Caddy
- Database: PostgreSQL
- Local feature tests: isolated in-memory SQLite
- Authentication: Laravel Sanctum and Google ID-token verification

Dependencies are installed from the committed `composer.lock`. Production builds must never run `composer update`.

## Local development

Requirements: PHP 8.3+, Composer 2, and PHP extensions including cURL, DOM, Fileinfo, Mbstring, PDO, OpenSSL and XML.

```sh
composer validate --strict
composer install
cp .env.example .env
php artisan key:generate
php artisan route:list --path=api/v1
php artisan test tests/Feature
```

The feature suite uses in-memory SQLite and does not require a live PostgreSQL service or provider credentials. For PostgreSQL integration tests, use a newly created disposable local database only:

```sh
DB_CONNECTION=pgsql DB_URL='postgresql://user:password@127.0.0.1:5432/raven23_test?sslmode=disable' \
  php artisan test tests/Integration/PostgresAuthLifecycleTest.php
```

Never point integration tests at a production database.

## Production container

The production image is built from the checked-in Laravel project and runs FrankenPHP/Caddy on port `10000`. It uses a non-root `raven:raven` account and handles `SIGTERM` through the foreground server process. HTTPS should terminate at the trusted ingress/load balancer; the container's Caddy listener is plain HTTP on the internal network by default.

Required runtime values:

- `APP_KEY`: stable Laravel encryption key.
- `DB_CONNECTION=pgsql`.
- Either `DB_URL` as a PostgreSQL URI, or the standard `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` fields.
- `PORT`: optional, default `10000`; non-root runtime ports must be in range 1024–65535.

Recommended persistent service defaults:

- `CACHE_STORE=database`
- `SESSION_DRIVER=database`
- `QUEUE_CONNECTION=database`

These share durable PostgreSQL state across replacement containers. Do not use `file` cache, file sessions, or local-disk queues as production persistence. Durable user uploads require object storage and are covered by the later storage phase.

Set `TRUSTED_PROXIES` to a comma-separated list of exact proxy IP addresses or CIDR ranges when the application is behind a TLS-terminating proxy. It is empty by default; the application does not trust arbitrary forwarded headers.

The image contains no provider-specific credentials, URLs, Render/Neon host assumptions, or database migration URL.

## Liveness, readiness and migration lifecycle

- `GET /up`: liveness only. It confirms Laravel has booted and does not require the database.
- `GET /ready`: readiness. It checks PostgreSQL connectivity and required authentication schema columns. It returns HTTP 503 when the database or schema is unavailable.
- `GET /api/v1/health`: API health compatibility response with generic source-commit metadata and the readiness result.

**Web container startup does not connect to PostgreSQL, write probe accounts/tokens, or run migrations.** This prevents an unknown or incorrectly configured database from being modified merely because a web container starts or restarts.

Run migrations as a separate, explicit release task after verifying the target database and backup policy. The task should use the same database/schema as the web service; when a provider supplies a direct/non-pooled connection for migrations, pass it only to the one-off migration task as `DB_URL`, not as an extra permanent runtime secret.

Example using the same image and an explicitly injected migration environment:

```sh
docker run --rm \
  --env APP_ENV=production \
  --env APP_KEY \
  --env DB_CONNECTION=pgsql \
  --env DB_URL \
  raven23-backend:release \
  php artisan migrate --force
```

The command above is an operator-invoked one-off task. It is not run by the web entrypoint. Confirm the database target before running it; never run it against production as an unreviewed test.

A queue worker is also a separate process using the same image and shared environment:

```sh
php artisan queue:work --tries=3 --timeout=90
```

Do not combine a long-lived queue worker and HTTP server in the same container process.

## Docker validation

```sh
docker build -t raven23-backend:local .
docker build --target postgres-test -t raven23-backend:postgres-test .
```

The default build validates Composer, PHP extensions and syntax, runs a clean SQLite migration and Feature suite, and verifies API routes. GitHub Actions additionally uses an isolated PostgreSQL 16 service to run database migrations and authentication lifecycle tests, launches the production FrankenPHP image against that temporary database, and checks liveness/readiness plus the non-root runtime user.

No Render or Neon service is needed for builds or CI.

## API contract

The Flutter application expects its API base URL to end in `/api/v1/`.

Implemented account endpoints:

- `POST /api/v1/auth/register`, `/login`, `/google`, `/guest`
- `POST /api/v1/auth/forgot-password`, `/reset-password` (unavailable until email delivery is configured)
- `GET /api/v1/users/check-username`
- Authenticated: `POST /api/v1/auth/logout`
- Authenticated: `GET/PUT /api/v1/user/profile`
- Authenticated: `GET/PUT /api/v1/user/preferences` (account locale and IANA timezone)
- Authenticated: `GET /api/v1/user/data-export` (server-held account, profile, preference and device data; excludes credentials and tokens)
- Authenticated: `POST /api/v1/user/change-email`, `/change-password`
- Authenticated: `DELETE /api/v1/user/account`

A valid `guest_token` upgrades the existing guest row during email or Google registration, preserving its account ID and revoking the old anonymous token. Device metadata (`device_id`, `device_name`, `platform`) is stored with Sanctum tokens, and re-authentication on the same installation replaces that installation's earlier token. The account also keeps one `user_devices` installation record per owner/device ID with first/last-seen timestamps; token sessions remain in `personal_access_tokens`.

Account data uses separate `user_profiles` and `user_preferences` records while preserving the legacy `users.profile_id` and `users.photo_url` fields for current Android response compatibility. Only locale and timezone are account-level preferences in this phase. Player, theme, audio/subtitle and other installation settings remain local to the device unless a later approved product contract says otherwise. Server timestamps are stored in UTC; the saved IANA `timezone` is for presentation and does not override the database/session timezone.

Google sign-in requires a verified Google identity token. A synthetic token accepted by a test double does not prove that Android OAuth is configured correctly.

## Current limitations

Password-reset email delivery remains unavailable until an outbound provider is configured. Plan-based maximum device limits and an active-device listing endpoint are not implemented. Do not consider the backend production-ready until real-device Google sign-in, email verification/reset delivery, abuse controls, backups, monitoring and object storage are completed.
