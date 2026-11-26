# المرحلة 10 — الجودة والاختبارات وهندسة الإصدارات

**الحالة:** IN_PROGRESS — 10A/10C/10D/10E/10F/10G COMPLETE؛ 10B DEFERRED_BY_OWNER إلى ما بعد اكتمال خطة تطوير Backend لتنفيذ إعداد الحماية يدوياً.  
**الاعتماديات:** 01–09 تدريجيًا على مستوى domain/code/CI؛ بوابة الإصدار النهائية تبقى مشروطة بجاهزية النطاقات المعتمدة، مع إبقاء اعتمادات Phase08/09 الخارجية صريحة.

## 10A — جرد CI وسلامة workflows

- جرد workflows من شجرة Git ومقارنة inventory مع الملفات الموجودة.
- إزالة bootstrap workflow انتهت مهمته وتملك صلاحيات كتابة غير لازمة؛ حزمة S3 مثبتة بالفعل في Composer lock.
- توثيق triggers والـpermissions والتداخل بين بوابات الاختبار.
- التحقق من حماية main وrequired checks؛ المرصود قبل هذه الشريحة: main فقط، protected=false ولا توجد rulesets.
- إعدادات الحماية تحتاج صلاحيات إدارة GitHub غير متاحة من إجراءات المستودع الحالية؛ تبقى OPEN ولا يُدعى أنها فُعّلت.
- أغلقت 10A بعد نجاح البوابات الأربع على commit 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63.

### إثبات إغلاق 10A — 24 نوفمبر 2026

| البوابة | التشغيل | النتيجة |
|---|---:|---|
| Docker / SQLite + PostgreSQL + S3Mock | [45455970338](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970338) | SUCCESS — SQLite Feature 144 / 1,508 assertions؛ PostgreSQL Integration 31 passed / 541 assertions، واختبار S3 optional واحد skipped؛ S3Mock منفصل 1 / 8 |
| Authentication Regression | [45455970334](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970334) | SUCCESS — SQLite 20 / 172 assertions؛ PostgreSQL 4 / 99 |
| Pint | [45455970270](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970270) | SUCCESS؛ لا تغييرات PHP في commit 10A فتخطت خطوات changed-file format؛ strict Pint داخل Docker نجح |
| Security | [45455970393](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970393) | SUCCESS — Gitleaks وTrivy تقارير filesystem/image؛ بوابة fixable HIGH/CRITICAL للصورة نجحت |

كل check run الأربع أُسندت إلى SHA 10A نفسه. لم يُسجل فشل أو تعديل runtime/API/schema في هذه الشريحة.

## 10B — required checks وحماية main وتقييم التداخل

**الحالة:** DEFERRED_BY_OWNER — بناءً على توجيه 26 نوفمبر 2026، يؤجل مالك المشروع إعداد branch protection وrequired checks يدوياً إلى ما بعد اكتمال خطة تطوير Backend بالكامل.

- آخر تحقق موثق: `main.protected=false`، ولا توجد Rulesets أو required status checks مفعّلة.
- هذا التأجيل إداري ومقصود، وليس فشلاً في workflows أو عيباً في كود Backend. لا يمنع تنفيذ الميزات أو migrations أو اختبارات regression أو CI أثناء التطوير.
- لا يُدعى أن `main` محمي حالياً؛ تشغيل CI بعد push لا يمنع التحديث قبل الاختبار.
- عند التنفيذ اليدوي، الفحوص الحاجزة المرشحة هي: `Build and test portable Laravel runtime`، و`Email, password, Google verifier and guest authentication`، و`Secret, dependency, filesystem and image scans`.
- فحص Pint الصارم داخل Docker هو بوابة التنسيق؛ workflow Pint المستقل ينشئ تقرير/Artifact ولا يصنف required check مستقلاً.
- يجب حسم نموذج تحديث محمي متوافق مع سياسة main-only. Required checks قبل الدمج تتطلب PR/merge flow أو آلية محمية مكافئة؛ direct push وحده لا يحقق pre-merge gate.
- خطوات الإعداد واختبار enforcement محفوظة في `phases/reports/POST-PLAN-MANUAL-BRANCH-PROTECTION-SETUP.md`.

**قرار 10B:** الاستمرار في تطوير Backend وتخطي الإعداد اليدوي الآن. تبقى 10B غير مكتملة حتى تنفيذ إعداد GitHub والتحقق منه بعد إكمال الخطة، وتظل شرطاً قبل Phase11B2 وقبل اعتماد مسار النشر إلى Staging/Production.

## 10C — migration rehearsal

**الحالة:** COMPLETE — نجح اختبار مستقل على SQLite لقاعدة فارغة وsnapshot حسابات/جلسات/queue اصطناعي على SHA b8c0bb91cea76db003501e81ec30dce09e3bf858.

- يتأكد الاختبار من تطبيق جميع ملفات migrations من الصفر وعدّ سجلات جدول migrations وموجودية الجداول الأساسية.
- كشفت أول دفعة CI أن username المولد للحساب القديم يضاف إليه base-36 id؛ صُحح assertion من legacy_migration_user إلى legacy_migration_user_16 وفق سلوك Raven23AuthSchema::generatedUsername()، وصُحح توقع البريد ليطابق fixture. نجحت إعادة CI على SHA التصحيح.
- يعيد تشغيل السلسلة على schema قديم اصطناعي يحتوي حسابًا قديمًا وSanctum token وcache row وqueued job؛ ويتحقق من بقاء البيانات وbackfill للملف الشخصي والتفضيلات والجهاز.
- هذا ليس dump من الإنتاج، ولا يبرر تشغيل migration على قاعدة إنتاج.
- Docker Feature: 146 passed / 1,548 assertions؛ PostgreSQL Integration: 31 passed / 541 assertions مع اختبار optional واحد skipped؛ S3Mock المنفصل: 1 passed / 8 assertions.
- فحص Authentication: SQLite 20 / 172 assertions؛ PostgreSQL 4 / 99. Docker وPint وSecurity وAuthentication كلها SUCCESS على SHA نفسه.
- اختبارا empty schema وlegacy snapshot نجحا: جميع migrations سُجلت، الجداول الحالية ظهرت، وبقي الحساب وSanctum token وcache entry وqueue job محفوظة مع backfill للملف الشخصي والتفضيلات والجهاز.
- PostgreSQL fresh migration مغطى في Docker؛ توسيع synthetic legacy rehearsal إلى PostgreSQL يبقى تحسينًا لاحقًا إذا أظهرت نتائج الاختبار حاجة لذلك.

## 10D — API v1 contract regression

**الحالة:** COMPLETE — manifest آلي ووثيقة عقد واختبارات regression اجتازت بوابات CI الأربع على SHA 44ed546.

- contracts/api-v1.json يسجل 39 مسارًا مع HTTP method وحدود Sanctum والغرض العام لكل route.
- contracts/API-V1.md يوثق قواعد JSON و401/422 وأشكال الاستجابات الأساسية للمصادقة والصحة والخطط والملف الشخصي والتفضيلات والاستحقاقات والمحفظة والمزامنة.
- ApiV1ContractTest يقارن كل route manifest مع Laravel Router، ويثبت حماية Sanctum، ويختبر الأشكال الأساسية واستجابات التحقق.
- لم تتغير controllers أو routes أو migrations؛ هذه الشريحة توثيق واختبارات عقدية فقط. لا يتضمن manifest أسرارًا أو بيانات مستخدمين أو عنوان نشر خاصًا بمزود.
- قبول 10D يتطلب نجاح البوابات الأربع على SHA الذي يحتوي الاختبارات.


## 10D — نتائج الإغلاق على SHA 44ed546

- Docker [45503594954](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594954): SUCCESS — SQLite Feature 150 / 1,704 assertions؛ PostgreSQL Integration 31 / 541 assertions مع اختبار optional واحد skipped؛ S3Mock 1 / 8؛ HTTP non-root/readiness/PHP asset blocking PASS.
- Authentication [45503594980](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594980): SUCCESS. Pint [45503594941](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594941): SUCCESS. Security [45503594952](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594952): SUCCESS — Gitleaks/source scan PASS؛ Trivy image summary Clean؛ بوابة fixable HIGH/CRITICAL PASS.
- اجتازت اختبارات ApiV1ContractTest الثلاثة: manifest/method/auth boundary، health/billing response shapes، registration/profile/preferences/entitlements/wallet/protected 401/validation 422.
- فشل التشغيل الأول على SHA 28b7df1 بسبب regex افترض lowercase hex بطول 64؛ رمز Sanctum الفعلي opaque alphanumeric. أُصلح assertion في SHA 44ed546 إلى معرّف رقمي وقيمة أبجدية رقمية بطول 40–64، دون تغيير كود المصادقة.

## 10E — synthetic PostgreSQL load baseline

**الحالة:** COMPLETE على SHA `f912dd2479a5f86471e1e8999b2d36c7611d41cb` بعد نجاح بوابات CI الأربع وظهور المقاييس الجديدة في Docker log.

- الاختبار يستخدم ثمانية عمال PHP مستقلين، ولكل عامل Sanctum token واتصال PostgreSQL مستقل، مع حاجز إطلاق مشترك؛ PostgreSQL disposable داخل GitHub Actions فقط.
- Docker [45504724906](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724906): SUCCESS — SQLite Feature 150 / 1,704 assertions؛ PostgreSQL Integration 31 passed / 546 assertions مع اختبار optional واحد skipped؛ S3Mock 1 / 8؛ runtime guards PASS.
- Authentication [45504724789](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724789): SUCCESS؛ Pint [45504724822](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724822): SUCCESS؛ Security [45504724860](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724860): SUCCESS — Trivy production image Clean وبوابة HIGH/CRITICAL PASS.
- القياس الفعلي: 8/8 طلبات ناجحة؛ wall-clock 188.248 ms؛ throughput 42.497 requests/sec؛ request bytes 4,191؛ response bytes 546؛ P50 143.150 ms؛ P95 177.715 ms؛ average 143.856 ms؛ max 177.715 ms؛ stale conflicts 5/8 (62.5%)؛ winning timestamp 5000.
- حساب P50/P95 يستخدم nearest-rank؛ النتيجة تخص عينة تزامن اصطناعية صغيرة ولا تمثل production capacity أو SLO.
- لا يوجد latency threshold ثابت؛ القبول هو نجاح جميع العمال، سلامة aggregate، فوز timestamp الأعلى، وحساب القياسات الجديدة بنجاح.

## 10F — immutable tested-image evidence, SBOM and provenance

**الحالة:** COMPLETE على SHA `c01a4bad1e4007dd8c9cb46bb56d5344f188f06c` — اجتازت بوابات CI الأربع، ونجح الأرشيف وCycloneDX SBOM وفحص SHA-256 وتوقيعا provenance/SBOM ورفع artifact المرتبط بالـSHA.

- يحافظ Docker validation على الصورة نفسها بعد الاختبارات، ويضيف لها local tag خاصاً بالـSHA: `raven23-backend:sha-<GITHUB_SHA>`.
- يسجل `docker image inspect .Id` باعتباره Docker image config digest، لا registry manifest digest؛ ويتحقق من تطابق `RAVEN23_SOURCE_COMMIT` داخل الصورة مع SHA الحالي.
- يصدر Docker archive للصورة المختبرة ويحسب SHA-256 للملف؛ كما يولد CycloneDX SBOM من الصورة المحلية نفسها عبر Trivy.
- يبني provenance JSON يربط source SHA وworkflow run وDockerfile وComposer manifest/lock وFrankenPHP base digest مع config digest وarchive SHA-256 وSBOM SHA-256.
- يستخدم GitHub Actions signed artifact attestations لإثبات provenance ولإرفاق SBOM بالـarchive، ويحفظ bundles في artifact لمدة 30 يوماً. لا توجد صلاحية packages:write ولا push إلى Registry.
- لأن المشروع لا ينشر صورة Registry في هذا workflow، لا يتم الادعاء بوجود OCI manifest digest؛ الـconfig digest وarchive SHA-256 يحددان الصورة/الحزمة المحلية بدقة.
- القبول يتطلب نجاح بوابات CI الأربع، ظهور artifact بكل الملفات المطلوبة، وصحة SHA-256 والتحقق من شهادتي provenance/SBOM.

## 10G — release manifest and isolated rollback rehearsal

**الحالة:** COMPLETE على SHA `a86fb3abd747a4110caa42b105dbb290b89f9e23` بعد نجاح بوابات CI الأربع وإثبات rollback وتوقيع manifest ورفع حزمة الأدلة.

### إثبات إغلاق 10G — 25 نوفمبر 2026

| البوابة | التشغيل | النتيجة |
|---|---:|---|
| Docker Validation | [45625075036](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036) | SUCCESS — runtime وSQLite وPostgreSQL وS3Mock وrollback وrelease evidence PASS |
| Authentication Regression | [45625075096](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075096) | SUCCESS |
| Pint Format | [45625075137](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075137) | SUCCESS |
| Security Scans | [45625075229](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075229) | SUCCESS — Gitleaks وTrivy وعتبة HIGH/CRITICAL PASS |

- البوابات الأربع جميعها مرتبطة بالـSHA نفسه `a86fb3a`.
- أثبت proxy loopback التسلسل `HTTP 200 → HTTP 502 → HTTP 200`: النسخة الحالية، إيقافها عمدًا، ثم تحويل المرور إلى rollback target السابق.
- أُعيد بناء rollback commit `f912dd2479a5f86471e1e8999b2d36c7611d41cb`. Syft v1.57.1 تحقق من CycloneDX SBOM يحتوي 5,720 مكوّناً.
- current image config digest: `sha256:8b1649e89c3feccb9f01124b2ac83abe0d4796442208df20cee18be5169e9b02`; archive SHA-256: `1f9347f89226634824c23cda2d6064ba15f2f98747b964aa77337efd06a22773`; SBOM SHA-256: `ecd6913ec2b92c166863ec9811aacdc657179397b352e8c9bb7fe26a28fde860`.
- rollback image config digest: `sha256:a736995b08c01b2feb7eaefeb462c6d12bf528b18cc2cbaecb0f2c3be03180f0`; archive SHA-256: `1647484f8bc7c4376a915aa48951adf2c1cc1f86490a1a2b3c50d65ae2ec92d6`; SBOM SHA-256: `3fab41bb3f765e11b23c8b13037ca50b2212e5cc64281c03b79aed52988d16e3`.
- Sigstore: [current image provenance](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175380)، [current image SBOM](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175401)، [rollback provenance](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175434)، [rollback SBOM](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175458)، و[release manifest signature](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175490).
- manifest SHA-256: `bb0d5d99b10f8f661d2b7b602b90dc55d37ba0af056608486a6e09e6bf5f649c`. اجتازت جميع ملفات SHA256SUMS الفحص.
- حزمة الإصدار [Artifact 15026689226](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036/artifacts/15026689226)، الحجم 562,608,814 bytes، digest `sha256:8b0a10585fc6f7542660e1cedb8ecb362b4b86f625a09ef7a73aed7213f9162c`، واحتفاظ 30 يوماً.
- أصلح commit `a86fb3a` إخراج ملخص Bash باستخدام `printf` بدلاً من backtick command substitution؛ لم تظهر رسائل `command not found` بعد الإصلاح.

**حدود الإثبات:** هذه تجربة حاويات وproxy على loopback وليست اختبار cloud load balancer أو production deployment. لم يحدث registry push أو deployment أو وصول إلى قاعدة/أسرار الإنتاج. تبقى 10B مفتوحة لأن branch API يؤكد `protected=false` ولا تتوفر صلاحية Admin.



- يثبت \`phases/release/rollback-target.json\` آخر إصدار سابق اجتاز بوابات CI الأربع على SHA \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\` وروابط التشغيلات الأربع.
- يعيد workflow بناء هذا الإصدار من شجرة Git عند SHA نفسه داخل مساحة CI مؤقتة، دون إنشاء فرع أو تعديل شجرة main.
- يشغّل الصورة الحالية والصورة السابقة في حاويتين منفصلتين non-root؛ readiness المتوقع 503 لعدم وجود قاعدة بيانات، وliveness يجب أن يرجع 200.
- يستخدم HTTP proxy مربوطًا على loopback فقط ويغيّر backend عبر ملف منفذ يُستبدل ذريًا. يوقف الحاوية الحالية عمدًا، ويتحقق من 502 قبل التحويل، ثم يثبت أن الطلب يعود 200 من حاوية الإصدار السابق.
- يحفظ أرشيف صورة rollback وCycloneDX SBOM المولّد بواسطة Anchore Syft من الأرشيف نفسه، مع provenance وسجل التجربة؛ يوقع أرشيف rollback وشهادة SBOM، إضافة إلى توقيع release manifest الذي يربط الصورتين وSHA-256 وروابط CI/attestations. يظل Trivy مسؤولًا عن فحوص الثغرات المستقلة ولا يتغير نطاقه.
- لا يستخدم PostgreSQL أو secrets الإنتاج، ولا ينشر صورة إلى registry ولا ينفذ deployment. اختبار proxy محلي معزول وليس اختبار load balancer فعلي في بيئة cloud.
- القبول: نجاح البوابات الأربع، نجاح فحص البصمات، تحقق CycloneDX من وجود components، نجاح تحويل المسار 200→502→200 إلى rollback target، ظهور release bundle وattestations الأربعة وmanifest attestation.
- محاولة 10G الأولى على SHA 018edc6 أعادت بناء صورة rollback وصدّرت Docker archive بنجاح، لكن Trivy 0.76.2 خرج برمز 1 أثناء توليد SBOM دون مخرجات خطأ تشخيصية بعد اكتشاف Debian 13.8 وملفي حزم لغوية؛ لم تبدأ حاويات rollback ولا اختبار traffic switch. استبدلنا مولّد SBOM لهذا الأرشيف فقط بـSyft مثبت الإصدار، مع تحقق صريح من CycloneDX وعدد المكونات. فحص Trivy الأمني للصورة في Security workflow بقي كما هو واجتاز.

## الشرائح التالية

- 10B: required checks وحماية main وتقييم تداخل workflows؛ ما زالت تنتظر صلاحيات Admin.
- 10C COMPLETE: migrations على schema فارغ وsnapshot اصطناعي سابق.
- 10D COMPLETE: API v1 contract regression verified on SHA 44ed546.
- 10E COMPLETE: synthetic PostgreSQL load baseline verified on SHA f912dd2.
- 10F COMPLETE: immutable tested-image archive, CycloneDX SBOM, signed provenance/SBOM attestations على SHA c01a4bad.
- 10G COMPLETE على SHA `a86fb3a`: release manifest and isolated rollback rehearsal verified؛ الأدلة أعلاه.

## الهدف
منع regressions، والتأكد من أن كل commit يمر بسلسلة اختبارات واضحة وأن كل إصدار يمكن تتبعه والرجوع عنه.

## العمل
1. توحيد workflows المكررة وإزالة retry files المتشابهة بعد نقل وظائفها وعدم فقد أي تحقق مفيد.
2. تقسيم CI إلى lint/static/dependency/test/build/container/security/contract jobs.
3. تشغيل SQLite لاختبارات سريعة فقط، وPostgreSQL disposable لكل migrations/integration.
4. اختبار migration من schema فارغ ومن snapshot اصطناعي قريب من schema السابق.
5. API contract tests تغطي Android clients والمسارات والحقول والأخطاء.
6. عدم استخدام production DB لأي اختبار CI.
7. اختبارات تحميل/ضغط تدريجية مع أهداف مبنية على الاستخدام المتوقع.
8. اختبار Docker image من حيث user permissions، healthcheck، startup، signals، secrets والنسخ.
9. اعتماد immutable image tags/digests وربط الإصدار بـ Git SHA.
10. إعداد قواعد main branch/PR checks/reviews وdeployment environments.
11. تنفيذ security scans وإدارة CVEs حسب severity وخطة تصحيح.
12. حفظ artifacts/logs المهمة دون أي بيانات شخصية أو أسرار.

## معايير القبول
- جميع workflows موحدة ومفهومة ولا توجد triggers مخادعة أو شروط لا تعمل.
- كل SHA مرشح للإصدار يمر بجميع required checks.
- توجد نتائج migrations وPostgreSQL integration وAPI contract.
- image التي تُنشر هي نفسها التي تم اختبارها (digest).
- يوجد rollback إلى image سابقة موثقة.
- تقارير الفشل تحدد root cause ولا تخفي الفشل بإعادة محاولات عشوائية.

## ملاحظة
لا تعني هذه المرحلة أن الميزات المكتوبة في الخطة كلها موجودة تلقائيًا؛ يجب أن تكون scope phase feature مكتملة قبل إدخالها في release gate.
