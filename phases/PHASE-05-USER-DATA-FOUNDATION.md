# المرحلة 05 — أساس بيانات المستخدم وعقود الحساب

**الحالة:** IN_PROGRESS — التنفيذ بدأ على `feature/phase-05-user-data-foundation` بعد اجتياز Phase 04 code/CI على `3d302f8`.  
**الاعتماديات:** المراحل 00–04 COMPLETE

## الهدف
تصميم مخطط بيانات مستقر يدعم ميزات RAVEN23 الشخصية وقابل للنقل بين PostgreSQL providers دون الاعتماد على خصائص Neon الخاصة.

## الأعمال
1. إقرار نموذج user/profile/preferences/device مع فصل هوية الحساب عن إعداداته.
2. تحديد المفاتيح الأساسية والأجنبية وunique constraints والفهارس وسياسة soft-delete/retention عند الحاجة.
3. تحديد ownership: كل سجل مستخدم يجب أن يرتبط بصاحبه ويُحمى server-side.
4. تعريف هوية المحتوى باستخدام IDs مستقرة من مصادر metadata المعتمدة، وعدم نسخ بيانات محتوى عامة إلى سجلات المستخدم بلا ضرورة.
5. توحيد API versioning وpagination/filter/sorting/error envelope.
6. تعريف timestamps بتوقيت UTC ومعنى timezone الخاص بالجهاز.
7. تعريف سياسة privacy/export/delete وحق المستخدم في حذف الحساب والبيانات التابعة له.
8. إعداد migrations forward-only قدر الإمكان واختبارات upgrade من schema سابقة.
9. تحديد idempotency وunique constraints للطلبات التي قد تتكرر بسبب انقطاع الاتصال.
10. توثيق ERD وقاموس البيانات وعقود JSON.

## معايير القبول
- اعتماد ERD وقاموس بيانات.
- كل جدول شخصي لديه owner constraint أو تحقق authorization واضح.
- migrations تعمل على قاعدة فارغة وعلى نسخة schema سابقة اصطناعية.
- اختبارات تمنع وصول مستخدم إلى بيانات مستخدم آخر.
- لا توجد ميزات DB خاصة بمزود غير موثقة.
