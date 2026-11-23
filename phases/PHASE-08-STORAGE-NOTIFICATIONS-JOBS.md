# المرحلة 08 — تخزين الملفات والإشعارات والمهام الخلفية

**الحالة:** IN_PROGRESS — أنجزت شريحة queue/mail داخلية واجتازت CI؛ التخزين وpush والتكامل الحقيقي للبريد ما زالت مفتوحة.  
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
- اختبارات `QueuedNotificationsTest` تفحص queue dispatch، التشفير، after-commit، queue name، backoff، والـretry deadline الثابت.
- Docker CI يفحص shell syntax ويشغّل حالات رفض timeout/retry_after المتعارضة وsync queue.

### CI proof على SHA `dd861fa9afd6f876ff300f65aae58a35b16f3112`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525) | SUCCESS — 92 Feature / 1,046 assertions؛ 12 PostgreSQL / 265 assertions |
| Authentication Regression | [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572) | SUCCESS — 20 SQLite / 151 assertions؛ 4 PostgreSQL / 99 assertions |
| Pint Format | [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578) | SUCCESS |
| Security Scans | [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417) | SUCCESS؛ Gitleaks/Trivy، وصورة الإنتاج Clean في ملخص Trivy |

### حدود ما تم إثباته

- اختبارات queue تستخدم Laravel Queue fake؛ أثبتت job properties/encryption/dispatch contract، ولم تشغّل worker فعلياً ضد SMTP أو mail sink.
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
