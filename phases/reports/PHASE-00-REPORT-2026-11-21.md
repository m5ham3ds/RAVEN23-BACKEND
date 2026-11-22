# تقرير إغلاق المرحلة 00 — جرد المستودع وتثبيت خط الأساس

- **الحالة:** COMPLETE — اكتمل الجرد المصدري الثابت؛ لم تُجرَ اختبارات تكامل إنتاجية.
- **تاريخ البدء:** 2026-11-21
- **تاريخ الإغلاق:** 2026-11-21
- **الفرع:** docs/backend-phased-roadmap
- **Backend baseline SHA:** 9f29fa55afa21c0445be82d4590a4a532bb1747c
- **Android baseline SHA:** 6d12023e446db3d444716c3b407572a89cf545be
- **Pull Request:** https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/1

## 1. النتيجة التنفيذية

اكتمل الجرد الثابت للمستودع الخاص، وملفات Laravel وDocker وmigrations والاختبارات وGitHub Actions، مع مطابقة طبقات Laravel client الرئيسية داخل تطبيق RAVEN23.

النتيجة: Backend الحالي ليس مشروعًا فارغًا؛ يحتوي مصادقة حقيقية ومختبرة. لكنه لا يقدم معظم المجالات التي يتوقعها تطبيق Android للمفضلة وسجل المشاهدة والتقدم وwellness والقوائم والإعدادات والإعلانات والإشعارات والاشتراكات والمحفظة. كما أن المشروع ليس Laravel self-contained في الوقت الحالي بسبب غياب Composer manifests واعتماد Docker على إنشاء skeleton أثناء البناء.

تم توثيق هذه الفجوات وتحديدها كأعمال تنفيذ لاحقة. لا توجد حاجة لإعادة كتابة المصادقة من الصفر.

## 2. نطاق الجرد والدليل

### Backend

- تم طلب Git tree recursive على commit الأساس؛ النتيجة غير truncated.
- العدد المؤكد: **34 ملفًا tracked في 17 مجلدًا**.
- من بينها **10 GitHub Actions workflows** و5 migrations وملفا اختبار.
- كل الملفات المتتبعة تظهر في BACKEND_FILE_CATALOG.md، مع تصنيف مكوناتها في ملفات الجرد المتخصصة.

### Android client

- تم فحص Git tree recursive لمستودع RAVEN23 عند SHA 6d12023e446db3d444716c3b407572a89cf545be؛ tree غير truncated واحتوى 2,085 ملفًا tracked.
- تمت مراجعة طبقات Laravel client الرئيسية: AuthRepository، LaravelApi، repositories للمفضلة والقوائم والسجل وwellness والاشتراكات والأجهزة والإعلانات والإعدادات والرسائل وFlix AI، sync engines/transport/coordinator، feature gates، Firebase session bridge، integration tests.
- تم فصل Scraper API v2 وTMDB requests عن Laravel account/data API.

### قاعدة البيانات والبيانات

- تمت مراجعة migrations الخمس وUser model وRaven23AuthSchema واختبارات SQLite/PostgreSQL.
- تم توثيق الجداول والحقول والقيود التي يمكن إثباتها من المصدر.
- لم يتم الاتصال بNeon الإنتاجية أو استعراض users/tokens أو counts أو البيانات الشخصية؛ هذا حد أمان مقصود وليس نسيانًا في التنفيذ.

### البيئة والأسرار

- تم حصر أسماء environment variables وGitHub Secrets التي تظهر في ملفات Backend وFlutter ذات العلاقة.
- لم تتم قراءة أي secret value أو DB URL أو APP_KEY أو OAuth secret.

### Workflows وCI

- تمت مراجعة 10 workflows وتصنيف triggers وpermissions وبيئة PostgreSQL المؤقتة وRender/Neon coupling والتكرار.
- تم فحص سجلات CI الأساسية والنتائج التاريخية المعروفة.

## 3. المخرجات النهائية للمرحلة

- phases/BACKEND_FILE_CATALOG.md
- phases/API_CONTRACT_INVENTORY.md
- phases/DATABASE_AND_DATA_INVENTORY.md
- phases/ENVIRONMENT_AND_SECRET_NAMES.md
- phases/WORKFLOW_INVENTORY.md
- phases/FEATURE_MATRIX.md
- phases/BASELINE_AUDIT.md
- phases/STATUS.md
- phases/MASTER_PLAN.md
- phases/PHASE-00-BASELINE-INVENTORY.md

## 4. أدلة CI المرجعية

- Backend Docker Validation #182 على 9f29fa55afa21c0445be82d4590a4a532bb1747c: SUCCESS — 11 Feature tests / 68 assertions، و3 PostgreSQL integration tests / 72 assertions.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418246
- Live Authentication Smoke #127 على SHA نفسه: SUCCESS — GET-only health/database/schema/commit/routes check على Render.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44905418305
- Roadmap PR validation #183 على 0f650a667e981c5e5a4341d33a17dcc3ae27ee76: SUCCESS.
  https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44945588390

هذه الأدلة تخص الاختبارات الموجودة فقط. لا تثبت Google OAuth الحقيقي أو البريد أو مزامنة المجالات الناقصة أو الدفع أو النسخ الاحتياطي.

## 5. نتائج ومخاطر يجب أن تنتقل إلى المراحل اللاحقة

1. المشروع غير self-contained: لا composer.json/lock في الجذر، وDocker ينشئ Laravel skeleton أثناء build.
2. runtime يستخدم PHP built-in server؛ يجب اختيار Nginx/PHP-FPM أو FrankenPHP في Phase 02.
3. startup مرتبط بـ Render/Neon ويشغل migrations تلقائيًا.
4. verify-auth-schema.php ينشئ probe rows ثم يحاول تنظيفها في كل startup؛ لا تعتبره health check read-only.
5. public health/error responses تكشف معلومات runtime/source أكثر مما يلزم.
6. file cache محلي وغير مشترك بين عدة instances.
7. بعض مسارات المصادقة موجودة لكن reset/verification غير مكتمل، وFirebase exchange غير موجود، وإدارة الأجهزة/حدودها جزئية.
8. كثير من Laravel paths التي يطلبها Flutter لا تقابلها routes في Backend الحالي.
9. Flutter default flags عند تعطيل Firebase تشمل auth+ads+sync؛ يلزم ضبط rollout/feature gates قبل تفعيل التطبيق على Backend.
10. خمس workflows production-account retry متشابهة وتكتب على production؛ يجب توحيدها على staging disposable.
11. هناك mismatch في contract: photo_url يرسله RegisterRequest لكنه لا يُحفظ عبر مسار التسجيل في Backend؛ account deletion لا ينفذ wallet credit protection التي يتوقعها العميل.
12. لا يوجد دليل مصدر/CI على backup restore drill أو AWS OIDC deploy حتى الآن.

## 6. ما لم يتم اختباره — ويسجل لاحقًا

- لا اتصال بقاعدة production ولا قراءة user data.
- لا Google OAuth حقيقي على جهاز Android.
- لا إرسال verification/reset email.
- لا تشغيل write integration test على production؛ E2E Flutter opt-in صراحةً للـlocal/disposable staging.
- لا اختبار مزود دفع أو إشعارات/OneSignal حقيقي.
- لا backup/restore rehearsal لأن ذلك يتطلب بيئة معزولة وإعدادًا لاحقًا.
- لا AWS resources أو حساب أو تكاليف تم إنشاؤها.

هذه العناصر ليست blockers لإغلاق الجرد المصدري، لكنها متطلبات لمراحل التنفيذ والتحقق المحددة في MASTER_PLAN.

## 7. معايير القبول

| المعيار | الحالة | الدليل |
|---|---|---|
| تأكيد repository/branch/SHA | PASS | GitHub metadata |
| جرد recursive كامل لـBackend | PASS | Git tree: 34 tracked files، غير truncated |
| جرد workflows/migrations/tests | PASS | WORKFLOW_INVENTORY وBACKEND_FILE_CATALOG |
| خريطة API Backend مقابل Flutter | PASS على مستوى المصدر | API_CONTRACT_INVENTORY |
| توثيق DB من migrations/models | PASS على مستوى المصدر | DATABASE_AND_DATA_INVENTORY |
| جرد أسماء environment/secrets | PASS | ENVIRONMENT_AND_SECRET_NAMES؛ لا قيم |
| تصنيف الميزات الموجودة/الناقصة | PASS | FEATURE_MATRIX |
| تصنيف CI التاريخي | PASS | WORKFLOW_INVENTORY وروابط runs |
| اختبار live database schema وbackup restore | NOT TESTED BY DESIGN | مؤجل إلى staging/backup gates ولا يلزم للجرد |

## 8. قرار إغلاق المرحلة

**Phase 00 = COMPLETE.** اكتمل الجرد المصدري المطلوب ووُثقت الحدود التي لا يمكن أو لا يجوز التحقق منها عبر الإنتاج. لا يوجد تعديل كود أو تغيير قاعدة بيانات من هذه المرحلة.

**المرحلة التالية المسموح بها: Phase 01 — Reproducible Laravel Project.** يجب الاستمرار من هذا التقرير والـcommit النهائي الذي يضيفه، وعدم إعادة جرد الملفات أو إعادة اختبارات خط الأساس دون تغيير مصدر أو دليل جديد.

## 9. بيانات الاستمرارية

ابدأ Phase 01 من الملفات التالية:
- MASTER_PLAN.md
- USER_RULES_AND_CONSTRAINTS.md
- EXECUTION_PROTOCOL.md
- BACKEND_FILE_CATALOG.md
- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- FEATURE_MATRIX.md

احتفظ بAuthController وUser model وSanctum وGoogle verifier وmigrations والاختبارات الحالية؛ أعد بناء المشروع حولها، ولا تنفذ أي تعديل على قاعدة الإنتاج.
