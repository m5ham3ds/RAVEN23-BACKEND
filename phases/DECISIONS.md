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
