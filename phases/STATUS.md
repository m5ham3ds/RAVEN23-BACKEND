# حالة مراحل RAVEN23 Backend

**آخر تحديث:** 22 نوفمبر 2026 — Phase 01 مغلقة وPhase 02 قيد الإصلاح والتحقق  
**SHA خط الأساس:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**فرع الخطة والجرد:** docs/backend-phased-roadmap

## الحالة العامة

**Phase 00 COMPLETE — الجرد المصدري ثابت ومُوثق.** لم يتم تعديل كود Backend أو بيانات الإنتاج أو إعدادات Render/Neon، ولم يتم إنشاء موارد AWS.

## جدول تقدم المراحل

| المرحلة | الحالة | السبب / الاعتماد |
|---|---|---|
| 00 — جرد المستودع والعقود | COMPLETE | Backend tree (34 files) وFlutter client contracts وDB source inventory وenv/workflows/features وثقوا في ملفات مستقلة؛ تقرير الإغلاق موجود |
| 01 — Laravel مستقل | COMPLETE | Docker #210 SUCCESS على SHA موحد؛ SQLite 14/100 وPostgreSQL 3/72 وDocker build نجحت |
| 02 — Runtime محمول | IN_PROGRESS | PR #3؛ FrankenPHP/Caddy وliveness/readiness وnon-root runtime مضافة. #220/#227 كشفا مسار Caddy قديمًا وأُصلح. #229/#234/#236 بنوا الصورة واجتازوا SQLite، لكن no-DB `/ready` أعاد 500 بدل 503. استبدلنا logging facade بـPHP native `error_log` الآمن؛ #240 يختبر التعديل و#241 ينتظر على أحدث SHA `358fa251` مع JSON readiness assertion |
| 03 — الأمن والرصد | NOT_STARTED | تنتظر 01 و02 |
| 04 — إكمال المصادقة | NOT_STARTED | تنتظر 01–03 |
| 05 — أساس بيانات المستخدم | NOT_STARTED | تنتظر 00–04 |
| 06 — المكتبة وسجل المشاهدة | NOT_STARTED | تنتظر 05 وعقد Android |
| 07 — المزامنة | NOT_STARTED | تنتظر 05 و06 |
| 08 — التخزين والإشعارات والمهام | NOT_STARTED | تنتظر 02–05 وقرار مزود الخدمات |
| 09 — الاشتراكات والصلاحيات | WAITING_EXTERNAL | تنتظر قواعد المنتج وموافقة اختيار مزود/طريقة الدفع |
| 10 — الجودة والإصدارات | NOT_STARTED | بوابة الجودة للمراحل 01–09 |
| 11 — AWS والنشر | WAITING_EXTERNAL | ينتظر حساب AWS والمنطقة والميزانية وموافقة التكلفة |
| 12 — ترحيل البيانات | WAITING_DEPENDENCY | ينتظر 04–11 ونجاح backup/restore وموافقة cutover |
| 13 — التشغيل والتعافي | WAITING_DEPENDENCY | ينتظر 12 |

## أدلة خط الأساس

على SHA 9f29fa55afa21c0445be82d4590a4a532bb1747c:
- Backend Docker Validation #182: SUCCESS — 11 اختبار Feature / 68 assertions، و3 اختبارات PostgreSQL Integration / 72 assertions.
- Live Authentication Smoke #127: SUCCESS — فحص صحة Render واتصال PostgreSQL وجاهزية المخطط ووصول المسارات.
- Roadmap PR validation #183: SUCCESS على commit إعداد الوثائق الأولي.

هذه نتائج الاختبارات الموجودة فقط، ولا تثبت التكاملات الخارجية غير المختبرة.

## إغلاق Phase 01 — 22 نوفمبر 2026

- فرع التنفيذ: `feature/phase-01-laravel-project`
- PR #2: مفتوح كمسودة، غير مدمج.
- SHA الذي اجتاز جميع البوابات: `519c3f1039f05818b413d466fbc31793ee6daeb6`.
- Docker Validation #210: SUCCESS.
- Docker image build وPHP lint وArtisan وroute registration: PASS.
- SQLite: **14 tests / 100 assertions**، بما فيها regression test يحفظ rows القديمة في users/cache/jobs.
- PostgreSQL 16: **3 integration tests / 72 assertions**.
- Composer Lock Bootstrap #18: dependency resolution و`composer validate --strict` وclean locked install: PASS.
- لم يتم الاتصال بقاعدة الإنتاج أو تنفيذ migrations عليها أو قراءة أسرار أو نشر التطبيق.
- تقرير الإغلاق: `phases/reports/PHASE-01-COMPLETION-REPORT-2026-11-22.md`.

## تقدم Phase 02 — 22 نوفمبر 2026

- بدأت بعد اجتياز جميع معايير Phase 01.
- النطاق: استبدال PHP built-in server ببيئة HTTP إنتاجية محمولة، إزالة اعتماد startup على Render/Neon، وفصل migration task عن تشغيل web container.
- الخيار قيد التنفيذ: FrankenPHP/Caddy على PHP 8.4؛ القرار النهائي يتطلب نجاح Docker وHTTP/health/readiness/non-root checks.
- فرع التنفيذ: `feature/phase-02-portable-runtime`، وPR منفصل يستهدف فرع Phase 01 المكتمل.
- CI #229/#234/#236/#240: Docker build + PHP lint + SQLite suite + PostgreSQL test image build PASS؛ الفشل الوحيد كان no-DB readiness HTTP 500. السبب الجذري: `/ready` داخل `routes/web.php` يمر عبر `StartSession` و`ShareErrorsFromSession`; في الإنتاج يستخدم `SESSION_DRIVER=database` فيفشل قبل controller عند تعطل PostgreSQL. الإصلاح: استثناء middleware الجلسات عن `/ready` فقط، مع اختبار `SESSION_DRIVER=database` و`session.connection=pgsql` واتصال مرفوض. CI #244 يختبر middleware exclusion، و#245 pending على SHA `9968e749bce03be0c52705ac57784ae58de3d8a7` ويضيف JSON contract assertion. كما أصبح catch يستخدم native `error_log` آمنًا بدل logging facade.
- لا نشر، ولا اتصالات بقاعدة الإنتاج، ولا migrations تلقائية على بيئة غير معروفة.


## مخرجات Phase 00

- BACKEND_FILE_CATALOG.md
- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- ENVIRONMENT_AND_SECRET_NAMES.md
- WORKFLOW_INVENTORY.md
- FEATURE_MATRIX.md
- reports/PHASE-00-REPORT-2026-11-21.md

## نتائج أساسية

1. نظام المصادقة موجود ومختبر؛ لا نعيد كتابته من الصفر.
2. Docker يبني Laravel skeleton أثناء البناء ولا يحتوي composer.json/lock في root.
3. التشغيل يستخدم PHP built-in server ويرتبط حاليًا بـ Render وNeon.
4. Backend routes الحالية تقتصر على الصحة والمصادقة والملف الشخصي؛ كثير من مسارات Flutter للمزامنة والاشتراكات والإعلانات والإعدادات والأجهزة غير موجودة.
5. Flutter default flags عند تعطيل Firebase تشمل auth+ads+sync؛ يجب تسوية feature gate قبل إطلاق كل backend routes.
6. لا يوجد دليل مرئي على backup/restore rehearsal أو AWS OIDC deployment.
7. لم يتم الاتصال بقاعدة الإنتاج أو قراءة أسرارها.

## العمل التالي المسموح

أكمل إصلاح Phase 02 أولًا، ولا تعتبرها مكتملة قبل نجاح Docker وSQLite وPostgreSQL وHTTP readiness/no-DB/non-root/PHP-asset checks على commit واحد. بعد ذلك فقط ابدأ Phase 03 وفق `phases/PHASE-03-SECURITY-OPERATIONS-FOUNDATION.md`. لا تبدأ AWS أو production data migration.
