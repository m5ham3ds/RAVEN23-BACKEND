# تقرير متابعة Phase08 — 24 نوفمبر 2026

**المستودع:** m5ham3ds/RAVEN23-BACKEND  
**الفرع:** main  
**آخر SHA برمجي اجتاز CI الكامل:** df390286cbe2170d4b18a96dad06835379091019

## 08F — Push subscription registration

تمت مطابقة عقد Flutter الحالي مع Backend:

- POST /api/v1/devices/register لرموز FCM.
- POST /api/v1/push/subscriptions لمعرف OneSignal subscription UUID.
- DELETE /api/v1/push/subscriptions/{subscriptionId} لإلغاء OneSignal subscription.

أضيف جدول user_push_subscriptions بمفتاح مالك FK cascade، provider، token hash وtoken مشفر وplatform/app_version/last_seen_at. التخزين يستخدم Laravel encrypted cast، بينما token_hash هو HMAC-SHA-256 scoped بالـprovider ومفتاح التطبيق. إعادة التسجيل idempotent، والتسجيل من حساب آخر ينقل association إلى الحساب المصادق الحالي. حذف OneSignal owner-scoped.

## الاختبارات

- SQLite Feature tests: encrypted storage، validation، idempotency، account ownership transfer، owner-scoped unregister وaccount-delete cascade.
- PostgreSQL Integration test: FCM وOneSignal مع تشفير الرموز وإعادة التسجيل والتبديل بين المالكين والإلغاء ضمن النطاق الصحيح.
- Flutter API contract تمت مطابقته مع DeviceRepository الحالي؛ لم يتطلب تغيير صيغة الطلب.

## CI evidence

على SHA df390286cbe2170d4b18a96dad06835379091019:

- Docker [45320144852](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144852): SUCCESS؛ SQLite 118/1,332؛ PostgreSQL 21/394، مع اختبار واحد skipped؛ S3Mock منفصل 1/8.
- Authentication [45320144671](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144671): SUCCESS.
- Pint [45320144645](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144645): SUCCESS.
- Security [45320144702](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144702): SUCCESS.

## غير المنفذ بعد

- لا يوجد FCM/OneSignal sender adapter ولا actual notification delivery.
- لم تستخدم provider credentials أو external tokens؛ test values اصطناعية.
- S3 الحقيقي وmail provider/staging وnative idempotency وسياسات retention/cost لا تزال مفتوحة.

**الحالة: Phase08 IN_PROGRESS.** لا تعني subscription registry جاهزية Push للإنتاج.

لم يتم الوصول إلى قاعدة إنتاج أو قراءة أسرار أو تنفيذ deploy أو إنشاء موارد مدفوعة.