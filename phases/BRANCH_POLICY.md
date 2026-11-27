# سياسة إدارة فروع RAVEN23 Backend

**سياسة التشغيل المعتمدة: فرع دائم واحد فقط — `main`.**

جميع ملفات المشروع والاختبارات والوثائق وGitHub Actions تبقى في `main`. لا توجد فروع Feature أو Phase أو Test دائمة، ولا نستخدم PR branches المؤقتة كجزء من دورة العمل المعتادة.

## قواعد العمل

1. نفّذ تغييرات التطبيق وmigrations والوثائق والاختبارات على `main` في commits صغيرة وواضحة.
2. كل تغيير سلوكي يرافقه اختبار regression مناسب في `tests/Feature` أو `tests/Integration`.
3. شغّل GitHub Actions على SHA المراد اعتماده: Docker/SQLite/PostgreSQL، Pint، وSecurity.
4. لا تفتح فروع phase/feature/test جديدة ولا تنقل الاختبارات إلى فرع منفصل.
5. اختبارات Google التي تستخدم verifier fake تثبت منطق Backend فقط؛ لا تثبت OAuth حقيقياً على Android.
6. لا تستخدم قواعد إنتاج أو حسابات حقيقية في الاختبارات. PostgreSQL الاختباري يجب أن يكون مؤقتاً ومعزولاً وبدور تطبيق محدود الصلاحية.
7. احتفظ بتاريخ الفروع والتقارير القديمة لأغراض المراجعة؛ لا تعِد كتابة التاريخ أو تحذف commits.
8. حذف branch ref عملية منفصلة عن نقل الملفات أو تغيير workflow triggers. لا تعلن حذف ref قبل التحقق من قائمة refs بعد العملية.

## انتقال 23 نوفمبر 2026

- نُقلت الاختبارات العامة المتخصصة من `RAVEN23-BACKEND-TEST` إلى `main` في commit `800cff70b9663815e57b47db2be8340e8c19215e`.
- تشمل الملفات المنقولة اختبارات bookmarks وrecently-watched وplaylists وpreferences واختبارات PostgreSQL المقابلة.
- فرع `RAVEN23-BACKEND-USERS` لم يكن يحمل اختبارات فريدة؛ الفرق عن `main` كان ملف توثيق نطاق الفرع فقط. اختبارات المصادقة موجودة بالفعل في `tests/Feature/AuthLifecycleTest.php` و`tests/Integration/PostgresAuthLifecycleTest.php`.
- تم اعتماد `main` ليكون الفرع الوحيد الذي يشغّل كامل بوابات CI. الفروع القديمة TEST وUSERS متقاعدة ولا ينبغي دفع commits جديدة إليها.
- عند إعداد هذا التحديث، كانت refs القديمة ما تزال ظاهرة في GitHub؛ لا تعتبر محذوفة إلا بعد حذفها والتحقق من refs.

## تحقق حذف فروع الاختبارات — 23 نوفمبر 2026

حذف المستخدم `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS`. أعاد GitHub branch listing بعد الحذف فرعًا واحدًا فقط: `main`. جميع الاختبارات المنقولة ما زالت في main؛ لم يحذف تنظيف refs أي ملفات اختبار ولم يُعِد كتابة commit history.


## تدقيق فرض الحماية — 24 نوفمبر 2026 (Phase10B)

- GitHub يعرض main وحده، لكن protected=false ولا توجد rulesets؛ لذلك السياسة أعلاه هي سياسة عمل وليست حماية تقنية مفعلة من GitHub.
- لا تتوفر في اتصال GitHub المستخدم هنا عملية كتابة لإعداد branch protection أو rulesets أو required status checks؛ لا يمكن تفعيلها من هذه الجلسة ولا أزعم أنها فُعّلت.
- لا يمكن فرض required checks قبل تحديث main مع الاستمرار في direct pushes كمسار العمل الوحيد. يلزم أن يختار مالك المستودع نموذج enforcement متوافقًا مع السياسة: مراجعة/دمج PR مع checks، أو إجراء محمي آخر.
- checks المرشحة بعد اعتماد نموذج الدمج: Build and test portable Laravel runtime، Email, password, Google verifier and guest authentication، Secret, dependency, filesystem and image scans.
- Format changed PHP files with Laravel Pint الحالي ينشئ formatted artifact ولا يفرض وحده فشلًا عند اختلاف التنسيق؛ strict Pint داخل Docker هو فحص التنسيق الملزم.


## قرار المستخدم بشأن توقيت فرض حماية main — 26 نوفمبر 2026

- إعداد Branch protection وRequired status checks مؤجل عمداً إلى ما بعد اكتمال خطة تطوير Backend بالكامل.
- خلال مراحل التطوير يستمر العمل على `main` وفق مسار المستودع الحالي، مع تشغيل CI على التغييرات وعدم الادعاء بأن الفرع محمي.
- هذا استثناء زمني لمرحلة التطوير فقط، وليس إعفاءً من متطلبات الإصدار أو النشر؛ Phase10B يجب أن تُغلق يدوياً قبل Phase11B2 وقبل Staging/Production.
- عند الإعداد، يجب اعتماد نموذج update/merge محمي لأن direct push لا يجعل required checks فحصاً سابقاً للتحديث.
- راجع `reports/POST-PLAN-MANUAL-BRANCH-PROTECTION-SETUP.md` للقائمة التفصيلية والفحوص ومعيار الإغلاق.

## تحقق main وسياسة استمرار التطوير — 27 نوفمبر 2026

- GitHub branch listing الحالي يعيد `main` وحده؛ لا تنشئ فروعًا إضافية.
- رأس التحقق المرجعي عند بداية مزامنة الوثائق الشاملة: `617ce7efd669b4d3af61c42f77b4cd1f986e3fae`، وجميع بوابات CI الأربع نجحت عليه.
- commit المرجع المذكور docs-only relative to `27cfcc9`; لم يتغير application/runtime code في ذلك الفرق.
- إعداد حماية main (Phase10B) يبقى مؤجلًا بطلب المالك إلى ما بعد اكتمال خطة Backend بالكامل؛ لا تدّع أن protected/rulesets مفعلة. يستمر CI على main حسب triggers الحالية.
- بموجب D-050، اختيارات جميع مزودي الخدمات والمنطقة والتكلفة مؤجلة إلى ما بعد التطوير المحايد؛ لا ينشئ ذلك فروعًا أو صلاحيات أو موارد خارجية.
