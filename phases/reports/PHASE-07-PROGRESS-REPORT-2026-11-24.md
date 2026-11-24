# تقرير متابعة Phase07 — 24 نوفمبر 2026

**المستودع:** m5ham3ds/RAVEN23-BACKEND  
**الفرع:** main  
**آخر SHA برمجي اجتاز CI الكامل:** df390286cbe2170d4b18a96dad06835379091019

## النطاق الذي أضيف

1. اختبار SQLite لتقارب ثلاثة أجهزة بعد سلسلة تحديثات out-of-order متعارضة: تحديث A، نسخة أقدم من B، حذف tombstone من C، إعادة رفع قديمة من A، ثم استئناف أحدث من B. تسحب الأجهزة الثلاثة نفس السجل النهائي والملخص اليومي.
2. اختبار PostgreSQL مماثل للتحقق من نفس السلوك على محرك قاعدة البيانات الفعلي المستخدم في CI.
3. اختبار PostgreSQL pagination يفتح snapshot من صفحتين، ثم يحفظ كتابة ثالثة بعد الصفحة الأولى، ويتأكد أن الصفحات التالية تحافظ على snapshot revision ولا تُدخل الكتابة الجديدة في منتصف السحب.
4. بعد اختبار cursor تالف، يتأكد الاختبار من 422 invalid_sync_cursor ثم ينفذ full sync من revision صفر ويستعيد الجلسات الثلاث والملخص اليومي.
5. اختبار Feature يثبت أن malformed cursor يمكن تجاوزه عبر بدء full sync جديد واستعادة السجل المملوك.

## أدلة CI

على SHA df390286cbe2170d4b18a96dad06835379091019:

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45320144852](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144852) | SUCCESS — 118 SQLite Feature tests / 1,332 assertions؛ 21 PostgreSQL Integration tests / 394 assertions، 1 skipped؛ S3Mock 1/8 |
| Authentication Regression | [45320144671](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144671) | SUCCESS |
| Pint Format | [45320144645](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144645) | SUCCESS |
| Security Scans | [45320144702](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144702) | SUCCESS |

## ما بقي قبل إغلاق المرحلة

- اختبارات المعاملات المتزامنة فعلياً (simultaneous PostgreSQL requests/transactions) تحت conflicting uploads؛ التغطية الجديدة interleaved commits مرتبة وليست سباقاً متوازياً.
- دورة device-ID rotation/account switch كاملة من Flutter إلى Backend، تشمل checkpoints والعمليات المتأخرة والتحقق من عدم تسرب بيانات المالك السابق.
- توثيق retry/backoff لمزامنة العميل وقياس latency/volume/conflict rate تحت حمل اصطناعي.

**الحالة: Phase07 IN_PROGRESS.** لا تعني الاختبارات الجديدة اكتمال offline sync أو production readiness.

## حدود التنفيذ

لم تُستخدم قاعدة إنتاج أو أسرار، ولم تُنفذ migration إنتاجية أو deployment أو DNS cutover أو موارد مدفوعة.

## 8. تحديث لاحق — PostgreSQL simultaneous writes وretry telemetry (24 نوفمبر 2026)

### التنفيذ

- أضيف اختبار PostgreSQL متعدد العمليات `test_postgresql_parallel_device_uploads_converge_to_the_newest_session_version` مع ثلاثة tokens منفصلة وعمليات PHP child مستقلة واتصالات DB منفصلة وحاجز انطلاق مشترك.
- النتائج المتعارضة تستخدم timestamps 3000 و1000 و5000. بعد اكتمال جميع الطلبات، أثبت الاختبار أن الصف النهائي يحمل timestamp 5000 والعنوان `Device C newest update`، وأن daily aggregate يحتوي 60,000 ms وجلسة واحدة.
- كان الفشل الأول بسبب طلب service binding `db` قبل bootstrap؛ أضيف طلب `/up` داخلي لتمهيد Laravel في كل worker، وأضيف error-file diagnostic.
- الاختبار بعد الإصلاح نجح ضمن Docker/PostgreSQL.
- Flutter `SyncRunner` يطبق 3 محاولات كحد أقصى للـtransient transport/HTTP errors فقط، backoff أُسّي 1/2/4s بحد 8s، Retry-After محدود لHTTP 429، وعدم retry للمصادقة/validation/conflict/malformed responses. تغيير owner/generation يوقف الطلب القديم قبل إعادة المحاولة.
- أضيفت مقاييس محلية للمدة والمحاولات والفشل المتتالي، ولم يتم إرسالها إلى backend telemetry أو قياسها تحت حمل حقيقي بعد.

### Backend CI على SHA `f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45326348001](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348001) | SUCCESS — 118 SQLite Feature / 1,332 assertions؛ 22 PostgreSQL Integration passed / 417 assertions، 1 skipped؛ S3Mock منفصل 1/8 |
| Authentication Regression | [45326348002](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348002) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99 |
| Pint Format | [45326347992](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326347992) | SUCCESS |
| Security Scans | [45326348012](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348012) | SUCCESS |

### Flutter CI

- [Run 45324044184](https://github.com/m5ham3ds/RAVEN23/actions/runs/45324044184) على app SHA `027f51a3a0fb3f0301e83eb3c71a19c045984447`: SUCCESS.
- `flutter analyze` passed؛ 487 focused Flutter tests passed؛ 25 Android ABI/offline tests passed.
- Release APK واحد ARM64 فقط بحجم 45.64 MiB. Build كان auth-focused (`RAVEN23_AUTH_ONLY=true`).

### ما بقي

- دورة device-ID rotation/account-switch كاملة تشمل checkpoints والطلبات المتأخرة بين الحسابات.
- synthetic load + request volume + latency distribution + conflict-rate reporting. المقاييس الحالية client-local only.

**Status remains IN_PROGRESS; this report does not claim full sync completion.**
