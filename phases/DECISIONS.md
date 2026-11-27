# سجل القرارات المعمارية وقرارات المنتج

يضاف كل قرار جديد في نهاية الملف. لا يُحذف قرار سابق ولا يُستبدل نصه؛ إذا تغير القرار، أضف قرارًا جديدًا يوضح سبب التغيير وأثره.

## D-001 — الاحتفاظ بالBackend الحالي وتطويره تدريجيًا
- **التاريخ:** 21 نوفمبر 2026
- **الحالة:** معتمد كاتجاه عمل
- **القرار:** لا نعيد كتابة المشروع من الصفر. نحتفظ بمنطق المصادقة المثبت والاختبارات والمكونات المفيدة، ونعيد بناء scaffolding/build/runtime تدريجيًا.
- **السبب:** توجد وظائف فعلية ونتائج اختبار SQLite وPostgreSQL ناجحة في خط الأساس.
- **الأثر:** المرحلة 00 توثق ما يجب الحفاظ عليه قبل إعادة الهيكلة.

## D-002 — استقلال التطبيق عن مزود الاستضافة
- **التاريخ:** 21 نوفمبر 2026
- **الحالة:** معتمد كاتجاه معماري
- **القرار:** GitHub هو مصدر الكود والمigrations والاختبارات. الخادم قابل للاستبدال. PostgreSQL وObject Storage يُستخدمان عبر عقود قياسية وواجهات adapters.
- **السبب:** الانتقال بين AWS ومزود آخر يجب ألا يتطلب إعادة كتابة منطق التطبيق.
- **الأثر:** لا تدخل Neon أو Render مباشرة في domain logic.

## D-003 — حماية بيانات الإنتاج
- **التاريخ:** 21 نوفمبر 2026
- **الحالة:** إلزامي
- **القرار:** لا تغيير أو نقل أو حذف لبيانات الإنتاج قبل backup فعلي وrestore rehearsal وموافقة مباشرة تحدد المصدر والهدف وإجراء cutover.
- **السبب:** حماية حسابات وبيانات مستخدمي RAVEN23.
- **الأثر:** المرحلة 12 هي بوابة نقل الإنتاج الوحيدة.

## D-004 — توثيق التنفيذ وإثبات الإنجاز
- **التاريخ:** 21 نوفمبر 2026
- **الحالة:** إلزامي
- **القرار:** كل مرحلة لها مواصفة مسبقة وتقرير منفصل عند الإكمال أو التوقف أو الحظر، ويذكر التقرير SHA والملفات والاختبارات والاعتماديات والحالة الفعلية.
- **السبب:** منع تكرار العمل أو إعلان اكتمال غير مثبت.
- **الأثر:** الالتزام بـ EXECUTION_PROTOCOL.md وتحديث STATUS.md وMASTER_PLAN.md بعد كل تغيير حالة.

## D-005 — Laravel baseline migrations must preserve pre-existing tables
- **التاريخ:** 21 نوفمبر 2026
- **الحالة:** معتمد ضمن Phase 01
- **القرار:** migrations الأساسية لـusers وcache وjobs تنشئ كل جدول فقط عند غيابه، ولا تحذف تلك الجداول في rollback. يجب اختبار بقاء صفوف تمثل بيانات قديمة قبل اعتبار بنية Laravel الجديدة آمنة للإدخال في مستودع قائم.
- **السبب:** كانت إصدارات سابقة من Docker تنشئ Laravel skeleton أثناء البناء؛ لذلك قد توجد جداول Laravel الأساسية مسبقًا في قاعدة تشغيل قائمة حتى لو لم تكن ملفات migrations ثابتة ملتزمة في المصدر. محاولة إعادة إنشاء هذه الجداول قد توقف بدء التشغيل، والrollback المدمر قد يحذف حسابات أو بيانات جلسات/مهام.
- **الأثر:** migrations الجديدة تضيف المفقود فقط، وتترك البيانات والجداول الموجودة كما هي. لا يثبت هذا وحده سلامة تطبيق migrations على الإنتاج؛ لم يتم الاتصال بقاعدة الإنتاج أو تجربتها ضمن Phase 01.

## D-006 — استخدام FrankenPHP/Caddy كخادم HTTP محمول
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** مُثبت بعد نجاح Phase 02 في Docker Validation #249
- **القرار:** استبدال PHP built-in server بصورة FrankenPHP/Caddy على PHP 8.4، مع non-root runtime على port أعلى من 1024، وعدم تفعيل worker mode في هذه المرحلة.
- **السبب:** خادم HTTP إنتاجي واحد مع Caddy وPHP يقلل مكونات الحاوية، ويزيل الاعتماد على خادم PHP التطويري. worker mode مؤجل حتى تتوفر اختبارات lifecycle تثبت عدم تسرب state عبر الطلبات.
- **الأثر:** تم التحقق من الصورة نفسها في Docker Validation #249 على SHA `ae311d1dced43b10f96849abfbd3c0a3460dcc3d`: SQLite 20/138، PostgreSQL 3/72، liveness/readiness عند DB unavailable/available، non-root، وPHP asset blocking. لا يسمح هذا القرار بالنشر أو تغيير وجهة الإنتاج.

## D-007 — جعل readiness مستقلة عن middleware الجلسات
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد ومختبر في Phase 02
- **القرار:** GET `/ready` read-only endpoint يتجاوز فقط `StartSession` و`ShareErrorsFromSession` وLaravel 13 `PreventRequestForgery`، لأن هذه middleware تقرأ session أو تصدر CSRF cookie. بقية المسارات تحتفظ بحماياتها.
- **السبب:** يجب أن تظل readiness قادرة على الإبلاغ عن قاعدة بيانات غير متاحة دون أن يحاول middleware جلسة قاعدة البيانات أولًا ويحول HTTP 503 إلى 500.
- **الأثر:** Feature test يفرض `SESSION_DRIVER=database` مع PostgreSQL connection refused؛ CI #249 يثبت 503 وJSON المتفق عليه، مع live readiness ناجحة عند وجود PostgreSQL.


## D-008 — فصل حساب التطبيق عن حساب الترحيل وفرض TLS على PostgreSQL البعيد
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد في أساس Phase 03؛ التحقق الإنتاجي مؤجل لما قبل النشر
- **القرار:** `DB_URL` يستخدم دور تطبيق غير مميز ولا يستطيع إنشاء قواعد أو أدوار أو schema objects. `DB_MIGRATION_URL` يستخدم دورًا منفصلًا لمهمة release/migration فقط. اتصال PostgreSQL البعيد في production يتطلب `sslmode=require` أو `verify-ca` أو `verify-full`; يسمح بـ`disable` لاتصال loopback فقط.
- **السبب:** لا ينبغي أن يمتلك خادم الويب صلاحيات تغيير المخطط أو إدارة الأدوار، ويجب ألا يقبل اتصالًا بعيدًا دون TLS.
- **الأثر:** CI ينشئ دورين منفصلين على PostgreSQL مؤقتة ويختبر صلاحياتهما، وconfig/database.php يرفض خفض TLS لاتصال بعيد في الإنتاج. يجب إعداد الأسرار الفعلية ومراجعتها لدى المزود قبل النشر.

## D-009 — سياسة السجلات والنسخ الاحتياطية تحتاج اعتماد قيم الاحتفاظ
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** خط أساس موثق؛ القيم التشغيلية تنتظر قرار المستخدم/المزود
- **القرار:** لا نخمن مدة الاحتفاظ أو RPO/RTO أو cadence النسخ الاحتياطي. نوثق المتطلبات الأمنية الآن، ولا نعتبر backup فعالًا أو log retention مهيأً قبل فحص إعداد المزود ونجاح restore rehearsal.
- **السبب:** مدة الاحتفاظ وتكلفة PITR والنسخ المستقلة تؤثر في الخصوصية والتكلفة والقدرة على التعافي.
- **الأثر:** `phases/SECURITY-OPERATIONS-POLICY.md` يحدد الضوابط والعناصر الواجب اعتمادها قبل الإنتاج؛ لم يتم الاتصال بقاعدة الإنتاج أو تغيير إعدادات المزود.


## D-010 — Phase 03 code/CI complete; provider production controls remain a pre-release gate
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد بعد نجاح Phase 03 CI
- **القرار:** تعتبر Phase 03 مكتملة على مستوى الكود والاختبارات المؤقتة عندما تجتاز Docker Validation وPint وSecurity Scans على SHA موحد. لا يعني ذلك أن DB roles أو TLS/proxy أو log retention أو backups/restore قد تم التحقق منها على مزود الإنتاج.
- **السبب:** لا يجوز خلط إثبات سلوك التطبيق في CI مع إعدادات حساب خارجي لم تتم مراجعته.
- **الأثر:** يمكن بدء Phase 04، بينما تظل متطلبات `SECURITY-OPERATIONS-POLICY.md` بوابة إلزامية قبل staging/prod rollout وPhase 11/12.

## D-011 — Email verification and password recovery require explicit mail-provider enablement
- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد ضمن Phase 04
- **القرار:** تظل `RAVEN23_AUTH_MAIL_ENABLED=false` افتراضيًا. لا ترسل رسائل التحقق/إعادة التعيين إلا بعد ضبط مزود حقيقي ورابط `RAVEN23_AUTH_ACTION_URL` عبر بيئة runtime آمنة؛ لا يُختار مزود ولا نطاق أو deep link بالافتراض.
- **السبب:** منع إرسال روابط حساسة إلى عنوان أو قناة غير صحيحة، وعدم اعتبار Mail log/array تسليمًا حقيقيًا في الإنتاج.
- **الأثر:** اختبارات CI تستخدم notification fake/array فقط؛ يجب اختبار وصول الرسائل والروابط في staging قبل التفعيل.

## D-012 — فصل بيانات الملف والتفضيلات وتثبيت الجهاز عن هوية الحساب

- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد في Phase 05 — pending CI verification
- **القرار:** يبقى users مصدر هوية الحساب والمصادقة. تنشأ جداول user_profiles وuser_preferences وuser_devices؛ جميعها تملك user_id FK إلى users.id مع ON DELETE CASCADE. لكل مستخدم سجل profile واحد وسجل preferences واحد؛ installation فريد بالمفتاح (user_id, device_id).
- **التوافق:** تبقى users.profile_id وusers.photo_url وJSON الحالي لـ user/profile كما هو. migration تعمل backfill، وتغييرات profile تزامن الحقول القديمة إلى user_profiles.
- **نطاق التفضيلات:** locale وIANA timezone فقط إعدادات حساب مشتركة في هذه المرحلة. إعدادات المشغل والثيم والصوت والترجمة وترتيب المصادر تبقى محلية على تثبيت التطبيق.
- **الأثر:** سجلات Sanctum تبقى في personal_access_tokens؛ user_devices سجل installations وليس بديلاً عن جلسات المصادقة. bookmarks/history/playlists/wellness تؤجل إلى مراحلها اللاحقة.

## D-013 — استثناء اعتماد غير مانع لبدء schema في Phase 05

- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** يسمح بعمل CI معزول فقط؛ لا يغيّر بوابات الإنتاج
- **القرار:** اجتازت Phase 04 code/CI على SHA 459fee8019228828013600fd7a6b13a4a3aa4f4f عبر Docker #320 وPint #52 وSecurity #54. تبقى Phase 04 PARTIAL بسبب PR governance واختبارات Google Android والبريد الحقيقي غير المنفذة.
- **الاستثناء:** يجوز متابعة Phase 05 schema/API work على قواعد SQLite/PostgreSQL مؤقتة لأن هذا النطاق لا يحتاج Google live أو mail delivery. لا يجيز ذلك merge أو production rollout أو migration إنتاجية، ولا يعني أن Phase 04 COMPLETE.

## D-014 — عقد تفضيلات الحساب وتصدير البيانات v1

- **التاريخ:** 22 نوفمبر 2026
- **الحالة:** معتمد في Phase 05؛ pending CI
- **القرار:** تبقى API تحت /api/v1 وبغلاف success المتوافق مع Flutter. يتطلب PUT /user/preferences حقلاً واحداً على الأقل من locale/timezone ويستخدم أخطاء Laravel القياسية مع request_id. يصدّر GET /user/data-export بيانات Backend الشخصية المتاحة بإصدار format_version=1، ولا يتضمن password أو google_id أو remember_token أو Sanctum token/token hash أو recovery/verification secrets.
- **الحدود:** هذا export لا يدّعي تصدير بيانات المكتبة أو wellness أو الاشتراكات غير المنفذة في Backend؛ تلحق بها المراحل التي تبني تلك المجالات.

## D-015 — Owner-scoped bookmarks API

- **التاريخ:** 23 نوفمبر 2026
- **الحالة:** تنفيذ أضيف إلى main؛ CI غير معتمد بعد.
- **القرار:** تخزن المفضلة في `user_bookmarks` بمفتاح فريد (user_id, media_type, media_id) وFK cascade. الأنواع movie/tv وفق عقد Flutter. المزامنة والحذف خلف auth:sanctum، والمالك هو المستخدم المصادق فقط.
- **الأثر:** migration `2026_11_23_000008_create_user_bookmarks_table.php` و`BookmarkSyncController`. الاختبارات العامة في TEST.

## D-016 — Recently watched revision and tombstone semantics

- **التاريخ:** 23 نوفمبر 2026
- **الحالة:** تنفيذ أضيف إلى main؛ CI غير معتمد بعد.
- **القرار:** يخزن سجل المشاهدة في `user_recent_media` مع revision لكل مستخدم/domain في `user_sync_revisions`. الطوابع UTC milliseconds؛ LWW يقارن تحديث progress مع tombstone، والتحديث الأحدث يلغي tombstone أقدم، والحذف الأحدث يمنع إحياء نسخة قديمة. clock skew يعيد الخطأ الذي يتعامل معه Flutter؛ response يعيد upload acknowledgements والحالة الحالية عند التعارض.
- **الأثر:** `POST /api/v1/sync/recently-watched`؛ جميع القراءة والكتابة revisions owner-scoped. لا يعني هذا اكتمال cross-device/offline orchestration الذي يبقى Phase07.

## D-017 — Account export format v2

- **التاريخ:** 23 نوفمبر 2026
- **الحالة:** تنفيذ أضيف إلى main؛ CI غير معتمد بعد.
- **القرار:** تم تحديث `GET /api/v1/user/data-export` إلى `format_version=2` ويضم bookmarks وrecently_watched إضافة إلى account/profile/preferences/devices. يستمر استبعاد password وgoogle_id وremember_token وSanctum tokens/secret values.
- **التوافق:** يسجل D-014 صيغة v1 التاريخية الخاصة بـPhase05؛ لا يحذف القرار السابق. صيغة v2 هي الحالية بعد إضافة Phase06.


## D-018 — Owner-scoped playlist operation sync

- التاريخ: 23 نوفمبر 2026
- الحالة: تنفيذ أضيف إلى main؛ CI غير معتمد بعد.
- القرار: تحفظ القوائم تحت user_playlists مع owner FK cascade؛ العناصر تحت user_playlist_items بمفتاح (playlist_id, media_type, media_id). سجل operation log يفرض unique (user_id, operation_id) ويحفظ payload hash والنتيجة؛ reuse لنفس operation_id مع محتوى مختلف يرفض.
- المزامنة: domain revision منفصل في user_sync_revisions، وسجل تغييرات غير قابل للتغيير في user_playlist_changes. يستخدم since_revision/until_revision/cursor لتثبيت snapshot؛ سجلات القوائم تعاد في الصفحة الأولى وتُقسم سجلات العناصر إلى صفحات لتوافق PlaylistSyncEngine.
- التعارض: update/reorder يحمل base_version؛ النسخة المتقادمة ترجع version_conflict/current_version.
- القيود: 200 قائمة/مستخدم، 2500 عنصر/قائمة، 500 operation/request؛ media_type هو movie/tv فقط.
- خارج النطاق: TMDB/IMDb imports ومشاركة القوائم العامة لم تنفذ.

## D-019 — Account export format v3

- التاريخ: 23 نوفمبر 2026
- الحالة: تنفيذ أضيف إلى main؛ CI غير معتمد بعد.
- القرار: GET /api/v1/user/data-export أصبح format_version=3 ويضم account/profile/preferences/devices/bookmarks/recently_watched/playlists.
- الأمان: لا تصدر كلمات المرور أو google_id أو remember_token أو Sanctum token hashes أو أسرار التحقق/الاسترداد.
- التوافق: D-014 v1 وD-017 v2 تاريخيان؛ v3 هو العقد الحالي بعد إضافة Playlist sync.

## D-020 — توحيد الاختبارات على main وإيقاف فروع TEST/USERS
- التاريخ: 23 نوفمبر 2026
- الحالة: معتمد
- القرار: جميع التطبيق وmigrations والوثائق واختبارات Feature/Integration/contract/regression تحفظ في الفرع الدائم الوحيد `main`. لا تستخدم فروع TEST أو USERS دائمة ولا تنشئ فروع اختبار جديدة.
- السبب: وضع الاختبارات بجوار الوظائف يقلل تشتت المصدر وازدواج workflows ومشكلات مزامنة الفروع.
- التنفيذ: نُقلت ثمانية ملفات اختبار من TEST إلى main في SHA `800cff70b9663815e57b47db2be8340e8c19215e`؛ اختبارات Auth كانت موجودة في main مسبقًا.
- الأثر: Docker/Pint/Security/Auth regression workflows تستهدف main. refs TEST وUSERS متقاعدة لكن حذفها عملية منفصلة يجب التحقق منها.

## D-021 — Queue contract لإشعارات التحقق وإعادة تعيين كلمة المرور

- **التاريخ:** 23 نوفمبر 2026
- **الحالة:** معتمد ومطبق على main SHA `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- **القرار:** ترسل إشعارات `EmailVerificationNotification` و`PasswordResetNotification` عبر Laravel database queue على queue `mail`، وتنفذ بعد commit. كلا الإشعارين `ShouldBeEncrypted` حتى لا يخزن payload queue روابط التحقق/الاسترداد كنص مكشوف.
- **سياسة المحاولات:** 5 tries، maxExceptions=3، timeout=30s، failOnTimeout، backoff=[10,30,90,180]، وretry deadline ثابت بعد 20 دقيقة من إنشاء الإشعار.
- **العامل:** تشغيل منفصل عبر `/usr/local/bin/raven23-queue-worker`، ولا يُدمج مع HTTP process. يرفض sync/null ويمنع timeout يساوي/يتجاوز database retry_after.
- **التوافق:** يعاد استخدام جداول Laravel القياسية jobs/job_batches/failed_jobs؛ لا migration جديدة ولا تغيير API response.
- **حدود القرار:** mail enabled يبقى false وMAIL_MAILER=log حتى اختيار مزود وموافقة وإعداد action URL وتجربة staging. لا يجيز إنشاء موارد مدفوعة أو قراءة/إضافة secrets. ولا يثبت SMTP delivery أو exactly-once semantics.

## D-022 — Secure Push subscription registry before provider activation

- التاريخ: 24 نوفمبر 2026
- الحالة: تنفيذ محلي واختبارات CI ناجحة على SHA df390286cbe2170d4b18a96dad06835379091019؛ provider delivery remains WAITING_EXTERNAL.
- القرار: يطابق Backend مسارات تطبيق Flutter لتسجيل FCM token وOneSignal subscription UUID. تحفظ قيمة token عبر Laravel encrypted cast، ويحسب HMAC-SHA-256 scoped بالـprovider لتحديد التسجيلات المتكررة دون فهرسة token الخام. unique(provider, token_hash) يجعل التسجيل idempotent.
- ملكية الرمز: إعادة التسجيل من جلسة مصادق عليها تنقل association إلى المستخدم الحالي، بما يسمح بتبديل الحساب على نفس التطبيق. OneSignal unregister لا يحذف إلا التسجيل المرتبط بالمستخدم المصادق؛ حذف الحساب يحذف الصفوف عبر FK cascade.
- حدود القرار: لا يفعّل هذا القرار FCM/OneSignal sender ولا يرسل Push ولا يضيف credentials. لا تتم أي عملية خارجية قبل اختيار provider والموافقة وإعداد staging.
- الأثر: migration 2026_11_24_000015_create_user_push_subscriptions_table.php، model/controller/routes، Feature/PostgreSQL tests وFlutter contract match. CI الأربع ناجحة على SHA المذكور.


## D-023 — Bounded transient retry policy for offline sync

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** مطبق ومختبر على Flutter main SHA `027f51a3a0fb3f0301e83eb3c71a19c045984447`؛ التشغيل [45324044184](https://github.com/m5ham3ds/RAVEN23/actions/runs/45324044184) SUCCESS.
- **القرار:** `SyncRunner` يعيد محاولة أخطاء الاتصال/timeout وHTTP statuses `408, 425, 429, 500, 502, 503, 504` فقط. عدد المحاولات 3، مع exponential backoff 1/2/4 ثوانٍ وبحد delay 8 ثوانٍ؛ يحترم Retry-After ل429 حتى 120 ثانية.
- **الاستثناءات:** لا يعاد الطلب تلقائيًا عند 401/403/409/422، أو malformed/non-Dio errors؛ تبقى recovery الخاصة بالمصادقة والتحقق والتعارض مسؤولية domain engine.
- **عزل الحساب:** كل دورة ترتبط بـLibraryScope owner/generation؛ إذا تغير المالك أثناء الطلب أو backoff لا تستمر المحاولة القديمة. `ownerChanged()` يصفر مؤشرات الدورة والموعد السابق.
- **المقاييس الحالية:** آخر عدد محاولات، مدة الدورة بالميلي ثانية، وعدد دورات الفشل المتتالية؛ محفوظة في ValueNotifiers داخل الذاكرة فقط. لم تُربط بعد بخدمة telemetry مركزية أو تخزين دائم.
- **الاختبارات:** transient retry/backoff، validation non-retry، cancellation on owner change، وpreserved coalescing/owner behavior مغطاة في `test/data/sync_runner_test.dart`، وأُضيفت إلى Android focused pre-build test inventory والـscoped formatter.
- **الأثر على Phase07:** يغلق جانب retry policy الأولي، لكنه لا يغلق end-to-end device-ID rotation/account switching ولا synthetic load/latency/volume/conflict acceptance.


## D-024 — Provider-neutral encrypted Push outbox and default-off delivery

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** مطبق ومختبر على Backend SHA `1d354f7e57ee3b5c03ee003f0fb2dbc1eff78ca3`؛ real provider adapter/delivery WAITING_EXTERNAL.
- **القرار:** جميع Push events تذهب عبر transactional encrypted outbox مع queue باسم `push` وعقد `PushNotificationTransport`. provider وsubscription المستهدف يثبتان في encrypted payload مع HMAC idempotency key ثابت. لا يحتفظ queue payload برموز أو محتوى رسالة مكشوف.
- **الحماية:** الـbinding الافتراضي DisabledPushNotificationTransport؛ `RAVEN23_PUSH_ENABLED=false` و`RAVEN23_PUSH_PROVIDER=none`. العامل يرفض تفعيل Push إذا لم تكن queue `push` ضمن `QUEUE_WORKER_QUEUES`. يفحص delivery وجود subscription وملكيته قبل الإرسال.
- **حدود القرار:** لا FCM/OneSignal sender SDK أو credentials أو actual Android/TV delivery مفعّل. موافقة المزود وإعداد staging اختبار منفصل.

## D-025 — Read-only notification outbox health command

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** مطبق ومختبر على Backend SHA `1d354f7`.
- **القرار:** `notifications:outbox-status` يقدم counts مجمعة فقط حسب type/status، due/scheduled backlog، processing، expired claims، failed/sent، وعمر أقدم pending due. يدعم JSON ولا يعرض user identifiers، email، tokens، encrypted payload، idempotency keys أو last error detail.
- **الغرض:** يتيح تشخيص تراكم الطابور دون استخدام سجلات رسائل المستخدم أو كشف بيانات حساسة.

## D-026 — Pause Push relay while the global delivery switch is disabled

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** مطبق ومختبر على Backend SHA `1d354f7`.
- **القرار:** لا يعيد relay جدولة Push event إذا كان `RAVEN23_PUSH_ENABLED=false`. تظل حالة Outbox و`last_dispatched_at` كما هي، ويُستأنف dispatch فقط بعد إعادة التفعيل. العامل نفسه يتحقق من المفتاح وقت delivery كطبقة حماية ثانية.
- **السبب:** يجب أن يمنع إيقاف الإرسال كلاً من الرسائل الجديدة والقديمة دون فقد الأحداث أو تكرار queue jobs أثناء التعطيل.


## D-027 — تأجيل اختيار FCM أو OneSignal إلى ما بعد بقية نطاق Backend

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** قرار مستخدم صريح؛ لا يوجد مزود مختار.
- **القرار:** تأجيل الاختيار بين FCM وOneSignal إلى ما بعد اكتمال بقية نطاق Backend (المراحل 09–13). لا sender SDK أو credentials أو إرسال خارجي قبل إعادة فتح القرار.
- **الأثر:** يظل Push transport provider-neutral، والـbinding الافتراضي DisabledPushNotificationTransport، و`RAVEN23_PUSH_ENABLED=false`. تبقى Phase08 IN_PROGRESS، لكن لا يمنع هذا القرار تنفيذ المراحل المستقلة اللاحقة ولا يكرر 08G–08I.
- **الحدود:** لا يختار القرار مزود بريد أو Object Storage، ولا يجيز إنشاء حسابات أو موارد أو أسرار أو تكاليف.

## D-028 — بدء Phase09 بقراءة الاستحقاق الموثوقة دون قرارات فوترة

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** مطبق على Backend code commit قيد تحقق CI.
- **القرار:** يمكن تنفيذ entitlement read contract مستقلة عن الخطط والأسعار ومزود الدفع. GET `/api/v1/user/entitlements` يستخدم Sanctum owner فقط، ويحسم `ad_free` حسب سجل حالي وبداية/انتهاء/إلغاء UTC؛ غياب grant يعني no access.
- **الأمان:** لا يوجد endpoint عام للمنح أو الإلغاء؛ لا يستطيع العميل تمرير user_id أو تفعيل ميزة. حالات الاشتراك لا تستنتج من wallet balance أو auto-renew.
- **الأثر:** migration/model/controller/tests تمثل الحالة الفعالة الحالية فقط. سجل الأحداث append-only، مصادر المنح، plan catalog، payment provider/webhooks وسياسات المنتج تبقى في شرائح لاحقة وبعد اعتمادها.


## D-029 — Append-only subscription event ledger without payment-provider coupling

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** تنفيذ 09B قيد CI على Backend commit جديد.
- **القرار:** تسجيل أحداث استحقاق موثوقة في `subscription_events` منفصلة عن current-state projection. الأحداث مدعومة بقيم عامة: granted/revoked/expired/refunded/corrected؛ المصادر العامة internal/coupon/payment/administrative، دون اختيار بوابة أو تفعيل أي مصدر.
- **Idempotency:** unique(source,idempotency_key)؛ إعادة الطلب بنفس المحتوى تعيد الحدث السابق، وإعادة ربط المفتاح بمحتوى مختلف تُرفض.
- **Append-only:** SQLite/PostgreSQL DB guards تمنع UPDATE والحذف المباشر. يسمح user FK cascade بمحو سجل الحساب عند حذف الحساب.
- **الحدود:** لا route عامة ولا webhook ولا wallet/payment execution. ربط ledger بإسقاط `user_entitlements` يأتي في 09C بعد التحقق من 09B.


## D-030 — Atomic event projection and stale-event ordering

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** تنفيذ 09C قيد CI.
- **القرار:** يسجل SubscriptionEventLedger الحدث ويحدّث current entitlement في transaction واحدة. يتلقى كل event aggregate_revision متزايدًا لكل user/entitlement وprojection_status ثابتًا عند الإدخال.
- **الترتيب:** event time الأقدم من last_event_occurred_at يُحفظ stale دون تغيير current projection؛ event replay بالمفتاح نفسه يعيد السجل السابق ولا يزيد revision.
- **الحدود:** لا route عامة ولا webhook أو payment provider؛ event ledger هو السجل الداخلي وuser_entitlements هو read model الحالي.


## D-031 — Preview-first due-entitlement expiry reconciliation

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** تنفيذ 09D قيد CI.
- **القرار:** الأمر `subscriptions:expire-due` يعرض المرشحين فقط افتراضيًا؛ التسجيل يتطلب `--apply`. حد المعالجة الافتراضي 500 وأقصاه 5000 لكل تشغيل.
- **المصدر:** جميع أحداث expired تسجل عبر SubscriptionEventLedger بمفتاح idempotency مرتبط entitlement ID/revision وoccurred_at يساوي وقت expiry. stale guard يحمي أحدث renewal/correction.
- **الحدود:** لا scheduler تلقائي في هذه الشريحة؛ لا مزود دفع أو webhook أو تغيير للأسعار والخطط.


## D-032 — Public plan catalog contract remains disabled until commercial approval

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** تنفيذ 09E قيد CI.
- **القرار:** يوفّر Backend المسار العام `GET /api/v1/billing/plans` ليتوافق مع Flutter، لكنه يعيد فقط `success=true`, `purchases_enabled=false`, `plans=[]`.
- **الأمان والمنتج:** لا يدرج أي سعر أو مدة أو عملة أو wallet limits أو شروط أو provider. لا يستطيع query parameter من العميل تفعيل الشراء.
- **الحدود:** لا يعدّ هذا كتالوجًا تجاريًا معتمدًا ولا يجيز wallet/topup/payment. يتطلب ملء الخطط موافقة صريحة على جميع تفاصيل المنتج أولًا.


## D-035 — إغلاق محفظة القراءة فقط وحماية حذف الرصيد (09F)

- التاريخ: 24 نوفمبر 2026
- الحالة: مكتمل على Backend SHA 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060؛ CI الأربع SUCCESS.
- القرار: محفظة USD cents owner-scoped، قراءة فقط للعميل، ودفتر append-only داخلي ذري/idempotent. لا public top-up أو payment route.
- حذف حساب برصيد موجب يتطلب acknowledge_wallet_credit=true؛ وإلا يرجع 422 مع wallet_credit_remaining. بعد الإقرار يمحو cascade المحفظة والسجل.
- CI: Docker SQLite 144/1,508؛ PostgreSQL 31/541؛ S3Mock 1/8؛ Auth SQLite 20/172 وPG 4/99؛ Pint/Security PASS.
- حدود القرار: لا اعتماد سعر أو خطة تجارية أو PaymentProvider أو شحن أو تجديد تلقائي.

## D-036 — Phase10A وإزالة Composer lock bootstrap المنتهي

- التاريخ: 24 نوفمبر 2026
- الحالة: التنفيذ على main؛ CI للتغيير الجديد pending.
- league/flysystem-aws-s3-v3 مثبت في composer.json وcomposer.lock بالإصدار 3.35.3؛ لذلك لم يعد prepare-s3-composer-lock.yml مطلوبًا.
- كان workflow يملك contents:write وactions:write ويستجيب لتعديل ملفه نفسه ثم ينفذ Composer resolve/commit/push وتشغيل CI.
- القرار: إزالة bootstrap المنتهي؛ تحديث dependencies يستمر عبر commit مراجع وComposer validation/audit المعتاد.
- main غير محمي (protected=false) ولا توجد rulesets. تفعيل required checks يتطلب صلاحيات GitHub admin ويبقى بندًا مفتوحًا في 10B.



## 24 نوفمبر 2026 — Phase10A/10B CI governance

- أُغلقت 10A على SHA 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63 بعد نجاح Docker/Auth/Pint/Security.
- قرار مؤقت: لا نحذف Auth Regression ولا ندمج Security image scan داخل Docker قبل تثبيت required-check contexts؛ توثيق التداخل يسبق حذف إشارات CI.
- قرار توصيف: workflow Pint المنفصل الحالي ينتج artifact من ملفات منسقة ولا يمثل gate صارمًا؛ pint --test داخل Docker هو gate التنسيق.
- حماية main وrequired checks تبقى OPEN؛ GitHub API/tool surface المتاح للقراءة فقط في هذه الإعدادات. يلزم Admin يملك صلاحية settings write.
- direct-to-main single-branch policy لا تمنع فشل commit من الوصول إلى main قبل ظهور نتيجة CI؛ يجب حسم نموذج enforcement قبل اعتبار 10B COMPLETE.
- بدأت 10C باختبار migration rehearsal مستقل ببيانات اصطناعية، من دون الوصول إلى قاعدة الإنتاج.


## D-037 — Migration rehearsal acceptance and API contract source of truth

- **التاريخ:** 24 نوفمبر 2026
- **الحالة:** 10C مكتملة؛ 10D قيد CI.
- **10C:** يعتمد اختبار empty schema وsynthetic legacy snapshot في DatabaseMigrationRehearsalTest كاختبار upgrade rehearsal معزول. لا يستخدم dump أو بيانات إنتاج.
- **النتيجة:** SHA b8c0bb91cea76db003501e81ec30dce09e3bf858 اجتاز Docker/Auth/Pint/Security. SQLite Feature 146/1,548؛ PostgreSQL 31/541 مع optional skip واحد؛ S3Mock 1/8؛ Auth SQLite 20/172 وPG 4/99.
- **10D:** contracts/api-v1.json هو manifest المسارات والطرق وحدود المصادقة؛ contracts/API-V1.md هو وصف الاستجابات؛ ApiV1ContractTest يقارن manifest بالـRouter ويختبر response envelopes.
- **الحدود:** لا يتغير runtime/API behavior أو قاعدة البيانات من مجرد توثيق العقد؛ أي تغيير مستقبلي يجب أن يعدل manifest والوثيقة والاختبار في commit واحد.


## D-038 — API v1 manifest and regression contract accepted

- **التاريخ:** 25 نوفمبر 2026
- **الحالة:** 10D COMPLETE على Backend SHA `44ed546c9881bc2d7ca16d6c97024c6ae77ba047`؛ CI الأربع SUCCESS.
- **القرار:** `contracts/api-v1.json` هو المصدر الآلي لمسارات API v1 (39 route/method/auth records)، و`contracts/API-V1.md` يصف السلوك المرئي للمستهلك. أي تعديل route/method/auth/response shape يجب أن يحدّث manifest والوثيقة واختبارات `ApiV1ContractTest` معًا.
- **إثبات CI:** Docker SQLite 150/1,704؛ PostgreSQL Integration 31/541 مع optional skip واحد؛ S3Mock 1/8؛ Authentication/Pint/Security SUCCESS.
- **الحدود:** لا يثبت هذا القرار توافق كل نسخة Flutter أو تكامل provider staging؛ ولا يغير runtime/controller/migration.

## D-039 — Synthetic PostgreSQL load metrics are a CI baseline, not an SLO

- **التاريخ:** 25 نوفمبر 2026
- **الحالة:** 10E قيد التنفيذ؛ انتظار CI على الاختبار الموسع.
- **القرار:** قياس التزامن في PostgreSQL disposable باستخدام ثمانية عمال PHP مستقلين، مع توثيق request/response bytes، P50/P95 (nearest-rank)، average/max latency، wall-clock duration، throughput، stale-conflict rate وwinning timestamp.
- **السبب:** يجب أن تكون التجربة قابلة لإعادة التشغيل وتعطي توزيعًا للزمن وحجم الحركة، لا متوسطًا منفردًا فقط.
- **الحدود:** لا يُفرض latency SLO قبل اعتماد حمل متوقع، ولا تُستخدم production DB أو production API؛ نتائج CI لا تمثل السعة الإنتاجية.


## D-040 — Phase10E synthetic PostgreSQL load measurement accepted

- **التاريخ:** 25 نوفمبر 2026
- **الحالة:** COMPLETE على SHA `f912dd2479a5f86471e1e8999b2d36c7611d41cb`؛ CI الأربع SUCCESS.
- **القرار:** يحتفظ الاختبار ذو 8 عمال مستقلة بقياسات حجم الطلبات/الاستجابات، زمن التزامن الكلي، throughput، P50/P95 nearest-rank، average/max latency، stale conflict count/rate والنسخة الفائزة.
- **النتيجة:** 8/8 نجاح؛ 188.248ms wall clock؛ 42.497 requests/sec؛ 4,191 request bytes؛ 546 response bytes؛ P50 143.150ms؛ P95 177.715ms؛ average 143.856ms؛ max 177.715ms؛ 5/8 conflicts (62.5%)؛ winning timestamp=5000.
- **الحدود:** PostgreSQL disposable وworkload اصطناعي صغير؛ لا يثبت production capacity أو SLO.

## D-041 — Bind the exact tested image to a source SHA and signed build evidence

- **التاريخ:** 25 نوفمبر 2026
- **الحالة:** 10F COMPLETE على SHA `c01a4bad1e4007dd8c9cb46bb56d5344f188f06c`؛ Docker/Auth/Pint/Security SUCCESS.
- **القرار:** يظل build workflow لا ينشر image إلى registry. يحفظ الصورة التي اجتازت runtime/functional tests تحت local SHA tag، ويسجل Docker config digest وSHA-256 للـarchive، وينشئ CycloneDX SBOM وprovenance يربط المصدر وملفات البناء وbase-image digest بالمخرجات.
- **الإثبات:** GitHub Actions signed artifact attestations لصورة archive ولـSBOM؛ يتم حفظ الملفات والbundles كـartifact محدد بالـSHA.
- **الصلاحيات:** contents:read وid-token:write وattestations:write وartifact-metadata:write فقط؛ لا packages:write ولا registry push.
- **الحدود:** Docker config digest ليس OCI registry manifest digest. لا يُدّعى وجود registry manifest digest حتى يوجد registry publishing معتمد. Artifact SHA-256 يحدد الحزمة الدقيقة التي اختُبرت.


## D-042 — Release manifest and rollback target must be built and rehearsed without production access

- **التاريخ:** 25 نوفمبر 2026
- **الحالة:** 10G IN_PROGRESS؛ ينتظر CI إثبات rollback rehearsal والـmanifest والتوقيعات.
- **القرار:** يثبت release manifest كلًا من current tested image والـprevious known-good image بواسطة source SHA وDocker config digest وarchive SHA-256 وCycloneDX SBOM؛ ويربط روابط GitHub CI وartifact attestations. يُعاد بناء rollback target من commit سبق أن اجتاز بوابات CI الأربع، ويُختبر traffic switch محليًا في حاويتين مع loopback-only proxy.
- **إجراء التجربة:** إيقاف current candidate عمدًا، إثبات أن مسار HTTP يعطي 502 عند تعطل backend النشط، ثم تبديل ملف active-port ذريًا إلى rollback target وإثبات HTTP 200 من الصورة السابقة.
- **أدلة SBOM:** تستخدم صورة الإصدار الحالية Trivy، بينما يولد Anchore Syft v1.57.1 SBOM rollback من Docker archive الممهور ذاته بصيغة CycloneDX JSON، مع فحص وجود قائمة مكونات غير فارغة. يظل فحص ثغرات الصورة باستخدام Trivy منفصلًا وإلزاميًا.
- **معالجة فشل أول محاولة:** Trivy 0.76.2 خرج برمز 1 عند توليد SBOM لصورة rollback على SHA 018edc6 بعد تعرفه على نظام التشغيل وملفات الحزم؛ لا يوجد في السجل خطأ صريح، ولم تصل الوظيفة إلى traffic rehearsal. استُبدل مسار توليد SBOM للـrollback بـSyft؛ لا يجري تخفيض فحص Security ولا تجاهل نتيجة الفشل.
- **الحدود:** هذه تجربة isolated container/proxy وليست إثباتًا لـcloud load balancer أو deployment rollback؛ لا production DB/secrets/AWS/registry push. لا يُسمح بتوسيعها إلى بيئة حقيقية دون موافقة مستقلة.


## D-043 — قبول دليل التحقق المحلي لـPhase11C دون تفويض provisioning

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** provider-neutral IaC baseline اجتاز CI؛ Phase11C تبقى IN_PROGRESS وليست COMPLETE.
- **القرار/التفسير:** تُقبل نتائج fmt/init/validate/provider-free plan و18/18 guardrail regression كدليل على سلامة scaffold المحلي وحدود منع التجاوز فقط. لا تُعتبر اختياراً لمزود، ولا موافقة لإنشاء backend أو adapter خاص بمزود، ولا تصريحاً بتشغيل apply أو staging.
- **الإثبات:** [IaC run 45754424342](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45754424342) على `f95e361`؛ compare إلى رأس main `c709ba7` أثبت أن الفرق اللاحق اقتصر على مواصفة Phase11. البوابات الأربع الحالية ناجحة على SHA `c709ba7`.
- **الاعتماديات المتبقية:** provider/region/workload/data growth/retention/HA/RPO/RTO/monthly cost envelope. تبقى 11D WAITING_EXTERNAL؛ و11B2 مشروطة بإغلاق 10B يدوياً كما قرر المالك.
- **حدود البيانات:** لا apply أو cloud resources أو credentials/secrets أو registry push أو production database أو DNS أو deployment.

## D-044 — اختبار queue worker حتى retry exhaustion وdead-letter

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** COMPLETE على SHA `1e79bb3591ca721fb824c4b498dbef5990c145aa`.
- **القرار:** يجب أن يختبر acceptance الخاص بالـoutbox worker مسار Laravel database queue الفعلي، وليس dispatch fake فقط. يشمل الاختبار transport فاشل، backoff، stable idempotency key، حد الاستثناءات، حالة outbox النهائية وfailed_jobs.
- **الدليل:** focused suite 16/119؛ full SQLite suite 151/1,719؛ Docker [45850009753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850009753) SUCCESS.
- **الحدود:** fake transport فقط؛ لا delivery provider أو claim of provider-native exactly-once.

## D-045 — تحديث Symfony إلى إصدارات خالية من advisories

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** COMPLETE على SHA `1e79bb3591ca721fb824c4b498dbef5990c145aa`.
- **القرار:** Composer audit يبقى blocking. تُحدّث Symfony packages المتأثرة إلى `v8.1.12` بدل تجاهل advisories أو إضعاف Security gate.
- **الإثبات:** تحديث سبعة lock entries، خمس حزم Symfony ذات صلة أصبحت `v8.1.12`، و`composer audit` أظهر عدم وجود advisories؛ Security [45850017359](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850017359) وDocker [45850009753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850009753) SUCCESS.
- **الحدود:** Trivy output موثق كما هو؛ لا تدعي هذه المعالجة أن كل فئات/شدات التقارير غير الحاجزة فارغة.

## D-046 — إعادة تأسيس rollback على آخر baseline خالٍ من advisories

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** baseline CI COMPLETE؛ rollback traffic rehearsal والتوقيعات ينتظران تشغيل push التالي بعد إعادة التفعيل.
- **القرار:** لا يُستخدم rollback target قديم يحمل dependency advisories كإصدار تراجع صالح. بعد اجتياز Docker/Auth/Pint/Security على `1e79bb3`، يُعيّن هذا SHA كهدف سابق ويُعاد تفعيل rollback rehearsal وrelease attestations دون خيار skip مؤقت.
- **الدليل التمهيدي:** Docker/Auth/Pint/Security runs [45850009753](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850009753)، [45850013586](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850013586)، [45850021125](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850021125)، [45850017359](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45850017359).
- **حدود:** Docker run التمهيدي نفسه تخطى rollback/release evidence مؤقتًا؛ إغلاق القرار بالكامل يتطلب نجاح التجربة الجديدة `200 → 502 → 200` والتوقيعات على commit الحالي. لا production deployment أو registry push.


## D-047 — Phase12 synthetic PostgreSQL backup/restore rehearsal boundary

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** المحاولة الأولى على `19bbeea` فشلت قبل تشغيل التجربة لأن صورة `postgres:16-alpine` لا تتضمن أمر `openssl`. عُزل السبب، وأضيف تثبيت أداة التشفير داخل حاوية CI المؤقتة قبل إعادة تشغيل الاختبار؛ النتيجة النهائية ما زالت تنتظر تشغيل SHA المصحح.
- **القرار:** إضافة اختبار backup/restore قابل للتكرار لقاعدة PostgreSQL مؤقتة فقط، مع synthetic rows، custom-format pg_dump، تشفير مؤقت، SHA-256 round-trip، pg_restore لقاعدة منفصلة، ومقارنة data/constraints/indexes.
- **حاجز الأمان:** السكربت يرفض أي host/database/user/password عدا PostgreSQL CI service المحددة. قاعدتا الاختبار المؤقتتان تُحذفان آليًا عند الخروج، والتقرير لا يحتوي الأسرار أو بيانات أشخاص.
- **الحدود:** لا يثبت هذا backup إنتاجيًا أو توافق مصدر/وجهة فعلية أو Phase12 completion، ولا يسمح بقراءة DB حقيقية أو cutover. تظل Phase12 WAITING_DEPENDENCY على 04–11 وقرار مصدر/وجهة وrestore معزول وموافقة cutover مباشرة.


## D-048 — إضافة أداة OpenSSL إلى حاوية PostgreSQL المؤقتة

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** إصلاح CI committed على `main`؛ ينتظر التحقق الثاني.
- **السبب المثبت:** فشل خطوة Phase12 synthetic restore على SHA `19bbeea63b28a0e541996cda1c5f9d813a9c8934` برسالة `openssl: not found` من صورة `postgres:16-alpine`. لم تبدأ عملية dump/encryption/restore.
- **الإصلاح:** تثبيت حزمة `openssl` داخل حاوية PostgreSQL المؤقتة باستخدام `apk add --no-cache openssl`، ثم تشغيل السكربت نفسه دون تغيير حدود الاتصال أو البيانات.
- **الحدود:** لا تغيير على runtime أو migrations أو قاعدة المشروع؛ لا اتصال بقاعدة إنتاج أو أسرار أو بيانات حقيقية.


## D-049 — جعل فشل Phase12 synthetic restore قابلاً للتشخيص

- **التاريخ:** 26 نوفمبر 2026
- **الحالة:** diagnostics patch committed؛ ينتظر التشغيل التالي.
- **السبب:** المحاولة الثانية على `19a3a81` أنشأت الجداول وأدخلت الصفوف الاصطناعية ثم خرجت برمز 1 دون رسالة محددة؛ لم يكن ممكنًا معرفة إن كان الإخفاق في dump أو encryption أو restore أو fingerprint guard.
- **الإصلاح:** إضافة milestones واضحة وعبارات خطأ لكل command، وفحوص archive/checksum/row count/fingerprint مع إظهار اسم وقيم المقارنة فقط (بيانات اصطناعية/sha256)، وتعديل دالة الاستعلام حتى لا تبتلع pipeline حالة فشل psql.
- **الحدود:** لا تغيير runtime أو schema الإنتاجي؛ قاعدة CI المؤقتة وحدها.

## D-050 — تأجيل موحد لجميع اختيارات مزودي الخدمات إلى ما بعد تطوير Backend المستقل عن المزود

- **التاريخ:** 27 نوفمبر 2026
- **الحالة:** قرار مالك صريح ونافذ على جميع المراحل ذات الاعتماد الخارجي.
- **القرار:** لا يختار المالك الآن أي مزود استضافة أو قاعدة بيانات أو تخزين ملفات أو بريد أو Push أو دفع أو registry أو secrets/identity أو monitoring/logs أو DNS/edge أو region أو خطة/تكلفة تشغيلية. تُجمع هذه القرارات في مراجعة واحدة بعد اكتمال جميع أعمال تطوير Backend التي يمكن تنفيذها والتحقق منها دون الاعتماد على مزود خارجي، وقبل تنفيذ provisioning أو staging integrations أو production cutover.
- **النطاق الذي يشمله:** Phase04 (Google Android OAuth/mail provider staging)، Phase08 (S3/mail/Push provider and retention/cost)، Phase09 (PaymentProvider والخطط والشروط التجارية)، Phase11 (hosting/database/region/registry/secrets/identity/monitoring/DNS وprovider-specific adapters/cost scorecard)، Phase12 (source/destination selection وreal backup/restore/cutover)، Phase13 (production operations/DR/monitoring/cost). لا يُختار FCM أو OneSignal أو مزود S3 أو بريد أو دفع أو cloud provider بالافتراض.
- **العمل المسموح الآن:** تطوير واختبار Laravel/API/schema/migrations/contracts والميزات والـprovider-neutral abstractions والـIaC scaffold والاختبارات المحلية/SQLite/PostgreSQL/S3Mock/fake transports والـsynthetic backup/restore وCI، بشرط عدم الحاجة إلى credentials أو موارد خارجية.
- **المحظورات حتى إعادة فتح القرار:** لا provider-specific SDK/sender/payment adapters أو credentials/secrets أو حسابات أو موارد مدفوعة أو remote state/backend أو OpenTofu apply أو OCI registry push أو DNS change أو staging/production deployment أو اتصال/تغيير في قاعدة الإنتاج.
- **السلوك الافتراضي الذي يبقى كما هو:** `RAVEN23_PUSH_ENABLED=false` و`RAVEN23_PUSH_PROVIDER=none`، إرسال البريد مغلق، `purchases_enabled=false` و`plans=[]`، والتخزين المحلي الخاص هو default؛ S3Mock لا يثبت مزودًا فعليًا.
- **أثر الحالة:** لا يُحوّل هذا التأجيل أي مرحلة إلى COMPLETE؛ Phase04 تبقى PARTIAL، Phase08/09/11 تبقى IN_PROGRESS، Phase10B تبقى DEFERRED_BY_OWNER، Phase12/13 تبقيان WAITING_DEPENDENCY. لا تتوقف أعمال التطوير المستقلة عن المزود.
- **إعادة فتح القرار:** بعد إكمال الأعمال المحايدة، تُقدم مقارنة موثقة تشمل المتطلبات والتكلفة والأمن والخصوصية والنقل والاعتمادية، ثم يوافق المالك صراحة على كل حساب/مزود/منطقة/ميزانية/موارد ونطاق تنفيذ على حدة. اختيار المزود لا يعد موافقة على apply أو cutover.
- **العلاقة بالقرار السابق:** يوسع D-050 قرار `PUSH-PROVIDER-SELECTION-DEFERRED.md` المؤرخ 24 نوفمبر؛ لا يلغي أي قرار سابق ولا يعيد تفسير نجاح CI أو mocks على أنها تكامل خارجي.
