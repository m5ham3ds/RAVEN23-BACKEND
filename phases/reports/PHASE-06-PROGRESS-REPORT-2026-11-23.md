# تقرير تقدم Phase06 — 23 نوفمبر 2026

**الحالة:** IN_PROGRESS — تنفيذ جزئي؛ لا تُغلق المرحلة ولا تنتقل إلى Phase07.
**المستودع:** `m5ham3ds/RAVEN23-BACKEND`
**SHA أساس المراجعة:** `9dca996bf024a55a9c2eaa075793bfd450ee41e7`
**آخر SHA كود على main قبل تحديث وثائق هذا التقرير:** `8219a5833c7a322c00d53bdebe8c4b30cfad3218`
**Flutter contract SHA:** `ffe543b6d263c4d1f8b5a84922f348c705baf138`.

## 1. ما تم التحقق منه قبل التنفيذ

- تم التأكد من حذف الفروع الستة القديمة بعد مقارنة الفروق وPRs وCI.
- بقيت الفروع الدائمة الثلاثة فقط: main وRAVEN23-BACKEND-TEST وRAVEN23-BACKEND-USERS.
- تمت مراجعة جميع ملفات phases ومواصفات Phase00–13 وتقارير Phase00–05، إضافة إلى Git tree والكود وroutes والمigrations والاختبارات وworkflows.
- تم مطابقة عقد Flutter للمفضلة وسجل المشاهدة والقوائم، بما في ذلك revisions وtombstones وclock skew وoperation IDs وstable pagination.
- لم يتم استخدام قاعدة الإنتاج أو قراءة قيم الأسرار أو تنفيذ نشر.

## 2. تنفيذ Phase06 الموجود على main

### 2.1 Bookmarks — كود موجود سابقًا في SHA الأساس
- Model: `app/Models/UserBookmark.php`.
- Controller: `app/Http/Controllers/Api/BookmarkSyncController.php`.
- Migration: `database/migrations/2026_11_23_000008_create_user_bookmarks_table.php`.
- Routes: `POST /api/v1/sync/bookmarks` و`DELETE /api/v1/sync/bookmarks/{type}/{id}`.
- owner scope عبر المستخدم المصادق، unique (user_id, media_type, media_id)، حذف idempotent وإعادة union المفضلة movie/tv.
- Feature test: `tests/Feature/BookmarkSyncTest.php` على فرع TEST.

### 2.2 Recently watched / Playback progress — أضيف في هذه المراجعة
- Migration: `database/migrations/2026_11_23_000009_create_user_recent_media_tables.php`.
- Tables: `user_recent_media` و`user_sync_revisions`.
- Models: `app/Models/UserRecentMedia.php`, `app/Models/UserSyncRevision.php`.
- Controller: `app/Http/Controllers/Api/RecentlyWatchedSyncController.php`.
- Route: `POST /api/v1/sync/recently-watched` خلف `auth:sanctum`.
- يدعم movies وepisodes، batch max 450 لكل نوع، UTC millisecond timestamps، user-scoped revision، transaction/row lock، LWW، tombstones، exact upload acknowledgements، 422 clock-skew وrevision-ahead.
- تطبيع tombstone يمنع بقاء المحتوى محذوفًا عندما تكون علامة التحديث أحدث من علامة الحذف.
- migration additive وforward-only، وFK owner cascade وunique owner/media tuple.

### 2.3 Data export
- `GET /api/v1/user/data-export` محدث إلى `format_version=2`.
- يشمل account/profile/preferences/devices بالإضافة إلى bookmarks وrecently_watched.
- لا يتضمن password أو google_id أو remember_token أو Sanctum tokens/secrets.
- تم تحديث assertion في `tests/Feature/UserDataFoundationTest.php` إلى version 2.

## 3. الاختبارات المضافة إلى فرع TEST

- `tests/Feature/RecentlyWatchedSyncTest.php`: full sync للم movies/episodes، upload ack/idempotency، منع stale progress، tombstones ومنع resurrection، newer progress overriding older tombstone، owner isolation، clock skew، revision ahead، validation/auth، وexport v2.
- `tests/Integration/PostgresRecentlyWatchedSyncTest.php`: PostgreSQL restricted-app-role، owner scope، recent progress وtombstone.
- `tests/Feature/BookmarkSyncTest.php`: اختبار المفضلة الموجود من قبل.
- تم تعديل Docker integration step ليشغل مجلد `tests/Integration` كاملًا بحيث يلتقط اختبار Postgres الجديد على فرع TEST.
- هذه الملفات لا تنقل التطبيق أو migrations إلى فرع TEST؛ الكود الوظيفي باقٍ على main.

## 4. سجل commits

| SHA | التغيير |
|---|---|
| `1cb6047aa13e41065f7932a091c85695b6bb24e0` | إضافة recent sync الأولى؛ احتوت صياغة namespace غير صحيحة واكتُشفت أثناء مراجعة Git tree |
| `93460e79a71e0b439302b88400c1f328e164eabf` | تصحيح backslashes في namespace/use للملفات الجديدة |
| `8219a5833c7a322c00d53bdebe8c4b30cfad3218` | جعل التحديث الأحدث يتغلب على tombstone الأقدم وتصحيح upload acknowledgement للحالة الفعالة |

تم تصحيح الصياغة غير السليمة في commit لاحق؛ لا نحذف التاريخ أو نعيد كتابة commits.

## 5. حالة CI — غير معتمدة بعد

على SHA `8219a5833c7a322c00d53bdebe8c4b30cfad3218`: Docker Validation #352، Pint #85، Security #87 — conclusion failure.
على TEST SHA `08f5e05fec98d19cf3d18a0b2993352680952f2b`: Docker Validation #351، Pint #84، Security #86 — conclusion failure.

عند فحص jobs لم تظهر خطوات تنفيذ (steps فارغة/null)، ولم تكن سجلات الوظائف متاحة عبر endpoint الخاص بها. لذلك لا يمكن تحديد اختبار أو خطوة PHP فشلت؛ كما لا يوجد إثبات أن الكود اجتاز البوابات. نتائج USERS السابقة (Auth #4/Pint #80/Security #82 SUCCESS) تخص SHA أقدم ولا تثبت آخر main.

## 6. ما لم ينفذ بعد

- Playlist sync endpoint `POST /api/v1/sync/playlists`.
- Operation log فريد لكل operation_id، وإعادة إرسال آمنة.
- عمليات playlist.create/update/delete/reorder وitem.add/remove.
- base_version conflict handling للسجلات المتنافسة.
- stable pagination عبر until_revision/cursor وatomic apply بعد جميع الصفحات.
- SQLite/PostgreSQL tests تغطي كل operations، conflicts، pagination، limits، ownership، idempotency وaccount export للقوائم.
- بعد ذلك إعادة تشغيل Docker/Pint/Security على SHA موحد ناجح وتحديث المصفوفة وERD.

## 7. بوابة الأمان والبيانات

- لم يتم الاتصال بـNeon أو Render أو DB الإنتاج.
- لم تتم قراءة قيم GitHub secrets أو ملف .env أو DB URL أو APP_KEY.
- لم يتم تشغيل migrations في بيئة إنتاج، ولم يحدث deploy أو AWS resource creation.
- لم يتم حذف حساب أو token أو سجل مشاهدة؛ التحقق كان عبر source tree وسجلات CI فقط.

## 8. قرار الاستمرار

Phase06 تبقى IN_PROGRESS. الخطوة التالية ضمن نفس المرحلة هي Playlist sync بعد استعادة بوابات CI القابلة للتنفيذ؛ Phase07 غير مصرح ببدئها قبل اكتمال Phase06، نجاح اختبارات SQLite/PostgreSQL وPint/Security، وتحديث توثيق الإغلاق.

## 9. مراجع الفروع النهائية بعد توثيق Phase06 ومزامنة الفروع

- main documentation commit: `a3a5e85777f1b303230f0ebaeb2583a851185a71` (كود التطبيق الذي خضع للمراجعة: `8219a5833c7a322c00d53bdebe8c4b30cfad3218`).
- TEST: `e5da2b9ce3c880fd7264015719dc76e9c4e1ae36`؛ diff من main أربعة ملفات اختبار فقط: BRANCH_SCOPE وBookmarkSyncTest وRecentlyWatchedSyncTest وPostgresRecentlyWatchedSyncTest.
- USERS: `22ded7925bd4cfcd31914268359ffb041c13384b`؛ diff من main ملف `tests/BRANCH_SCOPE.md` فقط.
- تم تأكيد وجود ثلاثة branch refs فقط ولا توجد PRs مفتوحة.
- إعادة تشغيل الوظائف الفاشلة مرة واحدة على كل من main وTEST وUSERS لم تحل التعثر؛ بقيت جميع jobs بلا خطوات تنفيذ أو سجلات متاحة.
