# RAVEN23 Backend API v1 Contract

**Contract version:** 1  
**Base path:** \`/api/v1\`  
**Status:** Implemented route manifest and automated regression coverage; update only with an intentional client-compatible change.

## Transport and shared behavior

All API endpoints return JSON. Protected routes use Laravel Sanctum bearer tokens:

\`\`\`http
Authorization: Bearer <sanctum-token>
Accept: application/json
\`\`\`

Unauthenticated access to protected routes returns HTTP 401. Request validation failures return HTTP 422 with Laravel's JSON validation envelope:

\`\`\`json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Validation message"]
  }
}
\`\`\`

The API version is part of the path, not inferred from a Render/AWS provider hostname. Server timestamps use ISO-8601 UTC unless a synchronization field explicitly documents an integer UTC timestamp.

## Canonical route inventory

The machine-readable route/method/authentication list is \`contracts/api-v1.json\`. Automated tests compare every entry against Laravel's registered routes and verify the Sanctum middleware boundary.

| Method | Route | Access | Contract purpose |
|---|---|---|---|
| GET | \`/health\` | Public | API health/readiness summary |
| GET | \`/billing/plans\` | Public | Non-purchasable catalog while commercial terms are unapproved |
| POST | \`/auth/register\` | Public | Account creation and token issue |
| POST | \`/auth/login\` | Public | Password authentication |
| POST | \`/auth/google\` | Public | Verified Google authentication |
| POST | \`/auth/guest\` | Public | Guest account and token |
| POST | \`/auth/forgot-password\` | Public | Non-enumerating recovery request |
| POST | \`/auth/reset-password\` | Public | One-time password reset |
| POST | \`/auth/verify-email\` | Public | One-time email verification |
| GET | \`/users/check-username\` | Public | Username availability |
| POST | \`/auth/logout\` | Bearer | Revoke current token |
| GET / PUT | \`/user/profile\` | Bearer | Read/update allowed profile fields |
| GET / PUT | \`/user/preferences\` | Bearer | Read/update preferences |
| GET | \`/user/data-export\` | Bearer | Export authenticated owner's data |
| GET | \`/user/entitlements\` | Bearer | Server-calculated access |
| GET | \`/wallet\` | Bearer | Read-only wallet state |
| GET | \`/wallet/transactions\` | Bearer | Owner-scoped transaction history |
| POST | \`/sync/bookmarks\` | Bearer | Bookmark synchronization |
| POST | \`/sync/recently-watched\` | Bearer | Playback progress synchronization |
| POST | \`/sync/playlists\` | Bearer | Playlist synchronization |
| POST | \`/sync/preferences\` | Bearer | Preference revision synchronization |
| POST | \`/sync/wellness\` | Bearer | Wellness sessions and daily summaries |

The full manifest also includes device/session management, push subscription registration, private media and account change routes.

## Response contracts locked by regression tests

### Health

\`GET /api/v1/health\` returns HTTP 200 when the API database/schema checks pass:

\`\`\`json
{
  "success": true,
  "service": "raven23-api",
  "version": "1",
  "database": "connected",
  "schema": "ready"
}
\`\`\`

Unavailable/incomplete readiness returns HTTP 503 and \`success: false\`; infrastructure internals are not exposed.

### Registration and user identity

\`POST /api/v1/auth/register\` returns HTTP 201. The response includes \`success\`, \`token\`, \`user\`, \`email_verification_required\` and \`verification_email_sent\`.

The \`user\` object fields are: \`id\`, \`name\`, \`email\`, \`username\`, \`joined_at\`, \`created_at\`, \`updated_at\`, \`profile_id\`, \`photo_url\`, \`provider\`, \`is_verified\`, and \`is_account_verified\`. Password hashes, token hashes and recovery secrets are never part of this object.

### Profile and preferences

- \`GET /user/profile\`: \`{ success, user }\`.
- \`GET /user/preferences\`: \`{ success, preferences }\`; preferences contain \`locale\`, \`timezone\`, \`revision\`, and \`updated_at\`.
- \`PUT /user/preferences\`: requires at least one of \`locale\` or \`timezone\`; invalid/missing fields use the shared HTTP 422 envelope.

### Entitlements and wallet

- \`GET /user/entitlements\`: \`{ success, entitlements, server_time, refresh_after_seconds }\`.
- \`GET /wallet\`: \`{ success, entitlements, server_time, refresh_after_seconds, subscription, wallet, pending_topups }\`.
- Wallet fields: \`id\`, \`currency\`, \`balance_cents\`, \`spendable_cents\`, \`status\`, \`frozen\`, \`revision\`, and \`periods_fundable\`.
- \`GET /wallet/transactions\`: \`{ success, transactions, next_cursor }\`.

No public wallet-write route, checkout route, top-up route, or purchase permission is included in v1.

## Synchronization compatibility

The sync endpoints require Sanctum authentication and derive the owner from the token. Client-provided owner identifiers do not grant access to another account. Cursor, revision and validation failures retain endpoint-specific error codes where defined, and ordinary Laravel field validation keeps the shared HTTP 422 envelope.

## Contract change policy

1. Add or alter a route in \`contracts/api-v1.json\` in the same commit as its controller/route change.
2. Update \`ApiV1ContractTest\` for method, auth boundary, response keys, and error behavior.
3. Preserve existing response fields consumed by clients unless a separately versioned migration is deliberately introduced.
4. Do not place provider-specific hostnames, secret values, bearer tokens, PII or production database values in the manifest or test fixtures.
5. CI must pass on the exact commit before the contract change is considered accepted.
