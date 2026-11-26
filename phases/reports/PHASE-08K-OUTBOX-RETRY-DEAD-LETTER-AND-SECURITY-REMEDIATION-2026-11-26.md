# تقرير Phase08K — queue retry/dead-letter وSymfony security remediation

- **المستودع:** `m5ham3ds/RAVEN23-BACKEND`
- **الفرع:** `main` فقط
- **الـbaseline الذي اجتاز بوابات CI الأربع:** `1e79bb3591ca721fb824c4b498dbef5990c145aa`
- **التاريخ:** 26 نوفمبر 2026
- **حالة Phase08:** IN_PROGRESS؛ شريحة 08K COMPLETE، بينما تبقى تكاملات المزود والسياسات التجارية/التشغيلية معلقة.

## 1. الاستئناف دون إعادة العمل

استؤنف التنفيذ من الشجرة التي كان رأسها `abe1b8a` بعد التحقق من phases والتقارير. لم تُعد شرائح Phase08G–08J أو Phase09 أو Phase10 أو Phase11. أُضيفت فقط تغطية ناقصة لمسار العامل عند استنفاد retries، ثم عولجت advisories التي ظهرت خلال Composer audit.

## 2. 08K — worker retry and dead-letter

أضيف:
- `tests/Fixtures/TransientFailingNotificationOutboxTransportFake.php`
- اختبار `test_database_worker_retries_transient_outbox_delivery_failures_and_dead_letters_after_three_exceptions` داخل `tests/Feature/QueuedNotificationsTest.php`.

يستخدم الاختبار database queue وLaravel `queue:work --once` فعليًا مع fake transport يرفض التسليم كل مرة. ويتحقق من:
1. سجل outbox يبدأ pending وattempts=0.
2. بعد أول وثاني إخفاق يبقى pending مع زيادة attempts.
3. يعاد تشغيل العامل بعد backoff دون إدخال sleep فعلي في CI.
4. بعد ثلاثة استثناءات يصبح outbox status=failed ويُزال job من queue وتظهر terminal failure في `failed_jobs`.
5. idempotency key يبقى ثابتًا بين المحاولات الثلاث.
6. failed-job payload لا يحتوي token plaintext.

**نتائج الشريحة:**
- focused outbox suite: **16 passed / 119 assertions**.
- full SQLite Feature suite: **151 passed / 1,719 assertions**.
- PostgreSQL Integration: **31 passed / 546 assertions**، مع optional skip واحد.
- S3Mock: **1 passed / 8 assertions**.
- Authentication: SQLite **20 / 172**؛ PostgreSQL **4 / 99**.

الاختبار لا يستخدم SMTP أو FCM أو OneSignal ولا يثبت وصول إشعار خارجي أو exactly-once عند مزود حقيقي.

## 3. تشخيص وإصلاح إخفاقات محاولات CI

- محاولة وسطية فشلت لأن fixture أُنشئ بتهريب namespace غير صحيح، فلم يطابق PSR-4. أُصلح namespace والاستيراد ومرجع `Throwable` قبل اعتماد النسخة النهائية.
- بعد التصحيح فشلت Docker/Security/Pint على commit وسيط لأن `composer audit` كشف خمسة advisories عبر Symfony HTTP Kernel/Mailer/Routing والعائلة المرتبطة.
- لم يتم تعطيل Composer audit أو تخطي Security gate.

## 4. Symfony security remediation

تم تحديث سبعة entries في `composer.lock`؛ أهم الحزم المتأثرة:
- `symfony/http-foundation` → `v8.1.12`
- `symfony/http-kernel` → `v8.1.12`
- `symfony/mailer` → `v8.1.12`
- `symfony/mime` → `v8.1.12`
- `symfony/routing` → `v8.1.12`

النتيجة من workflow المؤقت للتحقق الأمني: `composer audit` — **No security vulnerability advisories found**. نجح كذلك focused test suite وPint `--test` للملفين المعدّلين.

## 5. CI evidence على SHA `1e79bb3`

| البوابة | Run | النتيجة |
|---|---:|---|
| Docker Validation | [45850009753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850009753) | SUCCESS — SQLite 151/1,719؛ PostgreSQL 31/546؛ S3Mock 1/8؛ runtime/role/worker guards PASS |
| Authentication Regression | [45850013586](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850013586) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99 |
| Pint | [45850021125](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850021125) | SUCCESS؛ والملفات المعدّلة اجتازت Pint `--test` في focused remediation job |
| Security Scans | [45850017359](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850017359) | SUCCESS — Gitleaks/Trivy؛ production image summary Clean |

## 6. Rollback/release evidence boundary

كان تشغيل Docker على baseline `1e79bb3` تشغيل `workflow_dispatch` مع تخطي rollback والتوقيع بشكل مؤقت ومقصود فقط لأن target السابق `f912dd2` يحمل lock قديمًا. نجحت جميع اختبارات صورة baseline، لكن ذلك التشغيل **لم** ينشئ rollback rehearsal أو release bundle أو attestations.

بعد تثبيت baseline:
- أُعيد تعيين `phases/release/rollback-target.json` إلى `1e79bb3`.
- أُزيل خيار skip المؤقت بالكامل من Docker workflow.
- يعيد commit الذي يحتوي هذه التغييرات المسار الكامل: rebuild rollback target، إثبات loopback HTTP `200 → 502 → 200`، current/rollback archives وSBOMs وprovenance، release manifest والتوقيعات وartifact. لا تُعتبر هذه الخطوة مكتملة حتى نجاح التشغيل الجديد.

## 7. ما لم يتغير

- لا تعديل على Laravel API أو schema أو runtime behavior؛ شريحة 08K تضيف اختبارًا وfixture.
- لا provider selection أو credentials أو real mail/push delivery.
- لم تُنشأ موارد مدفوعة أو حسابات staging، ولم تُنشر صورة إلى registry.
- لا production DB/secrets أو DNS أو deploy أو OpenTofu apply.
- تظل Phase08 IN_PROGRESS للـS3/mail/Push staging وسياسات retention/capacity/cost.
- تظل Phase10B DEFERRED_BY_OWNER، وPhase11C تحتاج provider/region/workload/cost inputs.

**الحكم:** 08K اجتازت التنفيذ والاختبارات. لا يُعاد تنفيذ هذه الشريحة؛ نقطة الاستئناف التالية هي التحقق من تشغيل release/rollback الكامل على commit الذي يعيد المسار الاعتيادي.
