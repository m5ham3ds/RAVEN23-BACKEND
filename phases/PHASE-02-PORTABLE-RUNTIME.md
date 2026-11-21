# المرحلة 02 — بيئة تشغيل إنتاجية مستقلة عن مزود الاستضافة

**الحالة:** NOT_STARTED  
**الاعتماديات:** المرحلة 01 COMPLETE

## الهدف
بناء صورة تشغيل موحدة تعمل على Render وAWS وأي Linux/Docker host متوافق، دون افتراضات خاصة بمزود بعينه.

## الأعمال
1. اختيار production HTTP runtime بعد مقارنة موثقة: Nginx + PHP-FPM أو FrankenPHP.
2. تشغيل PHP workers بشكل سليم مع health/readiness probes وإغلاق صحيح للإشارات.
3. بناء multi-stage Docker image ثابتة وصغيرة قدر الإمكان، تعمل بصلاحيات non-root وتحتوي healthcheck.
4. فصل build-time عن runtime وعدم وضع أسرار داخل الصورة أو build args.
5. إزالة اعتماد startup على `DB_MIGRATION_URL` بنمط Neon؛ تصميم إعداد PostgreSQL قياسي يدعم URI أو host/port/user/password، مع فصل اتصال migrations عند الحاجة.
6. إزالة فرض اسم Neon أو Render من السكربتات العامة، ونقل الفحوص الخاصة إلى deployment profiles أو adapters.
7. إعداد parity بين التطوير وCI باستخدام Docker، وقاعدة PostgreSQL مؤقتة للاختبارات.
8. ضبط cache/queue/session؛ لا تعتمد بيانات دائمة على ملفات خادم يمكن استبداله.
9. إضافة graceful shutdown وtimeouts وrequest limits وtrusted proxy/TLS settings.
10. توثيق متغيرات البيئة والقيم المطلوبة والاختيارية.

## معايير القبول
- صورة واحدة تُبنى من المستودع الحالي وتعمل في CI.
- تشغيل container مع PostgreSQL مؤقتة عبر HTTP ينجح.
- لا يلزم Render أو Neon لتشغيل اختبارات أو بناء الصورة.
- بدء الخادم لا يشغل migrations غير آمنة على قاعدة مجهولة.
- readiness يميز بين سلامة التطبيق وسلامة اتصاله بقاعدة البيانات.
- لا تعتمد جلسات أو cache دائمة على القرص المحلي في الإنتاج.

## القرار المطلوب
اختيار Nginx/PHP-FPM أو FrankenPHP بناء على الاختبارات والتوافق والقياس، لا التخمين.
