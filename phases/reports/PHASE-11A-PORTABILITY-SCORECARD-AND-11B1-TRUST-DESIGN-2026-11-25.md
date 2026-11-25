# تقرير Phase11A portability scorecard وPhase11B1 trust model design

- **التاريخ:** 2026-11-25
- **الفرع:** `main`
- **Baseline SHA الذي اجتاز CI:** `634e68b9eddb41b556f1005b37bffe769a9d8b32`
- **الحالة:** 11A scorecard template جاهز لكن unpriced/WAITING_INPUT؛ 11B1 design IN_PROGRESS؛ enforcement WAITING_EXTERNAL.

## 1. CI baseline

- Docker [45630955379](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955379) — SUCCESS.
- Authentication [45630955656](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955656) — SUCCESS.
- Pint [45630955397](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955397) — SUCCESS.
- Security [45630955411](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955411) — SUCCESS.

## 2. 11A portability and exit matrix

أضيفت شروط نقل منفصلة لـOCI compute، PostgreSQL، S3-compatible private objects، cache، queue/outbox، mail/push، secrets/identity، registry، logs/metrics، DNS/TLS، وbackup/restore. تم فصل ما اجتاز CI عن اختبارات النقل العابرة للمزود التي لم تنفذ بعد.

## 3. 11A cost scorecard

أُعد جدول مقارنة لكامل monthly recurring cost، one-time exit/migration، و12-month total بين AWS integrated وmulti-provider. جميع الأسعار والدرجات بقيت TBD لأن المنطقة، حجم الاستخدام، النمو، egress، retention، HA/RPO/RTO والميزانية غير معتمدة. لا توجد recommendation أو winner.

## 4. 11B1 secure deployment trust model

- فصل CI build/test/scan عن artifact attestation وعن registry publishing وعن deployment.
- توضيح أن `id-token: write` في Docker workflow مخصص لتوقيع Sigstore عبر `actions/attest@v4`، وليس AWS/cloud deploy role.
- فصل GitHub artifact digest وDocker archive SHA-256 وimage config digest عن OCI registry manifest digest.
- مرشحو required checks: Docker، Authentication، Security؛ Pint artifact وmanual HTTP smoke لا يعدان gates مستقلين حسب سلوك workflows الحالي.
- تصميم staging/prod approvals وهويات OIDC قصيرة العمر ومنفصلة عن runtime/migration identities.
- main لا تزال `protected=false`؛ تفعيل required checks يحتاج Repository Admin وآلية merge معتمدة.

## 5. تحسين قابلية النقل

أُزيل `NEON_DB_URL` غير المستخدم من Auth regression workflow؛ يظل اختبار PostgreSQL يعتمد على `DB_URL` القياسي. لم تتم قراءة أو تعديل أي قيم GitHub secrets.

## 6. ما لم يتم تنفيذه

لا provider/region selection، لا حساب أو موارد، لا registry push، لا OIDC deploy role، لا GitHub Environment، لا secrets، لا DNS، لا production database ولا deployment. Phase08/04 real integrations تبقى اعتمادات عند استخدام تلك الخدمات.

## 7. الخطوة التالية

استكمال 11B1 design فقط. تفعيل 11B2 enforcement ينتظر 10B وصلاحية Admin، واختيار provider/region/cost بموافقة المستخدم.
