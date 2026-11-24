# تقرير دخول Phase09 وتدقيق العقود — 24 نوفمبر 2026

## الحالة

**Phase09:** IN_PROGRESS — اكتملت شريحة 09A (contract audit + server-authoritative entitlement read).  
**Backend base:** docs head `e33c358192a19a13f016aa4089d20998bb6d7e1a`; آخر كود سابق CI-verified `1d354f7e57ee3b5c03ee003f0fb2dbc1eff78ca3`.  
**Flutter source:** `d9418ea5d83658ee82a9bc04062f97a6c9452f65`.  
**Branch:** `main`; لا branch إضافي أو PR جديد.

## توجيه Push الذي نُفذ

أضيف `phases/PUSH-PROVIDER-SELECTION-DEFERRED.md` وسجل القرار D-027. لا FCM ولا OneSignal مختار. يبقى الإرسال معطلاً والعقد provider-neutral. إعادة فتح القرار ستكون بعد إكمال بقية نطاق Backend (09–13)، وقبل sender adapter/التفعيل الحقيقي. Phase08 تبقى IN_PROGRESS، لكن هذا التعليق لا يمنع المراحل المستقلة اللاحقة. لا يشمل التأجيل اختيار البريد أو التخزين.

## مصادر التدقيق

- Flutter:
  - `lib/data/repositories/billing_repository.dart`
  - `lib/data/models/billing.dart`
  - `lib/presentation/billing/plus_controller.dart`
  - `test/data/billing_repository_test.dart`
- Backend:
  - `routes/api.php`
  - `app/Models/User.php`
  - شجرة `app/Http/Controllers/Api`, `app/Models`, `database/migrations`, `tests`.

## الفجوة قبل 09A

تطبيق Flutter يستدعي بالفعل المسارات التالية: `billing/plans`, `user/entitlements`, `wallet`, `wallet/transactions`, `billing/coins`, `wallet/topups`, payment/reconcile/topup-link، wallet renewal، وcoupon redemption. لكن Backend لا يحتوي controllers/models/migrations/routes لهذه الوظائف. لذلك لا يجوز تصوير واجهة Flutter باعتبارها نظام اشتراك مكتملًا.

## ما نفذ في 09A

- إضافة `user_entitlements` مع unique (`user_id`, `code`) وحقول start/expiry/revoke/revision/source.
- إضافة `UserEntitlement` و`User::entitlements()`.
- إضافة `GET /api/v1/user/entitlements` خلف Sanctum.
- احتساب `ad_free` على الخادم بوقت UTC؛ عدم وجود grant أو كونه مستقبليًا أو منتهيًا أو ملغى يعني false.
- لا grant/revoke public API ولا user_id input ولا تسعير أو payment provider.

## الاختبارات المضافة

- Feature: unauthenticated rejection، default deny، owner isolation حتى عند تمرير user_id query، future/expired/revoked denial، guest denial.
- PostgreSQL Integration: owner-scoped active entitlement، عدم تسرب صلاحية إلى حساب آخر، expired/revoked denial.
- نتيجة CI لهذا commit: **بانتظار تشغيل GitHub Actions**، ولن تسجل PASS قبل انتهائها.

## ما لم ينفذ

- plan/entitlement catalog وقيم المنتجات والسعر والفترة والعملة.
- append-only subscription/billing event ledger.
- wallet/topup/payment/webhook/coupon/renewal flows.
- payment provider selection/signature verification.
- administrative grant/revoke surface.
- إصلاح wallet-credit account deletion contract.
- لا staging/production DB أو secrets أو external payment activity.

## مخاطر وحدود

- جدول entitlement الحالي يمثل الحالة الفعالة الحالية فقط؛ لا يغني عن سجل أحداث غير قابل للتغيير.
- Phase04 ما تزال PARTIAL بسبب Google Android وreal mail staging، لذا لا يعلن عن اكتمال التكامل الخارجي أو الجاهزية للإنتاج.
- Phase08 تظل IN_PROGRESS مع تأجيل Push ووجود S3/mail/retention/cost blockers.
- لا migrations إنتاجية أو deployment أو موارد مدفوعة نُفذت.

## الانتقال المسموح

يمكن متابعة Phase09 بشرائح provider-neutral بعد اعتماد قواعد المنتج. لا تُفعّل المدفوعات أو الخطة أو renewal أو الدفع قبل قرارات المستخدم. Phase10 تبقى لاحقة حسب MASTER_PLAN ولا تُعلن مرحلة سابقة COMPLETE من هذا التقرير.
