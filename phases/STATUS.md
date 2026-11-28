# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 28 نوفمبر 2026
**الفرع الدائم الوحيد:** `main`
**Latest main CI review:** `be14f83568e5abd51a9628d21b4e8a0c55f23d13` — Docker #686, Security #432, Authentication #308, Pint #430: all SUCCESS on this exact SHA. Docker: SQLite 151 tests/1,719 assertions; PostgreSQL 31 tests/546 assertions with one optional skip; S3Mock 1/8; encrypted full-schema CI restore, isolated rollback and trusted-main signing 17/17 passed. Security #432: Gitleaks, filesystem and production-image scans passed. Authentication #308: SQLite 20/172 and PostgreSQL 4/99. Pint #430: changed-PHP steps skipped because this commit contains no PHP changes; strict Pint in Docker passed. Artifacts: release bundle #15245482428, synthetic restore #15246550109, security report #15243606410. IaC #31 remains SUCCESS on unchanged baseline `dbd281c545f5a2da5a6816afccebd4d929a02b70` (30/30); HTTP smoke remains manual/not run because no approved staging URL. Runtime baseline remains `6750eac566c513c4fc97951f1b80cb7f54cd0c0a`; all later commits change `phases/` documentation only.
**نطاق CI:** `main` فقط؛ تم حذف TEST وUSERS والتحقق من أن branch listing يعيد `main` وحده.

## 1. ملخص المراحل

| المرحلة | الحالة | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | جرد المصدر والعقود والتقارير محفوظ. |
| 01 — مشروع Laravel قابل لإعادة البناء | COMPLETE | Laravel 13 وComposer lock وبناء Docker قابل للتكرار. |
| 02 — runtime محمول | COMPLETE | FrankenPHP/Caddy وnon-root وliveness/readiness وPHP asset blocking. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | Gitleaks/Trivy وlog redaction وTLS guard وleast-privilege؛ إعدادات المزود والنسخ الاحتياطي قبل الإنتاج. |
| 04 — المصادقة والحسابات والأجهزة | PARTIAL | Backend code/tests موجودة؛ Google Android الحقيقي والبريد في staging مؤجلان إلى ما بعد نهاية خطة تطوير Backend بالكامل (D-051). |
| 05 — أساس بيانات المستخدم | COMPLETE | profile/preferences/devices وAPI/export واختبارات SQLite/PostgreSQL. |
| 06 — المكتبة وسجل المشاهدة والقوائم | COMPLETE | Docker #397: 81 Feature/824 assertions و9 PostgreSQL/219 assertions؛ Auth #19: 20 SQLite/151 assertions و4 PostgreSQL/99 assertions؛ Security #143 ناجح. |
| 07 — المزامنة بين الأجهزة والعمل دون اتصال | COMPLETE | Flutter Android CI على `d9418ea`: 496 Flutter tests و25 Android ABI/offline tests؛ Backend Docker على `1d354f7`: 8 عمال PostgreSQL مستقلين، timestamp=5000 هو الفائز، daily aggregate متسق، وقياسات request/response/latency/conflict موثقة في تقرير الإغلاق. جميع بوابات Backend الأربع وAndroid CI ناجحة. |
| 08 — التخزين والإشعارات والمهام | IN_PROGRESS | الأساس المحايد للمزود و08A–08K منفذ ومختبر؛ real S3/mail/Push وretention/cost مؤجلة إلى ما بعد التطوير؛ الإرسال معطل افتراضيًا. |
| 09 — الاشتراكات والصلاحيات | IN_PROGRESS (09A–09F COMPLETE برمجيًا وCI) | قرارات الخطط/الأسعار/الشروط ومزود الدفع مؤجلة إلى ما بعد تطوير Backend؛ لا مشتريات مفعلة. |
| 10 — الجودة والإصدارات | IN_PROGRESS (10A/10C/10D/10E/10F/10G COMPLETE؛ 10B DEFERRED_BY_OWNER) | تم تأجيل إعداد حماية `main` وrequired checks يدوياً إلى ما بعد اكتمال خطة تطوير Backend؛ لا يوقف التأجيل تطوير الميزات، لكنه يبقى شرطاً قبل Phase11B2 والنشر. |
| 11 — البنية التحتية المحمولة والنشر | PAUSED / DEFERRED_BY_OWNER عند الحد الخارجي وفق D-050/D-051؛ 11A و11B1 مكتملان تقنيًا، 11C neutral guardrails 30/30 مثبتة، أما 11C adapters و11B2 و11D–11F فتنتظر ما بعد خطة Backend وقرار الخدمات و10B اليدوي. | لا provider/region/cost/resources/backend/registry push/apply/deploy. لا تُعلن Phase11 COMPLETE؛ تستأنف الأعمال الخارجية بعد المراجعة الموحدة والموافقات. |
| 12 — نقل البيانات والتحويل الإنتاجي | IN_PROGRESS — 12A provider-neutral preparation. Six SQLite stores and SharedPreferences journals are mapped. Synthetic device merge/snapshot-delta acceptance and a draft legacy Firebase session-exchange contract are documented; neither client implementation nor exchange API/tests have run. The exchange route is absent, Firebase UID mapping and account-linking policy are unresolved, and local owner-rekey retry safety remains an app-side blocker. No live-device/source-of-truth evidence. | 12B real source/target backup/restore and 12C cutover/hypercare remain WAITING_EXTERNAL/OWNER_APPROVAL; no production DB/device data/secrets accessed, and no source/target/provider approved. |
| 13 — تشغيل الإنتاج والتعافي | IN_PROGRESS (13A design baseline reviewed; 13B WAITING_DEPENDENCY) | تم توثيق ومراجعة runbooks/SLI/SLO/DR design على الكود وCI الحالي وفق D-053، مع إبقاء كل القيم غير المعتمدة TBD. لا dashboards أو alerts أو backup schedules أو provider integrations أو production operations. 13B لا تبدأ قبل Phase12 COMPLETE والقرارات/الموافقات المطلوبة. |

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


## 14. إغلاق Phase07 والتحقق من Phase08G–08I — 24 نوفمبر 2026

### الحالة المعتمدة

- Phase07: **COMPLETE**. هذا التحديث هو المرجع الأحدث ويلغي حالات IN_PROGRESS الواردة في تقارير المتابعة التاريخية السابقة.
- Phase08: **IN_PROGRESS** بعد تنفيذ 08A–08I. لا يُدّعى إرسال Push حقيقي أو mail خارجي أو اتصال S3 بمزود فعلي.
- Branch listing: `main` فقط؛ لا توجد PRs مفتوحة.

### إغلاق Phase07

- App `m5ham3ds/RAVEN23` SHA `d9418ea5d83658ee82a9bc04062f97a6c9452f65`: Android CI [45328849434](https://github.com/m5ham3ds/RAVEN23/actions/runs/45328849434) SUCCESS؛ 496 Flutter tests و25 Android ABI/offline tests؛ APK واحد `arm64-v8a` بحجم 47,853,410 bytes (45.64 MiB).
- Backend concurrent sync evidence — Docker [45411252479](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252479) على SHA `1d354f7`: ثمانية PHP worker processes واتصالات PostgreSQL مستقلة؛ request bytes=4,191، response bytes=546، متوسط latency=166.538 ms، الحد الأقصى=209.686 ms، stale conflicts=5/8 (62.5%)، winning timestamp=5000؛ السجل النهائي والملخص اليومي متسقان.
- اختبارات Flutter تغطي ثبات install `device_id` عبر sign-out/account switch، وتمريره من Auth Injector، رفض payload للمالك السابق، منع إحياء بيانات الحساب السابق، وإيقاف retry/backoff عند owner-generation change.
- حدود القياس: concurrency workload صناعي صغير داخل CI، وليس benchmark إنتاجيًا أو SLO.

### Phase08G–08I CI proof على Backend SHA `1d354f7`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45411252479](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252479) | SUCCESS — SQLite Feature 125 / 1,382 assertions؛ PostgreSQL Integration 24 passed / 493 assertions، 1 optional skipped؛ S3Mock 1 / 8 |
| Authentication Regression | [45411252843](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252843) | SUCCESS |
| Pint Format | [45411252483](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252483) | SUCCESS |
| Security Scans | [45411252485](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252485) | SUCCESS |

- Docker أكمل strict Pint، worker guards، PostgreSQL least-privilege، migration role منفصل، liveness/readiness دون DB، non-root HTTP runtime وPHP asset blocking.
- فشل Docker السابق على commit وسيط `acea8d1` كان اختلاف Pint فقط في Relay؛ أُصلح بتطبيق صيغة Pint والتحقق من تطابق الملف، ثم اجتازت SHAهات لاحقة strict Pint.
- Authentication Regression وPint وSecurity وDocker على SHA النهائي أعلاه كلّها SUCCESS.

### ما بقي خارجيًا في Phase08

1. اختيار مزود S3-compatible/حساب staging واختبار الاتصال بمفاتيح اختبار معتمدة؛ لا bucket أو AWS credentials أو مورد مدفوع أُنشئ.
2. اختيار mail provider وإعداد action/deep-link والتحقق من staging، ثم اعتماد provider-native idempotency قبل ادعاء exactly-once.
3. اختيار FCM أو OneSignal، وتركيب adapter فعلي واختبار وصول على Android/TV؛ Push ما زال disabled-by-default ولا توجد credentials.
4. موافقة سياسة retention، quota/capacity، cost وoutbox audit retention.

**Phase08 تظل IN_PROGRESS، ولا يجوز الانتقال إلى Phase09 قبل قرار المنتج/الاشتراكات والدفع، أو تخطي اعتمادياتها.**


## 20. Phase09F وبدء Phase10A — 24 نوفمبر 2026

- آخر SHA اجتاز البوابات الأربع: 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060.
- Docker [45450292965](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292965): SUCCESS — SQLite 144/1,508؛ PostgreSQL 31/541 مع S3 skip اختياري؛ S3Mock 1/8؛ runtime/least-privilege/worker checks PASS.
- Authentication [45450293025](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293025): SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Pint [45450293004](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293004): SUCCESS.
- Security [45450292826](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292826): SUCCESS.
- 09F COMPLETE. Phase09 overall remains IN_PROGRESS بسبب القرارات التجارية والدفع غير المعتمدة.
- 10A started: remove obsolete S3 lock bootstrap; audit actual workflow inventory and repository protection.
- main is the only branch but protected=false and rulesets are empty. This remains open for 10B; no production DB/secrets/deploy/AWS changes.



## 21. Phase10A closeout / Phase10B governance / Phase10C start — 24 نوفمبر 2026

- 10A COMPLETE على SHA 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63: Docker #45455970338 SUCCESS (SQLite 144/1,508؛ PostgreSQL 31/541 مع optional skip واحد؛ S3Mock 1/8)، Auth #45455970334 SUCCESS (SQLite 20/172؛ PostgreSQL 4/99)، Pint #45455970270 SUCCESS، Security #45455970393 SUCCESS.
- لم يكن هناك فشل في هذه الدفعة ولا تعديل runtime/API/schema.
- 10B IN_PROGRESS: branch API يؤكد main فقط وprotected=false؛ rulesets فارغة. تفعيل required checks يحتاج Admin settings write غير متاح هنا. تم توثيق check contexts والتداخل دون إعادة تشكيل workflows قبل قرار enforcement.
- Pint standalone workflow ليس gate تنسيق صارمًا؛ strict Pint داخل Docker هو gate. Auth Regression متداخل جزئيًا لكنه محفوظ مؤقتًا كفحص متخصص.
- 10C IN_PROGRESS: أضيف DatabaseMigrationRehearsalTest.php لتغطية empty schema وsynthetic legacy snapshot (account/token/cache/job preservation + data backfill)؛ pending CI.
- لا production DB أو secrets أو migrations إنتاجية أو deploy أو AWS resources.


## 22. معالجة فشل 10C الأول — 24 نوفمبر 2026

- أول Docker run على SHA fda51c0 فشل في Feature suite: 1 failed، 145 passed، 1,537 assertions.
- السبب المحدد من PHPUnit line 104: المتوقع username `legacy_migration_user` لكن ترحيل `Raven23AuthSchema::generatedUsername()` أعاد `legacy_migration_user_16` باستخدام base-36 id للحساب 42. هذا خطأ في توقع الاختبار وليس فشل ترحيل بيانات.
- Pint run على SHA ذاته توقف أثناء بناء image التطويرية لأن البناء شغّل Feature suite نفسها؛ خطوة Pint لم تُنفذ. Authentication على SHA نفسه نجح؛ Security بقيت أثناء بناء الصورة.
- أصلحنا assertion username إلى `legacy_migration_user_16`، وأكدنا email assertion ليطابق `legacy_migration_user@example.test`. CI الجديد مطلوب لإغلاق 10C.


## 23. Phase10C closeout and Phase10D start — 24 November 2026

- 10C COMPLETE على Backend SHA b8c0bb91cea76db003501e81ec30dce09e3bf858.
- Docker [45495207983](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207983): SUCCESS — SQLite Feature 146/1,548؛ PostgreSQL 31/541 مع optional skip واحد؛ S3Mock 1/8. اختبارا migration empty schema وlegacy snapshot كلاهما PASS.
- Authentication [45495207832](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207832): SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Pint [45495207864](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495207864): SUCCESS. Security [45495208148](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45495208148): SUCCESS.
- 10D started on main: 39-route API v1 manifest, human-readable contract, and ApiV1ContractTest added. CI pending on the contract-test SHA.
- 10B remains IN_PROGRESS: main is unprotected and rulesets empty; GitHub settings write is unavailable in this connection.
- No production database, secret, production migration, deployment or AWS resources touched.


## 25 نوفمبر 2026 — Phase10D closeout / Phase10E start

- Latest fully CI-verified Backend SHA: `44ed546c9881bc2d7ca16d6c97024c6ae77ba047`.
- Phase10D COMPLETE: Docker [45503594954](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594954) SUCCESS — SQLite Feature 150/1,704؛ PostgreSQL Integration 31/541 مع optional skip واحد؛ S3Mock 1/8؛ HTTP runtime guards PASS.
- Authentication [45503594980](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594980)، Pint [45503594941](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594941)، Security [45503594952](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45503594952): جميعها SUCCESS. Trivy image summary Clean وبوابة fixable HIGH/CRITICAL PASS.
- API contract manifest يغطي 39 route؛ اختبارات ApiV1ContractTest الثلاثة نجحت. لم يتغير controller أو route أو migration أو runtime behavior.
- فشل 10D الأول كان assertion بصيغة token غير مطابقة لمخرجات Sanctum؛ صُحح regex في 44ed546 واجتازت الاختبارات بعد ذلك.
- 10E IN_PROGRESS: توسيع اختبار PostgreSQL المتوازي ليقيس P50/P95 وwall-clock duration وthroughput، إلى جانب request/response bytes وconflict rate.
- 10B remains IN_PROGRESS: main unprotected وrequired-check settings تحتاج صلاحية repository Admin.
- لا production DB أو secrets أو deployment أو AWS resources تم استخدامها.


## 25 نوفمبر 2026 — Phase10E closeout / Phase10F start

- Latest fully CI-verified Backend SHA: `f912dd2479a5f86471e1e8999b2d36c7611d41cb`.
- Phase10E COMPLETE: Docker [45504724906](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724906)، Authentication [45504724789](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724789)، Pint [45504724822](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724822)، Security [45504724860](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45504724860): جميعها SUCCESS على SHA نفسه.
- PostgreSQL synthetic run: 8/8 successful workers؛ 188.248ms wall-clock؛ 42.497 req/s؛ 4,191 request bytes؛ 546 response bytes؛ P50 143.150ms؛ P95 177.715ms؛ average 143.856ms؛ max 177.715ms؛ 5/8 stale conflicts (62.5%)؛ winning timestamp=5000.
- PostgreSQL Integration: 31 passed / 546 assertions مع optional skip واحد؛ SQLite Feature 150/1,704؛ S3Mock 1/8.
- 10F IN_PROGRESS: Docker workflow now creates a SHA-bound local image tag, records image config digest and Docker archive SHA-256, generates CycloneDX SBOM from the tested image, prepares build materials provenance, and requests signed GitHub artifact provenance/SBOM attestations. CI is pending.
- No registry push, packages:write, production DB, production secrets, deployment or AWS resource.
- Phase10B remains OPEN: main protection/required checks require repository Admin access.


## 24. Phase10F closeout / Phase10G start — 25 November 2026

### Phase10F COMPLETE — verified on SHA \`c01a4bad1e4007dd8c9cb46bb56d5344f188f06c\`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45513822710](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710) | SUCCESS — SQLite Feature 150/1,704؛ PostgreSQL Integration 31/546 مع optional skip واحد؛ S3Mock 1/8؛ runtime/least-privilege PASS؛ release image evidence generated and uploaded |
| Authentication Regression | [45513822753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822753) | SUCCESS — SQLite 20/172؛ PostgreSQL 4/99 |
| Pint Format | [45513822755](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822755) | SUCCESS |
| Security Scans | [45513822742](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822742) | SUCCESS — Gitleaks/source scan PASS؛ Trivy production image Clean؛ fixable HIGH/CRITICAL gate PASS |

- Image archive was generated from the exact tested local image, CycloneDX SBOM generated by Trivy, SHA-256 checks verified, and both signed GitHub artifact attestations succeeded.
- Provenance attestation: [71890026](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890026). SBOM attestation: [71890037](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/71890037).
- Immutable artifact: [14975768921](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45513822710/artifacts/14975768921), 276,676,355 bytes, digest \`sha256:f50b53dd43a3c9f261d5da512adf9a005a5e45553870a9447533ecc931b193ed\`, 30-day retention.
- First 10F Docker attempt failed because \`sha256sum --check\` ran from repository root while filenames were relative to \`release-evidence/\`; corrected in the workflow to run the check within that directory. The new SHA passed all gates and uploaded the evidence.

### Phase10G implementation started

- Pin the last fully CI-verified previous release: \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\` (Docker/Auth/Pint/Security runs #45504724906/#45504724789/#45504724822/#45504724860).
- Rebuild that source commit from its own Git tree, run current and rollback images in disposable non-root containers, and route loopback HTTP traffic through a local proxy.
- Simulate candidate failure by stopping the current container; expect proxy 502; atomically switch the route to the previous image and require HTTP 200 from the rollback backend.
- Save both release archives, CycloneDX SBOMs, per-image provenance/SBOM attestations, signed release manifest, rehearsal report and SHA256SUMS in a single 30-day CI artifact.
- 10G remains IN_PROGRESS until all four gates and the full artifact/rollback proof succeed. 10B remains OPEN because branch protection/rulesets require repository Admin write access.
- No production database, production secrets, registry push, deployment or AWS resources used.


## 25. Phase10G — معالجة فشل SBOM في rollback — 25 نوفمبر 2026

- CI على SHA \`018edc6b7e5a17d2146982b536743152a6ae440f\`: Authentication #45516843920 SUCCESS؛ Pint #45516844066 SUCCESS؛ Security #45516843974 SUCCESS مع Trivy production image Clean.
- Docker #45516843876 FAILED داخل خطوة \`Rebuild previous known-good release and rehearse isolated traffic rollback\`.
- السجل يثبت نجاح build لصورة \`f912dd2479a5f86471e1e8999b2d36c7611d41cb\` وكتابة Docker archive؛ الفشل وقع عند أمر Trivy 0.76.2 لتوليد rollback CycloneDX SBOM، بعد رسائل تعرف OS Debian 13.8 وملفي حزم لغوية، ثم خرجت الوظيفة 1 دون تشخيص إضافي. لم تبدأ حاويات HTTP أو اختبار 200→502→200.
- التعديل: توليد SBOM rollback بواسطة Anchore Syft v1.57.1 من Docker archive نفسه \`docker-archive:\` لتجنب فشل أداة SBOM السابقة، والتحقق باستخدام jq من \`bomFormat=CycloneDX\` ووجود قائمة components غير فارغة. provenance والـrelease manifest يحفظان اسم المولد وعدد components.
- فحص Trivy vulnerabilities/misconfig/secret على صورة الإنتاج يظل دون تغيير؛ Security run السابق نجح وملخص Trivy كان Clean.
- ينتظر SHA التصحيح إعادة Docker/Auth/Pint/Security وإثبات rollback rehearsal وattestations وrelease bundle. 10G لا تزال IN_PROGRESS. لم تمس قاعدة إنتاج أو secrets أو نشر خارجي.


## 26. Phase10G closeout / Phase11A provider-neutral design start — 25 November 2026

### Phase10G — COMPLETE on main SHA `a86fb3abd747a4110caa42b105dbb290b89f9e23`

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45625075036](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036) | SUCCESS |
| Authentication Regression | [45625075096](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075096) | SUCCESS |
| Pint Format | [45625075137](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075137) | SUCCESS |
| Security Scans | [45625075229](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075229) | SUCCESS |

- Rollback proof: current route 200; stopped candidate 502; rollback route 200. Previous target: `f912dd2479a5f86471e1e8999b2d36c7611d41cb`.
- Rollback Syft v1.57.1 CycloneDX SBOM: 5,720 components; SHA-256 `3fab41bb3f765e11b23c8b13037ca50b2212e5cc64281c03b79aed52988d16e3`.
- Current/rollback image provenance and SBOM attestations plus release-manifest signature: [72175380](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175380), [72175401](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175401), [72175434](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175434), [72175458](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175458), [72175490](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72175490).
- Release artifact [15026689226](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45625075036/artifacts/15026689226): 562,608,814 bytes, digest `sha256:8b0a10585fc6f7542660e1cedb8ecb362b4b86f625a09ef7a73aed7213f9162c`, retention 30 days.
- All release evidence checksums passed. No shell command-substitution errors remained after the printf correction.
- 10B remains OPEN: branch API reports `protected=false`; Repository Admin permission is required.

### Phase11 — design-only start

- 11A is IN_PROGRESS as a provider-neutral architecture and option-comparison track.
- Candidate A: integrated AWS architecture; candidate B: multi-provider portable architecture. Neither is selected.
- Render and Neon remain recorded as transitional/current references, not permanent architectural decisions.
- No cloud account, paid resource, network, database, object bucket, registry push, OIDC deployment role, secret, DNS change or production deployment was created.
- Phase08 and Phase09 remain IN_PROGRESS for their external staging/product decisions. Phase04 real Google/mail integration remains PARTIAL. These remain relevant gates when their capabilities are used in staging or production.
- Phase11 provisioning/deployment remains WAITING_EXTERNAL pending provider/region/recurring-cost approval and required branch-protection/deployment controls.



## 27. Phase11A — portable object-storage configuration verified — 25 November 2026

- Main SHA: `3659843cb7bfbfebba6497d73be1a0dbf9d55440`.
- أضيفت متغيرات تخزين محايدة `RAVEN23_OBJECT_STORAGE_*` لإعداد S3-compatible disk: access key ID، secret access key، region، bucket، URL، endpoint، وpath-style endpoint.
- هذه المتغيرات هي الأولوية؛ أسماء `AWS_*` القديمة تبقى fallback للتوافق مع أي runtime قائم. الافتراضي ما زال `private_media` المحلي، ولا يحدث اتصال خارجي تلقائياً.
- اختبار S3PrivateObjectStorageTest لم يعد يعيد بناء إعداد القرص داخل الاختبار؛ يختبر `config/filesystems.php` الفعلي باستخدام القيم المحايدة التي يمررها CI إلى S3Mock.
- أزيل تعليق Google client ID الذي كان يذكر Render باعتباره مصدراً خاصاً؛ بقي السلوك كما هو.

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45629239131](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629239131) | SUCCESS؛ 150 tests / 1,704 assertions، S3Mock، PostgreSQL roles/migrations، runtime وrollback |
| Authentication Regression | [45629244185](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629244185) | SUCCESS |
| Pint Format | [45629238792](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629238792) | SUCCESS |
| Security Scans | [45629238837](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629238837) | SUCCESS |

- أثبت Docker workflow rollback sequence `200 → 502 → 200`؛ rollback SBOM Syft v1.57.1 يحتوي 5,720 مكوناً.
- توقيعات الأدلة: [current provenance 72188175](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188175)، [current SBOM 72188186](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188186)، [rollback provenance 72188201](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188201)، [rollback SBOM 72188212](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188212)، [manifest signature 72188225](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188225).
- Release artifact [15030387429](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629239131/artifacts/15030387429): 562,609,588 bytes; digest `sha256:3d05ead631a50234a99b4745695c60eb44eb064ca4de34c937f812c42428a1b2`; expires 2026-12-25.

### حدود ما تم إثباته

- S3Mock يثبت توافق API في الاختبار فقط؛ لا يثبت تكافؤ كل مزود في ACL/lifecycle/versioning/retention/metadata أو تكلفة النقل.
- لا يوجد provider أو region مختار، ولا إنشاء bucket أو حساب أو سر أو نشر أو registry push.
- Phase11A تبقى IN_PROGRESS حتى إكمال portability/cost scorecard، وتحديد متطلبات نقل الملفات والنسخ والـegress، ومراجعة الخيارات المتاحة وقت اتخاذ القرار.

## 28. Phase11A scorecard / Phase11B1 trust design — 25 November 2026

- Last fully verified SHA before this docs/CI-trust design change: `634e68b9eddb41b556f1005b37bffe769a9d8b32`.
- Docker [45630955379](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955379), Authentication [45630955656](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955656), Pint [45630955397](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955397), Security [45630955411](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45630955411): SUCCESS on the same SHA.
- Prepared a cost comparison template for AWS integrated and multi-provider architectures. Values and weights remain blank because region, workload, data growth, egress, retention, HA/RPO/RTO and budget are not approved.
- Added data exit and portability requirements for PostgreSQL, private objects, queues/outbox, cache/session, secrets, DNS/TLS, artifacts/registry and backup/restore.
- Began 11B1 design-only trust model. Docker workflow `id-token: write` is for Sigstore attestations via `actions/attest@v4`, not cloud deployment identity.
- Required-check candidates are Docker, Authentication and Security jobs; Pint report is not an independent blocking gate.
- Removed unused `NEON_DB_URL` environment alias from Authentication CI; application uses the generic `DB_URL` contract.
- 10B remains OPEN; main remains `protected=false`. No cloud deployment role, registry push, secret, environment, IaC apply or resource was created.

## 29. Phase11A scorecard / 11B1 trust design — CI verified on 84b87d9 — 25 November 2026

| Gate | Run | Conclusion |
|---|---:|---|
| Docker Validation | [45636103294](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103294) | SUCCESS |
| Authentication Regression | [45636103168](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103168) | SUCCESS |
| Pint Format | [45636103292](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103292) | SUCCESS |
| Security Scans | [45636103186](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103186) | SUCCESS |

- Docker Feature suite: 150 passed / 1,704 assertions.
- S3Mock: `S3PrivateObjectStorageTest` passed 1 / 8 assertions using `RAVEN23_OBJECT_STORAGE_*` and the actual Laravel filesystem configuration.
- PostgreSQL restricted app role/migration role, one-off migration, non-root runtime, liveness/readiness and rollback all passed.
- Rollback evidence: current route HTTP 200; candidate stop yields expected HTTP 502; route switches successfully to previous image. Syft v1.57.1 rollback SBOM: 5,720 components; SHA-256 `7c6339f66e78625ea61f513926922487cd5562a0fbdd074da78fe986a4a82b8e`.
- Sigstore attestations: [current provenance 72207357](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207357)، [current SBOM 72207371](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207371)، [rollback provenance 72207380](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207380)، [rollback SBOM 72207401](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207401)، [manifest signature 72207415](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72207415).
- Release artifact [15033437028](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45636103294/artifacts/15033437028): 562,609,467 bytes; digest `sha256:06a0618604531d035efb6c9a551d9042ab78f163c5fd4d24f615aee2a8ecd9a3`; expiry 2026-12-25.
- All SHA256SUMS entries passed, including release/rollback archives, SBOMs, provenance bundles, manifest, manifest signature and rehearsal report. No shell command errors.
- This is disposable CI/loopback evidence, not real S3 provider staging, cloud load-balancer test or production deployment.
- main remains `protected=false`; Phase10B is still OPEN. Phase11B1 remains design-only; cost scorecard waits for region/workload/retention/budget inputs. No provider, region or paid resources selected or created.


## 30. Phase11B1 trust design closeout — 25 November 2026

تم التحقق من main SHA `1b57b1da178dfd993c5f9bc1e2a19136af797f21`؛ بوابات CI الأربع كلها SUCCESS على SHA نفسه:

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45638169465](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169465) | SUCCESS |
| Authentication Regression | [45638169413](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169413) | SUCCESS |
| Security Scans | [45638169229](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169229) | SUCCESS |
| Pint Format | [45638169219](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45638169219) | SUCCESS |

- هذا SHA كان تحديثًا وثائقيًا فوق application/runtime baseline `84b87d9`؛ لم يتغير كود التطبيق في `1b57b1d`.
- أُغلقت 11B1 على مستوى التصميم فقط: trust flow، claim matrix، فصل CI/attestation/registry/deploy/migration/runtime identities، ومتطلبات digest verification.
- التدقيق وجد أن `.github/workflows/docker-build.yml` يضع `id-token: write` و`attestations: write` و`artifact-metadata: write` على مستوى workflow يعمل على push وpull_request، وأن خطوات attest غير مقيدة بشرط event/ref صريح. هذه فجوة least-privilege موثقة للإصلاح قبل enforcement.
- Gitleaks حاجز فشل؛ Trivy filesystem وimage reports غير blocking بسبب `--exit-code 0`، والعتبة blocking منفصلة للـfixable HIGH/CRITICAL image vulnerabilities فقط. لا يعني SUCCESS أن جميع تقارير Trivy فارغة.
- `main.protected=false`؛ 10B ما زالت OPEN. لم تنشأ Environments أو OIDC cloud roles أو secrets أو registry push أو IaC resources أو نشر.
- 11A cost scorecard ما زالت WAITING_INPUT. 11B2 enforcement تنتظر إغلاق 10B وموافقة المزود والمنطقة والتكلفة.
- أي تغييرات وثائقية تضاف بعد هذا baseline يجب أن تجتاز CI على SHA الجديد قبل وصفها بأنها verified.


## 31. Phase11B1 least-privilege remediation — verified on c17fc84ac0f6acbe4a6d300913871441fb4e7ebe — 26 November 2026

اكتملت معالجة فجوة صلاحيات Docker/attestation التي كشفها تدقيق 11B1. هذا تحديث تنفيذي لحدود الثقة، وليس فتحًا لـ11B2 أو تفويضًا بالنشر.

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45723087433](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433) | SUCCESS — SQLite Feature 150/1,704 assertions؛ PostgreSQL Integration 31/546 assertions مع optional skip واحد؛ S3Mock 1/8؛ strict Pint/runtime/rollback guards PASS |
| Authentication Regression | [45723087469](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087469) | SUCCESS |
| Pint Format Report | [45723087423](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087423) | SUCCESS؛ changed-PHP steps skipped لعدم وجود PHP files متغيرة |
| Security Scans | [45723087484](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087484) | SUCCESS — Gitleaks وTrivy؛ ملخص صورة الإنتاج Clean، وfixable HIGH/CRITICAL gate PASS |

- Docker validation workflow/job يطلب contents: read فقط. صلاحيات id-token: write وattestations: write وartifact-metadata: write أصبحت داخل attest-release فقط، وشرطها main مع push أو workflow_dispatch، وتعتمد على نجاح docker-build. أحداث pull_request لا تحصل على صلاحيات OIDC/attestation.
- عُدّل actions/download-artifact إلى الإصدار المنشور v8.0.2. بذلك زال سبب فشل إعداد وظيفة التوقيع في المحاولة السابقة.
- وظيفة التوقيع تحققت من source/run metadata وrollback وhashes؛ أنشأت تواقيع provenance وSBOM للصورة الحالية وصورة التراجع، ووقّعت release manifest، وفحصت checksums النهائية.
- Attestation records: [image provenance 72416097](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416097)، [image SBOM 72416109](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416109)، [rollback provenance 72416119](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416119)، [rollback SBOM 72416136](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416136)، [release manifest signature 72416150](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72416150).
- Immutable release bundle: [artifact 15072603777](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45723087433/artifacts/15072603777)، size 564,117,614 bytes، digest sha256:a12ddc732dcd2d2cff30df5ba73b236b32f418c51523b5a7c796778d94acb3ad. Unsigned inputs artifact: 15073148534، digest sha256:169d89d5d0d4307f9dc61435173454d838316c96cce606590f721f6b8b94696e.
- لا يوجد OCI registry push أو staging/production deployment أو تغيير DB/secrets أو إنشاء موارد سحابية.
- Pint workflow نجح لكنه تخطى فقط بناء/تنسيق الملفات المتغيرة لعدم وجود ملفات PHP معدلة؛ strict Pint وفحص baseline داخل Docker نجحا.
- main ما زال protected=false وrulesets فارغة؛ 10B مفتوحة. 11A cost scorecard ما زال WAITING_INPUT لغياب region/workload/data-growth/retention/HA/RPO/RTO/budget المعتمدة. لذلك لا يبدأ 11B2 حتى إغلاق 10B واستيفاء موافقة المزود/المنطقة/التكلفة.


## 14. قرار تأجيل إعدادات حماية GitHub — 26 نوفمبر 2026

- بناءً على توجيه المستخدم، يُؤجل إعداد Branch protection وRequired status checks على `main` إلى ما بعد اكتمال خطة تطوير Backend بالكامل.
- هذا تأجيل إداري مقصود، وليس عيب CI أو فشلاً برمجياً؛ لا يمنع استكمال ميزات Backend والاختبارات وعمليات CI خلال مراحل التطوير.
- تبقى Phase10B بحالة `DEFERRED_BY_OWNER` ولا تسجل COMPLETE قبل التنفيذ والتحقق اليدوي.
- تبقى Phase11B2 وعمليات Staging/Production مشروطة بإغلاق Phase10B وباعتماد provider/region/cost وبقية الاعتماديات.
- تقرير خطوات الإعداد والفحوص المطلوبة: `phases/reports/POST-PLAN-MANUAL-BRANCH-PROTECTION-SETUP.md`.
- الحالة المرصودة عند القرار: `main.protected=false` ولا توجد Rulesets؛ لم يتم تغيير إعدادات GitHub.


## 15. Phase11C — provider-neutral IaC baseline بدأ — 26 نوفمبر 2026

- بدأ 11C على `main` بعد تحقق Docker Validation وSecurity Scans وAuthentication Regression وPint Format على commit السابق `9ad253a4a5ebbae229d44f615bcb6ce5fa901ec2`؛ الأربع كانت SUCCESS.
- ثُبّت OpenTofu CLI في `.opentofu-version`، وأضيف `infra/opentofu/` بعقد منطقي محايد للمزود. لا توجد provider/resource/data/backend blocks.
- أضيف workflow مستقل لـ`fmt`, `init -backend=false`, `validate`, و`plan` محلي بلا provider أو credentials أو apply، مع guardrail يمنع تجاوز حدود الموافقة.
- CI الخاص بهذا الالتزام قيد الانتظار عند إنشاء هذا السجل؛ لا تُعلن 11C مكتملة قبل نجاحه.
- لم يتم اختيار مزود أو region أو budget، ولم يتم إنشاء resources أو state backend أو environment أو secrets أو registry أو deployment.
- 10B ما زالت `DEFERRED_BY_OWNER` حسب توجيه المستخدم؛ لا يُفعّل branch protection في هذه الشريحة.


## 16. Phase11C — تحقق CI للـprovider-neutral IaC — 26 نوفمبر 2026

- رأس `main`: `c709ba7a73aa9ab6d05aa1b6971e44b6912d97dc`. الفرع الوحيد المستخدم هو `main`.
- بوابات Docker [45756158476](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45756158476)، Security [45756158468](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45756158468)، Authentication [45756158528](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45756158528)، وPint [45756158555](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45756158555) جميعها SUCCESS على SHA نفسه.
- Docker: SQLite Feature 150/1,704 assertions؛ PostgreSQL Integration 31 passed/546 assertions مع optional skip واحد؛ S3Mock contract test 1/8؛ strict Pint، PostgreSQL restricted roles/migrations، non-root HTTP/liveness/readiness، وrollback rehearsal نجحت. وظيفة attest-release المنفصلة نجحت أيضاً.
- Authentication: SQLite 20/172 assertions وPostgreSQL 4/99 assertions — SUCCESS.
- Security: Gitleaks وComposer audit وفحص filesystem/image اكتملت؛ ملخص صورة الإنتاج Clean وبوابة fixable HIGH/CRITICAL نجحت. لا يعني ذلك أن تقارير Trivy الاستشارية خارج هذه البوابة غير موجودة أو أن كل أصناف المخاطر عولجت.
- Pint report: workflow SUCCESS، لكن خطوات Pint المعتمدة على PHP files المتغيرة كانت SKIPPED لعدم تغيير ملفات PHP؛ Docker strict Pint check نجح.
- IaC [45754424342](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45754424342) SUCCESS على `f95e361dc8ec2db4d605806ad54659085c38624a`: OpenTofu 1.13.10، guardrail، 18/18 regression cases، HCL format، init بلا remote backend، validate، وprovider-free plan. لا apply.
- مقارنة `f95e361...c709ba7` أعادت ahead_by=2، والملف الوحيد المتغير هو مواصفة Phase11 نفسها؛ لذلك لم تتغير ملفات `infra/opentofu/` أو workflow `iac-validation.yml` بعد تشغيل IaC المذكور، وتظل نتيجته صالحة لهذه الملفات على رأس main الحالي.
- لا يوجد فشل حالي في هذه البوابات يحتاج إصلاحاً. كانت هناك إخفاقات تاريخية في guard regression وتم علاجها؛ الاختبارات الحالية تشمل الحالات التي منعتها، وكل الحالات الـ18 نجحت.
- **الحكم:** نجاح فحوص الشريحة provider-neutral مثبت، لكن Phase11C لا تُغلق بعد؛ مواصفة المرحلة تشترط اعتماد provider/region/workload/cost قبل إنشاء واختبار adapter modules. Phase11D provisioning لم تبدأ ولا يجوز بدؤها دون موافقة صريحة. 10B ما زالت DEFERRED_BY_OWNER و`main.protected=false`؛ لذلك لا يبدأ 11B2.
- لم ينفذ OpenTofu apply، ولم يُنشأ remote state أو cloud resource أو account أو secret أو registry publication أو staging/production deployment. لم تُمَس قاعدة الإنتاج أو بيانات المستخدمين.
- الاستمرار من هذه النقطة: لا تعِد IaC scaffold أو guard tests؛ انتظر مدخلات provider/region/workload/retention/HA/RPO/RTO والميزانية، ثم نفّذ adapter modules مع versions مقفلة وخطة محلية قابلة للمراجعة، مع إبقاء موافقة apply منفصلة.

## تحديث الاستئناف — Phase08K وتصحيح الاعتماديات الأمنية — 26 نوفمبر 2026

- استؤنف العمل من `abe1b8a` دون إعادة المراحل السابقة؛ أضيف اختبار worker فعلي عبر database queue يتحقق من فشل transport المتكرر، retries، وثلاث استثناءات حتى dead-letter.
- أُصلح PSR-4 escaping في fixture قبل اعتماد الاختبارات، ثم عولجت 5 Composer advisories بترقية Symfony packages المتأثرة إلى `v8.1.12`.
- `composer audit` نجح بلا advisories؛ focused outbox suite: 16 tests / 119 assertions؛ full Docker SQLite Feature: 151 / 1,719؛ PostgreSQL: 31 / 546 مع optional skip واحد؛ S3Mock: 1 / 8.
- Docker/Auth/Pint/Security جميعها SUCCESS على `1e79bb3`. كان Docker baseline dispatch يتجاوز rollback والتوقيع مؤقتًا فقط لإعادة تأسيس هدف تراجع غير متأثر بالثغرات؛ هذا التغيير يعيد المسار الكامل ويحدد `1e79bb3` هدفًا سابقًا صالحًا ليُعاد بناؤه واختبار التحويل إليه على commit الحالي.
- Phase08 تبقى IN_PROGRESS لانتظار S3/mail/Push staging وسياسات retention/cost؛ لم يُفعّل مزود خارجي. Phase10B ما زالت DEFERRED_BY_OWNER كما هي.


## 23. Phase12 synthetic PostgreSQL backup/restore preflight — verified on main — 27 November 2026

- رأس `main` الذي اجتاز التحقق: `27cfcc9e577cca33c28e9845096a0c5564b02045`. لم ينشأ أي فرع جديد؛ GitHub refs أعاد `main` وحده.
- Docker Validation [45929855650](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650): SUCCESS — وظيفة build/test 30/30 خطوة، ووظيفة trusted-main signing 17/17.
- Authentication Regression [45929855617](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855617): SUCCESS. Pint Format [45929855622](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855622): SUCCESS. Security [45929855730](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855730): SUCCESS — 12/12 خطوة.
- نجح PostgreSQL synthetic rehearsal: custom-format dump مشفر بـAES-256-CBC مع PBKDF2-HMAC-SHA256 (200,000 iteration)، وفك التشفير وتطابق SHA-256، والاستعادة إلى قاعدة اختبار منفصلة؛ تطابقت 3 accounts و4 media progress rows و2 sync events و10 constraints (بما فيها behavioral CHECK probes) والفهارس.
- تقرير الاستعادة الاصطناعية [artifact 15168436709](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168436709) رُفع بنجاح (904 bytes؛ SHA-256 `2eb010768a7fbd2b89ca849babfc36fd5db546d8a556153e969c1f6edee0da7d`).
- Rollback rehearsal اجتاز liveness للصورة الحالية والقديمة، وأثبت HTTP 200 للمسار الحالي، وHTTP 502 بعد إيقاف candidate، ثم تحويل المرور بنجاح إلى rollback target. نجح التحقق من SHA-256 للصورة وملفات CycloneDX SBOM وrollback evidence قبل التوقيع.
- اكتملت 5 Sigstore attestations: [current provenance 72918520](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72918520)، [current SBOM 72918531](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72918531)، [rollback provenance 72918541](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72918541)، [rollback SBOM 72918554](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72918554)، [release manifest 72918567](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72918567).
- حزمة الإصدار [artifact 15168861287](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45929855650/artifacts/15168861287) رُفعت بنجاح (564,159,137 bytes؛ SHA-256 `e06ee520d6f31f2234cd6cf147117a41aad40823b8a0bad2f9608f13c39d647d`). لم تُنشر الصورة إلى registry؛ لا يعني artifact وجود deployment.
- **الحكم:** التحضير الاصطناعي 2.1 مكتمل ومثبت في CI فقط. تظل Phase12 `WAITING_DEPENDENCY` وتظل Phase13 مغلقة؛ يلزم إكمال 04–11، واعتماد المصدر والوجهة، ونسخة احتياطية فعلية واستعادة معزولة، وخطة الكتابات المتزامنة وموافقة cutover محددة. لا production DB/data/secrets، ولا migrations أو deploy أو DNS cutover أو cloud resources.

## 24. مزامنة شاملة مع رأس main وقرار تأجيل مزودي الخدمات — 27 نوفمبر 2026

- رأس `main` الحالي: `617ce7efd669b4d3af61c42f77b4cd1f986e3fae`؛ هو آخر commit اجتاز بوابات CI الأربع. المقارنة مع `27cfcc9` تبين أن commit الحالي توثيقي فقط، ولم يغير كود التطبيق أو IaC.
- Docker Validation [45936514582](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514582): SUCCESS؛ SQLite Feature 151/1,719، PostgreSQL 31/546 مع optional skip واحد، S3Mock 1/8، runtime/least-privilege/migration guards/backup-restore synthetic/rollback وevidence/signing نجحت؛ Build/test 30/30 والتوقيع 17/17.
- Authentication Regression [45936514610](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514610): SUCCESS؛ SQLite 20/172 وPostgreSQL 4/99.
- Security [45936514543](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514543): SUCCESS؛ Gitleaks/Composer/Trivy والبوابة القابلة للإصلاح HIGH/CRITICAL اجتازت.
- Pint [45936514597](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45936514597): SUCCESS؛ خطوات changed-PHP format skipped لعدم وجود ملفات PHP متغيرة؛ strict Pint ضمن Docker نجح.
- لقطة شجرة Git بعد إضافة تقرير المزامنة: 253 ملفًا و55 مجلدًا؛ `phases/` تضم 79 ملفًا (78 Markdown) بما فيها 45 تقريرًا؛ 23 migration؛ 42 ملف PHP للاختبارات؛ 6 workflows؛ branch listing يثبت `main` وحده.
- قرار المالك D-050/D-051: تأجيل جميع اختيارات مزودي الخدمات والمنصات والمناطق والتكاليف إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل؛ لا تُفتح القرارات عند نهاية مرحلة منفردة. تستمر الأعمال المحايدة الآن وتبقى معايير القبول الخارجية مؤجلة. يشمل الاستضافة وقاعدة البيانات والتخزين والبريد وPush والدفع وregistry/secrets/identity/monitoring/DNS.
- تستمر الآن جميع أعمال code/API/schema/contracts/tests/CI والـprovider-neutral foundations والـsynthetic tests. لا provider SDK أو credentials أو موارد مدفوعة أو apply أو deployment قبل إعادة فتح القرار والموافقة المحددة.
- حالات المراحل لم تتغير إلى COMPLETE بسبب التأجيل: 04 PARTIAL؛ 08/09 IN_PROGRESS؛ 10B DEFERRED_BY_OWNER؛ 11C IN_PROGRESS على مستوى scaffold المحايد؛ 12 و13 WAITING_DEPENDENCY. راجع `MASTER_PLAN.md` و`DECISIONS.md` والقرار D-050.


## 25. Phase11 — التحقق الكامل من بوابات CI وتحديث حدّ الإغلاق — 27 نوفمبر 2026

- رأس main الذي اجتاز البوابات الخمس: 74f57a01c1475c66a32337dc6be549b187a23344. branch listing أعاد main وحده.
- Docker [46066319105](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319105): SUCCESS؛ SQLite 151/1,719؛ PostgreSQL 31/546 مع optional S3 skip واحد داخل هذه المجموعة؛ S3Mock المنفصل PASS؛ runtime/non-root/health/TLS/queue-worker/PHP asset guards PASS؛ synthetic restore وloopback rollback PASS؛ trusted-main signing job SUCCESS.
- Authentication [46066319109](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319109): SUCCESS — SQLite 20/172؛ PostgreSQL 4/99.
- Security [46066319068](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319068): SUCCESS — Gitleaks/source scan/image scan وfixable HIGH/CRITICAL blocking gate؛ ملخص production image Clean.
- IaC [46066319030](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319030): SUCCESS — 30/30 provider-neutral guardrails، fmt، init بلا backend، validate، provider-free plan.
- Pint [46066318988](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066318988): SUCCESS؛ changed-PHP steps skipped لعدم وجود PHP changes، وstrict Pint في Docker PASS.
- Attestations الحالية/التراجع/الـmanifest: [73274092](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/73274092)، [73274098](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/73274098)، [73274111](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/73274111)، [73274117](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/73274117)، [73274126](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/73274126). Release bundle [15230897563](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319105/artifacts/15230897563)؛ synthetic restore report [15230907253](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46066319105/artifacts/15230907253).
- مقارنة 617ce7e...74f57a0: 80 commits ahead، لا تغييرات على app/routes/database/tests/config؛ التغييرات محصورة في inventories/docs/IaC guardrails/workflow path filters/.gitignore.
- تحديث الحالة: Phase11 لا تزال IN_PROGRESS؛ 11C provider-neutral slice PASS، لكن adapter modules و11B2 و11D–11F لا تغلق قبل قرارات D-050/D-051 و10B وموافقات staging/production. لا يتم اعتبار mock أو loopback rehearsal أو synthetic restore تكاملاً سحابياً حقيقياً.
- تقرير التفصيل: phases/reports/PHASE-11-CI-AND-PROVIDER-NEUTRAL-READINESS-2026-11-27.md.

## 27. Phase11 deferral and Phase12A entry — 28 November 2026

- استقر main على commit 46dd00bf9378436465bfec76fed4aaa1d8819ece، والفرع الوحيد في GitHub هو main.
- آخر baseline مكتمل التحقق قبل تغيير Phase12 هو dbd281c545f5a2da5a6816afccebd4d929a02b70، واجتازت عليه بوابات Docker/Auth/Security/IaC/Pint الخمس. لا تخلط هذه النتيجة مع commits Phase12 الأحدث التي تنتظر CI الخاص بها.
- Phase11 PAUSED/DEFERRED_BY_OWNER عند بوابات الاختيار الخارجي؛ لا تُحذف أعمالها، ولا تُحوّل إلى COMPLETE. كل خيارات hosting/database/storage/mail/Push/payment/registry/secrets/identity/monitoring/DNS/region/cost تظل مجمعة إلى نهاية خطة Backend.
- Phase12A بدأت بأعمال inventory والـsynthetic CI. أضيف scripts/postgres-full-schema-restore-rehearsal.sh وخطوة Docker workflow لإنشاء clone من قاعدة PostgreSQL المؤقتة بعد migrations، وأخذ custom-format encrypted dump ثم restore منفصل والتحقق من schema/data manifests.
- الاختبار الجديد على 46dd00b ينتظر نتائج CI؛ لا يُعد نجاحًا بمجرد إضافته. تقرير المرحلة يسجل نتيجة التشغيل بعد اكتمال البوابات.
- لا إنتاج: لا اتصال أو قراءة أو تعديل Neon/Render، لا production credentials، لا بيانات مستخدمين حقيقية، لا migration أو cutover أو routing/DNS change.
- Phase12B/12C تظل مؤجلة حتى إنهاء تطوير Backend المحايد، واعتماد الخدمات ومصدر/وجهة محددين، وbackup/restore حقيقي معزول وموافقة cutover مستقلة.


## 28. Phase12A — full-schema backup/restore PASS and data mapping — 28 November 2026

- **Current main:** \`72730281a6c96208979e4c8a1eaa46fbc723dcdd\`; branch listing يؤكد \`main\` فقط.
- **CI on current SHA:** Docker Validation #671 \`46077286759\` SUCCESS؛ Authentication Regression #293 \`46077286619\` SUCCESS؛ Security Scans #417 \`46077286662\` SUCCESS؛ Pint #415 \`46077286802\` SUCCESS.
- **IaC:** لم تتغير ملفات infra/OpenTofu بعد IaC Validation #31 \`46068443560\` على \`dbd281c545f5a2da5a6816afccebd4d929a02b70\`؛ 30/30 guardrails SUCCESS. لا يُنسب هذا الفحص إلى SHA مختلف.
- **Full-schema report:** artifact #15235408250، digest \`sha256:7cf5bf3ec6b5ce338e9c51e43a92fd7317e38339f41d4435e89b38a12cf91d05\`; GitHub artifact metadata is the authoritative exact digest. Run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759
- **Measured CI result:** 23 migration files = 23 migration ledger rows؛ 34 public tables؛ encrypted custom-format PostgreSQL dump round-trip checksum matched؛ restore target منفصل؛ table inventory/schema definitions/columns/constraints/indexes/sequence definitions/triggers/views/row counts/per-table content fingerprints كلها matched.
- **Evidence boundary:** source is disposable GitHub Actions CI database only. No production DB/secrets/data used or read؛ no cutover؛ source/target clone DBs dropped؛ artifact includes aggregate counts and hashes only, no row values.
- أضيفت \`phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md\` لتوثيق mapping domains/invariants وحقول API export v3 والفجوات بين end-user export والـdatabase backup الكامل.
- Phase11 remains PAUSED/DEFERRED_BY_OWNER؛ Phase12 remains IN_PROGRESS in 12A only؛ 12B and 12C remain external/owner-approved gates.


## 29. Phase12A — مصدر التطبيق المرشح وفحص sequence-state — 28 نوفمبر 2026

- Flutter source evidence pinned to m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a: legacy Firebase/Firestore collections، SQLite per-device files، LegacyFirebaseSession، وLaravel sync coordinator. لا يمثل هذا اتصالًا مباشرًا بخدمات Firebase أو إثبات source-of-truth.
- Firebase Authentication import remains a conditional blocker: the old PRD requires firebase_uid and Scrypt hash compatibility fields/parameters not defined in current Laravel migrations/auth support. لا تنفيذ حتى يعتمد Firebase مصدرًا.
- Docker #675 run 46078577654 full-schema restore step and evidence upload SUCCESS: report schemaVersion 2; 23 migration files/ledger rows; 34 public tables; 21 public sequences; sequence state last_value/is_called matched; schema/data/sequence manifests matched; production safety guards all true. Artifact #15236350311, SHA-256 907d099c910c463919fea0842a7b43f22723a356c9f756d304ec09ded778a0b6.
- Same SHA gates: Pint #419 46078577614 SUCCESS; Authentication #297 46078577591 SUCCESS; Security #421 46078577609 SUCCESS. Docker #675 remains IN_PROGRESS on previous-known-good image rollback/trusted-main signing at last poll. IaC #31 46068443560 SUCCESS on unchanged infra baseline dbd281c.
- Phase11 stays PAUSED/DEFERRED_BY_OWNER. Phase12 stays IN_PROGRESS at 12A; 12B/12C not authorized.


## 30. Docker #675 الإغلاق وإصلاح فشل sequence-state الوسيط — 28 نوفمبر 2026

- Docker Validation #675، run 46078577654، اكتمل SUCCESS على code SHA 6750eac566c513c4fc97951f1b80cb7f54cd0c0a، بما في ذلك job بناء/اختبار runtime وtrusted-main release evidence signing.
- Pint #419 run 46078577614 SUCCESS؛ Authentication Regression #297 run 46078577591 SUCCESS؛ Security #421 run 46078577609 SUCCESS.
- Docker #674 على SHA 2d57458141b554f94d2b9c3cc8d8e60c1122fb7a فشل في خطوة full-schema rehearsal تحديدًا. سجل الخطأ: scripts/postgres-full-schema-restore-rehearsal.sh: line 198: 2: parameter not set؛ كانت دالة sequence manifest تستخدم $2 دون أن يمرره caller، مع set -u.
- الإصلاح في 6750eac جعل اسم ملف SQL المؤقت مشتقًا من اسم قاعدة البيانات بدل الوسيط الغائب. Docker #675 أعاد الاختبار ونجح مع 21 sequence؛ sequence current values/is_called matched. لا يوجد فشل متبقٍ في بوابات #675.
- Artifact #15236350311، digest sha256:907d099c910c463919fea0842a7b43f22723a356c9f756d304ec09ded778a0b6. التقرير لا يحتوي row values؛ قواعد المصدر/الهدف المؤقتة حُذفت؛ لا production DB/data/secrets ولا cutover.
- IaC #31 لا تزال مرجع التحقق لطبقة infra التي لم تتغير منذ dbd281c؛ لا ندعي أنها أعيد تشغيلها على 6750eac.
- آخر تحديثات ما بعد runtime code SHA 6750eac وثائقية فقط على main؛ الفرع الوحيد هو main.


## 31. Phase12A — field mapping وplaylist/local queue gaps

- تمت مطابقة قواعد bookmark/recent/wellness/playlist المحلية مع API/controllers والجداول الحالية في خريطة البيانات.
- playlist local schema تحفظ source_provider/source_id/share_code/share_url؛ sync API الحالي لا يستقبلها ولا يعيدها. هذا contract gap مشروط بما إذا كانت حقولًا user-visible وليست local cache.
- queue المحلية من العمليات المعلقة مختلفة عن operation receipts/change logs في Backend. يجب حفظها وإعادة تشغيلها idempotently بعد اختيار وجهة معتمدة، وعدم إسقاطها عند الترحيل.
- owner_id المحلي للـwellness هو string namespaced؛ الخادم يحدد user_id من جلسة الحساب المصادق عليه. لا تنقل owner IDs عبر cast أو مطابقة اسمية.
- Phase12A تبقى IN_PROGRESS؛ لا يغير ذلك تأجيل 12B/12C.


## 32. Phase12A — تدقيق عقد Playlist import/sharing

- كود Flutter يحتوي حقول sourceProvider/sourceId/shareCode/shareUrl ويستدعي endpoints متوقعة لمشاركة القوائم والاستيراد.
- Backend الحالي لا يملك هذه routes ولا الأعمدة ولا response fields؛ \`POST /sync/playlists\` فقط. Client يوثق صراحةً أن import unavailable إن لم يقدم Backend endpoints.
- تم توسيع الفجوة إلى \`PLAYLIST_IMPORT_SHARING_BACKEND_CONTRACT_MISSING\`، وتتطلب مراجعة Phase06 قبل أي تنفيذ. هذا توثيق فقط، وليس تغييرا في API/schema.


## 33. Phase12A — retention and cursor portability rules

- Recently-watched tombstones: retention 90 days server/client; client full-pull cadence at least every 7 days. Offline devices beyond retention need reconciliation to avoid deleted-record resurrection.
- Wellness cursor is HMAC-SHA256 signed with APP_KEY and bound to user_id/since_revision/snapshot_revision/last_id; not portable across owner mapping or key change.
- Bookmark/playlist revisions, cursors, and local checkpoints must be re-established from target full snapshots; preserve pending operation IDs and server idempotency receipts rather than copying pagination state.
- لا توجد قياسات cohort للأجهزة غير المتصلة أو queue depth حاليًا؛ تبقى ضمن 12A acceptance design ولا تُختلق أرقامًا.


## 34. بدء Phase13A المحايدة بعد مراجعة CI — 28 نوفمبر 2026

- رأس main قبل تحديث هذا التوثيق: f46751768a4299aa08de221911343c2f2abb9520؛ الفرع الوحيد main.
- Docker #681 run 46080902419 SUCCESS؛ Security #427 run 46080902411 SUCCESS؛ Authentication #303 run 46080902380 SUCCESS؛ Pint #425 run 46080902461 SUCCESS.
- IaC workflow لم يعمل على هذا التحديث لأن مسارات infra/.opentofu-version/.gitignore/Workflow لم تتغير؛ آخر فحص صالح IaC #31 run 46068443560 SUCCESS مع 30/30. HTTP smoke workflow manual-only requires api_base_url، ولم يشغّل لعدم وجود URL staging معتمد.
- بدأ Phase13A design-only وفق D-053: runbooks وSLI/SLO/RPO/RTO/DR templates موثقة، دون numeric targets أو مزود أو خدمات خارجية. Phase12 تبقى IN_PROGRESS وPhase13B التشغيلية WAITING_DEPENDENCY.


## 35. Phase13A design baseline review — 28 November 2026

- راجعنا شجرة `phases/` كاملة على `main`: 86 entries تحت المجلد، منها 83 ملف Markdown و48 تقريرًا و18 مواصفة مرحلة؛ Git tree recursive غير مبتور.
- تم تدقيق مواصفات المراحل 00–13، والخطة الرئيسية والحالة والقرارات والجردات والسياسات وبروتوكول التنفيذ وقواعد المستخدم وجرد Workflows وتقارير Phase11/12/13 الأحدث. التقارير التاريخية بقيت كما هي.
- قورنت `6750eac566c513c4fc97951f1b80cb7f54cd0c0a` مع `4bf0535b587aeb6f8e7ca333224d3d8585716714`: سبعة commits، وجميع الملفات المتغيرة ضمن `phases/` فقط؛ لا تغييرات تطبيق أو routes أو migrations أو Docker أو workflows أو IaC في هذه الشريحة.
- تم التحقق من مراجع دليل Phase13A في المصدر: HealthController/routes، scheduler، Notification Outbox commands، private-media cleanup/config، queue-worker guards، وfull-schema PostgreSQL restore script.
- Docker #682 وSecurity #428 وAuthentication #304 وPint #426 على SHA واحد كلها SUCCESS. لا توجد إصلاحات CI مطلوبة.
- Phase13A design baseline موثق ومراجع؛ Phase13 العامة تبقى IN_PROGRESS. Phase12 تبقى IN_PROGRESS في 12A؛ 12B/12C وPhase13B لا تزال WAITING_DEPENDENCY/OWNER_APPROVAL. لا يُفتح تشغيل فعلي أو مزود خارجي بموجب هذا التقرير.
- التقرير التفصيلي: `phases/reports/PHASE-13A-DESIGN-BASELINE-CLOSEOUT-2026-11-28.md`.

## 36. Phase12A — مخازن الجهاز المحلية ودمج بيانات الضيف — 28 نوفمبر 2026

- تمت مراجعة RAVEN23 source على SHA `387feafb4865d991223897088b82eacb7a42061a`، ومقارنته مع main `51f12760cc4e06a5651f7e07dbd89f774bafece4`: commit واحد غيّر ملفي phases/README فقط؛ لا تغيير في كود SQLite أو sync.
- حُددت ستة ملفات SQLite: movies.db وtv.db وrecent_movies.db وrecent_episodes_v2.db وplaylists.db وraven23_wellness_v1.db. أول خمسة تستخدم owner-suffixed paths فقط عندما LibraryScope.enabled مفعّل؛ Wellness ملف واحد مشترك ومعزول بحقل owner_id.
- جرى جرد SharedPreferences namespace `flixquest.laravel.v1.` ومفاتيح bookmark known/deleted، وrecent/wellness cursors، وplaylist checkpoint، وguest claim/copy snapshots.
- **فجوة مفتوحة:** PlaylistStore.moveInto ينقل pending operations فقط ثم يمسح متجر الضيف. لا يثبت ذلك نقل confirmed lists/items أو source/share metadata. الاختبار الموجود يغطي queue-only transfer؛ ولا يوجد اختبار confirmed guest playlist state.
- **فجوة مشروطة:** bookmark known/deletion journals في SharedPreferences منفصلة عن جداول SQLite؛ لا تظهر خطوة صريحة لنقلها من guest إلى user scope. قد لا تؤثر في guest محلي بحت، لكن لا يمكن استبعادها قبل تحديد legacy/Firebase source.
- تم توثيق aggregate-only device preflight manifest المطلوب دون PII، مع إبقاء IDs/cursors/operation UUIDs داخل نسخ مصرح بها لاحقًا وليس في تقارير CI العامة.
- لا تغيير في RAVEN23 app repo أو Backend runtime/API/schema؛ لم يتم الوصول إلى ملفات أجهزة حقيقية أو بيانات مستخدمين أو قواعد production.
- التقرير: `phases/reports/PHASE-12A-DEVICE-LOCAL-STORES-AND-GUEST-MERGE-REVIEW-2026-11-28.md`.
## 37. Phase12A — owner-scope runtime وFirebase legacy re-key — 28 نوفمبر 2026

- الوضع الافتراضي غير المحلي يفعّل auth/ads/sync؛ buildInjector يفعّل LibraryScope عند تغيير owner. ملفات الحسابات تصبح owner-suffixed، بينما coordinator يسمح بالمزامنة فقط لـ user:<id>؛ guest يبقى local-only حتى تسجيل الدخول.
- FirebaseSessionBridge يستهدف تحويل user:<firebase uid> إلى user:<Laravel id>. callback الفعلي ينقل Wellness owner_id فقط، ولا يثبت نقل ملفات bookmark/recent/playlist أو مفاتيح المزامنة المرتبطة بالمالك القديم.
- اختبار Firebase bridge الحالي يستخدم fake callback ولا يختبر قواعد البيانات؛ كما أن POST /api/v1/auth/firebase/exchange غير موجود في Backend routes.
- هذا مانع قبول مشروط لlegacy Firebase accounts، وليس إثباتًا لفقد بيانات حقيقية. يجب إكمال عقد exchange/re-key واختبار نقل اصطناعي شامل قبل إغلاق 12A.

## تحديث Phase12A — مواصفة قبول دمج الجهاز ولقطة snapshot/delta — 28 نوفمبر 2026

- أضيفت `PHASE-12A-DEVICE-MERGE-AND-SNAPSHOT-ACCEPTANCE.md` بمصفوفة PL/BM/OWN/SNAP/DELTA لحالات guest confirmed playlists، bookmark journals، legacy Firebase owner re-key، tombstones، ACK/replay، وsnapshot consistency.
- هذه مواصفة تصميم فقط؛ لا توجد اختبارات جديدة ولا PASS لأي سيناريو فيها. Phase12A تبقى IN_PROGRESS.
- التغيير التالي الذي يتطلب كودًا هو في مستودع Flutter `m5ham3ds/RAVEN23`؛ لم يتم تعديله. Backend exchange route لا يزال غير موجود، وعقد playlist import/sharing يحتاج قرار Phase06.
- Phase12B/12C وPhase13B لا تزال محكومة بالاعتماديات/الموافقات السابقة.
- CI على رأس البداية `7070350`: Docker #685 وSecurity #431 وAuthentication #307 وPint #429 جميعها SUCCESS؛ التفاصيل في سجل GitHub Actions المرتبط بهذا SHA.

## Phase12A — legacy Firebase exchange contract review — 28 November 2026

- Added `PHASE-12A-FIREBASE-SESSION-EXCHANGE-CONTRACT.md` and its report. The design matches the Flutter call to `POST /api/v1/auth/firebase/exchange`, the expected session envelope, and terminal/retryable failure behavior.
- Confirmed Backend route and Firebase UID mapping are absent. The existing Google ID-token verifier is not suitable for Firebase Secure Token verification; `google_id` must not be repurposed.
- Identified an app-side retry gap if local owner re-key fails after the Laravel session is established. It requires a durable pending/completed migration state.
- Contract is design only. No route, migration, verifier, credential, Flutter change, or Firebase connection was added. Firebase source-of-truth and account-linking policy remain undecided.
- The full CI gates on baseline `be14f83` all passed: Docker #686, Security #432, Authentication #308 and Pint #430.
