# المرحلة 03 — الأمن والتشغيل والرصد الأساسي

**الحالة:** IN_PROGRESS — بدأت في 22 نوفمبر 2026 على PR #4  
**الاعتماديات:** المرحلتان 01 و02 COMPLETE

## الهدف
تقليل سطح الهجوم وحماية بيانات المستخدم والأسرار وتوفير تشخيص آمن لا يكشف تفاصيل البنية للعميل.

## الأعمال
1. مراجعة authentication/authorization وmiddleware وCORS وTLS وtrusted proxies حسب طبيعة API.
2. تقوية rate limiting حسب IP والحساب والجهاز مع حدود قابلة للضبط ومنع account enumeration.
3. إخفاء hostname واسم قاعدة البيانات والمخطط وinstance IDs ومعلومات المصدر من health/error responses العامة.
4. إخراج structured logs إلى stderr/collector مع request/correlation ID، ومنع تسجيل passwords/tokens/emails/SQL bindings.
5. فصل liveness عن readiness عن diagnostics المحمية.
6. مراجعة security headers وحجم الطلب والمهل والتحقق من المدخلات.
7. إعداد authorization policies وmass-assignment protections لكل domain.
8. تشغيل Composer audit وsecret scanning وcontainer scanning وstatic analysis تدريجيًا.
9. مراجعة صلاحيات database user ومبدأ أقل صلاحية؛ لا تستخدم superuser للتطبيق.
10. توثيق سياسة الاحتفاظ بالسجلات والنسخ الاحتياطية والتشخيص.

## معايير القبول
- لا تتسرب أسرار أو بيانات شخصية في logs أو responses.
- endpoint العام لا يكشف بنية الاستضافة.
- اختبارات rate limits وauthorization وvalidation ناجحة.
- secret/dependency/container scans تعمل وتنتج تقارير قابلة للمراجعة.
- إعداد الإنتاج لا يعمل مع APP_DEBUG=true.
- لا يستطيع مستخدم الوصول إلى بيانات مستخدم آخر عبر تغيير ID.

## قاعدة
لا تضعف الأمان لتجاوز فشل اختبار؛ أصلح السبب وأضف اختبار regression.
