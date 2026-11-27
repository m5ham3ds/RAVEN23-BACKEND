# جرد متغيرات البيئة وأسماء الأسرار

**آخر مزامنة source:** 27 نوفمبر 2026 على `main`؛ أسماء الإعدادات جرى مطابقتها مع `.env.example` و`config/*.php` وworkflows؛ لم تُقرأ قيم أسرار أو GitHub Secret values.

## 1. Backend runtime

| الاسم | الاستخدام | مطلوب/ملاحظات |
|---|---|---|
| APP_KEY | تشفير Laravel | مطلوب؛ يجب أن يبقى ثابتًا بين deployments |
| APP_ENV | وضع البيئة | production/testing |
| APP_DEBUG | عرض أخطاء Laravel | يجب أن يكون false في الإنتاج |
| APP_URL | عنوان Laravel | إعداد أساسي للتطبيق |
| LOG_CHANNEL | قناة السجل | الافتراضي `stderr` في صورة FrankenPHP الحالية؛ لا exporter لمزود رصد مفعّل مثبت |
| PORT | منفذ HTTP | الافتراضي `10000` في صورة FrankenPHP؛ entrypoint يتحقق من المنفذ ويعمل non-root |
| DB_CONNECTION | اتصال قاعدة البيانات | يفرض pgsql |
| DB_URL | URI PostgreSQL لدور التطبيق الأقل صلاحية | عقد اتصال عام يقبل PostgreSQL URI قياسياً ولا يثبت مزوداً معيناً |
| DB_MIGRATION_URL | URI PostgreSQL لدور migration أحادي المهمة | منفصل عن DB_URL؛ لا يمرر إلى web runtime |
| DB_SSLMODE | وضع TLS لقاعدة PostgreSQL | الاتصالات البعيدة في production تفرض require أو verify-ca أو verify-full |
| CACHE_STORE | مخزن cache | الافتراضي database خارج testing؛ يوصى بdatabase لمشاركة الحالة بين instances |
| GOOGLE_CLIENT_ID | Google OAuth audience | له fallback public client ID في config/services.php؛ لا تضع client secret هنا |

## 2. PostgreSQL/MySQL/SQLite وRedis/config names

الأسماء التي تُقرأ داخل config/database.php:
- DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET, DB_COLLATION, DB_SOCKET, DB_FOREIGN_KEYS, DB_SSLMODE.
- MYSQL_ATTR_SSL_CA, DB_ENCRYPT, DB_TRUST_SERVER_CERTIFICATE.
- REDIS_CLIENT, REDIS_CLUSTER, REDIS_PERSISTENT, REDIS_PREFIX, REDIS_URL, REDIS_HOST, REDIS_USERNAME, REDIS_PASSWORD, REDIS_PORT, REDIS_DB, REDIS_CACHE_DB, REDIS_MAX_RETRIES, REDIS_BACKOFF_ALGORITHM, REDIS_BACKOFF_BASE, REDIS_BACKOFF_CAP.

أسماء إعدادات cache في config/cache.php:
- APP_NAME, CACHE_PREFIX, CACHE_STORAGE_DISK, CACHE_STORAGE_PATH, DB_CACHE_CONNECTION, DB_CACHE_LOCK_CONNECTION, DB_CACHE_LOCK_TABLE, DB_CACHE_TABLE, DYNAMODB_CACHE_TABLE, DYNAMODB_ENDPOINT, MEMCACHED_HOST, MEMCACHED_PASSWORD, MEMCACHED_PERSISTENT_ID, MEMCACHED_PORT, MEMCACHED_USERNAME, REDIS_CACHE_CONNECTION, REDIS_CACHE_LOCK_CONNECTION, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION.

وجود إعدادات Redis/Memcached/DynamoDB/AWS في Laravel config لا يثبت أن هذه الخدمات مفعّلة في الإنتاج.

## 3. Mail/Google/AWS names

من config/services.php:
- Email providers: POSTMARK_TOKEN, RESEND_KEY, MAILGUN_DOMAIN, MAILGUN_SECRET, MAILGUN_ENDPOINT.
- SES: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION.
- Google: GOOGLE_CLIENT_ID.

لا توجد أدلة أن mail driver قد تم ربطه فعليًا بمسارات استعادة/تحقق البريد في Backend الحالي.

## 4. Build/runtime/diagnostic/deployment names

- Dockerfile: RAVEN23_SOURCE_COMMIT (ARG/ENV)، ويستخدم APP_ENV وDB_URL في فحوص build.
- Dockerfile يثبت `RAVEN23_SOURCE_COMMIT` داخل image metadata؛ صورة التشغيل الحالية FrankenPHP/Caddy ولا تستخدم `docker/start.sh`.
- HealthController ما زال يعرض حقول توافق اختيارية `RENDER_GIT_COMMIT` و`RENDER_INSTANCE_ID` عند وجودها؛ لا تفرض هذه الحقول Render startup أو routing.
- `docker/entrypoint.sh` يقرأ APP_KEY وPORT وAPP_ENV وAPP_DEBUG وLOG_CHANNEL فقط؛ لا يشغل migrations ولا يفحص DB عند startup.
- لا توجد workflows نشر إلى Render أو AWS في شجرة workflows الحالية؛ الخمس الحالية CI/manual read-only smoke.

## 5. أسماء GitHub Secrets التاريخية

ظهرت الاسمان `NEON_DB_URL` و`NEON_DB_MIGRATION_URL` في workflows/diagnostics أقدم. لا تستخدمهما workflows الخمسة الحالية كأسرار نشر، ولا يقرأهما `config/database.php`؛ عقد التطبيق الحالي هو `DB_URL` و`DB_MIGRATION_URL` القياسيان.

لم تتم قراءة قيم هذه الأسرار ولا يمكن تأكيد وجودها أو محتواها أو صلاحيتها. لا تُكتب القيم داخل المستودع أو التقارير، ولا يُنشأ أي secret جديد باسم Neon.

## 6. Flutter client variables

من RAVEN23/.env.example (الأسماء فقط):
- TMDB_API_KEY
- MIXPANEL_API_KEY
- OPENSUBTITLES_API_KEY
- SUBDL_API_KEY
- ARABICSUBS_PROXY_URL
- CONSUMET_URL
- RAVEN23_API_URL
- FLIXHQ_NEW_URL
- FLIXAPI_URL
- UNITY_GAME_ID_ANDROID
- UNITY_BANNER_PLACEMENT_ID
- UNITY_INTERSTITIAL_PLACEMENT_ID
- UNITY_REWARDED_PLACEMENT_ID
- UNITY_TEST_MODE
- LARAVEL_API_URL
- ONESIGNAL_APP_ID

Compile-time flags/optional test inputs observed in Dart:
- ENABLE_FIREBASE
- LOCAL_DEV_MODE
- RAVEN23_AUTH_ONLY
- RAVEN23_E2E_API_URL
- RAVEN23_E2E_ALLOW_WRITES
- LARAVEL_SMOKE_URL

LARAVEL_API_URL هو account/data API؛ RAVEN23_API_URL هو scraper/source service. لا تدمج هذين المتغيرين.

## 7. قواعد إدارة الأسرار

- لا تضف قيم أسرار إلى .env.example أو Markdown.
- AWS deployment should use GitHub OIDC and short-lived IAM role credentials, not permanent AWS keys stored in repo.
- Database URL values, OAuth secrets, app keys and tokens belong in selected secret manager/GitHub Environments.
- When Phase 11 is ready, replace Neon credentials only after AWS account/region/cost and migration are approved.
- Inventory shows names only; it does not prove that any secret is configured in the linked GitHub account.


## 8. Notification outbox and Push worker configuration

- `QUEUE_CONNECTION`: asynchronous queue connection؛ عامل الإنتاج لا يقبل `sync` أو `null`.
- `QUEUE_WORKER_QUEUES`: قائمة الطوابير للعامل؛ المثال الافتراضي `mail,push,default`. إذا فُعّل Push يجب تضمين `push` وإلا يرفض worker startup.
- `RAVEN23_PUSH_ENABLED`: الافتراضي `false`؛ يظل الإرسال مغلقًا إلى حين اعتماد provider adapter وstaging.
- `RAVEN23_PUSH_PROVIDER`: الافتراضي `none`؛ الاختيارات المدعومة في عقد المجال `fcm` و`onesignal` فقط بعد اعتماد الاختيار.
- `NOTIFICATION_OUTBOX_DISPATCH_BATCH` و`NOTIFICATION_OUTBOX_REDISPATCH_AFTER_SECONDS`: حدود أمر relay المجدول.
- `notifications:outbox-status`: أمر تشخيص aggregate read-only؛ لا يحتاج secret إضافي ولا يخرج PII أو notification payload.

## 9. إعدادات Object Storage المحايدة — Phase11A

- `RAVEN23_PRIVATE_STORAGE_DISK` الافتراضي `private_media` المحلي الخاص؛ لا اتصال سحابي افتراضي.
- عند اختيار S3-compatible disk بموافقة مستقلة، يكون `RAVEN23_PRIVATE_STORAGE_DISK=s3`.
- متغيرات الإعداد المحايدة ذات الأولوية: `RAVEN23_OBJECT_STORAGE_ACCESS_KEY_ID`, `RAVEN23_OBJECT_STORAGE_SECRET_ACCESS_KEY`, `RAVEN23_OBJECT_STORAGE_REGION`, `RAVEN23_OBJECT_STORAGE_BUCKET`, `RAVEN23_OBJECT_STORAGE_URL`, `RAVEN23_OBJECT_STORAGE_ENDPOINT`, `RAVEN23_OBJECT_STORAGE_USE_PATH_STYLE_ENDPOINT`.
- أسماء `AWS_*` المقابلة باقية fallback backward-compatible فقط؛ هذا لا يعني اختيار AWS أو إنشاء bucket.
- Docker S3Mock يمرر الأسماء المحايدة إلى تكوين Laravel الحقيقي. الاختبار لا يقرأ ولا يحتاج أسراراً خارجية.

## 10. مزامنة إعدادات main وقرار تأجيل جميع المزودين — 27 نوفمبر 2026

تمت مطابقة أسماء البيئة مع `.env.example` و`config/cache.php` و`config/queue.php` و`config/filesystems.php` على main. القيم الافتراضية غير السرية المثبتة هي:

- `DB_CONNECTION=pgsql`، و`DB_URL` لدور التطبيق، و`DB_MIGRATION_URL` لمهمة migration المنفصلة.
- `CACHE_STORE=database`، `SESSION_DRIVER=database`، `QUEUE_CONNECTION=database`.
- `FILESYSTEM_DISK=local`، `RAVEN23_PRIVATE_STORAGE_DISK=private_media` (private local disk). S3 disk موجود كـadapter قابل للإعداد، لكنه غير مفعّل افتراضيًا.
- `MAIL_MAILER=log`، `RAVEN23_AUTH_MAIL_ENABLED=false`، و`RAVEN23_AUTH_ACTION_URL` فارغ حتى اعتماد mail provider لاحقًا.
- `RAVEN23_PUSH_ENABLED=false` و`RAVEN23_PUSH_PROVIDER=none`؛ لا sender credentials أو delivery خارجي.
- Google client ID runtime configuration فقط؛ لا تثبت هذه القائمة نجاح real Android OAuth.

بموجب D-050، اختيار مزود الاستضافة/قاعدة البيانات/التخزين/البريد/Push/الدفع/registry/secrets/monitoring/DNS/region والتكلفة مؤجل إلى ما بعد أعمال Backend المستقلة عن المزود. لا تضف أسراراً أو accounts أو resources الآن. أسماء متغيرات البيئة ليست اعتمادًا لمزود أو دليلاً على تفعيله.

لم تتم قراءة أي secret value من GitHub أو بيئة التشغيل أو قاعدة البيانات.

## D-051 — توقيت إعادة فتح القرار بعد نهاية الخطة كاملة

لا يُطلب اختيار أي مزود أو منطقة أو خطة أو تكلفة عند إنهاء هذه المرحلة منفردة. جميع اختيارات المزودين تُؤجل إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل. حتى ذلك الوقت يستمر العمل المحايد الذي لا يحتاج مزودًا، وتبقى معايير القبول التي تستلزم مزودًا حقيقيًا أو staging/production operation مؤجلة ولا تُسجل COMPLETE. راجع phases/DECISIONS.md (D-050 وD-051).
