# سجل الأدوات والتقنيات والخدمات

هذا الملف يميز بين ما ثبت وجوده حاليًا، وما نوصي باستخدامه، وما يزال خيارًا ينتظر قرارًا. مجرد ذكر خدمة هنا لا يعني أنها مفعّلة أو تم إنشاء حساب لها.

## 1. الموجود حاليًا في المستودع

| الأداة/التقنية | الدليل الحالي | التوجيه |
|---|---|---|
| GitHub private repository | `m5ham3ds/RAVEN23-BACKEND` | المصدر الرئيسي للكود |
| GitHub Actions | Docker validation وauth smoke وDB diagnostics | توحيد CI/CD لاحقًا |
| Laravel 13 | Dockerfile ينشئ Laravel 13 | الاحتفاظ به وتثبيت الاعتماديات |
| PHP 8.4 | Docker build يستخدم PHP 8.4 | توحيد النسخة المدعومة |
| Composer 2 | يستخدم أثناء build | إضافة composer.json وcomposer.lock إلى الجذر |
| Sanctum | bearer tokens موجودة | الحفاظ عليه وتقويته |
| google/apiclient | يثبت أثناء build | الحفاظ على التحقق من Google |
| PostgreSQL | قاعدة بيانات التطبيق المحددة | عقد قاعدة البيانات |
| Neon | المزود المهيأ حاليًا | خدمة انتقالية لا يجب ربط منطق التطبيق بها |
| Docker/Buildx | Dockerfile وبناء CI | صورة تشغيل موحدة |
| SQLite | اختبارات Feature | اختبارات سريعة فقط |
| PostgreSQL 16 CI service | اختبارات تكامل | استمرار اختبارات DB الحقيقية |
| Render | الخدمة الحية التي تشير لها workflows | مزود حالي مؤقت |

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
