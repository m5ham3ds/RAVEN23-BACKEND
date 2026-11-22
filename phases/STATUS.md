# حالة مراحل RAVEN23 Backend

**آخر تحديث:** 22 نوفمبر 2026 — Phase 00 و01 و02 مكتملات؛ Phase 03 اجتازت بوابات الكود على Trixie وما زالت IN_PROGRESS تشغيليًا  
**SHA خط الأساس:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**فرع الخطة والجرد:** docs/backend-phased-roadmap

## الحالة العامة

**Phase 00 COMPLETE — الجرد المصدري ثابت ومُوثق.** لم يتم تعديل كود Backend أو بيانات الإنتاج أو إعدادات Render/Neon، ولم يتم إنشاء موارد AWS.

## جدول تقدم المراحل

| المرحلة | الحالة | السبب / الاعتماد |
|---|---|---|
| 00 — جرد المستودع والعقود | COMPLETE | Backend tree (34 files) وFlutter client contracts وDB source inventory وenv/workflows/features وثقوا في ملفات مستقلة؛ تقرير الإغلاق موجود |
| 01 — Laravel مستقل | COMPLETE | Docker #210 SUCCESS على SHA موحد؛ SQLite 14/100 وPostgreSQL 3/72 وDocker build نجحت |
| 02 — Runtime محمول | COMPLETE | Docker Validation #249 SUCCESS على SHA `ae311d1dced43b10f96849abfbd3c0a3460dcc3d`؛ FrankenPHP/Caddy build وSQLite 20/138 وPostgreSQL 16 3/72 وno-DB readiness وlive readiness وnon-root وPHP asset blocking كلها PASS |
| 03 — الأمن والرصد | IN_PROGRESS | PR #4 مفتوح كمسودة؛ أساسه اجتاز Docker #282/Pint #20/Security #23 على `096ad1d…`. أضيف فصل DB app/migrator وحراسة TLS البعيد واختبارات تنقيح logs على `8932cb…`؛ Docker #285/Security #25/Pint #23 قيد التحقق، راجع أحدث تقرير. سياسة retention/backup موثقة وقيمها وإعداد المزود تنتظر اعتمادًا وفحصًا قبل الإنتاج |
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

## إغلاق Phase 02 — 22 نوفمبر 2026

- الفرع: `feature/phase-02-portable-runtime`
- PR #3: مفتوح كمسودة وغير مدمج.
- SHA الذي اجتاز جميع بوابات Phase 02: `ae311d1dced43b10f96849abfbd3c0a3460dcc3d`.
- Docker Validation #249: **SUCCESS**.
- Docker image + Composer/PHP lint/Artisan/routes: PASS.
- SQLite Feature suite: **20 tests / 138 assertions**.
- PostgreSQL 16 auth lifecycle: **3 tests / 72 assertions**.
- No-DB start و`/up`: PASS؛ `/ready` يعيد 503 وJSON المتفق عليه عند DB unavailable.
- PostgreSQL live readiness على قاعدة CI المهاجرة: PASS.
- One-off migration task على CI فقط: PASS.
- Non-root `raven:raven` ورفض تنفيذ PHP asset فعلي: PASS.
- تقرير الإغلاق: `phases/reports/PHASE-02-COMPLETION-REPORT-2026-11-22.md`.
- لم يتم اتصال production DB أو تغيير Render/Neon أو قراءة أسرار أو deploy أو AWS.

## تنفيذ Phase 03 — 22 نوفمبر 2026

- الاعتماديات 01 و02 مكتملة.
- فرع التنفيذ: `feature/phase-03-security-observability`، PR #4 مفتوح كمسودة وغير مدمج؛ أساسه Phase 02 SHA أعلاه.
- SHA السابق الذي اجتاز جميع البوابات: `096ad1d31033a0af75f173f537410ddf3f6cb6bf`.
- SHA الجديد قيد التحقق: `8932cb21497dfbf999d20b674ecc1bf3ddae121b`.
- صورة التشغيل: `dunglas/frankenphp:1.13.6-php8.4-trixie@sha256:bbe6057527f8dd9e270d9e2a33315f26b264ce8b0dbd19b67b0855a7899960ec` (multi-platform index digest).
- Docker Validation #282 (run 44974897172): SUCCESS، جميع 18 خطوة، بما فيها SQLite وPostgreSQL وPint وAPP_DEBUG guard وno-DB readiness وnon-root HTTP smoke وPHP-asset blocking.
- Pint Format Report #20 (run 44974897523): SUCCESS، 8 خطوات.
- Security Scans #22 (run 44974402053): SUCCESS، 12 خطوة على صورة Trixie المطابقة للـdigest؛ تقرير Trivy image سجّل 121 finding (1 CRITICAL و120 HIGH) دون FixedVersion متاح وقت الفحص، ونجحت بوابة الثغرات القابلة للإصلاح. التقرير محفوظ كـartifact باسم `raven23-security-reports-22`.
- Security Scans #23 (run 44974897193) أعيد على SHA المثبت نفسه؛ كان IN_PROGRESS عند آخر تحديث. لا تعتبر #22 بديلًا عن إكمال هذا التشغيل على الـSHA الحالي.
- تفاصيل الإصلاحات ومقارنة Bookworm/Trixie وحدود التحقق في `reports/PHASE-03-PROGRESS-REPORT-2026-11-22.md`.
- أضيفت سياسة التشغيل `SECURITY-OPERATIONS-POLICY.md` مع متطلبات فصل DB roles وTLS وlogging وbackup؛ retention/RPO/RTO لم تُحدد بعد.
- لا production DB changes أو secrets reads أو deploy ضمن هذه المرحلة.


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

استمر من رأس فرع Phase 03 الحالي وPR #4 دون إعادة Phase 00–02. أكمل Security #23 على SHA `096ad1d…`، ثم راجع نتائج Trivy المتبقية وسجّل خطة المعالجة/القبول. بعد ذلك عالج ما يمكن إثباته داخل CI من least-privilege لمستخدم التطبيق، وأكمل توثيق متطلبات retention والنسخ الاحتياطية. لا تغلق Phase 03 ولا تبدأ Phase 04 قبل توثيق هذه المتطلبات وحدود ما لا يمكن اختباره دون صلاحية وموافقة إنتاجية. لا تبدأ AWS أو production data migration أو نشرًا أو تغييرات على Render/Neon.
