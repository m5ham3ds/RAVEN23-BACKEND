# تقرير إغلاق المرحلة 10G — release manifest and isolated rollback rehearsal

- **الحالة:** COMPLETE
- **التاريخ:** 2026-11-25
- **الفرع:** `main`
- **SHA قبل تصحيح الملخص النهائي:** `299ca91e7b104434b1af23f23c480a89f6fd94ce`
- **SHA النهائي الذي اجتاز بوابات CI الأربع:** `a86fb3abd747a4110caa42b105dbb290b89f9e23`
- **Pull Request:** لا يوجد؛ سياسة المستودع تعتمد main فقط.
- **الاعتماديات:** rollback baseline `f912dd2479a5f86471e1e8999b2d36c7611d41cb` مع بوابات CI الأربع ناجحة.

## 1. النتيجة التنفيذية

أُغلقت 10G بعد نجاح بوابات CI الأربع على SHA واحد. أعاد Docker workflow بناء الإصدار السابق في بيئة معزولة، وأثبت HTTP rollback عبر proxy loopback، وأنشأ CycloneDX SBOM وprovenance للصورة الحالية والسابقة، ثم أنشأ release manifest موقعًا ورفع حزمة أدلة ببصمة SHA-256.

## 2. ما تم تنفيذه

- أعاد بناء rollback target من Git commit السابق دون إنشاء فرع أو تعديل شجرة المستودع.
- شغل current candidate وrollback target كحاويتين مستقلتين non-root.
- أثبت المسار: current candidate = HTTP 200؛ إيقافها عمدًا = HTTP 502؛ تحويل المرور إلى rollback target = HTTP 200.
- تحقق من rollback CycloneDX SBOM بواسطة Anchore Syft v1.57.1 بعدد 5,720 مكوّنًا.
- أنشأ attestations للصورة الحالية (provenance وSBOM) وللصورة السابقة (provenance وSBOM)، ثم وقّع release manifest.
- تحقق من جميع ملفات SHA256SUMS ورفع artifact لمدة 30 يومًا.
- أصلح commit `a86fb3a` إخراج summary في workflow باستخدام `printf` بدلًا من backtick command substitution التي كانت تنتج رسائل `command not found` غير حاجزة.

## 3. ما لم يتم تنفيذه والسبب

- لا registry push؛ workflow يرفع Docker archive كـGitHub Actions artifact فقط.
- لا cloud load balancer أو staging/prod deploy؛ اختبار proxy loopback لا يثبت التوجيه السحابي.
- لا AWS account أو resources أو DNS أو deployment OIDC role أو production secrets.
- لا تغيير لقاعدة أو بيانات الإنتاج.
- 10B ما زالت تنتظر Repository Admin لتفعيل branch protection/required checks.

## 4. الملفات والمسارات التي تغيرت

| المسار | نوع التغيير | السبب |
|---|---|---|
| `.github/workflows/docker-build.yml` | استخدام printf في release summary | منع shell command substitution وإخراج summary موثوق |

لم يتغير Laravel runtime أو API أو database schema في تصحيح الملخص.

## 5. الاختبارات والأدلة

| الاختبار / Workflow | SHA | النتيجة | الرابط |
|---|---|---|---|
| Docker Validation | `a86fb3a` | SUCCESS | [45625075036](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036) |
| Authentication Regression | `a86fb3a` | SUCCESS | [45625075096](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075096) |
| Pint Format | `a86fb3a` | SUCCESS | [45625075137](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075137) |
| Security Scans | `a86fb3a` | SUCCESS | [45625075229](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075229) |

### Image and rollback identities

| الدليل | القيمة |
|---|---|
| Rollback source commit | `f912dd2479a5f86471e1e8999b2d36c7611d41cb` |
| Current image config digest | `sha256:8b1649e89c3feccb9f01124b2ac83abe0d4796442208df20cee18be5169e9b02` |
| Current Docker archive SHA-256 | `1f9347f89226634824c23cda2d6064ba15f2f98747b964aa77337efd06a22773` |
| Current CycloneDX SBOM SHA-256 | `ecd6913ec2b92c166863ec9811aacdc657179397b352e8c9bb7fe26a28fde860` |
| Rollback image config digest | `sha256:a736995b08c01b2feb7eaefeb462c6d12bf528b18cc2cbaecb0f2c3be03180f0` |
| Rollback Docker archive SHA-256 | `1647484f8bc7c4376a915aa48951adf2c1cc1f86490a1a2b3c50d65ae2ec92d6` |
| Rollback SBOM SHA-256 | `3fab41bb3f765e11b23c8b13037ca50b2212e5cc64281c03b79aed52988d16e3` |
| Rollback SBOM generator/components | Anchore Syft v1.57.1 / 5,720 |

### Signed attestations

| النوع | Attestation |
|---|---|
| Current image provenance | [72175380](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175380) |
| Current image SBOM | [72175401](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175401) |
| Rollback image provenance | [72175434](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175434) |
| Rollback image SBOM | [72175458](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175458) |
| Immutable release manifest | [72175490](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175490) |

- Release manifest SHA-256: `bb0d5d99b10f8f661d2b7b602b90dc55d37ba0af056608486a6e09e6bf5f649c`.
- GitHub artifact: [15026689226](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036/artifacts/15026689226).
- Artifact name: `raven23-release-bundle-a86fb3abd747a4110caa42b105dbb290b89f9e23`.
- Size: 562,608,814 bytes; digest `sha256:8b0a10585fc6f7542660e1cedb8ecb362b4b86f625a09ef7a73aed7213f9162c`; expiry 2026-12-25.
- كل ملفات SHA256SUMS اجتازت التحقق. لم تظهر رسائل shell command errors بعد إصلاح الملخص.

## 6. حماية البيانات والتوافق

- لا تغيير schema أو API contract أو Flutter contract.
- لم يتم لمس بيانات الإنتاج.
- rollback استُخدم فيه Git tree قديم وحاويات CI مؤقتة.
- اختبارات PostgreSQL وS3Mock وruntime نفذت في GitHub Actions.
- لم يتم إجراء restore من backup إنتاجي؛ ذلك خارج نطاق 10G.

## 7. العيوب والمخاطر المتبقية

- اختبار loopback لا يثبت سلوك cloud load balancer أو deployment rollback.
- Docker image محفوظة في GitHub Actions artifact ولم تُنشر إلى OCI registry.
- لا يوجد نشر staging أو production.
- حماية main وrequired checks غير مفعّلة.

## 8. المعوقات والاعتماديات

- **WAITING_EXTERNAL:** صلاحية Repository Admin لإعداد branch protection وrequired checks (10B).
- **WAITING_EXTERNAL:** اختيار provider/region/budget قبل إنشاء موارد Phase11.
- **WAITING_DEPENDENCY:** Phase08 real S3/mail/Push staging وPhase09 product/payment decisions.
- **قرار المستخدم المطلوب:** لا يلزم قرار جديد لإغلاق 10G؛ اختيار الاستضافة والتكلفة يتطلب موافقة مستقلة.

## 9. معايير القبول

| المعيار | النتيجة | الدليل |
|---|---|---|
| بوابات CI الأربع على SHA واحد | PASS | run IDs أعلاه |
| إعادة بناء rollback target | PASS | Docker step 21 |
| HTTP rollback 200→502→200 | PASS | workflow notices |
| Current/rollback SBOM وattestations | PASS | أربع Sigstore attestations |
| Manifest موقع ويربط release/rollback/rehearsal | PASS | attestation 72175490 |
| SHA256SUMS لكل الملفات | PASS | finalize step entries OK |
| Release artifact uploaded | PASS | artifact 15026689226 |
| Production/cloud load-balancer rollback | NOT TESTED | خارج CI المعزول |

## 10. قرار إغلاق المرحلة

- [x] COMPLETE — 10G جميع معايير CI والأدلة المعزولة مثبتة.
- [ ] Phase10 overall COMPLETE — لا؛ 10B ما زالت OPEN.

**المرحلة التالية المسموح بها:** 11A design-only provider-neutral architecture. Provisioning/deployment ينتظر اختيار provider/region/cost وموافقة المستخدم، وproduction deployment ينتظر 10B وPhase12.

## 11. بيانات الاستمرارية

اقرأ `phases/STATUS.md` ثم `phases/MASTER_PLAN.md` ثم مواصفة Phase11 الجديدة و`phases/TOOLS_AND_SERVICES.md`. لا تعِد تنفيذ 10G أو تغيّر release artifact.
