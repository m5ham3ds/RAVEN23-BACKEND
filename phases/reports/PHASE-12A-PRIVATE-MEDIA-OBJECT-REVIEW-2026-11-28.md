# تقرير Phase12A — جرد private media object ومواصفة سلامة النقل

- **التاريخ:** 2026-11-28
- **الحالة:** DESIGN SPECIFICATION COMPLETE؛ real inventory/copy/restore NOT RUN
- **Backend baseline:** `72cebe78fb436a6036b7144ff3b0c31e542204a0`
- **النطاق:** وثائق Phase12A محايدة للمزود فقط.

## 1. ما تمت مراجعته

تمت مراجعة UserMediaController وUserMedia model وmigration `user_media` وPrivateObjectStorage وFilesystemPrivateObjectStorage وconfig/filesystems وDeletePrivateObjectJob وأمر CleanupOrphanedPrivateMedia واختبارات SQLite/PostgreSQL.

النتائج المؤكدة من المصدر:
- بيانات الملف الوصفية محفوظة في PostgreSQL بينما bytes موجودة في private disk منفصل.
- السجل يحفظ UUID وuser_id وpurpose وobject_key وMIME وsize_bytes وchecksum_sha256.
- المسار ينشأ من user ID وmedia UUID.
- الافتراضي في config هو private local disk تحت `storage/app/private/media`. لا توجد أدلة على disk أو bucket النشط في أي بيئة تشغيل حية.
- API export v3 يعيد media metadata فقط ولا يصدّر bytes أو object_key.
- اختبارات SQLite وPostgreSQL تتحقق من checksum metadata وprivate access وowner isolation وsigned URL expiry وdelete jobs وorphan dry-run/opt-in.

## 2. ما أضيف

أضيفت المواصفة `PHASE-12A-PRIVATE-MEDIA-OBJECT-MIGRATION-AND-INTEGRITY.md`:
- data model/reconciliation بين DB metadata وsource/destination objects؛
- مقارنة byte length وSHA-256؛
- aggregate-only public evidence ومنع مفاتيح الملفات وأسماء الملفات ومعرفات المالك من artifacts العادية؛
- 16 حالة OBJ اصطناعية؛
- تسلسل snapshot/write freeze/delta/copy/read-back/rollback؛
- حفظ مصدر الملفات ومنع cleanup حتى موافقة منفصلة.

## 3. حدود الإثبات

لم يتم:
- listing لقرص أو bucket حي، أو استخراج عدد ملفات/حجم بيانات حقيقي؛
- نسخ أو استعادة أي object أو قراءة ملفات مستخدمين؛
- اختيار S3 أو مزود أو bucket أو region أو encryption/retention policy؛
- إنشاء أسرار أو تعديل disk config أو تشغيل cleanup apply؛
- تعديل Backend runtime أو مستودع Flutter.

المواصفة لا تثبت أن local fake أو S3Mock يساوي object storage إنتاجيًا. كما أنها لا تغلق Phase12A؛ التنفيذ الاصطناعي ومصدر/وجهة البيانات لا يزالان مفتوحين.

## 4. الخطوة التالية

تنفيذ object-manifest validator وsynthetic copy/read-back test داخل Backend CI، بشرط بقاء الاختبارات على temporary/fake storage وعدم إدخال provider credentials أو live target. بعدها يلزم اختبار تزامن metadata/object/delete queue ضمن snapshot/delta rehearsal.

تبقى Phase12A IN_PROGRESS، وPhase12B/12C وPhase13B gated كما سبق.
