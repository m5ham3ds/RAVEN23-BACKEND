# تقرير تقدم المرحلة 01 — 21 نوفمبر 2026

**الحالة:** IN_PROGRESS  
**تاريخ البدء:** 21 نوفمبر 2026  
**SHA الأساس:** 9f29fa55afa21c0445be82d4590a4a532bb1747c  
**فرع التنفيذ:** feature/phase-01-laravel-project  
**PR:** [#2 — Reproducible Laravel project](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/2)  
**أحدث SHA:** 519c3f1039f05818b413d466fbc31793ee6daeb6

## 1. ما نُفّذ

1. أضيفت نقطة تشغيل Laravel artisan وملفات bootstrap/providers وpublic index ومزود التطبيق ومتحكم الأساس ومسارات web/console.
2. أضيفت ملفات إعداد Laravel 13 الأساسية وملفات migrations الافتراضية وPHPUnit.
3. أضيف composer.json مع PHP ^8.3 وLaravel ^13.17 وSanctum وGoogle API Client، وأُنشئ composer.lock وثُبت في فرع التنفيذ.
4. عُدّل Dockerfile ليستخدم المشروع الفعلي من جذر المستودع ويثبت الاعتماديات من Composer lock بدل composer create-project.
5. عُدلت إعدادات قاعدة البيانات ليكون PostgreSQL هو الافتراضي مع السماح باستخدام SQLite في الاختبارات المعزولة فقط.
6. عُدلت مسارات تسوية مخطط المصادقة لتقبل SQLite في الاختبار، مع إبقاء قواعد PostgreSQL الإنتاجية حيث تدعمها القاعدة.
7. أضيف اختبار Foundation للتحقق من تشغيل Laravel، ووجود API route URIs وطرقها، وmigrations على SQLite فارغة.
8. أضيف اختبار regression يحاكي قاعدة قديمة تحتوي جدول users وصف مستخدم، وcache entry، وjob؛ ويتأكد من بقاء الصفوف بعد migrations الأساسية مع إضافة الجداول المفقودة فقط.
9. أُنشئت migrations Laravel الأساسية بأسلوب hasTable لتجنب إعادة إنشاء users/cache/jobs الموجودة، كما أصبحت عمليات down لها غير مدمرة لحماية بيانات قديمة.
10. أضيف .env.example بقيم placeholders فقط، وحدث .gitignore و.dockerignore، ويُنشأ ملف .env فارغ في مرحلة اختبار Docker فقط لتجنب تحذيرات dotenv.
11. أُعيد تنظيم README لتوثيق التثبيت والاختبار وقيود التشغيل الحالية.
12. أزيل workflow المؤقت الذي أنشأ Composer lock بعد نجاح توليده وتثبيته.

## 2. الاختبارات والأدلة

| الاختبار | النتيجة | الدليل / ملاحظة |
|---|---|---|
| Composer dependency resolution | PASS | Composer Lock Bootstrap #18 |
| composer validate --strict | PASS | خطوة التحقق في run #18 |
| حذف vendor ثم composer install من lock | PASS | خطوة clean install في run #18 |
| Docker Validation #187 | FAIL — stale commit | شُغل قبل وجود composer.lock، وفشل عند فحص الملف |
| Docker Validation #198 | FAIL — assertion | PHP lint وSQLite migrations نجحت؛ route-list assertion كان ضيقًا ولم يعرض المسار |
| Docker Validation #204 | FAIL — assertion | route URI موجود، لكن test map استبدل GET عند ظهور PUT لنفس URI؛ كما ظهرت تحذيرات متكررة لعدم وجود .env |
| Docker Validation #207 | IN_PROGRESS | [Run #207](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44951589124) على SHA 3f4ead...؛ يحتوي إصلاح جمع الطرق وإنشاء .env للاختبارات |
| Docker Validation #210 | PENDING | [Run #210](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44951684707) على أحدث SHA 519c3f...؛ يشمل migrations الآمنة واختبار حفظ البيانات |
| PostgreSQL 16 integration suite | NOT YET VALIDATED on latest SHA | لم تصل بوابة PostgreSQL في #210 إلى نتيجة |
| Production smoke / live DB | NOT RUN | لم يتم الاتصال بالإنتاج أو تشغيل migrations عليه |

## 3. معايير القبول الحالية

| المعيار | الحالة |
|---|---|
| Laravel 13 project files present | PASS — source added |
| Composer manifest valid | PASS — CI |
| Composer lock committed | PASS — verified in branch |
| Clean locked Composer install | PASS — CI |
| PHP syntax | PASS on earlier Docker runs; latest run pending |
| Artisan boot / API route registration | PENDING latest CI |
| Fresh SQLite migrations | PASS on earlier SHA; must confirm latest |
| Existing auth Feature tests | PENDING latest CI |
| Existing-data migration preservation regression | PENDING latest CI |
| PostgreSQL 16 migration and lifecycle suite | PENDING latest CI |
| Docker build uses repository project (no create-project) | PENDING latest complete build |
| No secrets in committed environment template | PASS — source inspection |
| Same-final-SHA CI gate | NOT YET MET |

## 4. العيوب التي عولجت

- فشل #198 بسبب استخدام مرشح route:list --path في الاختبار؛ تم نقل التحقق إلى route registry.
- فشل #204 بسبب أن الاختبار خزّن الطرق على أساس URI دون دمجها؛ الآن تُجمع كل الطرق للمسارات المشتركة (مثل GET وPUT للملف الشخصي).
- تحذيرات PHPUnit بسبب عدم وجود ملف .env في Docker context؛ ينشأ ملف فارغ في خطوة الاختبار فقط، دون أسرار.
- اكتُشف خطر migration على قاعدة قديمة: ملفات Laravel base migrations كانت ستحاول إنشاء users/cache/jobs رغم احتمال وجودها. أصبحت migrations مشروطة، وrollback الخاص بها لا يحذف الجداول الموجودة، وأضيف اختبار regression لحفظ الصفوف السابقة.

## 5. حماية البيانات والأمن

- لا توجد اتصالات بقاعدة الإنتاج ولا تغييرات على Render/Neon.
- لم تُقرأ قيم أي أسرار؛ .env.example يحتوي أسماء ومتغيرات placeholder فقط.
- لا توجد عمليات إنشاء أو حذف حسابات أو tokens إنتاجية.
- PostgreSQL suite تستخدم خدمة PostgreSQL مؤقتة في GitHub Actions فقط.
- لا توجد موارد AWS أو تكاليف جديدة.
- لم يُدمج PR #2 في main.

## 6. الخطوات التالية دون تكرار

1. إنهاء Docker #207 والتحقق من سبب أي فشل من السجل.
2. تشغيل Docker #210 على أحدث SHA حتى ينجح بناء الصورة واختبارات PHP/SQLite.
3. انتظار خطوة PostgreSQL 16 والتأكد من migrations وauth lifecycle tests.
4. إذا فشل أي اختبار، إصلاح السبب وإعادة التحقق على أحدث SHA.
5. إغلاق Phase 01 فقط بعد نجاح كل البوابات على commit واحد.
6. بعد ذلك فقط يمكن بدء Phase 02 — Portable Production Runtime وفق مواصفته.

**Phase 02 لم تبدأ؛ تنتظر إغلاق Phase 01.**
