# RAVEN23 Backend

Laravel 13 API for the RAVEN23 Android application.

## Runtime
- Laravel 13 / PHP 8.3+
- PostgreSQL (Neon)
- Laravel Sanctum bearer tokens
- Google ID-token verification

## Render deployment
Create a Docker Web Service from this repository. Use the repository root as the Docker build context and leave the Root Directory empty. Render detects the root `Dockerfile`.

Set these environment variables in Render (never commit secrets):
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` — generate a Laravel key and keep it stable
- `APP_URL=https://YOUR-SERVICE.onrender.com`
- `LOG_CHANNEL=stderr`
- `DB_CONNECTION=pgsql`
- `DB_URL` — pooled Neon PostgreSQL connection URI for app requests, with `sslmode=require`
- `DB_MIGRATION_URL` — direct (non-pooled) Neon PostgreSQL URI for startup migrations, with `sslmode=require`
- `GOOGLE_CLIENT_ID` — the RAVEN23 Web OAuth client ID

The container runs the repository's stable migrations on startup through `DB_MIGRATION_URL` (direct connection), then serves web requests through `DB_URL` (pooled connection). Sanctum's token-table migration validates and preserves an existing `personal_access_tokens` table instead of recreating it. The server listens on Render's `PORT` (default 10000).

## API base URL
The Flutter app expects a base URL ending in `/api/v1/`.

Health: `GET /up` and `GET /api/v1/health`. The API health route verifies the PostgreSQL connection and required `users` / `personal_access_tokens` columns, returning `database=connected` and `schema=ready` only when both are usable.

## Implemented account endpoints
- `POST /api/v1/auth/register`
  Registration and Sanctum token issuance run in one database transaction so a failed token insert cannot leave a partial account.\n- `POST /api/v1/auth/login`
- `POST /api/v1/auth/google`
- `POST /api/v1/auth/guest`
- `POST /api/v1/auth/logout`
- `GET /api/v1/user/profile`
- `PUT /api/v1/user/profile`
- `POST /api/v1/user/change-email`
- `POST /api/v1/user/change-password`
- `DELETE /api/v1/user/account`
- `GET /api/v1/users/check-username`

Password-reset email delivery is deliberately reported as unavailable until an outbound email provider is configured. Unexpected API server errors return a safe reference ID. Render logs record that ID with exception type, source file/line and SQLSTATE only; SQL statements, bindings, passwords, emails and request bodies are intentionally excluded. Do not treat this initial backend as production-ready until runtime/API tests, mail delivery, abuse controls, backups, and monitoring are completed.
