# جرد GitHub Actions وعمليات النشر

**Latest CI-verified main SHA:** dd861fa9afd6f876ff300f65aae58a35b16f3112  
**تاريخ التحديث:** 23 نوفمبر 2026 — سجل 21 نوفمبر تاريخي  
**الحالة:** COMPLETE على مستوى source/workflow inventory؛ لم تُنفذ workflows التي تكتب على الإنتاج.

## 1. ملفات workflows الحالية (بعد مراجعة شجرة main)

عدد الملفات الحالية: **5**.

| الملف | الاسم | Trigger | السلوك |
|---|---|---|---|
| `.github/workflows/docker-build.yml` | RAVEN23 Backend Docker Validation | push/pull_request إلى main | Production image build، SQLite Feature tests، PostgreSQL 16 disposable Integration tests، Pint strict/runtime/least-privilege/worker guards. |
| `.github/workflows/auth-smoke.yml` | RAVEN23 Runtime HTTP Smoke | workflow_dispatch فقط | فحص GET-only لـliveness/readiness/health وتسجيل المسارات؛ عنوان API يمرر يدوياً. لا إنشاء حساب أو migration. |
| `.github/workflows/pint-format-report.yml` | RAVEN23 Pint Format Report | push/pull_request إلى main وworkflow_dispatch | ينسق PHP files المتغيرة فقط ثم ينشر artifact؛ إذا لا توجد PHP files، يتخطى خطوات التنسيق. |
| `.github/workflows/security-scans.yml` | RAVEN23 Security Scans | push/pull_request إلى main وworkflow_dispatch | Gitleaks وTrivy filesystem/image وفشل على fixable HIGH/CRITICAL image vulnerabilities. |
| `.github/workflows/users-auth-tests.yml` | RAVEN23 Authentication Regression Tests | push/pull_request إلى main | Auth regression على SQLite وPostgreSQL المؤقتة. اسم الملف تاريخي ولا يعتمد فرع USERS. |

### Workflows الإنتاج التي أزيلت

حُذفت سبعة ملفات من `main` بعد اكتشاف استهدافها Render production مباشرة:
- `production-account-smoke.yml`
- `production-account-retry.yml`
- `production-account-retry-2.yml`
- `production-account-retry-3.yml`
- `production-account-retry-4.yml`
- `production-account-retry-7.yml`
- `production-registration-route-probe.yml`

ستة منها تنفذ دورة إنشاء/تسجيل دخول/حذف حساب ضد API الإنتاج، وملف route-probe يرسل POST فارغاً إلى production للتحقق من validation. كانت triggers مرتبطة بتعديل ملف workflow نفسه وليست regression checks آمنة لكل تغييرات التطبيق. لم يتم تشغيلها خلال المراجعة، ولم ترسل أي طلبات إلى Render، ولم يتم تغيير قاعدة الإنتاج. أي lifecycle test حي مستقبلاً يجب أن يستخدم staging بقاعدة معزولة وموافقة صريحة.

## 2. نتائج CI المرصودة

### نتائج خط الأساس التاريخية — 21 نوفمبر 2026

- Backend Docker Validation #182 — SHA 9f29fa55afa21c0445be82d4590a4a532bb1747c — SUCCESS.
  - 11 Feature tests ناجحة / 68 assertions.
  - 3 PostgreSQL Integration tests ناجحة / 72 assertions.
  - PostgreSQL 16 خدمة مؤقتة ومعزولة في GitHub runner.
  - Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418246
- Live Authentication Smoke #127 — SHA نفسه — SUCCESS.
  - health/DB/schema/expected commit/routes على Render.
  - فحص routes GET-only، وليس إنشاء حساب أو تسجيل Google حقيقي.
  - Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418305
- Roadmap PR validation #183 على SHA 0f650a667e981c5e5a4341d33a17dcc3ae27ee76 — SUCCESS.
  - Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44945588390

### فشلات سابقة مفيدة في التحليل

- Docker Validation #180 على SHA b0efdad369754917c5d5d5037c36f81e0866e40a فشل عند PHP syntax في AuthController.php line 107: unexpected token backslash. تم تصحيح الصياغة في commit لاحق؛ #182 نجح.
- Runtime DB Diagnostics #6 فشل لأن GitHub secret Neon URL لم يطابق هدف قاعدة بيانات تطبيق Render الحية؛ #7 نجح بعد تحديث المنطق.
- بعض runs على commits بينية تم إلغاؤها عند وصول commits أحدث؛ CANCELLED لا يعني pass أو source regression.
- لا تُخفى نتائج failure/cancelled القديمة خلف نتيجة أحدث ناجحة؛ تُسجل بالـSHA والسبب.

## 3. ملاحظات التصميم ومخاطر workflows

1. خمس نسخ production-account retry متشابهة تكرر نفس العمل تقريبًا وتُشغل تغييرات workflow نفسها فقط. ينبغي توحيدها في staging smoke workflow واحد.
2. تشغيل اختبار account creation/deletion على production يحمل خطر cleanup failure. الأفضل قاعدة staging معزولة وبيانات اصطناعية.
3. معظم workflows hardcode عنوان Render وتتحقق من render_git_commit؛ هذا يمنع نقل النشر إلى AWS دون إعادة تصميم آلية health version contract.
4. auth-smoke.yml يعرّف workflow_dispatch لكن job condition يقصر التنفيذ على event_name push؛ التشغيل اليدوي معلن لكنه لا يمر بشرط job.
5. trigger الخاص بـ production tests هو تعديل ملف الاختبار نفسه، وليس تغييرات التطبيق ذات العلاقة؛ لذلك لا يضمن regression coverage.
6. لا يوجد workflow نشر AWS في الملفات التي تم جردها. ولا يظهر OIDC أو id-token: write في workflow الموجود.
7. runtime-db-diagnostics يحتوي IDs ثابتة 165,168 في RAVEN_DIAG_INSPECT_USER_IDS لفحص read-only لسجلات حسابات موجودة. لم نشغّل هذا الفحص ولم نعدّل أي صفوف.
8. docker-build.yml هو workflow الوحيد الذي يثبت فعليًا PostgreSQL integration داخل بيئة disposable في المصدر الحالي.

## 4. توصية مرحلية

- خلال Phase 01–02: إبقاء Docker build validation فقط مع PostgreSQL disposable.
- خلال Phase 10: توحيد auth smoke وcontract smoke على staging، وحذف/دمج retry workflows بعد نقل كل assertions المفيدة إليها.
- خلال Phase 11: إضافة build-once/publish-by-digest/deploy عبر GitHub OIDC إلى AWS بعد موافقة المستخدم.
- Phase 12: عدم استخدام أي production account test أو DB write خارج نافذة cutover المصرح بها.


## 5. أحدث حالة تشغيل — 23 نوفمبر 2026

### رؤوس الفروع ونتائج GitHub Actions
- main docs validation SHA f1d33e4bdb6085d567c55e25e77f20892787e40b: Docker #362 (run 45084507399)، Pint #99 (45084507307)، Security #101 (45084507272).
- TEST validation SHA 1940c758c76cebc18451d4d8879912589524950f: Docker #363 (run 45084564611)، Pint #100 (45084564563)، Security #102 (45084564548).
- USERS validation SHA 0f8fe357e1333f75660e8f8338f72a2a2143adfd: Auth #9 (run 45084570722)، Pint #101 (45084570707)، Security #103 (run 45084570669).

### نتيجة فحص تخصيص runner
- آخر Docker job على main وTEST وآخر Auth job على USERS: runner_id=0، runner_name فارغ، steps=[].
- سجلات الوظائف غير متاحة ويعيد endpoint تنزيلها BlobNotFound؛ لم تبدأ أي خطوة من المشروع.
- هذا تعثر تخصيص/بدء runner قبل التنفيذ؛ لا يثبت فشل PHP أو نجاحه.
- quota/billing أو إعدادات تخصيص runner احتمال تشغيلي فقط، لم يتم تأكيده من صفحة Billing. يحتاج مالك المستودع مراجعة Actions usage/spending limit/payment method.
- لا تكرر rerun عشوائيًا ولا تعدّل الكود لإخفاء هذه الحالة.

لم يتم تشغيل workflows التي تكتب على الإنتاج، ولم تتم قراءة secrets أو تعديل قاعدة الإنتاج.

## 6. توحيد الاختبارات وCI على main — 23 نوفمبر 2026

**سياسة التشغيل الحالية هي main فقط.** أوصاف triggers في الجداول التاريخية أعلاه تعكس الحالة السابقة قبل إلغاء فروع الاختبارات.

- `docker-build.yml`: push/pull_request على main؛ جميع Feature tests على SQLite وجميع Integration tests على PostgreSQL 16 المؤقتة بدور تطبيق محدود.
- `pint-format-report.yml`: push/pull_request على main وworkflow_dispatch؛ Push يقارن مع parent SHA، PR مع base branch، والتشغيل اليدوي مع commit السابق.
- `security-scans.yml`: push/pull_request على main وworkflow_dispatch؛ Gitleaks وTrivy source/image.
- `users-auth-tests.yml`: أصبح `RAVEN23 Authentication Regression Tests` ويعمل على main فقط كفحص إضافي لدورة الحسابات.
- اختبارات Phase06 الثمانية في main؛ `tests/README.md` يوضح استخدام SQLite/PostgreSQL المؤقتة فقط.
- workflows القديمة production-account retry/smoke تحتاج توحيدًا آمنًا على staging ضمن Phase10، ولا تُشغّلها على الإنتاج.
- لا يوجد AWS deploy workflow أو OIDC role مثبت في المستودع.

## 7. CI على main — Phase08A، 23 نوفمبر 2026

### SHA المعتمد
`dd861fa9afd6f876ff300f65aae58a35b16f3112`

| Workflow | Run | النتيجة |
|---|---:|---|
| Backend Docker Validation | [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525) | SUCCESS — SQLite 92/1,046؛ PostgreSQL 12/265؛ worker safety guards وruntime smoke PASS |
| Authentication Regression | [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572) | SUCCESS — SQLite 20/151؛ PostgreSQL 4/99 |
| Pint Format Report | [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578) | SUCCESS |
| Security Scans | [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417) | SUCCESS — Gitleaks/Trivy؛ production image summary Clean |

### Failure history الخاصة بهذه الشريحة
- Docker على SHA `81127e370725e66a76199c006f788154ded10fe2` فشل قبل PostgreSQL بسبب Pint: `class_attributes_separation` في EmailVerificationNotification و`ordered_interfaces` في PasswordResetNotification.
- تم ضبط ترتيب الواجهات وفصل الأسطر في commits `f202166` و`14ecb5c`، واجتازت جميع البوابات الأربع على `14ecb5c`.
- أُضيف لاحقاً fixed retry deadline واختبارات worker guard في `dd861fa`، ثم اجتازت جميع البوابات الأربع عليه.
- لا تُخفى هذه النتيجة السابقة: الفشل كان تنسيقياً، وتم إصلاحه والتحقق من SHA أحدث.

لا توجد PRs أو فروع اختبار جديدة؛ GitHub branch listing أعاد `main` فقط.


## 8. تحديث نتائج ومراجعة CI — 23 نوفمبر 2026

تم التحقق من نتائج التشغيلات الأربعة على رأس الوثائق `729163a909679cf50cd0cd1275dcd9ab8f7061f6`:

| Workflow | Run | النتيجة المرصودة |
|---|---:|---|
| Docker Validation | [45205731260](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45205731260) | SUCCESS؛ جميع الخطوات الأساسية وSQLite/PostgreSQL/runtime smoke PASS |
| Security Scans | [45205731207](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45205731207) | SUCCESS؛ Gitleaks/Trivy/image gates PASS |
| Authentication Regression | [45205731245](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45205731245) | SUCCESS؛ SQLite/PostgreSQL auth lifecycle PASS |
| Pint Format | [45205731265](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45205731265) | SUCCESS؛ لم تتغير PHP files لذلك تخطت steps الخاصة بالملفات المتغيرة؛ strict Pint داخل Docker نجح |

آخر التزام برمجي اجتاز البوابات الأربع على SHA واحد هو `dd861fa9afd6f876ff300f65aae58a35b16f3112` مع runs #45204060525 و#45204060572 و#45204060578 و#45204060417، كلها SUCCESS.
