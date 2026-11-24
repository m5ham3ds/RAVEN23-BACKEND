# جرد عقد API بين تطبيق RAVEN23 وRAVEN23-BACKEND

**تاريخ الجرد:** 24 نوفمبر 2026
**Backend implementation SHA:** f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19
**Android baseline:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** COMPLETE — جرد source-level لمسارات Backend ومستهلكي Laravel الأساسية؛ التكامل الحي يؤجل إلى staging.

## 1. قاعدة العنوان والعقد العام

- يضبط تطبيق Flutter عنوان Laravel عبر LARAVEL_API_URL أو --dart-define=LARAVEL_API_URL.
- AppEnvironment.normalizeLaravelApiUrl يضيف /api/v1/ عند الحاجة.
- LaravelApi يتوقع JSON object فيه success: bool في أعلى مستوى.
- AuthInterceptor يرسل Bearer token إلى عنوان Laravel المصرح فقط، ويتحقق من sessionOwner وsyncOwner لمنع نقل طلب بين حسابين بعد تبديل الجلسة.
- لا تخلط LARAVEL_API_URL مع RAVEN23_API_URL: الأخير يخدم واجهات scraper/source مستقلة.

## 2. المسارات الموجودة في Backend الحالي

المصدر: routes/api.php على main implementation SHA f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19.

| الطريقة | المسار | المصادقة | وضع التنفيذ |
|---|---|---|---|
| GET | /up | عام | Laravel health من bootstrap |
| GET | /api/v1/health | عام | يفحص PostgreSQL ومخطط users وSanctum؛ يعرض حاليًا تفاصيل runtime أكثر مما ينبغي |
| POST | /api/v1/auth/register | عام | إنشاء حساب بريد وإصدار Sanctum token |
| POST | /api/v1/auth/login | عام | تسجيل الدخول بالبريد وكلمة المرور |
| POST | /api/v1/auth/google | عام | تحقق Google ID token ثم إصدار session |
| POST | /api/v1/auth/guest | عام | إنشاء حساب ضيف خادمي |
| POST | /api/v1/auth/forgot-password | عام | المسار موجود لكن يعيد 503؛ غير مكتمل |
| POST | /api/v1/auth/reset-password | عام | المسار موجود لكن يعيد 503؛ غير مكتمل |
| GET | /api/v1/users/check-username | عام | التحقق من توفر اسم المستخدم |
| POST | /api/v1/auth/logout | Bearer | إلغاء token الحالي |
| POST | /api/v1/devices/register | Bearer | تسجيل/تحديث FCM token مشفر ومرتبط بالمستخدم الحالي؛ fcm_token, platform, app_version |
| POST | /api/v1/push/subscriptions | Bearer | تسجيل/تحديث OneSignal subscription UUID مشفر وidempotent؛ subscription_id, platform, app_version |
| DELETE | /api/v1/push/subscriptions/{subscriptionId} | Bearer | إلغاء OneSignal subscription ضمن نطاق مالك الحساب الحالي |
| GET | /api/v1/user/media/{owner}/{media}/temporary | رابط signed مؤقت | قراءة ملف يملكه المسار؛ التوقيع ووقت الانتهاء إلزاميان، TTL الافتراضي 5 دقائق (حد أقصى 15) |
| POST | /api/v1/user/media | Bearer | رفع avatar أو attachment؛ validation للمحتوى والنوع والحجم والحصة الاختيارية |
| GET | /api/v1/user/media/{media} | Bearer | تنزيل/عرض ملف المالك فقط |
| DELETE | /api/v1/user/media/{media} | Bearer | حذف metadata للمالك وجدولة object cleanup مشفر after-commit |
| GET | /api/v1/user/profile | Bearer | قراءة ملف المستخدم |
| PUT | /api/v1/user/profile | Bearer | تحديث الاسم وusername وprofile_id وphoto_url |
| POST | /api/v1/user/change-email | Bearer | تغيير البريد مع تحقق كلمة المرور |
| POST | /api/v1/user/change-password | Bearer | تغيير كلمة المرور وإلغاء جميع tokens |
| DELETE | /api/v1/user/account | Bearer | إلغاء tokens ثم حذف الحساب مع cascade لبيانات owner FK |
| POST | /api/v1/sync/bookmarks | Bearer | مزامنة movie/tv bookmarks، owner-scoped |
| DELETE | /api/v1/sync/bookmarks/{type}/{id} | Bearer | حذف bookmark idempotent للمستخدم الحالي |
| POST | /api/v1/sync/recently-watched | Bearer | مزامنة movie/episode progress مع revision/tombstones |
| POST | /api/v1/sync/playlists | Bearer | مزامنة القوائم والعناصر بالعمليات والإصدارات والصفحات الثابتة |

### User media

- الصور: JPEG/PNG/WebP بحد أقصى 5 MiB وأبعاد لا تتجاوز 4096×4096.
- المرفقات: PDF/TXT بحد أقصى 10 MiB.
- object keys تُنشأ على الخادم، وتبقى الملفات على private disk؛ download responses تستخدم private/no-store وnosniff.
- `temporary_download_url` رابط signed قصير العمر للاستخدام دون Bearer header؛ `download_url` يتطلب Bearer وملكية المستخدم.
- `RAVEN23_USER_MEDIA_QUOTA_BYTES=0` يعني عدم وضع حصة؛ أي قيمة موجبة تفرض quota واحدة مجمعة للصور والمرفقات لكل حساب.
- `media:cleanup-orphans` dry-run افتراضيًا؛ الحذف المجدول يبقى معطلاً إلا بعد تفعيل `RAVEN23_PRIVATE_MEDIA_ORPHAN_CLEANUP_ENABLED` صراحةً.

## 3. عقد المصادقة كما يستهلكه تطبيق Flutter

المصادر الأساسية: lib/data/repositories/auth_repository.dart، lib/data/sources/laravel_api.dart، lib/data/models/auth_requests.dart، lib/data/models/auth_session.dart، lib/services/raven23_auth_service.dart، lib/presentation/session/session_view_model.dart.

| طلب العميل | Backend الحالي | ملاحظة توافق |
|---|---|---|
| POST auth/guest | موجود | العميل يتوقع token وuser.id |
| POST auth/register | موجود | العميل يرسل name/email/username/password/profile_id وبيانات الجهاز |
| POST auth/login | موجود | العميل يرسل email/password وبيانات الجهاز وguest_token عند وجوده |
| POST auth/google | موجود | يحتاج اختبار Google حقيقي من Android؛ لا يكفي mock |
| POST auth/firebase/exchange | غير موجود | يستخدمه FirebaseSessionBridge القديم عند تفعيل ENABLE_FIREBASE |
| GET user/profile | موجود | يتوقع envelope success وكائن user |
| PUT user/profile | موجود | الحقول المتوقعة متوافقة مبدئيًا |
| POST user/change-email | موجود | يتطلب current_password |
| POST user/change-password | موجود | بعد التغيير العميل يمسح الجلسة |
| POST auth/logout | موجود | token الحالي فقط |
| DELETE user/account | موجود | العميل قد يرسل acknowledge_wallet_credit؛ Backend يقبل الحقل لكنه لا ينفذ wallet policy حاليًا |
| GET users/check-username | موجود | العميل يقرأ available: bool |
| POST auth/forgot-password | موجود شكليًا | يرجع 503؛ لا يوجد mail flow مكتمل |
| POST auth/reset-password | موجود شكليًا | يرجع 503؛ لا يوجد reset token flow مكتمل |

**ملاحظة مهمة:** AuthRepository يحتوي exchangeFirebaseSession ويرسل id_token إلى auth/firebase/exchange. لا توجد route أو controller مقابلة في Backend الحالي. لا تحذف Firebase bridge أو SDK قبل حسم توافق الحسابات القديمة واختبار الترحيل.

## 4. مسارات يتوقعها كود العميل وحالتها في Backend الحالي

المسارات التالية ظهرت في repositories أو integration tests في مستودع RAVEN23. يعرض الجدول حالة التنفيذ الحالية؛ يظل CI غير معتمد على SHA Phase06 بسبب عدم تخصيص runner.

| المجال | المسارات المتوقعة من العميل | وضع Backend |
|---|---|---|
| Bootstrap/config | GET config/bootstrap مع ETag | غير موجود |
| إعلانات | GET ads، POST ads/{id}/impression، POST ads/{id}/click | غير موجود |
| المفضلة | POST sync/bookmarks، DELETE sync/bookmarks/{type}/{id} | موجود ومختبر ضمن Docker SQLite/PostgreSQL على main |
| سجل المشاهدة والتقدم | POST sync/recently-watched | موجود ومختبر ضمن Docker SQLite/PostgreSQL على main |
| Wellness/viewing sessions | POST sync/wellness مع pagination/revision | موجود؛ owner-scoped وrevision/cursor وtombstones، واختبارات SQLite/PostgreSQL |
| القوائم | POST sync/playlists | موجود ومختبر ضمن Docker SQLite/PostgreSQL على main |
| استيراد القوائم | POST playlist-imports، GET playlist-imports/{id}، DELETE playlist-imports/{id}، POST playlist-imports/{id}/commit | غير موجود |
| مشاركة القوائم | PUT playlists/{id}/sharing، GET shared-lists/{code} | غير موجود |
| تسجيل جهاز/FCM | POST devices/register | موجود؛ token مشفر وHMAC lookup وidempotent؛ لا يوجد sender أو delivery |
| OneSignal | POST push/subscriptions، DELETE push/subscriptions/{id} | موجود؛ UUID مشفر وowner-scoped؛ لا يوجد sender أو delivery |
| رسائل/إشعارات داخلية | GET messages/active، GET push/messages/{id} | غير موجود |
| الاشتراكات والصلاحيات | GET billing/plans، GET user/entitlements | غير موجود |
| المحفظة | GET wallet، GET wallet/transactions، POST wallet/topups، GET wallet/topups/{id}، POST wallet/topups/{id}/payment، POST wallet/topups/{id}/reconcile، POST wallet/topup-link | غير موجود |
| إدارة الاشتراك | GET billing/coins، PUT/PATCH billing/subscription/wallet، POST billing/coupons/redeem | غير موجود |
| Flix AI | flix-ai/usage، flix-ai/conversations، flix-ai/messages ومسارات المحادثة | repository عميل موجود؛ تشغيله من injector ومسارات الخادم يحتاجان تحققًا إضافيًا |

### أولوية هذا الفرق

في MigrationFlags.fromRuntime، عندما يكون Firebase معطّلًا وLOCAL_DEV_MODE معطّلًا وRAVEN23_AUTH_ONLY غير مفعّل، تكون flags الافتراضية auth,ads,sync. أي إن wellness وبعض مزايا sync غير المنفذة، وكذلك الإعلانات، قد تكون مفعّلة في build عادي رغم غياب routes الخادم المقابلة. أما bookmarks وrecently-watched وplaylists فلها routes على main الحالي.

الملفات التي تتحكم بذلك:
- lib/core/config/migration_flags.dart
- lib/core/config/app_runtime.dart
- lib/core/di/injector.dart
- lib/main.dart

هذا تناقض عملي بين comment في إعداد flags وبين القيمة الافتراضية الفعلية، ويجب حسمه في مراحل التنفيذ؛ لم يتم تعديل Flutter أو Backend أثناء هذه المرحلة.

## 5. اختبارات العقد الموجودة في تطبيق RAVEN23

- integration/laravel_foundation_smoke_test.dart اختبار اختياري عبر LARAVEL_SMOKE_URL، ويتوقع config/bootstrap وads وmessages/active و401 عند طلب profile دون token.
- test/integration/laravel_migration_e2e_test.dart اختبار opt-in فقط؛ يتطلب RAVEN23_E2E_API_URL وRAVEN23_E2E_ALLOW_WRITES=true، ويذكر صراحة أنه يجب تشغيله على local/disposable staging لا production. يغطي حسابًا اصطناعيًا وجهازين ومفضلة وwatch progress وclock skew وwellness وETag للإعدادات/الإعلانات وتسجيل الجهاز والخروج وحذف الحساب.
- اختبارات recently_watched_sync_test.dart وsync_checkpoint_test.dart تختبر منطق العميل المحلي (tombstones, revisions, cursors) ولا تثبت وجود مسارات الخادم.
- openapi.json في جذر تطبيق RAVEN23 يصف Scraper API v2 بمسارات /api/v2/*؛ ليس OpenAPI لخدمة Laravel account/backend ولا يجوز استخدامه كدليل على وجود مسارات Laravel.

## 6. المصادر

- Backend routes: https://github.com/m5ham3ds/RAVEN23-BACKEND/blob/main/routes/api.php
- Backend controller: https://github.com/m5ham3ds/RAVEN23-BACKEND/blob/main/app/Http/Controllers/Api/AuthController.php
- Flutter repositories: https://github.com/m5ham3ds/RAVEN23/tree/main/lib/data/repositories
- Flutter API source: https://github.com/m5ham3ds/RAVEN23/tree/main/lib/data/sources
- Flutter migration plan: https://github.com/m5ham3ds/RAVEN23/blob/main/docs/flutter_laravel_migration_phases.md
- Firebase migration PRD: https://github.com/m5ham3ds/RAVEN23/blob/main/docs/migration_prd_firebase_to_laravel.md

## 7. تحقق ثابت تم — اختبارات التكامل مؤجلة إلى staging

تم حصر ملفات Laravel repositories في Flutter ومصادر sync/network المعروفة، وفصل Scraper/TMDB عن Laravel. التوثيق يغطي الاستدعاءات الثابتة والـpaths الديناميكية التي ظهرت في repositories.

الأمور التالية ليست نواقص في جرد source، وإنما اختبارات تشغيل لاحقة:
- تشغيل العقد end-to-end على disposable staging بعد تنفيذ routes المطلوبة؛ لا توجد staging URL مثبتة حاليًا.
- Google OAuth الحقيقي على Android، وإرسال verification/reset email؛ هذه تتبع المرحلة 04.
- Push/OneSignal وS3-compatible integration ما زالا ضمن Phase08؛ private local upload/metadata/authorization/cleanup اجتازت اختبارات SQLite وPostgreSQL، لكن لم يتم اختبار bucket أو مزود سحابي حقيقي.
- Billing provider/webhooks؛ تتبع المرحلة 09 بعد اعتماد قواعد المنتج والمزود.
- مقارنة client build production الفعلي مع SHA الإصدار قبل النشر.
- عدم الوصول إلى قاعدة Neon الإنتاجية أو قراءة بياناتها كان مقصودًا وفق قواعد حماية البيانات؛ جرد المخطط الموجود هنا source-level فقط.

## 8. تفاصيل payloads التي أمكن استخلاصها من Flutter contract

هذه الحقول متوقعة من كود العميل و/أو وثيقة هجرة Flutter؛ لا تعني أن Backend الحالي ينفذها.

### المصادقة والحساب

- Register: name, email, username, password, profile_id (افتراضي 0)، photo_url في model العميل، guest_token عند ترقية guest. يضيف AuthRepository أيضًا device_id, device_name, platform وreplace_device_id عند الحاجة.
- Login: email, password, guest_token اختياري، وبيانات الجهاز.
- Google: id_token وguest_token اختياري، وبيانات الجهاز.
- Profile update: name, username, profile_id, photo_url.
- Change email: current_password, email.
- Change password: current_password, password.
- Session response: success, token, user؛ profile response: success, user.
- العميل يتوقع أن device-limit failure يمكن أن يحمل قائمة أجهزة/اختيار replace_device_id، لكن Backend الحالي لا يعرض signed-in devices ولا يفرض plan-based limit.

### Bookmarks

وثيقة migration PRD وعقد Flutter يذكران POST sync/bookmarks مع movies[], tvShows[] وdeleted_media[]. العميل يتوقع استجابة مدمجة تشمل movies وtvShows. حذف عنصر منفرد يتم عبر DELETE sync/bookmarks/{type}/{id}. تم تنفيذ controller والمخطط في migration 2026_11_23_000008؛ الرد يعيد union للمفضلة movie/tv ويظل owner-scoped.

### Recently watched / playback progress

- POST sync/recently-watched.
- العميل يرسل since_revision وmovies[] وepisodes[]؛ SyncTransport يضيف client_time_utc تلقائيًا.
- صف movie/episode يحمل بيانات content identity، elapsed، remaining، updated_at_utc، ويمكن أن يحمل deleted_at_utc للتombstone.
- الاستجابة المتوقعة server_revision, server_time_utc, movies[], episodes[] وقد يظهر requires_full_sync.
- العميل يعالج HTTP 422 clock_skew_detected مع server_time_utc، ويصحح timestamps المستقبلية ثم يعيد المحاولة مرة واحدة.
- العميل يجبر full pull عند فقد checkpoint أو عندما يصبح قديمًا؛ قاعدة البيانات لا يمكنها الاعتماد على clock الجهاز وحده.

### Wellness

- POST sync/wellness مع sessions[] وdaily[] وclient_time_utc.
- العميل يرسل مجموعات upload من 450 record قبل بدء paginated snapshot، ثم يقرأ مع since_revision/limit/cursor.
- الرد المتوقع يتضمن sessions[], daily[], server_revision, server_time_utc, has_more, next_cursor.
- client session يتضمن على الأقل id UUID، device_id، media_type، content_id، title، started_at_utc، ended_at_utc، timezone_offset_minutes، watched_ms، duration_ms، progress_end_ms، updated_at_utc.
- daily summary يستخدم local_day وsession_count وwatched_ms وupdated_at_utc.
- العميل ينفذ uploads قبل pagination؛ تكرار cursor أو فقدانه يعد خطأ format.

### Playlists

- POST sync/playlists منفذ بالكود على main مع operations[] وsince_revision وuntil_revision وcursor؛ الحد الأقصى 500 عملية/طلب.
- الرد: server_revision، full، results[]، playlists[]، items[]، next_cursor.
- العمليات: playlist.create/update/delete/reorder وitem.add/remove؛ operation IDs idempotent وbase_version يفرض conflict handling على update/reorder.
- snapshot يثبت حتى until_revision؛ playlists ترجع في الصفحة الأولى والعناصر تتصفح بالـcursor بما يوافق PlaylistSyncEngine.
- playlist imports: start preview ثم commit create/append ما زالت غير منفذة.
- sharing: PUT playlists/{id}/sharing مع public والقراءة العامة GET shared-lists/{code} ما زالت غير منفذة.

### Device/push, config, ads, billing

- devices/register: fcm_token أو fcmToken، platform، app_version/appVersion.
- push/subscriptions: subscription_id عند OneSignal؛ DELETE push/subscriptions/{id}.
- config/bootstrap: JSON envelope success/data مع ETag/If-None-Match؛ يحتوي features وbranding وupdates وnetwork وads وbanners وoccasional_theme بحسب smoke test.
- ads: GET ads يعيد ads[]؛ impression/click أحداث POST منفصلة وتستخدم client-side noStore queue.
- Billing topup: amount_cents، idempotency_key، activate_plus اختياري وterms_version. الدفع يرسل pay_currency وemail_receipt. هذه عقود عميل فقط حتى تنفيذ domain والبوابة المالية.
- Billing reads: billing/plans، user/entitlements، wallet، wallet/transactions مع cursor، billing/coins. Wallet renewal يستخدم PUT/PATCH billing/subscription/wallet، والقسائم POST billing/coupons/redeem.

## 9. فروقات دقيقة بين payload العميل وBackend الحالي

- Register model في Flutter يحتوي photo_url، لكن Backend register validation لا يقبل photo_url ضمن بيانات التسجيل ولا يحفظه في مسار إنشاء الحساب أو ترقية guest. يمكن تحديث photo_url لاحقًا عبر PUT user/profile.
- Flutter يضيف device_id وdevice_name وplatform لطلبات تسجيل/دخول email وGoogle. Backend يستقبلها داخل session() عبر deviceMetadata. أما createGuest في AuthRepository فلا يضيف deviceFields حاليًا؛ لذلك قد لا تُسجل هوية جهاز جلسة guest من هذا المسار.
- replace_device_id متاح في login وGoogle من العميل، وBackend يقيده بعلاقة tokens للمستخدم نفسه. لا يوجد API يعرض device list حتى يتمكن المستخدم من اختيار الجهاز من القائمة.
- Forgot password client payload: email. Reset payload: email وtoken وpassword وpassword_confirmation. Backend الحالي يعيد 503 من الطريقتين ولا يقرأ هذه الحقول.
- Delete account client قد يرسل acknowledge_wallet_credit=true ويتوقع 409 مع code wallet_credit_remaining عند وجود رصيد. Backend الحالي يتحقق من أن الحقل boolean فقط، ثم يلغي tokens ويحذف الحساب؛ لا يوجد wallet balance check. هذا اختلاف مهم يجب حله قبل تفعيل wallet/account deletion في الإنتاج.
- Change email في Backend يغير email_verified_at إلى NULL وis_verified إلى false، لكن لا يرسل verification email حاليًا.
- التسجيل بالبريد يضبط is_verified=false وis_account_verified=false. Google يضبط is_verified=true مع email_verified_at عند نجاح تحقق token من Google.

## 10. قيد المزامنة المشترك

SyncTransport يضيف client_time_utc إلى كل POST sync payload ويطلب من الخادم server_revision وserver_time_utc. عند HTTP 422 مع error=clock_skew_detected، يسجل وقت الخادم ويصحح timestamps المستقبلية ثم يعيد المحاولة مرة واحدة. لذلك أي Backend sync implementation يجب أن يثبت:
- client/server timestamp units (milliseconds UTC في كود العميل).
- حدود clock skew ومعنى 422.
- revision monotonicity وfull-sync behavior.
- idempotency وowner isolation.
- no-store وعدم إعادة إرسال POST تلقائيًا بصورة قد تكرر كتابة المستخدم.

هذه متطلبات contract مستخرجة من مصدر العميل وليست capabilities موجودة في Backend الحالي.


## 11. تحقق Backend المحدث — 24 نوفمبر 2026

- SHA الحالي الموثق `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`.
- CI الأربعة ناجحة؛ Docker: SQLite Feature 109 tests / 1,196 assertions، PostgreSQL Integration 15 tests / 295 assertions.
- لا توجد PRs مفتوحة والفرع الدائم الوحيد هو `main`.

## 12. Push registration contract — 24 نوفمبر 2026

- تم التحقق من العقد في lib/data/repositories/device_repository.dart في تطبيق Flutter.
- Backend routes الثلاثة موضوعة خلف Sanctum وrate limiter الخاص بإدارة الأجهزة.
- FCM token وOneSignal subscription ID لا يعادان في response ولا يستخدمان كقيم index نصية؛ token مشفر في model، وHMAC hash هو مفتاح idempotency والبحث.
- إعادة تسجيل الرمز نفسه تحدّث الحساب المرتبط به إلى الحساب الذي أرسل registration الحالي؛ DELETE OneSignal يتطلب المصادقة ويحدّث صف المالك الحالي فقط.
- التسجيل لا يرسل إشعارات ولا يثبت وصولها؛ provider sender/credentials/staging خارج هذا العقد الحالي.
- CI على SHA df390286cbe2170d4b18a96dad06835379091019: Docker [45320144852](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144852)، Auth [45320144671](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144671)، Pint [45320144645](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144645)، Security [45320144702](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144702) — كلها SUCCESS.


## 13. تحديث التحقق — 24 نوفمبر 2026، SHA `f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19`

- Backend routes والجداول والاختبارات على هذا الرأس اجتازت بوابات CI الأربع: Docker [45326348001](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348001)، Auth [45326348002](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348002)، Pint [45326347992](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326347992)، Security [45326348012](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348012).
- PostgreSQL integration suite تضم الآن اختبار ثلاث عمليات متزامنة مستقلة. فازت أحدث نسخة، وأعيد حساب daily summary بعد التداخل المتوازي.
- Push routes الثلاثة هي registration/storage فقط؛ لا تمثل إرسال إشعارات أو اختبار وصول من provider فعلي.
- Flutter `SyncRunner` يضيف bounded retries وin-memory telemetry على app SHA `027f51a`; full account/device-id rotation and production/staging verification are still separate acceptance items.


## 14. Phase09-09A — entitlement read contract (24 نوفمبر 2026)

- **Flutter consumer:** `lib/data/repositories/billing_repository.dart` يستدعي `GET user/entitlements` مع المصادقة وno-store. يتوقع الغلاف `success`، والكيان `entitlements` وفيه `ad_free`, `starts_at`, `expires_at`, `revision`، إضافة إلى `server_time` و`refresh_after_seconds`.
- **Flutter owner safety:** `PlusController` يربط النتيجة بمالك الحساب/نسخة الطلب، ويعتبر الوصول صالحًا فقط بعد تأكيد الخادم، قبل expiry، وضمن offline grace. لم يتم تغيير هذا السلوك أو نموذج Flutter.
- **Backend قبل 09A:** `routes/api.php` لم يحتوِ مسار entitlement أو أي controllers/models/migrations للفوترة؛ كانت هذه الواجهات client-only.
- **Backend بعد 09A:** `GET /api/v1/user/entitlements` أصبح ضمن `auth:sanctum`. يستخدم حساب الجلسة فقط ولا يقرأ user_id من الطلب. يعيد `ad_free=false` افتراضيًا، ولا يسمح بالمنح/الإلغاء عبر API عامة.
- **البيانات:** `user_entitlements` يمثل الحالة الحالية بمفتاح unique (`user_id`, `code`) وحقول starts_at/expires_at/revoked_at/revision/source. لا يحتوي حتى الآن على سجل أحداث append-only أو payment linkage.
- **العقود التالية ما زالت client-only:** `GET billing/plans`، `GET wallet`، `GET wallet/transactions`، `GET billing/coins`، `POST wallet/topups`، payment/reconcile/topup-link، wallet renewal PUT/PATCH، وcoupon redemption. لا تُنفذ قبل اعتماد قواعد المنتج/مزود الدفع ذات الصلة.
- **تعارض قائم:** حذف الحساب يتطلب في Flutter التعامل مع `wallet_credit_remaining` عند وجود رصيد؛ Backend الحالي لا يملك wallet ledger ولا يحسب هذا الشرط. يعالج ذلك قبل تمكين wallet/account deletion.
