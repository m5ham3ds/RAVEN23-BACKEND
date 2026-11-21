# حالة مراحل RAVEN23 Backend

**آخر تحديث:** 21 نوفمبر 2026  
**SHA خط الأساس:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**فرع إعداد الخطة والجرد:** docs/backend-phased-roadmap

## الحالة العامة

**Phase 00: IN_PROGRESS / PARTIAL.** تم إعداد الخطة وقواعد التنفيذ، كما أُنشئت وثائق API/database/environment/workflows/feature inventory وتقرير مرحلي. لم يتم تعديل كود Backend أو بيانات الإنتاج أو إعدادات Render/Neon، ولم يتم إنشاء موارد AWS.

## جدول تقدم المراحل

| المرحلة | الحالة | السبب / الاعتماد |
|---|---|---|
| 00 — جرد المستودع والعقود | IN_PROGRESS | توجد مخرجات جرد واسعة؛ يبقى dynamic route sweep ومطابقة بعض schemas والـfeature gates قبل COMPLETE |
| 01 — Laravel مستقل | NOT_STARTED | تنتظر 00 COMPLETE |
| 02 — Runtime محمول | NOT_STARTED | تنتظر 01 |
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

## مخرجات Phase 00 حتى الآن

- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- ENVIRONMENT_AND_SECRET_NAMES.md
- WORKFLOW_INVENTORY.md
- FEATURE_MATRIX.md
- reports/PHASE-00-REPORT-2026-11-21.md — PARTIAL

## أدلة خط الأساس

على SHA 9f29fa55afa21c0445be82d4590a4a532bb1747c:
- Backend Docker Validation #182: SUCCESS — 11 اختبار Feature / 68 assertions، و3 اختبارات PostgreSQL Integration / 72 assertions.
- Live Authentication Smoke #127: SUCCESS — فحص صحة Render واتصال PostgreSQL وجاهزية المخطط ووصول المسارات.
- Roadmap validation #183: SUCCESS على commit خطة التوثيق.

هذه نتائج الاختبارات الموجودة فقط ولا تثبت اكتمال كل ميزات التطبيق أو التكاملات الحقيقية.

## نتائج Phase00 الهامة

1. يوجد نظام مصادقة حقيقي ومختبر؛ لا نعيد كتابته من الصفر.
2. Docker يبني Laravel skeleton أثناء البناء ولا يحتوي composer.json/lock في root.
3. التشغيل يستخدم PHP built-in server ويرتبط حاليًا بـ Render وNeon.
4. Backend routes الحالية تقتصر على الصحة والمصادقة والملف الشخصي؛ كثير من مسارات Flutter للمزامنة والاشتراكات والإعلانات والإعدادات والأجهزة غير موجودة في الخادم.
5. Flutter عند بعض إعدادات الإنتاج يشغل auth+ads+sync افتراضيًا؛ يجب تسوية هذا التناقض.
6. لا يوجد دليل مرئي على backup/restore rehearsal أو AWS OIDC deployment.
7. لا يتم خلال Phase00 الاتصال بقاعدة الإنتاج أو قراءة أسرارها.

## القرارات الحالية

- الحفاظ على Backend المصادقة الحالي وتطويره تدريجيًا.
- عدم البدء من الصفر.
- إنشاء مشروع Laravel مكتمل مع composer.lock في المرحلة 01.
- فصل منطق التطبيق عن Neon وRender.
- الاحتفاظ بـ PostgreSQL كعقد بيانات.
- عدم نقل الإنتاج قبل نسخة مستعادة ونجاح الاختبارات وموافقة المستخدم.
- استخدام Docker وGitHub Actions.
- استخدام OIDC عند نشر AWS.
- حماية بيانات المستخدمين وtokens وعدم اختبار الإنتاج ببيانات حقيقية.

## العمل التالي المسموح

استكمال Phase 00 فقط: dynamic route/call-site sweep لبقية Flutter services/controllers/presentation، ومطابقة schemas التفصيلية، والتأكد من كل feature gate والاختبارات المقابلة. لا تبدأ Phase 01 أو AWS أو نقل الإنتاج حتى إغلاق Phase 00.
