# مساحة مراحل تطوير RAVEN23 Backend

> المستودع: m5ham3ds/RAVEN23-BACKEND  
> الفرع الأساسي: main  
> latest fully CI-verified implementation SHA: 93f626cff3a5b3c472a283285ac64b49060d0f85 — 24 نوفمبر 2026  
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

## الحالة الحالية

راجع `STATUS.md` بوصفه snapshot موحدًا. Phase00–03 COMPLETE، Phase04 PARTIAL للتكاملات الخارجية، Phase05–07 COMPLETE، Phase08 IN_PROGRESS مع اختيار FCM/OneSignal مؤجل بقرار المستخدم، وPhase09 IN_PROGRESS؛ 09A–09D اجتازت بوابات CI الأربع و09E (public disabled plan-catalog contract) قيد CI. Phase09 أضاف entitlement read-only server-authoritative مع default-deny؛ لا plan pricing أو payment provider معتمد حتى الآن. Phase08 تحتفظ بعقد Push المحايد والإرسال معطلاً.

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
