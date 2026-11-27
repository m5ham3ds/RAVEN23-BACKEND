# المرحلة 12 — ترحيل البيانات والتحويل الإنتاجي والرجوع الآمن

**الحالة:** WAITING_DEPENDENCY — لا تبدأ قبل جاهزية المنظومة وموافقة مباشرة  
**الاعتماديات:** المراحل 04–11 + backup/restore rehearsal + قرار المستخدم

## الهدف
نقل بيانات المستخدم الحالية إلى قاعدة الوجهة دون فقد هوية الحساب أو جلساته/بياناته، وتحويل API بأقل انقطاع ممكن مع وجود خطة rollback.

## العمل
1. جرد مصدر البيانات ووجهتها والإصدارات والمخططات وأحجام الجداول والفهارس.
2. إنشاء backup متسق ومشفر من المصدر والتحقق من integrity.
3. استعادة النسخة في بيئة معزولة ومقارنة counts وIDs والعلاقات والقيود.
4. اختبار migrations على clone ثم rehearsals كاملة متكررة.
5. تحديد استراتيجية التعامل مع الكتابات المتزامنة: maintenance/write freeze أو CDC/delta capture أو dual-write مدروس. لا تفترض أن dump واحدًا يلتقط التغييرات اللاحقة.
6. إعداد تقرير mapping لكل جدول/عمود والتحويلات ومخاطر البيانات.
7. تحديد نافذة cutover وشروط البدء والتوقف والرجوع ومَن يوافق.
8. إيقاف/تجميد الكتابات وفق الخطة وأخذ final backup/delta.
9. تنفيذ نقل البيانات والتحقق من row counts والـchecksums وعينات آمنة من السجلات الاصطناعية/المسموح بها.
10. اختبار login/profile/favorites/history/progress/sync/subscriptions بحسب الأنظمة المفعلة.
11. تحويل API domain أو routing إلى الوجهة الجديدة.
12. إبقاء المصدر القديم سليمًا للرجوع خلال فترة متفق عليها؛ لا تحذفه بعد نجاح أولي.
13. مراقبة أخطاء API وDB ومعدلات الطلب والlatency خلال hypercare.

## معايير القبول
- backup تم استعادته بنجاح قبل cutover.
- row counts/IDs/relationships ومؤشرات سلامة متفق عليها تطابق المتوقع.
- لا توجد كتابة معلقة غير ممثلة في الوجهة.
- smoke tests لكل الوظائف الأساسية ناجحة.
- rollback مجرب ومفهوم.
- موافقة المستخدم المباشرة موثقة لهذه الوجهة وهذا cutover تحديدًا.

## قاعدة حاسمة
لا تجرّب أو تنفذ migration على قاعدة الإنتاج الحقيقية خلال تطوير المراحل السابقة. لا تحذف Neon أو Render من الخطة التشغيلية حتى انتهاء فترة rollback وموافقة المستخدم.


## 2.1 — تحضير آمن قبل الترحيل: PostgreSQL synthetic backup/restore

**الحالة:** PREPARATION — ينتظر نتيجة CI على commit التنفيذ؛ لا يمثل بدء ترحيل بيانات المستخدمين ولا يغير حالة المرحلة الكلية WAITING_DEPENDENCY.

- أضيف `scripts/postgres-backup-restore-rehearsal.sh` لتنفيذ تجربة محصورة بقاعدة PostgreSQL المؤقتة `raven23_test` على `127.0.0.1` ودور CI الاختباري المعروف. يرفض السكربت أي host/database/user/password مختلف، ولا يقبل عنوانًا خارجيًا أو قاعدة إنتاج.
- ينشئ قاعدتي مصدر ووجهة مؤقتتين بأسماء مرتبطة بـGitHub run، ويدخل بيانات اصطناعية فقط (3 حسابات، 4 media progress rows، و2 sync events).
- ينشئ PostgreSQL custom-format dump، يشفره بـAES-256-CBC مع PBKDF2-HMAC-SHA256 (200,000 iteration) ومفتاح عشوائي مؤقت لا يُحفظ أو يطبع، ثم يفك التشفير ويتحقق من تطابق SHA-256 للحمولة.
- يستعيد النسخة إلى قاعدة منفصلة، ثم يقارن أعداد السجلات وبصمات المحتوى والقيود والفهارس بين المصدر والوجهة، ويحفظ تقرير JSON خاليًا من الأسرار والبيانات الحقيقية كـCI artifact.
- يحذف قاعدتي المصدر والوجهة المؤقتتين عند الخروج، بما في ذلك مسار الفشل؛ لا يعد هذا backup خارجيًا معتمدًا أو restore لبيانات RAVEN23 الحقيقية.
- لا ينفذ cutover أو migration على production، ولا يقرأ بيانات أو أسرار إنتاجية.


**ملاحظة CI:** فشلت المحاولة الأولى على `19bbeea` قبل تنفيذ dump لأن صورة PostgreSQL Alpine لا تتضمن برنامج `openssl`. عولج ذلك بتثبيت الأداة داخل الحاوية المؤقتة قبل تشغيل السكربت؛ لا تسجل التجربة PASS إلا بعد التشغيل التالي ورفع JSON evidence بنجاح.


**التشخيص الثاني:** بعد تثبيت OpenSSL نجحت أوامر إنشاء الجداول وإدخال 3/4/2 صفوف اصطناعية، ثم خرج السكربت برمز 1 دون تحديد الخطوة التالية. أضيفت رسائل milestones وفحوصًا صريحة لكل من `pg_dump` والتشفير وفك التشفير و`pg_restore` وأعداد السجلات وبصمات المحتوى والقيود والفهارس. لا يتم تسجيل PASS قبل ظهور نتائج هذه الخطوات في CI.


## 2.2 — إغلاق preflight الاصطناعي والتحقق النهائي — 27 نوفمبر 2026

**الحالة:** PASS للتحضير الاصطناعي فقط؛ حالة Phase12 الكلية تظل `WAITING_DEPENDENCY`.

على main SHA `27cfcc9e577cca33c28e9845096a0c5564b02045` نجح Docker Validation [#45929855650](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650)، وAuthentication [#45929855617](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855617)، وPint [#45929855622](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855622)، وSecurity [#45929855730](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855730). وظيفة التوقيع trusted-main اكتملت 17/17 خطوة.

- نجح round-trip للنسخة الاصطناعية المشفرة AES-256-CBC/PBKDF2-HMAC-SHA256، مع SHA-256 integrity check.
- تمت الاستعادة إلى قاعدة disposable منفصلة، وتطابقت 3 accounts و4 media progress rows و2 sync events، وجميع 10 constraints بما فيها اختبارات سلوك CHECK constraints، والفهارس.
- تقرير JSON: [artifact 15168436709](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168436709).
- اجتازت تجربة rollback مسار HTTP 200 → 502 → 200 إلى الصورة السابقة، واكتملت خمس Sigstore attestations للصورة الحالية وSBOMها، وصورة rollback وSBOMها، وrelease manifest.
- حزمة الإصدار: [artifact 15168861287](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168861287).

هذه النتيجة لا تغطي مصدر بيانات حقيقيًا ولا تثبت backup أو restore إنتاجيًا، ولا تفوض cutover أو migration أو النشر. تبقى معايير القبول الرئيسية للمرحلة 12 كما هي في أعلى هذه الوثيقة.
