# المرحلة 02 — بيئة تشغيل إنتاجية مستقلة عن مزود الاستضافة

**الحالة:** COMPLETE  
**الاعتماديات:** المرحلة 01 COMPLETE

## سجل التنفيذ — 22 نوفمبر 2026

- فرع التنفيذ: `feature/phase-02-portable-runtime`
- PR: [#3 — Portable production runtime](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/3)، Draft/Open، غير مدمج.
- Phase01 dependency: `feature/phase-01-laravel-project`، Phase01 code validation SHA `519c3f1039f05818b413d466fbc31793ee6daeb6`.
- القرار الأولي: FrankenPHP + Caddy على PHP 8.4، من دون FrankenPHP worker mode إلى أن يثبت اختبار دورة حياة الذاكرة.
- أضيف `HealthController`؛ `/up` liveness مستقل عن DB، و`/ready` و`/api/v1/health` readiness لفحص PostgreSQL والمخطط.
- أزيلت ملفات startup القديمة التي كانت تفرض Neon pooled/direct URLs، وتشغل migrations تلقائيًا، وتكتب مستخدمًا ورمز Sanctum مؤقتين مع كل بدء.
- أصبح تشغيل migrations مهمة release منفصلة ومقصودة. Entrypoint الخاص بالويب لا يجري اتصالًا أو كتابة إلى قاعدة البيانات.
- صورة الإنتاج الجديدة non-root على port 10000، مع PHP production ini، Caddy JSON logs، SIGTERM، trusted proxy allowlist، وحدود request-body timeout.
- الافتراضات الدائمة: database cache/session/queue. لا يُعامل القرص المحلي كخزن دائم.
- حُولت auth HTTP smoke إلى workflow_dispatch يدوي read-only، وأزيلت Neon diagnostics workflow التي كانت تقرأ/تفحص endpoint provider محدد.
- أضيفت اختبارات Feature لـliveness/readiness وتعطل DB ومنع تسريب بيانات الاتصال.
- Docker Validation #218 كشف خطأ صياغة Dockerfile في `HEALTHCHECK` (`CMD-SHELL` غير صالح داخل Dockerfile). تم إصلاحه إلى exec-form `CMD`.
- Docker #220: Docker image/SQLite، PostgreSQL lifecycle، وexplicit migration passed؛ HTTP failed because the Caddyfile was copied to the wrong path and image default attempted a privileged port. #227 repeated the same issue in no-DB liveness.
- The wrong Caddyfile path was corrected to `/etc/frankenphp/Caddyfile`, with explicit `http://:{$PORT}` listener. Caddy PHP asset matcher was made RE2-compatible and moved to site scope before the ordered `route`; HTTP smoke now creates a real PHP file inside the disposable container to prove it is rejected without execution.
- Docker #229 and #234: image build, PHP/SQLite tests and PostgreSQL image build passed, but the no-DB readiness assertion got HTTP 500 instead of 503 after `/up` succeeded. The exact exception remained unobservable because earlier smoke discarded the body/logs.
- Latest SHA `e7db3d547c2852ddcf255bbbddce55cadff77b0d`: adds an isolated Feature test with an actual refused PostgreSQL TCP connection on port 1, and adjusts no-DB HTTP CI to point at port 1, print the response body and container logs on failure. Docker #236 is currently IN_PROGRESS; no readiness pass has been recorded.

**الحالة:** IN_PROGRESS. لا تعتبر المرحلة مكتملة حتى ينجح بناء صورة FrankenPHP واختبارات SQLite وPostgreSQL واختبار HTTP الحقيقي، بما فيه non-root readiness ورفض تنفيذ PHP assets، على SHA واحد.

## تقرير الإغلاق — 22 نوفمبر 2026

**النتيجة:** COMPLETE  
**الفرع:** `feature/phase-02-portable-runtime`  
**PR:** [#3 — Portable production runtime](https://github.com/m5ham3ds/RAVEN23-BACKEND/pull/3) — Draft/Open/Unmerged  
**SHA المعتمد:** `ae311d1dced43b10f96849abfbd3c0a3460dcc3d`  
**CI:** [Docker Validation #249 — SUCCESS](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/44963610217)

### نتائج القبول على SHA واحد

| التحقق | النتيجة |
|---|---:|
| FrankenPHP/Caddy production image build | PASS |
| PHP syntax, Composer install, Artisan boot, route checks | PASS |
| SQLite migrations + Feature tests | **20 tests / 138 assertions — PASS** |
| PostgreSQL 16 migrations + auth lifecycle | **3 tests / 72 assertions — PASS** |
| Web startup without DB_URL | PASS |
| `/up` liveness without PostgreSQL | HTTP 200 — PASS |
| `/ready` with PostgreSQL unreachable | HTTP 503 + expected unavailable JSON — PASS |
| Separate one-off migration task on ephemeral CI database | PASS |
| Live readiness against migrated PostgreSQL 16 | PASS |
| Runtime process identity | `raven:raven` (non-root) — PASS |
| Real PHP file placed in public tree | HTTP 404; PHP payload not executed — PASS |
| Final GitHub Actions run | **SUCCESS** |

### العيوب التي عولجت

1. تصحيح صياغة Dockerfile `HEALTHCHECK` من Compose syntax إلى exec-form `CMD`.
2. تصحيح مسار Caddyfile إلى المسار الذي يقرأه FrankenPHP، مع HTTP listener صريح على المنفذ غير المميز.
3. نقل Caddy named matcher إلى مستوى الموقع وترتيب رفض ملفات PHP قبل `php_server`.
4. فصل liveness عن readiness وإزالة middleware التي تفرض session DB قبل readiness: `StartSession` و`ShareErrorsFromSession` وLaravel 13 `PreventRequestForgery`. الاستثناء محصور في GET `/ready`؛ بقية المسارات تحتفظ بحماياتها.
5. استخدام PHP native `error_log` في مسار فشل readiness مع حقول آمنة فقط، وتجنب الاعتماد على Laravel logging channel لتسليم 503.
6. إضافة اختبارات اتصال PostgreSQL المرفوض مع `SESSION_DRIVER=database`، واختبار JSON contract، واختبار HTTP فعلي لحظر تنفيذ PHP assets.

### حماية البيانات وحدود التشغيل

- جميع عمليات PostgreSQL في #249 استخدمت خدمة GitHub Actions المؤقتة فقط.
- لم يتم الاتصال بقاعدة الإنتاج أو Neon أو Render.
- لم تُقرأ الأسرار ولم تُغير إعدادات مزود الاستضافة.
- لم يتم تشغيل migrations على الإنتاج أو نشر التطبيق أو إنشاء موارد AWS.
- لم يتم إنشاء/حذف مستخدمين أو tokens إنتاجية.
- PR #3 مفتوح وغير مدمج. نجاح CI لا يعني أن التطبيق نُشر أو أن إعدادات أي مزود إنتاجي قد تغيرت.

**القرار:** Phase 02 COMPLETE. يمكن بدء Phase 03 على فرع وPR مستقلين، على أساس SHA هذا، مع إبقاء PR #3 منفصلًا وغير مدمج.

## الهدف
بناء صورة تشغيل موحدة تعمل على Render وAWS وأي Linux/Docker host متوافق، دون افتراضات خاصة بمزود بعينه.

## الأعمال
1. اختيار production HTTP runtime بعد مقارنة موثقة: Nginx + PHP-FPM أو FrankenPHP.
2. تشغيل PHP workers بشكل سليم مع health/readiness probes وإغلاق صحيح للإشارات.
3. بناء multi-stage Docker image ثابتة وصغيرة قدر الإمكان، تعمل بصلاحيات non-root وتحتوي healthcheck.
4. فصل build-time عن runtime وعدم وضع أسرار داخل الصورة أو build args.
5. إزالة اعتماد startup على `DB_MIGRATION_URL` بنمط Neon؛ تصميم إعداد PostgreSQL قياسي يدعم URI أو host/port/user/password، مع فصل اتصال migrations عند الحاجة.
6. إزالة فرض اسم Neon أو Render من السكربتات العامة، ونقل الفحوص الخاصة إلى deployment profiles أو adapters.
7. إعداد parity بين التطوير وCI باستخدام Docker، وقاعدة PostgreSQL مؤقتة للاختبارات.
8. ضبط cache/queue/session؛ لا تعتمد بيانات دائمة على ملفات خادم يمكن استبداله.
9. إضافة graceful shutdown وtimeouts وrequest limits وtrusted proxy/TLS settings.
10. توثيق متغيرات البيئة والقيم المطلوبة والاختيارية.

## معايير القبول
- صورة واحدة تُبنى من المستودع الحالي وتعمل في CI.
- تشغيل container مع PostgreSQL مؤقتة عبر HTTP ينجح.
- لا يلزم Render أو Neon لتشغيل اختبارات أو بناء الصورة.
- بدء الخادم لا يشغل migrations غير آمنة على قاعدة مجهولة.
- readiness يميز بين سلامة التطبيق وسلامة اتصاله بقاعدة البيانات.
- لا تعتمد جلسات أو cache دائمة على القرص المحلي في الإنتاج.

## القرار المطلوب
اختيار Nginx/PHP-FPM أو FrankenPHP بناء على الاختبارات والتوافق والقياس، لا التخمين.
