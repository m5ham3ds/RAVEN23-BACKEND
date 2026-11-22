# المرحلة 10 — الجودة والاختبارات وهندسة الإصدارات

**الحالة:** NOT_STARTED  
**الاعتماديات:** المراحل 01–09 (يمكن إغلاقها تدريجيًا مع كل domain، لكن بوابة المرحلة تشمل كل ما أُعلن كجاهز للنشر)

## الهدف
منع regressions، والتأكد من أن كل commit يمر بسلسلة اختبارات واضحة وأن كل إصدار يمكن تتبعه والرجوع عنه.

## العمل
1. توحيد workflows المكررة وإزالة retry files المتشابهة بعد نقل وظائفها وعدم فقد أي تحقق مفيد.
2. تقسيم CI إلى lint/static/dependency/test/build/container/security/contract jobs.
3. تشغيل SQLite لاختبارات سريعة فقط، وPostgreSQL disposable لكل migrations/integration.
4. اختبار migration من schema فارغ ومن snapshot اصطناعي قريب من schema السابق.
5. API contract tests تغطي Android clients والمسارات والحقول والأخطاء.
6. عدم استخدام production DB لأي اختبار CI.
7. اختبارات تحميل/ضغط تدريجية مع أهداف مبنية على الاستخدام المتوقع.
8. اختبار Docker image من حيث user permissions، healthcheck، startup، signals، secrets والنسخ.
9. اعتماد immutable image tags/digests وربط الإصدار بـ Git SHA.
10. إعداد قواعد main branch/PR checks/reviews وdeployment environments.
11. تنفيذ security scans وإدارة CVEs حسب severity وخطة تصحيح.
12. حفظ artifacts/logs المهمة دون أي بيانات شخصية أو أسرار.

## معايير القبول
- جميع workflows موحدة ومفهومة ولا توجد triggers مخادعة أو شروط لا تعمل.
- كل SHA مرشح للإصدار يمر بجميع required checks.
- توجد نتائج migrations وPostgreSQL integration وAPI contract.
- image التي تُنشر هي نفسها التي تم اختبارها (digest).
- يوجد rollback إلى image سابقة موثقة.
- تقارير الفشل تحدد root cause ولا تخفي الفشل بإعادة محاولات عشوائية.

## ملاحظة
لا تعني هذه المرحلة أن الميزات المكتوبة في الخطة كلها موجودة تلقائيًا؛ يجب أن تكون scope phase feature مكتملة قبل إدخالها في release gate.
