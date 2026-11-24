# Phase10A — CI workflow inventory and safety — 24 November 2026

## Status

10A COMPLETE after all four CI gates passed on the resulting main SHA. Phase10 remains IN_PROGRESS. This is not release readiness or deployment.

## Baseline

- Fully verified baseline before 10A: 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060.
- 10A result SHA: 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63.
- Docker [45455970338](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970338): SUCCESS — SQLite 144 / 1,508; PostgreSQL 31 / 541 with one optional skip; S3Mock 1 / 8.
- Authentication [45455970334](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970334): SUCCESS — SQLite 20 / 172; PostgreSQL 4 / 99.
- Pint [45455970270](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970270): SUCCESS; strict Pint within Docker also passed.
- Security [45455970393](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970393): SUCCESS.
- Branch listing: main only. GitHub branch API: protected=false. Rulesets endpoint: empty.

## Findings

- Actual tree contained six workflow files although the inventory said five.
- prepare-s3-composer-lock.yml had contents:write/actions:write and could modify composer.json/lock, push to main, and dispatch four workflows.
- league/flysystem-aws-s3-v3 is already in composer.json/lock at 3.35.3; Docker Composer validate/audit and builds already verify it.
- Manual HTTP smoke is GET-only; Docker is the full Feature/Integration/runtime gate; Auth regression is a focused overlapping gate; strict Pint also runs inside Docker.
- Trivy filesystem findings are report-only while Gitleaks and fixable HIGH/CRITICAL image gate are blocking.

## Change

- Removed obsolete prepare-s3-composer-lock.yml.
- Updated workflow inventory, Phase09/10 docs, decisions, status, master plan and reports.
- No runtime/API/schema changes, production DB, secrets, deployment or AWS resources.

## Open

- Branch protection/required checks are not enabled; available GitHub operations here do not expose settings writes. Track this for 10B.
- Consolidation of overlapping workflow jobs is deferred until exact required contexts are confirmed.
- Later 10C–10G: migration upgrade tests, API contracts, synthetic load, immutable artifacts and rollback.

## Acceptance

- [x] S3 dependency lock verified.
- [x] Obsolete write-enabled bootstrap identified for removal.
- [x] Workflow inventory corrected.
- [x] Unprotected main accurately recorded.
- [x] Run Docker, Authentication, Pint and Security on resulting SHA.

## 10B review — repository governance

- main remains the only branch and is explicitly unprotected (protected=false); the rulesets list is empty.
- Available GitHub tools do not expose a write operation for branch protection, repository rulesets or required status checks. These settings remain open for an administrator; no change is claimed.
- Proposed blocking contexts after the owner chooses an enforcement model: Docker runtime validation, focused Authentication regression, and Security scans. The separate Pint workflow only prepares an artifact; strict Pint within Docker is the actual blocking formatter check.
- The existing one-branch/direct-push policy conflicts with enforcing checks before updates through the normal PR gate. This policy choice needs owner/admin action before claiming required-check enforcement.
- Docker and Security separately build the same production image; Auth repeats a focused subset of Docker tests. Both are documented for cost/latency review, not changed before check contexts are approved.

## 10C start — migration rehearsal

- Added tests/Feature/DatabaseMigrationRehearsalTest.php with isolated temporary SQLite databases.
- Covers all migrations from an empty schema and a synthetic older snapshot that preserves a user, existing Sanctum token, cache entry and queue job while verifying profile/preferences/device backfill.
- CI evidence is pending on the implementation commit. The snapshot is synthetic only and does not use production data.


### 10C pre-CI fixture correction — 24 November 2026

- During source review of the synthetic legacy snapshot test, corrected an assertion whose expected email did not match the fixture value. The fixture remains `legacy_migration_user@example.test`; the assertion now checks that exact persisted value.
- This was caught by review before the Docker Feature suite completed; it is not recorded as a completed CI failure.
- The 10C test acceptance remains pending CI on the corrected commit.
