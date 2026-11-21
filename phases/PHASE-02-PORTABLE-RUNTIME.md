# المرحلة 02 — بيئة تشغيل إنتاجية مستقلة عن مزود الاستضافة

**الحالة:** IN_PROGRESS  
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
