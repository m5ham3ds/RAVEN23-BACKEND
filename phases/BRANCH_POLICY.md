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

- الفروع الدائمة الثلاثة فقط: main / RAVEN23-BACKEND-TEST / RAVEN23-BACKEND-USERS؛ تم حذف الفروع القديمة الستة.
- آخر دفعة كود Phase06 على main: `fea7045178e603a7b54150e75475f5d27960d306`.
- TEST: `15369516309ddd6122a358a82063a48ca6e8b7d3`؛ فرق الاختبارات عن main يضم BookmarkSyncTest وRecentlyWatchedSyncTest وPlaylistSyncTest وPostgresRecentlyWatchedSyncTest وPostgresPlaylistSyncTest وملف BRANCH_SCOPE.
- USERS: `c789b333574b140d1aed23ce9982dbe6a5c945e0`؛ يحتفظ بملف BRANCH_SCOPE فقط كإضافة خاصة بالفرع.
- main يحتفظ باختبارات Feature الأساسية التي يشغلها Docker، ويظل TEST هو الفرع المخصص للاختبارات العامة الموسعة.
- Phase04 PARTIAL للتكاملات الخارجية، Phase05 COMPLETE، Phase06 code implemented but CI BLOCKED.
- لا تنشئ phase/feature branches. لا تحذف أي فرع دائم. لا تنفذ إنتاج أو AWS.
