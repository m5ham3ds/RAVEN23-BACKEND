# Backend test suites

All regression, feature and integration tests are maintained on the `main` branch beside the code they protect. Do not create separate test-only branches.

## Suites

- `tests/Feature`: Laravel HTTP/API behavior, validation, authorization, ownership, migrations, account/library/sync workflows, and `QueuedNotificationsTest` for encrypted after-commit mail jobs and retry policy. The Docker build runs this suite against an isolated in-memory SQLite database.
- `tests/Integration`: PostgreSQL-specific behavior and restricted-application-role checks, plus `S3PrivateObjectStorageTest` for a disposable S3-compatible API endpoint. GitHub Actions runs PostgreSQL against an ephemeral PostgreSQL 16 service and the S3 adapter test against Adobe S3Mock; neither test uses production services.

## CI gates

The required main-branch checks are:

1. **Backend Docker Validation** — production image build, SQLite Feature suite, PostgreSQL Integration suite, queue-worker fail-fast configuration checks, runtime/least-privilege/HTTP smoke checks.
2. **Pint Format Report** — changed PHP test and application files.
3. **Security Scans** — Gitleaks and Trivy filesystem/image scans.
4. **Authentication Regression Tests** — focused SQLite and PostgreSQL authentication lifecycle coverage.

The Docker gate also starts pinned Adobe S3Mock `5.2.3` and verifies private put/get/visibility/list/delete/idempotent-delete through Laravel's actual Flysystem S3 adapter. S3Mock is an API emulator, not a substitute for provider staging.

## Local commands

Install the locked dependencies first, then run the suites only against disposable local databases:

```bash
composer install --no-interaction --prefer-dist
php artisan test tests/Feature
php artisan test tests/Integration
```

For PostgreSQL integration, provide a temporary PostgreSQL database and the test-only connection settings from the CI workflow. Never reuse Neon, Render, RDS or any production database URL.

## Safety

Tests must use synthetic accounts and isolated databases. Do not add test credentials, real user identifiers, secrets, or production database URLs to this directory.


## Queued notification coverage

- `tests/Feature/QueuedNotificationsTest.php` checks that verification and password-reset notifications are dispatched to the encrypted `mail` queue after commit, with fixed retry deadline and bounded backoff/attempt settings.
- Docker validation also checks the worker script syntax and asserts it rejects `QUEUE_CONNECTION=sync` and a database worker timeout greater than or equal to `DB_QUEUE_RETRY_AFTER`.
- These tests do not send SMTP traffic and do not establish that a real provider or Android action URL is configured.
