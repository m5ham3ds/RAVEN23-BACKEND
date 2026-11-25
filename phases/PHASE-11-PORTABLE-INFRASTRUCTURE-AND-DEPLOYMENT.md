# المرحلة 11 — البنية التحتية المحمولة والنشر المستقل عن المزود

**الحالة:** 11A DESIGN_IN_PROGRESS (provider-neutral baseline ready؛ pricing scorecard waits for region/workload inputs)؛ 11B1 DESIGN_IN_PROGRESS (trust model design only)؛ 11B2 enforcement و11C–11F WAITING_EXTERNAL/DEPENDENCY.  
**الاعتماديات:** 02–03 أساس runtime/security؛ 10G release evidence؛ 10B required checks شرط قبل نشر production. تعتمد تجارب staging على 04/08 عند استخدام تكاملاتها، وعلى موافقة مستقلة للحساب والمنطقة والتكلفة.

## 1. الهدف

بناء طريقة استضافة ونشر قابلة لإعادة الإنشاء والنقل بين AWS وأي مزود آخر متوافق، بحيث لا يصبح منطق Laravel أو API أو بيانات المستخدم مرتبطًا بمزود واحد. يجب أن يكون تغيير المزود عملية إعداد بنية وruntime configuration وترحيل بيانات مدروس، لا إعادة كتابة domain logic أو تغيير عقود تطبيق RAVEN23.

لا تختار هذه المرحلة AWS أو مزودًا بديلًا تلقائيًا. المساران للمقارنة فقط:
- **A — AWS Integrated Architecture:** compute وPostgreSQL وobject storage وregistry وidentity/secrets/monitoring ضمن AWS قدر الإمكان.
- **B — Multi-Provider Portable Architecture:** compute وPostgreSQL وS3-compatible storage وregistry وsecrets وmonitoring يمكن توزيعها بين مزودين مستقلين بعقود موحدة.

لا يتم اختيار أحد المسارين حتى تتم مقارنة التكلفة والعبء التشغيلي والاعتمادية وسهولة الخروج، ثم موافقة المستخدم.

## 2. ثوابت لا يجوز كسرها

1. Laravel domain/services لا تستورد SDK خاصًا بمزود الاستضافة مباشرة ولا تضع business logic خاصًا باسم مزود.
2. PostgreSQL هو العقد العلائقي؛ تبقى migrations وSQL ضمن التوافق القياسي قدر الإمكان، مع توثيق أي امتداد خاص قبل اعتماده.
3. ملفات المستخدم عبر Laravel Storage adapter وS3-compatible interface؛ endpoint/region/bucket/credentials إعدادات runtime وليست قيمًا ثابتة في الكود.
4. Redis ليس شرطًا مسبقًا؛ database queue/cache تبقى baseline حتى يثبت الحمل حاجة واضحة لخدمة مستقلة.
5. التطبيق يبنى كـOCI container image واحدة؛ image digest الذي اجتاز CI هو الذي ينتقل إلى staging ثم production دون rebuild صامت.
6. الأسرار تُحقن من runtime secret manager أو بيئة تشغيل آمنة؛ يمنع وضعها في Git أو CI logs أو تقارير المرحلة.
7. GitHub deploy identity قصيرة العمر عبر OIDC/workload federation حيث يدعمها المزود؛ لا access keys دائمة.
8. API hostname يبقى مستقلاً عن اسم مزود compute، وTLS/health/readiness/observability لها عقود منفصلة.
9. النسخ الاحتياطي والاستعادة لهما مسار مستقل عن التخزين الأساسي؛ snapshots وحدها لا تثبت نجاح restore.
10. لا production cutover قبل Phase12 وbackup/restore rehearsal وموافقة مباشرة تحدد المصدر والهدف والوقت.

## 3. مقارنة معماريتي الاستضافة

### A — AWS Integrated Architecture (مرشح غير معتمد)
- Compute: EC2 أو ECS/Fargate بعد قياس العبء والتكلفة.
- Database: RDS PostgreSQL.
- Object storage: S3 private buckets.
- Cache/queue: database queue أولًا؛ ElastiCache Redis عند الحاجة فقط.
- Container registry: ECR.
- Identity/secrets: GitHub OIDC إلى IAM role مؤقت، وSSM أو Secrets Manager.
- Logs/metrics: CloudWatch مع retention وتنبيهات معتمدة.
- DNS/TLS/edge: Route 53 وشهادات TLS وload balancing عند الحاجة.

### B — Multi-Provider Portable Architecture (مرشح غير معتمد)
- Compute: أي Linux container host يدعم OCI image وhealth checks.
- Database: PostgreSQL مُدار مستقل عن compute.
- Object storage: private S3-compatible API مع مسار bulk export/import موثق.
- Cache/queue: Redis-compatible مستقل عند الحاجة، وإلا Laravel database queue.
- Registry: أي OCI-compatible registry مع digest ثابت.
- Identity/secrets: runtime secret manager وOIDC/workload identity قصيرة العمر حيث يتوفر.
- Logs/metrics: structured logs وmetrics إلى خدمة مستقلة أو telemetry provider-neutral.
- DNS/TLS/edge: DNS وTLS وload balancer منفصلون عن compute لتسهيل النقل.

### معايير المقارنة قبل التوصية

| المعيار | ما يجب إثباته |
|---|---|
| التكلفة الكلية | compute + DB + storage + backups + egress + logs/metrics + registry + DNS/TLS، شهريًا وبعد أي credits |
| قابلية النقل | نسبة الخدمات ذات العقود القياسية وعدد تغييرات runtime/IaC عند التبديل |
| الإدارة | patching وscaling وalerts وتحديثات قواعد البيانات |
| الاعتمادية | SLA وتعدد المناطق/النسخ وحدود الاتصال ونقاط الفشل |
| البيانات والتعافي | backup encryption وretention وrestore test وRPO/RTO المعتمدان |
| الأمن | private networking وDB not public وshort-lived identity وsecret rotation وaudit logs |
| الأداء | HTTP latency وDB connections وstorage throughput وqueue lag |
| الخروج | export formats وegress fees ووقت نقل الملفات وقاعدة البيانات وخطة rollback |

لا توجد أسعار أو درجات أو توصية نهائية في هذه النسخة؛ يجب جلب الأسعار والخصائص وقت القرار بعد معرفة المنطقة والحجم والميزانية.

## 4. مسارات التنفيذ

### 11A — Provider-neutral architecture baseline (بدأت)
- تحديث سجل الأدوات والخدمات ليعكس خط التنفيذ الفعلي.
- توثيق الخيار A والخيار B ومقارنتهما بمعايير ثابتة.
- تحديد عقود التطبيقات التي يجب أن تظل مستقلة عن provider.
- إعداد scorecard للتكلفة والاعتمادية والأمن والخروج دون اختيار فائز الآن.

### 11B1 — Secure deployment trust model design (DESIGN_IN_PROGRESS؛ تصميم فقط)

- تعريف الفصل بين CI build/test/scan وartifact attestation وOCI registry publication وstaging/production deployment.
- توثيق الفرق بين Sigstore signing OIDC وcloud workload identity؛ `id-token: write` في Docker workflow يستخدمه `actions/attest@v4` لتوقيع provenance/SBOM ولا يعني وجود cloud deployment role.
- تحديد claims اللازمة لأي trust policy مستقبلية: repository/owner identity، workflow identity، protected ref، environment، audience، event type وsource SHA.
- فصل هويات staging deploy وproduction deploy وone-off migration عن runtime application identity.
- اشتراط أن ينشر deploy الـimage digest الذي اجتاز CI، بعد التحقق من provenance/SBOM/manifest ومصدر workflow والـSHA؛ يمنع rebuild أثناء deploy.
- تحديد مرشحي required checks دون تغيير إعداد GitHub الحالي: Docker وAuthentication وSecurity؛ Pint report ليس blocking check مستقلاً، وstrict Pint داخل Docker هو gate.
- منع `pull_request_target` وأي workflow يعرّض deployment credentials لمدخلات غير موثوقة.

### 11B2 — Enforce trust controls and OIDC (WAITING_EXTERNAL)

- تفعيل branch protection/required checks عبر Repository Admin بعد اعتماد سياسة PR/merge متوافقة مع main-only.
- إنشاء GitHub Environments منفصلة لـstaging وproduction مع approvals المطلوبة.
- بعد اختيار provider وموافقة المستخدم: trust policy ضيقة مرتبطة بالمستودع والـworkflow والـref والـenvironment والـaudience.
- صلاحيات نشر قصيرة العمر ومحدودة، مع فصل deploy identity عن runtime DB identity وعن DB_MIGRATION_URL.
- لا تُنشأ roles أو secrets أو environments أو deployments في المسار 11B1.

### 11C — Infrastructure as Code design and validation (لم يبدأ)
- اختيار Terraform أو OpenTofu بعد تقييم توافق المزود وأدواته وتكلفته.
- فصل الوحدات: network, compute, database, object-storage, secrets, monitoring, registry.
- اختبار format/validate/plan/policy دون apply على موارد حقيقية.
- تصميم state storage/locking/backups وsecrets بأمان.

### 11D — Staging provisioning and deployment (WAITING_EXTERNAL)
يبدأ بعد اختيار المزود والمنطقة والميزانية وموافقة المستخدم الصريحة. ينشئ staging معزولة فقط ولا يستخدم قاعدة إنتاج. لا يفعّل mail/Push/S3 الحقيقي إلا وفق موافقات Phase08 و04 واختبارات التكامل المعتمدة.

### 11E — Staging acceptance and operational rehearsal (لم يبدأ)
- migrations وPostgreSQL application/migrator roles.
- private object storage وqueue/cache عند تفعيلهما.
- auth/sync/API contract smoke tests.
- image digest attestation وsecrets injection وlogs/metrics/alerts.
- backup/restore rehearsal إلى بيئة منفصلة.
- rollback/deploy drill وقياس RPO/RTO دون لمس الإنتاج.

### 11F — Production deployment handoff (WAITING_DEPENDENCY)
لا يبدأ production deployment إلا بعد إغلاق البوابات المطلوبة وموافقة المستخدم على الحساب/المنطقة/التكلفة والمخاطر ووقت التنفيذ. Phase12 وحدها تحدد data migration/cutover، وPhase13 تغطي التشغيل والتعافي المستمر.

## 5. الوضع المثبت حاليًا

- Dockerfile يضم PHP 8.4 build stage وFrankenPHP/Caddy production runtime غير جذري، دون سلوك startup خاص بمزود استضافة.
- PostgreSQL هو عقد قاعدة البيانات؛ اختبارات CI تستخدم PostgreSQL 16 مؤقتة وأدوارًا مقيدة.
- S3 Flysystem adapter موجود واختبار S3Mock يثبت contract test فقط؛ لا يوجد دليل على real object-storage staging.
- Render وNeon مسجلان كخدمات قائمة/انتقالية، وليس كقرار دائم.
- Phase10G أثبت Docker archive وSBOM وتواقيع provenance/SBOM وrelease manifest. الحزمة موجودة كـGitHub Actions artifact؛ لم يتم دفعها إلى registry.
- لم يتم ضمن هذا العمل إنشاء موارد AWS أو OIDC deployment role أو تغيير DNS أو إجراء production deploy؛ ولا توجد في المستودع أدلة على تهيئة قناة نشر سحابية.
- main حاليًا protected=false؛ required checks وbranch protection لم يتم فرضها.

### 5.1 جرد حدود التكامل الحالية

| الطبقة | التنفيذ الحالي | قابلية النقل / القيد |
|---|---|---|
| Database | PostgreSQL عبر DB_URL أو متغيرات DB_* القياسية، مع TLS للاتصال البعيد | لا Neon SDK في domain؛ لا provider lock على مستوى PDO/PostgreSQL |
| Private files | PrivateObjectStorage contract فوق Laravel FilesystemAdapter | local default؛ S3-compatible endpoint configurable؛ RAVEN23_OBJECT_STORAGE_* محايدة وAWS_* aliases للتوافق |
| Cache/Queue | database drivers افتراضيًا | Redis متاح كخيار؛ DynamoDB/SQS إعدادات AWS اختيارية وليست defaults |
| Email | Laravel Mail؛ default log والإرسال الحقيقي معطّل | SMTP/Postmark/Resend/Mailgun/SES transports؛ لا provider مفعل |
| Push | PushNotificationTransport مع Disabled transport افتراضيًا | لا adapter أو credentials لـFCM/OneSignal |
| Compute | OCI image مع FrankenPHP/Caddy | لا startup خاص بـRender/Neon |
| Delivery | release archive ضمن GitHub Actions | لا registry push أو IaC أو cloud deployment workflow/OIDC role |

أضيفت aliases التخزين المحايدة في إعداد S3 مع إبقاء AWS_* fallback backward-compatible، ونُقل اختبار S3Mock لاستخدام aliases الجديدة. أسماء SQS/DynamoDB/SES تبقى optional AWS adapters ولا تستخدمها defaults. لا يوجد IaC directory أو deployment workflow في root/workflow inventory الحالي. أُزيلت إشارة Render القديمة من تعليق Google client ID دون تغيير سلوكه.

## 6. الاعتماديات والممنوعات

**المسموح الآن:** تصميم البنية، مقارنة الخيارات، إعداد scorecard، توثيق المتطلبات، واختبارات IaC غير المطبقة على موارد حقيقية بعد اختيار الأدوات.

**يتطلب موافقة منفصلة:** إنشاء حساب/مشروع أو region أو موارد مدفوعة أو قواعد DB أو buckets أو VPC/networking أو registry publication أو DNS/TLS أو OIDC deployment role أو secrets أو أي deployment.

**ممنوع:**
- لا تختَر AWS أو أي مزود تلقائيًا.
- لا تنشئ موارد حقيقية ولا تنفذ Terraform/OpenTofu apply.
- لا تضف secrets أو credentials إلى GitHub.
- لا تتصل بقاعدة production ولا تنقل بيانات.
- لا تغيّر API contracts أو Laravel domain code لجعلها خاصة بمزود واحد.
- لا تدّع اختبار S3/mail/Push أو production rollback لمجرد نجاح mocks/loopback.

## 7. معايير قبول Phase11

- وثيقة قرار تقارن الخيارين وتذكر التكلفة الشهرية الحالية وبعد credits والـegress والنسخ والـobservability.
- مزود/منطقة/ميزانية معتمدة صراحة من المستخدم قبل provisioning.
- IaC قابلة للمراجعة وإعادة الإنشاء، modules مفصولة عن domain logic، وplan تمت مراجعته قبل apply.
- PostgreSQL خاصة، roles منفصلة، secrets runtime-only، وshort-lived deploy identity.
- image digest نفسه ينتقل من build إلى staging ثم production.
- staging smoke tests وmigration وbackup/restore وrollback ناجحة.
- required checks وenvironment approvals مفعلة قبل production.
- خطة خروج ونقل بيانات وDNS موثقة؛ لا cutover قبل موافقة Phase12.

## 8. المرحلة التالية المسموح بها

استكمال 11A فقط: جرد كل تكامل حالي مع مزود خارجي داخل ملفات التطبيق، وتقييم نقاط التبديل وقيود S3/PostgreSQL/queue/secrets، ثم إعداد scorecard للتكلفة/portability دون اختيار أو إنشاء موارد.

## 9. Phase11A exit scorecard وPhase11B1 trust boundaries

### 9.1 Portability / exit matrix

| الطبقة | العقد الحالي | ما أثبته CI | ما يلزم قبل إعلان النقل الكامل |
|---|---|---|---|
| Compute | OCI image؛ FrankenPHP/Caddy؛ non-root؛ health/readiness | Docker runtime وHTTP smoke؛ بدء web process لا يتطلب DB | تشغيل نفس image على host مستهدف، ingress/forwarded headers/resource limits/restart/scale |
| PostgreSQL | PostgreSQL URL أو host variables؛ remote TLS؛ DB_URL للتطبيق وDB_MIGRATION_URL لمهمة migration | PostgreSQL 16 disposable؛ roles منفصلة وmigrations | pg_dump/pg_restore cross-provider، extensions/roles/grants/collation/connections وrestore duration |
| Private objects | PrivateObjectStorage فوق Laravel FilesystemAdapter؛ S3-compatible disk configurable | S3Mock CRUD/get/list/delete/private visibility | multipart، metadata/content-type، presigned URLs، versioning/lifecycle/retention والـegress |
| Cache | Laravel Cache؛ database default | configuration/runtime tests | cache غير authoritative يمكن إعادة بنائه؛ Redis يحتاج lock/TTL/eviction/TLS tests |
| Queue/outbox | Laravel Queue؛ database default | worker safety guards وoutbox tests | drain/replay، retries/visibility، idempotency/dead-letter semantics عند تغيير backend |
| Email | Laravel Mail؛ log default؛ auth mail disabled | fake/array/log tests | مزود staging حقيقي وSPF/DKIM/DMARC/bounce/suppression/webhooks |
| Push | PushNotificationTransport؛ disabled default | outbox boundary وkill switch | provider adapter وtoken migration وnative idempotency؛ اعتماد Phase08 |
| Secrets/identity | runtime environment injection | APP_KEY required؛ لا secrets داخل image | secret manager وrotation/audit/access policy وفصل runtime/migration/deploy identities |
| Registry/release | GitHub Actions artifact وSigstore attestations | archive/SBOM/provenance/manifest/checksum validation | OCI registry push والتحقق من registry manifest digest قبل deploy |
| Observability | structured logs إلى stdout/stderr | log redaction/runtime output | collector/metrics/alerts/retention/cost budget وalert delivery test |
| DNS/TLS | TLS termination خارج الحاوية؛ trusted ingress | TLS guard وproxy trust validation | domain/certificate renewal/trusted proxy CIDRs/DNS TTL/cutover rollback |
| Backup/DR | لا دليل على backup provider حقيقي | CI migration/rollback ليست backup restore | encrypted DB + object export، retention، off-provider copy، restore verification وRPO/RTO |

### 9.2 Cost / portability scorecard — قالب غير مسعّر

لا توجد region أو traffic أو data volume أو availability target أو budget معتمدة. لذلك تبقى الأسعار والدرجات فارغة ولا نختار فائزاً. تتم المقارنة على workload متطابق وretention وHA متساويين:

| بند التكلفة | منهج الحساب | A — AWS integrated | B — multi-provider | الدليل/تاريخ السعر |
|---|---|---|---|---|
| Compute | replicas × hours × CPU/RAM class | TBD | TBD | TBD |
| Managed PostgreSQL | instance hours + storage + IOPS/throughput + HA/backups | TBD | TBD | TBD |
| Object storage | GB-month + PUT/COPY/GET/LIST + lifecycle/versioning | TBD | TBD | TBD |
| Network/egress | internet + inter-region/provider + NAT/LB | TBD | TBD | TBD |
| Registry | image/SBOM bytes + pulls + retention | TBD | TBD | TBD |
| Logs/metrics | ingest GB + indexed/retained GB + alerting | TBD | TBD | TBD |
| Secrets/identity | stored secrets + API calls + audit logs إن كانت مدفوعة | TBD | TBD | TBD |
| DNS/TLS/edge | zones + queries + public IP/LB + optional WAF | TBD | TBD | TBD |
| Backup/DR | backup bytes + replication/copy + restore rehearsals | TBD | TBD | TBD |
| Operations/support | paid support + operator hours على rate متفق عليه | TBD | TBD | TBD |
| **Monthly recurring** | مجموع العناصر قبل credits وبعدها | **TBD** | **TBD** | نفس الافتراضات |
| **One-time exit/migration** | export + egress + import + validation + cutover/rollback | **TBD** | **TBD** | رسوم المصدر والوجهة |
| **12-month total** | 12 × post-credit monthly + one-time migration | **TBD** | **TBD** | بدون احتساب credits كتكلفة دائمة |

### 9.3 Workload inputs required to price the scorecard

- Region and user geography/latency objective.
- Requests/day، peak requests/sec، concurrent users، عدد web/queue replicas.
- CPU/RAM لكل web/queue process وتكرار scheduled tasks.
- PostgreSQL GB الحالية والنمو الشهري وconnections وread/write IOPS أو throughput ومتطلبات HA.
- Object count/GB الحالية والنمو الشهري ومتوسط/أقصى حجم وعدد GET/PUT/LIST/COPY وdownload egress GB.
- Backup retention وoff-provider copy وRPO/RTO.
- Logs/metrics ingest وretention.
- سقف شهري مقبول واحتياج support/on-call.

لا تملأ قيمة مجهولة بتخمين أو Free Tier؛ تسجل كل price snapshot مع provider/SKU/region/date/currency/credits/egress/backup assumptions.

### 9.4 Deployment trust model — design only

```
Protected source revision
 -> CI build + tests + security gates
 -> signed provenance + SBOM + immutable evidence
 -> (after approval) OCI registry publication by digest
 -> verify source/workflow/attestations/policy
 -> staging environment approval + short-lived identity
 -> staging acceptance + rollback
 -> production approval + separate short-lived identity
 -> deploy the already-verified digest
```

- Docker workflow الحالي يطلب `id-token: write` لتوقيعات Sigstore عبر `actions/attest@v4`؛ لا توجد AWS/OIDC trust policy أو cloud role.
- GitHub artifact digest وDocker archive SHA-256 وimage config digest وOCI manifest digest قيم مختلفة؛ التنفيذ الحالي يثبت الثلاثة الأولى بحسب حزمة الإصدار ولا يملك registry manifest digest.
- required-check candidates: Docker job، Authentication job، Security job. Pint artifact ليس blocking gate مستقلاً، والـHTTP smoke يدوي وGET-only.
- main ما زالت `protected=false`؛ لذلك CI بعد push لا يحمي من push غير مفحوص. 11B2 enforcement ينتظر 10B.
- لا deployment workflow أو GitHub Environment محمية أو cloud OIDC role أو registry push في 11B1.

## 10. CI verification for Phase11A / 11B1 baseline — 25 November 2026

**Verified main SHA:** `84b87d9e2b746a0fa6c1fca26ae57c02d36d9181`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45636103294](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103294) | SUCCESS — 150 Feature tests / 1,704 assertions; S3Mock 1 / 8; PostgreSQL/runtime/rollback |
| Authentication Regression | [45636103168](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103168) | SUCCESS |
| Pint Format | [45636103292](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103292) | SUCCESS |
| Security Scans | [45636103186](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103186) | SUCCESS |

- Rollback path in isolated loopback test: current candidate HTTP 200; stopped candidate HTTP 502 (expected); successful switch to rollback target. Rollback SBOM Syft v1.57.1: 5,720 components.
- Release evidence attestations: [current provenance 72207357](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207357), [current SBOM 72207371](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207371), [rollback provenance 72207380](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207380), [rollback SBOM 72207401](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207401), [release manifest signature 72207415](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207415).
- Artifact [15033437028](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103294/artifacts/15033437028): 562,609,467 bytes; digest `sha256:06a0618604531d035efb6c9a551d9042ab78f163c5fd4d24f615aee2a8ecd9a3`; expires 2026-12-25.
- S3Mock used `RAVEN23_OBJECT_STORAGE_*` and the real Laravel filesystem config. It validates API compatibility only, not provider-specific lifecycle/versioning/ACL, pricing, real staging credentials or transfer performance.
- This run is disposable CI/loopback evidence only; no registry push, cloud load balancer, cloud identity, staging resource or production deployment.
- 10B remains OPEN because `main.protected=false`; Phase11B2 enforcement remains blocked. 11A cost scorecard remains unpriced until region/workload/retention/HA/budget inputs are supplied.
