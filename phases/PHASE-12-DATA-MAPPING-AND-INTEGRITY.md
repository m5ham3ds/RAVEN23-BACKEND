# Phase12A — خريطة ترحيل البيانات وعقد السلامة والتكامل

**التاريخ:** 28 نوفمبر 2026  
**الحالة:** SOURCE-LEVEL MAPPING COMPLETE؛ source/target الفعليان وبيانات الإنتاج غير متاحين وغير معتمدين.  
**النطاق:** إعداد محايد لمزود الخدمة فقط؛ لا اتصال بقاعدة الإنتاج ولا اختيار وجهة أو مزود أو تنفيذ cutover.

## 1. الغرض وحدود الإثبات

تحدد هذه الوثيقة نطاق البيانات الذي يجب أخذه في الحسبان عند ترحيل RAVEN23 Backend، وتربط مجالات تطبيق المستخدم بمخطط Laravel الحالي، وتثبت invariants التي يجب عدم كسرها.

المصدر المثبت حاليًا هو **كود Git وملفات migrations وModels وAPI contracts**. جرد المصدر الحالي يقول إن هناك 23 migration. واختبار PostgreSQL في CI على commit \`72730281a6c96208979e4c8a1eaa46fbc723dcdd\` أنشأ 34 public table في قاعدة مؤقتة، واستعاد النسخة بنجاح وقارن schema/data fingerprints. هذه أعداد بيئة CI فقط وليست أعداد قاعدة إنتاج أو مستخدمين فعليين.

**المصدر الفعلي للبيانات: UNKNOWN_PENDING_SOURCE_APPROVAL.** تظهر في المستندات السابقة إشارات إلى PostgreSQL/Neon وإلى Firebase/Firestore وإلى قواعد محلية في التطبيق؛ لكنها لا تثبت أي مصدر حي هو المرجع النهائي، ولا يجوز دمج مصادر أو افتراض أن كل نسخة متزامنة.  
**الوجهة الفعلية: UNKNOWN_PENDING_PROVIDER_DECISION.** لا تختار هذه الوثيقة PostgreSQL host أو مزودًا أو منطقة أو تكلفة.

## 2. خريطة المجالات والجداول المستهدفة

| المجال | جداول Laravel الحالية المعروفة من migrations | قواعد الحفاظ المطلوبة |
|---|---|---|
| هوية الحساب | \`users\` | الإبقاء على معرف الحساب الأصلي وعلاقاته؛ لا دمج حسابين تلقائيًا بسبب تشابه البريد أو الاسم. |
| جلسات ومصادقة | \`personal_access_tokens\`, \`password_reset_tokens\`, \`email_verification_tokens\`, \`sessions\` | لا تصدير bearer tokens أو أسرار الاستعادة. قرار حفظ/إبطال الجلسات والـtokens يحدد لاحقًا؛ عمود token في Sanctum مخزن كـhash وليس bearer token قابلًا للاسترجاع. |
| أساس الحساب | \`user_profiles\`, \`user_preferences\`, \`user_devices\` | صف الملف والتفضيلات مرتبطان بمالك واحد؛ device key فريد لكل (\`user_id\`, \`device_id\`). |
| المفضلة | \`user_bookmarks\`, \`user_bookmark_operations\`, \`user_bookmark_changes\` | preserve key (\`user_id\`, \`media_type\`, \`media_id\`) وعمليات idempotency وchange revisions/tombstones. |
| سجل المشاهدة | \`user_recent_media\`, \`user_sync_revisions\` | preserve movie/episode identity، elapsed/remaining، revision، tombstones، وUTC epoch milliseconds دون تقريب أو تحويل وحدات. |
| القوائم | \`user_playlists\`, \`user_playlist_items\`, \`user_playlist_operations\`, \`user_playlist_changes\` | preserve playlist UUID، ترتيب العناصر position، version، composite item identity، operation idempotency، وchange history. |
| مزامنة التفضيلات | \`user_preference_operations\` بالإضافة إلى \`user_preferences\` | preserve revision وoperation receipts/payload hashes؛ لا تعاد تهيئة revision إلى صفر أثناء النقل. |
| بيانات wellness | \`user_wellness_sessions\`, \`user_wellness_daily\`, \`user_wellness_changes\`, \`user_wellness_upload_receipts\` | preserve session UUID، local day، revision، upload stamp، payload hash، deleted flags، وسجل التغييرات. |
| الملفات الخاصة | \`user_media\` metadata + private object blobs في private filesystem abstraction | metadata لا تمثل الملف نفسه؛ تنقل bytes منفصلًا وتتحقق من \`size_bytes\` و\`checksum_sha256\` ومطابقة \`object_key\`. |
| Outbox الإشعارات | \`notification_outbox\` | preserve UUID/idempotency/status/attempts/schedule/claim fields؛ لا تفقد الرسائل pending ولا تعاود إرسالها مرتين. \`encrypted_payload\` يتطلب خطة توافق مفاتيح التشفير. |
| تسجيل Push | \`user_push_subscriptions\` | \`token\` encrypted cast و\`token_hash\` HMAC مشتق من APP_KEY؛ لا تسجل قيم token في logs أو التقارير. يتطلب تغيير المفتاح قرار إعادة تشفير/إعادة تسجيل واضحًا. |
| الاستحقاقات والاشتراكات | \`user_entitlements\`, \`subscription_events\` | \`user_entitlements\` projection وليست المصدر التاريخي الوحيد؛ \`subscription_events\` append-only ledger. preserve idempotency keys وevent hashes وaggregate revisions وحالة الإسقاط. |
| المحفظة | \`user_wallets\`, \`wallet_transactions\` | preserve wallet UUIDs وcurrency وinteger cents وfrozen/status/revision والـappend-only ledger. يجب مطابقة مجموع المعاملات والرصيد المسجل؛ لا تستخدم floating point. |
| Laravel التشغيلية | \`migrations\`, \`cache\`, \`cache_locks\`, \`jobs\`, \`job_batches\`, \`failed_jobs\` | migrations ledger جزء من schema history؛ cache قد يكون قابلًا لإعادة البناء بعد قرار، أما jobs فيلزم drain/replay أو نقل منضبط كي لا تضيع رسائل queued. لا تعامل هذه الجداول كبيانات مكتبة المستخدم. |

هذه خريطة المجالات الأساسية المعروفة من source. لا تعتبر القائمة إثباتًا بأن المصدر الفعلي يحتوي كل جدول أو أي عدد من الصفوف؛ ذلك يتطلب manifest من مصدر معتمد لاحقًا.

## 3. واجهة تصدير الحساب ليست نسخة ترحيل كاملة

المسار \`GET /api/v1/user/data-export\` في \`AuthController::exportUserData()\` يعيد \`format_version = 3\` ويضم:

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
5. قيم \`updated_at_utc\` و\`deleted_at_utc\` وحقول الوقت بوحدة milliseconds لا تحول إلى seconds أو local time؛ حافظ على precision وtimezone semantics.
6. حافظ على JSON values وأنواعها ومفاتيحها كما هي؛ لا تسقط المفاتيح غير المعروفة ولا تعِد ترتيب/تطبيع payloads بطريقة تغير hash أو conflict resolution.
7. قيّم حالة sequence current/next values بعد restore قبل اعتماد ترحيل حقيقي؛ تمرين Phase12 الحالي يقارن تعريفات sequence metadata ضمن schema manifest ولا يعلن وحده قبول high-water state لكل sequence.
8. \`subscription_events\` و\`wallet_transactions\` سجلات append-only. لا تعد كتابتها من projection الحالي ولا تستخدم down-migrations مدمرة كخطة rollback.
9. Sanctum polymorphic owner references ليست FK تقليدية على \`users\`. سياسة الاحتفاظ/الإبطال للـtokens والجلسات قرار مستقل، ويجب عدم نقل bearer secrets أو طلب استخراجها.
10. الملفات الخاصة خارج PostgreSQL؛ يجب أن يطابق كل object metadata key/size/checksum قبل اعتماد النقل.

## 5. مفاتيح التشفير والأسرار

- Push subscription token يستخدم Eloquent encrypted cast، وlookup hash يستخدم HMAC-SHA256 بمفتاح APP_KEY.
- Notification outbox يخزن payload في \`encrypted_payload\`; queue payloads محمية أيضًا ضمن إعداد Laravel الحالي.
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

على commit \`72730281a6c96208979e4c8a1eaa46fbc723dcdd\`:

- Docker Validation #671: SUCCESS، بما في ذلك full-schema PostgreSQL dump/encrypt/decrypt/restore، smoke tests، rollback rehearsal، provenance/SBOM، signed release manifest.
- Authentication Regression #293: SUCCESS.
- Security Scans #417: SUCCESS.
- Pint #415: SUCCESS.
- IaC Validation #31 على baseline \`dbd281c545f5a2da5a6816afccebd4d929a02b70\`: SUCCESS، 30/30 guardrails؛ لم تتغير ملفات IaC منذ ذلك التحقق.

دليل full-schema artifact:
- Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759
- Artifact: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759/artifacts/15235408250
- Digest: \`sha256:7cf5bf3ec6b5ce338e9c51a8eaa46fbc723dcdd\` (see GitHub artifact metadata for the exact archive digest below in the status record).

**محتوى التقرير المقاس:** 23 migration files = 23 migration ledger rows؛ 34 public tables؛ schema manifest SHA-256 \`cfeed3162273eefebe90da3fdd3580bbecdc9bf9956ffeb69b51e9210c36c3c0\`؛ table-data manifest SHA-256 \`274777e346f2ff12d80e206ac4458690f49b19075c41e0caa3c3e688384b0179\`؛ جميع مقارنات الجدول/المخطط/الأعمدة/القيود/الفهارس/sequence definitions/triggers/views/row-counts/fingerprints = matched.

حدود مهمة: الأعداد 23/34 تخص قاعدة GitHub Actions المؤقتة. \`productionDatabaseUsed=false\`, \`productionSecretsUsed=false\`, \`productionDataRead=false\`, \`cutoverPerformed=false\`, \`temporarySourceAndTargetDropped=true\`, \`rowValuesIncludedInArtifact=false\`. لم يتم اختبار source حقيقي أو target حقيقي أو ملفات مخزنة خارج PostgreSQL أو delta/CDC أو write freeze أو cutover.

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
