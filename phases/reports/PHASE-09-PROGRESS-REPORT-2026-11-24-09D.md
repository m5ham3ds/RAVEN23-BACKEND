# تقرير Phase09 — إغلاق 09C وبدء 09D — 24 نوفمبر 2026

## إغلاق 09C

**Backend SHA:** `c3cb81c61c2711b6ad73ada262a5f230b89f9841`

- Docker [45432047055](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432047055) SUCCESS.
- Authentication [45432047070](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432047070) SUCCESS.
- Pint [45432046860](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432046860) SUCCESS.
- Security [45432046759](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432046759) SUCCESS.
- SQLite Feature: 136 tests / 1,441 assertions.
- PostgreSQL Integration: 28 passed / 518 assertions; one optional S3 skip. S3Mock: 1 passed / 8 assertions.

## 09D implementation

- Added preview-first `subscriptions:expire-due`; writes only with `--apply`.
- Processes at most 500 by default and rejects limits outside 1–5000.
- Records expiry through the append-only event ledger using the entitlement expiry timestamp and an idempotency key tied to entitlement ID/revision.
- Stale-event ordering preserves later renewal/correction state. No scheduled automatic run is enabled.

CI for 09D starts on the commit that adds the command and tests.
