# جرد قاعدة البيانات والبيانات — RAVEN23

**آخر مزامنة مصدر:** `617ce7efd669b4d3af61c42f77b4cd1f986e3fae` — 27 نوفمبر 2026؛ الأقسام الأولى تحتفظ ببعض سياق خط الأساس التاريخي  
**Android baseline:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** COMPLETE على مستوى source inventory — مبني على جميع migrations/models/client data stores؛ لم يتم الاتصال بقاعدة الإنتاج وفق قواعد السلامة.

## 1. ما يمكن إثباته من Backend source

### جدول users

المشروع الحالي يحتوي Laravel 13 source و`composer.json`/`composer.lock` و`artisan` و`bootstrap` و`app` و`routes` ملتزمة في Git. هذا يلغي فجوة scaffold القديمة المسجلة في جرد 21 نوفمبر. حقول RAVEN23 التي تضيفها migrations/model:

| العمود | الغرض |
|---|---|
| id | معرّف المستخدم |
| name / email / password | بيانات الحساب والاعتماد |
| email_verified_at | تاريخ تحقق البريد (يحتاج استكمال flow) |
| username | اسم مستخدم فريد |
| profile_id | اختيار صورة/ملف افتراضي |
| photo_url | رابط صورة الملف |
| provider | email/google/guest |
| google_id | هوية Google، فريدة عند وجودها |
| is_verified | حالة التحقق العامة المستخدمة في profile |
| is_account_verified | شارة تحقق منفصلة |
| is_guest | علامة حساب الضيف؛ migration الأخيرة تحول NULL إلى FALSE وتفرض NOT NULL |
| created_at / updated_at | تواريخ الإنشاء والتحديث |
| remember_token | حقل Laravel الأساسي إنشاؤه scaffold |

المخطط الأساسي الحالي يتضمن migrations Laravel القياسية الملتزمة في `database/migrations/0001_01_01_*`، إلى جانب اختبارات `LaravelFoundationTest` و`DatabaseMigrationRehearsalTest`. لا تعتمد النسخة الحالية على تنزيل skeleton غير محفوظ من Git.

الفهارس/القيود التي تذكرها Raven23AuthSchema:
- unique على username.
- unique على google_id.
- index على is_guest.
- تسوية username غير الصحيح/المفقود للحسابات الموجودة بدل حذف الحسابات.

### جدول personal_access_tokens

Sanctum bearer sessions:

| العمود | الغرض |
|---|---|
| id | هوية token row |
| tokenable_type / tokenable_id | علاقة polymorphic بالمستخدم |
| name | اسم token/platform |
| token | hash فريد |
| abilities | صلاحيات token |
| last_used_at / expires_at | استعمال/انتهاء |
| device_id / device_name / platform | معلومات تثبيت التطبيق والجهاز |
| created_at / updated_at | أوقات التسجيل |

يوجد index مركب على tokenable_type, tokenable_id, device_id. بما أن العلاقة polymorphic، لا يوجد foreign key عادي يحذف tokens آليًا عند حذف user؛ AuthController يلغي tokens يدويًا قبل حذف الحساب.

### جدول migrations

ينشئه Laravel migration runner لتتبع migrations. لا ينبغي اعتباره بيانات مستخدم.

### الجداول الأخرى

على baseline 21 نوفمبر لم تكن توجد migrations لهذه المجالات. على main الحالي أضيفت bookmarks وrecent media وplaylists وwellness وprivate media وnotification outbox وpush subscription registry وentitlements/subscription ledger وwallet:
- bookmarks / user_bookmarks
- recently_watched_movies / recently_watched_episodes
- wellness_sessions / wellness_daily_summaries
- (historical baseline) playlists / playlist_entries / playlist_sync_operations
- devices / push_subscriptions / messages
- banners/ads / app_configurations
- plans/subscriptions/entitlements/wallet/topups/transactions
- Flix AI conversations/turns/usage

قد تكون هناك جداول framework الافتراضية داخل Laravel skeleton الناتج وقت البناء، لكن لم يتم تثبيتها من Git history أو الاتصال بقاعدة بيانات للتحقق منها. لا نفترض وجودها أو اعتماد التطبيق عليها.

## 2. Migrations الموجودة في Backend عند خط الأساس 21 نوفمبر 2026 (سجل تاريخي)

| الملف | الأثر |
|---|---|
| 2026_11_18_000001_add_raven23_auth_fields_to_users.php | يستدعي Raven23AuthSchema لإضافة/تسوية حقول users وSanctum |
| 2026_11_18_000002_ensure_personal_access_tokens_table.php | ينشئ أو يتحقق من جدول Sanctum دون حذف سجل موجود |
| 2026_11_18_000003_reconcile_raven23_auth_schema.php | إعادة تسوية idempotent للمخطط السابق |
| 2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php | يضيف metadata للجهاز وفهرس lookup |
| 2026_11_21_000005_reconcile_guest_account_flag.php | يحول is_guest إلى boolean وينظف NULL ويجعل العمود NOT NULL |

معظم down() لا تقوم بإزالة أعمدة الهوية أو جدول tokens عمدًا. لا تشغّل migration أو rollback على الإنتاج خلال الجرد.

ملاحظة: قائمة migrations ذات الخمس ملفات أعلاه تصف لقطة خط الأساس 21 نوفمبر فقط؛ فهرس migrations الحالي الكامل (23 ملفًا) في القسم 11 أدناه.

## 3. ما يخزنه تطبيق Android محليًا

من ملفات RAVEN23:
- المفضلة: SQLite عبر BookmarkDatabaseController وBookmarkProvider.
- سجل الأفلام: SQLite عبر RecentlyWatchedMoviesController.
- سجل حلقات المسلسلات/الأنمي: SQLite عبر RecentlyWatchedEpisodeController.
- التقدم: elapsed/remaining وupdated_at_utc.
- عمليات الحذف المحلية للسجل تستخدم tombstones deleted_at_utc قبل إرسالها.
- wellness: قاعدة محلية عبر WellnessDatabaseController، فيها sessions وdaily summaries.
- قوائم مخصصة: PlaylistStore محلي وسجل عمليات/تغييرات للمزامنة.
- owner isolation: LibraryScope يميز guest عن user:<id>.
- token: SecureTokenStore؛ تفضيلات وحالة sync/cursors في KvStore.

هذه بيانات مستخدم محلية مهمة. لا يجب حذف قاعدة SQLite أو تغيير owner mapping أثناء إعادة بناء Backend دون اختبار ترحيل محلي.

## 4. تاريخ Firebase

وثيقة docs/migration_prd_firebase_to_laravel.md تصف تاريخًا سابقًا لبيانات Firestore: users وbookmarks وrecently-watched وwellness. وتوجد في Flutter حاليًا Firebase Auth bridge وFirebase Remote Config وFirebase Messaging/Analytics/Crashlytics dependencies. لم نصل إلى Firestore production أو export أو Firebase console في هذه المراجعة؛ لذلك لا يمكن تحديد ما إذا كانت هناك سجلات تاريخية ما زالت موجودة أو تحتاج ترحيلًا.

**قاعدة الحماية:** لا تحذف Firebase bridge أو إعدادات المشروع أو أي مصدر بيانات قديم قبل إغلاق اختبار نقل الحسابات والبيانات.

## 5. مخاطر تشغيلية مرتبطة بالبيانات

1. Startup الخاص بخادم الويب لا يشغّل migrations ولا يتصل بقاعدة PostgreSQL لمجرد boot؛ migrations عملية release منفصلة وصريحة باستخدام دور الترحيل فقط.
2. لا ينشئ web startup probe accounts أو Sanctum tokens أو سجلات أجهزة؛ liveness `/up` مستقل عن قاعدة البيانات، و`/ready` read-only ويتحقق من PostgreSQL/schema.
3. migration reconciliation تعالج username/is_guest في جدول users؛ يجب اختبارها على clone من schema السابق قبل أي تحديث إنتاجي.
4. defaults الحالية في `.env.example` هي `CACHE_STORE=database` و`SESSION_DRIVER=database` و`QUEUE_CONNECTION=database`؛ `config/cache.php` و`config/queue.php` تتبع Laravel database drivers، ولا يوجد فرض file-only.
5. ثبت synthetic encrypted PostgreSQL backup/restore داخل CI على قواعد مؤقتة اصطناعية مع SHA-256/row-count/constraints/index checks؛ لا يثبت ذلك backup/restore إنتاجيًا أو مزودًا خارجيًا.
6. لا نعرف عدد المستخدمين أو عدد token rows أو حالة القيود الحالية؛ لم يتم الاتصال بقاعدة Neon أو قراءة بياناتها.

## 6. جرد بيانات النقل المطلوب لاحقًا

قبل Phase 12 يجب إعداد:
- schema dump بدون أسرار.
- table/column/constraint/index report.
- row counts وIDs للمقارنة في بيئة معزولة (مع عدم استخراج بيانات شخصية إلى التقرير).
- encrypted backup checksum وrestore log.
- foreign/polymorphic relationship map.
- mapping من مصدر Firebase/Neon إلى الوجهة.
- خطة التغييرات المتزامنة والنسخ delta.
- إثبات أن استعادة نسخة backup نجحت.

## 7. المصادر

- Migrations: https://github.com/m5ham3ds/RAVEN23-BACKEND/tree/main/database/migrations
- User model: https://github.com/m5ham3ds/RAVEN23-BACKEND/blob/main/app/Models/User.php
- Raven23AuthSchema: https://github.com/m5ham3ds/RAVEN23-BACKEND/blob/main/app/Support/Raven23AuthSchema.php
- Client SQLite/sync layer: https://github.com/m5ham3ds/RAVEN23/tree/main/lib/data/sync
- Local database controllers: https://github.com/m5ham3ds/RAVEN23/tree/main/lib/controllers


## 8. تحديث الجداول المنفذة — 23 نوفمبر 2026

### `user_bookmarks`
- Migration: `2026_11_23_000008_create_user_bookmarks_table.php`.
- Model/controller: `UserBookmark`, `BookmarkSyncController`.
- مفتاح فريد (user_id, media_type, media_id)، وFK من user_id إلى users.id مع ON DELETE CASCADE.
- media_type = movie أو tv؛ metadata JSON؛ لا يقبل API owner_id من العميل.

### `user_recent_media`
- Migration: `2026_11_23_000009_create_user_recent_media_tables.php`.
- owner-scoped بـuser_id؛ مفتاح فريد (user_id, media_type, media_id).
- يخزن metadata JSON، elapsed، remaining، updated_at_utc وdeleted_at_utc بوحدة milliseconds، وrevision.
- media_type = movie أو episode؛ حقل is_anime يبقى محليًا ولا يرسله Flutter إلى Backend.

### `user_sync_revisions`
- migration نفسها تنشئ domain revision لكل (user_id, domain)؛ domain الحالي `recent`.
- FK user_id cascade، unique (user_id, domain)، ويستخدم القفل داخل المعاملة لضبط revision.
- migration additive وdown() forward-only.

### Playlists — migration 2026_11_23_000010
- user_playlists: UUID primary key، owner user_id FK cascade، name/description/version، timestamps.
- user_playlist_items: FK cascade to playlist؛ PK composite (playlist_id, media_type, media_id)، position وmetadata للعنوان وgenre IDs.
- user_playlist_operations: owner FK cascade، unique (user_id, operation_id)، action، payload_hash، result JSON لإعادة المحاولة الآمنة.
- user_playlist_changes: owner FK cascade، revision/entity_type/entity_key/payload/deleted event stream، مع indexes لدعم snapshot paging.
- لا توجد جداول imports أو shared/public playlists؛ هذه المسارات خارج نطاق Phase06.
- لم يتم الاتصال بقاعدة Neon الإنتاجية أو تنفيذ migrations عليها. هذا الجرد لا يثبت وجود أو حجم بيانات الإنتاج.


## 9. Queue persistence — 23 نوفمبر 2026

- Laravel queue persistence يعيد استخدام migration القائمة `database/migrations/0001_01_01_000002_create_jobs_table.php` التي تنشئ `jobs`, `job_batches`, و`failed_jobs` عند غيابها.
- لم تنشأ جداول جديدة في شريحة Phase08A.
- إشعارات verification/reset تُرسل كـqueued jobs مشفّرة؛ لذلك تخزن queue payloads بصورة مشفرة باستخدام Laravel queue encryption.
- يستخدم database queue في الإعداد النموذجي؛ worker مستقل عن HTTP web process، ويجب تشغيله فقط مع `APP_KEY` ثابت وPostgreSQL المشترك بعد إعداد deployment.
- لم يتم تشغيل worker على قاعدة إنتاج أو قراءة صفوف jobs/failed_jobs الحية.

## 10. Push subscriptions — 24 نوفمبر 2026

- Migration: 2026_11_24_000015_create_user_push_subscriptions_table.php.
- Table: user_push_subscriptions.
- Columns: id, user_id (FK cascade), provider, token_hash (HMAC-SHA-256 hex), token (Laravel encrypted cast serialized in text column), platform, app_version, last_seen_at, timestamps.
- Unique key (provider, token_hash) يمنع تسجيل الرمز نفسه مرتين ضمن provider نفسه؛ index (user_id, provider) لدعم قراءة اشتراكات المالك.
- HMAC uses provider namespace and APP_KEY-derived keyed digest; raw FCM/OneSignal values are encrypted at rest and are not the indexed lookup value.
- Re-registering the same provider/token transfers association to the authenticated account performing registration; OneSignal deletion is user-scoped. Deleting user cascades subscriptions.
- PostgreSQL and SQLite Feature tests cover encrypted storage, idempotency, owner transfer/deletion, validation and cascade.
- No production database migration was executed; no real push tokens or provider credentials were used.

## 11. مزامنة المخطط الحالي من Git tree — 27 نوفمبر 2026

**الرأس:** `617ce7efd669b4d3af61c42f77b4cd1f986e3fae` — `main`. شجرة Git recursive غير مبتورة؛ يوجد 23 ملف migration ملتزمًا. لا تمثل هذه الجردات اتصالًا بقاعدة إنتاج أو قياسًا لعدد صفوفها.

### migrations الحالية (23)

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

### المجالات والجداول التي يثبتها المصدر الحالي

- Laravel framework: `users`, `cache`, `jobs`, `job_batches`, `failed_jobs`, `migrations`، وSanctum `personal_access_tokens`.
- User foundation: `user_profiles`, `user_preferences`, `user_devices`, و`email_verification_tokens`.
- Personal library/sync: `user_bookmarks`, `user_recent_media`, `user_sync_revisions`, `user_playlist_*`, bookmark/preference sync state، وwellness session/daily summary tables.
- Private media: `user_media` metadata؛ الملف نفسه يمر عبر private filesystem/S3-compatible storage abstraction وليس قرص الحاوية العامة.
- Notifications: `notification_outbox` و`user_push_subscriptions`; payloads/registered tokens مشفرة؛ لا sender provider خارجي مثبت.
- Entitlements/billing: `user_entitlements`, `subscription_events` (append-only event ledger/projection).
- Wallet: `user_wallets`, `wallet_transactions` (append-only internal ledger؛ القراءة HTTP فقط ولا توجد top-up/payment write API).

### نقاط الاتصال والخصوصية

- التطبيق يستخدم PostgreSQL، مع دور `DB_URL` محدود للصلاحيات ودور `DB_MIGRATION_URL` لمهمة ترحيل منفصلة.
- `.env.example` يحدد `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local`, و`RAVEN23_PRIVATE_STORAGE_DISK=private_media`.
- real backup/restore لقاعدة مستخدمين أو مصدر إنتاج لم يُنفذ؛ الاختبار الناجح اصطناعي داخل CI فقط.
- لا تُستنتج row counts أو أحجام قاعدة الإنتاج أو وجود جداول/سجلات حية من Git migrations؛ يلزم مصدر ووجهة معتمدان وrestore معزول قبل Phase12.
