# جرد عقد API بين تطبيق RAVEN23 وRAVEN23-BACKEND

**تاريخ الجرد:** 21 نوفمبر 2026  
**Backend baseline:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**Android baseline:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** COMPLETE — جرد source-level لمسارات Backend ومستهلكي Laravel الأساسية؛ التكامل الحي يؤجل إلى staging.

## 1. قاعدة العنوان والعقد العام

- يضبط تطبيق Flutter عنوان Laravel عبر LARAVEL_API_URL أو --dart-define=LARAVEL_API_URL.
- AppEnvironment.normalizeLaravelApiUrl يضيف /api/v1/ عند الحاجة.
- LaravelApi يتوقع JSON object فيه success: bool في أعلى مستوى.
- AuthInterceptor يرسل Bearer token إلى عنوان Laravel المصرح فقط، ويتحقق من sessionOwner وsyncOwner لمنع نقل طلب بين حسابين بعد تبديل الجلسة.
- لا تخلط LARAVEL_API_URL مع RAVEN23_API_URL: الأخير يخدم واجهات scraper/source مستقلة.

## 2. المسارات الموجودة في Backend الحالي

المصدر: routes/api.php على Backend baseline.

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
| GET | /api/v1/user/profile | Bearer | قراءة ملف المستخدم |
| PUT | /api/v1/user/profile | Bearer | تحديث الاسم وusername وprofile_id وphoto_url |
| POST | /api/v1/user/change-email | Bearer | تغيير البريد مع تحقق كلمة المرور |
| POST | /api/v1/user/change-password | Bearer | تغيير كلمة المرور وإلغاء جميع tokens |
| DELETE | /api/v1/user/account | Bearer | إلغاء tokens ثم حذف الحساب |

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

## 4. مسارات يتوقعها كود العميل لكنها غير موجودة في Backend الحالي

المسارات التالية ظهرت في repositories أو integration tests في مستودع RAVEN23. لا توجد لها routes/controllers أو migrations مقابلة في Backend snapshot الذي تمت مراجعته.

| المجال | المسارات المتوقعة من العميل | وضع Backend |
|---|---|---|
| Bootstrap/config | GET config/bootstrap مع ETag | غير موجود |
| إعلانات | GET ads، POST ads/{id}/impression، POST ads/{id}/click | غير موجود |
| المفضلة | POST sync/bookmarks، DELETE sync/bookmarks/{type}/{id} | غير موجود |
| سجل المشاهدة والتقدم | POST sync/recently-watched | غير موجود |
| Wellness/viewing sessions | POST sync/wellness مع pagination/revision | غير موجود |
| القوائم | POST sync/playlists | غير موجود |
| استيراد القوائم | POST playlist-imports، GET playlist-imports/{id}، DELETE playlist-imports/{id}، POST playlist-imports/{id}/commit | غير موجود |
| مشاركة القوائم | PUT playlists/{id}/sharing، GET shared-lists/{code} | غير موجود |
| تسجيل جهاز/FCM | POST devices/register | غير موجود |
| OneSignal | POST push/subscriptions، DELETE push/subscriptions/{id} | غير موجود |
| رسائل/إشعارات داخلية | GET messages/active، GET push/messages/{id} | غير موجود |
| الاشتراكات والصلاحيات | GET billing/plans، GET user/entitlements | غير موجود |
| المحفظة | GET wallet، GET wallet/transactions، POST wallet/topups، GET wallet/topups/{id}، POST wallet/topups/{id}/payment، POST wallet/topups/{id}/reconcile، POST wallet/topup-link | غير موجود |
| إدارة الاشتراك | GET billing/coins، PUT/PATCH billing/subscription/wallet، POST billing/coupons/redeem | غير موجود |
| Flix AI | flix-ai/usage، flix-ai/conversations، flix-ai/messages ومسارات المحادثة | repository عميل موجود؛ تشغيله من injector ومسارات الخادم يحتاجان تحققًا إضافيًا |

### أولوية هذا الفرق

في MigrationFlags.fromRuntime، عندما يكون Firebase معطّلًا وLOCAL_DEV_MODE معطّلًا وRAVEN23_AUTH_ONLY غير مفعّل، تكون flags الافتراضية auth,ads,sync. أي إن مزامنة المفضلة والسجل وwellness والقوائم، وكذلك الإعلانات، قد تكون مفعّلة في build عادي حتى مع غياب routes الخادم المقابلة.

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

## 7. ما يزال يحتاج تحققًا قبل إغلاق الجرد

- مراجعة كل dynamic request paths في بقية خدمات العميل وربطها بالـfeature gate الذي يشغلها.
- توثيق body/response schemas كاملة لكل endpoint، وليس أسماء المسارات فقط.
- مطابقة النسخة الفعلية التي يستخدمها build production مع SHA التطبيق المذكور.
- اختبار contract end-to-end على disposable staging عندما يصبح backend مكتملًا؛ لم يُجرَ أي طلب إلى قاعدة الإنتاج أثناء هذا الجرد.
