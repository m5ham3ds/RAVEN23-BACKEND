# حالة مراحل RAVEN23 Backend

**آخر تحديث:** 21 نوفمبر 2026  
**SHA خط الأساس:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**فرع الخطة والجرد:** docs/backend-phased-roadmap

## الحالة العامة

**Phase 00 COMPLETE — الجرد المصدري ثابت ومُوثق.** لم يتم تعديل كود Backend أو بيانات الإنتاج أو إعدادات Render/Neon، ولم يتم إنشاء موارد AWS.

## جدول تقدم المراحل

| المرحلة | الحالة | السبب / الاعتماد |
|---|---|---|
| 00 — جرد المستودع والعقود | COMPLETE | Backend tree (34 files) وFlutter client contracts وDB source inventory وenv/workflows/features وثقوا في ملفات مستقلة؛ تقرير الإغلاق موجود |
| 01 — Laravel مستقل | NOT_STARTED | جاهزة للبدء؛ تحافظ على المصادقة الحالية وتضيف Composer manifests/lock |
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

## أدلة خط الأساس

على SHA 9f29fa55afa21c0445be82d4590a4a532bb1747c:
- Backend Docker Validation #182: SUCCESS — 11 اختبار Feature / 68 assertions، و3 اختبارات PostgreSQL Integration / 72 assertions.
- Live Authentication Smoke #127: SUCCESS — فحص صحة Render واتصال PostgreSQL وجاهزية المخطط ووصول المسارات.
- Roadmap PR validation #183: SUCCESS على commit إعداد الوثائق الأولي.

هذه نتائج الاختبارات الموجودة فقط، ولا تثبت التكاملات الخارجية غير المختبرة.

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

ابدأ Phase 01 — Reproducible Laravel Project وفق مواصفته. حافظ على AuthController وUser model وSanctum وGoogle verifier وmigrations والاختبارات الحالية. لا تبدأ AWS أو production data migration في Phase 01.
