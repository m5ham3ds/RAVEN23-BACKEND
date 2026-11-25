# تقرير تحقق إعدادات التخزين المحايدة — Phase11A

- **الحالة:** IMPLEMENTED_AND_CI_VERIFIED؛ Phase11A ما زالت IN_PROGRESS.
- **التاريخ:** 2026-11-25
- **الفرع:** `main`
- **Commit:** `3659843cb7bfbfebba6497d73be1a0dbf9d55440`
- **النطاق:** إعداد S3-compatible object storage، اختبار CI، وتوثيق نقاط الاعتماد.

## 1. النتيجة

أصبحت إعدادات تخزين الملفات الخاصة تقبل أسماء بيئية محايدة عن المزود مع الحفاظ على توافق الإعدادات القديمة. استخدم اختبار S3Mock أسماء الإعدادات المحايدة واختبر تكوين Laravel الحقيقي، واجتازت جميع بوابات CI الأربع على SHA نفسه.

## 2. التغييرات

| الملف | التغيير |
|---|---|
| `config/filesystems.php` | تفضيل `RAVEN23_OBJECT_STORAGE_*`، مع `AWS_*` كـfallback |
| `.env.example` | توثيق المتغيرات العامة وإبقاء local private disk هو الافتراضي |
| `.github/workflows/docker-build.yml` | تشغيل S3Mock بمتغيرات عامة وتمريرها إلى حاوية الاختبار |
| `tests/Integration/S3PrivateObjectStorageTest.php` | اختبار تكوين Laravel المحمّل بدلاً من بناء S3 disk داخل test |
| `config/services.php` | إزالة تعليق Render القديم من إعداد Google |
| `phases/TOOLS_AND_SERVICES.md` وPhase11 spec | جرد الاعتماديات والقيود ونتائج اختبار قابلية النقل |

المتغيرات الجديدة هي:
`RAVEN23_OBJECT_STORAGE_ACCESS_KEY_ID`,
`RAVEN23_OBJECT_STORAGE_SECRET_ACCESS_KEY`,
`RAVEN23_OBJECT_STORAGE_REGION`,
`RAVEN23_OBJECT_STORAGE_BUCKET`,
`RAVEN23_OBJECT_STORAGE_URL`,
`RAVEN23_OBJECT_STORAGE_ENDPOINT`,
`RAVEN23_OBJECT_STORAGE_USE_PATH_STYLE_ENDPOINT`.

القرص الخاص ما زال محلياً افتراضياً؛ على المشغل تعيين `RAVEN23_PRIVATE_STORAGE_DISK=s3` فقط بعد إعداد خدمة التخزين وموافقة البيئة. لم يتم تغيير البيئة الحالية أو تفعيل التخزين الخارجي.

## 3. CI evidence

| Workflow | Run | Result |
|---|---:|---|
| Docker Validation | [45629239131](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629239131) | SUCCESS |
| Authentication Regression | [45629244185](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629244185) | SUCCESS |
| Pint Format | [45629238792](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629238792) | SUCCESS |
| Security Scans | [45629238837](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629238837) | SUCCESS |

Docker أثبت 150 اختباراً و1,704 assertions في suite الأساسية، بالإضافة إلى نجاح خطوة S3Mock، واختبارات PostgreSQL/runtime/rollback. لا توجد خطوات فاشلة.

- Rollback: `200 → 502 → 200`.
- Rollback SBOM: Syft v1.57.1، 5,720 components.
- Signed attestations: [72188175](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188175)، [72188186](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188186)، [72188201](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188201)، [72188212](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188212)، [72188225](https://github.com/m5ham3ds/RAVEN23-BACKEND/attestations/72188225).
- Release artifact [15030387429](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45629239131/artifacts/15030387429)، الحجم 562,609,588 bytes، digest `sha256:3d05ead631a50234a99b4745695c60eb44eb064ca4de34c937f812c42428a1b2`.

## 4. ما لا يثبته الاختبار

- لا يثبت S3Mock التكافؤ الكامل لكل مزود في ACL/lifecycle/versioning/retention/metadata أو تسعير التخزين والطلبات والـegress.
- لم يتم اختيار S3 provider أو إنشاء bucket أو تفعيل credentials.
- لم يتم إنشاء موارد أو إجراء نشر.
- لا يُغلق هذا الجزء Phase11A بالكامل؛ cost/portability scorecard ما زال مطلوباً.

## 5. الخطوة التالية

استكمال 11A بمقارنة خيارات التخزين/compute/managed PostgreSQL استناداً إلى region وحجم البيانات والنقل والنسخ الشهرية، دون اختيار provider أو provisioning حتى اعتماد المستخدم.
