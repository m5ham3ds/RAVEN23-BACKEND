# جرد متغيرات البيئة وأسماء الأسرار

**الحالة:** PARTIAL — جرد من source/config/workflows و.env.example؛ لم تُقرأ قيم أسرار أو GitHub Secret values.

## 1. Backend runtime

| الاسم | الاستخدام | مطلوب/ملاحظات |
|---|---|---|
| APP_KEY | تشفير Laravel | مطلوب؛ يجب أن يبقى ثابتًا بين deployments |
| APP_ENV | وضع البيئة | production/testing |
| APP_DEBUG | عرض أخطاء Laravel | يجب أن يكون false في الإنتاج |
| APP_URL | عنوان Laravel | إعداد أساسي للتطبيق |
| LOG_CHANNEL | قناة السجل | Render يستخدم stderr حاليًا |
| PORT | منفذ HTTP | Render متغير؛ start.sh الافتراضي 10000 |
| DB_CONNECTION | اتصال قاعدة البيانات | يفرض pgsql |
| DB_URL | URI قاعدة PostgreSQL لترافيك التطبيق | مطلوب حاليًا؛ Neon pooled |
| DB_MIGRATION_URL | URI مباشر للـmigrations | مطلوب حاليًا؛ Neon direct |
| DB_SSLMODE | وضع TLS لقاعدة PostgreSQL | default في config هو prefer؛ URLs الحالية تضيف sslmode=require |
| CACHE_STORE | مخزن cache | start.sh يفرض file |
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
- Health endpoint يقرأ RAVEN23_SOURCE_COMMIT وRENDER_GIT_COMMIT وRENDER_INSTANCE_ID.
- docker/runtime-db-diagnostics.php: RAVEN_DIAG_MODE وRAVEN_DIAG_INSPECT_USER_IDS وDB_URL وDB_CONNECTION وAPP_ENV.
- docker/start.sh يقرأ APP_KEY وDB_URL وDB_MIGRATION_URL وPORT ويضبط APP_ENV وAPP_DEBUG وLOG_CHANNEL وDB_CONNECTION وCACHE_STORE.
- Render workflows تستخدم عنوان خدمة Render ثابتًا في المصدر، لا Secret. GitHub workflow diagnostics تستخدم أسماء secrets الموضحة أدناه.

## 5. أسماء GitHub Secrets التي ظهرت في workflows

- NEON_DB_URL
- NEON_DB_MIGRATION_URL

لم تتم قراءة قيم هذه الأسرار ولا يمكن تأكيد محتواها أو استمرار صلاحيتها. لا تُكتب القيم داخل المستودع أو التقارير.

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
