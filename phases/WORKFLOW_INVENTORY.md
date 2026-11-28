# جرد GitHub Actions وعمليات النشر

**Latest fully CI-verified code/runtime SHA:** `6750eac566c513c4fc97951f1b80cb7f54cd0c0a`; **latest main docs + CI SHA:** `72cebe78fb436a6036b7144ff3b0c31e542204a0` (Docker #687, Security #433, Authentication #309, Pint #431 — all SUCCESS).
**Latest application/runtime commit beneath this docs-only snapshot:** `6750eac566c513c4fc97951f1b80cb7f54cd0c0a`  
**تاريخ المزامنة:** 28 نوفمبر 2026 — السجلات السابقة أدلة تاريخية مرتبطة بعملياتها  
**الحالة:** ست workflows CI/manual read-only؛ أضيف OpenTofu provider-neutral validation في Phase11C ولا توجد قناة cloud deploy أو provider deployment.

## 1. ملفات workflows الحالية (بعد مراجعة شجرة main)

عدد ملفات CI/workflow الحالية بعد إضافة IaC validation: **6**.

| الملف | الاسم | Trigger | السلوك |
|---|---|---|---|
| `.github/workflows/docker-build.yml` | RAVEN23 Backend Docker Validation | push/pull_request إلى main | Production image build، SQLite Feature tests، PostgreSQL 16 disposable Integration tests، Pint strict/runtime/least-privilege/worker guards. |
| `.github/workflows/auth-smoke.yml` | RAVEN23 Runtime HTTP Smoke | workflow_dispatch فقط | فحص GET-only لـliveness/readiness/health وتسجيل المسارات؛ عنوان API يمرر يدوياً. لا إنشاء حساب أو migration. |
| `.github/workflows/pint-format-report.yml` | RAVEN23 Pint Format Report | push/pull_request إلى main وworkflow_dispatch | ينسق PHP files المتغيرة فقط ثم ينشر artifact؛ إذا لا توجد PHP files، يتخطى خطوات التنسيق. |
| `.github/workflows/security-scans.yml` | RAVEN23 Security Scans | push/pull_request إلى main وworkflow_dispatch | Gitleaks وTrivy filesystem/image وفشل على fixable HIGH/CRITICAL image vulnerabilities. |
| `.github/workflows/users-auth-tests.yml` | RAVEN23 Authentication Regression Tests | push/pull_request إلى main وworkflow_dispatch | Auth regression على SQLite وPostgreSQL المؤقتة؛ متداخل جزئيًا مع Docker full-suite ويعاد تقييمه في 10B. |
| `.github/workflows/iac-validation.yml` | RAVEN23 Infrastructure as Code Validation | push/pull_request إلى main مع مسارات IaC وworkflow_dispatch | OpenTofu fmt/init بدون backend/validate/provider-free plan؛ read-only، بلا provider أو credentials أو apply. |

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
6. لا يوجد workflow نشر سحابي أو AWS deployment role في الملفات الحالية. Docker workflow يملك `id-token: write` مع `attestations: write` و`artifact-metadata: write` لاستخدام `actions/attest@v4` في توقيع Sigstore؛ هذا OIDC خاص بالتوقيعات ولا يمنح صلاحية نشر إلى AWS أو أي مزود.
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


## 9. تحقق CI على رأس main — 24 نوفمبر 2026

على SHA `d0aa6d4a0e467fe6d72619858b13ef30a466722e` اكتملت البوابات الأربع بنجاح:

- Docker Validation [45289473199](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289473199) — SUCCESS.
- Authentication Regression [45289472959](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289472959) — SUCCESS.
- Pint Format [45289473303](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289473303) — SUCCESS.
- Security Scans [45289472973](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45289472973) — SUCCESS.

Docker steps المرصودة تشمل SQLite tests وPostgreSQL integration باعتماد application role محدود وmigration role منفصل، strict Pint، queue-worker guards، APP_DEBUG guard، non-root runtime، readiness وuploaded-PHP blocking. لم تكن هناك jobs فاشلة على هذا الرأس.


## 10. تحقق CI على SHA `8f34a0b` — Phase08 Outbox/S3، 24 نوفمبر 2026

- Docker Validation [45296772477](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772477) — SUCCESS: SQLite Feature 113/1,232؛ PostgreSQL Integration 18/311؛ S3Mock 1/8.
- Authentication Regression [45296772381](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772381) — SUCCESS: SQLite 20/172؛ PostgreSQL 4/99.
- Pint Format [45296772496](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772496) — SUCCESS.
- Security Scans [45296772309](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772309) — SUCCESS: Gitleaks/Trivy وproduction image وfixable HIGH/CRITICAL gates PASS.

Docker يشغّل `tests/Integration` ككل بدور PostgreSQL محدود الصلاحيات؛ أضيف `PostgresNotificationOutboxTest` إلى هذه المجموعة. اختبار S3Mock يدار كخطوة منفصلة بخدمة مؤقتة.


## 11. Final CI verification — Backend SHA `1d354f7e57ee3b5c03ee003f0fb2dbc1eff78ca3` — 24 November 2026

- Docker Validation [45411252479](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252479) — SUCCESS: SQLite Feature 125/1,382; PostgreSQL Integration 24/493 مع اختبار اختياري واحد skipped؛ S3Mock 1/8.
- Authentication Regression [45411252843](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252843) — SUCCESS.
- Pint Format [45411252483](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252483) — SUCCESS؛ Docker strict Pint على changed PHP files نجح.
- Security Scans [45411252485](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252485) — SUCCESS؛ Gitleaks/source scan وproduction image scan وfixable HIGH/CRITICAL gate PASS.
- Docker runtime steps نجحت: queue worker safety, APP_DEBUG guard, no-DB liveness/503 readiness، PostgreSQL restricted app role/migration role، non-root HTTP runtime، readiness، PHP asset blocking.
- Outbox tests: push relay pauses without mutating due push while feature flag disabled; outbox-status emits aggregate counters only.
- PostgreSQL concurrency metric in this same Docker run: 8 clients، 4,191 request bytes، 546 response bytes، average latency 166.538ms، max 209.686ms، 5/8 stale conflicts، winning stamp=5000. Synthetic CI measurement only.
- The single optional skipped PostgreSQL test is separately covered by the successful S3Mock integration step. This run does not prove real S3 provider staging.


## 12. Phase10A — CI inventory and workflow safety — 24 November 2026

- Actual tree before 10A contained six workflow files; the older inventory claimed five.
- league/flysystem-aws-s3-v3 is declared and locked at 3.35.3; Docker runs Composer validation/audit, so no lock bootstrap workflow is required.
- Removed prepare-s3-composer-lock.yml. It had contents:write/actions:write and could resolve dependencies, commit/push composer files to main, then dispatch four CI workflows.
- Five workflows remain: Docker, manual GET-only HTTP smoke, Auth regression, Pint report, Security scans.
- auth-smoke.yml is workflow_dispatch only and GET-only; no account/token creation or migrations.
- Docker is the full Feature/Integration/runtime gate. Auth regression is a focused overlapping gate; strict Pint is also enforced inside Docker.
- Pint report formats changed files into an artifact; Docker strict Pint is blocking.
- Trivy filesystem findings are report-only; Gitleaks and the fixable HIGH/CRITICAL image gate are blocking.
- Branch API shows main only with protected=false; rulesets endpoint is empty. Branch protection is OPEN for 10B; current repository tools do not expose settings writes.
- No runtime/API/schema change, production DB, secret, deploy, registry publish or AWS resource was touched.
- 10A implementation committed; CI on the new SHA is pending.



## 9. تدقيق 10A وإغلاقها — 24 نوفمبر 2026

- SHA الذي أغلق 10A: 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63.
- Docker [45455970338](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970338): SUCCESS — SQLite 144 / 1,508 assertions؛ PostgreSQL 31 passed / 541 assertions مع اختبار واحد optional skipped؛ S3Mock 1 / 8.
- Authentication [45455970334](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970334): SUCCESS — SQLite 20 / 172؛ PostgreSQL 4 / 99.
- Pint [45455970270](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970270): SUCCESS؛ strict Pint ضمن Docker PASS.
- Security [45455970393](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970393): SUCCESS.
- الـcheck runs الأربعة مكتملة ومرتبطة بالـSHA نفسه؛ لا يوجد فشل غير معالج.
- 10B: main unprotected؛ branch protection وrulesets غير مفعلة. الـPint artifact ليس check حاجزًا مستقلاً، وDocker/Auth لهما تداخل جزئي؛ راجع تقرير Phase10 لقرار الإبقاء المؤقت وتحليل الحماية.


## 13. Phase10C/10D validation — 24 November 2026

- Phase10C migration rehearsal accepted on SHA b8c0bb91cea76db003501e81ec30dce09e3bf858: Docker [45495207983](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207983) SUCCESS — SQLite Feature 146/1,548؛ PostgreSQL 31/541 مع optional skip؛ S3Mock 1/8. Both empty-schema and synthetic legacy snapshot tests passed.
- Authentication [45495207832](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207832) SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Pint [45495207864](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207864) SUCCESS؛ Security [45495208148](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495208148) SUCCESS.
- Phase10D started: contracts/api-v1.json defines 39 API route/method/auth records; contracts/API-V1.md documents consumer-visible behavior; tests/Feature/ApiV1ContractTest.php locks router registration, authentication boundaries and response/error shapes. Pending CI on its own commit.


## 14. Phase10D — API v1 contract regression accepted — 25 November 2026

**Verified commit:** `44ed546c9881bc2d7ca16d6c97024c6ae77ba047`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45503594954](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594954) | SUCCESS — SQLite Feature 150 / 1,704 assertions; PostgreSQL Integration 31 / 541 assertions with one optional skip; S3Mock 1 / 8; non-root runtime/readiness/PHP asset blocking PASS |
| Authentication Regression | [45503594980](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594980) | SUCCESS |
| Pint Format | [45503594941](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594941) | SUCCESS — changed PHP test passed Pint |
| Security Scans | [45503594952](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594952) | SUCCESS — Gitleaks/source scan PASS; Trivy image Clean; fixable HIGH/CRITICAL gate PASS |

- API v1 manifest covers 39 routes. All three ApiV1ContractTest cases passed: router/method/auth boundary; health/billing; registration/profile/preferences/entitlements/wallet/authentication/validation envelopes.
- Historical first attempt on SHA 28b7df1 failed at token regex only: test expected lowercase 64-char hex while Sanctum returned an opaque mixed-case alphanumeric token. Assertion corrected in 44ed546; Docker then reported SQLite Feature 150 passed / 1,704 assertions.
- Current Docker PostgreSQL concurrency sample on the existing 8-worker fixture: 8 successful clients; 4,191 request bytes; 545 response bytes; average 115.311 ms; max 149.676 ms; 6 stale conflict writes / 8 (75%); winning timestamp 5000. Synthetic CI sample only, not production capacity.
- Phase10D COMPLETE. Phase10E begins by enhancing the PostgreSQL synthetic concurrency report with p50/p95, full wall-clock duration and throughput.

No production DB, secrets, deployments, production API writes or AWS resources were used.


## 15. Phase10E closeout / Phase10F start — 25 November 2026

### Phase10E verified on SHA `f912dd2479a5f86471e1e8999b2d36c7611d41cb`

- Docker [45504724906](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724906): SUCCESS — SQLite Feature 150/1,704; PostgreSQL Integration 31/546 with one optional skipped test; S3Mock 1/8; runtime guards PASS.
- Authentication [45504724789](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724789): SUCCESS.
- Pint [45504724822](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724822): SUCCESS.
- Security [45504724860](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724860): SUCCESS — Trivy image Clean; fixable HIGH/CRITICAL gate PASS.
- Synthetic PostgreSQL metrics: 8/8 requests; 188.248ms wall-clock; 42.497 requests/sec; request 4,191 bytes; response 546 bytes; P50 143.150ms; P95 177.715ms; average 143.856ms; max 177.715ms; 5/8 conflicts; winning timestamp 5000.
- 10E COMPLETE. This is an isolated CI baseline, not a production SLO.

### Phase10F implementation started

- The Docker workflow gains post-test evidence packaging for the exact local image already tested by the job.
- A local SHA-specific image tag is checked against embedded `RAVEN23_SOURCE_COMMIT`; Docker config digest and docker-save archive SHA-256 are recorded.
- Trivy generates CycloneDX SBOM from the same local image.
- Provenance JSON records source SHA, workflow run, Dockerfile/Composer input hashes, pinned FrankenPHP base digest, image config digest, archive SHA-256 and SBOM SHA-256.
- `actions/attest@v4` generates signed artifact provenance and SBOM attestations; evidence is uploaded as a commit-specific artifact.
- This is file-based image evidence. No OCI registry manifest digest is claimed, no image is pushed, and packages:write is not requested.
- 10F acceptance is pending CI: all four gates, evidence artifact upload, checksums and both attestation outputs must complete.

No production database, secrets, deployments, registry publishing or AWS resources were used.


## 16. Phase10F closeout / Phase10G rollback workflow — 25 November 2026

### 10F verified SHA \`c01a4bad1e4007dd8c9cb46bb56d5344f188f06c\`

- Docker [45513822710](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710): SUCCESS — SQLite 150/1,704؛ PostgreSQL 31/546 with one optional skip؛ S3Mock 1/8؛ non-root/readiness/least-privilege guards PASS.
- Authentication [45513822753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822753): SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Pint [45513822755](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822755): SUCCESS.
- Security [45513822742](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822742): SUCCESS — Gitleaks/source scans pass; Trivy image summary Clean and fixable HIGH/CRITICAL gate PASS.
- Docker log shows tested image archive and CycloneDX SBOM generation; checksum verification; signed provenance and SBOM attestations; bundle collection; immutable artifact upload.
- Attestations: [provenance #71890026](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890026), [SBOM #71890037](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890037). Artifact [14975768921](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710/artifacts/14975768921), SHA-256 \`f50b53dd43a3c9f261d5da512adf9a005a5e45553870a9447533ecc931b193ed\`.
- 10F COMPLETE. First attempt failed only due relative checksum path, fixed by running sha256sum inside the evidence directory.

### 10G implementation pending its own CI

- New pinned rollback baseline \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\` references the four previously successful CI runs.
- Docker workflow rebuilds that exact source tree and starts current/previous images as disposable non-root containers.
- Loopback-only proxy test expects current route 200, simulated stopped-current route 502, then previous image route 200 after atomic backend switch.
- Release bundle contains both image archives, two CycloneDX SBOMs, four image provenance/SBOM attestations, signed release manifest, manifest attestation, rehearsal report and verified SHA256SUMS.
- This is an isolated rehearsal only; no actual deployment, cloud load balancer, production DB or secrets.


### 10G first-run failure and SBOM generator remediation — SHA \`018edc6b7e5a17d2146982b536743152a6ae440f\`

- Docker [45516843876](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45516843876): FAILED only in rollback rehearsal. Current image SQLite/PG/S3/runtime stages all completed successfully; prior known-good image rebuild completed and its Docker archive was saved.
- Authentication [45516843920](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45516843920), Pint [45516844066](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45516844066), Security [45516843974](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45516843974): SUCCESS. Trivy image report Clean.
- Rollback step stopped while running \`trivy image --format cyclonedx\` against the rebuilt previous image; log showed Debian 13.8 and two language package files, then exit 1 without a useful diagnostic. No HTTP rollback test ran.
- Replaced only rollback SBOM generation with pinned Anchore Syft v1.57.1 scanning the exact Docker archive via \`docker-archive:\`; validate CycloneDX JSON plus a non-empty components array, and record generator/version/component count in build provenance and release manifest.
- The Trivy Security workflow and its blocking vulnerability gate remain unchanged. New SHA CI is required before 10G can close.

## 14. Current main CI / Phase11B1 trust model baseline — 25 November 2026

**Historical trust-model baseline SHA:** `84b87d9e2b746a0fa6c1fca26ae57c02d36d9181`

| Workflow | Run | Result |
|---|---:|---|
| Docker Validation | [45636103294](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103294) | SUCCESS — 150 Feature tests / 1,704 assertions; S3Mock 1 / 8; PostgreSQL/runtime/rollback |
| Authentication Regression | [45636103168](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103168) | SUCCESS |
| Pint Format | [45636103292](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103292) | SUCCESS |
| Security Scans | [45636103186](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103186) | SUCCESS |

- Required-check candidates are Docker Validation, Authentication Regression, and Security Scans. Pint is currently an artifact-producing changed-file report; strict Pint is blocking inside Docker. Manual HTTP smoke is read-only and not a per-commit gate.
- Docker `id-token: write` supports Sigstore attestations through `actions/attest@v4`; no cloud deploy identity, provider role, registry push or deployment workflow is configured.
- main is still `protected=false`; required-check enforcement awaits Repository Admin and an approved merge-control approach.
- No provider, region, cost ceiling, GitHub deployment environment, cloud OIDC policy or paid resource has been selected or created.


## 15. Phase11B1 trust boundary audit — verified baseline 1b57b1d — 25 November 2026

All four workflows on `1b57b1da178dfd993c5f9bc1e2a19136af797f21` completed SUCCESS: Docker #45638169465, Authentication #45638169413, Security #45638169229, Pint #45638169219. This commit was documentation-only; the latest application/runtime baseline remained `84b87d9`.

### Permission and trigger audit

- Docker Validation is triggered by push and pull_request to main plus workflow_dispatch. Its top-level permissions include `contents: read`, `id-token: write`, `attestations: write`, and `artifact-metadata: write`. Build, tests, rollback rehearsal, evidence packaging and all `actions/attest@v4` steps share one job. Attestation steps have no explicit event/ref condition.
- This is broader than the trust design requires. Before 11B2, split validation from release attestation: validation jobs remain read-only; an attestation job runs only after required gates and only for a trusted main release event, with minimum Sigstore permissions.
- Auth, Security and Pint workflows currently request `contents: read` only.
- Do not confuse `id-token: write` used by Sigstore/Fulcio with cloud workload identity. No cloud role, trust policy, deployment workflow or environment exists.

### Meaning of the current gates

- Gitleaks is a blocking working-tree secret scan.
- Trivy filesystem and broad image reports use `--exit-code 0`, so those findings are report evidence and do not independently fail the job.
- A later Trivy step blocks fixable HIGH/CRITICAL vulnerabilities in the production image (`--ignore-unfixed`).
- Pint's separate workflow produces a formatting artifact; the blocking changed-PHP style check is `pint --test` within Docker Validation.
- Manual HTTP smoke is GET-only and does not create accounts, send notifications or run migrations.

### Release evidence identity

Current release evidence binds source commit, local image config digest, Docker archive SHA-256, SBOM SHA-256 and signed attestations/manifest. It explicitly records `registryPush:false`; archive digest, GitHub artifact digest, image config digest and future OCI registry manifest digest are different identifiers. Deployment must consume and verify the exact published OCI manifest digest without rebuilding.

### Remaining controls

- Enable main branch protection/required checks via Repository Admin (10B).
- Move privileged attestation permissions to a narrowly scoped trusted job; never expose deployment credentials to PR code.
- Create separate staging/production environments and distinct short-lived deploy identities only after provider/region/cost approval.
- Keep migration, deploy and runtime identities separate; no production cutover before Phase12 approval and verified backup restore.

No workflow was changed in this design-only audit.

## 16. Phase11B1 trust-boundary remediation — CI verified 26 November 2026

Historical note: the prior section's permission audit describes the state at commit 1b57b1d; the workflow was subsequently changed and is now verified on c17fc84ac0f6acbe4a6d300913871441fb4e7ebe.

- .github/workflows/docker-build.yml: top-level permissions are now contents: read; the docker-build validation job also has contents: read only.
- A separate attest-release job depends on docker-build and is gated to main plus push/workflow_dispatch. Only this job receives id-token: write, attestations: write, and artifact-metadata: write.
- Download action is actions/download-artifact@v8.0.2; upload actions remain actions/upload-artifact@v7.0.2.
- Workflow runs: Docker [45723087433](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433), Auth [45723087469](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087469), Pint [45723087423](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087423), Security [45723087484](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087484) — all SUCCESS on c17fc84ac0f6acbe4a6d300913871441fb4e7ebe.
- Docker validation and trusted signing jobs both succeeded. Signing verified inputs, produced five attestation records (current/rollback provenance and SBOM plus signed release manifest), finalized SHA256SUMS, and uploaded release bundle artifact 15072603777.
- Security's production-image Trivy summary was Clean; Gitleaks and the blocking fixable HIGH/CRITICAL image gate passed.
- No registry publication, cloud OIDC role, staging/production environment, IaC apply or deployment was configured. Branch protection remains external to these workflow changes and 10B remains OPEN.

## 17. مزامنة التشغيلات الحالية على main — 27 نوفمبر 2026

**Verified SHA:** `617ce7efd669b4d3af61c42f77b4cd1f986e3fae`

| Gate | Run | النتيجة |
|---|---:|---|
| Docker Validation | [45936514582](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514582) | SUCCESS — build/test 30/30؛ trusted-main signing 17/17 |
| Authentication Regression | [45936514610](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514610) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99 |
| Security Scans | [45936514543](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514543) | SUCCESS — Gitleaks/Composer/Trivy والبوابة القابلة للإصلاح HIGH/CRITICAL |
| Pint Format Report | [45936514597](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514597) | SUCCESS — خطوات changed-PHP skipped لعدم وجود PHP changes؛ strict Pint داخل Docker نجح |

- Docker suite: SQLite Feature 151/1,719؛ PostgreSQL Integration 31/546 مع optional skip واحد؛ S3Mock 1/8؛ runtime/least-privilege/migration/rollback وsynthetic backup/restore evidence PASS.
- Docker validation job 30/30 steps؛ trusted main evidence signing 17/17 steps، مع current/rollback provenance/SBOM وsigned release manifest.
- هذه النتائج تخص SHA `617ce7e` قبل مزامنة الوثائق الشاملة الحالية؛ التغييرات التي تلي هذا السجل وثائق فقط ولا تغير workflows/app code. يجب رصد تشغيلات الوثائق الجديدة على SHA النهائي قبل اعتبارها CI-verified.
- لا يوجد registry push أو provider deployment/OIDC role أو staging/production workflow. IaC validation فقط local provider-neutral fmt/init/validate/plan بلا backend أو credentials أو apply.
- المستخدم أجل جميع اختيارات provider/region/cost إلى ما بعد أعمال Backend المستقلة عن المزود؛ راجع D-050. لا يُنشأ أي cloud service أو secret أو account الآن.


## Current main CI review — 28 November 2026

All four primary workflows completed successfully on the exact same current main SHA `4bf0535b587aeb6f8e7ca333224d3d8585716714`:

| Workflow | Run | Result and evidence |
|---|---:|---|
| Docker Validation | [#682](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46084785762) | SUCCESS — SQLite 151/1,719; PostgreSQL 31 passed/546 assertions with one optional skip; S3Mock 1/8; encrypted full-schema restore and isolated rollback passed; trusted-main signing job passed. |
| Security Scans | [#428](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46084785665) | SUCCESS — Gitleaks passed; Trivy production-image summary Clean; blocking HIGH/CRITICAL gate passed. |
| Authentication Regression | [#304](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46084785706) | SUCCESS — SQLite 20/172; PostgreSQL 4/99. |
| Pint Format Report | [#426](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46084785634) | SUCCESS — changed-PHP checks were skipped because no PHP files changed; strict Pint in Docker Validation passed. |

IaC #31 remains SUCCESS (30/30 guardrail cases) on `dbd281c545f5a2da5a6816afccebd4d929a02b70`; no IaC paths changed since that run, so it is the latest applicable IaC validation, not a new run on the current documentation-only SHA. The manual GET-only HTTP smoke was not run because there is no approved staging URL. No CI failures remain on the current SHA.

## Current main CI review — 28 November 2026 — SHA `acef3f2a6ce447e45732311efb06b2782fe07790`

| Workflow | Run | Result |
|---|---:|---|
| Docker Validation | [#683](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325) | SUCCESS — SQLite 151/1,719; PostgreSQL 31 passed/546 assertions with one optional skip; S3Mock 1/8; full-schema encrypted PostgreSQL CI restore; isolated rollback; 31/31 steps. |
| Trusted-main signing | Docker run #683 | SUCCESS — 17/17 steps; current/rollback provenance and SBOM attestations plus signed release manifest; bundle [15240108019](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325/artifacts/15240108019). |
| Security Scans | [#429](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608312) | SUCCESS — Gitleaks passed; Trivy production image summary Clean; report [15240027778](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608312/artifacts/15240027778). |
| Authentication Regression | [#305](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608344) | SUCCESS — SQLite 20/172; PostgreSQL 4/99. |
| Pint Format Report | [#427](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608301) | SUCCESS — changed-PHP check skipped because no PHP files changed; strict Pint inside Docker passed. |

Phase12 synthetic restore evidence artifact: [15239713859](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325/artifacts/15239713859). IaC #31 remains latest applicable SUCCESS (30/30) on `dbd281c545f5a2da5a6816afccebd4d929a02b70`; no IaC paths changed. Manual HTTP smoke was not run because there is no approved staging URL.
## Current main CI review — 28 November 2026 — SHA `b3e73dbf2b3d7943c1f297fb8daccbee93cc07fd`

| Workflow | Run | Result |
|---|---:|---|
| Docker Validation | [#684](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352) | SUCCESS — SQLite 151/1,719; PostgreSQL 31 passed/546 assertions with one optional skip; S3Mock 1/8; full-schema encrypted restore and isolated rollback passed; build/test 31/31 steps. |
| Trusted-main signing | Docker run #684 | SUCCESS — 17/17 steps; current/rollback provenance and SBOM attestations plus signed release manifest; bundle [15243417105](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352/artifacts/15243417105). |
| Security Scans | [#430](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893380) | SUCCESS — Gitleaks passed; Trivy production-image summary Clean; report [15243530366](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893380/artifacts/15243530366). |
| Authentication Regression | [#306](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893420) | SUCCESS — SQLite 20/172; PostgreSQL 4/99. |
| Pint Format Report | [#428](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893363) | SUCCESS — changed-PHP check skipped because no PHP files changed; strict Pint inside Docker passed. |

Phase12 synthetic restore artifact: [15242412617](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352/artifacts/15242412617). IaC #31 remains latest applicable SUCCESS (30/30) on `dbd281c545f5a2da5a6816afccebd4d929a02b70`; no IaC paths changed. HTTP smoke remains manual/not run because no approved staging URL.

## Latest docs-only verification — 28 November 2026

On main SHA `7070350759253c4b245d2ec31992c9233c52f68c`:
- Docker Validation [#685](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597232): SUCCESS; SQLite 151 tests/1,719 assertions, PostgreSQL 31 tests/546 assertions with one optional skip, S3Mock 1/8, encrypted full-schema CI restore, isolated rollback, trusted-main signing 17/17.
- Security [#431](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597212): SUCCESS; Gitleaks and filesystem/image scan steps passed.
- Authentication [#307](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597361): SUCCESS; SQLite 20/172 and PostgreSQL 4/99.
- Pint [#429](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597204): SUCCESS; changed-PHP steps skipped because no PHP files changed; strict Pint in Docker passed.

Docker artifacts: release bundle [15242974549](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597232/artifacts/15242974549), synthetic restore report [15243077633](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597232/artifacts/15243077633). Security reports: [15243606410](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46095597212/artifacts/15243606410). These are CI artifacts only, not production backup evidence.

## Current verification — 28 November 2026

On `be14f83568e5abd51a9628d21b4e8a0c55f23d13`:
- Docker Validation [#686](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471248): SUCCESS — SQLite 151/1,719; PostgreSQL 31/546 with one optional skip; S3Mock 1/8; encrypted full-schema CI restore; isolated rollback; trusted-main signing 17/17.
- Security [#432](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471240): SUCCESS — Gitleaks, filesystem scan, production-image scan and vulnerability gate.
- Authentication [#308](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471252): SUCCESS — SQLite 20/172 and PostgreSQL 4/99.
- Pint [#430](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471224): SUCCESS; changed-PHP formatter steps skipped because no PHP files changed; strict Pint inside Docker passed.

Artifacts: release bundle [15245482428](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471248/artifacts/15245482428), Phase12 synthetic restore [15246550109](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471248/artifacts/15246550109), Security reports [15243606410](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46101471240/artifacts/15243606410). All are CI-only evidence, not production backup or cutover evidence.

## Latest main verification — 28 November 2026

On `72cebe78fb436a6036b7144ff3b0c31e542204a0`:
- Docker Validation [#687](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46106501491): SUCCESS — 31/31 validation steps; SQLite 151/1,719; PostgreSQL 31/546 with one optional skip; S3Mock 1/8; encrypted full-schema CI restore; isolated rollback; trusted-main signing 17/17.
- Security [#433](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46106501367): SUCCESS — 12/12 steps including Gitleaks, filesystem and production-image vulnerability scan.
- Authentication [#309](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46106501365): SUCCESS — 12/12 steps; SQLite 20/172 and PostgreSQL 4/99.
- Pint [#431](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46106501529): SUCCESS; changed-PHP steps skipped as no PHP files changed; strict Pint in Docker passed.

These workflows validate the backend code and disposable CI storage. They do not inventory, back up, or restore real private media objects.
