# تقرير تقدم المرحلة 02 — 22 نوفمبر 2026

**الحالة:** IN_PROGRESS  
**الفرع:** `feature/phase-02-portable-runtime`  
**PR:** [#3 — Portable production runtime](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/3)  
**اعتماد Phase01:** code validation SHA `519c3f1039f05818b413d466fbc31793ee6daeb6`  
**أحدث SHA أثناء هذا التحديث:** `585dfa699828932e254139fbfddf25cebe4933bb` (merge commit يضم تحديث trigger الخاص بفرع Phase01؛ اختبار #220 بُدئ على commit الأب `017a4c13cdd117e8db03e8f393c40765f881e6e6`).

## 1. ما نُفّذ

1. استبدال PHP built-in HTTP server بصورة FrankenPHP/Caddy على PHP 8.4، مع pin لصورة رسمية عبر digest.
2. إعداد Caddy على port داخلي 10000، JSON logs إلى stdout، headers أمنية، request-body timeout.
3. تشغيل الحاوية بمستخدم `raven:raven`، UID/GID 10001، وإزالة capability التي تسمح بالاستماع على المنافذ المنخفضة.
4. إعداد PHP production ini، Docker healthcheck، SIGTERM، وentrypoint يستخدم exec لبدء FrankenPHP.
5. إضافة readiness endpoint مستقل عن Laravel /up liveness، مع بقاء /api/v1/health بصيغة عامة متوافقة.
6. جعل health responses لا تكشف DB URL أو host أو database name أو أسرار الاتصال.
7. إزالة startup scripts القديمة التي كانت تعتمد على Neon pooled/direct database URLs، وتنفذ migrations تلقائيًا، وتنشئ/تحذف probe account وSanctum token.
8. فصل migrations إلى أمر release يدوي منفصل؛ بدء web container لا يقرأ ولا يكتب قاعدة البيانات.
9. جعل defaults الدائمة للـcache/session/queue تعتمد على PostgreSQL بدل filesystem المحلي.
10. إضافة TRUSTED_PROXIES كقائمة محددة صراحة، وليس wildcard افتراضيًا.
11. إضافة Caddy matcher يمنع تشغيل PHP scripts المرفوعة في public tree، مع إبقاء index.php هو front controller.
12. تحويل auth smoke إلى workflow_dispatch عام وread-only، وإزالة Neon runtime diagnostics workflow.
13. إضافة Feature tests لـliveness المستقل، readiness المتصل، 503 عند تعطل DB، وعدم كشف connection details.

## 2. CI والعيوب

| التشغيل | النتيجة | الملاحظة |
|---|---|---|
| Docker Validation #218 | FAIL | Dockerfile parser رفض `HEALTHCHECK CMD-SHELL`؛ هذه صيغة Compose وليست Dockerfile. |
| الإصلاح | PUSHED | استبدالها بصيغة exec-form `HEALTHCHECK ... CMD ["php","-r", ...]` في Dockerfile. |
| Docker Validation #220 | IN_PROGRESS عند آخر فحص | على commit `017a4c13cdd117e8db03e8f393c40765f881e6e6`؛ خطوة Docker build/SQLite كانت قيد التنفيذ. |
| PostgreSQL 16 | PENDING | لم تبدأ بعد في #220 عند آخر فحص. |
| FrankenPHP HTTP smoke | PENDING | لم تبدأ بعد في #220 عند آخر فحص. |

## 3. معايير القبول

| المعيار | الحالة |
|---|---|
| FrankenPHP production image build | PENDING CI |
| PHP syntax / Composer validation | PENDING latest CI |
| SQLite feature tests including RuntimeHealthTest | PENDING latest CI |
| PostgreSQL 16 migrations + auth lifecycle | PENDING latest CI |
| Actual HTTP liveness/readiness | PENDING latest CI |
| Non-root runtime user verification | PENDING latest CI |
| Direct uploaded PHP execution blocked | PENDING latest CI |
| SIGTERM graceful process exit | Implementation added; dedicated runtime assertion pending |
| Runtime startup does not connect/write/migrate DB | Code path removed; test/CI confirmation pending |
| No Render/Neon required for build/test | Portable CI workflow added; confirm after full workflow |

## 4. السلامة

- لم يتم الاتصال بقاعدة الإنتاج ولا تنفيذ migrations عليها.
- لم تُقرأ قيم أسرار أو إعدادات Render/Neon.
- لا توجد موارد AWS أو عملية نشر.
- PostgreSQL tests تستخدم خدمة مؤقتة في GitHub Actions فقط.
- لا توجد عمليات إنشاء/حذف حسابات إنتاجية.
- PR #2 وPR #3 مفتوحان وغير مدمجين.

## 5. الخطوات التالية

1. فحص نتيجة Docker Validation #220.
2. عند أي فشل، استخراج السبب من logs وإصلاحه على نفس الفرع.
3. اجتياز SQLite feature suite، ثم PostgreSQL 16 lifecycle.
4. اجتياز HTTP smoke على صورة FrankenPHP الحقيقية، مع liveness/readiness وnon-root وPHP asset blocking.
5. إضافة اختبار صريح لعدم إجراء أي DB query عند startup واختبار إيقاف SIGTERM إن تطلبت نتائج المراجعة ذلك.
6. إكمال مراجعة إعدادات Caddy/PHP والـtrusted proxies، ثم تحديث تقرير الإغلاق فقط بعد مرور جميع البوابات على SHA موحد.

**لم تُغلق Phase 02 ولم يبدأ النشر.**
