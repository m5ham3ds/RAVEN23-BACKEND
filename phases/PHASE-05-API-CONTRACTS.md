# Phase 05 — API Contracts

**Status:** Contract baseline for implementation; CI verification pending.
**API root:** `/api/v1`.
**Existing convention:** JSON object with top-level `success: boolean`; authenticated calls require the existing Sanctum Bearer token. Error responses use the common Laravel API validation/error renderer with request correlation ID.

## 1. Account preferences

### GET `/api/v1/user/preferences`

Authentication: required (`auth:sanctum`).

Success response:

```json
{
  "success": true,
  "preferences": {
    "locale": "ar",
    "timezone": "Africa/Khartoum",
    "updated_at": "2026-11-22T12:00:00.000000Z"
  }
}
```

An account with no saved locale returns `locale: null`. Timezone defaults to `UTC`. The endpoint always scopes through the authenticated account; it accepts no `user_id` selector.

### PUT `/api/v1/user/preferences`

Authentication: required (`auth:sanctum`). At least one field must be supplied.

Request:

```json
{
  "locale": "ar",
  "timezone": "Africa/Khartoum"
}
```

Validation:
- `locale`: optional per request, but required if timezone is absent; string 2–16 chars; supported language/script/region-tag pattern.
- `timezone`: optional per request, but required if locale is absent; valid IANA timezone, maximum 64 chars.
- Unknown fields are not mass-assigned.
- Repeating the same PUT is safe and leaves one row per user.

Success response:

```json
{
  "success": true,
  "preferences": {
    "locale": "ar",
    "timezone": "Africa/Khartoum",
    "updated_at": "2026-11-22T12:00:00.000000Z"
  }
}
```

An empty request or invalid locale/timezone returns HTTP 422 through the shared validation envelope, including `success: false`, `message`, `errors`, and `request_id`. Missing/invalid authentication returns HTTP 401.

Only locale and timezone are account-level preferences in Phase 05. Player, theme, subtitle/audio language, source order, and device-specific settings remain in the Android local preference store.

## 2. Account data export

### GET `/api/v1/user/data-export`

Authentication: required (`auth:sanctum`). No query-string owner ID is accepted.

Success response (format version 1):

```json
{
  "success": true,
  "data": {
    "format_version": 1,
    "exported_at": "2026-11-22T12:00:00.000000Z",
    "account": {
      "id": 42,
      "name": "RAVEN23 User",
      "email": "user@example.test",
      "username": "raven_user",
      "provider": "email",
      "is_guest": false,
      "is_verified": false,
      "is_account_verified": false,
      "email_verified_at": null,
      "created_at": "2026-11-20T10:00:00.000000Z"
    },
    "profile": {
      "profile_id": 0,
      "photo_url": null
    },
    "preferences": {
      "locale": null,
      "timezone": "UTC",
      "updated_at": "2026-11-22T12:00:00.000000Z"
    },
    "devices": []
  }
}
```

Export includes only current server-held data from the authenticated account: account fields safe for user export, profile, preferences, and known installation metadata.

Export MUST NOT include password hashes, remember tokens, Google subject IDs, bearer tokens, Sanctum token hashes, password-reset tokens, email-verification hashes, API secrets, or data belonging to another user.

Phase 05 export does not claim to include bookmarks, watch history/progress, playlists, wellness data, push subscriptions, billing, or media data because those server-side domains are not part of this phase.

## 3. Existing profile compatibility

Existing `GET /api/v1/user/profile` and `PUT /api/v1/user/profile` retain their current response shape and continue to expose `profile_id` and `photo_url` from the legacy `users` row for installed Android clients. `user_profiles` is the normalized storage record; the legacy columns remain during the compatibility period.

Registration accepts the Flutter profile fields `profile_id` and optional `photo_url`. The account ID, auth provider behavior, existing `success` envelope, and Sanctum token lifecycle do not change.

## 4. API versioning, timestamps and future pagination

- All endpoints remain under `/api/v1`; no existing route is renamed.
- All timestamps in these responses are serialized in UTC ISO-8601 format.
- The timezone preference is metadata for display; it does not change server-side time storage.
- The export is a bounded account snapshot in v1, not an incremental sync endpoint. Future collections with large histories must define cursor/revision pagination and idempotency with the Flutter consumer before implementation.
- No metadata-provider content payload is copied into account data in this phase.