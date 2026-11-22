# تقرير إغلاق Phase 05 — أساس بيانات المستخدم وعقود الحساب

**التاريخ:** 22 نوفمبر 2026  
**الحالة:** COMPLETE — schema/API/code/CI  
**فرع التنفيذ السابق:** `feature/phase-05-user-data-foundation`  
**فرع الاعتماد النهائي:** `main`  
**Commit المختبر قبل الدمج:** `3c056ed983dbd7025e2f1bc65806cfcaae3af885`  
**Merge commit:** `9c8374e591c11b845dac82d625fb81ed306d939c`  
**PR:** #6 merged.

## المخرجات

- جداول user_profiles/user_preferences/user_devices بعلاقات owner FK/cascade/unique constraints.
- Migration additive/forward-only مع backfill من users وpersonal_access_tokens والتحقق من سلامة المخطط السابق.
- الحفاظ على توافق Flutter profile/photo_url.
- API تفضيلات الحساب وتصدير البيانات مع ownership؛ export لا يحتوي password/Google identity/Sanctum token/hash/recovery/verification secrets.
- اختبارات SQLite migration/idempotency/ownership/export/account deletion.
- PostgreSQL integration على دور التطبيق الأقل صلاحية.

## CI على SHA واحد

| Workflow | Run | النتيجة |
|---|---:|---|
| Docker Validation | #339 | SUCCESS |
| Pint Format | #68 | SUCCESS |
| Security Scans | #70 | SUCCESS |

- SQLite Feature: **50 passed / 369 assertions**.
- PostgreSQL 16 integration: **5 passed / 140 assertions**.
- فحوص app/migration roles وFrankenPHP non-root/readiness ورفض PHP asset وAPP_DEBUG guard نجحت.
- Gitleaks وfilesystem/container scans وبوابة الثغرات القابلة للإصلاح نجحت.

## نتائج ما بعد الدمج

على main merge SHA `9c8374e591c11b845dac82d625fb81ed306d939c`:
- Docker Validation #340 — SUCCESS، SQLite 50/369 وPostgreSQL 5/140.
- Pint Format #69 — SUCCESS.
- Security Scans #71 — SUCCESS.

## إصلاحات أسباب الفشل السابقة

1. Docker #332 على `eb150376...` كشف auth guard مخزناً أثناء تبديل حسابين في PostgreSQL owner/export test. أضيف `Auth::forgetGuards()` قبل العودة للحساب الأول؛ بعدها نجحت اختبارات PostgreSQL الخمسة في #333/#339/#340.
2. Pint #67 فشل قبل تنفيذ Pint بسبب متغير `base_ref` غير المعرف مع `set -u`. أضيف fallback صريح إلى main، ونجح Pint #68.

## الدمج والحوكمة

- PR #1 roadmap، #2 Phase01، #3 Phase02، #4 Phase03، #6 Phase05 مدمجة إلى main.
- تغييرات Phase04 الوظيفية والاختبارات موجودة في الشجرة التراكمية المدمجة عبر Phase05.
- PR #5 أُغلق دون دمج مستقل بعد مقارنة فرعه مع main؛ ملفاته الثلاثة الوحيدة المتبقية كانت workflow trigger/base-ref قديمة ومغطاة بإعداد main الأحدث.
- إغلاق PR #5 لا يحذف branch ref القديم. تنظيف refs القديمة لم يكتمل بعد.

## ما لم يتم

- لم يتم تشغيل migration على Production DB.
- لا secrets reads أو provider edits أو Render/Neon changes أو deployment أو موارد AWS.
- لا تسليم بريد حقيقي في staging.
- لا Google OAuth حقيقي على Android؛ CI يستخدم verifier fake.
- لا backup/restore production rehearsal أو اعتماد RPO/RTO.

## المرحلة التالية

Phase06 تبدأ على main وفق عقد Flutter الفعلي؛ لا تُخزن ملفات الفيديو ولا تنشئ import/public-share endpoints قبل اعتماد قراراتها.
