# المرحلة 09 — الاشتراكات والصلاحيات والميزات المدفوعة

**الحالة:** IN_PROGRESS — بدأت 09A (جرد عقد التطبيق وتنفيذ قراءة الاستحقاق الموثوقة من الخادم). لا تزال الخطط/الأسعار/المزايا التجارية ومزود الدفع WAITING_EXTERNAL.  
**الاعتماديات:** 03 و05 COMPLETE على مستوى الكود والاختبارات؛ Phase04 ما تزال PARTIAL بسبب Google Android والبريد الحقيقي في staging. يسمح ذلك بجرد العقد وتنفيذ أساس entitlement read-only، ولا يثبت جاهزية المصادقة/الدفع للإنتاج.

## الهدف

بناء نموذج موثوق لحالة الاشتراك وصلاحيات الحساب دون افتراض مزود دفع أو طريقة دفع أو سعر غير معتمد. الخادم هو المصدر الوحيد الموثوق لمنح المزايا والتحقق من صلاحيتها.

## تسلسل التنفيذ الحالي

### 09A — تدقيق عقد Flutter وقراءة الاستحقاق من الخادم

- تمت مراجعة:
  - تطبيق Flutter: `lib/data/repositories/billing_repository.dart`.
  - نموذج التطبيق: `lib/data/models/billing.dart`.
  - إدارة حالة Plus: `lib/presentation/billing/plus_controller.dart`.
  - اختبارات العقد: `test/data/billing_repository_test.dart`.
  - Backend: `routes/api.php` و`app/Models/User.php` وشجرة models/controllers/migrations/tests.
- يتوقع العميل `GET /api/v1/user/entitlements` مع `success`, `entitlements.ad_free`, `starts_at`, `expires_at`, `revision`, `server_time` و`refresh_after_seconds`.
- أضيفت migration وجدول `user_entitlements` بحالة حالية واحدة لكل (`user_id`, `code`) مع بداية/نهاية/إلغاء ومراجعة ومصدر مرجعي.
- أضيف Model وعلاقة owner-scoped وقراءة GET محمية بـSanctum؛ لا يقبل endpoint أي user ID ولا يوفر grant/revoke من العميل.
- يمنح endpoint `ad_free=true` فقط إذا كان السجل مملوكًا للحساب المصادق، وبدأ مفعوله، ولم ينتهِ ولم يُلغَ. غياب السجل أو المستقبل/المنتهي/الملغى يعني رفضًا افتراضيًا.
- لا توجد كتابة استحقاق عبر API عامة. الصف يُكتب لاحقًا فقط من خدمات مجال موثوقة (اشتراك/قسيمة/إجراء إداري معتمد) بعد بناء تلك المسارات.
- اختبارات Feature وPostgreSQL تغطي unauthenticated denial، no-grant default deny، guest denial، owner isolation، future/expired/revoked windows.

### ما لم ينفذ في 09A

- لم ينشأ plan catalog أو تسعير أو trial أو renewal terms.
- لم تنشأ payment provider interface أو webhook routes أو مفاتيح توقيع.
- لا توجد wallet/topup/coin/coupon implementation في Backend حتى الآن.
- لا يوجد grant/revoke administrative API أو سجل أحداث append-only بعد؛ جدول `user_entitlements` يمثل الحالة الحالية فقط، ولا يُدّعى أنه audit ledger.
- لم يتغير تطبيق Flutter أو عقده.

## العمل المتبقي في Phase09

1. اعتماد قائمة الخطط والمزايا والحدود والفترة والعملة والسعر وسياسات التجديد/التجربة/الإلغاء/الاسترداد.
2. بناء plan/entitlement catalog مستقلين عن طريقة الدفع بعد اعتماد القيم.
3. إضافة append-only subscription/billing event ledger وقواعد ownership ومصدر الحقيقة.
4. تعريف PaymentProvider interface بعد اختيار مزود دفع واعتماد الأهلية التشغيلية والقانونية.
5. تنفيذ تحقق webhook signature وtimestamp/replay defense وidempotency وترتيب الأحداث.
6. ربط الدفع الموثق بالاستحقاقات دون السماح للعميل بمنح نفسه صلاحية.
7. حل تعارض حذف الحساب مع وجود رصيد محفظة؛ عقد Flutter يتوقع `409 wallet_credit_remaining` بينما Backend الحالي لا يحسب رصيد محفظة.
8. تحديد grant/revoke اليدوي وصلاحية الإدارة وسجل التدقيق إن كان مطلوبًا.
9. تحديد حدود الأجهزة/الاستخدام بعد قرار المنتج، لا بالافتراض.

## معايير القبول النهائية

- قواعد الخطط والمزايا معتمدة ومكتوبة.
- لا يستطيع العميل ترقية نفسه بتغيير request body.
- جميع القراءة تستند إلى مالك Sanctum المصادق، والتواريخ محسوبة بوقت الخادم UTC.
- معالجة webhooks موقعة ومتحقق منها ومقاومة replay وقابلة لإعادة المحاولة.
- كل تغيير في الاشتراك/الاستحقاق قابل للتدقيق.
- اختبارات الانتهاء والإلغاء والاسترداد والتكرار والتعارض تمر.
- اختبار staging حقيقي للمزود بعد الموافقة، دون الخلط بين mock/CI والتسليم الحقيقي.

## ممنوعات

لا اختيار دفع ببطاقات/محافظ/عملات رقمية، ولا أسعار أو بوابة دفع، ولا تفعيل مشتريات أو فوترة فعلية قبل موافقة المستخدم والتحقق من الأهلية القانونية/التشغيلية من المصادر الحالية. لا تعتبر بيانات Flutter أو أسماء NOWPayments في واجهة العميل اختيارًا معتمدًا لمزود الدفع.
