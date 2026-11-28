# المرحلة 12 — ترحيل البيانات والتحويل الإنتاجي والرجوع الآمن

**الحالة:** IN_PROGRESS — نطاق 12A المحايد للمزود (inventory وCI synthetic/full-schema backup-restore rehearsals). 12B real source/target migration و12C cutover/hypercare تبقى WAITING_EXTERNAL/OWNER_APPROVAL؛ لا إنتاج ولا موافقة cutover.  
**الاعتماديات:** 12A تعتمد على source inventory والمخطط والـmigrations والـCI المحايد؛ يمكن تنفيذها بالتوازي مع بقية أعمال Backend. 12B/12C فقط تنتظر D-051 وقرار الخدمات والمصدر/الوجهة وموافقة مستقلة.

## الهدف
نقل بيانات المستخدم الحالية إلى قاعدة الوجهة دون فقد هوية الحساب أو جلساته/بياناته، وتحويل API بأقل انقطاع ممكن مع وجود خطة rollback.

## العمل
1. جرد مصدر البيانات ووجهتها والإصدارات والمخططات وأحجام الجداول والفهارس.
2. إنشاء backup متسق ومشفر من المصدر والتحقق من integrity.
3. استعادة النسخة في بيئة معزولة ومقارنة counts وIDs والعلاقات والقيود.
4. اختبار migrations على clone ثم rehearsals كاملة متكررة.
5. تحديد استراتيجية التعامل مع الكتابات المتزامنة: maintenance/write freeze أو CDC/delta capture أو dual-write مدروس. لا تفترض أن dump واحدًا يلتقط التغييرات اللاحقة.
6. إعداد تقرير mapping لكل جدول/عمود والتحويلات ومخاطر البيانات.
7. تحديد نافذة cutover وشروط البدء والتوقف والرجوع ومَن يوافق.
8. إيقاف/تجميد الكتابات وفق الخطة وأخذ final backup/delta.
9. تنفيذ نقل البيانات والتحقق من row counts والـchecksums وعينات آمنة من السجلات الاصطناعية/المسموح بها.
10. اختبار login/profile/favorites/history/progress/sync/subscriptions بحسب الأنظمة المفعلة.
11. تحويل API domain أو routing إلى الوجهة الجديدة.
12. إبقاء المصدر القديم سليمًا للرجوع خلال فترة متفق عليها؛ لا تحذفه بعد نجاح أولي.
13. مراقبة أخطاء API وDB ومعدلات الطلب والlatency خلال hypercare.

## معايير القبول
- backup تم استعادته بنجاح قبل cutover.
- row counts/IDs/relationships ومؤشرات سلامة متفق عليها تطابق المتوقع.
- لا توجد كتابة معلقة غير ممثلة في الوجهة.
- smoke tests لكل الوظائف الأساسية ناجحة.
- rollback مجرب ومفهوم.
- موافقة المستخدم المباشرة موثقة لهذه الوجهة وهذا cutover تحديدًا.

## قاعدة حاسمة
لا تجرّب أو تنفذ migration على قاعدة الإنتاج الحقيقية خلال تطوير المراحل السابقة. لا تحذف Neon أو Render من الخطة التشغيلية حتى انتهاء فترة rollback وموافقة المستخدم.


## 2.1 — تحضير آمن قبل الترحيل: PostgreSQL synthetic backup/restore

**الحالة:** PREPARATION — ينتظر نتيجة CI على commit التنفيذ؛ لا يمثل بدء ترحيل بيانات المستخدمين ولا يغير حالة المرحلة الكلية WAITING_DEPENDENCY.

- أضيف `scripts/postgres-backup-restore-rehearsal.sh` لتنفيذ تجربة محصورة بقاعدة PostgreSQL المؤقتة `raven23_test` على `127.0.0.1` ودور CI الاختباري المعروف. يرفض السكربت أي host/database/user/password مختلف، ولا يقبل عنوانًا خارجيًا أو قاعدة إنتاج.
- ينشئ قاعدتي مصدر ووجهة مؤقتتين بأسماء مرتبطة بـGitHub run، ويدخل بيانات اصطناعية فقط (3 حسابات، 4 media progress rows، و2 sync events).
- ينشئ PostgreSQL custom-format dump، يشفره بـAES-256-CBC مع PBKDF2-HMAC-SHA256 (200,000 iteration) ومفتاح عشوائي مؤقت لا يُحفظ أو يطبع، ثم يفك التشفير ويتحقق من تطابق SHA-256 للحمولة.
- يستعيد النسخة إلى قاعدة منفصلة، ثم يقارن أعداد السجلات وبصمات المحتوى والقيود والفهارس بين المصدر والوجهة، ويحفظ تقرير JSON خاليًا من الأسرار والبيانات الحقيقية كـCI artifact.
- يحذف قاعدتي المصدر والوجهة المؤقتتين عند الخروج، بما في ذلك مسار الفشل؛ لا يعد هذا backup خارجيًا معتمدًا أو restore لبيانات RAVEN23 الحقيقية.
- لا ينفذ cutover أو migration على production، ولا يقرأ بيانات أو أسرار إنتاجية.


**ملاحظة CI:** فشلت المحاولة الأولى على `19bbeea` قبل تنفيذ dump لأن صورة PostgreSQL Alpine لا تتضمن برنامج `openssl`. عولج ذلك بتثبيت الأداة داخل الحاوية المؤقتة قبل تشغيل السكربت؛ لا تسجل التجربة PASS إلا بعد التشغيل التالي ورفع JSON evidence بنجاح.


**التشخيص الثاني:** بعد تثبيت OpenSSL نجحت أوامر إنشاء الجداول وإدخال 3/4/2 صفوف اصطناعية، ثم خرج السكربت برمز 1 دون تحديد الخطوة التالية. أضيفت رسائل milestones وفحوصًا صريحة لكل من `pg_dump` والتشفير وفك التشفير و`pg_restore` وأعداد السجلات وبصمات المحتوى والقيود والفهارس. لا يتم تسجيل PASS قبل ظهور نتائج هذه الخطوات في CI.


## 2.2 — إغلاق preflight الاصطناعي والتحقق النهائي — 27 نوفمبر 2026

**الحالة:** PASS للتحضير الاصطناعي فقط؛ حالة Phase12 الكلية تظل `WAITING_DEPENDENCY`.

على main SHA `27cfcc9e577cca33c28e9845096a0c5564b02045` نجح Docker Validation [#45929855650](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650)، وAuthentication [#45929855617](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855617)، وPint [#45929855622](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855622)، وSecurity [#45929855730](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855730). وظيفة التوقيع trusted-main اكتملت 17/17 خطوة.

- نجح round-trip للنسخة الاصطناعية المشفرة AES-256-CBC/PBKDF2-HMAC-SHA256، مع SHA-256 integrity check.
- تمت الاستعادة إلى قاعدة disposable منفصلة، وتطابقت 3 accounts و4 media progress rows و2 sync events، وجميع 10 constraints بما فيها اختبارات سلوك CHECK constraints، والفهارس.
- تقرير JSON: [artifact 15168436709](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168436709).
- اجتازت تجربة rollback مسار HTTP 200 → 502 → 200 إلى الصورة السابقة، واكتملت خمس Sigstore attestations للصورة الحالية وSBOMها، وصورة rollback وSBOMها، وrelease manifest.
- حزمة الإصدار: [artifact 15168861287](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168861287).

هذه النتيجة لا تغطي مصدر بيانات حقيقيًا ولا تثبت backup أو restore إنتاجيًا، ولا تفوض cutover أو migration أو النشر. تبقى معايير القبول الرئيسية للمرحلة 12 كما هي في أعلى هذه الوثيقة.

## تحديث قرار التأجيل — Phase12 — 27 نوفمبر 2026

**الحالة:** WAITING_DEPENDENCY. نجح synthetic PostgreSQL encrypted backup/restore preflight على `27cfcc9`، لكنه لا يحقق شروط ترحيل قاعدة RAVEN23 الحقيقية.

بموجب D-050، يُؤجل اختيار مصدر/وجهة قاعدة البيانات ومزود الاستضافة والمنطقة وأي backup service إلى ما بعد أعمال تطوير Backend المستقلة عن المزود. لا يؤجل ذلك استمرار تحسين سكربت rehearsal والـmapping والـchecksums والخطط والاختبارات الاصطناعية التي لا تحتاج بيانات أو أسرار حقيقية.

- لا اتصال بقاعدة الإنتاج ولا قراءة أسرارها ولا أخذ backup منها في هذه المرحلة.
- لا اختيار target DB أو provider أو retention/RPO/RTO مفترض.
- لا تنفيذ migrations أو restore أو cutover على بيانات حقيقية، ولا تغيير DNS/API endpoint.
- قبل cutover لاحقًا: قرار مصدر/وجهة محددين، backup فعلي مشفر، restore إلى بيئة معزولة، مقارنة counts/IDs/relationships/constraints، معالجة delta/الكتابات المتزامنة، rollback rehearsal وموافقة مالك مباشرة تسمي الهدف والنافذة.

نجاح synthetic preflight وCI لا يغيّر حالة Phase12 إلى COMPLETE ولا يمنح تصريحًا للنقل.

## D-051 — توقيت إعادة فتح القرار بعد نهاية الخطة كاملة

لا يُطلب اختيار أي مزود أو منطقة أو خطة أو تكلفة عند إنهاء هذه المرحلة منفردة. جميع اختيارات المزودين تُؤجل إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل. حتى ذلك الوقت يستمر العمل المحايد الذي لا يحتاج مزودًا، وتبقى معايير القبول التي تستلزم مزودًا حقيقيًا أو staging/device delivery مؤجلة ولا تُسجل COMPLETE. راجع phases/DECISIONS.md (D-050 وD-051).


## 2.3 — full-schema PostgreSQL backup/restore rehearsal — 28 نوفمبر 2026

التنفيذ: أضيف scripts/postgres-full-schema-restore-rehearsal.sh مع خطوة إلى .github/workflows/docker-build.yml. تستهدف الخطوة قاعدة raven23_test المؤقتة فقط بعد تنفيذ migrations واختبارات PostgreSQL، وتثبت من عدد ملفات migrations مقابل سجل Laravel قبل بدء الاستنساخ.

تسلسل الاختبار:
1. رفض التنفيذ ما لم تكن قيم PG host/port/user/password/database وقفل CI disposable مطابقة للقيم الثابتة داخل السكربت.
2. إنشاء clone مصدر من قاعدة CI المكتملة وtarget منفصل فارغ، مع تنفيذ CREATE/DROP عبر قاعدة الإدارة postgres وليس عبر اتصال بقاعدة القالب.
3. إنشاء custom-format pg_dump ثم تشفيره AES-256-CBC مع PBKDF2-HMAC-SHA256 (200,000 iteration)، فك التشفير والتحقق من SHA-256، ثم pg_restore إلى target مستقل.
4. مقارنة migration ledger، جرد public tables، تعريفات الأعمدة والقيود والفهارس والـsequences والـtriggers والـviews، ثم row counts وبصمة SHA-256 لمحتوى كل جدول.
5. نشر JSON aggregate-only يحتوي counts/hashes لا قيم rows؛ تنظيف قواعد المصدر والهدف والملفات المؤقتة.

حدود الإثبات: هذا rehearsal يثبت قابلية dump/restore للمخطط والصفوف الموجودة في PostgreSQL CI disposable فقط. لا يثبت حالة قاعدة إنتاج أو عدد مستخدميها، ولا يثبت تكاملًا مع مزود backup خارجي، ولا يختبر CDC/delta أو الكتابات الحية أو cutover. الاختبار أضيف في commit 46dd00bf9378436465bfec76fed4aaa1d8819ece واجتاز لاحقًا على current main commit 72730281a6c96208979e4c8a1eaa46fbc723dcdd ضمن Docker Validation #671. تقرير artifact سجل 23 migrations/ledger rows و34 public tables وتطابق schema/data manifests؛ artifact #15235408250 (SHA-256: 7cf5bf3ec6b5ce338e9c51e43a92fd7317e38339f41d4435e89b38a12cf91d05).

## 3 — مسار Phase12A / Phase12B / Phase12C

- 12A — Provider-neutral preparation (IN_PROGRESS): source inventory، migration ledger/schema inventory، mapping design، synthetic data fixtures، encrypted CI backup/restore، schema/data fingerprinting، rollback and consistency plan design دون حسابات أو أسرار خارجية.
- 12B — Real source/target migration rehearsal (WAITING_EXTERNAL): لا يبدأ إلا بعد انتهاء كل أعمال Backend المستقلة عن المزود، ومراجعة قرار الخدمات الموحد، واعتماد المالك لمصدر ووجهة محددين، ثم أخذ backup مشفر حقيقي واستعادته إلى بيئة معزولة بموافقة واضحة. يلزم إثبات delta/write-freeze semantics ومقارنة IDs والعلاقات والقيود.
- 12C — Production cutover and hypercare (WAITING_OWNER_APPROVAL): نافذة صريحة، موافقة مالك منفصلة، final backup/delta، validation/smoke tests، routing/cutover، rollback drill وفترة مراقبة متفق عليها. لا تنفذ الآن.

لا تعني حالة IN_PROGRESS في 12A أن بيانات Neon/Render أو Firebase أصبحت متاحة أو أن قرار مزود قاعدة البيانات قد فُتح.


## 4 — توثيق خريطة البيانات والـintegrity

توجد خريطة الجداول والحقول والقيود ومخاطر التشفير وحدود API export في `phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md`.

النقاط التي لا يجوز إغفالها:
- API `GET /api/v1/user/data-export` يعيد format_version 3 لبيانات الحساب والملف والتفضيلات والأجهزة والوسائط الوصفية والمفضلة وسجل المشاهدة والقوائم؛ لا يحتوي كل سجلات DB ولا blob files ولا credentials. ليس database backup بديلًا.
- يلزم حفظ user IDs وUUIDs وidempotency/change receipts/tombstones/revisions والتوقيت UTC milliseconds والـappend-only ledgers.
- media blobs خارج PostgreSQL وتتطلب نقل bytes والتحقق من size/checksum بصورة منفصلة.
- push tokens وnotification payloads لها اعتماد تشفيري على APP_KEY؛ لا تغيير مفتاح/إعادة تشفير دون إجراء معتمد.
- المصدر الفعلي والوجهة الفعلية غير معتمدين؛ لا row counts إنتاجية معروفة.



## 5 — مصادر التطبيق المرشحة والفجوات المؤجلة

أضيف فحص مستودع تطبيق Flutter m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a إلى خريطة البيانات. النتيجة ليست تحققًا من أي Firebase project حي:

- PRD قديم يصف Firebase Auth/Firestore مع collections users وusernames وbookmarks-v2.0 وrecently-watched-v1 وwellness-v1/{uid}/sessions وwellness-v1/{uid}/daily.
- العميل ما زال يحتوي LegacyFirebaseSession كجسر جلسة قديمة، وفي الوقت نفسه LaravelSyncCoordinator ينفذ sync إلى Laravel لبيانات bookmarks/recent/wellness/playlists؛ هذا يثبت انتقالًا في الكود، لا يثبت مصدر الحقيقة الحالي.
- قواعد SQLite المحلية تشمل bookmark/recent/wellness/playlist stores. guest data والعمليات غير المرفوعة قد تبقى على أجهزة المستخدمين، فلا يغطيها Backup PostgreSQL CI.
- توجد فجوة محتملة في Firebase Auth import: PRD يطلب firebase_uid/Firebase Scrypt fields/parameters، بينما مخطط Laravel الحالي لا يعرّف هذه الحقول أو Firebase Scrypt verifier. نسجل blocker إذا اختير Firebase مصدرًا لاحقًا؛ لا نعالجها ولا ننقل الأسرار الآن.
- mapping names differs بين PRD القديم والجداول الحالية؛ تفاصيله في phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md.

تظل 12A IN_PROGRESS حتى اعتماد source-of-truth/domain mapping، وتحليل guest/offline device coverage، ومعالجة تصميم الفجوات المحايدة. 12B/12C تبقيان مؤجلتين كما هي.


## 6 — إغلاق بوابة اختبار full-schema sequence-state في Docker #675

Docker #675 اكتمل SUCCESS على commit 6750eac566c513c4fc97951f1b80cb7f54cd0c0a. يضم تقرير Phase12 schemaVersion 2: 23 migration files/ledger rows، 34 public tables، و21 sequence states متطابقة بين المصدر/الهدف (last_value/is_called). اجتازت مقارنات schema/data/sequence manifests؛ artifact #15236350311.

فشل Docker #674 في نفس الخطوة بسبب استخدام $2 غير الممرر تحت set -u؛ أصلح commit 6750eac مسار الملف وجعل Docker #675 يعيد التمرين بنجاح. كما اكتمل isolated rollback والتوقيع الموثوق. هذا لا يفتح 12B/12C ولا يغير شرط قرار المصدر/الوجهة وموافقة المالك.


## 7 — خريطة الحقول والتحويلات المحلية

توضح الوثيقة phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md الآن مطابقة SQLite المحلية مع Laravel controllers والجداول، بما يشمل:
- movie/tv bookmarks إلى user_bookmarks metadata مع فصل media_type.
- movie/episode progress إلى user_recent_media مع progress fields وUTC millisecond timestamps وtombstones.
- wellness sessions إلى payload/changes/receipts، مع اشتراط mapping موثق من owner_id المحلي إلى الحساب المصادق عليه؛ daily aggregates يعاد بناؤها ويجب مقارنتها.
- playlists/items إلى جداول Laravel، مع فصل pending local operation queue عن receipts/change ledger المؤكدة على الخادم.

**فجوة عقد تحتاج قرارًا:** playlists.db تحتفظ source_provider/source_id/share_code/share_url، بينما PlaylistSyncController وroutes الحالية لا تستقبلها أو تعيدها. لا يجوز إسقاطها بصمت؛ يجب تقرير ما إذا كانت محلية/مؤقتة أم بيانات مستخدم متزامنة.

لا يعالج هذا التوثيق الفجوة ولا يغير API/schema؛ التنفيذ ينتظر قرار النطاق بعد مراجعة عقود Phase06 وPhase12.


## 8 — فجوة عقد Playlist import/sharing المكتشفة في كود العميل

مراجعة Flutter `PlaylistRepository` و`PlaylistRecord` أكدت أن العميل يتوقع:
- `PUT playlists/{id}/sharing`
- `GET shared-lists/{code}`
- `playlist-imports` لإنشاء عملية import، وعرضها، وإلغائها، ثم commit.

لكن routes الحالية في Backend تعرض `POST /sync/playlists` فقط؛ migration `user_playlists` لا تتضمن source/share/visibility columns؛ و`PlaylistSyncController::playlistPayload` لا يعيد حقول source أو share. كما يوثق Client أن `unavailable` تعني أن Backend لا يملك import endpoint بعد.

هذه فجوة عقد تحتاج قرار نطاق مع Phase06؛ لا ننفذ endpoint/schema أو نغير تطبيق Flutter ضمن مرحلة النقل قبل قرار صريح. لا تعتمد على حقول client-side كبيانات موجودة على الخادم، ولا تسقطها من SQLite عند full refresh دون سياسة واضحة.


## 9 — Revisions/cursors واحتفاظ tombstones

سياسات الخادم الحالية تؤثر على تاريخ المزامنة:
- Recently watched: tombstones تُحذف من الخادم بعد 90 يومًا، والعميل ينظف نسخته المؤكدة بعد 90 يومًا ويطلب full pull على الأقل كل 7 أيام. جهاز كان offline أكثر من retention يحتاج reconciliation خاصة؛ قد لا توجد tombstone قديمة في snapshot الجديد.
- Wellness: pagination cursor يتضمن user_id/revisions/snapshot_revision/last_id وموقع HMAC-SHA256 باستخدام APP_KEY. لا تنقل token بين حسابات أو APP_KEY مختلف؛ افتح full snapshot جديدًا.
- Bookmarks/playlists: checkpoints رقمية ومقيدة بمالك الحساب وبسجل revisions/operations؛ لا تنسخ cursor أو local checkpoint إلى وجهة جديدة من دون إعادة تأسيس revision baseline.
- Pending operations المحلية تحتفظ بـUUIDs نفسها وتُعاد idempotently؛ تبقى ملفات الجهاز الأصلية حتى ACK نهائي لكل عملية.

يجب تضمين offline-device cohort والـtombstone age والـlast-successful-sync وqueue depth في preflight measurement عندما يوافق المالك لاحقًا على مصدر بيانات فعلي. لا تتوفر هذه القياسات حاليًا ولا نفترضها.

## 10 — مخازن الجهاز المحلية ودمج guest/offline — 28 نوفمبر 2026

تم تدقيق تطبيق RAVEN23 قراءة فقط على SHA `387feafb4865d991223897088b82eacb7a42061a`. رأس app main الحالي وقت التدقيق `51f12760cc4e06a5651f7e07dbd89f774bafece4` يبعد commit واحدًا غيّر ملفي phases/README فقط؛ ملفات SQLite/sync المرجعية لم تتغير.

### 10.1 طوبولوجيا التخزين المحلية

| الملف | الجداول/المحتوى | العزل |
|---|---|---|
| `movies.db` | `movie_bookmark_table` | owner-suffixed عند تفعيل LibraryScope؛ guest يحتفظ بالمسار التاريخي. |
| `tv.db` | `tv_bookmark_table` | مثل أعلاه. |
| `recent_movies.db` | `recently_watched_movies_table` | progress وupdated/deleted UTC milliseconds وsynced flag. |
| `recent_episodes_v2.db` | `recently_watched_tv_shows_table` | episode ID مستقل عن series ID، مع progress/tombstones. |
| `playlists.db` | `playlists`, `playlist_items`, `playlist_operations`, `playlist_meta` | owner-suffixed عند تفعيل LibraryScope؛ schema v2 يحفظ share_code/share_url، والـplaylist row يحفظ source_provider/source_id. |
| `raven23_wellness_v1.db` | `wellness_sessions`, `wellness_daily_summaries` | ملف مشترك ثابت؛ كل صف معزول عبر owner_id. |

LibraryScope.enabled default is false in the pinned source. عند تفعيله تستخدم حسابات Laravel suffix `.laravel.<URI-encoded-owner>`؛ guest يبقى على المسار التاريخي. Wellness لا يستخدم owner-suffixed filename بل owner_id داخل قاعدة واحدة.

### 10.2 سجل المزامنة خارج SQLite

`KvStore` يضع SharedPreferences في namespace `flixquest.laravel.v1.`، وهو مستقل عن SQLite. المفاتيح ذات أثر ترحيل تشمل:
- `sync.<owner>.bookmarks.known` و`sync.<owner>.bookmarks.deleted`: remote-known IDs وpending deletion journal.
- `sync.<owner>.bookmarks.last_synced` و`sync.<owner>.playlists.last_synced`: آخر مزامنة.
- `sync.<owner>.recent.cursor` و`sync.<owner>.wellness.cursor`: revision/time/full-pull metadata؛ cursor Wellness opaque وموقّع.
- `playlists.db/playlist_meta.checkpoint`: revision محلية مؤكدة وليست checkpoint صالحة للنقل إلى هدف آخر.
- `sync.guest.claimed_by` و`sync.guest.copied`: owner المستلم وsnapshots التي تمنع حذف نسخة ضيف تغيرت بعد النسخ.

لا تنقل revision أو cursor أو checkpoint كما هي إلى هدف جديد؛ أعد full snapshot/cursor بعد تعيين الهوية والمالك.

### 10.3 آلية guest merge المرصودة

- `LocalLibrary.copyInto` ينسخ كل صفوف bookmark/recent، ويستخدم conflict-ignore للbookmark، ويقارن updated_at_utc للrecent ثم يجعل النسخ المنقولة synced=0.
- `clearCopied` يحذف صفوف guest فقط إذا بقيت مطابقة حرفيًا للsnapshot؛ أي تعديل لاحق يحفظ النسخة الجديدة.
- `WellnessDatabaseController.moveOwner` يعيد ربط owner_id من guest إلى user:<id>، ويجعل الصفوف pending ثم يعيد بناء daily summaries من sessions.
- `PlaylistStore.moveInto` ينقل pending operations بحسب operation_id ثم يمسح guest store؛ التفاصيل والفجوة موضحتان أدناه.
- إذا فشل sync بسبب offline، تبقى guest claim ونسخ البيانات حتى retry ناجح؛ يوجد Flutter integration test يغطي bookmarks/recent/wellness في هذه الحالة.

### 10.4 مخاطر الحفاظ على بيانات الضيف التي تمنع قبول نقل الأجهزة

**RISK-12A-PLAYLIST-GUEST-CONFIRMED-STATE:**

`PlaylistStore.moveInto` يقرأ pending operations. إذا كانت القائمة فارغة يرجع دون نسخ أي شيء. وإذا وُجدت عمليات معلقة، ينسخ operation_id/body إلى account queue ثم ينفذ `clear()` على guest store، وهو ما يحذف playlists وplaylist_items وplaylist_operations وplaylist_meta. لا ينسخ confirmed lists/items أو source_provider/source_id/share_code/share_url. اختبار Flutter الحالي يثبت queue-only merge، ولا يوجد اختبار يثبت الحفاظ على confirmed guest rows/items والحقول الوصفية.

هذا يثبت فجوة في منطق النقل/تغطية الاختبار، **ولا يثبت أن بيانات مستخدم فعلية فُقدت**؛ يلزم إثبات أن confirmed guest state غير ممكن في كل cohort أو إضافة اختبار نقل وحفظ صريح في مستودع RAVEN23 قبل قبول الجهاز.

**RISK-12A-BOOKMARK-GUEST-JOURNAL:**

bookmark known IDs وpending deletions موجودة في SharedPreferences منفصلة عن SQLite. مسار mergeGuest لا يُظهر نقلًا صريحًا للمفتاحين من guest إلى user scope. قد لا يؤثر ذلك على guest محلي لم يتصل بمصدر بعيد، لكن لا يمكن استبعاده قبل تحديد legacy/Firebase source.

**RISK-12A-WELLNESS-OWNER-MAPPING:**

Wellness يعيش في ملف مشترك ويعتمد ownership على owner_id؛ يجب أن يكون mapping guest إلى user:<id> موثوقًا. daily summaries مشتقة من sessions ويجب إعادة بنائها ومقارنتها، لا التعامل معها كمصدر مستقل.

### 10.5 متطلبات device preflight aggregate-only

عند السماح مستقبلًا بجمع دليل من جهاز، يكون التقرير مجمعًا بلا PII أو raw rows:
- app/source build SHA، schema versions، والملفات المتوفرة.
- counts لكل table: total، synced=0 pending، synced=1 confirmed، tombstones وأعمارها في buckets 0–7 / 8–30 / 31–90 / أكثر من 90 يومًا.
- playlist counts: confirmed lists/items، pending operation count، min/max queue sequence، counts لحقول source/share، وcheckpoint-presence فقط.
- wellness counts حسب owner scope، sessions pending/deleted، daily summary count ونتيجة rebuild validation.
- bookmark known/deletion journal counts، cursor-present flags، last successful/full sync times، وآخر server revision كدليل لا كقيمة تُنقل.
- تبقى operation UUIDs وraw owner IDs داخل نسخة مشفرة مصرح بها لاحقًا ولا تدخل التقرير العام.

لا نجمع telemetry أو نقرأ أي ملفات أجهزة الآن.

### 10.6 أثر النتائج على Phase12

- اكتمل source-code inventory لطوبولوجيا مخازن الأجهزة؛ لا توجد device-level row counts أو cohort evidence.
- Phase12A تبقى IN_PROGRESS بسبب source-of-truth المجهول، وguest confirmed-playlist state وbookmark journal coverage المفتوحين.
- Phase12B/12C تبقيان WAITING_EXTERNAL/OWNER_APPROVAL. لا مصدر/وجهة حقيقيين، ولا backup/restore حقيقي، ولا cutover أو provider selection.
- التقرير التفصيلي: `phases/reports/PHASE-12A-DEVICE-LOCAL-STORES-AND-GUEST-MERGE-REVIEW-2026-11-28.md`.