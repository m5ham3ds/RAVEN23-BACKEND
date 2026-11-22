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

## نقطة الانتقال — 23 نوفمبر 2026

- تم تأكيد بقاء الفروع الدائمة الثلاثة فقط؛ الفروع القديمة الستة حُذفت بعد مراجعتها.
- `main`: `8219a5833c7a322c00d53bdebe8c4b30cfad3218` — العمل الوظيفي والـmigrations والوثائق.
- `RAVEN23-BACKEND-TEST`: `08f5e05fec98d19cf3d18a0b2993352680952f2b` — اختبارات bookmarks/recent progress؛ يحتاج مزامنة main الأحدث.
- `RAVEN23-BACKEND-USERS`: `b0fd200021b65e19c3a133f95de480804f6fd87e` — آخر CI ناجح على SHA قديم؛ يحتاج مزامنة main.
- لا تنشئ فروع phase/feature أو فروعًا مؤقتة؛ لا تحذف أي فرع دائم.
- بعد كل دفعة main، تتم مزامنة TEST وUSERS مع الحفاظ على الاختبارات والنطاق المسموح فقط.
- Phase04 PARTIAL للتكاملات الخارجية، Phase05 COMPLETE، Phase06 IN_PROGRESS مع Playlists وCI verification غير مكتملين.
