# المرحلة 09 — الاشتراكات والصلاحيات والميزات المدفوعة

**الحالة:** IN_PROGRESS — 09A–09F COMPLETE على مستوى التنفيذ واختبارات CI؛ القرارات التجارية والدفع WAITING_EXTERNAL. لا يعني ذلك اعتماد الخطط أو تشغيل الشراء.  
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

### 09B — نتيجة CI النهائية

- Backend SHA `a3f321ee3c4c70dad2db2b1737099c61c3d41d1e` اجتاز Docker [45429542495](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45429542495)، Authentication [45429542789](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45429542789)، Pint [45429543371](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45429543371)، Security [45429542767](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45429542767) — كلها SUCCESS.
- SQLite Feature: 134 tests / 1,425 assertions. PostgreSQL Integration: 27 passed / 510 assertions، مع اختبار S3 اختياري واحد skipped؛ S3Mock منفصل 1 passed / 8 assertions.
- PostgreSQL يثبت idempotency ورفض UPDATE/direct DELETE والسماح بحذف الحساب عبر cascade.

### 09C — ربط ledger بإسقاط الاستحقاق الحالي

- أُضيف aggregate revision وتسلسل projection status لكل event، وحقل last_event_occurred_at إلى current entitlement.
- يسجل ledger الحدث ويحدّث current projection في transaction واحدة؛ لا يمكن إظهار حدث ناجح دون انعكاسه في الحالة أو العكس.
- أحداث grant/corrected تنشئ أو تستعيد الاستحقاق؛ revoke/expired/refunded تلغي الاستحقاق مع حفظ بداية ونهاية الفترة التاريخية.
- الحدث الأقدم من آخر حدث مطبق يُحفظ كـstale ولا يستطيع إعادة current state إلى الخلف.
- replay بالمفتاح نفسه يعيد event السابق بلا زيادة revision.
- لا public API أو webhook أو payment provider أو تسعير أو شراء حقيقي.

### 09C — نتيجة CI النهائية

- Backend SHA `c3cb81c61c2711b6ad73ada262a5f230b89f9841` اجتاز Docker [45432047055](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432047055)، Authentication [45432047070](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432047070)، Pint [45432046860](https://github.com/m5ham3s/RAVEN23-BACKEND/actions/runs/45432046860)، Security [45432046759](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45432046759) — كلها SUCCESS.
- SQLite Feature: 136 tests / 1,441 assertions. PostgreSQL Integration: 28 passed / 518 assertions مع اختبار S3 اختياري واحد skipped؛ S3Mock منفصل 1 passed / 8 assertions.
- اختبارات 09C تثبت projection ذرية، aggregate revisions، وعدم تراجع الاستحقاق عند وصول حدث أقدم.

### 09D — تسجيل الاستحقاقات المنتهية

- أضيف الأمر `subscriptions:expire-due` لفحص الاستحقاقات التي بدأت وانتهى وقتها وما زالت غير ملغاة.
- الوضع الافتراضي preview-only؛ لا يكتب أي سجل. لا يحدث التعديل إلا مع `--apply`.
- كل عملية expiry تمر عبر `SubscriptionEventLedger` مع occurred_at مساويًا لوقت انتهاء الاستحقاق، ومفتاح idempotency مرتبط بمعرف سجل الاستحقاق ومراجعته.
- تستخدم الخدمة نفسها stale-event protection؛ إذا وصل renew/correction أحدث من حدث الانتهاء، يُحفظ expiry event كـstale ولا يسحب استحقاقًا أحدث.
- الحد الأعلى الافتراضي 500 وبحد أقصى 5000 لكل تشغيل؛ لا scheduler تلقائي في هذه الشريحة.

### 09D — نتيجة CI النهائية

- Backend SHA `93f626cff3a5b3c472a283285ac64b49060d0f85` اجتاز Docker [45434224117](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45434224117)، Authentication [45434224314](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45434224314)، Pint [45434224098](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45434224098)، Security [45434224005](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45434224005) — كلها SUCCESS.
- SQLite Feature: 139 tests / 1,460 assertions. PostgreSQL Integration: 29 passed / 526 assertions، مع اختبار S3 اختياري واحد skipped؛ S3Mock منفصل 1 passed / 8 assertions.
- اختبارات preview-only و`--apply` والتكرار في SQLite وPostgreSQL ناجحة. لا scheduler تلقائي.

### 09E — عقد كتالوج الخطط العام، مغلق افتراضيًا

- أضيف `GET /api/v1/billing/plans` كمسار عام متوافق مع `BillingRepository.plans()` في Flutter.
- الاستجابة الحالية: `success=true` و`purchases_enabled=false` و`plans=[]`. لا سعر أو مدة أو currency أو wallet limits أو terms أو payment provider.
- يفرض الخادم الحالة المغلقة ولا يثق في query parameters مثل `purchases_enabled=true` أو `amount_cents`.
- الغرض إكمال عقد القراءة العام ومنع التطبيق من افتراض شراء مفتوح. لا يمثل هذا اعتمادًا تجاريًا أو تشغيلًا للفوترة.
- فتح الشراء وإنشاء plan entries يتطلب اعتماد صريح لقائمة المزايا والحدود والأسعار والفترة والعملة والشروط قبل التنفيذ.

### 09F — محفظة القراءة فقط ودفتر معاملات داخلي

- أضيفت user_wallets وwallet_transactions: محفظة واحدة لكل مستخدم، USD cents، balance/spendable/revision/status/frozen.
- أضيفت GET /api/v1/wallet وGET /api/v1/wallet/transactions؛ القراءة مرتبطة بمالك Sanctum والسجل cursor-paginated.
- الكتابة لا تملك مسار HTTP عامًا. الائتمان/الخصم محصوران في WalletLedger داخل Backend، مع معاملات ذرية، idempotency key مربوط ببصمة المحتوى، ومنع الرصيد السالب أو الإنفاق من محفظة مجمدة/غير نشطة.
- سجل المعاملات append-only على SQLite/PostgreSQL؛ UPDATE/DELETE المباشر مرفوضان، ويحذف سجل المحفظة فقط ضمن حذف الحساب الكامل عبر cascade.
- إذا كان الرصيد موجبًا، يرفض حذف الحساب افتراضيًا بـHTTP 422 والحقل wallet_credit_remaining؛ لا يتم الحذف إلا بإرسال acknowledge_wallet_credit=true صراحة.
- لا public top-up/credit/debit API، ولا coupon redemption أو coin system أو webhook أو payment provider أو auto-renewal أو أسعار/حدود خطط.
- periods_fundable يظل صفرًا حتى اعتماد خطة Plus وسعرها.

### 09F — نتيجة CI النهائية على SHA 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060

- Docker [45450292965](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292965): SUCCESS — SQLite Feature 144/1,508؛ PostgreSQL Integration 31/541؛ S3Mock منفصل 1/8؛ runtime/least-privilege/worker/PHP asset checks PASS.
- Authentication [45450293025](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293025): SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Pint [45450293004](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293004): SUCCESS.
- Security [45450292826](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292826): SUCCESS — Gitleaks وTrivy وfixable HIGH/CRITICAL gate PASS.
- فشل fixture وسيطًا لعدم إنشاء user_wallets؛ أُصلح في 7341392. ثم أُصلح تنسيق Pint في 009d2f1 واجتازت البوابات الأربع.

### الاعتماديات الخارجية التي بقيت مفتوحة

- لم تُعتمد بعد أسماء الخطط ومزاياها وأسعارها وفتراتها وعملتها وشروط التجديد/التجربة/الإلغاء/الاسترداد.
- لم يُختَر مزود دفع أو تُبنَ payment adapter أو webhook routes أو مفاتيح توقيع.
- عقود wallet/topup/coin/coupon/renewal لا تزال client-only وغير مفعلة في Backend.
- لا توجد واجهة grant/revoke إدارية؛ الكتابة الحالية مخصصة لخدمات المجال الداخلية فقط.
- لم يتغير تطبيق Flutter أو عقده؛ 09E يضيف فقط route مطابقة للعقد الموجود.

## العمل المتبقي في Phase09

1. 09A–09F مكتملة برمجيًا ومتحققة عبر CI؛ تبقى القرارات التجارية الخارجية فقط.
2. اعتماد قائمة الخطط والمزايا والحدود والفترة والعملة والسعر وشروط التجديد/التجربة/الإلغاء/الاسترداد قبل إدراج أي plan entries.
3. بناء catalog الفعلي منفصلًا عن مزود الدفع بعد اعتماد القيم التجارية.
4. اختيار PaymentProvider بعد موافقة مستقلة والتحقق من الأهلية القانونية والتشغيلية الحالية.
5. تنفيذ webhooks موقعة مع timestamp/replay defense وidempotency وترتيب الأحداث.
6. ربط الدفع الموثق بالاستحقاقات دون السماح للعميل بمنح نفسه صلاحية.
7. تأكيد أن شاشة Flutter ترسل acknowledge_wallet_credit=true بعد إقرار المستخدم بفقدان الرصيد؛ حماية Backend واختبارها موجودان.
8. تحديد grant/revoke اليدوي وصلاحية الإدارة وسجل التدقيق إن لزم.
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

## تحديث قرار الخطط ومزود الدفع — 27 نوفمبر 2026

**الحالة:** 09A–09F تبقى COMPLETE على مستوى التنفيذ وCI؛ Phase09 الكلية تبقى IN_PROGRESS. اختيار PaymentProvider واعتماد الخطط/الأسعار/العملة/الشروط التجارية مؤجل إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل (D-051)، وفق D-050.

- لا تختَر أي PaymentProvider ولا تضف SDK أو webhook credentials أو checkout/payment adapter أو حساب تاجر الآن.
- لا تعتمد أسعارًا أو عملات أو مددًا أو حدود أجهزة أو شروط تجربة/تجديد/إلغاء/استرداد بالافتراض.
- يبقى endpoint `GET /api/v1/billing/plans` مغلقًا: `purchases_enabled=false` و`plans=[]`؛ لا مشتريات أو فوترة حقيقية.
- استمر في الاختبارات والعقود المحايدة ومراجعة التوافق والأمان التي لا تتطلب مزودًا أو قيمة تجارية غير معتمدة.
- إعادة فتح قرارات المنتج والدفع تتم في مراجعة موحدة بعد إنجاز أعمال Backend القابلة للتطوير دون مزود، ولا تعني موافقة تلقائية على تفعيل الشراء.
