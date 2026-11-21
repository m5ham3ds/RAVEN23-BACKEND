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

## 3. الخدمات المقترحة

| المكوّن | العقد/الواجهة | المرشح الأول | شرط قابلية النقل |
|---|---|---|---|
| HTTP runtime | HTTP app interface | Nginx + PHP-FPM أو FrankenPHP | اختيار بعد قياس |
| قاعدة البيانات | PostgreSQL | Neon مؤقتًا، RDS خيار لاحق | SQL قياسي وتجنب Neon-only |
| ملفات المستخدم | Storage adapter | S3 عند اختيار AWS | واجهة Laravel filesystem/S3 |
| Cache | Laravel Cache | file/array للتطوير، Redis عند الحاجة | لا تحفظ بيانات أساسية في cache فقط |
| Queue | Laravel Queue | database مبدئيًا أو Redis لاحقًا | Jobs قابلة لإعادة المحاولة |
| Email | Laravel Mail | مزود يُختار بعد تقييم | adapter بدل ربط domain بالمزود |
| Push | Notification adapter | FCM بعد فحص ربط Android | لا تفترض التفعيل |
| Secrets | Runtime secret injection | GitHub Environments وAWS SSM/Secrets Manager | لا تُحفظ داخل Git |
| Monitoring | logs/metrics/alerts | CloudWatch عند AWS | telemetry لا يعتمد على المزود |
| DNS/TLS | API domain ثابت | يحدد في مرحلة AWS | اسم API يبقى عند تبديل الخادم |
| Container registry | OCI image | ECR عند AWS أو GHCR | نشر image digest ثابت |

## 4. خدمات AWS المرشحة — لم تُنشأ بعد

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

## 5. ضوابط أمن الأدوات
- GitHub إلى AWS عبر OIDC وصلاحيات IAM الأقل الممكنة.
- عدم وضع AWS access keys دائمة داخل GitHub secrets إذا أمكن OIDC.
- قفل صلاحيات CI وproduction environments.
- فحص dependencies والأسرار وصور Docker.
- عدم وضع DB URLs أو private keys أو OAuth secrets أو bearer tokens أو بيانات المستخدمين في Git أو logs.
- فصل قاعدة اختبار CI عن قواعد الإنتاج.

## 6. قيد التطوير عبر Android
يجب أن تظل عملية التطوير والمراجعة ممكنة عبر هاتف Android. ننقل builds الثقيلة والاختبارات إلى GitHub-hosted runners أو حاويات CI، ولا نجعل وجود كمبيوتر مكتبي شرطًا مخفيًا. نفضل PR صغيرة ومسارات واضحة وأوامر يمكن تشغيلها في Termux عند الحاجة.

## 7. توثيق اختيار أي أداة
عند الاختيار بين البدائل، سجل: البدائل، سبب الاختيار، قياسات الاختبار، التكلفة الحالية وما بعد التجربة، عبء الإدارة، تأثيره على قابلية النقل، طريقة التراجع، التاريخ والـcommit وتقرير المرحلة. لا تصف أي خدمة مرشحة بأنها مفعلة قبل وجود دليل.
