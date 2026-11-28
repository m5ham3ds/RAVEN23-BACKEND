# Phase12A — Legacy Firebase Session Exchange Contract

**Status:** DESIGN SPECIFICATION ONLY — API route, verifier, persistence model, and tests are NOT IMPLEMENTED.  
**Date:** 2026-11-28  
**Backend baseline:** `m5ham3ds/RAVEN23-BACKEND@be14f83568e5abd51a9628d21b4e8a0c55f23d13`  
**Flutter source reviewed read-only:** `m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a`  
**Decision boundary:** Firebase remains a legacy source candidate; this document does not approve it as the migration source-of-truth or select/enable any external provider.

## 1. Verified client contract

The current Flutter client has an explicit legacy-session bridge:

- `FirebaseSessionBridge` runs only for a persisted non-anonymous Firebase user when there is no active Laravel session.
- It obtains a refreshed Firebase ID token through `LegacyFirebaseSession.idToken()`.
- `AuthRepository.exchangeFirebaseSession()` sends an unauthenticated `POST auth/firebase/exchange` request.
- The body contains `id_token` and the installation metadata returned by `deviceFields` (`device_id`, `device_name`, `platform`); it does not send a Laravel Bearer token or a `guest_token`.
- The client expects the ordinary Laravel session envelope consumed by `AuthSession.fromJson`: `success`, `token`, and `user`.
- The bridge then asks its local owner-migration callback to move `user:<firebase uid>` to `user:<Laravel user id>`.

The bridge tests expect:
- HTTP 401 with code `firebase_session_invalid` for a terminal invalid legacy identity;
- HTTP 409 with code `firebase_account_conflict` for an identity/account conflict;
- retryable network/5xx behavior that leaves the Firebase session available for another attempt.

The client does not send a guest token on this exchange. Therefore this endpoint cannot silently claim, delete, or merge a separate Laravel guest account. Any guest merge is a different, separately authenticated flow.

## 2. Confirmed Backend gap

At the reviewed Backend baseline:

- `routes/api.php` has `POST /auth/google`, `POST /auth/guest`, and email/password routes, but no `POST /auth/firebase/exchange`.
- `GoogleIdTokenVerifier` is configured against the Google OAuth Web client ID and verifies Google sign-in ID tokens. It is not a Firebase Secure Token verifier and must not be reused for Firebase ID tokens.
- `users` currently has `provider` and unique nullable `google_id`, but no Firebase UID mapping field/table.
- `AuthController::session()` centralizes device validation, normalized account data setup, Sanctum token issuance, and same-device token replacement. A future exchange should reuse the established session policy rather than create a parallel token lifecycle.
- The current client-side auth repository contract test does not list this endpoint among its shared endpoint cases; the bridge test checks the path/body and a mocked session response, not a real Backend implementation.

These are source-level findings only; no Firebase project, user account, or production database was accessed.

## 3. Token verification requirements

A future implementation MUST use a dedicated Firebase ID-token verification component. Decoding JWT claims without signature verification is never acceptable.

The verifier must be bound to an explicitly configured Firebase project and validate, at minimum:
1. the JWT signature against the trusted Firebase Secure Token signing keys, with safe key caching and key rotation handling;
2. exact issuer `https://securetoken.google.com/<project-id>`;
3. exact audience equal to the configured Firebase project ID;
4. non-empty subject/UID, valid issue/expiry times, and acceptable authentication time;
5. the expected token shape and any required tenant/project boundary.

The server must derive the Firebase UID and identity claims only from the verified token. It must ignore any client-supplied UID, email, provider, or verification flag.

**Revocation/disabled-account policy is still an approval item.** Signature and time validation alone do not provide an immediate server-side revocation check. Before enabling the flow, define whether the exchange must additionally check Firebase user disabled/revoked state and which approved verification mechanism and credentials, if any, are allowed. Do not claim revoked-token protection unless that check is actually implemented and tested.

## 4. Identity mapping and account-safety requirements

No identity may be selected by an unverified email or display name.

The implementation needs a durable, unique mapping from the Firebase project/UID identity to exactly one Laravel user. The mapping must be distinct from `google_id`; the Google subject and Firebase UID are different identity namespaces and must never share a column by convention.

Required behavior:
- A previously linked Firebase project/UID resolves to the same Laravel user on every exchange.
- Concurrent first exchanges for the same Firebase identity cannot create duplicate mappings or users.
- A Firebase identity that collides with an existing Laravel email/password account or a different Google identity must not take over that account silently. Return the documented conflict response and require a separately authenticated account-linking process.
- If a verified Firebase identity has no durable Laravel mapping, the create/link policy must be decided before implementation. Do not guess whether to create a new account, attach by email, or require manual sign-in.
- Missing email, unverified email, disabled/revoked identity, unexpected project/tenant, or ambiguous account ownership must fail closed.
- The server must never use the Firebase UID as the Laravel numeric user ID.

A normalized identity-link table or a dedicated Firebase UID column may satisfy the persistence requirement, but the final schema choice must be reviewed for existing account compatibility, uniqueness, rollback safety, and PostgreSQL behavior before a migration is authored.

## 5. Request, response, and lifecycle contract

**Proposed route:** `POST /api/v1/auth/firebase/exchange`, unauthenticated, HTTPS-only at deployment, with a dedicated rate limiter.

**Request fields:**
- `id_token`: required string, bounded length; never logged or persisted.
- `device_id`: optional UUID, following the current shared session validation.
- `device_name`: optional string, max 100 characters.
- `platform`: optional `android|ios|tv`.

The current Flutter exchange call does not send `guest_token` or `replace_device_id`; do not make server-side guest merge or device replacement depend on fields the client does not send.

**Success:** use the existing session envelope and the existing session issuance/device-limit behavior. The `user.id` returned in the envelope is the only target owner ID the client may use for local re-key.

**Failure contract:**

| HTTP | Code | Meaning | Client behavior |
|---|---|---|---|
| 401 | `firebase_session_invalid` | Signature/claims invalid, expired, or identity not accepted as an active legacy session | Terminal sign-in fallback |
| 409 | `firebase_account_conflict` | Verified identity maps ambiguously or conflicts with an existing account/link | Terminal sign-in fallback; no local owner move |
| 422 | validation envelope | Malformed request/device metadata | Do not issue session |
| 429 | rate limit + Retry-After | Too many exchange attempts | Retry later |
| 503 | service unavailable envelope | Trusted-key service unavailable or approved identity verification dependency unavailable | Keep legacy session and retry; do not misclassify as invalid identity |

Do not disclose whether a particular email or Laravel account exists in conflict messages. All API errors must follow the existing redacted response/request-ID policy. Never log raw ID tokens, authorization headers, Firebase UID, email, full JWT claims, or key material.

## 6. Local owner re-key is a separate transaction from server authentication

A successful server exchange does not prove that local SQLite/SharedPreferences migration succeeded.

The reviewed Flutter bridge has a critical retry edge:
- after server exchange, the client session is established;
- the bridge then awaits `moveLocalOwner(from, to)`;
- if that callback fails, a later bridge run sees an existing Laravel session and follows the branch that finishes/signs out the Firebase session without retrying the owner migration.

Therefore client acceptance must persist a separate **owner-rekey-pending/completed** state. The local move must be idempotent and resumable after process death, and the completion marker must be written only after all required stores/journals pass verification. An already-established Laravel session must not cause a pending owner-rekey job to be discarded.

The currently reviewed production callback rekeys Wellness ownership only. The other owner-suffixed SQLite stores and bookmark preference journals are not shown to be re-keyed by that callback. The client must not sign out/mark migration complete until the complete owner migration has durable evidence.

This client lifecycle fix belongs in `m5ham3ds/RAVEN23`; this Backend contract does not edit that repository.

## 7. Required synthetic test matrix

| ID | Test | Required result |
|---|---|---|
| FBE-001 | Valid token from configured project | One stable Laravel owner mapping and ordinary session envelope |
| FBE-002 | Wrong signature, issuer, audience, project, expired/not-yet-valid token, missing subject | 401 `firebase_session_invalid`; no user/mapping/token write |
| FBE-003 | Key fetch/verification dependency unavailable | 503; no account mutation; safe retry signal |
| FBE-004 | Same Firebase UID exchanged repeatedly | Same Laravel user; no duplicate mapping/user; session renewal follows established device policy |
| FBE-005 | Parallel first exchange for same UID | Unique constraint/transaction yields one mapping; loser resolves same owner or a safe retry without duplicates |
| FBE-006 | Verified Firebase email conflicts with a password or Google account | 409 `firebase_account_conflict`; no silent link, token, or guest deletion |
| FBE-007 | Unknown Firebase UID with no approved create/link policy | Fail closed; no guessed account creation or email-based attachment |
| FBE-008 | Missing/unverified email, disabled/revoked user, unsupported tenant | Rejected according to the approved policy; no fallback to client claims |
| FBE-009 | Valid token with invalid device metadata | 422; no session or account-link side effect |
| FBE-010 | Rate limit exceeded | 429 with Retry-After; retryable client behavior |
| FBE-011 | Repeated request with client-supplied UID/email fields | Server ignores those fields; identity still comes only from signed token |
| FBE-012 | Log capture around success and failures | No token, UID, email, raw claims, or secret material in logs/artifacts |
| FBE-013 | Server exchange succeeds, client process dies before local owner re-key | Client resumes local re-key without repeating destructive cleanup or losing original stores |
| FBE-014 | Local owner re-key callback fails after Laravel session creation | Pending work remains durable and retries on next launch/resume despite an active Laravel session |
| FBE-015 | Two Firebase identities or two projects attempt to resolve to one local owner | No implicit merge; explicit reviewed account-linking policy required |

Backend acceptance needs SQLite feature tests and PostgreSQL integration tests for unique mapping, concurrency, transaction boundaries, token issuance and owner isolation. Client acceptance additionally needs the PL/BM/OWN/SNAP cases from `PHASE-12A-DEVICE-MERGE-AND-SNAPSHOT-ACCEPTANCE.md`.

## 8. Decisions required before implementation

1. Confirm whether this legacy Firebase bridge must remain supported and which Firebase project/tenant(s) are in scope.
2. Choose the durable Firebase UID mapping model and migration/backfill policy without overloading `google_id`.
3. Decide what happens when no Firebase UID mapping exists but a verified email matches an existing Laravel account.
4. Decide the required disabled/revoked-user check and the permitted runtime identity-verification credentials.
5. Define whether an unknown verified Firebase user may create a new Laravel account or must use a manual authenticated-linking flow.
6. Approve the rate-limit budget and any device-limit UX change only if the existing session policy is insufficient.

Until those items are resolved:
- do not add the route, migration, verifier dependency, Firebase SDK, credentials, or project configuration;
- do not use email as a fallback identity key;
- do not merge/delete a guest or account;
- keep Firebase as an unresolved legacy candidate under D-050/D-051.

## 9. Phase12A exit boundary

This contract draft completes a Backend design/reconciliation task only. It does not implement Firebase exchange, authenticate a Firebase user, establish source-of-truth, repair device-local stores, or close Phase12A.

Phase12A remains IN_PROGRESS until the owner-rekey state machine and store coverage are implemented/tested in the client, the Backend exchange decisions above are approved and implemented where required, and the synthetic tests pass on both SQLite and PostgreSQL. Phase12B/12C and Phase13B remain gated by their existing dependencies and approvals.
