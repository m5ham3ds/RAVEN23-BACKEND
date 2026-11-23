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

### ما تبقى لإغلاق Phase07 بعد الاختبارات الأخيرة

اجتازت الاختبارات التالية على main، ضمن Docker/SQLite وPostgreSQL integration:

- جهازان يرسلان جلسات out-of-order ويصلان إلى daily totals متطابقة.
- tombstone يفوز على النسخة القديمة، وإعادة الرفع المطابقة لا تكرر الكتابة.
- فقد استجابة HTTP ثم replay مطابق لا يزيد server revision ولا ينشئ change/receipt إضافياً؛ الاختبار موجود في SQLite وPostgreSQL.
- revision محلي متقدم يرفض pull incremental غير صالح، ثم يسمح full resync من revision صفر.
- إبطال token جهاز واحد لا يمنع جهازاً ثانياً للحساب نفسه من المزامنة.
- cursor snapshot ثابت يستبعد التغييرات التي تمت بعد الصفحة الأولى.
- جلسة تعبر منتصف الليل توزع المدة على اليومين وتضع completed count في يوم النهاية.

تبقى متطلبات الإغلاق التالية:

1. اختبار تقارب ثلاثة أجهزة مع كتابات متزامنة ومتعارضة، لا مجرد وصول out-of-order في اختبار جهازين.
2. pagination مع كتابة متزامنة تحت PostgreSQL وفقدان/تلف cursor فعلي ثم إعادة بناء كل بيانات العميل.
3. device-ID rotation/account switch عبر دورة عميل وخادم كاملة، مع إثبات عدم إرسال الطلبات القديمة بعد تبديل الحساب.
4. توثيق retry/backoff الخاص بمزامنة العميل وقياس sync latency/volume/conflict rate تحت حمل اصطناعي.

**القرار:** Phase07 تبقى IN_PROGRESS؛ البنود التي اجتازت اختبارات backend لا تُعاد كتابتها كأنها ما زالت غير منفذة، لكن لا تُغلق المرحلة قبل قبول العناصر الأربعة المتبقية.


## تحديث CI — SHA `dd861fa9afd6f876ff300f65aae58a35b16f3112`

اجتازت مجموعة CI الكاملة بعد إضافة queue/mail slice في Phase08، وشغّلت جميع اختبارات Phase07 على نفس الشجرة:

- Docker [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525): SUCCESS — 92 Feature / 1,046 assertions؛ 12 PostgreSQL Integration / 265 assertions.
- Authentication Regression [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572): SUCCESS.
- Pint [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578): SUCCESS.
- Security [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417): SUCCESS.

الـCI الكلي يشمل الاختبارات السابقة والجديدة؛ لا تُنسب جميع assertions إلى Wellness وحده.
