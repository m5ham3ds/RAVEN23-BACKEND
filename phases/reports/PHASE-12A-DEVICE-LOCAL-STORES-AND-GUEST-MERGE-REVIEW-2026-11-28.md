# تقرير Phase12A — جرد مخازن الأجهزة المحلية ومراجعة guest/offline merge

- **الحالة:** PARTIAL — source-code inventory اكتمل؛ device-level proof وlegacy Firebase owner re-key وguest/journal acceptance ما زالت مفتوحة.
- **التاريخ:** 2026-11-28
- **Backend repository:** `m5ham3ds/RAVEN23-BACKEND`
- **Backend SHA:** `acef3f2a6ce447e45732311efb06b2782fe07790`
- **فرع Backend:** `main`
- **Android source:** `m5ham3ds/RAVEN23` على `387feafb4865d991223897088b82eacb7a42061a` — قراءة فقط.
- **مقارنة Android source:** `51f12760cc4e06a5651f7e07dbd89f774bafece4`؛ commit واحد غيّر ملفي phases/README فقط، ولم يغيّر كود SQLite/sync.
- **PR:** لا يوجد؛ لم يتغير مستودع Android.

## 1. النتيجة التنفيذية

استُكملت الشريحة التالية من Phase12A بتدقيق مخازن الأجهزة المحلية وسلوك guest/offline merge على مستوى المصدر. تم تحديد ستة ملفات SQLite وطبقة SharedPreferences منفصلة، وتوثيق آليات النسخ والـACK والـtombstones والـowner mapping.

في وضع Laravel sync الافتراضي يُفعّل owner-scoped storage للحسابات؛ guest لا يزامن إلى Laravel ويظل local-only حتى first login. كما كشفنا فجوة مشروطة في re-key لمستخدمي Firebase القدامى. هذا source-code inventory وليس قياسًا حيًا لحجم بيانات الأجهزة أو اكتمال المصدر. لم نصل إلى أجهزة أو ملفات SQLite فعلية أو Firebase/Firestore أو PostgreSQL إنتاجية.

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

هذا **خطر مشروط بفئات بيانات محلية قديمة** وليس إثباتًا أن بيانات مستخدم فعلية فُقدت. guest sync غير متاح في المسار الحالي، لذا لا يتوقع وجود confirmed guest rows في guest جديد؛ لكن إذا وجدت من إصدار سابق، فسيحذفها clear() عند وجود pending operations أو يتركها في ملف guest غير المستخدم عند عدم وجود queue. يلزم preflight cohort أو اختبار legacy fixture في RAVEN23.

### 5.2 Bookmark deletion/known journals

bookmark known IDs وسجل pending deletions محفوظان في SharedPreferences منفصلة. guest لا يزامن إلى Laravel في المسار الحالي، لكن legacy Firebase/older builds قد تترك journal keys. mergeGuest لا يُظهر نقلًا صريحًا لها؛ يلزم حسم semantics وحسابات أي مفاتيح موجودة قبل إسقاطها.

### 5.3 Wellness owner mapping and derived aggregates

Wellness store ملف مشترك؛ ownership عبر owner_id. يجب إعادة بناء daily summaries من sessions والتحقق من الناتج بعد owner mapping.

### 5.4 Tombstones/cursors

recent tombstones تُحذف بعد 90 يومًا والعميل يطلب full pull كل 7 أيام؛ جهاز offline لأكثر من 90 يومًا يحتاج reconciliation. لا تنقل revision/cursor/checkpoint إلى هدف جديد؛ ابدأ full snapshot جديدًا.

## 6. تصميم evidence manifest للأجهزة — aggregate-only

قبل أي جمع مستقبلًا، يتضمن manifest: source/app SHA، schema versions، file availability، table counts، pending/confirmed/tombstone counts وأعمارها، playlist confirmed/pending counts، source/share metadata counts، bookmark known/deletion journal counts، Wellness owner-scope counts وdaily rebuild result، cursor-presence وlast full/last successful sync. لا يتضمن raw rows أو PII أو raw owner IDs أو operation UUIDs.

لم نجمع telemetry أو نقرأ أي ملفات أجهزة حقيقية.

## 7. CI والأدلة على Backend — SHA `b3e73dbf2b3d7943c1f297fb8daccbee93cc07fd`

جميع البوابات الأساسية اجتازت على SHA نفسه:

| Workflow | Run | النتيجة |
|---|---:|---|
| Docker | [#684](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352) | SUCCESS — 31/31 build/test steps؛ SQLite 151/1,719؛ PostgreSQL 31/546 مع optional skip؛ S3Mock 1/8؛ full-schema encrypted restore؛ isolated rollback. |
| Trusted-main signing | Docker #684 | SUCCESS — 17/17 steps؛ provenance/SBOM للصورة الحالية والـrollback وsigned release manifest؛ bundle [15243417105](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352/artifacts/15243417105). |
| Security | [#430](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893380) | SUCCESS — Gitleaks passed وTrivy production image Clean؛ artifact [15243530366](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893380/artifacts/15243530366). |
| Authentication | [#306](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893420) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99. |
| Pint | [#428](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893363) | SUCCESS — changed-PHP check skipped لعدم وجود ملفات PHP changed؛ strict Pint داخل Docker passed. |

Synthetic restore evidence [15242412617](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46094893352/artifacts/15242412617). لا registry push أو deployment.

## 8. التغييرات وحدودها

- تحديث وثائق Backend تحت phases/ فقط.
- لم يتغير مستودع RAVEN23 أو كود Backend أو API routes أو migrations أو Docker/workflows.
- لم يتم الوصول إلى production DB/Firestore أو أجهزة أو ملفات مستخدمين أو أسرار.
- لا provider/target selection ولا backup/restore حقيقي ولا cutover.

## 9. الخطوة التالية المسموح بها

تستمر Phase12A بالأعمال المحايدة: synthetic acceptance specification لguest confirmed playlists وbookmark deletion journals، ثم consistent device snapshot/delta manifest design. أي إصلاح كود لهذه الفجوات يجب أن يتم في مستودع RAVEN23 فقط ضمن تعليمات صريحة؛ لم نعدّل ذلك المستودع.

Phase12B/12C تبقيان WAITING_EXTERNAL/OWNER_APPROVAL. لا اختيار source/target أو provider أو تنفيذ نقل.
## 10. Legacy Firebase account owner re-key — blocker مشروط

- FirebaseSessionBridge يطلب نقل owner من `user:<firebase uid>` إلى `user:<Laravel id>`.
- callback الفعلي في main.dart ينقل Wellness owner_id فقط؛ لا يعيد ربط bookmark/recent/playlist owner-specific SQLite files ولا SharedPreferences journals.
- Firebase bridge test يستخدم fake callback يؤكد تمرير source/target strings فقط ولا يختبر نقل databases. Backend route `/api/v1/auth/firebase/exchange` غير موجود حاليًا، فلا يوجد end-to-end proof.
- login fallback اليدوي يدمج guest owner فقط ولا ينقل تلقائيًا البيانات تحت Firebase UID القديم.

هذا blocker مشروط لlegacy Firebase cohort. المطلوب synthetic owner-rekey fixture يحوي rows/operations/journals تحت المالك القديم، ويثبت النقل إلى Laravel ID مع حفظ المصدر حتى ACK. لا يوجد دليل على فقد بيانات حية.