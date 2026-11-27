# مساحة مراحل تطوير RAVEN23 Backend

> المستودع: m5ham3ds/RAVEN23-BACKEND  
> الفرع الأساسي: main  
> latest fully CI-verified runtime SHA: 6750eac566c513c4fc97951f1b80cb7f54cd0c0a (Docker #675/Pint #419/Auth #297/Security #421 SUCCESS); IaC #31 SUCCESS on unchanged dbd281c. Current main 522f967 is documentation-only after the verified runtime SHA — 28 نوفمبر 2026  
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
7. PHASE-12-DATA-MAPPING-AND-INTEGRITY.md — خريطة ترحيل البيانات والعقود والقيود والتكامل.
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
19. reports/PHASE-11-CI-AND-PROVIDER-NEUTRAL-READINESS-2026-11-27.md — تحقق Phase11 السابق على SHA 74f57a0 ونتائج IaC/CI وحدود ما ينتظر قرار المزود.
20. reports/PHASE-11-DEFERRAL-AND-PHASE-12-ENTRY-2026-11-28.md — توثيق تأجيل 11 الخارجي وفتح 12A ونتائج CI المرجعية.

## الحالة الحالية

راجع `STATUS.md` بوصفه snapshot موحدًا. Phase00–03 و05–07 COMPLETE؛ Phase04 PARTIAL؛ Phase08 و09 IN_PROGRESS؛ Phase10 IN_PROGRESS مع 10B مؤجلة بقرار المالك؛ Phase11 PAUSED/DEFERRED_BY_OWNER عند حد الاعتماد الخارجي وفق D-050/D-051؛ 11A/11B1 مكتملتان تقنيًا و11C guardrails 30/30، أما adapters و11B2 و11D–11F فمؤجلة حتى بعد خطة Backend والقرار الموحد و10B. Phase12 هي مسار العمل الحالي على 12A provider-neutral preparation؛ 12B real migration و12C cutover ما زالتا تنتظران المزود/المصدر/الوجهة والموافقات. Phase13 تظل لاحقة. قرار 27 نوفمبر 2026 يؤجل جميع اختيارات المزود والمنطقة والتكلفة (hosting/database/storage/mail/push/payment/registry/secrets/monitoring/DNS) إلى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل، لا بعد نهاية مرحلة منفردة (D-051). لا يوقف ذلك أعمال الكود والاختبارات المحايدة. التفاصيل في `MASTER_PLAN.md` و`DECISIONS.md` وD-050.

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

20. infra/opentofu/contracts/POST-PLAN-11B2-11F-EXECUTION-PLAYBOOK.md — تسلسل التنفيذ ومعايير قبول 11B2–11F بعد نقطة قرار D-051.
21. PHASE-12-DATA-MAPPING-AND-INTEGRITY.md — source-level mapping وAPI export v3 والـintegrity invariants.


## انتقال 28 نوفمبر 2026

- Phase11 متوقفة عند بوابات مزود الخدمة، وليست مكتملة؛ جميع الاختيارات تبقى مؤجلة حتى نهاية خطة Backend.
- Phase12A هي المرحلة النشطة الآن للأعمال المحايدة، وقد اجتاز full-schema rehearsal على main 7273028؛ خريطة mapping والـintegrity في PHASE-12-DATA-MAPPING-AND-INTEGRITY.md.
- main هو الفرع الدائم الوحيد. لا تنشئ فروعًا إضافية.


## تحديث Phase12A — CI result and mapping — 28 نوفمبر 2026

اجتازت على current main بوابات Docker #671 وAuthentication #293 وSecurity #417 وPint #415. اختبار IaC #31 اجتاز على dbd281c وبقي صالحًا لأن ملفات IaC لم تتغير. تقرير full-schema artifact #15235408250: 23 migration files/ledger rows و34 public tables، وكل المقارنات المقررة متطابقة. لا يدل ذلك على بيانات إنتاج ولا يمنح إذنًا للترحيل الحقيقي.


## تحديث مصدر التطبيق وsequence-state — 28 نوفمبر 2026

خريطة PHASE-12-DATA-MAPPING-AND-INTEGRITY.md تتضمن الآن أدلة مستودع RAVEN23 Flutter على commit 387feafb: Firebase/Firestore legacy candidate، ملفات SQLite المحلية، الانتقال عبر LaravelSyncCoordinator، وفجوة حقول Firebase Scrypt المشروطة. لا يوجد إثبات لمصدر production حي.

full-schema CI على 6750eac تحقق من 21 Sequence بالإضافة إلى 23 migrations/34 public tables؛ Docker #675 اجتاز أيضًا rollback والتوقيع الموثوق. Docker #674 فشل مؤقتًا بسبب parameter $2 غير الممرر، وأُصلح ذلك في 6750eac وأعيد الاختبار بنجاح.
