# مساحة مراحل تطوير RAVEN23 Backend

> المستودع: m5ham3ds/RAVEN23-BACKEND  
> الفرع الأساسي: main  
> latest fully CI-verified backend SHA: 74f57a01c1475c66a32337dc6be549b187a23344 — 27 نوفمبر 2026  
> application/runtime note: commit 617ce7e is documentation-only relative to 27cfcc9; application/runtime code was not changed in this documentation synchronization  
> الفرع التشغيلي الوحيد: main

هذا المجلد هو المرجع الرسمي لخطة تطوير Backend، والأدوات، وقرارات التصميم، وقواعد المستخدم، وحالة كل مرحلة، وأدلة اكتمالها.

## ابدأ بالقراءة بهذا الترتيب

1. MASTER_PLAN.md — الخطة الشاملة وتقسيم المراحل واعتمادياتها.
2. STATUS.md — الحالة الحالية لكل مرحلة وما يمنع البدء أو الإغلاق.
3. BASELINE_AUDIT.md — نتائج مراجعة المستودع وحدود التحقق.
4. BACKEND_FILE_CATALOG.md — فهرس كل الملفات المتتبعة في Backend عند خط الأساس.
5. API_CONTRACT_INVENTORY.md — مطابقة المسارات الحالية مع طلبات تطبيق RAVEN23.
6. DATABASE_AND_DATA_INVENTORY.md — جرد الجداول والبيانات والـmigrations.
7. ENVIRONMENT_AND_SECRET_NAMES.md — أسماء متغيرات البيئة والأسرار فقط، دون قيم.
8. WORKFLOW_INVENTORY.md — جرد GitHub Actions والاختبارات وعمليات النشر.
9. FEATURE_MATRIX.md — ما هو موجود ومختبر، وما هو جزئي أو مفقود.
10. TOOLS_AND_SERVICES.md — الأدوات والتقنيات والخدمات الحالية والمقترحة.
11. USER_RULES_AND_CONSTRAINTS.md — قواعد المستخدم وقيوده غير القابلة للتجاوز.
12. EXECUTION_PROTOCOL.md — آلية العمل والاختبار وتسجيل النتائج وإغلاق المرحلة.
13. DECISIONS.md — سجل القرارات المعمارية والمنتج.
14. SECURITY-OPERATIONS-POLICY.md — ضوابط PostgreSQL وTLS والسجلات والنسخ الاحتياطية.
15. PHASE-XX-*.md — مواصفات كل مرحلة ومعايير قبولها.
16. PUSH-PROVIDER-SELECTION-DEFERRED.md — قرار تأجيل اختيار FCM/OneSignal.
17. reports/PHASE_REPORT_TEMPLATE.md — قالب التقرير الإلزامي.
18. reports/PHASES-SOURCE-RECONCILIATION-AND-GLOBAL-PROVIDER-DEFERRAL-2026-11-27.md — أحدث مزامنة للمصدر وقرار D-050.
19. reports/PHASE-11-CI-AND-PROVIDER-NEUTRAL-READINESS-2026-11-27.md — تحقق Phase11 على SHA 74f57a0 ونتائج IaC/CI وحدود ما ينتظر قرار المزود.

## الحالة الحالية

راجع `STATUS.md` بوصفه snapshot موحدًا. Phase00–03 و05–07 COMPLETE؛ Phase04 PARTIAL؛ Phase08 و09 IN_PROGRESS؛ Phase10 IN_PROGRESS مع 10B مؤجلة بقرار المالك؛ Phase11 هي مسار العمل الحالي؛ 11C اجتازت الشريحة المحايدة للمزود على 74f57a0 مع 30/30 guardrails وجميع بوابات CI ناجحة، لكنها تظل IN_PROGRESS حتى اعتماد adapter modules؛ 11D–11F تنتظر D-051 و10B ونتائج staging الفعلية؛ Phase12 و13 تنتظران اعتمادياتهما. قرار 27 نوفمبر 2026 يؤجل جميع اختيارات المزود والمنطقة والتكلفة (hosting/database/storage/mail/push/payment/registry/secrets/monitoring/DNS) إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل، لا بعد نهاية مرحلة منفردة (D-051). لا يوقف ذلك أعمال الكود والاختبارات المحايدة. التفاصيل في `MASTER_PLAN.md` و`DECISIONS.md` وD-050.

`main` هو الفرع التشغيلي الوحيد؛ branch listing الحالي يعيد main فقط. جميع اختبارات Feature/Integration/Auth موجودة على main. أزيلت workflows التي كانت تكتب على Render production؛ راجع `WORKFLOW_INVENTORY.md`.

## قواعد هذا المجلد

- مواصفة المرحلة تُكتب قبل تنفيذها.
- عند إكمال مرحلة أو تعليقها أو حظرها، ينشأ تقرير مستقل جديد داخل reports/.
- لا يُنشأ تقرير نجاح وهمي قبل تنفيذ العمل.
- لا تُحذف التقارير السابقة ولا تُستبدل؛ التصحيحات تكون في تقرير إضافي.
- المرحلة لا تُعتبر مكتملة بمجرد نجاح البناء؛ يجب تحقق معايير القبول مع أدلة مرتبطة بالـcommit الصحيح.
- إذا كانت المرحلة مكتملة برمجيًا لكنها تنتظر حسابًا أو سرًا أو موافقة أو جهازًا حقيقيًا، يُسجل الجزء المنجز والجزء المنتظر بوضوح.
- بعد كل تغيير حالة، يجب تحديث STATUS.md وMASTER_PLAN.md.

## سلامة البيانات

هذه الملفات توثيقية فقط. لا يتم الاتصال بقاعدة الإنتاج أو تعديلها خلال Phase 00. لم تتم قراءة أي قيم secrets أو إنشاء أي موارد AWS.


## قرار المستخدم — تأجيل حماية main

تُنفذ إعدادات Branch protection وRequired status checks يدوياً بعد اكتمال خطة تطوير Backend بالكامل. هذا التأجيل لا يوقف تطوير الميزات أو الاختبارات، لكنه لا يغلق Phase10B ولا يسمح بتجاوزها قبل Phase11B2 والنشر. التفاصيل في `reports/POST-PLAN-MANUAL-BRANCH-PROTECTION-SETUP.md`.

## قرار موحد بشأن مزودي الخدمات — 27 نوفمبر 2026

تُؤجل جميع اختيارات المزود/المنصة/المنطقة/التكلفة إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل (D-051)، ولا يقتصر ذلك على Push. اقرأ `DECISIONS.md` (D-050) بوصفه القرار الحاكم. ممنوع افتراض اختيار مزود أو إضافة credentials أو provider-specific resources أو deploy قبل قرار لاحق وموافقة صريحة. تستمر أعمال Laravel والـcontracts والاختبارات وCI وprovider-neutral IaC الآن.


## Phase11 — آخر تحقق شامل على main

رأس main 74f57a01c1475c66a32337dc6be549b187a23344 اجتاز بوابات Docker/Auth/Security/IaC/Pint الخمس؛ guardrails المحايدة للمزود 30/30. راجع تقرير reports/PHASE-11-CI-AND-PROVIDER-NEUTRAL-READINESS-2026-11-27.md. لا يغلق ذلك Phase11 الكلية: تبقى 11B2 و11C provider adapters و11D–11F مؤجلة حسب D-050/D-051، وPhase11 تظل IN_PROGRESS.