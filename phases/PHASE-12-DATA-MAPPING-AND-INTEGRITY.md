# Phase12A — خريطة ترحيل البيانات وعقد السلامة والتكامل

**التاريخ:** 28 نوفمبر 2026  
**الحالة:** SOURCE-CODE MAPPING DOCUMENTED؛ عقد المشاركة/الاستيراد وبعض source metadata gaps مفتوحة؛ source/target الفعليان وبيانات الإنتاج غير متاحين وغير معتمدين.  
**النطاق:** إعداد محايد لمزود الخدمة فقط؛ لا اتصال بقاعدة الإنتاج ولا اختيار وجهة أو مزود أو تنفيذ cutover.

## 1. الغرض وحدود الإثبات

تحدد هذه الوثيقة نطاق البيانات الذي يجب أخذه في الحسبان عند ترحيل RAVEN23 Backend، وتربط مجالات تطبيق المستخدم بمخطط Laravel الحالي، وتثبت invariants التي يجب عدم كسرها.

المصدر المثبت حاليًا هو **كود Git وملفات migrations وModels وAPI contracts**. جرد المصدر الحالي يقول إن هناك 23 migration. واختبار PostgreSQL في CI على commit `72730281a6c96208979e4c8a1eaa46fbc723dcdd` أنشأ 34 public table في قاعدة مؤقتة، واستعاد النسخة بنجاح وقارن schema/data fingerprints. ثم أضاف commit `6750eac566c513c4fc97951f1b80cb7f54cd0c0a` فحص حالة 21 Sequence (last_value وis_called)، واكتمل Docker #675 كله SUCCESS بما في ذلك isolated rollback والتوقيع الموثوق. هذه أعداد CI فقط وليست أعداد قاعدة إنتاج أو مستخدمين فعليين.

**المصدر الفعلي للبيانات: UNKNOWN_PENDING_SOURCE_APPROVAL.** تظهر في المستندات السابقة إشارات إلى PostgreSQL/Neon وإلى Firebase/Firestore وإلى قواعد محلية في التطبيق؛ لكنها لا تثبت أي مصدر حي هو المرجع النهائي، ولا يجوز دمج مصادر أو افتراض أن كل نسخة متزامنة.  
**الوجهة الفعلية: UNKNOWN_PENDING_PROVIDER_DECISION.** لا تختار هذه الوثيقة PostgreSQL host أو مزودًا أو منطقة أو تكلفة.

## 2. خريطة المجالات والجداول المستهدفة

| المجال | جداول Laravel الحالية المعروفة من migrations | قواعد الحفاظ المطلوبة |
|---|---|---|
| هوية الحساب | `users` | الإبقاء على معرف الحساب الأصلي وعلاقاته؛ لا دمج حسابين تلقائيًا بسبب تشابه البريد أو الاسم. |
| جلسات ومصادقة | `personal_access_tokens`, `password_reset_tokens`, `email_verification_tokens`, `sessions` | لا تصدير bearer tokens أو أسرار الاستعادة. قرار حفظ/إبطال الجلسات والـtokens يحدد لاحقًا؛ عمود token في Sanctum مخزن كـhash وليس bearer token قابلًا للاسترجاع. |
| أساس الحساب | `user_profiles`, `user_preferences`, `user_devices` | صف الملف والتفضيلات مرتبطان بمالك واحد؛ device key فريد لكل (`user_id`, `device_id`). |
| المفضلة | `user_bookmarks`, `user_bookmark_operations`, `user_bookmark_changes` | preserve key (`user_id`, `media_type`, `media_id`) وعمليات idempotency وchange revisions/tombstones. |
| سجل المشاهدة | `user_recent_media`, `user_sync_revisions` | preserve movie/episode identity، elapsed/remaining، revision، tombstones، وUTC epoch milliseconds دون تقريب أو تحويل وحدات. |
| القوائم | `user_playlists`, `user_playlist_items`, `user_playlist_operations`, `user_playlist_changes` | preserve playlist UUID، ترتيب العناصر position، version، composite item identity، operation idempotency، وchange history. |
| مزامنة التفضيلات | `user_preference_operations` بالإضافة إلى `user_preferences` | preserve revision وoperation receipts/payload hashes؛ لا تعاد تهيئة revision إلى صفر أثناء النقل. |
| بيانات wellness | `user_wellness_sessions`, `user_wellness_daily`, `user_wellness_changes`, `user_wellness_upload_receipts` | preserve session UUID، local day، revision، upload stamp، payload hash، deleted flags، وسجل التغييرات. |
| الملفات الخاصة | `user_media` metadata + private object blobs في private filesystem abstraction | metadata لا تمثل الملف نفسه؛ تنقل bytes منفصلًا وتتحقق من `size_bytes` و`checksum_sha256` ومطابقة `object_key`. |
| Outbox الإشعارات | `notification_outbox` | preserve UUID/idempotency/status/attempts/schedule/claim fields؛ لا تفقد الرسائل pending ولا تعاود إرسالها مرتين. `encrypted_payload` يتطلب خطة توافق مفاتيح التشفير. |
| تسجيل Push | `user_push_subscriptions` | `token` encrypted cast و`token_hash` HMAC مشتق من APP_KEY؛ لا تسجل قيم token في logs أو التقارير. يتطلب تغيير المفتاح قرار إعادة تشفير/إعادة تسجيل واضحًا. |
| الاستحقاقات والاشتراكات | `user_entitlements`, `subscription_events` | `user_entitlements` projection وليست المصدر التاريخي الوحيد؛ `subscription_events` append-only ledger. preserve idempotency keys وevent hashes وaggregate revisions وحالة الإسقاط. |
| المحفظة | `user_wallets`, `wallet_transactions` | preserve wallet UUIDs وcurrency وinteger cents وfrozen/status/revision والـappend-only ledger. يجب مطابقة مجموع المعاملات والرصيد المسجل؛ لا تستخدم floating point. |
| Laravel التشغيلية | `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | migrations ledger جزء من schema history؛ cache قد يكون قابلًا لإعادة البناء بعد قرار، أما jobs فيلزم drain/replay أو نقل منضبط كي لا تضيع رسائل queued. لا تعامل هذه الجداول كبيانات مكتبة المستخدم. |

هذه خريطة المجالات الأساسية المعروفة من source. لا تعتبر القائمة إثباتًا بأن المصدر الفعلي يحتوي كل جدول أو أي عدد من الصفوف؛ ذلك يتطلب manifest من مصدر معتمد لاحقًا.

## 3. واجهة تصدير الحساب ليست نسخة ترحيل كاملة

المسار `GET /api/v1/user/data-export` في `AuthController::exportUserData()` يعيد `format_version = 3` ويضم:

- account metadata: id/name/email/username/provider/guest and verification flags/timestamps؛
- profile وpreferences؛
- device metadata؛
- user media metadata مع download URL محمي ورابط temporary signed قصير العمر؛
- bookmarks؛
- recently watched movies/episodes مع elapsed/remaining/UTC/tombstone/revision fields؛
- playlists وعناصرها.

الكود يوضح أنه لا يضم credentials أو token hashes أو bearer tokens أو recovery/verification secrets. لكنه لا يشمل كل ما تحتاجه database migration: wellness data/receipts، preference/bookmark/playlist operation and change ledgers كاملة، notification outbox، push tokens، entitlements/subscription event ledger، wallet/transactions، framework queue/session/cache، ولا bytes للملفات الخاصة. روابط التنزيل الموقعة مؤقتة وليست وسيلة نقل دائمة للـobject blobs.

**النتيجة:** لا يجوز استخدام API export v3 وحده بدل database backup/restore كامل. هو عقد portability على مستوى حساب، لا dump تشغيلي شامل.

## 4. قواعد الهوية والعلاقات والتسلسل الزمني

1. احتفظ بمفاتيح الحسابات الأساسية وUUIDs كما هي ما لم يعتمد المالك mapping محددًا. لا تولد IDs جديدة بصمت ولا تنفذ auto-merge حسب email.
2. حافظ على foreign keys، cascades، unique constraints، composite primary keys، وفهارس القراءة/المزامنة. يجب أن يكون عدد orphan references صفرًا بعد الاستعادة.
3. حافظ على جميع operation IDs/idempotency keys والـreceipts والـchange logs؛ حذفها قد يجعل retries تنفذ آثارًا مكررة.
4. حافظ على tombstones/deleted markers والـrevisions. عدم نقل tombstones قد يعيد العناصر التي حذفها المستخدم إلى أجهزته.
5. قيم `updated_at_utc` و`deleted_at_utc` وحقول الوقت بوحدة milliseconds لا تحول إلى seconds أو local time؛ حافظ على precision وtimezone semantics.
6. حافظ على JSON values وأنواعها ومفاتيحها كما هي؛ لا تسقط المفاتيح غير المعروفة ولا تعِد ترتيب/تطبيع payloads بطريقة تغير hash أو conflict resolution.
7. قيّم حالة sequence current/next values بعد restore قبل اعتماد ترحيل حقيقي؛ تمرين Phase12 الحالي يقارن تعريفات sequence metadata ضمن schema manifest ولا يعلن وحده قبول high-water state لكل sequence.
8. `subscription_events` و`wallet_transactions` سجلات append-only. لا تعد كتابتها من projection الحالي ولا تستخدم down-migrations مدمرة كخطة rollback.
9. Sanctum polymorphic owner references ليست FK تقليدية على `users`. سياسة الاحتفاظ/الإبطال للـtokens والجلسات قرار مستقل، ويجب عدم نقل bearer secrets أو طلب استخراجها.
10. الملفات الخاصة خارج PostgreSQL؛ يجب أن يطابق كل object metadata key/size/checksum قبل اعتماد النقل.

## 5. مفاتيح التشفير والأسرار

- Push subscription token يستخدم Eloquent encrypted cast، وlookup hash يستخدم HMAC-SHA256 بمفتاح APP_KEY.
- Notification outbox يخزن payload في `encrypted_payload`; queue payloads محمية أيضًا ضمن إعداد Laravel الحالي.
- هذه القيم لا تُفك أو يعاد تشفيرها أو تنقل باستخدام مفاتيح مستنتجة. يجب في مرحلة لاحقة تحديد key continuity أو re-encryption/re-registration procedure، وصلاحيات الوصول، والتدوير، والاختبار.
- لا تكتب APP_KEY أو DB URLs أو tokens أو payloads أو emails أو row contents في التقارير أو artifacts أو job logs.
- ملف artifact الناجح من CI يحتوي أعدادًا وبصمات فقط، لا قيم صفوف.

## 6. منهج التحقق المطلوب قبل أي ترحيل حقيقي

لكل مصدر ووجهة سيعتمدان لاحقًا:

1. **Source inventory:** تسجيل system/schema/version/owner ووقت snapshot وحالة الكتابات؛ counts لكل جدول، PK ranges، nullability/unique/foreign-key/index inventory، وأحجام blobs. لا تستخدم قيمة تقريبية بدل قياس.
2. **Mapping:** جدول source→target لكل جدول وعمود، أنواع البيانات، تحويلات محددة، defaults المقبولة، null handling، owner ID mapping، timestamp units، encryption dependencies، وقرار أي field غير قابل للنقل.
3. **Backup:** snapshot متسق ومشفّر من المصدر المعتمد مع checksum ونسخة منفصلة ومفاتيح استرجاع محكومة؛ لا تعتبر نجاح CI بديلًا عنه.
4. **Restore rehearsal:** الاستعادة إلى target معزول، ثم مقارنة counts وprimary-key set digests وrelationships/foreign keys/unique conflicts وledger totals وsequence high-water values وblob checksums.
5. **Concurrency:** اختيار write freeze أو CDC/delta capture أو dual-write بعد فحص المصدر والوجهة فقط. dump واحد لا يثبت التقاط الكتابات التي تحدث بعد snapshot.
6. **Application acceptance:** اختبار login/session policy/profile/bookmarks/recent/playlist/wellness/media downloads/entitlements/wallet/outbox behavior، مع ownership isolation وidempotent replay.
7. **Rollback:** تعريف نقطة رجوع، حفظ آخر source snapshot، تحديد من يقرر stop/go، وقياس زمن rollback. لا يجرّب rollback على الإنتاج دون موافقة مستقلة.
8. **Evidence:** artifact لا يحتوي إلا counts/hashes/status ونسخة schema/version؛ يمنع رفع raw SQL dump أو user rows أو secrets إلى GitHub artifact.

## 7. ما ثبت في CI حتى الآن وما لم يثبت

على commit `72730281a6c96208979e4c8a1eaa46fbc723dcdd`:

- Docker Validation #671: SUCCESS، بما في ذلك full-schema PostgreSQL dump/encrypt/decrypt/restore، smoke tests، rollback rehearsal، provenance/SBOM، signed release manifest.
- Authentication Regression #293: SUCCESS.
- Security Scans #417: SUCCESS.
- Pint #415: SUCCESS.
- IaC Validation #31 على baseline `dbd281c545f5a2da5a6816afccebd4d929a02b70`: SUCCESS، 30/30 guardrails؛ لم تتغير ملفات IaC منذ ذلك التحقق.

دليل full-schema artifact:
- Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759
- Artifact: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759/artifacts/15235408250
- Digest: `sha256:7cf5bf3ec6b5ce338e9c51e43a92fd7317e38339f41d4435e89b38a12cf91d05` (see GitHub artifact metadata for the exact archive digest below in the status record).

**محتوى التقرير المقاس:** 23 migration files = 23 migration ledger rows؛ 34 public tables؛ schema manifest SHA-256 `cfeed3162273eefebe90da3fdd3580bbecdc9bf9956ffeb69b51e9210c36c3c0`؛ table-data manifest SHA-256 `274777e346f2ff12d80e206ac4458690f49b19075c41e0caa3c3e688384b0179`؛ جميع مقارنات الجدول/المخطط/الأعمدة/القيود/الفهارس/sequence definitions/triggers/views/row-counts/fingerprints = matched.

حدود مهمة: الأعداد 23/34 تخص قاعدة GitHub Actions المؤقتة. `productionDatabaseUsed=false`, `productionSecretsUsed=false`, `productionDataRead=false`, `cutoverPerformed=false`, `temporarySourceAndTargetDropped=true`, `rowValuesIncludedInArtifact=false`. لم يتم اختبار source حقيقي أو target حقيقي أو ملفات مخزنة خارج PostgreSQL أو delta/CDC أو write freeze أو cutover.

## 8. حالة الاعتماديات والبوابات

- 12A provider-neutral: IN_PROGRESS؛ source-level mapping والجرد وCI full-schema rehearsal متاحة.
- 12B real source/target migration rehearsal: WAITING_EXTERNAL حتى اكتمال تطوير Backend المستقل عن المزود ومراجعة D-050/D-051 واعتماد مالك صريح لمصدر ووجهة وموافقة أخذ/استعادة backup حقيقي.
- 12C production cutover/hypercare: WAITING_OWNER_APPROVAL؛ تتطلب نافذة وقرار go/no-go وخطة delta/rollback وموافقة مستقلة.
- لا تغير هذه الوثيقة D-050/D-051 ولا تختار خدمة ولا تمنح إذنًا للوصول إلى بيانات الإنتاج.

## 9. معايير قبول 12A

- خريطة المصدر المستهدف والمجهول موثقة دون افتراضات.
- API export v3 مفصول بوضوح عن database full backup.
- الجداول الحساسة والقيود والمفاتيح والتسلسل الزمني ومخاطر blobs/encryption موثقة.
- تمرين CI الكامل مشفر ومستعاد مع مقارنة schema/data fingerprints ونجاحه على رأس main موثق.
- لا raw production rows/secrets ولا اختيار provider/source/target.


## 10. مصادر البيانات المرشحة التي ظهرت في مستودع تطبيق Flutter

**مرجع المصدر:** مستودع m5ham3ds/RAVEN23 على commit 387feafb4865d991223897088b82eacb7a42061a (قراءة فقط، لم تعدل هذه المرحلة مستودع التطبيق). هذه أدلة كود/وثائق تاريخية داخل Git، وليست تحققًا من Firebase project أو قاعدة تشغيل حية.

### 10.1 Firebase/Firestore — بنية موثقة قديمة وليست مصدرًا حيًا مثبتًا

الملف docs/migration_prd_firebase_to_laravel.md يصف البنية السابقة التالية:
- Firebase Authentication للهويات: Email/Password وGoogle وAnonymous.
- Firestore collections/paths المعروفة في الوصف: users، usernames، bookmarks-v2.0، recently-watched-v1، wellness-v1/{uid}/sessions، wellness-v1/{uid}/daily.
- Remote Config لإعدادات العميل والثيمات والإعلانات، وFCM لتسجيل واستقبال push.
- وثيقة docs/wellness_firestore_rules.md تعرض rules مقترحة لملكية مسار wellness، لكنها تنص صراحةً على أن إعداد Firebase environment-level غير موجود في المستودع وأن المقتطف ليس ruleset مستقلًا قابلًا للنشر.

**التفسير:** هذه وثائق معمارية سابقة/legacy candidate. لا تثبت أن Firestore ما زال يملك كل البيانات أو أن تلك collections كاملة أو متزامنة أو أن حساب Firebase نفسه متاح. لا يمكن تعيينها كمصدر ترحيل نهائي دون موافقة وصول صريحة وجرد من المشروع الفعلي.

### 10.2 فجوة ترحيل Firebase Authentication

الـPRD القديم يقترح استيراد firebase_uid وكلمات المرور ذات hash_algorithm=firebase_scrypt مع parameters مثل signer_key وsalt_separator وrounds وmem_cost، ثم lazy re-hash إلى bcrypt بعد تحقق الدخول.

لكن مخطط Backend الحالي ضمن migrations الـ23 وApp\Support\Raven23AuthSchema لا يعرّف firebase_uid أو hash_algorithm أو password_salt، ولم يظهر تنفيذ FirebaseScryptHasher في بحث المصدر الحالي. الحقول الحالية تشمل username وprofile_id وphoto_url وprovider وgoogle_id وحقول التحقق والضيف. لذلك نسجل:

**BLOCKER_FOR_FIREBASE_AUTH_IMPORT_IF_FIREBASE_IS_SOURCE** — لا يمكن اعتبار استيراد Firebase Auth أو الحفاظ على كلمات مرور Scrypt مدعومًا من المخطط الحالي. إذا ثبت لاحقًا أن Firebase هو المصدر المعتمد، فيجب تصميم واعتماد migration/auth compatibility منفصل قبل نقل الهويات؛ لا نضيف حقولًا أو أسرار Scrypt الآن، ولا نفترض ضرورة reset أو lazy re-hash قبل التقييم.

### 10.3 SQLite المحلية على أجهزة المستخدمين

مستودع التطبيق يعرّف قواعد SQLite محلية مستقلة، وهي مصدر محتمل لبيانات guest أو edits لم تُرفع بعد:
- movies.db — جدول movie_bookmark_table.
- tv.db — جدول tv_bookmark_table.
- recent_movies.db — جدول recently_watched_movies_table.
- recent_episodes_v2.db — جدول recently_watched_tv_shows_table.
- raven23_wellness_v1.db — wellness_sessions وwellness_daily_summaries.
- playlists.db — playlists وplaylist_items وplaylist_operations وplaylist_meta.

يضيف LibraryScope.filename suffix .laravel.<owner> على قواعد account-scoped عندما يكون owner isolation مفعّلًا؛ guest يحتفظ بمساراته التاريخية. وLocalLibrary يفتح bookmark/recent databases الأربع، بينما LaravelSyncCoordinator ينسق bookmark/recent/wellness/playlist sync مع Laravel ويملك مسار mergeGuest: ينسخ بيانات guest إلى account، يحاول المزامنة، ثم يمسح فقط النسخ التي لم تتغير بعد نجاح المزامنة. توجد أيضًا LegacyFirebaseSession كجسر لاستعادة جلسة Firebase قديمة.

**النتيجة:** بيانات SQLite محلية لكل جهاز؛ لا يمكن جرد أعدادها من Backend أو GitHub، ولا يجوز افتراض أنها وصلت إلى Laravel. يلزم عند أي نقل حقيقي تحديد سياسة الأجهزة غير المتصلة والـguest data والعمليات المحلية المعلقة، وإبقاء النسخ الأصلية حتى إثبات نجاح المزامنة.

### 10.4 فروق mapping بين وثائق Firebase القديمة وLaravel الحالي

- PRD القديم يصف recently_watched_movies وrecently_watched_episodes؛ Laravel الحالي يستخدم user_recent_media مع media_type وmedia_id وmetadata وelapsed/remaining/timestamps/revision.
- PRD القديم يصف wellness_sessions وwellness_daily_summaries؛ Laravel الحالي يستخدم user_wellness_sessions وuser_wellness_daily، ومعهما changes وupload receipts لحفظ المزامنة/idempotency.
- PRD القديم يصف bookmarks كحقول مباشرة؛ Backend الحالي يخزن user_bookmarks.metadata JSON مع unique key على owner/media type/media id، ويحتفظ بعمليات وتغييرات المزامنة.
- usernames القديمة أصبحت unique key على users.username في Laravel.

هذه فروق schema تحتاج mapping/transform tests لنسخة source dump فعلية لاحقًا؛ أسماء المجالات المتقاربة لا تعني تطابق البيانات أو الوحدات أو قواعد حذف التعارضات.

### 10.5 قرار المصدر المؤجل

تظل مصادر البيانات المحتملة متعددة وغير محسومة:
1. Firebase Auth/Firestore (legacy documented candidate).
2. Laravel PostgreSQL current application state (target/current backend candidate، لكن لا يوجد وصول إنتاجي في هذا العمل).
3. SQLite per-device stores (local/guest/pending changes).
4. Exports أو backups قديمة إن وجدت لدى المالك (لم يتم الوصول إليها أو التحقق منها).

لا تُدمج هذه المصادر تلقائيًا ولا تستخدم أحدث timestamp وحده لاختيار السجل الصحيح. يلزم في وقت القرار اعتماد source-of-truth لكل domain، وقياس conflicts/coverage، وتحديد precedence وowner ID mapping. حتى ذلك الحين: UNKNOWN_PENDING_SOURCE_APPROVAL.

## 11. تحديث دليل CI — sequence-state coverage

على commit 6750eac566c513c4fc97951f1b80cb7f54cd0c0a، تقرير artifact 15236350311 من run #675 يحتوي:
- schemaVersion=2، result=passed.
- 23 migration files = 23 ledger rows، و34 public tables.
- 21 public sequences فُحصت؛ sequenceCurrentValuesAndIsCalledMatched=true.
- schema manifest SHA-256: cfeed3162273eefebe90da3fdd3580bbecdc9bf9956ffeb69b51e9210c36c3c0.
- table-data manifest SHA-256: 274777e346f2ff12d80e206ac4458690f49b19075c41e0caa3c3e688384b0179.
- sequence-state manifest SHA-256: c1c26ace11d8e7b1afeade4b82317767eec87d651062570a96f76cda5c2de0dd.
- encrypted custom archive round-trip checksum matched؛ target مستقل؛ لا production DB/secrets/data ولا row values؛ dropped temporary source/target DBs.

روابط الإثبات:
- Docker #675: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654
- Artifact #15236350311: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654/artifacts/15236350311
- Artifact digest: sha256:907d099c910c463919fea0842a7b43f22723a356c9f756d304ec09ded778a0b6

**حالة البوابة عند توثيق هذه الإضافة:** خطوة full-schema restore ورفع تقريرها = SUCCESS؛ Docker job ما زال يعمل على isolated rollback إلى آخر صورة معروفة، فلا تعتبر Docker Validation #675 كلها مكتملة قبل إنهاء job وtrusted-main signing. على SHA نفسه كانت Pint #419 وAuthentication #297 وSecurity #421 SUCCESS؛ لم تتغير IaC منذ IaC #31 على dbd281c.


## 12. سجل إصلاح الفشل والقبول الكامل لـDocker #675

كان Docker #674 على SHA 2d57458141b554f94d2b9c3cc8d8e60c1122fb7a يفشل في خطوة full-schema restore بسبب shell nounset: line 198: 2: parameter not set. السبب أن دالة sequence-state manifest استخدمت الوسيط $2 رغم أن caller مرر اسم قاعدة البيانات فقط.

أُصلح ذلك في commit 6750eac566c513c4fc97951f1b80cb7f54cd0c0a باستخدام اسم ملف مشتق من قيمة database. إعادة التشغيل في Docker #675 اجتازت خطوة full-schema ورفعت artifact، ثم اجتاز job كاملًا SUCCESS بما في ذلك rollback للصورة السابقة وتوقيع أدلة الإصدار.

روابط التشغيل:
- Docker #674 failed (fixed): https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078538941
- Docker #675 success: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654
- Artifact #15236350311: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654/artifacts/15236350311

هذا الاختبار يثبت rehearsal على قاعدة CI مؤقتة فقط؛ لا يثبت production source/target أو blob transfer أو delta/CDC أو cutover.


## 13. مطابقة الحقول المحلية مع عقود Laravel الحالية

هذه المطابقة مستخرجة من migrations/Controllers في Backend وSQLite stores/SyncCoordinator في تطبيق Flutter؛ وهي mapping contract على مستوى الكود، وليست data inventory لنسخة مستخدم حقيقية.

| مصدر SQLite المحلي | عقد Laravel المستهدف | التحويل/الهوية | ما يجب حفظه أو التحقق منه |
|---|---|---|---|
| movies.db / movie_bookmark_table | user_bookmarks | id → media_id؛ media_type ثابت movie؛ بقية أعمدة TMDB (title/original_title/language/overview/release_date/popularity/poster/backdrop/rating/count/genres/date_added) داخل metadata JSON | unique key (authenticated user, movie, media_id)، عدم استبدال date_added عند تحديث صف قائم، genre_ids تحفظ كأرقام لا string مشفر مزدوجًا |
| tv.db / tv_bookmark_table | user_bookmarks | id → media_id؛ media_type ثابت tv؛ بقية أعمدة العرض داخل metadata JSON | لا تخلط movie وtv حتى لو تساوى رقم TMDB؛ حفظ حذف العنصر كـoperation/tombstone عند المزامنة |
| recent_movies.db / recently_watched_movies_table | user_recent_media + recently-watched movies API contract | id → media_id؛ elapsed/remaining تبقيان حقلي progress؛ title/poster/backdrop/release_year داخل metadata؛ updated_at_utc/deleted_at_utc بوحدة epoch milliseconds | synced مجرد local transport flag لا ينقل كبيانات للمستخدم؛ يجب أخذ كل الصفوف بما فيها tombstones والصفوف synced=0 وعدم إسقاط الحذف |
| recent_episodes_v2.db / recently_watched_tv_shows_table | user_recent_media + recently-watched episodes API contract | episode id → media_id؛ series_id/season/episode/title/artwork/is_anime ضمن metadata؛ elapsed/remaining وUTC stamps في حقولها | episode identity لا تُختزل إلى series_id؛ احتفظ ببيانات الموسم والحلقة والتقدم والتومبستون |
| raven23_wellness_v1.db / wellness_sessions | user_wellness_sessions + user_wellness_changes + upload receipts | id UUID → session_id؛ بقية الجلسة payload JSON؛ owner_id المحلي يربط إلى مالك الحساب المصادق عليه، لا إلى user_id بتحويل نصي مباشر | preserve device_id/source/content_id/series_id/title/subtitle/season/episode/timestamps/timezone offset/watched_ms/duration_ms/progress/segments/genres/languages/countries/network_bytes/revisions/deletion |
| raven23_wellness_v1.db / wellness_daily_summaries | user_wellness_daily | local_day + aggregate fields إلى daily payload/row | هذه summary مشتقة من sessions؛ قارنها بنتيجة rebuild على الخادم ولا تفضّل aggregate محليًا إذا تعارض مع الجلسات؛ احفظ timezone offset وlocal_day |
| playlists.db / playlists | user_playlists | UUID id وname/description/version/created_at | لا تستبدل UUID؛ version جزء من optimistic concurrency |
| playlists.db / playlist_items | user_playlist_items + playlist change stream | playlist_id/media_type/media_id/position + title/artwork/rating/release_date/genre_ids/added_at | composite identity (playlist_id, media_type, media_id)، حفظ position وgenre_ids وadded_at |
| playlists.db / playlist_operations | user_playlist_operations (server receipts) عبر replay contract، وليس نسخًا مباشرًا | operation_id + body المحلي pending؛ الخادم يخزن payload_hash/result بعد معالجة العملية | أبقِ pending queue حتى ACK أو رفض ثابت؛ لا تخلط pending requests مع server operation receipts |
| playlists.db / playlist_meta | user_sync_revisions / client checkpoint policy | checkpoint يمثل revision مؤكدة من آخر مزامنة | لا تنقل checkpoint قديمًا كـserver revision عند تغيير source/target؛ يبدأ snapshot/cursor جديد وفق العقد المعتمد |

### 13.1 عقد recently-watched المؤكد

Controller الحالي يقبل collections منفصلة movies وepisodes. لكل سجل يحفظ elapsed/remaining، updated_at_utc، deleted_at_utc وmetadata؛ وهوية سجل الخادم هي owner + media_type + media_id. التواريخ UTC milliseconds؛ لا تحولها إلى seconds أو local timestamps. يجب أخذ الصفوف المحذوفة/المعلقة، لا الاكتفاء بقوائم العرض التي تخفي tombstones.

### 13.2 عقد wellness المؤكد

Controller الحالي يستخدم authenticated user ID من جلسة API بدل قبول owner_id يرسله العميل. يحفظ session payload ومراجعات التغيير وupload receipts، ويعيد بناء daily aggregates من مساهمات الجلسات. لذلك فإن owner_id المحلي string namespaced ليس مفتاحًا علائقيًا قابلًا للنسخ؛ يلزم mapping عبر حساب موثوق، مع منع نقل جلسة guest إلى حساب آخر دون قواعد دمج مصرح بها.

### 13.3 فجوة playlist import/sharing بين عميل Flutter وBackend

الفجوة أوسع من أربعة أعمدة محلية فقط؛ هناك نموذج Client-side لمشاركة واستيراد القوائم، لكن Backend الحالي لا يقدم العقد المقابل.

**ما يملكه العميل محليًا:**
- `PlaylistRecord` يحفظ `sourceProvider` و`sourceId` و`shareCode` و`shareUrl`، ويقرأ من JSON حقول `source.provider` و`source.id` و`share_code` و`visibility=public/share_url`.
- `playlists.db` يحتفظ بهذه القيم في `source_provider` و`source_id` و`share_code` و`share_url`؛ إضافة `share_code/share_url` تمت في SQLite schema v2، مع تصفير checkpoint حتى يعيد العميل طلب snapshot كامل.
- `PlaylistRepository` يتضمن استدعاءات متوقعة: `PUT playlists/{id}/sharing`، و`GET shared-lists/{code}`، وعمليات `playlist-imports` (POST/GET/DELETE/commit).

**ما يقدمه Backend الحالي في Git:**
- migration `2026_11_23_000010_create_user_playlist_sync_tables.php` ينشئ `user_playlists` بأعمدة `id,user_id,name,description,version,created_at,updated_at\) فقط. لا توجد أعمدة `source_provider/source_id/share_code/share_url` أو visibility.
- `routes/api.php` يعرّف لمسار القوائم `POST /sync/playlists` فقط؛ لا توجد routes لـ`playlists/{id}/sharing` أو `shared-lists/{code}` أو `playlist-imports`.
- `PlaylistSyncController::playlistPayload()` يرجع `id,name,description,version,created_at\) فقط، ولا يرجع `source` أو `share_code` أو `visibility` أو `share_url`.
- كلاس العميل نفسه يوثق أن `unavailable` تعني أن Backend لا يحتوي import endpoint بعد؛ ويحوّل HTTP 404/405 إلى هذه الحالة. هذا دليل من الكود على أن هذه العمليات مخططة/متوقعة من العميل وليست واجهات خادم مكتملة.

**النتيجة:** لا يمكن وصف حقول playlist source/share بأنها مجرد cache مؤكدة أو بيانات مركزية؛ توجد نية واضحة في نموذج العميل ولكن لا يوجد تنفيذ server-side متناظر في الإصدار الحالي. كما أن استجابة full sync قد تعيد سجل القائمة دون هذه الحقول، فلا يصح افتراض استمرارها بعد إعادة مزامنة كاملة.

تسجل المسألة كالتالي:

**PLAYLIST_IMPORT_SHARING_BACKEND_CONTRACT_MISSING** — يلزم مراجعة نطاق Phase06 وعقد Flutter/Backend لتحديد ما إذا كانت المشاركة والاستيراد ميزات مطلوبة الآن، وما هي حقول المصدر/الظهور/الخصوصية والعمليات المسموح بها. لا تضف schema/routes أو تشغّل endpoints الآن ضمن Phase12؛ لا تُسقط الحقول محليًا بصمت، ولا تعتبر ميزات العميل مدعومة من الخادم قبل تنفيذ واختبار عقد متكامل.

### 13.4 سياسة حفظ الطوابير المحلية

كل قائمة SQLite تحمل queue محلية للعمليات غير المرسلة، وplaylist store يحفظ body لكل operation_id وcheckpoint منفصلًا. هذه ليست هي نفسها سجلات العملية التي يحتفظ بها Backend بعد المعالجة. عند أي migration مستقبلية:
1. تؤخذ snapshot من confirmed state ومن pending queue على حدة؛
2. تعاد العمليات المعلقة بالترتيب مع operation IDs ذاتها وبحسب idempotency contract؛
3. لا تحذف النسخة المحلية حتى يستلم العميل ACK/رفض ثابتًا ويثبت أن عملية replay لم تضف أثرًا مزدوجًا؛
4. تُعاد مزامنة cursor/revision بعد اختيار المصدر والوجهة، ولا يُفترض أن رقم revision في نظام قديم صالح في النظام الجديد.

### 13.5 Open mapping decisions

- مصدر Firebase القديم إلى أعمدة Laravel الحالية يحتاج field mapping للـUIDs وFirestore document IDs وأنواع الوقت والحذف/التعارضات؛ ما زال source غير معتمد.
- Firebase Scrypt Auth import blocker مشروط باختيار Firebase، ولا توجد fields/verifier في المخطط الحالي.
- playlist source/share metadata عقدها غير مكتمل؛ يلزم حسم إن كانت user-visible.
- local guest/offline pending operations تتطلب قواعد ownership/merge ومنع إعادة إرسال مزدوج.
- لا توجد إحصاءات حقيقية لعدد الأجهزة أو الصفوف المحلية أو pending queues؛ لا تُقدّر من ملفات الكود.



## 14.3 — فحوص عقد مشاركة واستيراد القوائم

- Flutter client expected routes: `PUT playlists/{id}/sharing`, `GET shared-lists/{code}`, و`playlist-imports` start/status/cancel/commit.
- Backend route inventory الحالي: `POST /sync/playlists` فقط؛ user_playlists schema وplaylistPayload لا يحتويان source/share/visibility fields.
- Client documents `unavailable` عند 404/405 ويصرح بأن backend لا يحتوي imports بعد.
- هذه فجوة عقد وظيفية، وليست دليلًا على وجود بيانات مشاركة مفقودة في خادم حي. يلزم قرار نطاق بعد مراجعة Phase06؛ Phase12 تسجل الحقول فقط ولا تنفذ الميزات.



## 15. سياسات revisions وcursors والاحتفاظ التي تؤثر في الترحيل

### 15.1 Recently watched tombstone retention

Backend الحالي يحدد TOMBSTONE_RETENTION_MS = 7,776,000,000 (90 يومًا). وفي sync:
- يحذف الخادم tombstones الأقدم من 90 يومًا.
- تعليق Controller يذكر أن Flutter يحذف tombstones المحلية التي سبق sync لها بعد 90 يومًا.
- العميل يجبر full pull مرة واحدة على الأقل كل 7 أيام.

**مخاطرة ترحيل:** جهاز ظل غير متصل لأكثر من 90 يومًا قد يعود دون tombstone قديم موجود في الخادم أو في local store بعد prune. لا تفترض أن full pull وحده يعيد تاريخ الحذف إذا كانت tombstones حُذفت بالفعل؛ يلزم تحديد سياسة الأجهزة القديمة والبيانات اليتيمة وتعارضات resurrected records قبل أي source switch. يجب قياس هذه الحالة من telemetry/manifest عند السماح لاحقًا، لا تخمينها.

### 15.2 Revisions and pagination cursors are not portable IDs

- Bookmark sync uses account/domain since_revision and until_revision numeric checkpoints plus page cursor. A full snapshot starts at revision zero and rejects incompatible incremental windows. The change log and operation receipts (user_bookmark_changes, user_bookmark_operations) provide idempotency and replay protection.
- Playlist sync similarly uses account-scoped revisions and numeric item/change cursors, plus user_playlist_operations receipts. Local playlist_meta checkpoint is not a canonical revision in a new database.
- Wellness cursor is opaque and signed: payload contains version, user_id, since_revision, snapshot_revision, last_id; signature is HMAC-SHA256 using current APP_KEY. Copying it to another user ID or after changing APP_KEY produces invalid cursor. A new target/account/key must start a new authorized full snapshot rather than import the old cursor token.
- Recently-watched since_revision is account-scoped; if a client revision is ahead of server it must full sync.

**Migration rule:** preserve domain records, tombstones, operation UUIDs, change ledgers and server revision state where source/target semantics truly match, but never copy a local pagination cursor or signed wellness cursor as an independent data record. Initialize new cursors only through the target API after identity mapping and full snapshot acceptance. Replaying pending operations must retain their original operation IDs and be bounded by idempotency receipts.

### 15.3 Expired/offline queue treatment

Before cutover in a future approved migration, classify each device's state into: confirmed server snapshot, pending operation queue, last successful sync time, last server revision/cursor, guest owner scope, and local tombstone retention age. A device that has been offline longer than the server's deletion retention window requires conflict-aware reconciliation; do not blindly upload all remaining local rows or delete local DBs after a single HTTP 200. Device cleanup requires per-operation ACK and stable owner binding.

## 16. مخازن الأجهزة وguest/offline merge — source inventory — 28 نوفمبر 2026

**Android source:** `m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a` (read-only). Current app main `51f12760cc4e06a5651f7e07dbd89f774bafece4` differs by one documentation-only commit; SQLite/sync code paths reviewed here did not change.

### 16.1 Local database topology

| Local file | Tables | Ownership and migration notes |
|---|---|---|
| `movies.db` | `movie_bookmark_table` | LibraryScope owner suffix only when enabled; guest retains legacy path. |
| `tv.db` | `tv_bookmark_table` | Same; movie/tv identity must remain separate. |
| `recent_movies.db` | `recently_watched_movies_table` | Updated/deleted UTC milliseconds and synced flag; include tombstones and pending rows. |
| `recent_episodes_v2.db` | `recently_watched_tv_shows_table` | Episode ID remains distinct from series ID; preserve season/episode and progress. |
| `playlists.db` | `playlists`, `playlist_items`, `playlist_operations`, `playlist_meta` | Owner-scoped file when enabled; confirmed state, pending operation queue, checkpoint and source/share metadata live together. |
| `raven23_wellness_v1.db` | `wellness_sessions`, `wellness_daily_summaries` | One shared file; owner_id partitions rows; daily summary is derived from sessions. |

LibraryScope.enabled defaults to false in the pinned app source. With it enabled, account DB filenames use `.laravel.<URI-encoded-owner>` suffix while guest retains historical paths. Wellness uses a fixed DB path and owner_id row partition instead of per-owner files.

### 16.2 SharedPreferences sync journal

`KvStore` uses namespace `flixquest.laravel.v1.`. Migration-relevant keys include:
- `sync.<owner>.bookmarks.known` and `sync.<owner>.bookmarks.deleted` for server-known bookmark IDs and pending deletion journal.
- `sync.<owner>.recent.cursor` and `sync.<owner>.wellness.cursor` for revision/sync/full-pull state; Wellness cursor is opaque and signed.
- `sync.<owner>.playlists.last_synced` and local `playlist_meta.checkpoint`.
- `sync.guest.claimed_by` and `sync.guest.copied` for guest claim ownership and row snapshots.

These journals are separate from SQLite files. Do not assume that copying SQLite alone captures all migration state. Do not copy numeric revisions or opaque HMAC cursors to a new target; establish a new full snapshot after authorized owner mapping.

### 16.3 Guest merge behavior and acceptance risks

- LocalLibrary copies every bookmark/recent row, including pending rows and tombstones. Bookmark copy uses conflict-ignore; recent rows compare updated_at_utc and are marked unsynced when copied.
- Guest snapshots are cleared only when rows still exactly match the snapshot, preserving edits made during an offline merge.
- Wellness owner remapping updates owner_id and pending status, then rebuilds daily summaries from sessions.
- PlaylistStore.moveInto copies pending operation IDs/bodies only. If there are no pending operations it returns without moving anything; otherwise it copies the queue and clears the guest store, including confirmed lists/items and playlist_meta. It does not copy source/share fields.
- Existing Flutter guest-transfer coverage tests queue-only playlist migration; it does not prove confirmed guest playlist preservation.
- Bookmark remote-known/deletion journals are in SharedPreferences. The reviewed guest merge path has no explicit transfer of these guest keys to the authenticated owner; assess whether such keys can be populated in any legacy guest cohort before excluding them.

**RISK-12A-PLAYLIST-GUEST-CONFIRMED-STATE** is an unclosed data-preservation risk, not evidence that a live user's rows were lost. Close it by proving confirmed guest state cannot exist for every relevant cohort or by adding a preservation/transfer test in the RAVEN23 app repository.

**RISK-12A-BOOKMARK-GUEST-JOURNAL** is conditional on the existence of guest remote-known/deletion journal keys; no live device state has been inspected.

### 16.4 Aggregate-only device evidence contract

Any later approved preflight should capture counts only: file/schema presence; table totals; pending/confirmed/tombstone counts and age buckets; playlist confirmed/pending counts and source/share metadata counts; Wellness owner-scope counts and daily rebuild validation; bookmark known/deletion journal counts; cursor presence; last full/last successful sync timestamps. Exclude PII, row content, emails, raw owner identifiers and operation UUIDs from the public report.

No device telemetry or user database files were accessed for this source review.