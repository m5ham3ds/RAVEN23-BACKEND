# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 24 نوفمبر 2026
**الفرع الدائم الوحيد:** `main`
**Latest fully CI-verified implementation SHA:** `f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19`
**نطاق CI:** `main` فقط؛ تم حذف TEST وUSERS والتحقق من أن branch listing يعيد `main` وحده.

## 1. ملخص المراحل

| المرحلة | الحالة | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | جرد المصدر والعقود والتقارير محفوظ. |
| 01 — مشروع Laravel قابل لإعادة البناء | COMPLETE | Laravel 13 وComposer lock وبناء Docker قابل للتكرار. |
| 02 — runtime محمول | COMPLETE | FrankenPHP/Caddy وnon-root وliveness/readiness وPHP asset blocking. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | Gitleaks/Trivy وlog redaction وTLS guard وleast-privilege؛ إعدادات المزود والنسخ الاحتياطي قبل الإنتاج. |
| 04 — المصادقة والحسابات والأجهزة | PARTIAL | Backend code/tests موجودة؛ Google Android الحقيقي والبريد الحقيقي في staging غير مثبتين. |
| 05 — أساس بيانات المستخدم | COMPLETE | profile/preferences/devices وAPI/export واختبارات SQLite/PostgreSQL. |
| 06 — المكتبة وسجل المشاهدة والقوائم | COMPLETE | Docker #397: 81 Feature/824 assertions و9 PostgreSQL/219 assertions؛ Auth #19: 20 SQLite/151 assertions و4 PostgreSQL/99 assertions؛ Security #143 ناجح. |
| 07 — المزامنة بين الأجهزة والعمل دون اتصال | IN_PROGRESS | اجتاز اختبار PostgreSQL لثلاث عمليات PHP مستقلة تكتب بالتزامن على جلسة واحدة مع timestamps متعارضة؛ النسخة الأحدث فازت والملخص اليومي بقي متسقاً. أضيفت سياسة Flutter retry/backoff محدودة ومقاييس مدة/محاولات وفحوص owner-generation؛ بقيت دورة device-ID rotation/account switch كاملة وقياسات volume/conflict/latency تحت حمل اصطناعي. |
| 08 — التخزين والإشعارات والمهام | IN_PROGRESS | 08A–08F: private media وS3Mock، encrypted queue/outbox، وPush subscription registration مع token مشفر وHMAC index؛ اجتازت اختبارات FCM/OneSignal على SQLite/PostgreSQL. بقي push sender/delivery وcredentials/staging، S3 staging الحقيقي، mail provider/native idempotency وسياسات retention/cost. |
| 09 — الاشتراكات والصلاحيات | WAITING_EXTERNAL | تتطلب قرارات الخطط والأسعار والاستحقاقات ومزود الدفع. |
| 10 — الجودة والإصدارات | NOT_STARTED | release engineering وcontract/load/rollback gates. |
| 11 — AWS والنشر | WAITING_EXTERNAL / DEPENDENCY | لا توجد بنية AWS أو OIDC deployment؛ تعتمد على مراحل سابقة وقرار التكلفة والمنطقة. |
| 12 — نقل البيانات والتحويل الإنتاجي | WAITING_DEPENDENCY | يتطلب backup/restore rehearsal وموافقة cutover مباشرة. |
| 13 — تشغيل الإنتاج والتعافي | WAITING_DEPENDENCY | يعتمد على Phase12 وقياس RPO/RTO. |

## 2. Phase06 — التنفيذ والاختبارات

- Bookmarks: جدول `user_bookmarks`، owner-scoped، unique per user/media، وإضافة/حذف idempotent.
- Recently watched: `user_recent_media` و`user_sync_revisions`؛ revisions، LWW، tombstones، UTC milliseconds، clock-skew، upload acknowledgements.
- Playlists: `user_playlists`, `user_playlist_items`, `user_playlist_operations`, `user_playlist_changes`؛ ست عمليات، idempotency، base-version conflicts، pagination ثابتة، tombstones.
- Export: `format_version=3` ويضم account/profile/preferences/devices/bookmarks/recently_watched/playlists ويستبعد credentials/tokens/secrets.
- اختبارات Feature وIntegration كلها الآن داخل `tests/` على main؛ أضيف `tests/README.md` لتوضيح SQLite/PostgreSQL المؤقتة.

### أدلة CI النهائية على main SHA `608c94fe057816ee582af7d3e578e14a2bfb3a37`

- Docker [45111592814](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592814): SUCCESS — 81 Feature/824 assertions، 9 PostgreSQL/219 assertions، runtime/readiness/non-root/least-privilege PASS.
- Auth [45111592724](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592724): SUCCESS — 20 SQLite/151 assertions، 4 PostgreSQL/99 assertions.
- Security [45111592767](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592767): SUCCESS — Gitleaks/Trivy/image scan.
- Pint [45111592764](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592764): SUCCESS؛ changed-PHP Pint steps skipped لعدم وجود PHP files متغيرة، بينما Docker strict Pint check نجح.

### قرار Phase06
جميع بوابات الاختبار المطلوبة على main نجحت، لذا Phase06 COMPLETE. Phase07 هي المرحلة التالية المسموح ببدئها.

## 3. سياسة الفروع

- `main` هو الفرع الدائم الوحيد المعتمد، ويحمل التطبيق والمigrations والاختبارات والوثائق وCI.
- `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS` حُذفا بواسطة المستخدم؛ branch listing بعد الحذف يعيد `main` فقط.
- جميع الاختبارات الوظيفية تبقى في `main`؛ حذف refs لم يحذف ملفات الاختبار أو يعيد كتابة التاريخ.
- آخر فحص سابق لم يظهر PRs مفتوحة.

## 4. ما يلزم بعد ذلك

1. متابعة Phase07: إثبات تقارب جهازين/ثلاثة أجهزة، offline retries، account switching/token revocation، cursor loss/full resync، وإصلاح daily-summary conflict.
2. Phase08: storage/mail/push/queue adapters.
3. Phase09: entitlement/subscription model بعد قرارات المنتج ومزود الدفع.
4. Phase10: release engineering/contract/load/rollback gates بعد إكمال نطاقات 01–09.
5. Phase11–13: AWS, cutover, production operations بعد الموافقات والاعتماديات.

## 5. حماية البيانات وحدود التنفيذ

- لم يتم الاتصال بقاعدة Neon/Render الإنتاجية أو قراءة بيانات المستخدمين.
- لم تُقرأ قيم GitHub Secrets أو `.env` أو مفاتيح التطبيق.
- لم تُنفذ migrations إنتاجية أو deploy أو DNS cutover.
- لم ينشأ حساب أو مورد AWS من خلال هذه الأعمال، ولا يوجد في المستودع دليل على AWS deployment/OIDC.
- تم التحقق من وجود `main` فقط؛ refs TEST/USERS غير موجودة.

## 6. تحديث Phase07 — 23 نوفمبر 2026

- أضيف `POST /api/v1/sync/wellness` مع migration والجداول واختبارات SQLite/PostgreSQL.
- جميع بوابات CI الأربع ناجحة على `ef3db49dea53c33622a716f8f46aa1dadccd5109`; التفاصيل في `phases/reports/PHASE-07-PROGRESS-REPORT-2026-11-23.md`.
- Phase07 ما زالت IN_PROGRESS: endpoint وowner isolation وrevisions وtombstones وstable pagination مختبرة، لكن multi-device convergence وoffline recovery وaccount switching وdaily-summary conflict لم تغلق بعد.
- لم يتم الاتصال بقاعدة الإنتاج أو قراءة الأسرار أو تنفيذ migration إنتاجية أو deploy.


## 7. تحديث التنفيذ — 23 نوفمبر 2026، Phase07/08

- آخر SHA اجتاز بوابات CI الأربع: `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- Docker: 92 Feature / 1,046 assertions على SQLite، و12 PostgreSQL Integration / 265 assertions؛ جميع خطوات runtime وleast-privilege وqueue-worker guards نجحت.
- Authentication Regression: 20 SQLite / 151 assertions، و4 PostgreSQL / 99 assertions.
- Pint وSecurity كلاهما SUCCESS؛ Security image scan لم يسجل findings في صورة الإنتاج ضمن تقرير Trivy.
- Phase07 ما زالت IN_PROGRESS مع تغطية إضافية للـretry/full-resync/device-revoke/daily aggregation؛ تبقى اختبارات 3 أجهزة والتزامن المتداخل وdevice-ID rotation وقياس sync latency/retry.
- Phase08 بدأت بشريحة mail queue فقط؛ لا يوجد مزود بريد فعلي ولا تخزين S3 أو push adapter مكتمل.
- البريد ما زال معطلاً في الإعدادات النموذجية: `RAVEN23_AUTH_MAIL_ENABLED=false` و`MAIL_MAILER=log`.
- لا production DB/secrets/deploy/DNS/AWS resources.


## 8. تحقق لاحق وإصلاح حوكمة CI — 23 نوفمبر 2026

- رأس main الذي خضعت عليه هذه المراجعة: `729163a909679cf50cd0cd1275dcd9ab8f7061f6`؛ آخر SHA برمجي اختُبر بالكامل قبل تعديلات حوكمة CI: `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- تم فحص تشغيلات CI الأربعة على 729163a: Docker #45205731260 SUCCESS، Security #45205731207 SUCCESS، Authentication #45205731245 SUCCESS، Pint #45205731265 SUCCESS. Pint تخطى فقط خطوات فحص/تنسيق الملفات المتغيرة لعدم وجود PHP changes؛ Docker strict Pint على Wellness controller نجح.
- تمت مراجعة مواصفات Phase07 وPhase08 وتقاريرهما؛ المرحلتان تبقيان IN_PROGRESS ولا يوجد أساس لبدء Phase09.
- حُذفت سبعة workflows تستهدف Render production مباشرة: ستة workflows لإنشاء/تسجيل دخول/حذف حسابات إنتاج اصطناعية، وworkflow route probe يرسل POST إلى production. السبب منع CI من كتابة بيانات على الإنتاج عند تعديل ملفات workflow نفسها.
- لم يتم تشغيل هذه workflows أو إرسال طلب إلى Render، ولم تُقرأ GitHub Secrets ولم يتم لمس قاعدة الإنتاج أو تنفيذ deploy.


## 9. تحديث Phase08 والتحقق من رأس main — 24 نوفمبر 2026

- رأس `main` المرصود: `d0aa6d4a0e467fe6d72619858b13ef30a466722e`، والالتزام الأب `958a8d5f369d10b4b2a4830fde4fd3882b90e897`.
- التحقق من وجود فرع `main` وحده؛ لا توجد فروع أخرى أو PRs مفتوحة.
- CI على SHA نفسه: Docker #45289473199 SUCCESS؛ Authentication #45289472959 SUCCESS؛ Pint #45289473303 SUCCESS؛ Security #45289472973 SUCCESS.
- Docker: نجحت خطوات SQLite feature وPostgreSQL integration، وPint الصارم، وqueue-worker safety guards، وفحوص runtime وPostgreSQL least-privilege.
- اختبار `test_database_worker_executes_private_media_cleanup_and_job_is_idempotent` يشغّل database queue worker مرة واحدة، ويتحقق من حذف object وعدم وجود jobs أو failed_jobs متبقية، ثم يثبت أن تكرار DeletePrivateObjectJob بعد اختفاء object عملية no-op ناجحة.
- فشل تاريخي على SHA `dfdc82f`: Security وPint وDocker. أُصلحت صياغة الاختبار في `f4a6440`، ونجحت البوابات الأربع على `f4a64407`، ثم أضيف اختبار worker التنفيذي واجتازت البوابات الأربع مجدداً على `d0aa6d4`.
- Phase08 تبقى IN_PROGRESS. 08A–08D منفذة ومختبرة؛ S3-compatible integration، external mail provider/staging، push adapter/Flutter contract، outbox/provider idempotency، وretention/capacity/cost policy لم تثبت بعد.
- لا يوجد دليل على تشغيل cleanup الفعلي على تخزين مستخدمين؛ الاختبار يستخدم filesystem fake. dry-run هو الافتراضي، والتنظيف المجدول معطل ما لم يفعّل صراحة.
- لا AWS credentials أو موارد مدفوعة أو SMTP/FCM خارجي أو قاعدة إنتاج أو deploy تم استخدامها.
- Phase07 تبقى IN_PROGRESS ببنودها الأربعة غير المغلقة؛ Phase09 تنتظر قرارات الخطط والاستحقاقات والدفع.


## 11. تحديث Phase08 Outbox/S3 — 24 نوفمبر 2026

- آخر SHA برمجي مرصود واجتاز بوابات CI الأربع: `8f34a0b0b725eb6704149154e2558eed81bc497f`.
- Docker #45296772477 SUCCESS: SQLite Feature 113/1,232؛ PostgreSQL Integration 18/311؛ اختبار S3Mock منفصل 1/8.
- Authentication #45296772381 SUCCESS: SQLite 20/172؛ PostgreSQL 4/99. Pint #45296772496 SUCCESS؛ Security #45296772309 SUCCESS.
- اختبارات PostgreSQL الجديدة لـOutbox تثبت HMAC uniqueness، التراجع دون dispatch، واستعادة claim منتهية مع عدم إعادة دفع claim نشطة.
- أضيف Outbox مشفر transactional لرسائل التحقق وإعادة تعيين كلمة المرور، مع DB::afterCommit، worker lease/retry، وrelay مجدول كل دقيقة. يوجد header idempotency ثابت لكن لا توجد ضمانة SMTP dedupe من دون دعم المزود.
- S3Mock اختبر put/get/private/list/delete عبر Flysystem adapter؛ لا يوجد real bucket/provider staging أو AWS credentials.
- Phase08 تبقى IN_PROGRESS: external mail provider/native idempotency، Flutter push adapter، real S3 staging، retention/capacity/cost policy ما زالت مفتوحة.
- Phase07 تبقى IN_PROGRESS؛ Phase09 تنتظر قرارات الخطط والاستحقاقات والدفع. لا production DB/secrets/deploy أو موارد مدفوعة مستخدمة.

## 12. تحديث Phase07/08 — 24 نوفمبر 2026

- آخر تنفيذ برمجي اجتاز بوابات CI الأربع على SHA df390286cbe2170d4b18a96dad06835379091019.
- Docker [45320144852](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144852): SUCCESS — SQLite Feature 118/1,332 assertions؛ PostgreSQL Integration 21 passed/394 assertions مع اختبار واحد skipped؛ S3Mock منفصل 1/8.
- Authentication [45320144671](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144671): SUCCESS. Pint [45320144645](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144645): SUCCESS.
- Security [45320144702](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144702): SUCCESS؛ Gitleaks وTrivy وصورة الإنتاج وبوابة fixable HIGH/CRITICAL ناجحة.
- Phase07: أضيف تقارب ثلاثة أجهزة مع تحديثات قديمة وحذف tombstone ثم استئناف أحدث؛ الأجهزة الثلاثة تسحب النتيجة النهائية نفسها على SQLite وPostgreSQL. أضيف PostgreSQL stable pagination مع كتابة جديدة committed بين الصفحات، واختبار cursor تالف ثم full resync. هذه اختبارات interleaving وليست إثباتًا لتشغيل معاملات متوازية فعليًا.
- Phase07 تبقى IN_PROGRESS: اختبار race بمعاملات متوازية، دورة device-ID rotation/account-switch كاملة من Flutter إلى Backend، وتوثيق retry/backoff وقياسات latency/volume/conflict تحت حمل اصطناعي.
- Phase08: اكتملت شريحة 08F لتسجيل FCM وOneSignal subscriptions وفق عقد Flutter؛ token يخزن مشفرًا ويُفهرس عبر HMAC hash، والتسجيل idempotent وowner-scoped، مع نقل ارتباط token للحساب الذي يسجل الدخول حالياً. اختبارات SQLite وPostgreSQL تؤكد التشفير والتكرار وتبديل المالك وحصر الإلغاء.
- Phase08 تبقى IN_PROGRESS؛ التسجيل لا يعني إرسال Push أو وصول إشعار. لا provider adapter أو credentials أو إرسال خارجي مفعل. S3 الحقيقي وmail provider وprovider-native idempotency وretention/cost ما زالت مفتوحة.
- Phase09 تبقى WAITING_EXTERNAL لقرارات الخطط والأسعار والاستحقاقات ومزود الدفع.
- branch listing ظل main وحده ولا توجد PRs مفتوحة. لا إنتاج DB أو secrets أو deploy أو DNS cutover أو موارد AWS مدفوعة استُخدمت.


## 13. تحديث Phase07 وتحقق Flutter — 24 نوفمبر 2026

- رأس Backend main الذي اجتاز بوابات CI الأربع: `f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19`. الفرع الوحيد في المستودع هو main، ولا توجد PRs مفتوحة.
- أضيف `tests/Fixtures/ConcurrentWellnessSyncWorker.php` لتشغيل Laravel في ثلاث عمليات PHP مستقلة. كل عملية تجهز اتصال PostgreSQL منفصلاً وتنتظر حاجزاً مشتركاً ثم ترسل تحديثاً متعارضاً إلى `/api/v1/sync/wellness`.
- اختبار `test_postgresql_parallel_device_uploads_converge_to_the_newest_session_version` نجح: فازت النسخة ذات timestamp 5000، بقي سجل جلسة واحد، والملخص اليومي النهائي 60,000 ms وجلسة واحدة.
- فشل المحاولة الأولى كان في bootstrap عامل الاختبار: استُدعيت خدمة db قبل تمهيد Laravel. أضيف طلب داخلي إلى /up قبل إنشاء اتصال PostgreSQL، وأضيفت رسائل تشخيص للعامل.
- Flutter commit `027f51a3a0fb3f0301e83eb3c71a19c045984447`: retry حتى 3 محاولات للأخطاء العابرة فقط، backoff أُسّي 1/2/4s (حد 8s)، Retry-After محدود لخطأ 429، وعدم retry لأخطاء المصادقة/التحقق/التعارض. Owner-generation يوقف النتيجة القديمة عند تبديل الحساب.
- مقاييس العميل الحالية داخل الذاكرة: عدد محاولات آخر دورة، مدة آخر دورة بالميلي ثانية، وعدد دورات الفشل المتتالية. لم تُضف بعد إلى telemetry خادمية أو تخزين دائم، ولم ينفذ اختبار حمل لإنتاج percentiles أو conflict rate.

### CI proof — Backend SHA `f0b9823c2ab5dba2ddcc9b92600abe6ac1476c19` (24 November 2026)

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45326348001](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348001) | SUCCESS — SQLite Feature 118 / 1,332 assertions; PostgreSQL Integration 22 passed / 417 assertions, 1 skipped; S3Mock 1 / 8; runtime and least-privilege checks PASS |
| Authentication Regression | [45326348002](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348002) | SUCCESS — SQLite 20 / 172 assertions; PostgreSQL 4 / 99 assertions |
| Pint Format | [45326347992](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326347992) | SUCCESS |
| Security Scans | [45326348012](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45326348012) | SUCCESS — Gitleaks, filesystem/image scans and fixable HIGH/CRITICAL gate PASS |

الاختبار الوحيد skipped في PostgreSQL suite العامة هو اختبار S3 الاختياري؛ Docker يشغل S3Mock منفصلاً وقد نجح 1/8.

### Flutter evidence — SHA `027f51a3a0fb3f0301e83eb3c71a19c045984447`

- [Android CI / APK Build #45324044184](https://github.com/m5ham3ds/RAVEN23/actions/runs/45324044184): SUCCESS. flutter analyze و487 اختبار Flutter مركّز و25 اختبار Android ABI/offline-download كلها اجتازت.
- Release APK بحجم 47,853,410 bytes (45.64 MiB)، ABI واحد `arm64-v8a`. استخدم البناء `RAVEN23_AUTH_ONLY=true`، لذلك لا يثبت جاهزية كل ميزات التطبيق للإنتاج.

### المتبقي قبل إغلاق Phase07

1. دورة device-ID rotation/account switch كاملة من العميل إلى Backend، تشمل إعادة تهيئة checkpoints والتحقق من عدم إرسال طلب/بيانات متأخرة تخص المالك السابق.
2. حمل اصطناعي قابل للقياس وتسجيل latency/volume/conflict rate؛ المقاييس المحلية الحالية وحدها لا تكفي.

**الحالة: IN_PROGRESS.**
