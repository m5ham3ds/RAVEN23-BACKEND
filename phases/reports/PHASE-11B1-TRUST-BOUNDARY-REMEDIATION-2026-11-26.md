# تقرير معالجة حدود الثقة — Phase11B1 — 26 نوفمبر 2026

- **الحالة:** PARTIAL — معالجة workflow منفذة ومجتازة CI؛ لا يعني ذلك بدء 11B2 أو إغلاق بوابات الموافقة الخارجية.
- **تاريخ البدء:** 2026-11-25
- **تاريخ الإغلاق أو التوقف:** 2026-11-26
- **الفرع:** main
- **SHA قبل التنفيذ:** 1b57b1da178dfd993c5f9bc1e2a19136af797f21
- **SHA الوسيط لفصل الوظائف:** 56d9c88de71f0ed107fda5c840ac587bff670f9a
- **SHA النهائي الذي تم اختباره:** c17fc84ac0f6acbe4a6d300913871441fb4e7ebe
- **Pull Request:** غير مستخدم؛ تم تحديث main مباشرة وفق سير المستودع الحالي.
- **الاعتماديات:** Phase10G release evidence؛ إغلاق Phase10B مطلوب قبل 11B2؛ cost/provider inputs مطلوبة قبل provisioning.

## 1. النتيجة التنفيذية

تم إصلاح فجوة least-privilege التي كشفها تدقيق 11B1: فصل Docker build/test/scan عن وظيفة توقيع الأدلة، وحصر صلاحيات OIDC/Sigstore في الوظيفة الموثوقة على main. كان أول تنفيذ للفصل يستخدم actions/download-artifact@v7.0.2، وهو tag غير موجود؛ لذلك فشلت وظيفة التوقيع عند Set up job على SHA 56d9c88. تم تصحيح المرجع إلى v8.0.2 في c17fc84ac0f6acbe4a6d300913871441fb4e7ebe، وبعده نجحت وظيفتا Docker validation والتوقيع بالكامل.

هذا إثبات لحدود CI وتوقيع artifacts فقط، وليس cloud deployment identity أو نشرًا إلى registry أو staging/production.

## 2. ما تم تنفيذه

- صلاحيات workflow العامة أصبحت contents: read فقط.
- وظيفة docker-build تملك contents: read فقط وتنفذ البناء والاختبارات والفحوص وتجربة rollback وتجهيز unsigned release inputs.
- وظيفة attest-release مستقلة، تعتمد على نجاح docker-build، ومقيدة بـrefs/heads/main مع push أو workflow_dispatch.
- الصلاحيات المرتفعة محصورة في وظيفة التوقيع: id-token: write وattestations: write وartifact-metadata: write.
- استُبدل actions/download-artifact@v7.0.2 بـactions/download-artifact@v8.0.2. بقي actions/upload-artifact@v7.0.2 كما هو.
- وظيفة التوقيع نزّلت inputs من نفس workflow run، وتحققت من source/run metadata وrollback وhashes، ثم أنشأت تواقيع image provenance وSBOM للصورة الحالية وصورة rollback، ووقّعت release manifest، وفحصت SHA256SUMS.

## 3. ما لم يتم تنفيذه والسبب

- لم تُفعّل branch protection أو required checks لأن اتصال GitHub المتاح في الجلسة لا يوفّر عملية كتابة لهذه الإعدادات، رغم أن صلاحية المستودع المعروضة Admin. القراءة تؤكد main.protected=false وrulesets فارغة.
- لم تُنشأ GitHub Environments أو cloud OIDC roles أو registry push أو IaC resources.
- لم يتم اختيار provider أو region أو cost ceiling؛ scorecard ما زال ينتظر المدخلات.
- لم يتم staging أو production deploy أو DB migration.

## 4. الملفات والمسارات التي تغيرت

| المسار | نوع التغيير | السبب |
|---|---|---|
| .github/workflows/docker-build.yml | تعديل workflow على commits 56d9c88 وc17fc84ac0f6acbe4a6d300913871441fb4e7ebe | عزل validation عن attestation، تقليص الصلاحيات، إصلاح إصدار download-artifact |
| phases/README.md | تحديث snapshot | توضيح آخر SHA متحقق والحالة المحدثة |
| phases/STATUS.md | تحديث الحالة وإضافة سجل | تسجيل الأدلة والاعتماديات المتبقية |
| phases/MASTER_PLAN.md | تحديث Phase11 وإضافة سجل | تثبيت بوابة المرحلة التالية |
| phases/PHASE-11-PORTABLE-INFRASTRUCTURE-AND-DEPLOYMENT.md | إضافة remediation evidence | تحديث trust boundary status دون إعلان 11B2 |
| phases/WORKFLOW_INVENTORY.md | إضافة ملحق تصحيحي | جعل تدقيق الصلاحيات التاريخي واضحًا ومحدثًا |
| phases/reports/PHASE-11B1-TRUST-BOUNDARY-REMEDIATION-2026-11-26.md | تقرير جديد | حفظ سلسلة السبب والإصلاح والاختبار |

## 5. الاختبارات والأدلة

| الاختبار / Workflow | SHA | النتيجة | رابط / ملاحظة |
|---|---|---|---|
| Docker Validation | c17fc84ac0f6acbe4a6d300913871441fb4e7ebe | PASS | [Run 45723087433](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433); 150 SQLite Feature / 1,704 assertions؛ 31 PostgreSQL / 546 assertions مع optional skip واحد؛ S3Mock 1/8 |
| Trusted release signing job | c17fc84ac0f6acbe4a6d300913871441fb4e7ebe | PASS | ضمن Docker run؛ download، source/rollback/hash verification، خمس attestations، manifest signing وSHA256SUMS |
| Authentication Regression | c17fc84ac0f6acbe4a6d300913871441fb4e7ebe | PASS | [Run 45723087469](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087469) |
| Pint Format | c17fc84ac0f6acbe4a6d300913871441fb4e7ebe | PASS | [Run 45723087423](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087423); changed-file steps skipped لعدم وجود PHP changes، strict Pint ضمن Docker PASS |
| Security Scans | c17fc84ac0f6acbe4a6d300913871441fb4e7ebe | PASS | [Run 45723087484](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087484); Gitleaks PASS وTrivy production-image summary Clean |

Release artifacts:
- Release bundle [15072603777](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433/artifacts/15072603777): 564,117,614 bytes; SHA-256 a12ddc732dcd2d2cff30df5ba73b236b32f418c51523b5a7c796778d94acb3ad.
- Unsigned release inputs [15073148534](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433/artifacts/15073148534): SHA-256 169d89d5d0d4307f9dc61435173454d838316c96cce606590f721f6b8b94696e.
- Attestations: [provenance 72416097](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416097)، [SBOM 72416109](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416109)، [rollback provenance 72416119](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416119)، [rollback SBOM 72416136](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416136)، [manifest signature 72416150](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416150).

## 6. حماية البيانات والتوافق

- لا تغييرات في Laravel API أو database schema/migrations؛ التغيير على workflow والوثائق فقط.
- لم يتم الاتصال بقاعدة إنتاج أو قراءة secrets أو تغيير بيانات.
- لم يتم اختبار تكامل Android API جديد لأن عقود التطبيق لم تتغير.
- لم يتم إنشاء backup/restore proof؛ rollback هنا اختبار صور محلية وحركة loopback فقط.

## 7. العيوب والمخاطر المتبقية

- Branch protection غير مفعل؛ نجاح CI بعد push لا يحمي main من push غير مفحوص.
- لا يوجد OCI registry manifest digest لأن صورة الإصدار لم تُنشر إلى registry.
- Trivy filesystem/broad report يبقى advisory في workflow حسب إعداداته؛ البوابة الحاجزة هي fixable HIGH/CRITICAL production-image scan.
- Pint workflow المستقل غير حاجز بذاته؛ strict Pint داخل Docker هو الفحص الحاجز.

## 8. المعوقات والاعتماديات

- **WAITING_DEPENDENCY:** إغلاق Phase10B قبل enforcement.
- **WAITING_EXTERNAL:** اختيار provider/region/workload/retention/HA/RPO/RTO/budget لتسعير 11A.
- **قرار المستخدم المطلوب:** اعتماد نموذج GitHub merge/protection، ثم اعتماد مزود الاستضافة والمنطقة وسقف التكلفة قبل أي موارد.

## 9. معايير القبول

| المعيار | PASS / FAIL / NOT TESTED | الدليل |
|---|---|---|
| validation workflow بصلاحية read-only | PASS | job permissions في workflow وDocker run |
| signing معزول عن pull_request | PASS | شرط main + push/workflow_dispatch ووظيفة منفصلة |
| download artifact action resolves | PASS | v8.0.2 ضمن job الناجح |
| source/run/rollback/hash verification | PASS | خطوة Verify source, rollback, hashes and rehearsal |
| signed provenance/SBOM وmanifest | PASS | سجلات attestations 72416097، 72416109، 72416119، 72416136، 72416150 |
| SHA256SUMS verification | PASS | جميع العناصر المعبأة OK |
| Docker/Auth/Pint/Security | PASS | أربع runs على SHA نفسه |
| branch protection required checks | NOT TESTED / BLOCKED | main.protected=false؛ لا write operation متاحة |
| provider cost scorecard | NOT TESTED / WAITING_INPUT | لا توجد مدخلات معتمدة |
| OCI registry/staging/production | NOT STARTED | غير مصرح ولم تتم الموافقة |

## 10. قرار إغلاق المرحلة

- [x] PARTIAL — معالجة trust-boundary workflow اكتملت واختُبرت.
- [ ] COMPLETE — لا تُعلن Phase11 مكتملة؛ لا تزال 10B و11A scorecard واعتماديات 11B2 قائمة.
- [x] WAITING_DEPENDENCY — 11B2 تنتظر إغلاق 10B.
- [x] WAITING_EXTERNAL — 11A تنتظر مدخلات التكلفة والموافقة.

**المرحلة التالية المسموح بها وسبب ذلك:**

لا يبدأ 11B2 بعد. البوابة العملية التالية هي Phase10B: تفعيل branch protection/required checks بنموذج merge متوافق مع سياسة main-only. بالتوازي يجب استكمال مدخلات scorecard 11A قبل اختيار provider أو provisioning. لا توجد خطوة provisioning/deployment مسموحة حاليًا.

## 11. بيانات الاستمرارية

ابدأ بقراءة هذا التقرير ثم:
1. phases/STATUS.md
2. phases/MASTER_PLAN.md
3. phases/PHASE-11-PORTABLE-INFRASTRUCTURE-AND-DEPLOYMENT.md
4. phases/BRANCH_POLICY.md
5. phases/WORKFLOW_INVENTORY.md

استأنف من c17fc84ac0f6acbe4a6d300913871441fb4e7ebe؛ لا تعِد Phase11A أو Phase11B1. لا تبدأ 11B2 حتى إغلاق 10B واعتماد مدخلات provider/region/cost.
