# جرد قاعدة البيانات والبيانات — RAVEN23

**Backend baseline:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**Android baseline:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** PARTIAL — مبني على migrations/models/client code؛ لم يتم الاتصال بقاعدة الإنتاج.

## 1. ما يمكن إثباته من Backend source

### جدول users

قاعدة المشروع التي ينشئها Dockerfile هي Laravel 13، لكن Laravel scaffold نفسه يُنزّل أثناء build ولا توجد نسخة كاملة منه في Git. حقول RAVEN23 التي تضيفها migrations/model:

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

حقول Laravel الأساسية أعلاه تُستمد من scaffold وقت البناء واختبارات Laravel، وليست جميعها موثقة بملف migration committed داخل المستودع. يجب تثبيت migrations الأصلية ضمن المرحلة 01 بدل الاعتماد على scaffold خارجي.

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

لا توجد في المستودع الحالي migrations committed أو Models/Controllers واضحة لجداول:
- bookmarks / user_bookmarks
- recently_watched_movies / recently_watched_episodes
- wellness_sessions / wellness_daily_summaries
- playlists / playlist_entries / playlist_sync_operations
- devices / push_subscriptions / messages
- banners/ads / app_configurations
- plans/subscriptions/entitlements/wallet/topups/transactions
- Flix AI conversations/turns/usage

قد تكون هناك جداول framework الافتراضية داخل Laravel skeleton الناتج وقت البناء، لكن لم يتم تثبيتها من Git history أو الاتصال بقاعدة بيانات للتحقق منها. لا نفترض وجودها أو اعتماد التطبيق عليها.

## 2. Migrations الموجودة في Backend

| الملف | الأثر |
|---|---|
| 2026_11_18_000001_add_raven23_auth_fields_to_users.php | يستدعي Raven23AuthSchema لإضافة/تسوية حقول users وSanctum |
| 2026_11_18_000002_ensure_personal_access_tokens_table.php | ينشئ أو يتحقق من جدول Sanctum دون حذف سجل موجود |
| 2026_11_18_000003_reconcile_raven23_auth_schema.php | إعادة تسوية idempotent للمخطط السابق |
| 2026_11_20_000004_add_device_metadata_to_personal_access_tokens.php | يضيف metadata للجهاز وفهرس lookup |
| 2026_11_21_000005_reconcile_guest_account_flag.php | يحول is_guest إلى boolean وينظف NULL ويجعل العمود NOT NULL |

معظم down() لا تقوم بإزالة أعمدة الهوية أو جدول tokens عمدًا. لا تشغّل migration أو rollback على الإنتاج خلال الجرد.

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

1. docker/start.sh يشغّل php artisan migrate --force تلقائيًا عند startup باستخدام DB_MIGRATION_URL.
2. docker/verify-auth-schema.php ليس read-only تمامًا: ينشئ حساب probe وSanctum token وبيانات جهاز اصطناعية ثم يحاول تنظيفها في كل startup. لا نشغله يدويًا على إنتاج خلال هذه المرحلة.
3. migration reconciliation تعالج username/is_guest في جدول users؛ يجب اختبارها على clone من schema السابق قبل أي تحديث إنتاجي.
4. CACHE_STORE=file مفروض في الإنتاج؛ file cache/session state ليست مشتركة تلقائيًا بين أكثر من instance.
5. لم يثبت في الملفات التي تمت مراجعتها وجود نسخ احتياطية مشفرة مستقلة أو restore drill ناجح.
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
