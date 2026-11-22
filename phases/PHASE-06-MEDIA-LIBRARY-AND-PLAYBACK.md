# المرحلة 06 — المكتبة الشخصية وسجل المشاهدة وتقدم التشغيل

**الحالة:** IN_PROGRESS — bookmarks وrecently-watched أضيفا إلى main؛ Playlist sync والاختبار النهائي ما زالا باقيين.  
**الاعتماديات:** Phase05 COMPLETE؛ عقد Flutter المصدر تمت مراجعته على SHA `ffe543b6d263c4d1f8b5a84922f348c705baf138`

## الهدف
توفير API دائم لحفظ بيانات المستخدم الشخصية المرتبطة بالمحتوى، دون تخزين ملفات الفيديو أو بيانات المصدر العامة داخل قاعدة الحسابات.

## الوظائف المطلوبة
1. Favorites: إضافة/حذف/عرض العناصر المفضلة مع منع التكرار.
2. Watchlists/Collections: قوائم مخصصة، ترتيب العناصر، وإمكانية الخصوصية عند الحاجة.
3. Watch History: تسجيل ومراجعة وحذف سجل المشاهدة مع سياسة احتفاظ واضحة.
4. Playback Progress: حفظ موضع التشغيل، مدة المحتوى، الحلقة/الموسم، آخر تحديث، وحالة الاكتمال.
5. Continue Watching: استخراج العناصر التي يمكن استكمالها وفق قواعد متفق عليها.
6. دعم Movie/Series/Season/Episode/Anime/Manga فقط وفق مخطط المحتوى الفعلي في العميل.
7. حذف/تعديل السجلات بعمليات idempotent لتفادي ازدواج السجلات عند إعادة إرسال الطلب.
8. Pagination وbatch endpoints عند الحاجة مع حدود حجم.
9. التحقق من ملكية كل عنصر وربط الـcontent ID بمصدره بشكل ثابت.
10. تصميم retention وexport/delete للمستخدم.

## معايير القبول
- عمليات CRUD تعمل من API وتنجح على PostgreSQL.
- لا يستطيع مستخدم قراءة أو تغيير بيانات مستخدم آخر حتى عند تخمين معرف السجل.
- تكرار نفس الطلب لا ينتج سجلات مزدوجة.
- تقدم التشغيل لا يعود للخلف بسبب طلب قديم إلا وفق قواعد conflict صريحة.
- اختبارات الحدود: مدة سالبة، موضع أكبر من المدة، metadata ناقصة، حذف متكرر، وحلقات مختلفة.
- عقد API موثق ومطابق لما يستخدمه تطبيق RAVEN23.

## خارج النطاق
- لا تخزين أو استضافة ملفات الفيديو.
- لا بناء محرك اكتشاف metadata أو تبديل مصادر البث ضمن هذه المرحلة.
- لا تغيير UI Android غير المطلوب للتكامل الموثق.


## عقد Flutter الفعلي — مراجعة 22 نوفمبر 2026

**مصدر المراجعة:** مستودع `m5ham3ds/RAVEN23` على SHA `ffe543b6d263c4d1f8b5a84922f348c705baf138`. تمت مراجعة repositories/sync engines/models/local stores/tests الخاصة بالمفضلة وسجل المشاهدة والقوائم.

### Bookmarks / Favorites
- `POST /api/v1/sync/bookmarks`: `movies[]`, `tvShows[]`, `deleted_media[]`؛ الهوية `(media_type, media_id)` مع `movie` أو `tv`.
- `DELETE /api/v1/sync/bookmarks/{type}/{id}` حذف idempotent ومقيد بالمالك.
- الاستجابة الكاملة: `success`, `movies[]`, `tvShows[]`. يحتفظ العميل بجرد known IDs وسجل tombstones حتى لا يعيد إنشاء عنصر محذوف.

### Recently watched / Playback progress
- `POST /api/v1/sync/recently-watched`: `since_revision`, `movies[]`, `episodes[]`, `client_time_utc` بوحدة UTC milliseconds.
- Movie row: `movie_id,title,release_year,elapsed,remaining,date_watched,poster_path,backdrop_path,updated_at_utc,deleted_at_utc`.
- Episode row: `episode_id,series_id,series_name,episode_name,episode_num,season_num,elapsed,remaining,date_added,poster_path,backdrop_path,updated_at_utc,deleted_at_utc`. حقل `is_anime` محلي ولا يرسله Laravel client.
- Response: `server_revision,server_time_utc,movies[],episodes[],uploads.movies[],uploads.episodes[],full` مع دعم `requires_full_sync`.
- أحدث `updated_at_utc` يفوز؛ tombstone أحدث يحذف القديم؛ progress محلي أحدث لا يستبدل بطلب قديم. لا يقر الخادم upload إلا للنسخة التي استلمها.
- انحراف الساعة: HTTP 422 مع `error=clock_skew_detected` و`server_time_utc`؛ العميل يصحح ويعيد مرة واحدة. العميل يحتفظ محليًا بـtombstones حتى 90 يومًا؛ يلزم اعتماد retention server-side.

### Playlists / Collections
- `POST /api/v1/sync/playlists`: `operations[]`, `since_revision`, `until_revision`, `cursor`؛ max 500 operations/request.
- كل operation: UUID `operation_id`, `action`, UUID `playlist_id`؛ actions: `playlist.create/update/delete/reorder` و`item.add/remove`.
- update/reorder يحمل `base_version`؛ reorder يحمل `items[]` من `media_type/media_id`. Movie وTV منفصلان حتى لو تشابه ID.
- حدود العميل: 200 قائمة/مستخدم، 2500 عنصر/قائمة، الاسم 80 حرفًا، الوصف 500 حرفًا. العمليات idempotent بواسطة `operation_id`؛ stale version يعاد كرفض موثق.
- Response: `server_revision,full,results[],playlists[],items[],next_cursor`. pagination ثابتة ضمن `until_revision`؛ العميل يجلب كل الصفحات قبل تطبيقها.

### ما لم يُحسم
- Manga/Manhwa لا تظهر كـmedia types في كود bookmark/playlist المراجع؛ لا نضيفها قبل عقد عميل مثبت.
- TMDB/IMDb playlist imports خارج sync core الأول وتحتاج قرارات مصدر البيانات وحدود الاستيراد.
- مشاركة القوائم العامة تحتاج تثبيت host وvisibility policy؛ لا تفعّل قبل قرار موثق.
- wellness sync منفصل عن Phase06.

### خطة التنفيذ
1. Migrations additive لملكية bookmarks/recent progress/playlists/operation log/revision state.
2. Authenticated owner-scoped requests/services.
3. Bookmarks full-union/delete idempotency.
4. Recent LWW/tombstones/revisions/clock-skew/exact upload acknowledgements.
5. Playlist operation log/idempotency/version conflicts/stable paginated snapshots.
6. SQLite + PostgreSQL restricted-role tests: ownership, duplicate requests, conflicts, tombstone races, pagination, clock skew, batch limits.
7. Contracts/Feature Matrix ثم Docker/Pint/Security على SHA موحد.

## تقدم التنفيذ الفعلي — 23 نوفمبر 2026

### Bookmarks — موجودة على main
- Migration: `2026_11_23_000008_create_user_bookmarks_table.php`.
- Model/controller: `UserBookmark.php`, `BookmarkSyncController.php`.
- المسارات: `POST /api/v1/sync/bookmarks` و`DELETE /api/v1/sync/bookmarks/{type}/{id}`.
- Feature tests: `tests/Feature/BookmarkSyncTest.php` على TEST.
- المزامنة والحذف owner-scoped، والمفتاح الفريد يمنع التكرار، والرد يعيد movie/tv union.

### Recently watched / Playback — كود مضاف على main
- Migration: `2026_11_23_000009_create_user_recent_media_tables.php` تنشئ `user_recent_media` و`user_sync_revisions`.
- Models: `UserRecentMedia.php`, `UserSyncRevision.php`.
- Controller/route: `RecentlyWatchedSyncController.php`, `POST /api/v1/sync/recently-watched`.
- يدعم movies وepisodes، UTC millisecond stamps، revisions لكل مستخدم، LWW، tombstones، clock-skew 422، revision-ahead 422 وupload acknowledgements.
- التحديث الأحدث يلغي tombstone أقدم؛ حذف أحدث يمنع إحياء نسخة أقدم.
- تصدير البيانات `format_version=2` ويضم bookmarks وrecently_watched مع بيانات الحساب/الملف/التفضيلات/الأجهزة، مع استبعاد أسرار الحساب.

### الاختبارات وحالة الاعتماد
- TEST يحتوي `BookmarkSyncTest.php`, `RecentlyWatchedSyncTest.php`, و`PostgresRecentlyWatchedSyncTest.php` إضافة إلى ملف نطاق الفرع.
- أضيف اختبار يثبت أن تقدمًا أحدث يتغلب على tombstone أقدم.
- main `8219a5833c7a322c00d53bdebe8c4b30cfad3218`: Docker #352 وPint #85 وSecurity #87 انتهت بالفشل دون خطوات تنفيذ معروضة، والسجلات غير متاحة. TEST `08f5e05fec98d19cf3d18a0b2993352680952f2b`: Docker #351 وPint #84 وSecurity #86 لديها الحالة نفسها. لا يوجد PASS أو دليل على failure في PHP نفسه.
- لا يتم اعتماد هذا النطاق قبل نجاح SQLite وPostgreSQL وPint وSecurity على SHA موحد.

### ما لا يزال مطلوبًا لإغلاق Phase06
1. تنفيذ `POST /api/v1/sync/playlists` وفق عمليات Flutter الستة، مع operation idempotency وowner scope.
2. version conflicts في update/reorder وسلوك رفض العملية وفق client replay.
3. pagination snapshot ثابت عبر `until_revision` و`cursor`، وعدم تطبيق البيانات قبل اكتمال كل الصفحات.
4. اختبارات SQLite/PostgreSQL لـcreate/update/delete/reorder/add/remove، التكرار، ownership، النزاعات، pagination، حذف القائمة وعناصرها.
5. تحديث ERD وAPI inventory وFeature Matrix وتشغيل جميع البوابات على SHA موحد.

**الحالة الدقيقة الحالية:** bookmarks وrecent sync منفذان بالكود مع اختبارات مضافة؛ CI لم يعتمدها بعد، وPlaylist sync غير منفذ. Phase06 IN_PROGRESS.
