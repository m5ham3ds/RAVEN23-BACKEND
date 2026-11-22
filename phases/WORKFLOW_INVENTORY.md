# جرد GitHub Actions وعمليات النشر

**Backend SHA المراجع:** 8219a5833c7a322c00d53bdebe8c4b30cfad3218  
**تاريخ التحديث:** 23 نوفمبر 2026 — سجل 21 نوفمبر تاريخي  
**الحالة:** COMPLETE على مستوى source/workflow inventory؛ لم تُنفذ workflows التي تكتب على الإنتاج.

## 1. ملفات workflows

| الملف | الاسم في GitHub Actions | Trigger | طبيعة العمل / ملاحظات |
|---|---|---|---|
| docker-build.yml | RAVEN23 Backend Docker Validation | push وpull_request إلى main | يبني الصورة، Feature tests على SQLite، ويبني PostgreSQL test image ويشغل migrations/integration tests على PostgreSQL 16 مؤقتة |
| auth-smoke.yml | RAVEN23 Live Authentication Smoke | push main مع paths-ignore، وworkflow_dispatch | يستيقظ Render ويفحص health/DB/schema/commit/routes بطلبات GET؛ شرط job الحالي يسمح push فقط، ولذلك manual dispatch لا يشغل المهمة |
| production-account-retry.yml | RAVEN23 Production Registration Retry | تعديل الملف نفسه على main | ينتظر Render ثم ينشئ حسابًا اصطناعيًا ويسجل دخوله ويمسحه من production |
| production-account-retry-2.yml | RAVEN23 Production Account Retry 2 | تعديل الملف نفسه على main | نسخة قريبة من اختبار account lifecycle الإنتاجي |
| production-account-retry-3.yml | RAVEN23 Production Account Retry 3 | تعديل الملف نفسه على main | نسخة قريبة من اختبار account lifecycle الإنتاجي |
| production-account-retry-4.yml | RAVEN23 Production Account Retry 6 | تعديل الملف نفسه على main | نسخة قريبة؛ الاسم يوحي برقم retry غير مطابق للملف |
| production-account-retry-7.yml | RAVEN23 Production Account Retry 6 | تعديل الملف نفسه على main | اسم workflow مكرر مع retry-4 رغم اختلاف اسم الملف |
| production-account-smoke.yml | RAVEN23 Production Account Creation Test | تعديل الملف نفسه على main | ينشئ حساب إنتاج اصطناعيًا ويجرب دورة الحياة ويحاول التنظيف؛ إذا فشل cleanup يوقف المهمة بخطر بقاء الحساب |
| production-registration-route-probe.yml | RAVEN23 Production Registration Route Probe | تعديل الملف نفسه على main | يختبر route validation عبر payload فارغ، ويتوقع 422؛ لا ينشئ حسابًا في المسار الرئيسي |
| pint-format-report.yml | RAVEN23 Pint Format Report | push إلى الفروع الثلاثة | Laravel Pint للملفات المعدلة |\n| security-scans.yml | RAVEN23 Security Scans | push إلى الفروع الثلاثة | Gitleaks وTrivy filesystem/image وبوابات الثغرات |\n| users-auth-tests.yml | RAVEN23 USERS Authentication Tests | push إلى RAVEN23-BACKEND-USERS | اختبارات PostgreSQL/SQLite الخاصة بنطاق الحسابات |

## 2. نتائج CI المرصودة

### أحدث خط أساس على main

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


## 5. تحديث ملفات workflows وحالة التشغيل — 23 نوفمبر 2026

العدد الحالي على main هو 12 workflow:
- `auth-smoke.yml`
- `docker-build.yml`
- `pint-format-report.yml`
- `security-scans.yml`
- `users-auth-tests.yml`
- `production-account-retry.yml`
- `production-account-retry-2.yml`
- `production-account-retry-3.yml`
- `production-account-retry-4.yml`
- `production-account-retry-7.yml`
- `production-account-smoke.yml`
- `production-registration-route-probe.yml`

`runtime-db-diagnostics.yml` لم يعد في Git tree الحالي. ما تزال نسخ production-account retry المتعددة موجودة؛ توحيدها مهمة Phase10، ولا تُحذف أو تُشغّل ضمن Phase06 دون تصريح مناسب.

### أحدث نتائج الفروع الثلاثة بعد المزامنة وإعادة المحاولة
- main `8219a5833c7a322c00d53bdebe8c4b30cfad3218`: Docker #352، Pint #85، Security #87 — conclusion failure، لكن لا توجد steps منفذة/معروضة وسجلات الوظائف غير متاحة.
- TEST `08f5e05fec98d19cf3d18a0b2993352680952f2b`: Docker #351، Pint #84، Security #86 — النتيجة نفسها.
- هذه ليست إثباتًا أن اختبارات PHP فشلت؛ كما أنها ليست نجاحًا. لا توجد بوابة CI معتمدة للـSHA الحالي.
- USERS `b0fd200...`: Auth #4 / Pint #80 / Security #82 SUCCESS على SHA قديم فقط.

لم يتم تشغيل workflows التي تكتب على الإنتاج، ولم يتم قراءة secrets أو تعديل قاعدة الإنتاج.
