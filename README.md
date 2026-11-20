# RAVEN23 Backend

Laravel 13 API for the RAVEN23 Android application.

## Runtime
- Laravel 13 / PHP 8.3+
- PostgreSQL (Neon)
- Laravel Sanctum bearer tokens
- Google ID-token verification

## Render deployment
Create a Docker Web Service from this repository. Use the repository root as the Docker build context and leave the Root Directory empty. Render detects the root \`Dockerfile\`.

Set these environment variables in Render (never commit secrets):
- \`APP_ENV=production\`
- \`APP_DEBUG=false\`
- \`APP_KEY\` — generate a Laravel key and keep it stable
- \`APP_URL=https://YOUR-SERVICE.onrender.com\`
- \`LOG_CHANNEL=stderr\`
- \`DB_CONNECTION=pgsql\`
- \`DB_URL\` — pooled Neon PostgreSQL connection URI for app requests, with \`sslmode=require\`
- \`DB_MIGRATION_URL\` — direct (non-pooled) Neon PostgreSQL URI for startup migrations, with \`sslmode=require\`
- \`GOOGLE_CLIENT_ID\` — optional override for the matching RAVEN23 Web OAuth client ID (the public client ID is bundled as the default)

The pooled and direct Neon URLs must point to the same project, branch, database and schema. Startup validates this relationship before running migrations. It applies migrations through \`DB_MIGRATION_URL\`, then verifies the account and Sanctum schema through the runtime \`DB_URL\`. The service refuses to start if the two connections do not expose the same completed schema.

The account-schema reconciliation is idempotent. It adds missing RAVEN23 account fields, backfills missing/invalid usernames for existing users without deleting accounts, and ensures the Sanctum \`personal_access_tokens\` table exists. Existing valid usernames and token records are preserved. The device-session migration adds nullable installation metadata to Sanctum tokens; it does not delete existing token rows.

The server listens on Render's \`PORT\` (default 10000). Production starts PHP's built-in HTTP server directly with \`docker/router.php\`; do not replace this with \`php artisan serve\`. Laravel's development ServeCommand filters child-process environment variables when a \`.env\` file exists, which can remove Render secrets such as \`DB_URL\` and \`GOOGLE_CLIENT_ID\` from HTTP requests even though startup commands can read them.

## API base URL
The Flutter app expects a base URL ending in \`/api/v1/\`.

Health: \`GET /up\` and \`GET /api/v1/health\`. The API health route verifies the PostgreSQL connection and the required \`users\` / \`personal_access_tokens\` columns, including device identity metadata, returning \`database=connected\` and \`schema=ready\` only when they are usable.

## Implemented account endpoints
- \`POST /api/v1/auth/register\` — creates an email account and Sanctum session atomically (HTTP 201).
- \`POST /api/v1/auth/login\` — verifies the password, issues a session and optionally consumes a guest token.
- \`POST /api/v1/auth/google\` — verifies the Google ID-token signature/audience/expiry, requires a verified email, and creates or reuses the matching Google account.
- \`POST /api/v1/auth/guest\` — creates a server-side guest identity and Sanctum token.
- \`POST /api/v1/auth/logout\` — revokes only the presented token.
- \`GET /api/v1/user/profile\` — validates the current bearer session.
- \`PUT /api/v1/user/profile\`
- \`POST /api/v1/user/change-email\`
- \`POST /api/v1/user/change-password\` — revokes every active token after password change.
- \`DELETE /api/v1/user/account\` — revokes all Sanctum tokens before deleting the account.
- \`GET /api/v1/users/check-username\`

A valid \`guest_token\` upgrades the existing guest database row during email or Google registration, preserving its account ID and revoking the old anonymous token. When a guest signs into an already-existing account, the guest row and its tokens are removed after the credentials/Google identity are verified. Device metadata (\`device_id\`, \`device_name\`, \`platform\`) is stored with the Sanctum token; signing in again from the same installation replaces that installation's earlier token. A supplied \`replace_device_id\` is scoped to the authenticated account and cannot revoke another user's session.

Google sign-in does not silently link to an existing password-only account. It returns a conflict so the user signs in with the existing password first. A real Google ID token must come from the Android Google Sign-In flow; a mocked-token test is not proof of a working device OAuth configuration.

## Tests and current limitations
The Docker build runs the authentication feature suite against an isolated in-memory SQLite database, validates required source files and production routes, then runs a separate PostgreSQL lifecycle suite through the same Laravel bootstrap used by Render. The PostgreSQL suite requires the GitHub Actions secret `NEON_DB_URL`, creates uniquely named test accounts and Sanctum sessions, and cleans up only those generated records. Configure that secret to point to a dedicated non-production Neon database; do not point it at the production database. The post-deployment smoke workflow is deliberately GET-only: it checks live health, schema readiness, commit identity and route matching without registering users, issuing tokens, or changing production data.

Password-reset email delivery remains unavailable until an outbound email provider is configured. The current code does not yet enforce a plan-based maximum number of simultaneous devices or expose a full active-device listing endpoint; it does persist installation IDs and supports revoking a selected token ID on that same user's account. Do not treat the backend as production-ready until Google sign-in is verified with a real Android device, email verification/reset delivery is implemented, and abuse controls/backups/monitoring are completed.
