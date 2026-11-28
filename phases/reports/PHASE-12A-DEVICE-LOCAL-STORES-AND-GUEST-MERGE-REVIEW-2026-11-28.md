# تقرير Phase12A — جرد مخازن الأجهزة المحلية ومراجعة guest/offline merge

- **الحالة:** PARTIAL — source-code inventory اكتمل؛ device-level proof وguest playlist/journal acceptance ما زالا مفتوحين.
- **التاريخ:** 2026-11-28
- **Backend repository:** `m5ham3ds/RAVEN23-BACKEND`
- **Backend SHA:** `acef3f2a6ce447e45732311efb06b2782fe07790`
- **فرع Backend:** `main`
- **Android source:** `m5ham3ds/RAVEN23` على `387feafb4865d991223897088b82eacb7a42061a` — قراءة فقط.
- **مقارنة Android source:** `51f12760cc4e06a5651f7e07dbd89f774bafece4`؛ commit واحد غيّر ملفي phases/README فقط، ولم يغيّر كود SQLite/sync.
- **PR:** لا يوجد؛ لم يتغير مستودع Android.

## 1. النتيجة التنفيذية

استُكملت الشريحة التالية من Phase12A بتدقيق مخازن الأجهزة المحلية وسلوك guest/offline merge على مستوى المصدر. تم تحديد ستة ملفات SQLite وطبقة SharedPreferences منفصلة، وتوثيق آليات النسخ والـACK والـtombstones والـowner mapping.

هذا source-code inventory وليس قياسًا حيًا لحجم بيانات الأجهزة أو اكتمال المصدر. لم نصل إلى أجهزة أو ملفات SQLite فعلية أو Firebase/Firestore أو PostgreSQL إنتاجية.

## 2. مصادر المراجعة

- `lib/data/sync/library_scope.dart`
- `lib/data/sync/local_library.dart`
- `lib/data/sync/laravel_sync_coordinator.dart`
- `lib/controllers/bookmark_database_controller.dart`
- `lib/controllers/recently_watched_database_controller.dart`
- `lib/controllers/wellness_database_controller.dart`
- `lib/data/playlists/playlist_store.dart`
- `lib/data/playlists/playlist.dart`
- `lib/data/sync/recent_sync_engine.dart`
- `lib/data/sync/wellness_sync_engine.dart`
- `lib/data/repositories/bookmark_repository.dart`
- `lib/core/storage/kv_store.dart`
- `test/data/laravel_sync_coordinator_test.dart`
- `test/data/playlist_sync_engine_test.dart`

## 3. خريطة التخزين

| المخزن | العزل | بيانات مهمة |
|---|---|---|
| movies.db / movie_bookmark_table | per-owner filename عند تفعيل LibraryScope | rows وmetadata؛ bookmark journals في SharedPreferences. |
| tv.db / tv_bookmark_table | per-owner filename عند التفعيل | فصل media_type=movie عن media_type=tv. |
| recent_movies.db | per-owner filename عند التفعيل | progress وUTC millisecond timestamps وtombstones وsynced. |
| recent_episodes_v2.db | per-owner filename عند التفعيل | episode ID وهوية الموسم/الحلقة والتقدم والتومبستون. |
| playlists.db | per-owner filename عند التفعيل | confirmed lists/items وpending operations وcheckpoint وsource/share metadata. |
| raven23_wellness_v1.db | ملف واحد، owner_id داخل الصف | sessions وtombstones؛ daily summaries مشتقة. |

## 4. guest/offline merge كما ينفذه العميل

- LocalLibrary ينسخ جميع صفوف bookmark/recent، بما فيها pending/tombstones.
- نسخ recent تقارن updated_at_utc وتصبح unsynced؛ guest snapshot لا يُمسح إلا إذا بقي الصف مطابقًا له بعد نجاح المزامنة.
- Wellness يعيد ربط owner_id من guest إلى user:<id> ويعيد بناء daily summaries.
- PlaylistStore.moveInto ينقل pending operations بمعرفاتها فقط، وليس confirmed lists/items.
- عند فشل الشبكة يحتفظ العميل بclaim ونسخة guest حتى retry ناجح. توجد تغطية Flutter لهذا السلوك في bookmark/recent/wellness.

## 5. المخاطر المفتوحة

### 5.1 Confirmed playlist state

PlaylistStore.moveInto يرجع إذا لم توجد pending operations. وإذا وجدت، ينسخ queue إلى الحساب ثم clear() يحذف جداول guest كلها: playlists/items/operations/meta. لا ينقل confirmed state أو source/share metadata. اختبار guest transfer الحالي queue-only ولا يغطي confirmed rows/items.

هذا **خطر مثبت في منطق النقل وفجوة في تغطية الاختبار**، وليس إثباتًا أن بيانات مستخدم فعلية فُقدت. يجب إثبات أن confirmed guest state غير ممكن في جميع cohorts أو إضافة اختبار نقل وحفظ صريح في RAVEN23 قبل قبول نقل الجهاز.

### 5.2 Bookmark deletion/known journals

bookmark known IDs وسجل pending deletions محفوظان في SharedPreferences منفصلة. مسار mergeGuest لا يُظهر خطوة صريحة لنقل هذه المفاتيح من guest إلى user scope. قد لا يؤثر ذلك في guest محلي بحت، لكنه لا يستبعد قبل تحديد legacy/Firebase source.

### 5.3 Wellness owner mapping and derived aggregates

Wellness store ملف مشترك؛ ownership عبر owner_id. يجب إعادة بناء daily summaries من sessions والتحقق من الناتج بعد owner mapping.

### 5.4 Tombstones/cursors

recent tombstones تُحذف بعد 90 يومًا والعميل يطلب full pull كل 7 أيام؛ جهاز offline لأكثر من 90 يومًا يحتاج reconciliation. لا تنقل revision/cursor/checkpoint إلى هدف جديد؛ ابدأ full snapshot جديدًا.

## 6. تصميم evidence manifest للأجهزة — aggregate-only

قبل أي جمع مستقبلًا، يتضمن manifest: source/app SHA، schema versions، file availability، table counts، pending/confirmed/tombstone counts وأعمارها، playlist confirmed/pending counts، source/share metadata counts، bookmark known/deletion journal counts، Wellness owner-scope counts وdaily rebuild result، cursor-presence وlast full/last successful sync. لا يتضمن raw rows أو PII أو raw owner IDs أو operation UUIDs.

لم نجمع telemetry أو نقرأ أي ملفات أجهزة حقيقية.

## 7. CI والأدلة على Backend

| Workflow | Run | النتيجة |
|---|---:|---|
| Docker | [#683](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325) | SUCCESS — SQLite 151/1,719؛ PostgreSQL 31/546 مع optional skip؛ S3Mock 1/8؛ full-schema encrypted restore؛ isolated rollback؛ 31/31 steps. |
| Trusted-main signing | Docker #683 | SUCCESS — 17/17 steps؛ release provenance/SBOM and signed manifest. |
| Security | [#429](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608312) | SUCCESS — Gitleaks passed وTrivy production image Clean. |
| Authentication | [#305](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608344) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99. |
| Pint | [#427](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608301) | SUCCESS — changed-PHP check skipped لعدم تغيير PHP؛ strict Pint داخل Docker passed. |

Release bundle [15240108019](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325/artifacts/15240108019)؛ synthetic restore evidence [15239713859](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46088608325/artifacts/15239713859). لا registry push أو deploy.

## 8. التغييرات وحدودها

- تحديث وثائق Backend تحت phases/ فقط.
- لم يتغير مستودع RAVEN23 أو كود Backend أو API routes أو migrations أو Docker/workflows.
- لم يتم الوصول إلى production DB/Firestore أو أجهزة أو ملفات مستخدمين أو أسرار.
- لا provider/target selection ولا backup/restore حقيقي ولا cutover.

## 9. الخطوة التالية المسموح بها

تستمر Phase12A بالأعمال المحايدة: synthetic acceptance specification لguest confirmed playlists وbookmark deletion journals، ثم consistent device snapshot/delta manifest design. أي إصلاح كود لهذه الفجوات يجب أن يتم في مستودع RAVEN23 فقط ضمن تعليمات صريحة؛ لم نعدّل ذلك المستودع.

Phase12B/12C تبقيان WAITING_EXTERNAL/OWNER_APPROVAL. لا اختيار source/target أو provider أو تنفيذ نقل.