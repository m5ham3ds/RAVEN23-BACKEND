# المرحلة 11 — البنية التحتية المحمولة والنشر المستقل عن المزود

**الحالة:** DESIGN_IN_PROGRESS — بدأ 11A لتقييم معماريتي الاستضافة وتثبيت حدود portability. اختيار المزود وprovisioning وstaging/production deployment تبقى WAITING_EXTERNAL.  
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

### 11B — Secure deployment trust model (لم يبدأ)
- تثبيت required checks وbranch protection وenvironment approvals قبل نشر production.
- تحديد OIDC/workload identity والسياسات الأقل صلاحية.
- فصل build artifact عن registry image وعن staging/production deployment.
- منع إعادة build للصورة بعد اختبارها؛ نشر digest نفسه.

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
