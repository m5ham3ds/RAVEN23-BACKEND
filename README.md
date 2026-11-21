# RAVEN23 Backend

Laravel 13 API for the RAVEN23 Android application.

## Project and dependencies

This repository is the source of the Laravel application itself. It includes the framework bootstrap, Artisan entrypoint, routes, configuration, migrations, tests and Composer dependency manifest. Docker builds this checked-in project; it must not generate a fresh Laravel skeleton at image-build time.

- Framework: Laravel 13
- PHP support: 8.3 or newer; CI and Docker use PHP 8.4
- Database: PostgreSQL in deployed environments
- Local feature/migration tests: isolated SQLite
- Authentication: Laravel Sanctum and Google ID-token verification

Dependencies are defined in \`composer.json\` and installed from the checked-in \`composer.lock\`. Never run \`composer update\` in production builds; dependency updates are reviewed and lock-file changes are committed separately.

## Local development

Requirements: PHP 8.3+, Composer 2, and the required PHP extensions (including cURL, DOM, Fileinfo, Mbstring, PDO, OpenSSL and XML).

\`\`\`sh
composer validate --strict
composer install
cp .env.example .env
php artisan key:generate
php artisan route:list --path=api/v1
php artisan test tests/Feature
\`\`\`

Feature tests use an in-memory SQLite database and do not require Neon credentials. For PostgreSQL integration tests, use a new disposable local/staging database only. Set \`DB_CONNECTION=pgsql\` and \`DB_URL\` to that disposable database, apply migrations there, then run:

\`\`\`sh
php artisan test tests/Integration/PostgresAuthLifecycleTest.php
\`\`\`

Never point integration tests at a production database.

## Docker validation

\`\`\`sh
docker build -t raven23-backend:local .
docker build --target postgres-test -t raven23-backend:postgres-test .
\`\`\`

The default Docker build validates Composer, checks required PHP extensions, syntax-checks PHP files, runs a clean SQLite migration, runs the Feature suite, and checks required API routes. The PostgreSQL target is intended for GitHub Actions with its isolated PostgreSQL 16 service.

## Existing deployment configuration

The existing container startup contract remains unchanged: production expects \`APP_KEY\`, \`DB_URL\` (pooled request connection), and \`DB_MIGRATION_URL\` (direct migration connection), validates that both URIs target the same PostgreSQL database/schema, applies pending migrations, performs the existing auth-schema verification probe, then starts the PHP router on \`PORT\` (default 10000).

The startup probe creates and removes a uniquely generated temporary user and Sanctum token to verify write access. It must only run against an intentionally configured deployment database. This Phase 01 code change does not deploy the image or invoke that startup process against production.

## API base URL

The Flutter application expects the Laravel API base URL to end in \`/api/v1/\`.

Health routes: \`GET /up\` and \`GET /api/v1/health\`.

Implemented account endpoints:
- \`POST /api/v1/auth/register\`, \`/login\`, \`/google\`, \`/guest\`
- \`POST /api/v1/auth/forgot-password\`, \`/reset-password\` (currently unavailable until email delivery is configured)
- \`GET /api/v1/users/check-username\`
- Authenticated: \`POST /api/v1/auth/logout\`
- Authenticated: \`GET/PUT /api/v1/user/profile\`
- Authenticated: \`POST /api/v1/user/change-email\`, \`/change-password\`
- Authenticated: \`DELETE /api/v1/user/account\`

A valid \`guest_token\` upgrades the existing guest row during email or Google registration, preserving its account ID and revoking the old anonymous token. Device metadata (\`device_id\`, \`device_name\`, \`platform\`) is stored with Sanctum tokens, and re-authentication on the same installation replaces that installation's earlier token.

Google sign-in requires a verified Google identity token. A synthetic token accepted by a test double is not proof of a working Android OAuth configuration.

## Current limitations

Password-reset email delivery remains unavailable until an outbound provider is configured. Plan-based maximum device limits and an active-device listing endpoint are not implemented. Do not consider the backend production-ready until real-device Google sign-in, email verification/reset delivery, abuse controls, backups and monitoring are completed.
