# المرحلة 07 — المزامنة بين الأجهزة والعمل عند انقطاع الاتصال

**الحالة:** IN_PROGRESS — أضيف Wellness API واجتاز جميع بوابات CI؛ بقية قبول multi-device/offline ما زالت مفتوحة.  
**الاعتماديات:** المرحلتان 05 و06 COMPLETE

## الهدف
جعل بيانات المكتبة وسجل المشاهدة والتقدم متزامنة بين أجهزة المستخدم، مع تحمل انقطاع الشبكة وتكرار الطلبات وتعارض التعديلات.

## العمل
1. تعريف sync cursor/version لكل مجموعة بيانات أو سجل.
2. دعم incremental pull بدل إعادة تنزيل كامل البيانات في كل مزامنة.
3. دعم push batches مع idempotency key ومفاتيح deduplication.
4. تعريف قواعد conflict لكل نوع: favorites، ترتيب القوائم، history، progress، preferences.
5. استخدام timestamps/versions موثوقة من الخادم مع التعامل مع ساعة الجهاز غير الدقيقة.
6. التعامل مع deletion tombstones حتى لا تعود السجلات المحذوفة من جهاز قديم.
7. تحديد حدود batch وحجم payload وإعادة المحاولة مع exponential backoff.
8. حماية المزامنة من token revoked أو تبديل الحساب أو تبديل device ID.
9. توفير full resync آمن عند فقدان cursor أو تبديل الجهاز.
10. قياس sync latency وحجم البيانات ونتائج التعارض.

## معايير القبول
- سيناريو جهازين/ثلاثة أجهزة يثبت التقارب إلى نفس الحالة.
- انقطاع الاتصال أثناء push/pull لا يفقد البيانات ولا يكررها.
- حذف عنصر على جهاز لا يعيده جهاز آخر قديم.
- لا يمكن إعادة مزامنة بيانات حساب سابق بعد تسجيل الخروج/تغيير الحساب.
- اختبارات concurrency وidempotency وstale writes تمر على PostgreSQL.
- توثيق قواعد التعارض وسلوك الواجهة الأمامية.

## ملاحظة
لا تستخدم قاعدة "آخر كتابة تفوز" لكل المجالات بشكل أعمى؛ تقدم المشاهدة والقوائم والحذف لها قواعد مختلفة.


## التنفيذ الفعلي — 23 نوفمبر 2026

### أساس المزامنة الموجود على main

- Bookmarks: owner-scoped، عمليات idempotent، revisions.
- Recently watched/progress: LWW، revisions، tombstones، UTC milliseconds، clock-skew وupload acknowledgements.
- Playlists: operation log، payload-hash idempotency، base-version conflict، stable revision pagination.
- Preferences: revision-aware sync.

### Wellness API الجديد

- Route: `POST /api/v1/sync/wellness` خلف `auth:sanctum`.
- Migration: `2026_11_23_000013_create_user_wellness_sync_tables.php`.
- Tables: `user_wellness_sessions`, `user_wellness_daily`, `user_wellness_changes`, `user_wellness_upload_receipts`.
- Upload sessions/daily بحد 450 records combined؛ يقوم العميل بالـpull بعد إكمال جميع upload batches.
- Per-user `wellness` revision، owner-scoped UUIDs، LWW/tombstones، payload-hash receipts، immutable changes.
- Pull باستخدام `since_revision`, `limit`, HMAC-signed cursor bound to user and snapshot revision.
- Response fields: `server_revision`, `server_time_utc`, `sessions`, `daily`, `full`, `has_more`, `next_cursor`.
- Clock skew أكثر من 24 ساعة يعيد 422 `clock_skew_detected` ووقت الخادم UTC milliseconds.

### Final CI proof

## Final CI proof on `ef3db49dea53c33622a716f8f46aa1dadccd5109` — 23 November 2026

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45118862759](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45118862759) | SUCCESS — 85 Feature / 925 assertions; 10 PostgreSQL Integration / 240 assertions |
| Pint Format | [45118862746](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45118862746) | SUCCESS |
| Security Scans | [45118862763](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45118862763) | SUCCESS under configured Gitleaks/Trivy/fixable-vulnerability gate |
| Authentication Regression | [45118862792](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45118862792) | SUCCESS — 20 SQLite / 151 assertions; 4 PostgreSQL / 99 assertions |

Docker also passed strict Pint, APP_DEBUG guard, no-DB startup/liveness, restricted PostgreSQL app role, separate migration role, readiness, non-root runtime and uploaded-PHP blocking.


### الأخطاء التي تم إصلاحها

1. تعارض helper باسم `session()` مع Laravel TestCase؛ أعيدت تسميته إلى `wellnessSession()`.
2. strict Pint اكتشف تنسيقًا واحدًا في PostgreSQL test؛ طُبّق artifact وتحققنا أن المصدر يطابقه.
3. PostgreSQL كشف username اصطناعيًا يتجاوز حد 30 حرفًا؛ تم تقصير prefix إلى `well_`، ثم اجتاز الاختبار.
4. صُحح typo اسم جدول في assertion عزل المالك وعدد upload receipts المتوقع.

### ما تبقى لإغلاق Phase07

1. سيناريو تقارب جهازين/ثلاثة أجهزة مع concurrent/out-of-order updates.
2. lost HTTP response ثم retry بلا تكرار أو فقد acknowledgements.
3. logout/account switch/token revoke/device ID change دون تسرب بيانات المالك السابق.
4. cursor loss/full resync وpagination ثابتة مع concurrent writes.
5. daily-summary conflict: Flutter يستمد `updated_at_utc` لليوم من أكبر session timestamp؛ إضافة جلسة أقدم من جهاز آخر قد تغير totals دون زيادة stamp، وبالتالي قد تتخطى daily upload plan الملخص المعدل. يلزم daily monotonic revision/merge contract واختبار جهازين.
6. توثيق retry/backoff وقياس latency/volume.

**القرار:** Phase07 تبقى IN_PROGRESS. لا تعتبر اكتمال Wellness endpoint وحده إثباتًا لاكتمال تقارب الأجهزة والعمل دون اتصال.
