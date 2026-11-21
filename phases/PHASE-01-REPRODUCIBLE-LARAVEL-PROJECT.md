# المرحلة 01 — تحويل المستودع إلى مشروع Laravel مستقل قابل لإعادة البناء

**الحالة:** IN_PROGRESS  
**الاعتماديات:** المرحلة 00 COMPLETE

## سجل التنفيذ — 21 نوفمبر 2026

- فرع التنفيذ: `feature/phase-01-laravel-project`
- Pull Request: [#2](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/2) — مفتوح كمسودة، غير مدمج.
- آخر SHA موثق عند تحديث هذا السجل: `5a077cba5f75753cb83ec47f660c51eeb1212634`.
- أضيف `composer.json` و`composer.lock`؛ أظهر GitHub Actions نجاح حل الاعتماديات، `composer validate --strict`، والتثبيت النظيف.
- أضيف هيكل Laravel 13 الأساسي وإعداد PHPUnit وملفات البيئة النموذجية، وأُبقي منطق المصادقة الحالي.
- عُدلت مسارات اختبار المخطط لدعم SQLite المعزول، مع الإبقاء على PostgreSQL الافتراضي وقيود الإنتاج.
- Dockerfile أصبح يستخدم ملفات المشروع الموجودة في المستودع بدل `composer create-project`.
- أزيل workflow المؤقت الذي أنشأ lock بعد تثبيت الملف.
- Docker Validation #198 كان قيد التنفيذ عند آخر فحص؛ لا يوجد اعتماد نجاح نهائي بعد. Docker run #187 أُطلق قبل lock وفشل لأن `composer.lock` لم يكن موجودًا في commit الذي بُني؛ لا يُعد اختبارًا للكود بعد إنشاء lock.

**الحكم الحالي:** IN_PROGRESS. لا تسجل المرحلة COMPLETE حتى ينجح Docker build واختبارات SQLite وPostgreSQL على SHA نهائي واحد، وتُراجع النتائج المتبقية.

## الهدف
إزالة الاعتماد على إنشاء تطبيق Laravel جديد أثناء Docker build، وجعل المستودع يحتوي تطبيق Laravel كاملًا باعتماديات ثابتة وقابلة لإعادة البناء.

## الأعمال
1. إضافة ملفات Laravel الأساسية اللازمة إلى جذر المستودع وفق Laravel 13.
2. نقل ودمج الملفات الحالية إلى الهيكل الرسمي دون إسقاط أي منطق أو إعداد.
3. إنشاء `composer.json` مضبوط و`composer.lock` ملتزم بالمستودع.
4. تثبيت إصدار PHP والحزم المدعومة بوضوح، وحل اختلاف README (PHP 8.3+) مع Docker (PHP 8.4).
5. نقل إعدادات الاختبار وPHPUnit/Test Runner الحالية دون تبديل إطار الاختبارات بلا داعٍ.
6. التأكد من تحميل migrations وroutes وmodels/controllers من ملفات المستودع مباشرة.
7. إنشاء `.env.example` يحوي أسماء المتغيرات وقيمًا وهمية فقط.
8. ضبط `.gitignore` لمنع vendor و.env وlogs وcache ومفاتيح الأسرار.
9. تحديث Dockerfile ليبني من ملفات المشروع الفعلية ولا ينشئ مشروعًا جديدًا أثناء build.
10. إضافة تحقق مبكر من غياب manifests أو عدم توافق PHP/Composer.

## معايير القبول
- نجاح `composer validate --strict`.
- تثبيت نظيف من `composer.lock` دون تحديث تلقائي للحزم.
- عمل Artisan وroute:list وmigrate على قاعدة اختبار فارغة.
- نجاح اختبارات المصادقة الحالية دون تغيير سلوك API.
- عدم وجود `composer create-project` في Docker build الإنتاجي.
- عدم تسرب الأسرار إلى Git أو طبقات Docker.

## الحفاظ على العمل
تظل وظائف AuthController وUser Model وSanctum وGoogle verifier وmigrations واختبارات SQLite/PostgreSQL موجودة أو يتم تصحيحها باختبارات regression.

## الممنوع
لا تعِد كتابة المصادقة لمجرد نقل الملفات. لا تغير API contract ضمن هذه المرحلة إلا عند الضرورة المثبتة، ويجب عزل التغيير وتوثيقه.
