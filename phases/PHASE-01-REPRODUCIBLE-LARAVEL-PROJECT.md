# المرحلة 01 — تحويل المستودع إلى مشروع Laravel مستقل قابل لإعادة البناء

**الحالة:** COMPLETE  
**الاعتماديات:** المرحلة 00 COMPLETE

## سجل التنفيذ — 21 نوفمبر 2026

- فرع التنفيذ: `feature/phase-01-laravel-project`
- Pull Request: [#2](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/2) — مفتوح كمسودة، غير مدمج.
- آخر SHA موثق عند تحديث هذا السجل: `519c3f1039f05818b413d466fbc31793ee6daeb6`.
- أضيف `composer.json` و`composer.lock`؛ أظهر GitHub Actions نجاح حل الاعتماديات، `composer validate --strict`، والتثبيت النظيف.
- أضيف هيكل Laravel 13 الأساسي وإعداد PHPUnit وملفات البيئة النموذجية، وأُبقي منطق المصادقة الحالي.
- عُدلت مسارات اختبار المخطط لدعم SQLite المعزول، مع الإبقاء على PostgreSQL الافتراضي وقيود الإنتاج.
- Dockerfile أصبح يستخدم ملفات المشروع الموجودة في المستودع بدل `composer create-project`.
- أزيل workflow المؤقت الذي أنشأ lock بعد تثبيت الملف.
- Docker Validation #198 و#204 فشلا في اختبار المسارات بسبب طريقة تصفية CLI ثم بسبب استبدال طرق HTTP عند وجود URI له GET/PUT. جُمعت الطرق بدل استبدالها، كما أُنشئ ملف `.env` فارغ داخل مرحلة الاختبار لتجنب تحذيرات dotenv المتكررة. Docker #207: SUCCESS على commit `3f4ead...` — Docker image، SQLite (13 tests / 92 assertions)، PostgreSQL 16 (3 tests / 72 assertions) كلها نجحت. Docker #210 قيد التنفيذ على أحدث SHA `519c3f...` الذي يضيف migrations غير مدمرة واختبار حفظ بيانات users/cache/jobs. لا يغلق Phase 01 حتى ينجح #210 على هذا SHA.

**الحكم الحالي:** IN_PROGRESS. أضيفت أيضًا حماية للمigrations الأساسية كي لا تعيد إنشاء جداول users/cache/jobs التي قد تكون موجودة في قاعدة قديمة، وأضيف اختبار يثبت بقاء بيانات مستخدم وcache وqueue قائمة قبل migration. أُغلقت المرحلة بعد نجاح Docker build وSQLite وPostgreSQL على SHA موحد `519c3f1039f05818b413d466fbc31793ee6daeb6`؛ التفاصيل في تقرير `PHASE-01-COMPLETION-REPORT-2026-11-22.md`.

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
