# Phase 04 — Authentication, account lifecycle and devices

**Status:** CI gates passed; phase implementation verified in isolated CI. PR remains open/draft pending repository workflow and review.
**Verified head:** `459fee8019228828013600fd7a6b13a4a3aa4f4f`
**Verified:** 22 November 2026
**Parent:** Phase 03 branch `feature/phase-03-security-observability` at `6b14d55fb87bdd6948b0f896db9a4c99c43f7748`

## Scope delivered

- Email registration and email-change verification flow with short-lived, one-time tokens stored as SHA-256 hashes.
- Password reset via Laravel PasswordBroker, expiry/throttling, generic non-enumerating responses, reset notification, and revocation of existing sessions after successful reset.
- Account-scoped device/session listing and exact-session or all-session revocation.
- Per-IP and per-account throttles for verification and device management.
- Mail delivery remains opt-in and disabled until provider and action URL are explicitly configured.
- Google ID-token verification remains dependent on correct production Android OAuth client configuration.

## Verified CI gates on the exact same head

| Gate | Workflow run | Result |
|---|---:|---|
| Docker Validation | #320 | SUCCESS |
| Pint Format | #52 | SUCCESS |
| Security Scans | #54 | SUCCESS |

### Docker Validation #320 detail

- Production FrankenPHP image build: passed.
- PHP syntax and Composer validation/audit: passed.
- SQLite Feature suite: **40 passed, 269 assertions**.
- PostgreSQL 16 integration suite: **4 passed, 99 assertions**.
- Restricted PostgreSQL application/migration role checks: passed.
- Application liveness/readiness, non-root runtime, and PHP asset blocking checks: passed.
- Docker job: all steps completed successfully.

### Security and formatting

- Pint #52: job and all steps successful.
- Security #54: Gitleaks, filesystem scan, production-image scan, vulnerability gate, and report publication successful.

## Failure history and resolution

Earlier failures referenced in progress notes (#308 Docker and #42 Pint, and #44 Security) are not the current result set. The latest completed runs are #320, #52, and #54, all attached to the exact verified head above. The Docker log confirms the successful SQLite and PostgreSQL test totals.

The earlier test corrections include PasswordBroker validation payload, refreshing Laravel auth guards before token-revocation assertions, PHP syntax corrections, and Pint formatting. Do not replay these fixes unless a later regression reproduces them.

## External checks not claimed as complete

CI does not prove production provider configuration. The following remain pre-production tasks:
- Configure and test real mail delivery and the externally hosted verification/reset action URL.
- Verify real Google sign-in with the production Android OAuth client on a real device.
- Verify provider-side database roles, TLS and trusted proxy settings.
- Approve and test centralized log collection/retention and backup/restore policy.
- Conduct production/staging deployment checks only after explicit configuration and authorization.

No production database, secrets, provider settings, or deployments were accessed or changed during this verification.

## Phase 04 exit assessment

**Code and CI exit criteria: PASS.** All three mandatory CI workflows passed on one SHA, with SQLite Feature and PostgreSQL integration suites green.

**Repository governance / phase-chain exit: NOT YET CLOSED.** PR #5 remains open and draft. Its parent PR #4 (Phase 03) also remains open and draft; Phase 03 production-operational items are still explicitly outstanding in PR #4. Do not merge Phase 04 into a base that has not passed the project's phase governance.

## Continuity checkpoint

- Current Phase 04 branch: `feature/phase-04-authentication-completion`
- Current verified head: `459fee8019228828013600fd7a6b13a4a3aa4f4f`
- PR: #5
- Latest CI: Docker #320, Pint #52, Security #54 — all SUCCESS.
- No Phase 04 CI rerun is needed unless the branch head changes.

## Next phase handoff

Phase 05 must start from the agreed phase-chain base after Phase 03/04 governance is resolved. This repository snapshot does not contain an approved Phase 05 specification. Before implementation, add a reviewed Phase 05 scope document describing requirements, exclusions, acceptance criteria, dependencies, tests, migrations, security implications, and CI gates. Do not infer a production feature scope from the Phase 04 implementation.
