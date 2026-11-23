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