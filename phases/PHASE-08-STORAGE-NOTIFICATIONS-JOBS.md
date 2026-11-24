# المرحلة 08 — تخزين الملفات والإشعارات والمهام الخلفية

**الحالة:** IN_PROGRESS — 08A–08D منفذة ومختبرة (queue/mail baseline، private storage contract، media upload/metadata/owner access/temporary signed URLs، optional quota، وorphan cleanup آمن). ما زالت S3-compatible integration وpush وmail provider وoutbox/retention policy مفتوحة.  
**الاعتماديات:** 02 و03 و05 COMPLETE؛ Phase04 PARTIAL في تكامل البريد/Google الخارجي. تسمح الاعتمادية الحالية بتطوير queue abstraction وإبقاء الإرسال معطلاً إلى حين staging/provider approval.

## الهدف
إضافة خدمات الملفات والرسائل والمهام الخلفية عبر واجهات قابلة لاستبدال المزود.

## العمل
1. تعريف Storage interface واختيار مسار S3-compatible دون ربط domain logic بـ AWS SDK مباشرة.
2. تخزين صور الحساب والملفات الصغيرة فقط وفق سياسة أنواع/أحجام وMIME validation.
3. استخدام signed URLs أو upload authorization محدود العمر؛ منع جعل الحاويات عامة افتراضيًا.
4. حفظ metadata والملكية في PostgreSQL، أما bytes فتكون في object storage.
5. تعريف mail interface ورسائل verification/reset بقوالب قابلة للاختبار.
6. تعريف push notification adapter مع التحقق من توافق FCM مع تطبيق RAVEN23 قبل تفعيله.
7. إنشاء queue workers للمهام التي تحتاج معالجة خلفية، مع retries وdead-letter/failure tracking.
8. جعل كل jobs قابلة لإعادة المحاولة دون إرسال/تحديث مكرر.
9. حماية حذف الملفات من حذف ملف يخص مستخدمًا آخر، وتوفير orphan cleanup آمن.
10. وضع قواعد retention وlimits والتكلفة والتخزين المؤقت.

## معايير القبول
- storage يعمل عبر adapter اختباري وS3-compatible test/staging.
- صلاحيات الوصول للملفات خاصة ومحدودة.
- mail verification/reset يمكن اختباره باستخدام mail sink قبل تفعيل مزود حقيقي.
- Jobs retry-safe وتظهر أسباب الفشل دون كشف الأسرار.
- حذف/تصدير حساب المستخدم يتعامل مع الملفات وفق سياسة واضحة.
- لا تخزن ملفات الفيديو على قرص EC2.

## اعتماديات خارجية
اختيار مزود البريد/الإشعارات، credentials، domain/DNS وAWS account قد تبقى WAITING_EXTERNAL. لا تشترِ خدمة أو تنشئ موارد مدفوعة تلقائيًا.


## التنفيذ المثبت على main — 23 نوفمبر 2026

### شريحة 08A — Mail notifications وdatabase queue

- `EmailVerificationNotification` و`PasswordResetNotification` تطبقان `ShouldQueue` و`ShouldBeEncrypted`.
- توضع الرسائل على queue باسم `mail` وتُرسل بعد commit لتجنب إرسال رابط يشير إلى تغيير لم يُثبت في قاعدة البيانات.
- حدود المهمة: 5 attempts، وtimeout خاص بالإشعار 30 ثانية، و3 max exceptions، وfail-on-timeout؛ backoff `[10, 30, 90, 180]` ثانية، وretry deadline ثابت بعد 20 دقيقة من إنشاء الإشعار.
- يستخدم Laravel database queue الموجود بالفعل (`jobs`, `job_batches`, `failed_jobs`)؛ لم تُضف migration أو schema جديداً لهذه الشريحة.
- أضيف `docker/queue-worker.sh` كعملية منفصلة داخل صورة runtime. افتراضاته: queues `mail,default`، sleep 3s، tries 5، timeout 45s، max-time 3600s، memory 128MB.
- يرفض العامل `sync/null` ويشترط أن يكون `QUEUE_TIMEOUT < DB_QUEUE_RETRY_AFTER` عند استخدام database driver؛ القيم النموذجية 45 و90 ثانية.
- اختبارات `QueuedNotificationsTest` تفحص queue dispatch والتشفير وafter-commit وqueue name وbackoff والـretry deadline؛ كما تختبر rendering لقوالب البريد وتشغيل عامل قاعدة البيانات مرة واحدة عبر mailer محلي `array` وتسجيل الفشل النهائي في `failed_jobs`.
- Docker CI يفحص shell syntax ويشغّل حالات رفض timeout/retry_after المتعارضة وsync queue.

### CI proof على SHA `dd861fa9afd6f876ff300f65aae58a35b16f3112`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525) | SUCCESS — 92 Feature / 1,046 assertions؛ 12 PostgreSQL / 265 assertions |
| Authentication Regression | [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572) | SUCCESS — 20 SQLite / 151 assertions؛ 4 PostgreSQL / 99 assertions |
| Pint Format | [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578) | SUCCESS |
| Security Scans | [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417) | SUCCESS؛ Gitleaks/Trivy، وصورة الإنتاج Clean في ملخص Trivy |

### حدود ما تم إثباته

- اختبارات dispatch تستخدم Laravel Queue fake، مع اختبار مستقل يشغّل `queue:work --once` على قاعدة البيانات ويرسل الرسالة إلى mailer محلي `array`؛ لم يُختبر SMTP خارجي.
- لم يُضبط provider أو domain/DNS أو credential؛ `RAVEN23_AUTH_MAIL_ENABLED=false` و`MAIL_MAILER=log` ما زالا كما هما.
- لم يُختبر توصيل البريد الحقيقي أو الرسائل في تطبيق Android.
- لم يُنفذ S3-compatible storage adapter أو private object authorization أو metadata/ownership API.
- لم يُنفذ FCM push adapter؛ يجب مطابقة عقد Flutter أولاً.
- لم تُغلق idempotency عند حالة SMTP accepted ثم worker crash؛ التوصيل الخارجي يبقى at-least-once ما لم يضف provider-specific idempotency/outbox.

### المتبقي في Phase08

1. تعريف Storage contract وS3-compatible test adapter دون ربط domain بـAWS SDK.
2. رفع صور الحساب والملفات الصغيرة بحدود size/MIME/content validation وتوقيع محدود العمر.
3. metadata/owner access في PostgreSQL، private bucket/container افتراضياً، وownership-safe delete/orphan cleanup.
4. Mail sink tests لقوالب الرسائل وrendered action URLs؛ ثم provider/staging integration بعد اختيار المزود والموافقة.
5. Push adapter بعد مطابقة FCM/OneSignal مع تطبيق RAVEN23، دون تفعيل مزود أو سر قبل الموافقة.
6. worker execution/retry/dead-letter integration test باستخدام fake provider، ثم قياس التشغيل.
7. سياسات retention وlimits والتكلفة وبيانات account export/delete للملفات.

**الحالة:** Phase08 IN_PROGRESS، وهذه الشريحة لا تعني أن التخزين أو push أو البريد الخارجي جاهز للإنتاج.


### شريحة 08B — عقد التخزين الخاص المستقل عن المزود

- أضيف عقد PrivateObjectStorage بعمليات put/get/exists/delete فقط؛ لا يسرّب تفاصيل AWS أو HTTP أو provider إلى منطق المجال.
- أضيف FilesystemPrivateObjectStorage فوق Illuminate Contracts Filesystem، ولذلك يمكن استخدامه مع Laravel local أو أي disk متوافق مثل S3 بعد تثبيت driver المطلوب.
- رُبط العقد في AppServiceProvider عبر RAVEN23_PRIVATE_STORAGE_DISK، والافتراضي disk منفصل private_media بمجلد خاص غير منشور وvisibility=private؛ بقي FILESYSTEM_DISK=local وعقد Flutter photo_url دون تغيير.
- يرفض adapter مفاتيح فارغة أو traversal (./..) أو فواصل مزدوجة أو محارف غير مسموح بها، ويفرض private visibility في الكتابة، ويرفع استثناءً عند فشل التخزين/القراءة/الحذف.
- أضيف PrivateObjectStorageTest لاختبار binding والكتابة والقراءة والوجود والحذف والرفض الآمن للمفاتيح غير الصالحة باستخدام Storage::fake.
- لا توجد حزمة league/flysystem-aws-s3-v3 أو AWS SDK في composer.lock حالياً؛ لذلك لم يتم تفعيل disk S3 أو إضافة مفاتيح/اعتمادات خارجية. هذا يثبت حدود العقد وسلوكه على adapter محلي اختباري فقط، وليس اختبار اتصال S3 حقيقي.

### ما لم يدخل بعد في 08B

- لا يوجد upload endpoint أو قبول ملفات من المستخدم حتى الآن.
- لا يوجد جدول metadata/ownership أو signed URL أو authorization للتنزيل.
- لا يوجد profile-photo migration أو تغيير إلى photo_url؛ الحقل القديم متوافق كما هو.
- لا يوجد S3 credentials أو bucket أو تكاليف أو موارد cloud تم إنشاؤها.


## آخر نقطة تنفيذ موثقة — 24 نوفمبر 2026

### 08C — رفع الملفات وmetadata والحصة الاختيارية

- أضيف `UserMedia` وmigration `2026_11_23_000014_create_user_media_table` مع UUID metadata وowner FK وchecksum SHA-256.
- `POST /api/v1/user/media` محمي بـSanctum. الصور المقبولة JPEG/PNG/WebP بحد 5 MiB وأبعاد أقصى 4096×4096؛ المرفقات PDF/TXT بحد 10 MiB. يتم التحقق من MIME من المحتوى، وتوليد object key على الخادم.
- `GET /api/v1/user/media/{media}` و`DELETE /api/v1/user/media/{media}` يفرضان ملكية المستخدم. تنزيل الصورة inline والمرفق attachment، مع private/no-store وnosniff.
- مسار temporary signed download هو `GET /api/v1/user/media/{owner}/{media}/temporary`; TTL الافتراضي 5 دقائق وبحد أقصى 15، ولا يحتاج Bearer لكنه يثبت توقيع الرابط ومالكه وانتهاء صلاحيته.
- حذف الملف وجدولة cleanup بعد حذف الحساب يستخدمان `DeletePrivateObjectJob` المشفر، بعد commit، مع retry/backoff؛ ويظل حذف metadata owner-scoped.
- export يتضمن metadata ورابط تنزيل محميًا ولا يكشف object keys. لم يُكسر عقد `photo_url` القديم ولم تُغيّر ملفات الفيديو إلى تخزين التطبيق.
- الحصة الاختيارية عبر `RAVEN23_USER_MEDIA_QUOTA_BYTES`؛ القيمة الافتراضية 0 تعني غير محدود. يتم قفل سجل الحساب داخل transaction قبل احتساب usage والكتابة حتى لا تتجاوز الرفعات المتزامنة الحصة عند تفعيلها.

### 08D — كشف الملفات اليتيمة وتنظيفها

- أضيف `PrivateObjectStorage::listObjects()` كـiterable streaming فوق Flysystem listing لتجنب تحميل قائمة التخزين بالكامل إلى الذاكرة.
- الأمر `php artisan media:cleanup-orphans` يعمل dry-run افتراضيًا ويعرض عدد العناصر المرشحة دون طباعة مسارات الملفات.
- `--apply` وحده غير كافٍ: يجب أيضًا تفعيل `RAVEN23_PRIVATE_MEDIA_ORPHAN_CLEANUP_ENABLED=true` صراحةً. عندها فقط تُرسل jobs للحذف؛ لا ينفذ الأمر حذف bytes مباشرة.
- لا يُفحص إلا المسار `users/{id}/media/{uuid}.{jpg|jpeg|png|webp|pdf|txt}`، وتُستبعد objects الأحدث من فترة السماح. الافتراضي 7 أيام والحد الأدنى يوم واحد.
- الحد الافتراضي 500 orphan لكل تشغيل، ويمكن تقليله أو زيادته حتى 5000. الاستعلام عن metadata يتم على دفعات 250 object.
- الجدولة اليومية 03:30 و`withoutOverlapping` تُسجل فقط إذا كان التفعيل الصريح صحيحًا؛ الإعداد الافتراضي في `.env.example` هو false.

### إثبات CI على SHA `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`

| Gate | Run | النتيجة |
|---|---:|---|
| Docker Validation | [45288019564](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45288019564) | SUCCESS — SQLite Feature 109 tests / 1,196 assertions؛ PostgreSQL Integration 15 tests / 295 assertions |
| Authentication Regression | [45288019611](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45288019611) | SUCCESS |
| Pint Format | [45288020088](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45288020088) | SUCCESS |
| Security Scans | [45288019627](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45288019627) | SUCCESS — Gitleaks/Trivy وصورة الإنتاج؛ لا نتائج HIGH/CRITICAL قابلة للإصلاح |

### ما بقي مفتوحًا في Phase08

1. S3-compatible adapter/integration اختبار حقيقي على خدمة اختبار مؤقتة مثل MinIO أو staging؛ لم يُثبت اتصال S3 ولا تُوجد AWS credentials أو bucket.
2. اختيار مزود mail حقيقي وعنوان action/deep link وإجراء staging verification/reset. `RAVEN23_AUTH_MAIL_ENABLED=false` ما زال يمنع الإرسال الخارجي.
3. مطابقة Flutter push contract ثم إضافة adapter واختبارات FCM/OneSignal دون تفعيل أسرار أو provider غير معتمد.
4. تصميم outbox/idempotency لحالة قبول SMTP ثم تعطل worker، واختبار تسليم at-least-once.
5. اعتماد سياسة retention والسعة والتكلفة. الحصة ما زالت غير محدودة افتراضيًا، والتنظيف معطل افتراضيًا؛ لم تُحذف ملفات فعلية في CI.

**المرحلة 08 تبقى IN_PROGRESS.** نجاح هذه الشرائح لا يعني جاهزية S3 أو البريد أو push للإنتاج.


### 08E — Transactional notification outbox and idempotent recovery (24 November 2026)

- Added `notification_outbox` with UUID records, a unique HMAC-SHA-256 idempotency key, Laravel-Crypt encrypted JSON payload, notification type, delivery state, attempts, availability/dispatch/claim/sent timestamps, and a sanitized last-error class.
- Email verification token persistence and the matching outbox event now share one database transaction; queue dispatch is registered with `DB::afterCommit`. Password reset notifications are routed through the same outbox.
- Added encrypted `DeliverNotificationOutboxJob` with a 60-second claim lease, bounded attempts/backoff, terminal failure state, and safe skip behavior for already sent/failed records.
- Added the `notifications:outbox-dispatch` relay, scheduled every minute. It repairs pending events whose queue dispatch was lost and recovers expired processing claims while leaving active claims untouched.
- The tests cover duplicate business events, encrypted tokens, local mail sink delivery, crash/replay with a stable idempotency key, stale queue dispatch, expired-lease recovery, and active-claim exclusion on SQLite and PostgreSQL.
- The default mail transport adds `X-RAVEN23-Idempotency-Key`; generic SMTP is not required to deduplicate this header. Provider-enforced exactly-once delivery remains unavailable until a selected provider's native idempotency mechanism is integrated.

### S3-compatible integration evidence

- Composer locks `league/flysystem-aws-s3-v3` and its AWS SDK dependency.
- Docker CI runs the private storage contract through Laravel/Flysystem against a disposable Adobe S3Mock service and verifies put/get/private visibility/list/delete/idempotent delete. This is an emulator test, not a real provider staging test.

### CI proof — SHA `8f34a0b0b725eb6704149154e2558eed81bc497f`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45296772477](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772477) | SUCCESS — SQLite Feature 113/1,232; PostgreSQL Integration 18/311; S3Mock 1/8; runtime/least-privilege PASS |
| Authentication Regression | [45296772381](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772381) | SUCCESS — SQLite 20/172; PostgreSQL 4/99 |
| Pint Format | [45296772496](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772496) | SUCCESS |
| Security Scans | [45296772309](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45296772309) | SUCCESS — Gitleaks/Trivy/production image and fixable HIGH/CRITICAL gates PASS |

### Remaining Phase08 acceptance items

1. Real S3-compatible provider/staging verification with test credentials after provider selection; no bucket, AWS credentials or paid cloud resource exists.
2. External mail provider selection, provider-native idempotency support, action/deep-link staging verification; delivery remains disabled.
3. Flutter push contract review and an approved provider adapter/test; no FCM/OneSignal secret or external push has been used.
4. Retention, quota/capacity, cost and outbox audit-retention policy approval.

**Phase08 remains IN_PROGRESS.** The local emulator and fake mail transport do not establish production provider readiness.
