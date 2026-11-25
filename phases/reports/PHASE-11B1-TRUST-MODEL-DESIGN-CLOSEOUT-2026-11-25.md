# تقرير إغلاق تصميم Phase11B1 — نموذج الثقة للنشر المحمول

**التاريخ:** 25 نوفمبر 2026  
**المستودع:** `m5ham3ds/RAVEN23-BACKEND`  
**الفرع:** `main`  
**المرجع الذي تمت مراجعته:** `1b57b1da178dfd993c5f9bc1e2a19136af797f21`  
**الحالة:** 11B1 DESIGN_COMPLETE (design only؛ لا enforcement أو نشر)

## 1. التحقق من خط الأساس

جميع بوابات CI الأربع مكتملة بنجاح على SHA واحد قبل هذا التعديل الوثائقي:

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45638169465](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169465) | SUCCESS |
| Authentication Regression | [45638169413](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169413) | SUCCESS |
| Security Scans | [45638169229](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169229) | SUCCESS |
| Pint Format | [45638169219](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169219) | SUCCESS |

هذا المرجع كان تحديثًا وثائقيًا؛ آخر كود application/runtime سبق أن اجتاز البوابات الأربع ظل على `84b87d9`.

## 2. نطاق التدقيق

تمت مراجعة شجرة المستودع وملفات workflows الخمسة وملفات database/filesystems/cache/queue/mail/services وإعدادات `.env.example` وعقود `PrivateObjectStorage` و`PushNotificationTransport` وتسجيلها في `AppServiceProvider`. لم تتم قراءة GitHub Secrets أو قيم `.env` أو أي قاعدة إنتاجية.

## 3. النتائج المهمة

1. Docker workflow واحد يجمع validation وrelease evidence/attestations، ويمنح على مستوى workflow `id-token: write` و`attestations: write` و`artifact-metadata: write`، بينما trigger يشمل `pull_request` أيضاً ولا توجد شروط صريحة على خطوات attestation لتقييدها بـtrusted main event. هذه فجوة least-privilege تستوجب فصل الوظائف قبل 11B2.
2. `actions/attest@v4` يثبت Sigstore provenance/SBOM/manifest؛ لا يوجد cloud OIDC role أو deploy workflow. لا يجوز تفسير `id-token: write` على أنه صلاحية AWS أو أي cloud provider.
3. release evidence الحالية تخص Docker archive وimage config digest وSBOM وmanifest، مع `registryPush:false`. لا يوجد OCI manifest digest حتى الآن.
4. Security workflow يفرض Gitleaks وعتبة fixable HIGH/CRITICAL production-image vulnerabilities؛ تقارير Trivy filesystem والصورة العامة ليست blocking حالياً بسبب `--exit-code 0`.
5. Docker job يفرض Pint عبر `pint --test` على ملفات PHP المتغيرة؛ Pint workflow المنفصل ينتج artifact ولا يغيّر commit.
6. `main.protected=false`؛ نجاح CI بعد push لا يعادل حماية merge. لا توجد GitHub Environments محمية أو deployment identity أو registry push.
7. Auth HTTP smoke يدوي وGET-only. لا يوجد نشر أو كتابة على production ضمن هذا التدقيق.

## 4. نموذج الثقة للتصميم المستقبلي

- CI build/test/scan بصلاحيات قراءة المصدر فقط.
- Attestation job منفصل بعد نجاح البوابات وعلى trusted main release event، بصلاحيات Sigstore الدنيا.
- لا deploy credentials أو secrets في PR workflows، ولا `pull_request_target`.
- النشر إلى OCI registry مرة واحدة ثم تسجيل OCI manifest digest؛ staging/production يستهلكان digest نفسه دون rebuild.
- التحقق من issuer/audience/repository ID/owner/workflow_ref/source ref/event/environment/source SHA/run metadata وفق claims الفعلية وسياسة المزود.
- بيئتا staging وproduction منفصلتان مع approvals؛ وهويات قصيرة العمر منفصلة للـregistry publisher وstaging deployer وproduction deployer.
- DB migration identity منفصلة عن DB application runtime identity وعن deploy identity.
- rollback إلى digest معروف سبق التحقق من attestations/SBOM.

لا تثبت هذه الوثيقة صيغة claim أو audience خاصة بمزود لم يتم اختياره؛ تؤخذ القيم الفعلية من token metadata ووثائق المزود بعد الموافقة.

## 5. قرار الإغلاق والاعتماديات

**11B1 DESIGN_COMPLETE — design only.** اكتمل التصميم وتوثيق فجوة الصلاحيات، لكن لم يتم تعديل workflow permissions ولم يتم إنشاء trust policy أو environment أو secret أو cloud role أو registry أو مورد مدفوع.

- 10B OPEN لأن branch protection غير مفعلة و`main.protected=false`؛ يتطلب صلاحية Repository Admin.
- 11A cost scorecard WAITING_INPUT لعدم اعتماد region/workload/data/egress/retention/HA/RPO/RTO/budget.
- 11B2 WAITING_EXTERNAL حتى إغلاق 10B واعتماد provider/region/cost.
- 11C–11F لم تبدأ؛ لا Terraform/OpenTofu apply أو staging أو production deployment.
- Phase12 لا يبدأ دون backup/restore rehearsal وموافقة cutover مباشرة.

## 6. الملفات التي تم تحديثها

- `phases/PHASE-11-PORTABLE-INFRASTRUCTURE-AND-DEPLOYMENT.md`
- `phases/MASTER_PLAN.md`
- `phases/STATUS.md`
- `phases/WORKFLOW_INVENTORY.md`
- `phases/README.md`
- هذا التقرير الجديد داخل `phases/reports/`

نتائج SHA المرجعي أعلاه لا تُنسب إلى commit التحديث؛ يجب أن يجتاز commit الجديد البوابات الأربع قبل وصفه بأنه verified.
