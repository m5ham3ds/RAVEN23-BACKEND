# تقرير مزامنة مجلد phases مع مصدر المشروع وقرار تأجيل جميع المزودين

**التاريخ:** 27 نوفمبر 2026  
**المستودع:** m5ham3ds/RAVEN23-BACKEND  
**الفرع الدائم:** main  
**آخر commit اجتاز بوابات CI الأربع قبل هذه المزامنة الشاملة:** 617ce7efd669b4d3af61c42f77b4cd1f986e3fae  
**طبيعة هذه الشريحة:** مزامنة توثيقية؛ لا تغييرات في Laravel runtime أو API routes أو migrations أو workflow behavior أو IaC أو إعدادات الإنتاج.

## 1. لماذا تم هذا التحديث؟

أظهر التدقيق أن بعض سجلات phases تصف لقطة 21–24 نوفمبر باعتبارها الحالة الراهنة، رغم أن التنفيذ تقدم بعدها. كما تداخلت حالات تاريخية لفروع/PRs قديمة مع سياسة main الحالية، وكانت بعض الجداول تصنف ميزات منفذة ومختبرة على أنها غير موجودة.

إضافة إلى ذلك، كان هناك قرار مستخدم سابق بتأجيل اختيار مزود Push فقط. وجّه المالك الآن بتعميم هذا التأجيل ليشمل كل مزودي الخدمات والمنصات والمناطق والتكاليف التي تحتاجها مراحل الإغلاق، إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل. لا تُفتح الاختيارات على مستوى مرحلة منفردة؛ وتبقى معايير قبول المزود الحقيقية مؤجلة (قرار D-051).

## 2. مرجع الشجرة الحالية عند بدء المزامنة

تمت مراجعة Git Trees API recursive على main عند SHA eaa522f53bf80911e869ce25af0115b516a9b8dd، وكانت النتيجة truncated=false:

- 253 ملفًا متتبعًا و55 عقدة مجلد.
- 79 ملفًا داخل phases/، منها 78 ملف Markdown و45 تقريرًا و16 مواصفة مرحلة PHASE-XX.
- 6 GitHub Actions workflows.
- 23 ملف migration.
- 42 ملف PHP للاختبارات: 22 Feature و14 Integration والباقي Fixtures/TestCase.
- 49 ملف PHP تحت app/ و13 ملف PHP تحت config/.

أعيد بناء phases/BACKEND_FILE_CATALOG.md من شجرة Git الفعلية، مع المحافظة على حقيقة أن الأعداد الأقدم كانت جردًا تاريخيًا وليست جردًا للشجرة الحالية.

## 3. أدلة المشروع التي طوبقت مع التوثيق

### 3.1 التطبيق وواجهات API

- Laravel 13 وPHP 8.4، مع composer.json وcomposer.lock وartisan وbootstrap وapp وroutes ضمن المصدر الملتزم.
- contracts/api-v1.json يحتوي 39 سجلًا للطريقة والمسار وحدود المصادقة، ويطابق routes/api.php عبر ApiV1ContractTest.
- المسارات تشمل auth، devices، private media، profile/preferences/data export، entitlements، wallet read APIs، bookmarks، recently-watched، playlists، preferences sync وwellness sync.
- تم تحديث phases/API_CONTRACT_INVENTORY.md بجدول manifest الحالي وإيضاح الفرق بين route موجود وبين تكامل خارجي مثبت.

### 3.2 البيانات والاختبارات

- 23 migration ملتزمة في database/migrations/، تشمل Laravel foundation، auth/device metadata، user profile/preferences/devices، bookmarks/recent media/playlists/preferences/wellness sync، user media، notification outbox، push subscriptions، entitlements/subscription events/projection، وwallet/transactions.
- اختبارات SQLite وPostgreSQL موجودة في tests/Feature وtests/Integration.
- تم تحديث phases/DATABASE_AND_DATA_INVENTORY.md بقائمة الملفات الحالية، وتصحيح الملاحظات القديمة حول startup migrations/probe accounts وcache/session/queue defaults.
- لم يتم الاتصال بقاعدة الإنتاج أو قراءة بياناتها أو أسرارها. أعداد السجلات في Git لا تكشف row counts في قاعدة تشغيل حقيقية.

### 3.3 الميزات الحالية وما لا يزال مفقودًا

تم تحديث phases/FEATURE_MATRIX.md لمطابقة الشفرة والاختبارات الحالية، خصوصًا:

- Phase04: email/password/guest/Google verifier موجودة خادميًا؛ الاختبار الحقيقي من Android غير مثبت، وإرسال البريد الحقيقي معطل.
- Phase05–07: أساس المستخدم، المكتبة، recently-watched، playlists، wellness والمزامنة مثبتة باختباراتها.
- Phase08: private media/outbox/queue retry/dead-letter وPush subscription registry موجودة؛ لا S3 bucket أو mail sender أو Push sender خارجي مثبت.
- Phase09: entitlement read، append-only subscription ledger/projection/expiry، billing plans closed-by-default، وwallet read APIs/internal ledger موجودة. لا top-ups أو checkout أو payment webhooks أو renewal أو coupon public APIs.
- Phase10: API contract، load baseline، provenance/SBOM/attestations وisolated rollback rehearsal موجودة؛ 10B مؤجلة بطلب المالك.
- Phase11: runtime portability وtrust boundary وprovider-neutral OpenTofu scaffold موجودة؛ لا provider modules أو resources أو remote state أو apply أو deploy.

## 4. الحالة الموحدة للمراحل بعد المزامنة

| المرحلة | الحالة | ما يستمر الآن |
|---|---|---|
| 00–03 | COMPLETE | لا إعادة تنفيذ؛ تبقى متطلبات المزود قبل الإنتاج ضمن بوابات الإطلاق |
| 04 | PARTIAL | العمل البرمجي/الاختباري المحايد فقط؛ Google Android والبريد الحقيقي مؤجلان |
| 05–07 | COMPLETE | لا إعادة تنفيذ للمنجز؛ الحفاظ على العقود والاختبارات |
| 08 | IN_PROGRESS | provider-neutral storage/notification/outbox/worker tests؛ S3/mail/Push الحقيقي مؤجل |
| 09 | IN_PROGRESS؛ 09A–09F مكتملة برمجيًا وCI | استمرار أي فجوات code/contract لا تحتاج قرارًا تجاريًا؛ الخطط والأسعار والدفع مؤجلة |
| 10 | IN_PROGRESS؛ 10A/10C–10G مكتملة، 10B DEFERRED_BY_OWNER | استمرار CI والإصدار؛ حماية main يدويًا بعد خطة Backend وقبل 11B2 |
| 11 | IN_PROGRESS | استمرار 11C المحايدة وفحوصها؛ provider adapters/provisioning/staging مؤجلة |
| 12 | WAITING_DEPENDENCY | صيانة synthetic rehearsal وmapping/design دون DB حقيقية؛ real backup/restore/cutover مؤجل |
| 13 | WAITING_DEPENDENCY | runbooks ومتطلبات التشغيل المحايدة فقط؛ موارد التشغيل الفعلية مؤجلة |

لا يحول تأجيل الاعتماديات أي مرحلة إلى COMPLETE. كما لا يعاد تنفيذ المراحل المكتملة.

## 5. القرار الجديد D-050 — التأجيل الموحد لاختيار جميع المزودين

بموجب التوجيه الصريح للمالك في 27 نوفمبر 2026:

**تؤجل جميع اختيارات مزودي الخدمات والمنصات والمناطق والخطط والتكاليف إلى ما بعد اكتمال أعمال تطوير Backend التي يمكن بناؤها واختبارها دون مزود خارجي.**

يشمل ذلك، دون حصر:

- Hosting/Compute وPostgreSQL provider/region.
- Object storage/S3-compatible provider.
- Email delivery provider.
- Push provider: لا FCM ولا OneSignal مختار.
- Payment provider والخطط والأسعار والعملة والشروط التجارية.
- Container registry.
- Secrets manager وworkload identity/OIDC deployment.
- Logs/metrics/monitoring/alerts.
- DNS/TLS/edge/load balancing.
- Backup/DR provider، retention، region، RPO/RTO وتكلفة التشغيل.

### العمل المسموح والمطلوب الآن

- Laravel domain/services وAPI contracts وroutes وschema/migrations.
- الميزات التي لا تحتاج حساب مزود أو credentials.
- اختبارات Feature وIntegration على SQLite/PostgreSQL مؤقتة.
- mocks/fakes وS3Mock لاختبار العقود والسلوك المحلي.
- provider-neutral storage/mail/push/payment boundaries دون ربط SDK أو إرسال خارجي.
- OpenTofu provider-neutral scaffold وguardrails وfmt/init/validate/provider-free plan.
- API contract regression وsecurity/runtime/release evidence.
- synthetic backup/restore وrollback rehearsal المعزول.

### ما يبقى ممنوعًا حتى إعادة فتح القرار

- لا اختيار مزود أو منطقة أو خطة مدفوعة الآن.
- لا provider-specific SDK/sender/payment adapters أو credentials/secrets أو حسابات جديدة.
- لا cloud resources أو remote state/backend أو tofu apply.
- لا OCI registry push أو DNS change أو staging/production deployment.
- لا اتصال بقاعدة الإنتاج ولا backup/restore أو migration أو cutover حقيقي.
- اختيار المزود لاحقًا لا يعتبر إذنًا تلقائيًا بإنشاء الموارد أو دفع التكلفة أو تنفيذ cutover؛ لكل منها موافقة محددة مستقلة.

الافتراضات الآمنة الحالية تبقى: mail delivery disabled، RAVEN23_PUSH_ENABLED=false، RAVEN23_PUSH_PROVIDER=none، purchases_enabled=false وplans=[]، والتخزين المحلي الخاص هو default.

هذا القرار موثق بالتفصيل في phases/DECISIONS.md تحت D-050، وهو يوسع قرار Push السابق في PUSH-PROVIDER-SELECTION-DEFERRED.md دون حذف السجل التاريخي.

## 5.1 توضيح التوقيت — D-051

لا تُفتح اختيارات المزودين بمجرد اكتمال الأعمال المحايدة لمرحلة واحدة. موعد إعادة فتحها هو بعد الانتهاء النهائي من خطة تطوير Backend بالكامل. حتى ذلك الحين تواصل المراحل أعمال الكود والاختبارات والعقود وCI التي لا تحتاج مزودًا، وتبقى معايير التكامل الخارجي أو provisioning أو restore/cutover التي تحتاج مزودًا بحالة مؤجلة ولا تُسجل COMPLETE. بعد اكتمال الخطة تُعرض مقارنة موحدة ثم تُطلب موافقة مستقلة على كل مزود وتكلفة ومورد وعملية تشغيل.

## 6. وثائق جرت مزامنتها

تمت مزامنة أو تحديث الملفات التالية مع أدلة main الحالية:

- MASTER_PLAN.md, STATUS.md, README.md, DECISIONS.md.
- BACKEND_FILE_CATALOG.md, FEATURE_MATRIX.md, API_CONTRACT_INVENTORY.md, DATABASE_AND_DATA_INVENTORY.md, WORKFLOW_INVENTORY.md, ENVIRONMENT_AND_SECRET_NAMES.md.
- TOOLS_AND_SERVICES.md, USER_RULES_AND_CONSTRAINTS.md, EXECUTION_PROTOCOL.md, SECURITY-OPERATIONS-POLICY.md, BRANCH_POLICY.md.
- مواصفات Phase04 وPhase08 وPhase09 وPhase11 وPhase12 وPhase13.
- PUSH-PROVIDER-SELECTION-DEFERRED.md لتوسيع قرار Push القديم إلى جميع المزودين.

لم تُحذف أو تُستبدل تقارير الإغلاق والتقدم السابقة؛ تبقى أدلة تاريخية بتاريخها وSHA الخاص بها. هذا التقرير هو سجل المزامنة الأحدث.

## 7. CI والحد الفاصل بين التحقق البرمجي والتكامل الخارجي

على commit الأساس 617ce7e قبل المزامنة الشاملة كانت البوابات الأربع:

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | 45936514582 | SUCCESS |
| Security | 45936514543 | SUCCESS |
| Pint | 45936514597 | SUCCESS؛ changed-PHP steps skipped لعدم وجود ملفات PHP متغيرة، وstrict Pint داخل Docker نجح |
| Authentication | 45936514610 | SUCCESS |

Docker evidence على ذلك SHA: SQLite Feature 151/1,719؛ PostgreSQL Integration 31/546 مع optional skip واحد؛ S3Mock 1/8؛ Build/test 30/30؛ trusted-main signing 17/17. هذه أدلة CI وليست تكامل مزود حقيقي.

**ملاحظة تحقق:** الوثائق المحدثة في هذه الشريحة تُدفع إلى main كتغييرات توثيقية. يجب التحقق من تشغيلات CI الجديدة على الرأس النهائي بعد اكتمال التحديث؛ لا تُنسب نتائج SHA 617ce7e تلقائيًا إلى commits التوثيق اللاحقة.

## 8. سلامة النطاق

- المستودع الوحيد المعدّل: m5ham3ds/RAVEN23-BACKEND.
- جميع التغييرات على main فقط.
- لم يُنشأ أي فرع جديد.
- لم تتغير ملفات التطبيق أو API routes أو migrations أو workflows التنفيذية أو IaC.
- لم تُقرأ أو تُعدل أسرار أو قواعد إنتاج أو بيانات مستخدمين.
- لم يتم إنشاء موارد أو نشر أو تنفيذ apply أو cutover.
- لا يغير هذا التقرير معايير قبول أي مرحلة؛ إنه يوحد الواقع الحالي والقرار التشغيلي للمراحل التالية.
