# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 23 نوفمبر 2026
**main — Phase06 code SHA:** `fea7045178e603a7b54150e75475f5d27960d306`
**RAVEN23-BACKEND-TEST:** `15369516309ddd6122a358a82063a48ca6e8b7d3`
**RAVEN23-BACKEND-USERS:** `c789b333574b140d1aed23ce9982dbe6a5c945e0`
**الفروع الدائمة الوحيدة:** `main`, `RAVEN23-BACKEND-TEST`, `RAVEN23-BACKEND-USERS`.

## 1. ملخص حالة المراحل

| المرحلة | الحالة الحالية | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | التقرير التاريخي محفوظ؛ الجرد الحالي محدث بملحق 23 نوفمبر. |
| 01 — مشروع Laravel القابل لإعادة البناء | COMPLETE | ملفات Composer وLaravel skeleton ضمن المستودع؛ أدلة الإغلاق التاريخية محفوظة. |
| 02 — runtime محمول | COMPLETE | Docker/FrankenPHP/Caddy وreadiness/non-root مثبتة في CI التاريخي. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | متطلبات مزود الإنتاج وTLS/backups/restore بوابة ما قبل الإنتاج. |
| 04 — المصادقة والحسابات | PARTIAL | code/CI التاريخي اختُبر؛ Google Android الحقيقي وتسليم البريد الحقيقي في staging لم يثبتا. |
| 05 — أساس بيانات المستخدم | COMPLETE على مستوى schema/API/code/CI التاريخي | profile/preferences/devices وتصدير v1 موثقة؛ لا يعني ذلك تحققًا إنتاجيًا. |
| 06 — المكتبة وسجل المشاهدة والقوائم | IN_PROGRESS — الكود موجود؛ CI BLOCKED | bookmarks وrecent progress وplaylist sync مطبقة، والاختبارات مضافة؛ لا يوجد CI PASS لأن GitHub لم يخصص runners. |
| 07 — المزامنة بين الأجهزة والعمل دون اتصال | NOT_STARTED | لا تبدأ قبل اعتماد Phase06. |
| 08 — التخزين والإشعارات والمهام | NOT_STARTED | وفق الاعتماديات وموافقات الموارد. |
| 09 — الاشتراكات والصلاحيات | WAITING_EXTERNAL | تتطلب قرارات المنتج ومزود الدفع والأسعار والقواعد. |
| 10 — الجودة والإصدارات | NOT_STARTED | تشمل توحيد workflows وتحسين الاختبارات وإثبات release. |
| 11 — AWS والنشر | WAITING_EXTERNAL | لا إنشاء موارد أو تكاليف قبل موافقة صريحة. |
| 12 — نقل البيانات والتحويل | WAITING_DEPENDENCY | لا يبدأ قبل backup/restore rehearsal وموافقة cutover. |
| 13 — تشغيل الإنتاج والتعافي | WAITING_DEPENDENCY | يعتمد على Phase12. |

## 2. الوضع الفعلي لـPhase06 على main

- **Bookmarks:** migration `2026_11_23_000008_create_user_bookmarks_table.php`، نموذج `UserBookmark`، controller وPOST/DELETE routes owner-scoped.
- **Recently watched / playback:** migration `2026_11_23_000009_create_user_recent_media_tables.php`، جدولا `user_recent_media` و`user_sync_revisions`، مع LWW وrevisions وtombstones وclock-skew handling وupload acknowledgements.
- **Playlists:** migration `2026_11_23_000010_create_user_playlist_sync_tables.php` تنشئ `user_playlists`, `user_playlist_items`, `user_playlist_operations`, `user_playlist_changes`.
- المسار `POST /api/v1/sync/playlists` خلف `auth:sanctum` يدعم العمليات الست، owner scope، idempotency حسب operation_id، version conflicts، حدود 200 قائمة و2500 عنصر، وstable snapshot pagination عبر until_revision/cursor.
- إزالة عنصر تعيد ترتيب positions وتسجل تغييرات العناصر المتأثرة في نفس revision.
- تصدير الحساب محدث إلى `format_version=3` ويشمل account/profile/preferences/devices/bookmarks/recently_watched/playlists.
- اختبارات Feature وPostgreSQL الخاصة بالقوائم والمزامنة موجودة في فرع TEST فقط. اختبار account export الأساسي في main محدث إلى v3 حتى لا يفشل Docker Feature suite.

## 3. حالة GitHub Actions على رؤوس الفروع

- main `fea7045178e603a7b54150e75475f5d27960d306`: Docker #360 (run 45083888323)، Pint #96 (45083888275)، Security #98 (45083888350) — conclusion failure.
- TEST `15369516309ddd6122a358a82063a48ca6e8b7d3`: Docker #361 (run 45083923781)، Pint #97 (45083923772)، Security #99 (45083923791) — conclusion failure.
- USERS `c789b333574b140d1aed23ce9982dbe6a5c945e0`: Auth #8 (run 45083951166)، Pint #98 (45083951162)، Security #100 (45083951138) — conclusion failure.
- فحص تفاصيل job لآخر Docker على main، وآخر Docker على TEST، وآخر Auth على USERS كشف `runner_id=0`, `runner_name=""`, و`steps=[]`. لم يتم تخصيص runner، ولذلك لم يبدأ PHP أو الاختبارات. جلب سجلات الوظائف يعيد BlobNotFound.
- تكرر هذا بعد إعادة المحاولة في الرؤوس السابقة. لا يوجد دليل على فشل اختبار كود محدد، ولا توجد نتيجة PASS للرؤوس الحالية.
- الاحتمال التشغيلي هو حصة/فوترة GitHub Actions للمستودع الخاص أو منع تخصيص runner، لكنه غير مؤكد دون صلاحية صفحة Billing لدى مالك المستودع.

**بوابة الاعتماد:** Phase06 تبقى IN_PROGRESS / CI BLOCKED إلى أن يتم تخصيص runners، وتنجح Docker + SQLite Feature + PostgreSQL Integration + Pint + Security على SHA موحد. لا انتقال إلى Phase07 قبل ذلك.

## 4. سياسة الفروع
- الفروع القديمة الستة حُذفت بعد مراجعة فروقها وPRs وCI.
- الفروع الدائمة الثلاثة فقط أعلاه؛ main للكود والمigrations والوثائق، TEST للاختبارات العامة، USERS لاختبارات الحسابات.
- لا تنشئ phase/feature branches إضافية، ولا تحذف أي فرع دائم.
- لا يتم لمس DB الإنتاج أو قراءة أسرارها أو تنفيذ نشر/AWS دون موافقة صريحة.

## 5. الاستكمال المطلوب
1. حل عائق تخصيص GitHub Actions runner من إعدادات/حصة Billing للمستودع الخاص لدى مالكه، ثم إعادة تشغيل البوابات.
2. إصلاح أي فشل مصدر/اختبار يظهر بعد بدء الخطوات الفعلية؛ لا تخمن سببًا من jobs فارغة.
3. إغلاق Phase06 بعد نجاح جميع معايير القبول على SHA موحد، ثم الانتقال إلى Phase07.
