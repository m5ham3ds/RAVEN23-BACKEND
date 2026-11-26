# المرحلة 11 — البنية التحتية المحمولة والنشر المستقل عن المزود

**الحالة:** 11A portability baseline مكتمل تقنيًا؛ cost scorecard WAITING_INPUT؛ 11B1 DESIGN_COMPLETE، ومعالجة فصل صلاحيات validation/attestation منفذة ومثبتة على c17fc84؛ 11B2 WAITING_EXTERNAL؛ 11C IN_PROGRESS بعقد OpenTofu محايد للمزود وفحوص CI محلية فقط؛ 11D–11F WAITING_EXTERNAL/DEPENDENCY. إعداد حماية main في 10B مؤجل يدوياً لما بعد اكتمال خطة تطوير Backend.  
**الاعتماديات:** 02–03 أساس runtime/security؛ 10G release evidence؛ إعدادات 10B اليدوية شرط قبل Phase11B2 enforcement والنشر، لكنها لا توقف تطوير Backend الوظيفي الحالي. تعتمد تجارب staging على 04/08 عند استخدام تكاملاتها، وعلى موافقة مستقلة للحساب والمنطقة والتكلفة.

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

### 11B1 — Secure deployment trust model design (DESIGN_COMPLETE؛ التصميم فقط، دون enforcement)

- تعريف الفصل بين CI build/test/scan وartifact attestation وOCI registry publication وstaging/production deployment.
- توثيق الفرق بين Sigstore signing OIDC وcloud workload identity؛ `id-token: write` في Docker workflow يستخدمه `actions/attest@v4` لتوقيع provenance/SBOM ولا يعني وجود cloud deployment role.
- تحديد claims اللازمة لأي trust policy مستقبلية: repository/owner identity، workflow identity، protected ref، environment، audience، event type وsource SHA.
- فصل هويات staging deploy وproduction deploy وone-off migration عن runtime application identity.
- اشتراط أن ينشر deploy الـimage digest الذي اجتاز CI، بعد التحقق من provenance/SBOM/manifest ومصدر workflow والـSHA؛ يمنع rebuild أثناء deploy.
- تحديد مرشحي required checks دون تغيير إعداد GitHub الحالي: Docker وAuthentication وSecurity؛ Pint report ليس blocking check مستقلاً، وstrict Pint داخل Docker هو gate.
- منع `pull_request_target` وأي workflow يعرّض deployment credentials لمدخلات غير موثوقة.
- تم تنفيذ remediation لحدود الثقة في `.github/workflows/docker-build.yml`: Docker validation بصلاحية `contents: read` فقط، ووظيفة `attest-release` مستقلة مشروطة بـ`main` مع `push` أو `workflow_dispatch` وتعتمد على نجاح build. صلاحيات OIDC/Sigstore محصورة في وظيفة التوقيع، ولا تتاح لأحداث pull_request. تحقق ذلك على `c17fc84`؛ التفاصيل والأدلة في تقرير 26 نوفمبر 2026.

### 11B2 — Enforce trust controls and OIDC (WAITING_EXTERNAL)

- تفعيل branch protection/required checks عبر Repository Admin بعد اعتماد سياسة PR/merge متوافقة مع main-only.
- إنشاء GitHub Environments منفصلة لـstaging وproduction مع approvals المطلوبة.
- بعد اختيار provider وموافقة المستخدم: trust policy ضيقة مرتبطة بالمستودع والـworkflow والـref والـenvironment والـaudience.
- صلاحيات نشر قصيرة العمر ومحدودة، مع فصل deploy identity عن runtime DB identity وعن DB_MIGRATION_URL.
- لا تُنشأ roles أو secrets أو environments أو deployments في المسار 11B1.

### 11C — Infrastructure as Code design and validation (IN_PROGRESS — provider-neutral baseline)

- تم اختيار OpenTofu CLI كأساس مؤقت ومحايد للمزود، مع تثبيت نسخته في `.opentofu-version`. لا يعني ذلك اختيار مزود استضافة أو ضمان توافق كل provider/module؛ تُراجع إصدارات provider الفعلية بعد قرار المزود.
- أُنشئ `infra/opentofu/` ليحوي platform contract وdeployment descriptor منطقيين فقط: OCI image، PostgreSQL، S3-compatible private storage، Laravel cache/queue abstractions، immutable image digest، وفصل هويات النشر والتشغيل والترحيل.
- حدود الوحدات المستقبلية موثقة ومنفصلة: network, compute, database, object-storage, secrets, monitoring, registry. لا توجد provider/resource/data/backend blocks بعد.
- workflow مستقل يختبر `fmt`, `init -backend=false`, `validate`, وprovider-free `plan`; جميع صلاحياته `contents: read` ولا يملك credentials.
- guardrail يمنع إضافة provider-specific resources/modules أو remote backend/cloud block أو state/backup/plan/tfvars files أو أمر `apply` قبل موافقة المالك؛ أضيفت 15 حالة regression تعتمد fixtures مؤقتة وتختبر المنع والقبول، بما فيها أوامر apply في backticks و$() حتى لا تتحول رسائل echo إلى تنفيذ shell غير مقصود.
- صُمم state management ليشترط العزل حسب البيئة، locking، نسخًا احتياطية قابلة للاستعادة، تشفير state/plan، ومفاتيح خارج المستودع؛ لا backend أو key manager مختار حتى الآن.
- المتبقي لإغلاق 11C: اعتماد provider/region/workload/cost ثم إضافة adapter modules واختبارها بـprovider versions مقفلة ومراجعة plan آمن. لا يوجد apply أو provisioning في هذه الشريحة. ما زالت هذه الشريحة provider-neutral؛ اجتياز regression guard لا يمنح إذن provisioning.

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

أضيفت aliases التخزين المحايدة في إعداد S3 مع إبقاء AWS_* fallback backward-compatible، ونُقل اختبار S3Mock لاستخدام aliases الجديدة. أسماء SQS/DynamoDB/SES تبقى optional AWS adapters ولا تستخدمها defaults. أُنشئ IaC scaffold محايد للمزود في `infra/opentofu/` مع workflow تحقق فقط؛ لا توجد provider resources أو state backend أو deployment workflow. أُزيلت إشارة Render القديمة من تعليق Google client ID دون تغيير سلوكه.

## 6. الاعتماديات والممنوعات

**المسموح الآن:** تصميم البنية ومقارنة الخيارات وتحديث scorecard دون اختلاق أسعار؛ scaffold محايد للمزود؛ OpenTofu fmt/validate/provider-free plan؛ وسياسات state/locking/backup/encryption موثقة دون إنشاء backend أو مفاتيح. تبقى provider-specific modules مشروطة باختيار المزود وموافقته.

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

اكتمل جرد العقود التقنية وportability matrix وتصميم trust boundaries. كما نُفذت معالجة فصل صلاحيات Docker validation عن وظيفة attestation الموثوقة واجتازت بوابات CI الأربع على c17fc84؛ هذا لا يساوي تفعيل enforcement على الفرع أو إنشاء cloud identity. بدأ 11C بعقد OpenTofu محايد للمزود وفحوص CI لا تستخدم backend أو provider أو credentials أو apply. يبقى تسعير scorecard الخاص بـ11A معلقًا حتى اعتماد مدخلات المنطقة والحمل والبيانات والاحتفاظ وHA/RPO/RTO والميزانية. لا يبدأ 11B2 enforcement إلا بعد إغلاق 10B عبر Repository Admin، ولا تبدأ 11D provisioning أو IaC apply أو staging قبل اختيار المزود والمنطقة والتكلفة وموافقة المستخدم.

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


## 11. Phase11B1 — تدقيق حدود الثقة وتصميم النشر الآمن — 25 نوفمبر 2026

### 11.1 نتيجة مراجعة الملفات الفعلية

تمت مراجعة شجرة المستودع وملفات workflows والإعدادات على main SHA `1b57b1da178dfd993c5f9bc1e2a19136af797f21`.

| الملف/المكوّن | الحالة المرصودة | الدلالة الأمنية |
|---|---|---|
| `.github/workflows/docker-build.yml` | يعمل على push وpull_request إلى main وworkflow_dispatch؛ جميع مراحل build/test/release evidence/attestations داخل job واحد | لا يوجد فصل صلاحيات بين اختبار PR وإصدار أدلة release |
| Docker workflow permissions | `contents: read` و`id-token: write` و`attestations: write` و`artifact-metadata: write` على مستوى workflow | صلاحيات OIDC/attestation أوسع من حاجة خطوات الاختبار، وليست مقيدة على مستوى job أو event |
| خطوات `actions/attest@v4` | توقيع provenance وSBOM وrelease manifest دون شرط event/ref صريح على الخطوات | يجب فصلها في job إصدار موثوق بعد نجاح الاختبارات وعلى source ref موثوق |
| Auth / Security / Pint workflows | `contents: read` فقط في المستوى الحالي | Auth وSecurity بوابات candidates، وPint report ينتج artifact |
| Pint | التقرير المستقل يرفع الملفات المنسقة كـartifact ولا يكتبها في main؛ Docker يشغل `pint --test` على PHP المتغير | Docker job هو بوابة Pint الفعلية؛ تقرير Pint ليس required check مستقلًا |
| Security | Gitleaks يفشل عند العثور على secret؛ تقارير Trivy filesystem وimage تستخدم `--exit-code 0`؛ خطوة منفصلة تفشل على fixable HIGH/CRITICAL image vulnerabilities مع `--ignore-unfixed` | نجاح Security لا يعني خلو كل تقارير Trivy من كل finding |
| Release evidence | Docker archive مربوط بـsource SHA وimage config digest وbase digest وSHA256 للـarchive/SBOM وprovenance/manifest موقع | هذا دليل archive/local image؛ ليس OCI registry manifest digest |
| Auth HTTP smoke | workflow_dispatch يدوي، GET-only لـ/up و/ready و/health وتسجيل المسارات | لا credentials أو إنشاء حساب أو migrations |
| GitHub branch/tree | `main.protected=false`، لا deployment workflow ولا Environment محمية ولا cloud OIDC role أو registry push أو IaC directory | لا توجد حماية merge أو قناة نشر سحابية مهيأة |

### 11.2 مواصفات فصل الصلاحيات قبل enforcement

1. **CI validation:** `contents: read` فقط افتراضيًا؛ لا `id-token: write` ولا `attestations: write` ولا credentials سحابية.
2. **Release attestation:** job مستقل بعد نجاح Docker/Auth/Security، وعلى push موثوق إلى `refs/heads/main` فقط؛ أقل صلاحيات Sigstore المطلوبة، دون cloud deploy permissions.
3. **PR isolation:** لا `pull_request_target`، ولا secrets أو deploy identities لشفرة PR؛ لا يصدر PR attestations موثوقة صالحة للنشر.
4. **Registry publication:** job مستقل ينشر artifact/image المختبر نفسه مرة واحدة إلى OCI registry ويسجل manifest digest؛ لا rebuild أثناء staging أو production.
5. **Staging/Production:** GitHub Environments منفصلة، approvals/reviewers وbranch restrictions؛ Production approval منفصل ولا يستطيع staging identity تنفيذ production.
6. **Migration identity:** مهمة واحدة تستخدم `DB_MIGRATION_URL` عند الحاجة؛ لا تمرر هذه الهوية إلى web runtime أو queue workers.
7. **Runtime identity:** DB application role محدود بـCRUD المطلوب؛ لا create role/database/schema ولا deploy rights ولا قراءة deploy secrets.
8. **Rollback:** الرجوع إلى digest سبق التحقق من provenance/SBOM/manifest؛ لا يعاد build للنسخة القديمة أثناء rollback.

### 11.3 عقد claims لهوية GitHub OIDC المستقبلية

| Claim / attribute | القيد المطلوب |
|---|---|
| issuer (`iss`) | GitHub Actions issuer الرسمي فقط |
| audience (`aud`) | قيمة مخصصة للمستهلك/المزود؛ لا wildcard ولا audience مشتركة بين staging وproduction |
| repository identity | `repository` ويفضل `repository_id` الثابت للمستودع المحدد |
| owner identity | `repository_owner` أو المعرّف الثابت للمالك |
| workflow identity | مسار workflow محدد و`workflow_ref` مقيد إلى ref معتمد |
| source ref | `refs/heads/main` المحمي فقط لـrelease/deploy؛ لا PR أو fork refs |
| event | allowlist صريحة للأحداث المطلوبة؛ نشر البيئة لا يُستمد من حدث PR |
| environment | `staging` أو `production` المحددة فقط لهوية النشر؛ تطابق Environment protection |
| source SHA / run | ربط العملية بـ`sha` وrun ID ومحاولة التنفيذ وprovenance |
| reusable workflow | عند استخدامه، يقيد مساره وهويته وإصداره/commit المثبت بقدر ما يدعمه المزود |

لا تُثبّت صيغة subject أو audience الخاصة بمزود قبل اختياره؛ يجب فحص JWT claims الفعلية ووثائق المزود عند التنفيذ. لا يدخل `actor` أو اسم branch غير محمي وحده كدليل كافٍ.

### 11.4 فصل الهويات

| الهوية | المسموح | الممنوع |
|---|---|---|
| CI builder | قراءة المصدر وbuild/test/scan | production secrets أو DB أو deploy API |
| Attestor | توقيع provenance/SBOM/manifest لأثر محدد | إنشاء موارد أو نشر أو تعديل بيانات |
| Registry publisher | push image immutable/digest محدد | DB access أو runtime secrets |
| Staging deployer | نشر digest متحقق إلى staging فقط | production أو DB migration role |
| Production deployer | نشر digest معتمد بعد approval | تغيير المصدر أو rebuild أو DB admin |
| Migration runner | migration صريحة بهوية DB مستقلة | serving requests أو queue worker |
| Runtime application | DB_URL وموارد runtime المحددة | DB_MIGRATION_URL أو cloud admin/deploy |

### 11.5 ضوابط مطابقة الأدلة قبل النشر

- تطابق repository identity وsource SHA وworkflow identity بين commit وprovenance وSBOM وmanifest.
- التحقق من التوقيع والـsubject digest والسياسة قبل نشر أو تشغيل image.
- بعد registry push، يسجل OCI manifest digest؛ ولا يستبدل بـGitHub artifact digest أو Docker archive SHA-256 أو image config digest.
- جميع required checks خضراء على SHA نفسه، مع branch protection مفعّلة قبل release/deploy.
- لا تعد نتيجة Pint report المستقلة gate طالما Docker strict Pint هو check الفعلي.
- تقارير Trivy غير الحاجبة تظل evidence advisory؛ أي تغيير لعتبة security gate يحتاج قرارًا معلنًا واختبارات مثبتة.
- لا توجد في 11B1 صلاحيات أو trust policy فعلية أو provider معتمد.

### 11.6 إغلاق التصميم

**11B1 DESIGN_COMPLETE — design only.** اكتمل توثيق تدفق الثقة وclaim allowlist وفصل الهويات ومتطلبات digest/attestation وفجوة least-privilege. لم يتم تغيير workflow permissions أو تنفيذ trust policy فعلية. تبقى 11B2 WAITING_EXTERNAL بسبب 10B وقرارات المزود/المنطقة/التكلفة. وتبقى 11A cost scorecard WAITING_INPUT.


## 12. Phase11B1 trust-boundary remediation — verified 26 November 2026

Repository SHA: c17fc84ac0f6acbe4a6d300913871441fb4e7ebe (workflow and documentation baseline; application/runtime baseline remains 84b87d9e2b746a0fa6c1fca26ae57c02d36d9181).

- Top-level workflow permission is contents: read; the docker-build job keeps contents: read only.
- attest-release runs only for refs/heads/main on push or workflow_dispatch, and only after docker-build succeeds. Its only elevated permissions are id-token: write, attestations: write, and artifact-metadata: write. Pull-request events do not receive these permissions.
- actions/download-artifact@v8.0.2 resolves successfully. The signing job verifies the source/run metadata, rollback inputs and hashes, generates current and rollback provenance/SBOM attestations, signs the immutable release manifest, validates SHA256SUMS, and uploads the release bundle.
- Docker Validation [45723087433](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433) SUCCESS: SQLite 150/1,704; PostgreSQL 31/546 with one optional skipped test; S3Mock 1/8; runtime/rollback rehearsals passed.
- Authentication [45723087469](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087469), Pint [45723087423](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087423) and Security [45723087484](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087484) all SUCCESS on the same SHA. Pint's changed-file steps were skipped because this commit changed no PHP source; strict Pint checks in Docker passed. Trivy production-image summary was Clean.
- Attestations: [current provenance](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416097), [current SBOM](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416109), [rollback provenance](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416119), [rollback SBOM](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416136), [manifest signature](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416150).
- Release bundle artifact [15072603777](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433/artifacts/15072603777), SHA-256 a12ddc732dcd2d2cff30df5ba73b236b32f418c51523b5a7c796778d94acb3ad.
- This verifies the CI trust boundary only. No OCI registry push, cloud role, GitHub environment, staging/production deployment, provider selection, paid resource, production database or secret was created or touched.
- Next gate: Phase10B remains OPEN because GitHub reports main.protected=false and there are no rulesets. The GitHub connection exposes reads and code writes but no branch-protection/ruleset write operation. Phase11A cost scorecard also remains WAITING_INPUT until workload, region, growth, retention, HA/RPO/RTO and budget are confirmed. Therefore Phase11B2 remains blocked; do not start 11C–11F.


## قرار تأجيل إعداد حماية main — 26 نوفمبر 2026

تأجلت إعدادات Branch protection وRequired status checks في Phase10B بطلب المستخدم إلى ما بعد اكتمال تطوير Backend بالكامل. لا تمنع هذه الخطوة تنفيذ واختبار وظائف Backend الحالية. لكنها تظل شرطاً قبل بدء 11B2 enforcement أو تشغيل مسار Staging/Production. لا يتم تجاوز موافقة provider/region/cost أو اعتماديات Phase04/08 عند استخدام التكاملات الخارجية.
