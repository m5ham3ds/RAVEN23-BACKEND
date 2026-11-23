# فهرس ملفات RAVEN23-BACKEND — خط الأساس والتحديث الحالي

**Commit:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**التاريخ:** 21 نوفمبر 2026  
**الدليل:** Git Trees API recursive، truncated=false.  
**النتيجة:** 34 ملفًا tracked في 17 مجلدًا، منها 10 GitHub Actions workflows.

هذا فهرس لكل الملفات المسجلة في Git على commit خط الأساس. الملفات غير المتتبعة أو إعدادات runtime المولدة خارج Git ليست ضمن هذا العدد.

## 1. الجذر

- .dockerignore
- Dockerfile
- README.md

## 2. GitHub Actions — 10 workflows

- .github/workflows/auth-smoke.yml
- .github/workflows/docker-build.yml
- .github/workflows/production-account-retry.yml
- .github/workflows/production-account-retry-2.yml
- .github/workflows/production-account-retry-3.yml
- .github/workflows/production-account-retry-4.yml
- .github/workflows/production-account-retry-7.yml
- .github/workflows/production-account-smoke.yml
- .github/workflows/production-registration-route-probe.yml
- .github/workflows/runtime-db-diagnostics.yml

## 3. التطبيق والنماذج والدعم

- app/Http/Controllers/Api/AuthController.php
- app/Models/User.php
- app/Support/GoogleIdTokenVerifier.php
- app/Support/Raven23AuthSchema.php

## 4. Laravel bootstrap/config

- bootstrap/app.php
- config/cache.php
- config/database.php
- config/services.php

## 5. Database migrations — 5

- database/migrations/2026_11_18_000001_add_raven23_auth_fields_to_users.php
- database/migrations/2026_11_18_000002_ensure_personal_access_tokens_table.php
- database/migrations/2026_11_18_000003_reconcile_raven23_auth_schema.php
- database/migrations/2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php
- database/migrations/2026_11_21_000005_reconcile_guest_account_flag.php

## 6. Docker/runtime helpers — 5

- docker/check-database-target.php
- docker/router.php
- docker/runtime-db-diagnostics.php
- docker/start.sh
- docker/verify-auth-schema.php

## 7. API routes and tests

- routes/api.php
- tests/Feature/AuthLifecycleTest.php
- tests/Integration/PostgresAuthLifecycleTest.php

## 8. ملفات Laravel الأساسية التي كانت غير موجودة في baseline

لم تظهر ضمن tree:
- composer.json
- composer.lock
- artisan
- .env.example خاص بالـBackend
- Laravel full application skeleton committed in source.
- Migrations كاملة للـLaravel base tables، أو domains الخاصة بمزامنة بيانات RAVEN23.

هذه ليست ملفات مفقودة في عملية الجرد؛ هي بالفعل غير tracked في commit المذكور. Dockerfile ينشئ Laravel skeleton أثناء البناء، وهو ما يجعل مصدر التطبيق غير مستقل بالكامل.

## 9. ملفات Flutter التي تمت مراجعتها لعقد Laravel

مستودع RAVEN23 العام عند SHA 6d12023e446db3d444716c3b407572a89cf545be يحتوي 2,085 tracked files بحسب Git tree API. من ملفات client contract ذات الصلة:
- lib/core/config/app_environment.dart
- lib/core/config/app_runtime.dart
- lib/core/config/migration_flags.dart
- lib/core/di/injector.dart
- lib/core/network/interceptors/auth_interceptor.dart
- lib/data/sources/laravel_api.dart
- lib/data/sources/legacy_firebase_session.dart
- lib/data/models/auth_requests.dart
- lib/data/models/auth_session.dart
- lib/data/repositories/ads_repository.dart
- lib/data/repositories/announcement_repository.dart
- lib/data/repositories/auth_repository.dart
- lib/data/repositories/billing_repository.dart
- lib/data/repositories/bookmark_repository.dart
- lib/data/repositories/config_repository.dart
- lib/data/repositories/device_repository.dart
- lib/data/repositories/flix_ai_repository.dart
- lib/data/repositories/playlist_repository.dart
- lib/data/repositories/push_message_repository.dart
- lib/data/repositories/recently_watched_repository.dart
- lib/data/repositories/wellness_repository.dart
- lib/data/sync/laravel_sync_coordinator.dart
- lib/data/sync/sync_transport.dart
- lib/data/sync/bookmark_sync_engine.dart
- lib/data/sync/recent_sync_engine.dart
- lib/data/sync/playlist_sync_engine.dart
- lib/data/sync/wellness_sync_engine.dart
- lib/presentation/session/firebase_session_bridge.dart
- lib/presentation/notifications/device_registration_controller.dart
- lib/presentation/notifications/in_app_message_controller.dart
- lib/presentation/notifications/push_message_controller.dart
- lib/presentation/billing/plus_controller.dart
- integration/laravel_foundation_smoke_test.dart
- test/integration/laravel_migration_e2e_test.dart

هذا فهرس الملفات المؤثرة في عقد Laravel؛ لا يعني أن كل ملفات Flutter الـ2,085 قد فُحص محتواها سطرًا بسطر. مسارات scraper/TMDB مستقلة عن account backend ويجب أن تبقى خارج نطاق عقد Laravel.


## 10. جرد التنفيذ الحالي — 23 نوفمبر 2026

**SHA الذي تمت مراجعته:** `fea7045178e603a7b54150e75475f5d27960d306`. يحتفظ هذا الملف بقائمة baseline القديمة لأنها تسجل تاريخ Phase00؛ الأعداد التالية هي المرجع الحالي بدلًا منها.

- Git tree recursive: `truncated=false`, 133 tracked files، 12 workflows، 13 migrations، 44 ملفًا تحت phases، و8 ملفات تحت tests.
- أصبح `composer.json`, `composer.lock`, `artisan`, و`.env.example` ضمن الشجرة؛ لم تعد فجوة المصدر القديمة قائمة.
- ملفات Phase06 على main:
  - `app/Http/Controllers/Api/BookmarkSyncController.php`
  - `app/Http/Controllers/Api/RecentlyWatchedSyncController.php`
  - `app/Http/Controllers/Api/PlaylistSyncController.php`
  - `app/Models/UserBookmark.php`
  - `app/Models/UserRecentMedia.php`
  - `app/Models/UserSyncRevision.php`
  - `database/migrations/2026_11_23_000008_create_user_bookmarks_table.php`
  - `database/migrations/2026_11_23_000009_create_user_recent_media_tables.php`
  - `database/migrations/2026_11_23_000010_create_user_playlist_sync_tables.php`
- `routes/api.php` يضم endpoints المفضلة وسجل المشاهدة والقوائم.
- `AuthController.php` يصدّر account data بصيغة `format_version=3` متضمنة bookmarks وrecently_watched وplaylists.
- اختبارات Phase06 في فرع TEST: `BookmarkSyncTest.php`, `RecentlyWatchedSyncTest.php`, `PlaylistSyncTest.php`, `PostgresRecentlyWatchedSyncTest.php`, `PostgresPlaylistSyncTest.php`.
- Playlist sync core منفذ؛ imports وpublic sharing غير منفذين وخارج نطاق Phase06.

### القائمة الحالية للـworkflows
`.github/workflows/`: auth-smoke.yml, docker-build.yml, pint-format-report.yml, security-scans.yml, users-auth-tests.yml, production-account-retry.yml, production-account-retry-2.yml, production-account-retry-3.yml, production-account-retry-4.yml, production-account-retry-7.yml, production-account-smoke.yml, production-registration-route-probe.yml.

### migrations الحالية (12)
- `0001_01_01_000000_create_users_table.php`
- `0001_01_01_000001_create_cache_table.php`
- `0001_01_01_000002_create_jobs_table.php`
- `2026_11_18_000001_add_raven23_auth_fields_to_users.php`
- `2026_11_18_000002_ensure_personal_access_tokens_table.php`
- `2026_11_18_000003_reconcile_raven23_auth_schema.php`
- `2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php`
- `2026_11_21_000005_reconcile_guest_account_flag.php`
- `2026_11_22_000006_create_email_verification_tokens_table.php`
- `2026_11_22_000007_create_user_data_foundation_tables.php`
- `2026_11_23_000008_create_user_bookmarks_table.php`
- `2026_11_23_000009_create_user_recent_media_tables.php`

## 11. تحديث تنظيم الاختبارات — 23 نوفمبر 2026
- `tests/README.md` يحدد main كمصدر الاختبارات الوحيد ويشرح Feature/SQLite وIntegration/PostgreSQL المؤقتة.
- أضيفت اختبارات BookmarkSyncTest وPlaylistSyncTest وPreferenceSyncTest وRecentlyWatchedSyncTest، وPostgresBookmarkSyncTest وPostgresPlaylistSyncTest وPostgresPreferenceSyncTest وPostgresRecentlyWatchedSyncTest.
- Docker/Pint/Security/Auth regression workflows تستهدف main فقط.
- refs فرعي TEST وUSERS كانت لا تزال ظاهرة في GitHub عند آخر تحقق؛ تقاعدت تنظيميًا ولم يُؤكد حذفها.