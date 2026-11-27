# تقرير الانتقال من Phase11 إلى Phase12A — 28 نوفمبر 2026

## الملخص التنفيذي

بناءً على توجيه المالك، جرى إيقاف Phase11 عند حدود الخدمات ومزود الاستضافة، ونقل مسار التطوير النشط إلى Phase12A للأعمال المحايدة للمزود. لا تُسجل Phase11 مكتملة؛ وتبقى مراحل adapters وstaging وdeployment معلقة حسب D-050/D-051.

## الحالة المرجعية والحوكمة

- المستودع: m5ham3ds/RAVEN23-BACKEND.
- الفرع الدائم الوحيد: main؛ آخر branch listing أثناء التحديث أعاد main فقط.
- Commit dbd281c545f5a2da5a6816afccebd4d929a02b70 اجتاز جميع البوابات الخمس:
  - Docker Validation #668 — SUCCESS: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46068443564
  - IaC Validation #31 — SUCCESS: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46068443560
  - Pint #412 — SUCCESS: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46068443597
  - Authentication Regression #290 — SUCCESS: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46068443593
  - Security Scans #414 — SUCCESS: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46068443550
- بعد هذا baseline أضيف اختبار full-schema rehearsal في d227461، ثم صُححت اتصالات CREATE/DROP وتصدير متغير rehearsal في 46dd00b. اجتاز على current main 72730281a6c96208979e4c8a1eaa46fbc723dcdd ضمن Docker Validation #671 SUCCESS. كما اجتاز Docker على commit الوسيط 46dd00b؛ لا حاجة لافتراض نتيجة.
- فحوص IaC/provider-neutral السابقة على 74f57a0 كانت 30/30، ولا تعني اعتماد مزود أو إنشاء مورد.

## Phase11 — ما أُنجز وما أُجّل

- 11A portability baseline و11B1 trust design/attestation separation مثبتان تقنيًا.
- 11C provider-neutral guardrails اجتازت 30/30؛ لا تزال Phase11 ككل غير مكتملة لأن provider adapters/lockfile لا يمكن اعتمادها قبل اختيار الخدمة.
- 11B2 (branch/environment trust enforcement) و11D provisioning و11E real staging و11F production handoff مؤجلة؛ إعداد 10B branch protection اليدوي مؤجل بقرار المالك أيضًا.
- لم ينشأ حساب أو بيئة أو role أو secret، ولم يحدث registry push أو IaC apply أو deploy أو DNS update.

## Phase12A — بدء التحضير المحايد

أضيف scripts/postgres-full-schema-restore-rehearsal.sh إلى main وربط بـ .github/workflows/docker-build.yml بعد تنفيذ migrations واختبارات PostgreSQL المؤقتة.

الاختبار يقوم بما يلي:
1. يقيد نفسه بقاعدة GitHub Actions PostgreSQL raven23_test على 127.0.0.1:5432 والدور الثابت المعروف، ويرفض أي قيم أخرى.
2. يتحقق من تطابق عدد migration files مع migration ledger.
3. ينشئ clone مصدر من قاعدة CI المؤقتة وtarget مستقلًا فارغًا.
4. ينشئ pg_dump custom archive، يشفره AES-256-CBC/PBKDF2-HMAC-SHA256، ويفك تشفيره ويتحقق من checksum، ثم يستعيده بـpg_restore.
5. يقارن public table inventory، column definitions، constraints، indexes، sequence definitions، triggers، views، per-table row counts، وSHA-256 content fingerprints.
6. يرفع تقريرًا aggregate-only؛ لا يضم row values، ويحذف قواعد المصدر والهدف المؤقتة.

حالة الاختبار: PASS على current main 72730281a6c96208979e4c8a1eaa46fbc723dcdd. سجل التقرير 23 migration files و23 ledger rows و34 public tables؛ encrypted dump round-trip وrestore target المستقل ومقارنات schema/data كلها matched. لا بيانات row-level ضمن artifact. رابط Docker run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759 ؛ artifact: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759/artifacts/15235408250 ؛ digest: sha256:7cf5bf3ec6b5ce338e9c51e43a92fd7317e38339f41d4435e89b38a12cf91d05.

## حدود المرحلة والبيانات

- لا اتصال بقاعدة Neon أو Render أو Firestore/Firebase الإنتاجية.
- لا قراءة أسرار أو متغيرات production أو استخراج بيانات شخصية.
- لا اختيار قاعدة مصدر/وجهة أو provider أو region أو تكلفة.
- لا migration أو restore لبيانات مستخدمين حقيقية، ولا write freeze/CDC/dual-write أو cutover.
- مرحلة 12B تتطلب مصدرًا ووجهة محددين وموافقات مالك، ونسخة مشفرة حقيقية تستعاد إلى بيئة معزولة. مرحلة 12C تتطلب موافقة cutover مستقلة للوجهة والنافذة وخطة rollback.

## الحالة بعد هذا الانتقال

- Phase11: PAUSED / DEFERRED_BY_OWNER عند حد الخدمات الخارجية؛ ليست COMPLETE.
- Phase12: IN_PROGRESS في 12A provider-neutral preparation فقط.
- Phase12B: WAITING_EXTERNAL.
- Phase12C: WAITING_OWNER_APPROVAL.
- Phase13: تبقى لاحقة لـPhase12 والتحويل المعتمد.

أضيفت خريطة source-level mapping في phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md. اجتاز current main Docker #671 وAuth #293 وSecurity #417 وPint #415؛ IaC #31 بقي SUCCESS على dbd281c لأن infra files لم تتغير. هذا لا يمثل ترحيلًا إنتاجيًا أو تصريح cutover.


## خريطة البيانات والـintegrity — 28 نوفمبر 2026

أنشئ \`phases/PHASE-12-DATA-MAPPING-AND-INTEGRITY.md\` لتثبيت المجالات والجداول والحقول التي يجب الحفاظ عليها: identity/auth، profiles/preferences/devices، bookmarks/recent/playlists/wellness، private media، notifications/push، entitlements/subscription ledger، wallets، وLaravel queue/session/cache.

وثّقت أيضًا أن \`GET /api/v1/user/data-export\` format_version 3 لا يشمل كل قاعدة البيانات؛ يجب عدم استخدامه بدل full database backup. المصدر والوجهة الحقيقيان يظلان UNKNOWN_PENDING_SOURCE_APPROVAL / UNKNOWN_PENDING_PROVIDER_DECISION.

الاختبار الكامل يثبت schema/data integrity لقاعدة CI المؤقتة فقط، ولا يثبت source production ولا object blob transfer ولا CDC/delta ولا key continuity ولا cutover.


## أدلة تطبيق Flutter وsequence-state — 28 نوفمبر 2026

تم فحص مستودع التطبيق قراءةً فقط على m5ham3ds/RAVEN23@387feafb4865d991223897088b82eacb7a42061a. يصف PRD سابقًا Firebase Auth/Firestore collections؛ ويحتوي التطبيق المحلي على قواعد SQLite للمفضلة والمشاهدة وwellness والقوائم، مع LaravelSyncCoordinator وLegacyFirebaseSession. لا يثبت ذلك أي مصدر إنتاجي حي. كما أن Firebase Auth Scrypt import في PRD يحتاج حقولًا غير موجودة في مخطط Backend الحالي، وسُجلت كفجوة شرطية لا تُعالج قبل قرار المصدر.

في current main commit 6750eac566c513c4fc97951f1b80cb7f54cd0c0a:
- Docker #675 full-schema step + artifact upload PASS، report schemaVersion 2.
- 23 migration files/ledger rows، 34 public tables، 21 sequences، last_value/is_called matched.
- schema manifest cfeed3162273eefebe90da3fdd3580bbecdc9bf9956ffeb69b51e9210c36c3c0؛ data manifest 274777e346f2ff12d80e206ac4458690f49b19075c41e0caa3c3e688384b0179؛ sequence manifest c1c26ace11d8e7b1afeade4b82317767eec87d651062570a96f76cda5c2de0dd.
- Artifact #15236350311 digest sha256:907d099c910c463919fea0842a7b43f22723a356c9f756d304ec09ded778a0b6: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654/artifacts/15236350311
- Docker #675 overall still executing rollback/trusted-main signing at last poll; Pint #419/Auth #297/Security #421 passed. No production DB/secrets/rows, no cutover.


## إغلاق بوابة Docker #675 والتحقيق في التشغيل الوسيط — 28 نوفمبر 2026

Docker Validation #675 على 6750eac566c513c4fc97951f1b80cb7f54cd0c0a اكتمل SUCCESS، بعد إصلاح Docker #674 الذي فشل في sequence-state query لأن script حاول قراءة parameter $2 غير الممرر. الإصلاح جعل SQL query file name مشتقًا من database argument. أعيد full-schema test ونجح مع 21 sequences، ثم نجح rollback والتوقيع الموثوق.

- Docker #674 failure: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078538941
- Docker #675 success: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46078577654
- Phase12 report artifact #15236350311, SHA-256 907d099c910c463919fea0842a7b43f22723a356c9f756d304ec09ded778a0b6.

بوابات Pint #419/Auth #297/Security #421 نجحت على runtime SHA نفسه. IaC #31 بقيت على dbd281c لأن ملفات IaC لم تتغير. رأس main الحالي 522f967 وثائقي فقط بعد runtime SHA 6750eac.
