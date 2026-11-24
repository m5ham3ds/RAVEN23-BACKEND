# المرحلة 09 — الاشتراكات والصلاحيات والميزات المدفوعة

**الحالة:** IN_PROGRESS — 09A COMPLETE (عقد قراءة الاستحقاق) و09B قيد التنفيذ (سجل أحداث موثوق append-only). لا تزال الخطط/الأسعار/المزايا التجارية ومزود الدفع WAITING_EXTERNAL.  
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

### 09A — نتيجة CI النهائية

- الإصلاح النهائي على Backend SHA `fd7ab5b716abe36d9d676abbd9c03c945b4611e1`.
- Docker Validation [45426235234](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45426235234) SUCCESS؛ Authentication [45426235087](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45426235087) SUCCESS؛ Pint [45426236015](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45426236015) SUCCESS؛ Security [45426235159](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45426235159) SUCCESS.
- Docker SQLite Feature suite: 129 tests / 1,412 assertions. PostgreSQL integration وS3Mock وruntime/least-privilege checks اجتازت التشغيل.
- تم إصلاح فشل test owner guard/guest status، ثم أزيل unused import الذي اكتشفه Pint. لا تغيير في قواعد الاستحقاق لتجاوز الاختبارات.

### 09B — سجل أحداث الاستحقاق غير القابل للتعديل

- إنشاء `subscription_events` لحفظ الأحداث الموثوقة: granted/revoked/expired/refunded/corrected.
- يتضمن كل حدث المالك، entitlement code، المصدر العام (internal/coupon/payment/administrative)، مرجع المصدر، idempotency key، وقت وقوع الحدث، حدود فترة الاستحقاق، reason code، وبصمة SHA-256 للمحتوى.
- قيد unique على (`source`, `idempotency_key`) لمنع تكرار الحدث. إعادة المفتاح نفسه مع المحتوى نفسه تعيد الحدث السابق؛ إعادة استخدامه لمحتوى مختلف ترفض بتعارض.
- يمنع PostgreSQL وSQLite تعديل السجل أو حذفه مباشرة. يسمح بحذف الحدث عبر cascade فقط عندما يحذف المستخدم حسابه بالكامل، بما يراعي مسار محو الحساب.
- خدمة المجال داخل Backend فقط؛ لا public API ولا client-supplied grant، ولا تُفعّل أي مصدر دفع أو مزود أو عملية مالية في 09B.
- اختبارات Feature وPostgreSQL تغطي idempotency، تضارب المفتاح، رفض UPDATE/DELETE المباشر، وحذف الحساب.

### ما لم ينفذ في 09B

- لم يتم ربط ledger بعد بإسقاط الحالة الحالية `user_entitlements`؛ هذا يحتاج transaction/projection stage منفصلة في 09C.
- لا يوجد payment provider أو webhook أو coupon/admin grant API.
- لا توجد plan catalog أو أسعار أو wallet/topup flows.

### ما لم ينفذ في 09A

- لم ينشأ plan catalog أو تسعير أو trial أو renewal terms.
- لم تنشأ payment provider interface أو webhook routes أو مفاتيح توقيع.
- لا توجد wallet/topup/coin/coupon implementation في Backend حتى الآن.
- لا يوجد grant/revoke administrative API أو سجل أحداث append-only بعد؛ جدول `user_entitlements` يمثل الحالة الحالية فقط، ولا يُدّعى أنه audit ledger.
- لم يتغير تطبيق Flutter أو عقده.

## العمل المتبقي في Phase09

1. إكمال والتحقق من 09B (ledger) ثم 09C (atomic projection من الأحداث الموثوقة إلى current entitlement).

1. اعتماد قائمة الخطط والمزايا والحدود والفترة والعملة والسعر وسياسات التجديد/التجربة/الإلغاء/الاسترداد.
2. بناء plan/entitlement catalog مستقلين عن طريقة الدفع بعد اعتماد القيم.
3. بعد 09B، استكمال قواعد projection/event ordering ومصدر الحقيقة في 09C.
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
