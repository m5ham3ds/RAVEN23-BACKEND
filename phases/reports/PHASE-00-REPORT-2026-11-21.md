# تقرير تنفيذ المرحلة 00 — جرد المستودع وتثبيت خط الأساس

- **الحالة:** PARTIAL — المرحلة 00 ما زالت IN_PROGRESS
- **تاريخ البدء:** 2026-11-21
- **تاريخ التقرير:** 2026-11-21
- **الفرع:** docs/backend-phased-roadmap
- **Backend baseline SHA:** 9f29fa55afa21c0445be82d4590a4a532bb1747c
- **Android baseline SHA:** 6d12023e446db3d444716c3b407572a89cf545be
- **Pull Request:** https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/1

## 1. النتيجة التنفيذية

تمت مراجعة بنية المستودع الخاص وملفات Laravel الأساسية وroutes وmodels وmigrations وDocker scripts والاختبارات و10 GitHub Actions workflows. كما تمت مطابقة مجموعة واسعة من مصادر Flutter repositories وAPI clients وsync engines والاختبارات الاختيارية ووثائق الهجرة.

النتيجة الأساسية: Backend الحالي يحتوي نظام مصادقة حقيقيًا ومختبرًا، لكنه لا يحتوي معظم خدمات بيانات RAVEN23 التي يتوقعها تطبيق Android (المفضلة، watch history/progress، wellness، playlists، config، ads، push، billing). لذلك لا يجوز بدء إعادة الهيكلة دون إتمام خريطة العقود والـfeature gates وحسم التناقض بين الميزات التي يفعلها التطبيق افتراضيًا والمسارات غير الموجودة في الخادم.

## 2. ما تم في هذه الدفعة

- تأكيد المستودع والـbranches وbaseline SHAs.
- جرد مجلدات الجذر في Backend ومحتويات app/bootstrap/config/database/docker/routes/tests/.github/workflows.
- فحص AuthController وUser model وGoogle verifier وRaven23AuthSchema.
- فحص migrations الخمس الحالية.
- فحص Dockerfile وstart/router/DB target/schema verification/diagnostics.
- فحص routes/api.php وREADME وconfig files.
- قراءة Feature وPostgreSQL Integration tests.
- جرد 10 workflows وتحديد triggers وRender/Neon coupling والنسخ المتكررة.
- قراءة GitHub Actions results والـlogs لخط الأساس: Docker #182 وLive Smoke #127.
- تحديد failure سابق #180 وسبب PHP syntax بدقة.
- مطابقة client-side AuthRepository وLaravelApi وSyncCoordinator وrepositories (bookmarks/recent/wellness/playlists/billing/device/ads/config/announcement/push/Flix AI) ومصادر البيئة.
- تمييز OpenAPI Scraper v2 عن عقد Laravel.
- إعداد 5 ملفات inventory ومصفوفة ميزات.

## 3. الملفات/المراجع التي أُنشئت أو عُدلت

- phases/API_CONTRACT_INVENTORY.md
- phases/DATABASE_AND_DATA_INVENTORY.md
- phases/ENVIRONMENT_AND_SECRET_NAMES.md
- phases/WORKFLOW_INVENTORY.md
- phases/FEATURE_MATRIX.md
- phases/BASELINE_AUDIT.md
- phases/PHASE-00-BASELINE-INVENTORY.md
- phases/MASTER_PLAN.md
- phases/STATUS.md
- phases/README.md

## 4. أدلة CI المرصودة

- Backend Docker Validation #182 على 9f29fa55afa21c0445be82d4590a4a532bb1747c: SUCCESS — 11 Feature tests / 68 assertions، و3 PostgreSQL integration tests / 72 assertions.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418246
- Live Authentication Smoke #127 على نفس SHA: SUCCESS — فحص GET-only للـhealth والـdatabase/schema/commit/routes على Render.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418305
- Roadmap PR validation #183 على 0f650a667e981c5e5a4341d33a17dcc3ae27ee76: SUCCESS.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44945588390

هذه الأدلة تخص الاختبارات التي شُغلت فقط. لا تثبت عمل Google الحقيقي أو بريد reset أو مزامنة البيانات أو تخزين الملفات أو الدفع.

## 5. المخاطر والنتائج المهمة

1. مشروع Laravel غير مستقل: لا composer.json/lock في جذر repo، وDocker ينشئ skeleton جديدًا أثناء كل build.
2. runtime يستخدم PHP built-in server بدل Nginx/PHP-FPM أو FrankenPHP.
3. startup مرتبط بـ Render/Neon ويفرض DB_URL وDB_MIGRATION_URL.
4. migrations تشغّل تلقائيًا عند startup.
5. verify-auth-schema.php ينشئ probe user/token/device metadata ويزيله عند كل startup؛ هذا ليس فحصًا read-only.
6. health/error responses تكشف runtime host/schema/commit/instance وبعض تفاصيل source line.
7. cache مخزن file محلي، وليس مشتركًا بين instances.
8. Auth موجود ومختبر، لكن Firebase session exchange غير موجود، password reset/email verification غير مكتمل، وdevice listing/limit enforcement غير مكتمل.
9. توجد فجوة كبيرة بين Flutter client API calls وبين Backend routes؛ التفاصيل في API_CONTRACT_INVENTORY وFEATURE_MATRIX.
10. Flutter default flags عند تعطيل Firebase تسمح auth+ads+sync، رغم غياب routes ads/sync في الخادم.
11. 5 workflows account retry مكررة تنفذ إنشاء/حذف حسابات اصطناعية على production Render؛ يجب نقل اختبارات الكتابة إلى staging disposable قبل توحيد CI.
12. لا يوجد دليل في المصدر أو سجل Actions تمت مراجعته على backup/restore rehearsal أو AWS OIDC deployment.
13. توجد وثيقة تاريخية عن Firestore data migration، لكن لم يتم الوصول إلى Firebase/Firestore أو إثبات وضع البيانات التاريخية.

## 6. ما لم يُنفذ عمدًا

- لم يتم الاتصال بقاعدة Neon/RDS الإنتاجية.
- لم تتم قراءة أي user rows أو عدد حسابات أو tokens أو بيانات مشاهدة.
- لم تتم قراءة قيم GitHub Secrets أو DB URLs أو APP_KEY أو OAuth secrets.
- لم يتم تشغيل أي workflow يكتب على الإنتاج.
- لم يتم اختبار Google OAuth على جهاز Android أو إرسال بريد.
- لم يتم تعديل كود Backend/Flutter أو migrations أو إعدادات Render/Neon.
- لم يتم إنشاء AWS account أو موارد أو تكاليف سحابية.

## 7. معايير القبول

| المعيار | الحالة | الدليل/السبب |
|---|---|---|
| تأكيد repo/branch/SHA | PASS | metadata وGitHub Actions |
| جرد مجلدات Backend الأساسية | PASS مبدئيًا | root + nested directories التي تم فحصها |
| مصفوفة API Backend مقابل Flutter | PARTIAL | المسارات الرئيسية والمستودعات الرئيسية جرى ربطها؛ يبقى dynamic call-site sweep كامل |
| جرد DB من migrations/models | PARTIAL | الموجود في source موثق؛ schema الفعلي الإنتاجي غير مقروء عمدًا |
| جرد أسماء environment/secrets | PARTIAL | الأسماء المستخرجة من الملفات المعروفة، لا قيمها؛ يستكمل بمراجعة workflows المتبقية |
| جرد workflows | PASS مبدئيًا | 10 ملفات workflows وفحص triggers والنتائج المتاحة |
| تصنيف الميزات | PARTIAL | المجالات الرئيسية معروفة، يلزم التحقق من كل consumer/gate |
| CI baseline evidence | PASS | runs #182/#127 |
| backup/restore evidence | NOT TESTED | لا اتصال بإنتاج ولا restore |

## 8. المعوقات والاعتماديات

- WAITING_EXTERNAL: لا يلزم إنشاء AWS account الآن؛ هذا تابع للمرحلة 11.
- WAITING_DEPENDENCY: لا تبدأ Phase 01 قبل استكمال inventory sweep.
- USER DECISION: لا يوجد قرار مطلوب الآن؛ يمكن استكمال الجرد دون تغيير إنتاج.

## 9. الخطوات المتبقية لإغلاق المرحلة 00

1. sweep إضافي لكل dynamic route/reference في Flutter services/controllers/presentation، وتحديد feature flag ووقت تشغيله.
2. توثيق schemas الكاملة (request/response) لكل route متوقع من app.
3. مراجعة بقية files/tests/workflows ذات الصلة وربطها بالـAPI.
4. فصل الوثائق التاريخية/القديمة عن implementation الحالي وتحديد ما الذي لا يزال مفعلًا.
5. عند توفر staging فقط، يمكن تنفيذ non-production contract tests؛ لا تنتظر هذه المرحلة اتصالًا ببيانات الإنتاج.
6. تحديث report إلى COMPLETE فقط بعد إغلاق كل البنود وإرفاق evidence.

## 10. قرار المرحلة

**PARTIAL / IN_PROGRESS.** المخرجات الحالية ذات فائدة مستقلة وتكشف gap رئيسيًا في نطاق Backend، لكنها لا تغلق Phase 00 بالكامل. المرحلة التالية غير مسموح بها بعد.

## 11. الاستمرارية

ابدأ من هذا التقرير ومن الملفات الخمسة inventory، ثم تابع dynamic route sweep للـFlutter. لا تعد فحص AuthController أو migrations الخمس أو نتائج CI #182/#127 ما لم يظهر commit أحدث أو دليل جديد يغير النتيجة.
