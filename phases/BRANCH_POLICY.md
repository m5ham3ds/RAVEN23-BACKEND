# سياسة إدارة فروع RAVEN23 Backend

**الفروع الدائمة الوحيدة المطلوبة:** `main`, `RAVEN23-BACKEND-TEST`, `RAVEN23-BACKEND-USERS`.

| الفرع | الاستخدام |
|---|---|
| `main` | فرع العمل المباشر: الميزات وmigrations وruntime والوثائق. |
| `RAVEN23-BACKEND-TEST` | جميع الاختبارات العامة فقط: Feature/Integration/contract/regression/fixtures والـtest harness. لا ميزات أو migrations. |
| `RAVEN23-BACKEND-USERS` | اختبارات الحسابات فقط: registration، email/password login، Google login، Guest upgrade، verification/reset، sessions/devices. لا ميزات أو migrations. |

## قواعد ملزمة

1. لا تنشئ فروع phase/feature أو فروع مؤقتة أخرى دون طلب مباشر.
2. يبدأ كل تغيير وظيفي على main؛ استخدم commits صغيرة وCI على SHA واحد قبل اعتماد النتيجة.
3. حدّث TEST وUSERS من main قبل دورة الاختبار، ولا تسمح بفرق فيهما خارج الملفات والاختبارات المحددة.
4. Docker full suite يعمل على main وTEST. USERS له workflow auth مخصص؛ Pint/Security يغطيان الفروع الثلاثة.
5. اختبارات Google التي تستخدم verifier fake تثبت منطق Backend فقط، ولا تثبت OAuth حقيقياً على Android.
6. قبل حذف أي branch قديم، افحص commits/trees/PRs وCI، وأثبت أن تغييره الفريد في main.
7. إغلاق PR أو دمجه لا يحذف Git branch ref تلقائياً. لا تعلن أن عدد الفروع أصبح ثلاثة حتى تؤكد قائمة refs بعد الحذف.
8. لا تُجرِ أي migration أو تعديل بيانات production ضمن اختبارات الفروع.

## نقطة الانتقال

- تم دمج roadmap وPhase01–05 إلى main.
- Phase04 code/CI محفوظ، لكن real Android Google OAuth وmail delivery في staging لم يثبتا.
- Phase05 COMPLETE على main.
- Phase06 IN_PROGRESS؛ يبدأ على main من العقود الموثقة في `PHASE-06-MEDIA-LIBRARY-AND-PLAYBACK.md`.
- refs القديمة ما زالت بحاجة إلى حذف بعد التأكد النهائي؛ أداة GitHub المتاحة في جلسة التنفيذ الحالية لا تقدم إجراء حذف branch refs.
