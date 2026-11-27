# فهرس ملفات RAVEN23-BACKEND — المزامنة الحالية مع main

**لقطة الشجرة:** 1e4e8d7f8d2617575868604d21452511a9f10c61
**تاريخ المزامنة:** 27 نوفمبر 2026
**المصدر:** Git Trees API recursive؛ `truncated=false`.

## ملخص الجرد

- الملفات المتتبعة (blobs): **252**.
- مجلدات الشجرة (trees): **55**.
- مجلد phases: **78 ملفًا**، منها 77 Markdown و44 تقريرًا و16 ملف مواصفة باسم PHASE-XX.
- GitHub Actions: **6 workflows**.
- Database migrations: **23**.
- PHP tests: **42** (22 Feature، 14 Integration، والبقية fixtures/TestCase).
- App PHP files: **49**؛ config PHP files: **13**.

هذا فهرس snapshot لمحتويات Git على commit المذكور؛ لا يشمل الملفات غير المتتبعة أو runtime state أو secrets أو بيانات أي قاعدة تشغيل.

## ملاحظة عن السجل التاريخي

الأقسام والأعداد القديمة أدناه في النسخ السابقة كانت تصف خط أساس 21–23 نوفمبر، ولا تصف الشجرة الحالية. تم تحديث هذا الفهرس ليكون snapshot كاملًا للمستودع الحالي، مع الإبقاء على تقارير المراحل القديمة كسجلات تاريخية وعدم حذفها.

## جميع الملفات المتتبعة حسب المسار

## .dockerignore/ (1)

- `.dockerignore`

## .env.example/ (1)

- `.env.example`

## .github/ (6)

- `.github/workflows/auth-smoke.yml`
- `.github/workflows/docker-build.yml`
- `.github/workflows/iac-validation.yml`
- `.github/workflows/pint-format-report.yml`
- `.github/workflows/security-scans.yml`
- `.github/workflows/users-auth-tests.yml`

## .gitignore/ (1)

- `.gitignore`

## .opentofu-version/ (1)

- `.opentofu-version`

## Dockerfile/ (1)

- `Dockerfile`

## README.md/ (1)

- `README.md`

## app/ (49)

- `app/Console/Commands/CleanupOrphanedPrivateMedia.php`
- `app/Console/Commands/DispatchPendingNotificationOutbox.php`
- `app/Console/Commands/ExpireDueEntitlements.php`
- `app/Console/Commands/NotificationOutboxStatus.php`
- `app/Contracts/Notifications/NotificationOutboxTransport.php`
- `app/Contracts/Notifications/PushNotificationTransport.php`
- `app/Contracts/Storage/PrivateObjectStorage.php`
- `app/Exceptions/SubscriptionEventConflict.php`
- `app/Exceptions/WalletTransactionConflict.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/BillingCatalogController.php`
- `app/Http/Controllers/Api/BookmarkSyncController.php`
- `app/Http/Controllers/Api/EntitlementController.php`
- `app/Http/Controllers/Api/HealthController.php`
- `app/Http/Controllers/Api/PlaylistSyncController.php`
- `app/Http/Controllers/Api/PreferenceSyncController.php`
- `app/Http/Controllers/Api/PushSubscriptionController.php`
- `app/Http/Controllers/Api/RecentlyWatchedSyncController.php`
- `app/Http/Controllers/Api/UserMediaController.php`
- `app/Http/Controllers/Api/WalletController.php`
- `app/Http/Controllers/Api/WellnessSyncController.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Middleware/RequestCorrelationId.php`
- `app/Jobs/DeletePrivateObjectJob.php`
- `app/Jobs/DeliverNotificationOutboxJob.php`
- `app/Models/SubscriptionEvent.php`
- `app/Models/User.php`
- `app/Models/UserBookmark.php`
- `app/Models/UserDevice.php`
- `app/Models/UserEntitlement.php`
- `app/Models/UserMedia.php`
- `app/Models/UserPreference.php`
- `app/Models/UserProfile.php`
- `app/Models/UserPushSubscription.php`
- `app/Models/UserRecentMedia.php`
- `app/Models/UserSyncRevision.php`
- `app/Models/UserWallet.php`
- `app/Models/WalletTransaction.php`
- `app/Notifications/EmailVerificationNotification.php`
- `app/Notifications/PasswordResetNotification.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Entitlements/SubscriptionEventLedger.php`
- `app/Services/Notifications/DisabledPushNotificationTransport.php`
- `app/Services/Notifications/LaravelNotificationOutboxTransport.php`
- `app/Services/Notifications/NotificationOutboxService.php`
- `app/Services/Storage/FilesystemPrivateObjectStorage.php`
- `app/Services/Wallet/WalletLedger.php`
- `app/Support/GoogleIdTokenVerifier.php`
- `app/Support/Raven23AuthSchema.php`

## artisan/ (1)

- `artisan`

## bootstrap/ (3)

- `bootstrap/app.php`
- `bootstrap/cache/.gitignore`
- `bootstrap/providers.php`

## composer.json/ (1)

- `composer.json`

## composer.lock/ (1)

- `composer.lock`

## config/ (13)

- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/cors.php`
- `config/database.php`
- `config/filesystems.php`
- `config/logging.php`
- `config/mail.php`
- `config/notifications.php`
- `config/queue.php`
- `config/security.php`
- `config/services.php`
- `config/session.php`

## contracts/ (2)

- `contracts/API-V1.md`
- `contracts/api-v1.json`

## database/ (23)

- `database/migrations/0001_01_01_000000_create_users_table.php`
- `database/migrations/0001_01_01_000001_create_cache_table.php`
- `database/migrations/0001_01_01_000002_create_jobs_table.php`
- `database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php`
- `database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php`
- `database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php`
- `database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php`
- `database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php`
- `database/migrations/2026_11_22_000006_create_email_verification_tokens_table.php`
- `database/migrations/2026_11_22_000007_create_user_data_foundation_tables.php`
- `database/migrations/2026_11_23_000008_create_user_bookmarks_table.php`
- `database/migrations/2026_11_23_000009_create_user_recent_media_tables.php`
- `database/migrations/2026_11_23_000010_create_user_playlist_sync_tables.php`
- `database/migrations/2026_11_23_000011_create_user_bookmark_sync_tables.php`
- `database/migrations/2026_11_23_000012_create_user_preference_sync_tables.php`
- `database/migrations/2026_11_23_000013_create_user_wellness_sync_tables.php`
- `database/migrations/2026_11_23_000014_create_user_media_table.php`
- `database/migrations/2026_11_24_000015_create_notification_outbox_table.php`
- `database/migrations/2026_11_24_000015_create_user_push_subscriptions_table.php`
- `database/migrations/2026_11_24_000016_create_user_entitlements_table.php`
- `database/migrations/2026_11_24_000017_create_subscription_events_table.php`
- `database/migrations/2026_11_24_000018_add_subscription_event_projection_columns.php`
- `database/migrations/2026_11_24_000019_create_user_wallets_and_transactions.php`

## docker/ (4)

- `docker/Caddyfile`
- `docker/entrypoint.sh`
- `docker/php-production.ini`
- `docker/queue-worker.sh`

## infra/ (8)

- `infra/opentofu/README.md`
- `infra/opentofu/contracts/PLATFORM-MODULE-CONTRACTS.md`
- `infra/opentofu/contracts/STATE-AND-RECOVERY-DESIGN.md`
- `infra/opentofu/main.tf`
- `infra/opentofu/outputs.tf`
- `infra/opentofu/scripts/guardrail-provider-neutral.sh`
- `infra/opentofu/tests/test-guardrail-provider-neutral.sh`
- `infra/opentofu/variables.tf`

## phases/ (78)

- `phases/API_CONTRACT_INVENTORY.md`
- `phases/BACKEND_FILE_CATALOG.md`
- `phases/BASELINE_AUDIT.md`
- `phases/BRANCH_POLICY.md`
- `phases/DATABASE_AND_DATA_INVENTORY.md`
- `phases/DECISIONS.md`
- `phases/ENVIRONMENT_AND_SECRET_NAMES.md`
- `phases/EXECUTION_PROTOCOL.md`
- `phases/FEATURE_MATRIX.md`
- `phases/MASTER_PLAN.md`
- `phases/PHASE-00-BASELINE-INVENTORY.md`
- `phases/PHASE-01-REPRODUCIBLE-LARAVEL-PROJECT.md`
- `phases/PHASE-02-PORTABLE-RUNTIME.md`
- `phases/PHASE-03-SECURITY-OPERATIONS-FOUNDATION.md`
- `phases/PHASE-04-AUTHENTICATION-COMPLETION.md`
- `phases/PHASE-05-API-CONTRACTS.md`
- `phases/PHASE-05-ERD-AND-DATA-DICTIONARY.md`
- `phases/PHASE-05-USER-DATA-FOUNDATION.md`
- `phases/PHASE-06-MEDIA-LIBRARY-AND-PLAYBACK.md`
- `phases/PHASE-07-CROSS-DEVICE-SYNC.md`
- `phases/PHASE-08-STORAGE-NOTIFICATIONS-JOBS.md`
- `phases/PHASE-09-SUBSCRIPTIONS-ENTITLEMENTS.md`
- `phases/PHASE-10-QUALITY-AND-RELEASE-ENGINEERING.md`
- `phases/PHASE-11-PORTABLE-INFRASTRUCTURE-AND-DEPLOYMENT.md`
- `phases/PHASE-12-DATA-MIGRATION-CUTOVER.md`
- `phases/PHASE-13-PRODUCTION-OPERATIONS-RESILIENCE.md`
- `phases/PUSH-PROVIDER-SELECTION-DEFERRED.md`
- `phases/README.md`
- `phases/SECURITY-OPERATIONS-POLICY.md`
- `phases/STATUS.md`
- `phases/TOOLS_AND_SERVICES.md`
- `phases/USER_RULES_AND_CONSTRAINTS.md`
- `phases/WORKFLOW_INVENTORY.md`
- `phases/release/rollback-target.json`
- `phases/reports/PHASE-00-REPORT-2026-11-21.md`
- `phases/reports/PHASE-01-COMPLETION-REPORT-2026-11-22.md`
- `phases/reports/PHASE-01-PROGRESS-REPORT-2026-11-21.md`
- `phases/reports/PHASE-02-COMPLETION-REPORT-2026-11-22.md`
- `phases/reports/PHASE-02-PROGRESS-REPORT-2026-11-22.md`
- `phases/reports/PHASE-03-COMPLETION-REPORT-2026-11-22.md`
- `phases/reports/PHASE-03-PROGRESS-REPORT-2026-11-22.md`
- `phases/reports/PHASE-04-CI-VERIFICATION-ADDENDUM-2026-11-22.md`
- `phases/reports/PHASE-04-PROGRESS-REPORT-2026-11-22.md`
- `phases/reports/PHASE-05-COMPLETION-REPORT-2026-11-22.md`
- `phases/reports/PHASE-06-COMPLETION-REPORT-2026-11-23.md`
- `phases/reports/PHASE-06-MAIN-TESTS-CONSOLIDATION-2026-11-23.md`
- `phases/reports/PHASE-06-PLAYLIST-SYNC-ADDENDUM-2026-11-23.md`
- `phases/reports/PHASE-06-PROGRESS-REPORT-2026-11-23.md`
- `phases/reports/PHASE-07-COMPLETION-REPORT-2026-11-24.md`
- `phases/reports/PHASE-07-PROGRESS-REPORT-2026-11-23.md`
- `phases/reports/PHASE-07-PROGRESS-REPORT-2026-11-24.md`
- `phases/reports/PHASE-08-PROGRESS-REPORT-2026-11-23.md`
- `phases/reports/PHASE-08-PROGRESS-REPORT-2026-11-24-ADDENDUM.md`
- `phases/reports/PHASE-08-PROGRESS-REPORT-2026-11-24.md`
- `phases/reports/PHASE-08K-OUTBOX-RETRY-DEAD-LETTER-AND-SECURITY-REMEDIATION-2026-11-26.md`
- `phases/reports/PHASE-09-ENTRY-AND-CONTRACT-AUDIT-2026-11-24.md`
- `phases/reports/PHASE-09-PROGRESS-REPORT-2026-11-24-09B.md`
- `phases/reports/PHASE-09-PROGRESS-REPORT-2026-11-24-09C.md`
- `phases/reports/PHASE-09-PROGRESS-REPORT-2026-11-24-09D.md`
- `phases/reports/PHASE-09-PROGRESS-REPORT-2026-11-24-09E.md`
- `phases/reports/PHASE-09-PROGRESS-REPORT-2026-11-24-09F.md`
- `phases/reports/PHASE-10-PROGRESS-REPORT-2026-11-24-10A.md`
- `phases/reports/PHASE-10-PROGRESS-REPORT-2026-11-24-10C-10D.md`
- `phases/reports/PHASE-10-PROGRESS-REPORT-2026-11-25-10D-10E.md`
- `phases/reports/PHASE-10-PROGRESS-REPORT-2026-11-25-10E-10F.md`
- `phases/reports/PHASE-10-PROGRESS-REPORT-2026-11-25-10F-10G.md`
- `phases/reports/PHASE-10G-RELEASE-MANIFEST-ROLLBACK-CLOSEOUT-2026-11-25.md`
- `phases/reports/PHASE-11A-PORTABILITY-SCORECARD-AND-11B1-TRUST-DESIGN-2026-11-25.md`
- `phases/reports/PHASE-11A-PORTABLE-OBJECT-STORAGE-CONFIG-2026-11-25.md`
- `phases/reports/PHASE-11A-PROVIDER-NEUTRAL-ARCHITECTURE-START-2026-11-25.md`
- `phases/reports/PHASE-11B1-TRUST-BOUNDARY-REMEDIATION-2026-11-26.md`
- `phases/reports/PHASE-11B1-TRUST-MODEL-DESIGN-CLOSEOUT-2026-11-25.md`
- `phases/reports/PHASE-11C-PROVIDER-NEUTRAL-VALIDATION-2026-11-26.md`
- `phases/reports/PHASE11C-APPLY-COMMAND-SUBSTITUTION-REPAIR.md`
- `phases/reports/PHASE11C-APPROVAL-GUARDRAIL-TESTS.md`
- `phases/reports/PHASE11C-PROVIDER-NEUTRAL-IAC-BASELINE.md`
- `phases/reports/PHASE_REPORT_TEMPLATE.md`
- `phases/reports/POST-PLAN-MANUAL-BRANCH-PROTECTION-SETUP.md`

## phpunit.xml/ (1)

- `phpunit.xml`

## public/ (1)

- `public/index.php`

## routes/ (3)

- `routes/api.php`
- `routes/console.php`
- `routes/web.php`

## scripts/ (3)

- `scripts/postgres-backup-restore-rehearsal.sh`
- `scripts/rollback-rehearsal.sh`
- `scripts/rollback-traffic-proxy.py`

## storage/ (6)

- `storage/app/private/.gitignore`
- `storage/app/public/.gitignore`
- `storage/framework/cache/data/.gitignore`
- `storage/framework/sessions/.gitignore`
- `storage/framework/views/.gitignore`
- `storage/logs/.gitignore`

## tests/ (43)

- `tests/Feature/ApiV1ContractTest.php`
- `tests/Feature/AuthLifecycleTest.php`
- `tests/Feature/BillingCatalogTest.php`
- `tests/Feature/BookmarkSyncTest.php`
- `tests/Feature/DatabaseMigrationRehearsalTest.php`
- `tests/Feature/EntitlementReadTest.php`
- `tests/Feature/ExpireDueEntitlementsTest.php`
- `tests/Feature/LaravelFoundationTest.php`
- `tests/Feature/PlaylistSyncTest.php`
- `tests/Feature/PreferenceSyncTest.php`
- `tests/Feature/PrivateObjectStorageTest.php`
- `tests/Feature/PushSubscriptionTest.php`
- `tests/Feature/QueuedNotificationsTest.php`
- `tests/Feature/RecentlyWatchedSyncTest.php`
- `tests/Feature/RuntimeHealthTest.php`
- `tests/Feature/SecurityResponseTest.php`
- `tests/Feature/SubscriptionEventLedgerTest.php`
- `tests/Feature/UserDataFoundationMigrationTest.php`
- `tests/Feature/UserDataFoundationTest.php`
- `tests/Feature/UserMediaTest.php`
- `tests/Feature/WalletReadTest.php`
- `tests/Feature/WellnessSyncTest.php`
- `tests/Fixtures/ConcurrentWellnessSyncWorker.php`
- `tests/Fixtures/FailingQueueJob.php`
- `tests/Fixtures/IdempotentNotificationOutboxTransportFake.php`
- `tests/Fixtures/PushNotificationTransportFake.php`
- `tests/Fixtures/TransientFailingNotificationOutboxTransportFake.php`
- `tests/Integration/PostgresAuthLifecycleTest.php`
- `tests/Integration/PostgresBookmarkSyncTest.php`
- `tests/Integration/PostgresEntitlementReadTest.php`
- `tests/Integration/PostgresExpireDueEntitlementsTest.php`
- `tests/Integration/PostgresNotificationOutboxTest.php`
- `tests/Integration/PostgresPlaylistSyncTest.php`
- `tests/Integration/PostgresPreferenceSyncTest.php`
- `tests/Integration/PostgresPushSubscriptionTest.php`
- `tests/Integration/PostgresRecentlyWatchedSyncTest.php`
- `tests/Integration/PostgresSubscriptionEventLedgerTest.php`
- `tests/Integration/PostgresUserMediaTest.php`
- `tests/Integration/PostgresWalletLedgerTest.php`
- `tests/Integration/PostgresWellnessSyncTest.php`
- `tests/Integration/S3PrivateObjectStorageTest.php`
- `tests/README.md`
- `tests/TestCase.php`