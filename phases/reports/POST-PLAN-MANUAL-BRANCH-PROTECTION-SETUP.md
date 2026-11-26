# تقرير إعداد حماية main يدوياً بعد اكتمال تطوير Backend

- **القرار:** DEFERRED BY OWNER — بناءً على توجيه المستخدم في 26 نوفمبر 2026، يتم تأجيل إعدادات GitHub branch protection وrequired status checks إلى ما بعد اكتمال خطة تطوير RAVEN23 Backend بالكامل.
- **النطاق:** إعدادات GitHub للمستودع `m5ham3ds/RAVEN23-BACKEND` والفرع الدائم `main` فقط.
- **التنفيذ في هذه الخطوة:** توثيق فقط. لم تتغير إعدادات GitHub ولم يتم تفعيل الحماية.
- **حالة التطوير:** لا تُعامل هذه الإعدادات المؤجلة كحاجز أمام تنفيذ واختبار ميزات Backend الحالية.
- **حالة الإصدار والنشر:** تظل الحماية اليدوية شرطاً قبل بدء Phase11B2 enforcement وقبل اعتماد مسار نشر Staging/Production.

## 1. الحالة التي تم التحقق منها عند تسجيل القرار

على رأس `main` التالي:

`60633d92ed61f0a85bab1522a6d5a85a477c8c60`

كانت الحالة المرصودة:

- `main.protected = false`.
- `protection.enabled = false` وRequired status checks غير مفعّلة.
- GitHub Rulesets endpoint أعاد قائمة فارغة.
- آخر أربع بوابات CI على SHA المذكور نجحت: Docker Validation، Security Scans، Authentication Regression، وPint Format.
- لا يعني نجاح CI بعد push أن التغيير كان محمياً قبل إدخاله إلى `main`.

## 2. الفحوص المطلوبة المقترحة

عند تنفيذ الإعداد اليدوي، استخدم أسماء الوظائف الفعلية التالية كـrequired status checks، وتأكد من ظهورها على commit تجريبي قبل اعتمادها:

1. `Build and test portable Laravel runtime` — Docker، SQLite، PostgreSQL، S3Mock، runtime وrollback validation.
2. `Email, password, Google verifier and guest authentication` — Authentication regression.
3. `Secret, dependency, filesystem and image scans` — Security scans.

فحص Laravel Pint الصارم يعمل داخل Docker validation؛ أما workflow المنفصل `RAVEN23 Pint Format Report` فهو تقرير/Artifact ولا يُعد وحده حاجز تنسيق ملزماً.

## 3. خطوات التنفيذ اليدوي المؤجلة

بعد اكتمال تطوير Backend، ينفّذ مالك المستودع الخطوات التالية من GitHub:

1. فتح المستودع ثم **Settings → Branches**، أو إنشاء Repository Ruleset يستهدف الفرع `main`.
2. اختيار نموذج التحديث المحمي. إذا استُخدمت required checks قبل الدمج، فيجب اعتماد Pull Request/merge flow أو آلية محمية مكافئة؛ direct push وحده يشغّل CI بعد تحديث الفرع ولا يحقق pre-merge gate.
3. تفعيل required status checks وإضافة أسماء الفحوص الثلاثة في القسم السابق، بعد التحقق من ظهور check contexts الصحيحة.
4. تفعيل منع force pushes وحذف `main`. وتحديد سياسة bypass/admin enforcement صراحةً بدلاً من تركها ضمنية.
5. يمكن تفعيل require branch up to date وconversation resolution وفق سير المراجعة الذي سيعتمده مالك المستودع.
6. إجراء تجربة تغيير غير إنتاجي للتأكد أن التغيير لا يمكن دمجه/تمريره عند فشل check مطلوب، وأنه يُسمح به بعد نجاح كل checks.
7. إعادة قراءة Branch protection أو Rulesets API والتأكد من أن `main` محمي وأن required checks ظاهرة فعلياً.

## 4. نقاط قرار لا تُحسم تلقائياً

- المستودع يعمل بسياسة فرع دائم واحد `main` ولا يعتمد حالياً فروع feature دائمة؛ يجب أن يحدد المالك كيفية إنشاء ومراجعة ودمج التغييرات المؤقتة إن اختار PR workflow.
- عدد الموافقات المطلوبة، واستثناءات المالك أو administrators، واشتراط مزامنة الفرع قبل الدمج قرارات تشغيلية تُثبت وقت الإعداد.
- لا تضف Pint report المستقل كـrequired check بدلاً من فحص Pint الصارم داخل Docker.
- لا تغيّر أسماء workflows أو jobs في نفس وقت تسجيل required contexts إلا مع إعادة التحقق من أسماء الشروط المطلوبة.

## 5. أثر التأجيل وحدوده

- لا يوقف هذا القرار كتابة ميزات Backend أو migrations أو اختبارات regression أو CI المعتاد.
- لا يعلن Phase10B مكتملة؛ حالتها `DEFERRED_BY_OWNER` إلى حين تنفيذ الإعدادات اليدوية.
- لا يُعد هذا تنازلاً عن متطلبات أمن الإصدار: Phase11B2 وStaging/Production release/deployment تظل مشروطة بإغلاق الحماية والاعتماديات الأخرى.
- لا يتم اختيار مزود استضافة أو منطقة أو ميزانية، ولا إنشاء موارد مدفوعة بموجب هذا التقرير.
- لا تتم قراءة أو تغيير GitHub secrets أو بيانات الإنتاج أثناء تنفيذ الإعدادات.

## 6. معيار الإغلاق المستقبلي

لا تُغلق Phase10B عند فتح صفحة الإعدادات أو حفظ النموذج فقط. يتطلب الإغلاق دليلاً قابلاً للمراجعة يثبت:
- أن `main` محمي فعلياً أو أن Ruleset نافذ يستهدفه.
- أن الفحوص الثلاثة المطلوبة مربوطة بأسماء check contexts الصحيحة.
- أن تجربة enforcement تثبت منع التحديث عند فشل required check.
- أن مسار الدمج المحمي متوافق مع سياسة المستودع التي يقرها المالك.

**القرار النهائي:** يؤجل الإعداد اليدوي لما بعد انتهاء خطة تطوير Backend بالكامل. تظل هذه الوثيقة مرجع التنفيذ حينها؛ لا تكرر محاولة تفعيل الحماية أثناء مراحل التطوير الحالية.
