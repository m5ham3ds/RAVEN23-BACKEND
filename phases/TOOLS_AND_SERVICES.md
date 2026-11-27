# سجل الأدوات والتقنيات والخدمات

هذا الملف يميز بين ما ثبت وجوده حاليًا، وما نوصي باستخدامه، وما يزال خيارًا ينتظر قرارًا. مجرد ذكر خدمة هنا لا يعني أنها مفعّلة أو تم إنشاء حساب لها.

## 1. الموجود حاليًا في المستودع

| الأداة/التقنية | الدليل الحالي | التوجيه |
|---|---|---|
| GitHub private repository | `m5ham3ds/RAVEN23-BACKEND` | المصدر الرئيسي للكود |
| GitHub Actions | 6 workflows: Docker, Auth regression, Security, Pint report, GET-only HTTP smoke, IaC validation | بوابات CI الحالية والتحقق المحايد؛ لا cloud deployment workflow |
| Laravel 13 | مصدر Laravel ملتزم في Git مع composer.json/lock وartisan وbootstrap/app/routes | أساس Backend الحالي؛ لا scaffold خارجي غير محفوظ |
| PHP 8.4 | Docker build يستخدم PHP 8.4 | توحيد النسخة المدعومة |
| Composer 2 | composer.json وcomposer.lock ملتزمان في الجذر؛ CI validate/install/audit | تثبيت الاعتماديات من lock؛ لا composer update أثناء release |
| Sanctum | bearer tokens موجودة | الحفاظ عليه وتقويته |
| google/apiclient | يثبت أثناء build | الحفاظ على التحقق من Google |
| PostgreSQL | عقد قاعدة البيانات واختبارات PostgreSQL 16 المؤقتة | لا مزود مستضاف محدد الآن؛ provider selection مؤجل وفق D-050 |
| Neon | مرجع تاريخي/انتقالي في سجلات المشروع | لا يُعد اختيارًا أو وجهة تشغيل معتمدة لهذه الخطة؛ source/destination DB decision مؤجل |
| Docker/Buildx | Dockerfile وبناء CI | صورة تشغيل موحدة |
| SQLite | اختبارات Feature | اختبارات سريعة فقط |
| PostgreSQL 16 CI service | اختبارات تكامل | استمرار اختبارات DB الحقيقية |
| Render | مرجع تاريخي في workflows/config القديمة؛ أزيلت lifecycle workflows التي كانت تستهدف production | لا توجد حاليًا قناة deployment تثبت نشرًا إلى Render؛ لا يُعد قرار استضافة مستقبلي |

## 2. أدوات التطوير والاختبار المطلوبة

| الأداة | الغرض | الحالة المطلوبة |
|---|---|---|
| Git/GitHub | نسخ، فروع، PR، tags | أساسي |
| GitHub Actions | بناء واختبار وفحص ونشر | أساسي |
| PHP CLI + Composer 2 | الاعتماديات والاختبار وArtisan | أساسي في CI |
| Laravel Artisan | routes/migrations/tests/config | أساسي |
| Docker + Buildx | إعادة بناء الصورة نفسها | أساسي |
| PostgreSQL 16+ | اختبارات التكامل | أساسي |
| Laravel Test Runner / PHPUnit | اختبارات Feature/Integration | الحفاظ على النمط الحالي |
| Laravel Pint | تنسيق كود PHP | مطلوب |
| Composer Audit | فحص ثغرات الاعتماديات | مطلوب |
| PHPStan/Larastan | التحليل الساكن | إدخال تدريجيًا |
| Gitleaks | كشف الأسرار المتسربة | مطلوب |
| Trivy | فحص صورة الحاوية والثغرات | مطلوب |
| Terraform أو OpenTofu | تعريف البنية السحابية ككود | مطلوب قبل إنشاء AWS |
| pg_dump / pg_restore | النسخ والاستعادة والترحيل | مطلوب |
| k6 أو أداة API load test مماثلة | اختبار ضغط API | قبل إطلاق الإنتاج |

يجب تثبيت إصدارات الأدوات أو قيودها وتسجيلها في CI. لا نضيف أداتين لهما الوظيفة نفسها بلا سبب مكتوب.

## 3. الخدمات المقترحة — بعقود محايدة للمزود

| المكوّن | العقد القياسي | التوجيه الحالي | ضمان قابلية النقل |
|---|---|---|---|
| HTTP runtime | OCI container + HTTP | FrankenPHP/Caddy في صورة التطبيق الحالية | الصورة نفسها على أي host يدعم OCI؛ لا startup خاص بـRender |
| Database | PostgreSQL | Neon مسجل كمزود انتقالي؛ RDS وPostgreSQL المُدار بدائل غير مختارة | SQL قياسي وmigrations وpg_dump/pg_restore |
| Object storage | S3-compatible private API | AWS S3 مرشح واحد؛ لا real bucket/staging مثبت | Laravel Storage adapter، endpoint/region/credentials من runtime، واختبارات S3Mock |
| Cache | Laravel Cache | database/file مبدئيًا؛ Redis عند الحاجة المثبتة | لا تحفظ البيانات الأساسية في cache وحده |
| Queue | Laravel Queue | database حاليًا؛ Redis driver محتمل لاحقًا | jobs قابلة لإعادة المحاولة وoutbox مستقل عن المزود |
| Email | Laravel Mail | لا provider محدد؛ الإرسال الحقيقي معطل افتراضيًا | adapter وإعدادات runtime منفصلة |
| Push | Notification transport | FCM/OneSignal مؤجلان بقرار المستخدم | provider-neutral outbox؛ sender معطل |
| Secrets | Runtime secret injection | secret manager حسب المنصة المختارة | لا قيم أسرار في Git أو logs |
| Monitoring | Structured logs / metrics / alerts | telemetry محايدة؛ CloudWatch مرشح عند AWS | لا domain dependency على خدمة رصد |
| DNS/TLS | Stable API hostname + TLS | يحدد لاحقًا حسب provider | بقاء اسم API ثابتًا عند تبديل compute |
| Container registry | OCI image digest | ECR أو GHCR أو سجل متوافق؛ لا push حاليًا | نشر digest الذي اجتاز CI دون rebuild |

## 4. مقارنة معماريتي الاستضافة — كلاهما مرشح ولم يُعتمد

| المجال | الخيار A — AWS متكامل | الخيار B — متعدد المزودين وقابل للنقل |
|---|---|---|
| Compute | EC2 أو ECS/Fargate بعد قياس التكلفة | Linux container host مستقل |
| PostgreSQL | RDS PostgreSQL | PostgreSQL مُدار لدى مزود مستقل |
| Files | S3 private buckets | Object storage يقدم S3-compatible API |
| Cache/Queue | database queue أولًا؛ ElastiCache عند الحاجة | database queue أولًا؛ Redis-compatible مستقل عند الحاجة |
| Registry | ECR | OCI registry مستقل مثل GHCR أو بديل متوافق |
| Identity/Secrets | GitHub OIDC إلى IAM role مؤقت وSSM/Secrets Manager | secret manager/runtime injection وOIDC/workload identity حيث يدعم |
| Monitoring | CloudWatch | telemetry provider-neutral أو خدمة مستقلة |
| DNS/TLS/Edge | Route 53 وTLS/load balancing عند الحاجة | DNS/TLS/load balancer مستقل عن compute |
| نقاط القوة المتوقعة | تكامل مركزي وخدمات IAM وشبكات وإدارة ضمن منظومة واحدة | تبديل compute أو DB أو storage على حدة وتقليل الارتباط بمنصة واحدة |
| المخاطر/التكلفة | اعتماد أكبر على AWS وفاتورة خدمات وegress/backup/monitoring | جهد تكامل وتشغيل ومراقبة وفوترة عبر عدة مزودين |

**لا يوجد فائز أو مزود مختار.** هذه مقارنة معمارية وليست عرض أسعار أو قياس أداء. قبل القرار يجب مقارنة التكلفة الشهرية الكاملة (compute/DB/storage/egress/backups/logs/registry/DNS/TLS)، وبعد انتهاء credits، والمنطقة والتأخير وعبء الإدارة وbackup/restore وRPO/RTO وسهولة الخروج ونقل البيانات.

**ثوابت الخيارين:** Laravel domain لا يستورد SDK لمزود الاستضافة مباشرة؛ PostgreSQL عقد البيانات؛ ملفات المستخدم عبر adapter؛ endpoints/regions/credentials runtime-only؛ image digest ثابت؛ وCI identity قصيرة العمر. أي provider-specific IaC يوضع في modules منفصلة ولا يغيّر منطق التطبيق.

## 5. خدمات AWS المرشحة — لم تُنشأ بعد

- **EC2:** خادم التطبيق الأول المحتمل، بعد مقارنة مواصفات الذاكرة والمعالج والتكلفة والصيانة.
- **EBS:** قرص نظام وتشغيل وملفات مؤقتة؛ ليس النسخة الوحيدة من بيانات المستخدم أو backups.
- **RDS PostgreSQL:** قاعدة مُدارة محتملة؛ يجب مقارنة التكلفة الدائمة والتخزين والنسخ والاتصالات والمنطقة.
- **S3:** تخزين ملفات المستخدم والنسخ الاحتياطية بصورة منفصلة عن الخادم.
- **IAM + OIDC:** صلاحيات مؤقتة ومحددة من GitHub Actions.
- **ECR:** سجل صور حاويات إذا تم اختياره.
- **CloudWatch:** سجلات ومقاييس وتنبيهات.
- **Secrets Manager أو SSM Parameter Store:** أسرار وقت التشغيل وفق التكلفة والاحتياج.
- **Route 53 وشهادات TLS:** عند الحاجة لإدارة DNS والشهادات.

لا يُنشأ أي مورد فعلي قبل موافقة المستخدم على المنطقة والمواصفات وحدود الميزانية والتكاليف الشهرية المتوقعة والتنبيهات.

## 6. ضوابط أمن الأدوات
- GitHub إلى AWS عبر OIDC وصلاحيات IAM الأقل الممكنة.
- عدم وضع AWS access keys دائمة داخل GitHub secrets إذا أمكن OIDC.
- قفل صلاحيات CI وproduction environments.
- فحص dependencies والأسرار وصور Docker.
- عدم وضع DB URLs أو private keys أو OAuth secrets أو bearer tokens أو بيانات المستخدمين في Git أو logs.
- فصل قاعدة اختبار CI عن قواعد الإنتاج.

## 7. قيد التطوير عبر Android
يجب أن تظل عملية التطوير والمراجعة ممكنة عبر هاتف Android. ننقل builds الثقيلة والاختبارات إلى GitHub-hosted runners أو حاويات CI، ولا نجعل وجود كمبيوتر مكتبي شرطًا مخفيًا. نفضل PR صغيرة ومسارات واضحة وأوامر يمكن تشغيلها في Termux عند الحاجة.

## 8. توثيق اختيار أي أداة
عند الاختيار بين البدائل، سجل: البدائل، سبب الاختيار، قياسات الاختبار، التكلفة الحالية وما بعد التجربة، عبء الإدارة، تأثيره على قابلية النقل، طريقة التراجع، التاريخ والـcommit وتقرير المرحلة. لا تصف أي خدمة مرشحة بأنها مفعلة قبل وجود دليل.


## 9. تفاصيل خط الأساس المثبتة من Dockerfile وGitHub Actions

- Build stage: `php:8.4-cli-bookworm` مع Composer 2؛ ينفذ `composer validate --strict` و`composer install` و`composer audit` والاختبارات.
- Production runtime: `dunglas/frankenphp:1.13.6-php8.4-trixie` مع digest مثبت `sha256:bbe6057527f8dd9e270d9e2a33315f26b264ce8b0dbd19b67b0855a7899960ec`، وCaddy HTTP server، ومستخدم غير جذري `raven` UID/GID 10001، والمنفذ 10000.
- Laravel 13 وPHP 8.4. ملفا `composer.json` و`composer.lock` ملتزمان في الجذر؛ تشمل الاعتماديات Laravel Framework وSanctum وGoogle API Client و`league/flysystem-aws-s3-v3` ^3.35.3.
- PostgreSQL هو عقد قاعدة البيانات؛ Docker Validation يستخدم PostgreSQL 16 مؤقتة ويختبر أدوار application/migrator منفصلة. SQLite يستخدم لاختبارات Feature السريعة.
- اختبارات S3 تستخدم Adobe S3Mock منفصلًا. هذا يثبت عقد S3-compatible API في الاختبار ولا يثبت وجود bucket حقيقي أو staging credentials.
- GitHub Actions runner هو `ubuntu-latest`. الإصدارات المثبتة في Docker workflow: `actions/checkout@v7.0.1`، `docker/setup-buildx-action@v4.4.1`، `actions/attest@v4`، و`actions/upload-artifact@v7.0.2`.
- Release workflow يجمع Docker archive وCycloneDX SBOM وprovenance/attestations في GitHub Actions artifact. تشغيل 25 نوفمبر 2026 على SHA `a86fb3a` رفع artifact ID `15026689226`؛ هذا artifact ليس container registry push.
- لا يوجد registry push أو cloud deployment workflow أو دليل على AWS OIDC deployment role. لا يوجد نشر إنتاج في تشغيل 10G.
- Render وNeon مسجلان كخدمات قائمة/انتقالية، لا كقرار دائم. إعدادات DB والملفات والqueue/secrets تظل runtime configuration.
- main حاليًا `protected=false`؛ required checks وbranch protection تنتظر صلاحية Repository Admin. لا يبدأ نشر production قبل معالجة ذلك.

هذه تفاصيل التنفيذ المثبتة الآن. لا تعني أن S3 الحقيقي أو mail/Push providers أو registry أو النشر السحابي قد تم تفعيلها.


## 10. Phase11A — جرد نقاط اعتماد المزود داخل التنفيذ

| الطبقة | الحد/الإعداد الحالي | الافتراضي المثبت | نقاط المزود الخاصة / ما بقي |
|---|---|---|---|
| Compute | OCI image مع FrankenPHP/Caddy | container runtime موحد | لا Render/Neon startup coupling؛ لا workflow نشر سحابي |
| Database | PostgreSQL عبر `DB_URL` أو `DB_HOST/PORT/...` | PostgreSQL؛ remote production يفرض TLS mode آمن | لا Neon SDK في domain؛ Neon يظهر كمرجع تشغيل انتقالي فقط |
| Private object storage | `PrivateObjectStorage` interface و`FilesystemPrivateObjectStorage` فوق Laravel `FilesystemAdapter`؛ اختيار disk عبر `RAVEN23_PRIVATE_STORAGE_DISK` | local private disk | قرص S3-compatible يقبل endpoint مخصصاً؛ أضيفت `RAVEN23_OBJECT_STORAGE_*` مع fallback إلى `AWS_*` للتوافق. S3Mock يختبر الأسماء المحايدة |
| Cache | Laravel Cache | database | Redis وMemcached اختياريان؛ DynamoDB driver إعداد AWS اختياري وليس default |
| Queue | Laravel Queue | database | Redis وBeanstalkd اختياريان؛ SQS إعداد AWS اختياري وليس default |
| Email | Laravel Mail transport | `log`؛ auth mail disabled | SMTP وPostmark وResend وMailgun وSES متاحة كخيارات؛ لا مزود إرسال حقيقي مفعل |
| Push | `PushNotificationTransport` interface | `DisabledPushNotificationTransport` وprovider `none` | لا FCM/OneSignal adapter أو credentials نشطة |
| Google identity | `GOOGLE_CLIENT_ID` من environment مع fallback public client ID | environment configuration | لا اعتماد على Render بعد تعميم تعليق الإعداد؛ لا علاقة له بمزود compute |
| Registry/deploy | GitHub Actions release artifact موقّع | لا registry push ولا deployment | لا IaC ولا OIDC deployment role ولا DNS أو secrets نشر في المستودع |
| Observability | structured logs إلى stderr | framework logging | لا CloudWatch/Datadog/Sentry exporter أو خدمة رصد سحابية مفعلة في هذه المرحلة |

### نتائج التدقيق والقرار

- كود domain لا يستدعي AWS SDK مباشرةً؛ حد التخزين يمر عبر Laravel Filesystem adapter. مكتبة Flysystem S3/AWS SDK هي driver implementation وليست ربطًا مباشرًا بين domain وAWS.
- مفاتيح `AWS_*` السابقة كانت نقطة coupling في config S3. أصبحت الأسماء المحايدة `RAVEN23_OBJECT_STORAGE_*` ذات أولوية، مع بقاء aliases القديمة fallback حتى لا تكسر أي runtime قائم.
- اختبار S3Mock في Docker workflow يستخدم الآن المفاتيح المحايدة ويختبر مسار الإعداد الفعلي في `config/filesystems.php`.
- SQS وDynamoDB وSES خيارات AWS مضمّنة في Laravel config، لكنها ليست defaults؛ لا يجوز مساواتها باعتماد التطبيق عليها. بدائل database/Redis/SMTP/Postmark/Resend/Mailgun متاحة حسب الخدمة.
- لم يُعثر في root inventory على مجلد IaC أو deployment workflow؛ workflows الخمسة الحالية هي CI validation/auth/Pint/security فقط.
- لا تزال تكلفة التخزين الفعلية وقيود نقل S3 metadata/ACL/lifecycle/versioning/retention تتطلب مقارنة providers قبل إعلان تبديل كامل متكافئ.

## 11. Phase11A — Portability exit matrix and cost scorecard

### Portability matrix

| الطبقة | العقد الحالي | إثبات CI الحالي | ما يلزم لإثبات النقل |
|---|---|---|---|
| Compute | OCI/FrankenPHP/Caddy | Docker runtime وhealth/readiness | تشغيل image على host آخر وقياس ingress/restart/scale |
| PostgreSQL | PostgreSQL URL + TLS + roles | PostgreSQL 16 disposable ومستخدم app/migrator منفصل | pg_dump/pg_restore بين مزودين وفحص extensions/grants/collation/connections |
| Private objects | PrivateObjectStorage وLaravel Filesystem؛ S3-compatible endpoint | S3Mock CRUD/get/list/delete/private visibility | multipart/metadata/presigned/versioning/lifecycle/retention/large copy/egress |
| Cache | Laravel Cache؛ database default | config/runtime baseline | cache يعاد بناؤه إذا كان غير authoritative؛ Redis يحتاج locks/TTL/eviction/TLS tests |
| Queue | Laravel Queue؛ database default | worker guards/outbox tests | drain/replay/idempotency/dead-letter semantics عند نقل backend |
| Email | Laravel Mail؛ log default | fake/array/log | staging حقيقي وSPF/DKIM/DMARC/bounce/webhooks |
| Push | interface وdisabled transport | outbox/kill-switch | adapter/device token migration/native idempotency بعد اعتماد Phase08 |
| Secrets | runtime injection | APP_KEY gate ولا secret داخل image | secret manager/rotation/audit وفصل الهويات |
| Registry | GitHub artifact + Sigstore | Docker archive/SBOM/attestations | OCI push/manifest digest verification |
| Observability | stdout/stderr structured logs | log redaction | collector/metrics/alerts/retention |
| DNS/TLS | edge TLS خارج container | proxy/TLS guards | domain/cert renewal/DNS TTL/cutover |
| Backup/DR | لا backup provider فعلي مثبت | migration/rollback لا يساوي backup restore | encrypted DB+objects export/retention/off-provider restore/RPO/RTO |

### Cost comparison template (unpriced)

| بند | حساب شهري/لمرة واحدة | AWS integrated | Multi-provider | Evidence/date |
|---|---|---|---|---|
| Compute | replicas × hours × CPU/RAM | TBD | TBD | TBD |
| PostgreSQL | instance + storage + IOPS + HA + backups | TBD | TBD | TBD |
| Object storage | GB-month + requests + versions/lifecycle | TBD | TBD | TBD |
| Egress/network | Internet + inter-region/provider + NAT/LB | TBD | TBD | TBD |
| Registry | image/SBOM storage + pulls + retention | TBD | TBD | TBD |
| Logs/metrics | ingest + retention + alerts | TBD | TBD | TBD |
| Secrets/identity | stored secrets/API calls/audit | TBD | TBD | TBD |
| DNS/TLS/edge | zones/queries/IP/LB/WAF if chosen | TBD | TBD | TBD |
| Support/operations | support plan + operator hours | TBD | TBD | TBD |
| **Monthly recurring** | **Sum before and after credits** | **TBD** | **TBD** | Same workload |
| **One-time exit** | **export + egress + import + validate + cutover/rollback** | **TBD** | **TBD** | Include both provider fees |
| **12-month total** | **12 × post-credit monthly + one-time exit** | **TBD** | **TBD** | Credits excluded from steady state |

Required inputs remain unspecified: region/user geography, requests/peak/concurrency, replicas and CPU/RAM, PostgreSQL size/growth/IOPS/connections/HA, object count/size/request classes/egress, backup retention/RPO/RTO, logs ingest/retention, support needs and monthly budget. No prices, weights or winner are assigned before these values are approved.

## 11. تحديث الحالة الفعلية للخدمات — 27 نوفمبر 2026

- **Backend runtime:** Laravel 13 / PHP 8.4 / FrankenPHP-Caddy / PostgreSQL standard URL / Docker OCI image؛ build and tests occur in GitHub Actions.
- **Data/cache/queue:** PostgreSQL contract؛ app role and one-off migration role منفصلان؛ database cache/session/queue defaults.
- **Storage:** local private disk default وS3-compatible Flysystem adapter؛ S3Mock يثبت contract test فقط، لا bucket/provider حقيقي.
- **Notifications:** encrypted outbox وqueue retry/dead-letter وdisabled Push transport؛ لا email sender أو push delivery provider مفعّل.
- **Billing:** entitlement/event/wallet read paths and internal ledgers؛ `purchases_enabled=false`, `plans=[]`; no payment provider/webhooks/top-ups.
- **Release:** build provenance/SBOM/attestations وimmutable release evidence + isolated container rollback rehearsal؛ لا OCI registry publication أو cloud deploy.
- **IaC:** OpenTofu 1.13.10 provider-neutral scaffold + validation only؛ no provider/resource/module/backend/cloud/credentials/apply.

### قرار المالك D-050

تؤجل جميع اختيارات المزود/المنصة/المنطقة/الخطة/التكلفة إلى ما بعد اكتمال أعمال Backend التي يمكن تطويرها واختبارها دون مزود. لا يُطلب الآن اختيار AWS/Render/Neon/S3/mail/FCM/OneSignal/payment provider أو أي خدمة أخرى. تستمر الأعمال المحايدة فقط، ولا تنشأ accounts/secrets/resources ولا يتم apply أو deploy. بعد اكتمال الأعمال المحايدة تجرى مقارنة شاملة وموافقات مستقلة لكل مورد.

## D-051 — توقيت إعادة فتح القرار بعد نهاية الخطة كاملة

لا يُطلب اختيار أي مزود أو منطقة أو خطة أو تكلفة عند إنهاء هذه المرحلة منفردة. جميع اختيارات المزودين تُؤجل إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل. حتى ذلك الوقت يستمر العمل المحايد الذي لا يحتاج مزودًا، وتبقى معايير القبول التي تستلزم مزودًا حقيقيًا أو staging/production operation مؤجلة ولا تُسجل COMPLETE. راجع phases/DECISIONS.md (D-050 وD-051).
