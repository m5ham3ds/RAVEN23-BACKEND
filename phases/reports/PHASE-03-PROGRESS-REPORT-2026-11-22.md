# تقرير تقدم المرحلة 03 — الأمن والتشغيل والرصد

- **الحالة:** IN_PROGRESS
- **تاريخ البدء:** 22 نوفمبر 2026
- **تاريخ هذا التقرير:** 22 نوفمبر 2026
- **فرع التنفيذ:** feature/phase-03-security-observability
- **PR:** #4 — مفتوح كمسودة وغير مدمج
- **SHA الأساس:** ae311d1dced43b10f96849abfbd3c0a3460dcc3d (Phase 02 COMPLETE)
- **SHA الحالي وقت التقرير:** 50780ce27e410b890a7f2535172e86cbfe5565a8

## 1. النتيجة حتى الآن

بدأ تنفيذ Phase 03 بعد إثبات اكتمال Phase 01 وPhase 02. لم تغلق المرحلة بعد؛ توجد إصلاحات أمنية واختبارات إضافية على فرع PR #4، وتنتظر النسخة الحالية نتائج جميع بوابات CI على SHA واحد.

## 2. ما تم تنفيذه في فرع Phase 03

- إضافة request correlation UUID مولّد من الخادم وX-Request-ID، مع تنظيف logging context بين الطلبات.
- إخفاء بيانات البنية الداخلية من health وAPI error responses العامة، وإرجاع رسائل عامة ومعرف طلب.
- تقليل تفاصيل سجلات API إلى بيانات تشخيصية محدودة، دون request bodies أو email أو tokens أو SQL/bindings أو أسماء مضيف/قاعدة بيانات.
- فصل liveness عن readiness، مع إبقاء فشل قاعدة البيانات كـ503 دون تسريب تفاصيل الاتصال.
- إضافة CORS exact-origin allowlist، دون credentials أو wildcard؛ كما أصبح التحقق يرفض أي علامة نجمة داخل origin لأن مكتبة CORS تحوّلها إلى نمط wildcard.
- تقوية rate limits على IP والحساب والجهاز باستخدام HMAC لمفاتيح الهوية.
- تقليل mass assignment في User model، وإضافة اختبارات لسلوك token replacement وحدود الحساب.
- إضافة/توسيع security headers، no-store، إعدادات runtime آمنة، ورفض APP_DEBUG=true في production.
- إضافة GitHub Actions لفحوص Gitleaks وTrivy filesystem/image وComposer audit وPint، مع تقارير قابلة للتنزيل.

## 3. تشخيص فشل CI والإصلاحات

| التشغيل | SHA | النتيجة والسبب | المعالجة |
|---|---|---|---|
| Docker Validation #263 | a91d9bb… | فشل Feature test بسبب افتراض أن CORS يجب أن يحذف ACAO عند وجود origin ثابت واحد. مكتبة Fruitcake v1.4.0 تعيد الـorigin الثابت المسموح به؛ المتصفح لا يقبلها للـorigin المختلف لأنها لا تطابقه. | تعديل الاختبار لتمييز القائمة ذات العنصر الواحد عن القائمة متعددة العناصر، وإضافة assertions تمنع انعكاس origin غير الموثوق. |
| Security Scans #6 وPint #4 | a91d9bb… | تعثرت بواباتها التابعة بعد فشل اختبار البناء السابق؛ لا تعتبر نتائجها نجاحًا. | أعيدت بعد تصحيح الاختبار. |
| Docker Validation #264 | 750ab01… | اجتازت صورة Docker واختبارات SQLite، ثم فشلت بوابة Pint الصارمة: 6 مخالفات تنسيق في 12 ملف PHP؛ لذلك تخطت PostgreSQL وHTTP smoke اللاحقين. | تطبيق تنسيق Pint على الملفات المتأثرة، وإزالة متغير closure غير مستخدم، وضبط الاختبار المضاف لاحقًا. |
| Security Scans #7 وPint #5 | 750ab01… | نجحت على SHA وسيط فقط؛ لا تثبت SHA الحالي. | تُحفظ كأدلة وسيطة ولا تُستخدم لإغلاق المرحلة. |

كما أضيف اختبار regression لمنع إعداد CORS من نوع https://*.example.test؛ هذا كان خلل إعداد فعليًا لأن المكتبة تحوّل النجمة داخل النطاق إلى wildcard. تم تصحيح صياغة استثناء الاختبار والتحقق من الملف بعد ذلك.

## 4. سلسلة إصلاحات PR #4

- 750ab01bc5e94c9ba09b6b53c7c39794e57dcb0f — تصحيح توقعات اختبار CORS وإضافة حالة متعددة origins.
- 5c393c604321607338a8c3fa3e9d43c6e1734c5d — رفض wildcard داخل أي origin في config.
- 6a22e1971910208948ba87f2d12eed12048935a7 — إصلاح صياغة exception class وإضافة اختبار wildcard subdomain.
- de2775cd5f7c7e4b98c6fffaa4a46e1ba973aa1e، 6e7695a1668017c49264b04002834ced4b483a40، 3432c79e31297051f6d27ea8164c70637b71b9bf، 36ef40ac594f606b344e3c8207f96e920610174c، 4616e9c2c73f828540bcd62171ace17dd602c6f5، f0b0c3142dc1751232c3396ee695164e32b69621 — إصلاحات تنسيق Pint في الملفات المتأثرة.
- 50780ce27e410b890a7f2535172e86cbfe5565a8 — ضبط تنسيق concatenation في اختبار CORS المضاف.

## 5. حالة CI على SHA الحالي

وقت إعداد هذا التقرير، GitHub Actions أنشأ التشغيلات التالية على 50780ce27e410b890a7f2535172e86cbfe5565a8، لكنها ما زالت في قائمة الانتظار ولم تُسجل نتيجة نهائية:

| Workflow | Run | الحالة |
|---|---:|---|
| Backend Docker Validation | #272 — https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44972375093 | PENDING |
| Security Scans | #15 — https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44972375092 | PENDING |
| Pint Format Report | #13 — https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44972375101 | PENDING |

نجاح Pint #8 على SHA أقدم 6a22e19… هو نتيجة تنسيق تقريرية، وليس بديلًا عن بوابة Pint الصارمة في Docker على SHA الحالي.

## 6. حماية البيانات وحدود التحقق

- لا اتصال بقاعدة الإنتاج أو Neon أو Render، ولا تنفيذ migrations أو تغييرات بيانات.
- لا قراءة لقيم secrets أو رفعها إلى Git.
- PostgreSQL tests في workflow تستخدم PostgreSQL 16 مؤقتة داخل GitHub Actions.
- لا نشر، ولا إنشاء موارد AWS، ولا تغيير DNS/API production.
- لم يتم التحقق من TLS الطرفي أو صلاحية DB user الإنتاجي أو سياسة retention الفعلية للسجلات والنسخ الاحتياطية على بيئة تشغيل حقيقية؛ هذه تبقى غير مختبرة ولا يجوز الادعاء بأنها اجتازت.
- لم يتم اختبار Google OAuth أو البريد أو جلسات Android الحقيقية ضمن هذه المرحلة.

## 7. معايير القبول والحالة

| المعيار | الحالة |
|---|---|
| تقليل المعلومات العامة في health/errors | IMPLEMENTED — ينتظر إعادة إثبات CI النهائية |
| correlation ID وسجلات منقحة | IMPLEMENTED — ينتظر إعادة إثبات CI النهائية |
| CORS exact origins ورفض wildcard | IMPLEMENTED — ينتظر نجاح regression tests |
| rate limiting واختبارات الحساب/الجهاز | IMPLEMENTED — التحقق النهائي ينتظر Docker/PG |
| mass-assignment وcross-user token protection | IMPLEMENTED — التحقق النهائي ينتظر Docker/PG |
| APP_DEBUG production guard وruntime smoke | موجودة في Docker workflow؛ لم تصل إلى نتيجة SHA الحالي بعد |
| Gitleaks/Trivy reports | موجودة؛ تنتظر تشغيل Security #15 |
| DB least-privilege وTLS/retention على الإنتاج | NOT TESTED — لا توجد تغييرات إنتاجية مصرح بها |

## 8. القرار والخطوة التالية

**Phase 03 = IN_PROGRESS؛ لا تغلق ولا تبدأ Phase 04 بعد.**

الاستمرار من رأس الفرع الحالي feature/phase-03-security-observability، والتحقق من تشغيلات #272/#15/#13 على SHA 50780ce…. إذا فشل أي gate، اقرأ سجل الوظيفة الفاشلة وأصلح السبب المثبت فقط، ثم أعد الاختبارات على SHA جديد واحد. بعد النجاح، راجع بقايا معايير المرحلة غير المختبرة، وأنشئ تقرير إغلاق فقط إذا استوفت المعايير؛ وإلا وثّق الجزء المنتظر بوضوح. لا تدمج PR #4 ولا تنشر ولا تلمس الإنتاج دون موافقة مباشرة.

## 9. تعليمات الاستمرارية

ابدأ بقراءة هذا التقرير، ثم phases/STATUS.md وphases/EXECUTION_PROTOCOL.md وphases/PHASE-03-SECURITY-OPERATIONS-FOUNDATION.md. تابع نفس الفرع وPR #4 وآخر SHA؛ لا تعِد Phase 00–02 أو الاختبارات التي اجتازت SHA موحدًا سابقًا ما لم يتغير مصدرها أو تتطلبها بوابة commit الجديد.


---

## تحديث الحالة — 22 نوفمبر 2026 (بعد فحص Trixie وتثبيت الـdigest)

هذا التحديث أحدث من حالة التشغيلات المذكورة في المتن أعلاه، ويعتمد على رأس فرع PR #4 الحالي `096ad1d31033a0af75f173f537410ddf3f6cb6bf`.

### صورة الإنتاج ونتائج CI

- تم نقل صورة التشغيل من Debian Bookworm إلى Debian Trixie باستخدام FrankenPHP 1.13.6 / PHP 8.4.28.
- تم حل digest متعدد المعماريات من Docker Buildx ثم تثبيته في Dockerfile:
  `sha256:bbe6057527f8dd9e270d9e2a33315f26b264ce8b0dbd19b67b0855a7899960ec`.
- أضيف تحقق داخل Docker workflow يفشل إذا لم يتطابق digest المثبت في Dockerfile مع digest المحسوم من registry.
- Docker Validation #280 على وسم Trixie قبل التثبيت: SUCCESS (18 خطوة).
- Docker Validation #282 على SHA المثبت `096ad1d…`: SUCCESS (18 خطوة، بما فيها SQLite، PostgreSQL 16، migration task، readiness/no-DB، مستخدم غير root، ورفض تنفيذ PHP assets).
- Pint #19 على مرحلة Trixie وPint #20 على SHA المثبت: SUCCESS (8 خطوات لكل تشغيل).
- Security Scans #21 على صورة Bookworm المثبتة: SUCCESS، 122 finding (3 CRITICAL و119 HIGH، بلا FixedVersion في قاعدة Trivy حينها).
- Security Scans #22 على صورة Trixie: SUCCESS، 121 finding (1 CRITICAL و120 HIGH، جميعها بلا FixedVersion معلن في التقرير)، واجتازت بوابة HIGH/CRITICAL القابلة للإصلاح. أزيلت نتيجتا SQLite وzlib الحرجتان بالمقارنة مع Bookworm، وبقيت CVE-2026-6653 في libxml2 دون FixedVersion في Debian 13.8 وقت الفحص.
- Security Scans #23 على SHA المثبت `096ad1d…`: كان IN_PROGRESS وقت آخر مراجعة؛ لا يوصف بأنه ناجح حتى ينتهي.

### تفسير نتائج الأمن

نجاح بوابة `--ignore-unfixed` لا يعني خلو الصورة من الثغرات؛ بل يعني أن قاعدة Trivy لم تعرض إصدارًا ثابتًا للنتائج التي ظلت في تقرير Trixie. يجب الاحتفاظ بـartifact ومراجعة هذه النتائج عند تحديث قاعدة Trivy أو إصدار Debian/FrankenPHP جديد. لا توجد نتيجة تدعم ادعاء “صفر ثغرات”.

### ما لا يزال يمنع إغلاق Phase 03

1. التحقق من صلاحيات DB user الفعلي في بيئة الاستضافة ومبدأ أقل صلاحية لم يُنفذ؛ الاختبارات الحالية تستخدم PostgreSQL مؤقتة في GitHub Actions فقط.
2. لم نتحقق من TLS الطرفي/إعدادات proxy الفعلية في الاستضافة.
3. سياسة الاحتفاظ بالسجلات والنسخ الاحتياطية، وrehearsal الاستعادة الفعلية، غير مثبتة على مزود الاستضافة.
4. Google OAuth والبريد وجلسات Android الحقيقية ليست جزءًا من اختبارات CI الحالية.

هذه القيود مقصودة: لم يتم الاتصال بقاعدة الإنتاج، أو قراءة الأسرار، أو تعديل Render/Neon، أو تنفيذ migrations إنتاجية، أو النشر. لذلك تبقى Phase 03 `IN_PROGRESS` وPhase 04 `NOT_STARTED` إلى أن تغلق المتطلبات المتبقية بموافقة صريحة وأدلة قابلة للتحقق.

### الخطوة التالية

- أكمل Security #23 على SHA المثبت الحالي.
- إذا نجح، لا تعِد Docker/Pint؛ فقد اجتازا على نفس SHA.
- راجع المخاطر غير القابلة للإصلاح في artifact، ثم وثّق متطلبات least-privilege وretention/backup وما يستلزم صلاحية بيئة الإنتاج.
- لا تبدأ Phase 04 ولا تدمج PR #4 أو تنشر دون إكمال شروط المرحلة وموافقة المستخدم.
