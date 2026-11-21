# تقرير خط الأساس — RAVEN23-BACKEND

## معلومات المراجعة

- المستودع: m5ham3ds/RAVEN23-BACKEND (خاص).
- الفرع: main.
- Commit Backend: 9f29fa55afa21c0445be82d4590a4a532bb1747c.
- مستودع Android: m5ham3ds/RAVEN23، commit 6d12023e446db3d444716c3b407572a89cf545be.
- التاريخ: 21 نوفمبر 2026.
- نوع المراجعة: قراءة ملفات وsource contracts وسجل CI فقط؛ لم يتم تعديل بيانات الإنتاج أو الملفات.
- الحالة: **Phase 00 PARTIAL / IN_PROGRESS.**

## 1. مكونات المستودع

تم فحص مجلدات الجذر والملفات ذات الصلة:
- .github/workflows/
- app/Http/Controllers/Api/
- app/Models/
- app/Support/
- bootstrap/
- config/
- database/migrations/
- docker/
- routes/
- tests/Feature/
- tests/Integration/
- Dockerfile وREADME.md و.dockerignore.

المجلدات والملفات الأساسية التي ظهرت في الجرد مفصلة في API_CONTRACT_INVENTORY.md وWORKFLOW_INVENTORY.md.

## 2. ملاحظة هيكل Laravel

لم يظهر composer.json أو composer.lock أو artisan أو Laravel full skeleton في جذر المستودع. يقوم Dockerfile بإنشاء Laravel 13 جديد أثناء البناء، ويثبت Sanctum وgoogle/apiclient ثم ينسخ ملفات RAVEN23 المحددة إليه. هذه طريقة أثبتت نجاحها في CI لكنها لا تجعل المصدر مشروع Laravel مستقلًا بالكامل، ولا تثبت كل الاعتماديات عبر lock file.

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

مستودع RAVEN23 لديه LaravelApi وAuthRepository وrepositories للمفضلة والسجل وwellness والقوائم والاشتراكات والأجهزة والإعلانات والإعدادات والرسائل وFlix AI. كثير من هذه الطبقات يتوقع routes غير موجودة في Backend الحالي. راجع:
- API_CONTRACT_INVENTORY.md
- FEATURE_MATRIX.md

الملاحظة الحرجة: MigrationFlags الافتراضي عند تعطيل Firebase وLOCAL_DEV_MODE يعطي auth,ads,sync، ما يعني أن ads/sync يمكن تشغيلهما في build عادي رغم غياب routes الخادم المقابلة. يجب حسم سلوك flags قبل الاعتماد على Backend الجديد.

openapi.json في تطبيق RAVEN23 هو وصف Scraper API v2 ولا يمثل عقد Laravel.

## 6. مشكلات ومخاطر معمارية وتشغيلية

1. غياب Composer manifests في الجذر واعتماد Docker على create-project أثناء البناء.
2. خادم HTTP: docker/start.sh يستخدم PHP built-in server. يجب استبداله ببيئة إنتاجية مناسبة بعد مقارنة Nginx + PHP-FPM وFrankenPHP.
3. الارتباط بالمزود: README وstartup scripts وفحوص DB وworkflows تفترض Render وNeon pooled/direct URLs.
4. migrations تعمل تلقائيًا عند startup باستخدام --force؛ يلزم فصل migration release job في إعادة التصميم.
5. docker/verify-auth-schema.php ينشئ probe account وSanctum token وdevice metadata ويحاول تنظيفها خلال startup؛ هذا ليس فحصًا read-only.
6. health response تعرض host/database/schema/commit/instance، كما تعرض بعض أخطاء API اسم الملف والسطر. يجب حصر التشخيص الداخلي في السجلات المحمية.
7. CACHE_STORE=file مفروض في الإنتاج؛ مخزن محلي غير مشترك بين instances.
8. Auth: Firebase session exchange غير موجود، password reset/email verification غير مكتمل، device listing/limit enforcement غير مكتمل.
9. Backend لا يحتوي حاليًا migrations/routes واضحة لمفضلة المستخدم، watch history/progress، wellness، playlists، ads/config/notifications/devices/billing/wallet/Flix AI.
10. خمس workflows account retry متكررة تستخدم كتابة وحذف حسابات اصطناعية على production Render؛ ينبغي نقل هذه الاختبارات إلى staging disposable.
11. auth-smoke يعرّف workflow_dispatch لكن شرط job يسمح push فقط.
12. لا يوجد دليل في source أو سجل Actions تمت مراجعته على backup/restore rehearsal أو AWS OIDC deployment.

## 7. البيانات وقاعدة الإنتاج

لم يتم الاتصال بقاعدة Neon الإنتاجية أو عرض أي user rows أو token rows أو counts أو schema فعلي. الجرد في DATABASE_AND_DATA_INVENTORY.md مبني على migrations/models/اختبارات العميل فقط. كما لم يتم التحقق من وجود بيانات Firestore تاريخية فعلية؛ وثيقة migration PRD تذكرها كتاريخ تصميم، لا كدليل على وضع البيانات الحالي.

## 8. ما لم يتم إثباته

- لم يتم فحص كل ملف Flutter سطرًا بسطر؛ dynamic routes sweep لا يزال مطلوبًا.
- لم يتم توثيق جميع request/response fields للمسارات الديناميكية.
- لم يتم قراءة قيم secrets أو DB URLs أو APP_KEY أو OAuth secrets.
- لم يتم اختبار Google OAuth الحقيقي على Android.
- لم يتم اختبار إرسال البريد أو الإشعارات أو الدفع أو التخزين.
- لم يتم تنفيذ Docker build من هذه الجلسة؛ تم الاعتماد على سجل GitHub Actions.
- لم يتم إنشاء AWS account أو موارد سحابية.

## 9. المخرجات الحالية للمرحلة 00

- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- ENVIRONMENT_AND_SECRET_NAMES.md
- WORKFLOW_INVENTORY.md
- FEATURE_MATRIX.md
- reports/PHASE-00-REPORT-2026-11-21.md

التقرير PARTIAL؛ لا تنتقل إلى Phase 01 حتى إكمال dynamic route sweep ومطابقة schemas التفصيلية وإغلاق بقية معايير القبول. لم تتغير ملفات تطبيق أو إعدادات إنتاج أو بيانات في هذه المراجعة.
