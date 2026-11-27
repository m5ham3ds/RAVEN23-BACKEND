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

حالة الاختبار: PASS على current main 72730281a6c96208979e4c8a1eaa46fbc723dcdd. سجل التقرير 23 migration files و23 ledger rows و34 public tables؛ encrypted dump round-trip وrestore target المستقل ومقارنات schema/data كلها matched. لا بيانات row-level ضمن artifact. رابط Docker run: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759 ؛ artifact: https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/46077286759/artifacts/15235408250 ؛ digest: sha256:7cf5bf3ec6b5ce338e9c51e43eaa46fbc723dcdd.

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
