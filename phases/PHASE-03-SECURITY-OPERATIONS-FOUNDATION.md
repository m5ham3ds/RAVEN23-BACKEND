# المرحلة 03 — الأمن والتشغيل والرصد الأساسي

**الحالة:** COMPLETE على مستوى الكود وCI — إغلاق 22 نوفمبر 2026 على PR #4  
**الاعتماديات:** المرحلتان 01 و02 COMPLETE

## الهدف
تقليل سطح الهجوم وحماية بيانات المستخدم والأسرار وتوفير تشخيص آمن لا يكشف تفاصيل البنية للعميل.

## الأعمال
1. مراجعة authentication/authorization وmiddleware وCORS وTLS وtrusted proxies حسب طبيعة API.
2. تقوية rate limiting حسب IP والحساب والجهاز مع حدود قابلة للضبط ومنع account enumeration.
3. إخفاء hostname واسم قاعدة البيانات والمخطط وinstance IDs ومعلومات المصدر من health/error responses العامة.
4. إخراج structured logs إلى stderr/collector مع request/correlation ID، ومنع تسجيل passwords/tokens/emails/SQL bindings.
5. فصل liveness عن readiness عن diagnostics المحمية.
6. مراجعة security headers وحجم الطلب والمهل والتحقق من المدخلات.
7. إعداد authorization policies وmass-assignment protections لكل domain.
8. تشغيل Composer audit وsecret scanning وcontainer scanning وstatic analysis تدريجيًا.
9. مراجعة صلاحيات database user ومبدأ أقل صلاحية؛ لا تستخدم superuser للتطبيق.
10. توثيق سياسة الاحتفاظ بالسجلات والنسخ الاحتياطية والتشخيص.

## معايير القبول
- لا تتسرب أسرار أو بيانات شخصية في logs أو responses.
- endpoint العام لا يكشف بنية الاستضافة.
- اختبارات rate limits وauthorization وvalidation ناجحة.
- secret/dependency/container scans تعمل وتنتج تقارير قابلة للمراجعة.
- إعداد الإنتاج لا يعمل مع APP_DEBUG=true.
- لا يستطيع مستخدم الوصول إلى بيانات مستخدم آخر عبر تغيير ID.

## قاعدة
لا تضعف الأمان لتجاوز فشل اختبار؛ أصلح السبب وأضف اختبار regression.


## سياسة التشغيل والأمن

راجع `phases/SECURITY-OPERATIONS-POLICY.md` لتفاصيل فصل `DB_URL` عن `DB_MIGRATION_URL`، وفرض TLS لاتصالات PostgreSQL البعيدة، وحدود سجلات التطبيق، ومتطلبات retention/backup/restore. قيم الاحتفاظ وRPO/RTO وإعدادات مزود الإنتاج تبقى قرارات خارجية تنتظر الاعتماد والتحقق؛ لا يدّعي CI أنه اختبر مزود الإنتاج.


## نتيجة الإغلاق — 22 نوفمبر 2026

**SHA النهائي الذي اجتاز جميع بوابات CI:** `6b14d55fb87bdd6948b0f896db9a4c99c43f7748`

| بوابة القبول | الدليل | النتيجة |
|---|---|---|
| Docker build + PHP lint + Artisan/routes + SQLite Feature | Docker Validation #291، run `44985067812` | PASS — 34 tests / 210 assertions |
| PostgreSQL 16 integration | Docker Validation #291، run `44985067812` | PASS — 3 tests / 72 assertions |
| PostgreSQL least-privilege | نفس Docker run؛ app/migrator roles منفصلان ومحاولات CREATE ROLE/CREATE DATABASE مرفوضة | PASS |
| Runtime/APP_DEBUG/readiness/non-root/PHP asset blocking | نفس Docker run | PASS |
| Pint | Pint #27، run `44985067768` | PASS |
| Secret/dependency/filesystem/image scans | Security #29، run `44985067818` | PASS؛ اجتازت بوابة الثغرات HIGH/CRITICAL القابلة للإصلاح |

تمت إضافة Phase 03 إلى filters الخاصة بـDocker/Pint/Security حتى تعمل البوابات على PRs المبنية على هذا الفرع، كما ثبتت نجاحاتها على SHA أعلاه.

## ما لم يتم اختباره على مزود الإنتاج

- لم يتم الاتصال بـNeon/Render أو قراءة secrets أو تغيير إعدادات حساب DB الفعلي.
- TLS الطرفي وCIDRs الخاصة بالـtrusted proxy لم تُفحص لدى مزود الاستضافة.
- Log collector/retention وBackup/PITR/restore rehearsal وRPO/RTO تحتاج اختيارًا واعتمادًا وفحصًا قبل الإنتاج.
- نجاح Trivy لا يعني صفر ثغرات؛ إنه يثبت نجاح scan وبوابة الثغرات القابلة للإصلاح وفق قاعدة الفحص المستخدمة في هذا التشغيل.

هذه العناصر تبقى **قائمة ما قبل الإنتاج** في `SECURITY-OPERATIONS-POLICY.md`؛ ولا تمنع بدء Phase 04 في بيئات CI/staging المعزولة.

## القرار

Phase 03 = **COMPLETE (code and CI)**. PR #4 يظل Draft/Open/Unmerged. Phase 04 مسموح بها، وبدأت في PR #5 فوق فرع Phase 03. لا نشر ولا production DB changes.
