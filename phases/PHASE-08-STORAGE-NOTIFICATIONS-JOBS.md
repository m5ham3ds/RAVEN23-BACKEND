# المرحلة 08 — تخزين الملفات والإشعارات والمهام الخلفية

**الحالة:** NOT_STARTED  
**الاعتماديات:** المراحل 02–05 COMPLETE

## الهدف
إضافة خدمات الملفات والرسائل والمهام الخلفية عبر واجهات قابلة لاستبدال المزود.

## العمل
1. تعريف Storage interface واختيار مسار S3-compatible دون ربط domain logic بـ AWS SDK مباشرة.
2. تخزين صور الحساب والملفات الصغيرة فقط وفق سياسة أنواع/أحجام وMIME validation.
3. استخدام signed URLs أو upload authorization محدود العمر؛ منع جعل الحاويات عامة افتراضيًا.
4. حفظ metadata والملكية في PostgreSQL، أما bytes فتكون في object storage.
5. تعريف mail interface ورسائل verification/reset بقوالب قابلة للاختبار.
6. تعريف push notification adapter مع التحقق من توافق FCM مع تطبيق RAVEN23 قبل تفعيله.
7. إنشاء queue workers للمهام التي تحتاج معالجة خلفية، مع retries وdead-letter/failure tracking.
8. جعل كل jobs قابلة لإعادة المحاولة دون إرسال/تحديث مكرر.
9. حماية حذف الملفات من حذف ملف يخص مستخدمًا آخر، وتوفير orphan cleanup آمن.
10. وضع قواعد retention وlimits والتكلفة والتخزين المؤقت.

## معايير القبول
- storage يعمل عبر adapter اختباري وS3-compatible test/staging.
- صلاحيات الوصول للملفات خاصة ومحدودة.
- mail verification/reset يمكن اختباره باستخدام mail sink قبل تفعيل مزود حقيقي.
- Jobs retry-safe وتظهر أسباب الفشل دون كشف الأسرار.
- حذف/تصدير حساب المستخدم يتعامل مع الملفات وفق سياسة واضحة.
- لا تخزن ملفات الفيديو على قرص EC2.

## اعتماديات خارجية
اختيار مزود البريد/الإشعارات، credentials، domain/DNS وAWS account قد تبقى WAITING_EXTERNAL. لا تشترِ خدمة أو تنشئ موارد مدفوعة تلقائيًا.
