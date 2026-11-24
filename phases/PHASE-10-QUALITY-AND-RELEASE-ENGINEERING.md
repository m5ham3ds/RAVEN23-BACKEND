# المرحلة 10 — الجودة والاختبارات وهندسة الإصدارات

**الحالة:** IN_PROGRESS — 10A COMPLETE؛ 10B IN_PROGRESS لأن حماية main تتطلب صلاحيات Admin؛ 10C COMPLETE على SHA b8c0bb9؛ 10D COMPLETE على SHA 44ed546؛ 10E IN_PROGRESS لقياس الحمل الاصطناعي.  
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

**الحالة:** IN_PROGRESS — اكتمل تدقيق جهة المستودع والـworkflows؛ تفعيل branch protection/required checks ينتظر صلاحيات Admin.

- GitHub branch listing يعرض main وحده مع protected=false؛ rulesets endpoint يعيد قائمة فارغة.
- أدوات GitHub المتاحة في جلسة التنفيذ تدعم قراءة الحماية فقط ولا تعرض عملية كتابة لإعدادات repository/branch protection/rulesets؛ لم تتغير إعدادات GitHub ولم يُدّع تفعيلها.
- لا يُعد تشغيل CI بعد push حماية قبل الدمج؛ سياسة الفرع الواحد والكتابة المباشرة إلى main لا توفر required-check gate قبل تحديث الفرع. يتطلب فرض required checks قبل الدمج اعتماد PR/merge workflow أو آلية تحديث محمية متوافقة مع السياسة.
- البوابات ذات القيمة الحاجزة: Build and test portable Laravel runtime، Email, password, Google verifier and guest authentication، وSecret, dependency, filesystem and image scans.
- Pint report check الحالي ينسق نسخة مؤقتة ويرفع artifact ولا يفشل لمجرد وجود تنسيق مخالف؛ لذلك لا يصنف required check مستقلًا. strict pint --test داخل Docker هو بوابة التنسيق الملزمة.
- Authentication Regression يعيد جزءًا من تغطية Docker، لكنه فحص متخصص أسرع لدورة المصادقة؛ يُحتفظ به حاليًا لتقديم إشارة مستقلة إلى أن تُجمع بيانات وقت التنفيذ/التكلفة ثم يُعاد تقييمه.
- Docker وSecurity يبنيان صورة الإنتاج كلٌ على حدة؛ هذا تكرار تكلفة/وقت واضح، لكنه لم يُدمج في هذه الخطوة حتى لا نغيّر أسماء checks قبل إعداد required contexts رسميًا.

**قرار 10B:** إبقاء الـworkflows الخمسة كما هي في هذه الدفعة، وتوثيق أسماء check runs الفعلية دون حذف أو دمج متسرع. تظل الحماية required-check setup اعتمادًا خارجيًا مفتوحًا.

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

**الحالة:** IN_PROGRESS — إضافة قياسات زمن النافذة الكاملة ومعدل الطلبات وP50/P95 إلى اختبار التزامن الموجود.

- الاختبار يستخدم ثمانية عمال PHP مستقلين، ولكل عامل Sanctum token واتصال PostgreSQL مستقل، مع حاجز إطلاق مشترك. التشغيل عبر Docker يستخدم PostgreSQL disposable فقط.
- ستشمل القياسات عدد الطلبات الناجحة، زمن نافذة التزامن الكلي، throughput requests/sec، request/response bytes، P50/P95 بطريقة nearest-rank، average/max latency، وعدد/معدل stale conflicts والنسخة الفائزة.
- baseline المسجل على Docker run 45503594954 قبل إضافة هذه المقاييس: 8 clients؛ 4,191 request bytes؛ 545 response bytes؛ average latency 115.311 ms؛ max 149.676 ms؛ stale conflicts 6/8 (75%)؛ winning timestamp 5000.
- هذه عينة CI اصطناعية صغيرة وليست production load benchmark أو SLO. لن نضيف حد latency اعتباطياً قبل اعتماد workload وهدف أداء متوقع.
- قبول 10E يتطلب نجاح البوابات الأربع على SHA الذي يحتوي القياسات، ثم تسجيل القيم النهائية من Docker logs.

## الشرائح التالية

- 10B: required checks وحماية main وتقييم تداخل workflows.
- 10C COMPLETE: migrations على schema فارغ وsnapshot اصطناعي سابق.
- 10D COMPLETE: API v1 contract regression verified on SHA 44ed546.
- 10E IN_PROGRESS: synthetic PostgreSQL load instrumentation (P50/P95/throughput/volume/conflict).
- 10F: immutable image digest وربط artifact بالـSHA وSBOM/provenance.
- 10G: release manifest وتجربة rollback في بيئة معزولة.

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
