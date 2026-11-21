# تقرير خط الأساس — RAVEN23-BACKEND

## معلومات المراجعة
- المستودع: `m5ham3ds/RAVEN23-BACKEND` (خاص).
- الفرع: `main`.
- Commit: `9f29fa55afa21c0445be82d4590a4a532bb1747c`.
- التاريخ: 21 نوفمبر 2026.
- نوع المراجعة: قراءة ملفات ومراجعة سجل CI فقط؛ لم يتم تعديل بيانات الإنتاج أو الملفات.
- الحالة: **تمهيدية — المرحلة 00 لم تكتمل بعد.**

## 1. مكونات المستودع التي ظهرت في الجرد
- `.github/workflows/`
- `app/Http/Controllers/Api/`
- `app/Models/`
- `app/Support/`
- `bootstrap/`
- `config/`
- `database/migrations/`
- `docker/`
- `routes/`
- `tests/Feature/`
- `tests/Integration/`
- `Dockerfile` و`README.md` و`.dockerignore`.

لم يظهر `composer.json` أو `composer.lock` في الجذر ضمن القائمة التي تمت مراجعتها. يقوم Dockerfile حاليًا بإنشاء Laravel 13 جديد أثناء البناء، ويثبت Sanctum وgoogle/apiclient ثم ينسخ ملفات RAVEN23 المحددة إليه. هذه طريقة أثبتت نجاحها في CI لكنها لا تجعل المصدر مشروع Laravel مستقلًا بالكامل، كما أنها لا تثبت كل الاعتماديات عبر lock file.

## 2. وظائف موجودة في المصدر
- التسجيل بالبريد وكلمة المرور وتسجيل الدخول.
- إنشاء حساب ضيف.
- ترقية حساب ضيف إلى حساب بريد أو Google مع محاولة الحفاظ على نفس user ID.
- التحقق البرمجي من Google ID token عبر google/apiclient.
- جلسات bearer عبر Laravel Sanctum.
- بيانات تعريف الجهاز على token واستبدال token السابق للجهاز نفسه.
- تسجيل الخروج وإبطال token الحالي.
- قراءة وتحديث الملف الشخصي.
- تغيير البريد وكلمة المرور.
- حذف الحساب بعد إلغاء tokens.
- التحقق من توفر username.
- migrations وفحوص schema مرتبطة بـ PostgreSQL.
- health endpoint وسجلات أخطاء تحاول تجنب SQL والأسرار.
- اختبارات Feature باستخدام SQLite واختبارات Integration باستخدام PostgreSQL.

وجود الكود لا يعني أن كل التكاملات الخارجية اختُبرت فعليًا؛ Google على جهاز Android والبريد الإلكتروني لا يزالان بحاجة إلى تحقق حقيقي.

## 3. دليل GitHub Actions على خط الأساس

على SHA المذكور:
- Backend Docker Validation رقم 182: SUCCESS.
- سجل البناء أظهر 11 اختبار Feature ناجحًا مع 68 assertion.
- اختبارات PostgreSQL Integration أظهرت 3 اختبارات ناجحة مع 72 assertion على خدمة PostgreSQL 16 معزولة في CI.
- Live Authentication Smoke رقم 127: SUCCESS؛ تحقق من commit المنشور على Render وصحة health وقاعدة البيانات والمخطط ووصول مسارات API بطلبات غير معدّلة للبيانات.

هذه النتائج تثبت نجاح هذه الاختبارات المحددة فقط، ولا تثبت أن كل وظائف التطبيق مكتملة أو أن Google/البريد/المزامنة/النسخ الاحتياطي قد اختُبرت.

## 4. مشكلات ومخاطر مبدئية
1. **هيكل Laravel:** غياب Composer manifests في الجذر واعتماد Docker على create-project أثناء البناء.
2. **خادم HTTP:** يستخدم `docker/start.sh` خادم PHP المدمج. يجب استبداله ببيئة إنتاجية مناسبة بعد مقارنة Nginx + PHP-FPM وFrankenPHP.
3. **الارتباط بالمزود:** توجد افتراضات Render وNeon في README وstartup scripts وفحوص DB وعدة workflows.
4. **كشف التشخيص:** health response تعرض معلومات host/database/schema/commit/instance، كما تعرض بعض أخطاء API اسم الملف والسطر. يجب نقل التفاصيل الداخلية للسجلات المحمية.
5. **المصادقة:** endpoints لاستعادة كلمة المرور وإرسال البريد تعيد 503؛ التحقق بالبريد غير مكتمل؛ إدارة الأجهزة جزئية؛ اختبار Google الواقعي من Android غير مثبت.
6. **بيانات التطبيق:** لم تظهر ضمن الملفات التي فُحصت وحدات مكتملة للمفضلة، watchlists، history، playback progress، sync، الاشتراكات أو file storage. يلزم تأكيد ذلك في الجرد الكامل.
7. **GitHub Actions:** توجد ملفات production-account-retry متعددة متشابهة وبعض شروط التشغيل تحتاج مراجعة، بما فيها شرط التشغيل اليدوي في auth-smoke.
8. **التشغيل:** لم يثبت في هذه المراجعة وجود backup/restore rehearsal آلي، أو monitoring/alerting مكتمل، أو خطة نقل مزود تم اختبارها.
9. **نسخ PHP:** Dockerfile يستخدم PHP 8.4، بينما README يذكر PHP 8.3+؛ يلزم توحيد النسخة المدعومة.

## 5. أمور لم يتم إثباتها في هذه المراجعة
- لم يتم فحص كل ملف في المستودع سطرًا بسطر.
- لم يتم الاتصال بقاعدة Neon الإنتاجية أو عرض بيانات المستخدمين أو الأسرار أو تعديلها.
- لم يتم إكمال ربط كل endpoint باستدعاءاته داخل تطبيق RAVEN23 Android.
- لم يتم تنفيذ Docker build من هذه الجلسة؛ تم الاعتماد على سجل GitHub Actions الناجح المذكور.
- لم يتم اختبار Google OAuth الحقيقي على جهاز Android.
- لم يتم اختبار إرسال البريد أو الإشعارات أو التخزين أو الدفع.
- لم يتم إنشاء حساب AWS أو أي موارد سحابية.

## 6. ما تبقى لإغلاق المرحلة 00
- شجرة ملفات recursive كاملة على SHA محدد.
- فحص كل source/config/workflow/migration/dependency.
- API contract inventory وربطه باستدعاءات تطبيق RAVEN23.
- قاعدة بيانات/data inventory من schema وmigrations فقط.
- جرد أسماء environment variables والأسرار دون قيمها.
- تصنيف كل workflows وسجل فشلها/نجاحها على SHA الدقيق.
- مصفوفة ميزات: موجود / جزئي / مفقود / يحتاج تحقق حقيقي.
- تقرير إغلاق المرحلة 00.

## النتيجة
المستودع يحتوي Backend حقيقيًا للمصادقة يستحق الحفاظ عليه. الأنسب هو إعادة هيكلة الأساس وطريقة البناء والتشغيل، ثم إكمال المجالات الناقصة تدريجيًا بدل البدء من الصفر.
