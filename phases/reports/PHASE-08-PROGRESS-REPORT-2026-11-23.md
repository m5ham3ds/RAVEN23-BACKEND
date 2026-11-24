# Phase08 Progress Report — 23 November 2026

**Status:** IN_PROGRESS — Phase08A–08D implemented and CI-verified on 24 November 2026; S3-compatible integration, push, external mail, outbox/idempotency and retention policy remain open.
**Repository:** `m5ham3ds/RAVEN23-BACKEND`
**CI-verified SHA:** `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`
**Permanent branch:** `main` only.

## 1. Scope decision

Phase08 has dependencies 02–05. Runtime/security/data foundations are in place, while Phase04 remains PARTIAL for real Google Android and external email verification. The first Phase08 slice is provider-independent queue code and is safe to implement with mail delivery disabled. External mail/push credentials, domain/DNS, AWS account and paid resources remain WAITING_EXTERNAL.

## 2. Implemented — Phase08A

- `EmailVerificationNotification` and `PasswordResetNotification` now implement `ShouldQueue` and `ShouldBeEncrypted`.
- Jobs use the `mail` queue and `afterCommit`.
- Retry contract is bounded: 5 tries, maxExceptions 3, notification timeout 30 seconds, failOnTimeout, backoff [10, 30, 90, 180], fixed retry deadline 20 minutes from notification creation.
- Reuses existing Laravel database queue tables `jobs`, `job_batches` and `failed_jobs`; no migration was added.
- Added `docker/queue-worker.sh`, installed as `/usr/local/bin/raven23-queue-worker` in the runtime image.
- Worker defaults: database connection, queues mail/default, sleep 3 seconds, tries 5, timeout 45 seconds, max-time 3600 seconds, memory 128 MB. It rejects sync/null drivers and rejects a database timeout greater than or equal to retry_after.
- `QueuedNotificationsTest` asserts encrypted queue jobs, after-commit behavior, retry deadline stability and retry/backoff policy.
- Docker CI validates worker shell syntax and verifies the two fail-fast guard paths.

## 3. Files changed

- `app/Notifications/EmailVerificationNotification.php`
- `app/Notifications/PasswordResetNotification.php`
- `tests/Feature/QueuedNotificationsTest.php`
- `docker/queue-worker.sh`
- `Dockerfile`
- `.env.example`
- `README.md`
- `.github/workflows/docker-build.yml`

## 4. CI evidence on the same SHA

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525) | SUCCESS — SQLite 92 tests/1,046 assertions; PostgreSQL 12 tests/265 assertions |
| Authentication Regression | [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572) | SUCCESS — SQLite 20/151; PostgreSQL 4/99 |
| Pint Format | [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578) | SUCCESS |
| Security Scans | [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417) | SUCCESS — Gitleaks/Trivy; production image report Clean |

Docker additionally passed strict Pint, queue worker guard tests, production APP_DEBUG guard, startup/liveness without DB, restricted PostgreSQL application role, separate migration role, readiness, non-root runtime and uploaded-PHP blocking.

## 5. Failure and repair history

- Initial queue implementation on SHA `81127e3` passed Authentication but Docker failed at Pint.
- Pint identified `class_attributes_separation` and `ordered_interfaces` in the two notification classes.
- Formatting was corrected in `f202166` and `14ecb5c`; all four gates then passed on `14ecb5c`.
- The follow-up on `dd861fa` made retry deadlines fixed at construction time and added runtime tests for worker misconfiguration; all four gates passed again.

## 6. What has not been proven / remaining

- No S3-compatible adapter/integration run has been performed against MinIO or staging; the tested adapter currently uses Laravel's local fake/private filesystem. No AWS SDK adapter, bucket, secrets or paid resource was added.
- `RAVEN23_AUTH_MAIL_ENABLED=false` and `MAIL_MAILER=log` remain unchanged. Mail templates and queue behavior are tested locally, but external SMTP/provider delivery and Android deep links are not.
- No FCM/OneSignal push adapter exists; the Flutter contract must be confirmed before implementing one.
- At-least-once mail delivery can duplicate after provider acceptance followed by worker crash; provider idempotency/outbox semantics remain open.
- The per-account media quota is opt-in and remains unlimited at default 0. Orphan cleanup runs dry-run by default and scheduled apply is disabled by default. No physical objects were deleted in CI.
- Retention duration, capacity policy, cost limits and alert thresholds have not been approved or configured.
- No production/staging secrets, cloud resources, production DB, DNS or deployment were accessed.

## 7. Next permitted Phase08 work

1. Add mail rendering tests with a local mail sink and verify action URLs without sending external mail.
2. Define storage adapter contract and an S3-compatible fake/test implementation before any provider credentials.
3. Inspect the Flutter push-messaging contract and only then implement a provider interface/test adapter.
4. Add a worker execution test with a fake mail transport and failed_jobs/retry visibility.

**Phase08 remains IN_PROGRESS.** This report does not claim object storage, push or real email delivery is production-ready.


## 8. Phase08B — Private object storage contract

- Introduced the provider-neutral PrivateObjectStorage contract (put/get/exists/delete).
- Added FilesystemPrivateObjectStorage over Laravel's Filesystem contract; storage keys reject traversal and non-portable characters, writes force private visibility, and failed I/O raises explicit exceptions.
- Bound the contract through AppServiceProvider to a configurable RAVEN23_PRIVATE_STORAGE_DISK; default is a separate non-public private_media local disk, leaving the app's existing default disk and Flutter photo_url response contract unchanged.
- Added feature tests for the binding, object lifecycle, missing reads and invalid-key rejection using Laravel's filesystem fake.
- composer.lock currently contains Flysystem core/local only; no AWS S3 Flysystem adapter is installed. S3 is not activated and no credentials, bucket, cloud resources or paid services were added. A real S3-compatible integration remains a later staging/provider task.
- Upload endpoints, PostgreSQL object metadata/ownership, signed URLs, MIME/content validation and orphan cleanup remain unimplemented.



## 9. Phase08C — User media API and optional quota (24 November 2026)

- Added `UserMedia` metadata and owner FK migration `2026_11_23_000014`, with MIME/content validation, size/dimension bounds, server-generated UUID object keys and SHA-256 checksum.
- Added Sanctum-authenticated upload, owner-scoped read/delete, account export metadata and short-lived signed capability URLs. The private filesystem disk remains separate from the public disk; the old profile `photo_url` contract remains unchanged.
- Avatar files: JPEG/PNG/WebP, max 5 MiB, max dimensions 4096×4096. Attachments: PDF/TXT, max 10 MiB.
- Optional quota uses `RAVEN23_USER_MEDIA_QUOTA_BYTES`; 0 is unlimited. The owner row is locked inside the upload transaction before current usage is measured and the object is written.
- Account deletion and media deletion queue encrypted after-commit cleanup jobs; no production storage was accessed.

## 10. Phase08D — Safe orphan media cleanup

- Added streaming object metadata listing to the storage contract and Flysystem adapter.
- Added `media:cleanup-orphans` command with dry-run as the default, UUID media key allowlist, metadata comparison in 250-key batches, 7-day default grace (minimum one day), and configurable bounded queue limit.
- `--apply` requires explicit `RAVEN23_PRIVATE_MEDIA_ORPHAN_CLEANUP_ENABLED=true`; scheduler registration is also conditional and uses daily 03:30 plus `withoutOverlapping`.
- Tests confirm referenced media is not queued and only stale unreferenced media is sent to `DeletePrivateObjectJob` under SQLite and PostgreSQL.
- CI on SHA `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`: Docker 109/1,196 SQLite and 15/295 PostgreSQL; Auth, Pint and Security all SUCCESS.


## 11. تحديث التحقق — 24 نوفمبر 2026، SHA d0aa6d4

**رأس main:** `d0aa6d4a0e467fe6d72619858b13ef30a466722e`

### CI على commit واحد

| Gate | Run | النتيجة |
|---|---:|---|
| Docker Validation | [45289473199](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289473199) | SUCCESS — SQLite/PostgreSQL suites وruntime وstrict Pint وqueue worker guards PASS |
| Authentication Regression | [45289472959](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289472959) | SUCCESS |
| Pint Format | [45289473303](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289473303) | SUCCESS |
| Security Scans | [45289472973](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289472973) | SUCCESS — Gitleaks/Trivy/production-image gates PASS |

### اختبار worker الجديد

أضيف اختبار `test_database_worker_executes_private_media_cleanup_and_job_is_idempotent` الذي ينفذ database queue worker فعلياً مرة واحدة بعد حذف UserMedia metadata، ويتحقق من إزالة object، وعدم بقاء jobs أو failed_jobs، ثم يستدعي DeletePrivateObjectJob مجدداً لنفس المفتاح ويثبت أنه no-op آمن.

### تاريخ الفشل والإصلاح

- commit `dfdc82f`: فشلت بوابات Docker وPint وSecurity؛ فحص Authentication لم يكن ضمن النتائج الظاهرة لهذا الالتزام.
- commit `f4a6440`: تصحيح صياغة الاختبار؛ البوابات الأربع SUCCESS.
- commit `958a8d5`: تحديث توثيق النتائج؛ البوابات الأربع SUCCESS.
- commit `d0aa6d4`: اختبار worker التنفيذي؛ البوابات الأربع SUCCESS.

### الحدود المتبقية

- الاختبارات لا تزال تستخدم filesystem fake، ولا تمثل اتصالاً حقيقياً بـS3-compatible service أو حذف ملفات مستخدمين فعلية.
- لا SMTP/provider أو FCM/OneSignal أو Flutter push contract مثبت.
- outbox/idempotency الخارجي وسياسة الاحتفاظ/السعة/التكلفة لم تُعتمد.
- Phase08 تبقى IN_PROGRESS؛ Phase07 كذلك IN_PROGRESS. لا staging secrets أو production DB أو deploy أو موارد سحابية تم استخدامها.


## 12. تحديث Phase08E — Transactional Outbox + S3Mock، 24 نوفمبر 2026

**Verified code SHA:** `8f34a0b0b725eb6704149154e2558eed81bc497f`

### التنفيذ

- Outbox transactional مشفر لرسائل email verification وpassword reset؛ HMAC idempotency key بطول 64 hex مع unique database constraint، encrypted payload، delivery status، attempt counts، timestamps، وsanitized last error type.
- Email verification token row والـoutbox event داخل transaction واحدة، مع queue dispatch بعد commit. Password reset callback يحفظ event عبر نفس Outbox service.
- Worker مشفر مع claim lease 60 ثانية وbounded retry/backoff. Relay مجدول كل دقيقة يستعيد pending events بعد فقد dispatch ويستعيد processing claims المنتهية دون لمس claim نشطة.
- اختبارات SQLite تغطي التكرار/التشفير/array mail sink/worker execution/accept-then-crash replay/relay recovery.
- `PostgresNotificationOutboxTest` يغطي 3 حالات حقيقية: idempotent uniqueness، rollback suppresses after-commit dispatch، وexpired-claim recovery مع حماية claim نشطة.
- اختبار fake provider يعيد نفس مفتاح idempotency بعد الانهيار ويثبت dedupe داخل fake فقط. هذا لا يثبت exactly-once لدى SMTP؛ provider-native idempotency غير موجود حتى اختيار المزود.

### S3-compatible adapter

- تم تثبيت Flysystem S3 adapter الرسمي في Composer lock.
- Docker شغّل S3Mock مؤقتاً، ونجح اختبار put/get/private visibility/list/delete/repeated delete عبر Laravel S3 adapter. لا يوجد AWS bucket أو مفاتيح أو موارد مدفوعة.

### CI على SHA واحد

| Gate | Run | Result |
|---|---:|---|
| Docker | [45296772477](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772477) | SUCCESS — SQLite 113/1,232؛ PostgreSQL 18/311؛ S3Mock 1/8 |
| Authentication | [45296772381](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772381) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99 |
| Pint | [45296772496](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772496) | SUCCESS |
| Security | [45296772309](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772309) | SUCCESS |

### المتبقي

- مزود mail فعلي مع native idempotency وverification/reset staging/deep-link؛ outbound mail remains disabled.
- Flutter push contract وprovider adapter بعد اعتماده.
- S3-compatible provider/staging test بخدمات واعتمادات اختبار معتمدة.
- retention/capacity/cost policy ومدة الاحتفاظ بسجلات Outbox.

**Phase08 تبقى IN_PROGRESS.** Phase07 تبقى IN_PROGRESS، Phase09 WAITING_EXTERNAL، ولم يُلمس الإنتاج أو secrets أو deploy.
