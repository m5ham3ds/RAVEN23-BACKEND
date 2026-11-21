# تقرير خط الأساس — RAVEN23-BACKEND

## معلومات المراجعة

- Backend repository: m5ham3ds/RAVEN23-BACKEND (خاص).
- Android repository: m5ham3ds/RAVEN23.
- Backend baseline: 9f29fa55afa21c0445be82d4590a4a532bb1747c.
- Android baseline: 6d12023e446db3d444716c3b407572a89cf545be.
- التاريخ: 21 نوفمبر 2026.
- نوع المراجعة: Git trees وsource/config/tests/workflows وclient API contracts؛ read-only.
- الحالة: **Phase 00 COMPLETE — static source inventory.**

## 1. حجم وبنية المستودع

Git tree recursive على Backend baseline لم يكن truncated:
- 34 tracked files.
- 17 directories.
- 10 GitHub Actions workflows.
- 5 database migrations.
- 2 test files (Feature وIntegration).
- لا توجد composer.json أو composer.lock أو artisan أو .env.example في الجذر.

الفهرس الكامل: BACKEND_FILE_CATALOG.md.

مستودع Android عند SHA المذكور يحتوي 2,085 tracked files بحسب Git tree recursive. تمت مراجعة وحدات Laravel client/sync ذات العلاقة، ولم تُعامل ملفات Scraper API/TMDB كأنها Laravel backend.

## 2. ملاحظة هيكل Laravel

Dockerfile ينشئ Laravel 13 جديدًا أثناء البناء عبر composer create-project، ويضيف Sanctum وgoogle/apiclient ثم ينسخ ملفات RAVEN23 المحددة إليه. هذه طريقة أثبتت نجاحها في CI لكنها لا تجعل المصدر مشروع Laravel مستقلًا بالكامل، ولا تثبت كل الاعتماديات عبر lock file.

Docker يستخدم PHP 8.4 CLI بينما README يذكر PHP 8.3+؛ يجب توحيد النسخة المدعومة في Phase 01.

## 3. وظائف موجودة في Backend

- التسجيل بالبريد وكلمة المرور وتسجيل الدخول.
- إنشاء حساب ضيف.
- ترقية حساب ضيف إلى حساب بريد أو Google مع الحفاظ على user ID.
- التحقق البرمجي من Google ID token عبر google/apiclient.
- جلسات bearer عبر Laravel Sanctum.
- بيانات تعريف الجهاز على token واستبدال token السابق للجهاز نفسه.
- تسجيل الخروج وإبطال token الحالي.
- قراءة وتحديث الملف الشخصي.
- تغيير البريد وكلمة المرور.
- حذف الحساب بعد إلغاء tokens.
- التحقق من توفر username.
- migrations وفحوص schema مرتبطة بـ PostgreSQL.
- health endpoint وسجلات أخطاء تحاول تجنب SQL والأسرار.
- Feature tests باستخدام SQLite واختبارات Integration باستخدام PostgreSQL.

وجود الكود لا يعني أن كل التكاملات الخارجية اختُبرت فعليًا؛ Google على جهاز Android والبريد الإلكتروني لا يزالان بحاجة إلى تحقق حقيقي. Forgot/reset password routes موجودة لكنها ترجع 503.

## 4. دليل GitHub Actions على خط الأساس

على SHA المذكور:
- Backend Docker Validation #182: SUCCESS.
- 11 Feature tests ناجحة مع 68 assertions.
- 3 PostgreSQL Integration tests ناجحة مع 72 assertions على PostgreSQL 16 معزولة في CI.
- Live Authentication Smoke #127: SUCCESS؛ تحقق من commit المنشور على Render وصحة health وقاعدة البيانات والمخطط ووصول مسارات API بطلبات GET غير معدلة للبيانات.

هذه النتائج تثبت نجاح الاختبارات المحددة فقط، ولا تثبت أن كل وظائف التطبيق أو Google/البريد/المزامنة/النسخ الاحتياطي اختُبرت.

## 5. نتائج مطابقة Flutter API

مستودع RAVEN23 لديه LaravelApi وAuthRepository وrepositories للمفضلة والسجل وwellness والقوائم والاشتراكات والأجهزة والإعلانات والإعدادات والرسائل وFlix AI. كثير من هذه الطبقات يتوقع routes غير موجودة في Backend الحالي. راجع API_CONTRACT_INVENTORY.md وFEATURE_MATRIX.md.

الملاحظة الحرجة: MigrationFlags الافتراضي عند تعطيل Firebase وLOCAL_DEV_MODE يعطي auth,ads,sync، ما يعني أن ads/sync يمكن تشغيلهما في build عادي رغم غياب routes الخادم المقابلة.

openapi.json في تطبيق RAVEN23 هو وصف Scraper API v2 ولا يمثل عقد Laravel.

## 6. مشكلات ومخاطر معمارية وتشغيلية

1. غياب Composer manifests في الجذر واعتماد Docker على create-project أثناء البناء.
2. runtime يستخدم PHP built-in server؛ يجب اختيار Nginx/PHP-FPM أو FrankenPHP في Phase 02.
3. startup مرتبط بـ Render/Neon ويشغل migrations تلقائيًا.
4. verify-auth-schema.php ينشئ probe account وSanctum token وdevice metadata ويحاول تنظيفها خلال startup؛ هذا ليس فحصًا read-only.
5. public health/error responses تكشف معلومات runtime/source أكثر مما يلزم.
6. file cache محلي وغير مشترك بين instances.
7. بعض مسارات المصادقة موجودة لكن reset/verification غير مكتمل، وFirebase exchange غير موجود، وإدارة الأجهزة/حدودها جزئية.
8. كثير من Laravel paths التي يطلبها Flutter لا تقابلها routes في Backend الحالي.
9. Flutter default flags عند تعطيل Firebase تشمل auth+ads+sync؛ يلزم ضبط rollout/feature gates قبل تفعيل التطبيق على Backend الجديد.
10. خمس workflows production-account retry متشابهة وتكتب على production؛ يجب توحيدها على staging disposable.
11. auth-smoke يعرّف workflow_dispatch لكن شرط job يسمح push فقط.
12. لا يوجد دليل مصدر/CI على backup restore drill أو AWS OIDC deployment حتى الآن.
13. اختلاف في contract: photo_url يرسله RegisterRequest ولا يحفظه backend على مسار التسجيل؛ وDELETE user/account لا ينفذ wallet credit protection التي يتوقعها العميل.

## 7. البيانات وقاعدة الإنتاج

لم يتم الاتصال بقاعدة Neon الإنتاجية أو عرض أي user rows أو token rows أو counts أو schema فعلي. الجرد في DATABASE_AND_DATA_INVENTORY.md مبني على migrations/models/اختبارات العميل فقط. كما لم يتم التحقق من وجود بيانات Firestore تاريخية فعلية؛ وثيقة migration PRD تذكرها كتاريخ تصميم، لا كدليل على وضع البيانات الحالي.

## 8. حدود التحقق الخارجي

- لم يتم قراءة قيم secrets أو DB URLs أو APP_KEY أو OAuth secrets.
- لم يتم اختبار Google OAuth الحقيقي على Android.
- لم يتم اختبار إرسال البريد أو الإشعارات أو الدفع أو التخزين.
- لم يتم تنفيذ Docker build في هذه المراجعة؛ استُخدمت سجلات GitHub Actions.
- لم يتم إنشاء AWS account أو موارد سحابية.

هذه ليست نواقص في inventory؛ هي اختبارات تشغيل تذهب إلى مراحلها المعنية لاحقًا.

## 9. المخرجات النهائية للمرحلة 00

- BACKEND_FILE_CATALOG.md
- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- ENVIRONMENT_AND_SECRET_NAMES.md
- WORKFLOW_INVENTORY.md
- FEATURE_MATRIX.md
- reports/PHASE-00-REPORT-2026-11-21.md

**النتيجة:** Phase 00 COMPLETE على مستوى الجرد المصدري الثابت. لا يوجد تغيير في كود Backend أو إعدادات الإنتاج أو قاعدة البيانات. المرحلة التالية المسموح بها هي Phase 01 — Reproducible Laravel Project.
