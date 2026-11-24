# Phase10D closeout and Phase10E start — 25 November 2026

## 10D — API v1 contract regression COMPLETE

**Verified SHA:** `44ed546c9881bc2d7ca16d6c97024c6ae77ba047`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45503594954](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594954) | SUCCESS — SQLite Feature 150 passed / 1,704 assertions; PostgreSQL Integration 31 passed / 541 assertions with one optional skipped test; S3Mock 1 passed / 8 assertions; runtime guards PASS |
| Authentication Regression | [45503594980](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594980) | SUCCESS |
| Pint Format | [45503594941](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594941) | SUCCESS |
| Security Scans | [45503594952](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594952) | SUCCESS — Gitleaks/source scan PASS; Trivy image report Clean; fixable HIGH/CRITICAL gate PASS |

- `contracts/api-v1.json` defines 39 route/method/auth records; `contracts/API-V1.md` documents consumer-visible behavior.
- All three `ApiV1ContractTest` cases passed: route/method/auth boundary, health/billing response shapes, registration/profile/preferences/entitlements/wallet, protected 401s and validation 422 envelopes.
- Docker totals: SQLite Feature 150/1,704; PostgreSQL Integration 31/541 with one optional skip; S3Mock 1/8.
- No controllers, routes, migrations, schema or runtime behavior changed in 10D; this slice added contract manifest/docs/tests.
- Failure history retained: first Docker/Security image builds failed because the test incorrectly required a lowercase 64-character hex Sanctum token. Actual token is opaque mixed-case alphanumeric. The assertion was corrected in commit 44ed546 and CI passed afterward.
- All four gate runs target the same SHA. No production DB, secret, deployment or AWS resource was used.

## 10E — synthetic PostgreSQL load baseline IN_PROGRESS

The existing test `test_postgresql_parallel_device_uploads_converge_to_the_newest_session_version` launches eight independent PHP workers, each with its own PostgreSQL connection and Sanctum device token, synchronized by a shared barrier.

### Baseline observed on the 10D Docker run

- Concurrent clients / uploaded requests: 8 / 8
- Aggregate request bytes: 4,191
- Aggregate response bytes: 545
- Average request latency: 115.311 ms
- Maximum request latency: 149.676 ms
- Stale conflict writes: 6 / 8 (75%)
- Winning timestamp: 5000

This is a small synthetic CI concurrency scenario, not production capacity, production traffic distribution, or an SLO.

### 10E implementation started

- Add wall-clock duration from shared barrier release until all workers return.
- Add throughput in successful requests per wall-clock second.
- Add P50/P95 latency using deterministic nearest-rank calculation, retaining average and maximum latency.
- Continue reporting request/response volume, stale-conflict count/rate and winning timestamp.
- Assert all eight worker responses succeeded and the stored aggregate converges to the highest timestamp.
- Run only against disposable PostgreSQL in GitHub Actions; no production API, production database or provider credentials.

**Acceptance:** all four CI gates must pass on the SHA containing the instrumentation; read the new metrics from the completed Docker log and record them. No fixed latency SLO is introduced until expected workload and target capacity are explicitly approved.

## Remaining 10B governance dependency

- GitHub branch API reports main unprotected and rulesets empty.
- Required-check enforcement needs repository Admin settings write not exposed by the current connection.
- Do not claim main is protected or that push-time CI blocks a commit before it reaches main.
