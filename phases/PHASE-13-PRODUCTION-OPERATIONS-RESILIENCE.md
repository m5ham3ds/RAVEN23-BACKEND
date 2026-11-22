# المرحلة 13 — تشغيل الإنتاج والتعافي وإدارة التكلفة

**الحالة:** WAITING_DEPENDENCY  
**الاعتماديات:** المرحلة 12 COMPLETE

## الهدف
توفير تشغيل مستقر بعد النقل، مع قدرة موثقة على اكتشاف الأعطال واستعادة الخدمة والبيانات والتحكم في التكلفة.

## العمل
1. إعداد لوحات صحة الخدمة وPostgreSQL وqueue/storage وAPI latency/error rates.
2. إعداد alerts للـdowntime، ارتفاع 5xx، DB connections، storage, queue backlog، backup failures، budget thresholds.
3. مراجعة capacity وresource utilization بشكل دوري ورفع الحجم فقط بناءً على القياس.
4. تنفيذ backup schedules وretention وencryption وrestore drills دورية.
5. توثيق incident runbooks لكل من: API outage، DB outage، compromised credentials، failed migration، storage outage، bad deployment.
6. اختبار rollback للإصدار وإجراءات server replacement.
7. اختبار استعادة قاعدة البيانات والملفات إلى مزود/منطقة بديلة عندما تسمح التكلفة.
8. وضع سياسة patching وتحديث PHP/Laravel/dependencies/images.
9. مراجعة access keys/IAM roles/users وإزالة غير المستخدم مع تدوير آمن مصرح به.
10. مراجعة privacy/data retention/deletion/export.
11. إعداد تقرير شهري عن availability، data recovery، cost، capacity، incidents، unresolved risks.
12. تحديث دليل نقل الاستضافة بحيث يمكن تشغيله دون معرفة داخلية غير موثقة.

## معايير القبول
- كل alert حرج اختُبر عمليًا.
- restore drill ينجح ويقاس RPO/RTO.
- توجد أدلة على استعادة ملفات وقاعدة بيانات، لا مجرد ملفات backup.
- يمكن نشر إصدار سابق أو استبدال الخادم دون إعادة كتابة التطبيق.
- توجد إجراءات incident واضحة ومسؤوليات معروفة.
- التكلفة الفعلية ومعدل النمو موثقان ومراجعان.

## قاعدة الاستمرارية
تبقى هذه المرحلة دورة تشغيل متكررة؛ تُضاف تقارير تشغيل دورية ولا تُمحى تقارير الحوادث السابقة.
