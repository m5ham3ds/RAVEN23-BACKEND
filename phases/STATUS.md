# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 23 نوفمبر 2026
**main HEAD عند آخر مزامنة توثيقية:** `a3a5e85777f1b303230f0ebaeb2583a851185a71` (كود التطبيق راجعه SHA `8219a5833c7a322c00d53bdebe8c4b30cfad3218`)
**RAVEN23-BACKEND-TEST:** `e5da2b9ce3c880fd7264015719dc76e9c4e1ae36` (مزامن مع main؛ 4 ملفات اختبار إضافية فقط)
**RAVEN23-BACKEND-USERS:** `22ded7925bd4cfcd31914268359ffb041c13384b` (مزامن مع main؛ ملف BRANCH_SCOPE فقط)
**الفروع الوحيدة:** `main`, `RAVEN23-BACKEND-TEST`, `RAVEN23-BACKEND-USERS`.

## 1. ملخص حالة المراحل

| المرحلة | الحالة الحالية | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | التقرير التاريخي محفوظ؛ الجرد الحالي أُضيف له ملحق في 23 نوفمبر. |
| 01 — مشروع Laravel القابل لإعادة البناء | COMPLETE | ملفات Composer وLaravel skeleton ضمن المستودع؛ دليل الإغلاق التاريخي في تقارير المرحلة. |
| 02 — runtime محمول | COMPLETE | Docker/FrankenPHP/Caddy وreadiness/non-root مثبتة في CI التاريخي. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | متطلبات مزود الإنتاج وTLS/backups/restore بوابة ما قبل الإنتاج. |
| 04 — المصادقة والحسابات | PARTIAL | code/CI اختُبر؛ Google Android الحقيقي وتسليم البريد الحقيقي في staging لم يثبتا. |
| 05 — أساس بيانات المستخدم | COMPLETE على مستوى schema/API/code/CI التاريخي | profile/preferences/devices وبيانات الحساب وتصدير v1 موثقة؛ لا يعني ذلك تحققًا إنتاجيًا. |
| 06 — المكتبة وسجل المشاهدة | IN_PROGRESS | المفضلة وسجل المشاهدة/التقدم بالكود على main؛ القوائم المخصصة غير منفذة، وCI للـSHA الحالي غير معتمد. |
| 07 — المزامنة بين الأجهزة | NOT_STARTED | تعتمد على إغلاق Phase06 أولًا. |
| 08 — التخزين والإشعارات والمهام | NOT_STARTED | وفق الاعتماديات وموافقات الموارد. |
| 09 — الاشتراكات والصلاحيات | WAITING_EXTERNAL | تتطلب قرارات المنتج ومزود الدفع والأسعار والقواعد. |
| 10 — الجودة والإصدارات | NOT_STARTED | تشمل توحيد workflows وتحسين الاختبارات وإثبات release. |
| 11 — AWS والنشر | WAITING_EXTERNAL | لا إنشاء موارد أو تكاليف قبل موافقة صريحة. |
| 12 — نقل البيانات والتحويل | WAITING_DEPENDENCY | لا يبدأ قبل backup/restore rehearsal وموافقة cutover. |
| 13 — تشغيل الإنتاج والتعافي | WAITING_DEPENDENCY | يعتمد على Phase12. |

## 2. الوضع الفعلي لـPhase06

### ما هو موجود على main
- المفضلة: `user_bookmarks`، نموذج `UserBookmark`، و`BookmarkSyncController` مع POST/DELETE routes.
- سجل المشاهدة والتقدم: migration `2026_11_23_000009_create_user_recent_media_tables.php` تنشئ `user_recent_media` و`user_sync_revisions`.
- `RecentlyWatchedSyncController` و`POST /api/v1/sync/recently-watched` يدعمان ملكية الحساب، revisions، UTC milliseconds، LWW، tombstones، clock-skew response وupload acknowledgements.
- تصدير الحساب صار `format_version=2` ويضم بيانات المفضلة وسجل المشاهدة مع account/profile/preferences/devices.
- اختبارات المفضلة وسجل المشاهدة أضيفت إلى فرع TEST فقط.

### ما لم يكتمل
- Playlist sync: operation log، idempotency حسب operation_id، version conflicts، عمليات create/update/delete/reorder/add/remove، وstable pagination عبر until_revision/cursor.
- توسيع اختبارات PostgreSQL/SQLite وتشغيلها فعليًا على commit موحد.
- تحديث ERD وAPI contract والـFeature Matrix وإثبات كل معايير القبول.

## 3. حالة CI على رؤوس الفروع المتزامنة

- main `a3a5e85777f1b303230f0ebaeb2583a851185a71`: Docker #353 (run 45081864951)، Pint #86 (45081865002)، Security #88 (45081865004). أُعيدت الوظائف مرة واحدة (attempt 2) وما زالت conclusion=failure.
- TEST `e5da2b9ce3c880fd7264015719dc76e9c4e1ae36`: Docker #354 (45081894162)، Pint #87 (45081894210)، Security #89 (45081894207). أُعيدت مرة واحدة وما زالت failure.
- USERS `22ded7925bd4cfcd31914268359ffb041c13384b`: Auth #5 (45081903413)، Pint #88 (45081903427)، Security #90 (45081903433). أُعيدت مرة واحدة وما زالت failure.
- عند فحص jobs لم تظهر خطوات تنفيذ (steps فارغة/null) وسجلات الوظائف غير متاحة؛ لا يوجد دليل أن PHP أو الاختبارات وصلت إلى التنفيذ. لا نصنف هذه النتائج كفشل اختبار كود محدد، ولا نصنفها PASS. هذه النتيجة تكررت بعد retry للوظائف الثلاثة على كل فرع.

**بوابة الاعتماد:** Phase06 تبقى IN_PROGRESS إلى أن تعمل Docker + Pint + Security وPostgreSQL integration على SHA واحد معروف وتنجح جميع اختبارات القبول. لا انتقال إلى Phase07 قبل ذلك.

## 4. سياسة الفروع والتنفيذ
- الفروع الدائمة الثلاثة فقط؛ تم حذف الفروع الستة القديمة بعد مراجعة فروقها وPRs وCI.
- التطوير والـmigrations والوثائق على main.
- الاختبارات العامة فقط على TEST، واختبارات الحسابات فقط على USERS.
- لا تنشئ phase/feature branches إضافية، ولا تحذف أيًا من الفروع الدائمة.
- لا يتم لمس DB الإنتاج أو قراءة أسرارها أو تنفيذ نشر/AWS دون موافقة صريحة.

## 5. نقاط الاستكمال
1. استعادة بوابات CI على SHA حديث بعد معالجة تعثر GitHub job startup؛ لا تكرر محاولات عشوائية.
2. إكمال Playlist sync وفق عقد Flutter المثبت في Phase06.
3. إضافة اختبارات operation idempotency وversion conflicts وpagination وحذف/إعادة ترتيب القوائم والعناصر.
4. تحديث جميع وثائق المرحلة وERD، ثم تشغيل البوابات على SHA موحد.
