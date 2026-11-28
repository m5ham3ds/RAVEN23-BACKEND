# تقرير Phase12A — مواصفة قبول دمج بيانات الجهاز ولقطة snapshot/delta

- **التاريخ:** 2026-11-28
- **الحالة:** DESIGN ARTIFACT COMPLETE؛ implementation/tests NOT RUN
- **Backend repository:** `m5ham3ds/RAVEN23-BACKEND`
- **Starting Backend SHA:** `7070350759253c4b245d2ec31992c9233c52f68c`
- **Flutter source reviewed read-only:** `m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a`
- **Scope:** Phase12A provider-neutral documentation only.

## 1. ما تم إنجازه

أُعدت مواصفة قبول اصطناعية تغطي الفجوات التي ظهرت في مراجعة مخازن الأجهزة:
- نقل القوائم المؤكدة وعناصرها حتى عندما لا توجد pending operations؛
- الجمع بين confirmed playlist state وطابور العمليات المعلقة دون فقد؛
- نقل bookmark known/deletion journals المحفوظة خارج SQLite؛
- إعادة ربط legacy Firebase owner عبر جميع المخازن والـjournals، وليس Wellness فقط؛
- حفظ tombstones ومعرفات العمليات وإبطال cursors/checkpoints القديمة؛
- عقد manifest مجمع بلا PII، مع حدود consistent snapshot وdelta/replay.

المواصفة المرجعية: `phases/PHASE-12A-DEVICE-MERGE-AND-SNAPSHOT-ACCEPTANCE.md`.

## 2. النتيجة وحدود الإثبات

هذه الخطوة أغلقت **التصميم والمواصفة فقط**. لم تُنفذ اختبارات جديدة، ولم يتغير أي سلوك في التطبيق أو Backend runtime. لذلك لا تسجل أي حالة PL/BM/OWN/SNAP/DELTA على أنها PASS.

لم يتم:
- تعديل `m5ham3ds/RAVEN23` أو الوصول إلى جهاز أو ملف SQLite حقيقي؛
- الاتصال بـFirebase/Firestore أو PostgreSQL/Neon/Render production؛
- قراءة أسرار أو جمع telemetry أو رفع manifest لجهاز؛
- إضافة API route أو migration أو endpoint أو provider integration؛
- اختيار source/target/provider أو تنفيذ backup/restore حقيقي أو cutover.

## 3. ما تبقى

1. تنفيذ fixture والاختبارات في مستودع Flutter `m5ham3ds/RAVEN23` ضمن نطاق عمل مصرح به.
2. مراجعة عقد Firebase exchange وإثبات owner mapping؛ route المقابل غير موجود حاليًا في Backend.
3. إضافة اختبار تحقق manifest aggregate-only، ثم إثبات global write barrier أو اعتماد full snapshot متكرر آمن إذا تعذر delta كامل.
4. معالجة قرار playlist import/sharing في نطاق مراجعة عقد Phase06 قبل إضافة أي schema أو API.

تبقى Phase12A **IN_PROGRESS** إلى حين الاختبارات والتنفيذ والمصدر النهائي المعروف. Phase12B تبقى WAITING_EXTERNAL، و12C WAITING_OWNER_APPROVAL، وPhase13B WAITING_DEPENDENCY.

## 4. حوكمة التغيير

التغيير المطلوب لتصحيح منطق SQLite/SharedPreferences يقع في مستودع Flutter، وليس في Backend. هذه المواصفة لا تمنح إذنًا ضمنيًا لتعديل ذلك المستودع. جميع تغييرات هذا التسليم في `phases/` داخل Backend فقط.
