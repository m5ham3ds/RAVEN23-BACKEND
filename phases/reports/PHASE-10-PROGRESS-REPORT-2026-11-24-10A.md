# Phase10A — CI workflow inventory and safety — 24 November 2026

## Status

10A implementation committed; CI on the resulting main SHA pending. Phase10 is IN_PROGRESS. This is not release readiness or deployment.

## Baseline

- Fully verified SHA before 10A: 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060.
- Docker, Authentication, Pint and Security all SUCCESS on that SHA.
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
- [ ] Run Docker, Authentication, Pint and Security on resulting SHA.
