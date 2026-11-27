# Phase13A — دليل التشغيل والاستجابة للحوادث (محايد لمزود الخدمة)

**التاريخ:** 28 نوفمبر 2026  
**الحالة:** DESIGN BASELINE — لا توجد خدمة إنتاج أو منصة رصد معتمدة.  
**النطاق:** توثيق محايد للعمليات فقط؛ لا تنشئ حسابات أو موارد أو dashboards أو alert destinations ولا تغيّر إعدادات التشغيل.

## 1. حدود هذا الدليل

هذا الدليل يحدد كيف يقرأ المشغّل أدلة صحة التطبيق، وكيف يصنف الحوادث ويحافظ على الأدلة، وما المعلومات التي يجب جمعها قبل أي قرار إصلاح. لا يفترض hostname أو cloud provider أو database provider أو object storage provider أو region أو budget أو RPO/RTO.

**ما ثبت في المستودع:** Laravel health/readiness endpoints، request correlation IDs، redacted API error responses، database queue worker guardrails، notification outbox status command، private local filesystem default، outbox scheduler، CI backup/restore rehearsals، وcontainer rollback rehearsal معزول.

**ما لم يثبت:** production deployment أو registry push، مزود logs/metrics/alerts، SMTP أو Push delivery حقيقي، S3 bucket حقيقي، backup schedule خارجي، production restore، أو DNS/edge cutover. نجاح CI وS3Mock وrollback المحلي ليس إثبات تشغيل إنتاجي.

## 2. عقد الصحة الذي يستطيع المشغّل الاعتماد عليه

| Endpoint | الغرض | ما الذي يثبته | حدوده |
|---|---|---|---|
| GET /up | Liveness | أن عملية Laravel/FrankenPHP تستجيب | لا يتصل بقاعدة البيانات ولا يثبت readiness |
| GET /ready | Readiness | اتصال PostgreSQL عبر SELECT 1 ووجود مجموعة الأعمدة المطلوبة في المخطط | لا يختبر كل query أو التخزين الخاص أو queue أو mail/push |
| GET /api/v1/health | Health contract للتطبيق | يعرض service/version وdatabase/schema status مشتقة من readiness | لا يثبت تكامل provider أو وجود بيانات مستخدمين |

السلوك الحالي:
- نجاح readiness يعيد HTTP 200 وapplication/database/schema = ok/ready.
- فشل اتصال قاعدة البيانات أو استثناء readiness يعيد HTTP 503، ويكتب native error log محدودًا إلى event وconnection name وexception type دون SQL أو exception message أو host أو username أو password.
- نقص الأعمدة المطلوبة يعيد HTTP 503 وschema=incomplete.
- مسار /ready يستثني StartSession وShareErrorsFromSession وCSRF middleware حتى يبقى متاحًا عند تعطل مخزن الجلسات المعتمد على DB.
- API responses تحتوي X-Request-ID وCache-Control: no-store؛ request ID يولده الخادم ولا يثق بمعرف يرسله العميل.
- API exceptions تعرض رسالة عامة وrequest_id؛ لا تضع payload أو SQL bindings أو token في التذكرة أو سجل الحادث.

**فحص HTTP يدوي آمن:** لا يجرى إلا على base URL يقدمه/يعتمده المالك. يقتصر هذا smoke على GET /up وGET /ready وGET /api/v1/health، ولا يرسل credentials أو بيانات مستخدمين. ملف auth-smoke workflow يدوي ويتطلب api_base_url؛ لا عنوان staging معتمد حاليًا، لذلك لم يُشغّل.

## 3. صورة التشغيل الافتراضية الحالية

| الجزء | الافتراضي الحالي في Git | دلالة التشغيل |
|---|---|---|
| Database | PostgreSQL contract؛ connection app منفصل عن migration role | اختبارات PostgreSQL مؤقتة فقط؛ لا host إنتاج معتمد |
| Queue | database queue افتراضيًا | العامل طويل العمر يرفض sync/null؛ يجب أن يكون timeout أقل من retry_after |
| Worker queues | mail,push,default | Push يبقى معطلًا ما لم يصرح به قرار مستقل |
| Notification outbox | relay مجدول كل دقيقة مع withoutOverlapping(5) | command يعيد العدادات والعمر دون محتوى الرسائل |
| Mail | log default | لا إرسال SMTP/provider خارجي مثبت |
| Push | disabled transport؛ RAVEN23_PUSH_ENABLED=false | لا FCM/OneSignal sender نشط |
| Private files | private_media local disk؛ private visibility وserve=false | لا S3 bucket حقيقي؛ S3Mock عقد اختبار فقط |
| Orphan cleanup | معطل افتراضيًا | لا تستخدم --apply ولا تفعّل الجدولة دون إجراء معتمد |
| Purchases | purchases_enabled=false وplans=[] | لا payment provider أو webhooks |
| Release | Docker archive + SBOM + provenance/attestations في GitHub artifacts | لا OCI registry push أو cloud deployment مثبت |

الأدوات التشغيلية الحالية المقيدة:
- notifications:outbox-status --json: ملخص مجمع للـpending/processing/expired claims/failed/sent وأقدم pending due age، من دون بيانات المستخدم أو محتوى الرسائل.
- notifications:outbox-dispatch: relay مجدول؛ لا تشغله يدويًا بصورة متكررة لمجرد وجود backlog، ولا تكرر الإرسال قبل مراجعة idempotency/claim state.
- queue worker script يتحقق من connection/timeout/retry_after وقوائم queues ويمنع تشغيل worker مع sync أو null.
- media:cleanup-orphans يعمل فقط عند تفعيل flag صريح، والجدولة الافتراضية معطلة.

## 4. مبادئ التعامل مع الحوادث

1. صنّف الأثر أولًا: availability، data integrity، confidentiality، queue/delivery، storage، أو release.
2. التقط وقت UTC، endpoint، HTTP status، request ID، commit/image digest المعروف، وأثر المستخدمين بشكل إجمالي فقط.
3. لا تطلب من المستخدم إرسال token أو APP_KEY أو DB URL أو ملف .env أو row values.
4. لا تنفذ migration أو restore أو delete أو queue replay أو key rotation أو DNS change تلقائيًا.
5. احفظ source/backup/original device database حتى يثبت الاسترداد وموافقة المالك.
6. لا تستخدم test endpoint أو HTTP POST عند تشخيص liveness/readiness.
7. لا تعلن resolution قبل نجاح health checks والتحقق من الوظيفة المتأثرة وموافقة المسؤول المعين.
8. أدوار الاستجابة وقنوات التصعيد ونافذة الصيانة تبقى TBD حتى يعتمدها المالك.

## 5. Runbook — API outage أو readiness failure

**العلامات:** /up لا يستجيب، أو /ready يعيد 503، أو /api/v1/health يعيد database=unavailable/schema=incomplete.

1. سجل UTC timestamp وHTTP status ومدة الاستجابة وX-Request-ID إن وجد.
2. نفذ GET فقط على /up ثم /ready ثم /api/v1/health من موقع مراقبة معتمد؛ لا تكرر الطلبات بسرعة غير محدودة.
3. إذا /up يفشل: صنّف الحادث كعملية/runtime/ingress path؛ اجمع container/process exit reason من منصة التشغيل بعد اعتمادها.
4. إذا /up ينجح و/ready يفشل: افصل بين database unavailable وschema incomplete من JSON response؛ لا تعتبر هذا مجرد مشكلة API route.
5. إذا readiness ينجح وendpoint واحد يفشل: استخدم request_id للربط مع redacted logs وroute/application diagnostic؛ لا تستخرج request payload.
6. بعد إصلاح معتمد، أعد GET health checks ثم smoke test لوظيفة GET غير حساسة فقط. لا تستخدم تسجيل الدخول أو عمليات كتابة لاختبار التعافي.

**لا تفعل:** لا تعِد تشغيل migrations، لا تغيّر DB_URL، لا ترفع replicas أو compute، ولا تحوّل traffic/DNS دون قرار مستقل.

## 6. Runbook — PostgreSQL unavailable أو schema mismatch

**علامات:** readiness يعرض database=unavailable أو schema=incomplete.

1. احفظ response status وrequest correlation ID وexception type من log المنقح.
2. تحقق من حالة الخدمة/الشبكة/الاتصالات/حدود connections من لوحة المزود بعد اعتماده؛ لا تضع connection string في السجل.
3. فرّق بين الاتصال وschema readiness: اتصال SELECT 1 مع أعمدة ناقصة قد يعني release/migration mismatch.
4. راجع release SHA وmigration manifest ونتيجة آخر release pipeline. استخدم migration role المنفصل للمخطط، لا app role.
5. لا تشغل migrate:fresh أو destructive rollback على بيانات حقيقية. لا تشغل down migration كوسيلة إصلاح تلقائية.
6. قبل أي migration حقيقية مستقبلًا: backup متسق ومشفّر، restore معزول مجرب، خطة توقف/رجوع وموافقة محددة.
7. بعد إصلاح معتمد، تحقق من readiness ثم endpoint smoke واختبار قراءة آمن.

**Recovery gate:** إذا كان المخطط غير متوافق ولا يتوفر backup/restore معتمد، أوقف cutover/نشر الإصدار واطلب قرار مالك النظام؛ لا تخمّن migration عكسية.

## 7. Runbook — Queue backlog أو Notification Outbox

**علامات:** ارتفاع pending_due_count أو oldest_due_pending_age_seconds أو expired_claim_count أو failed_count.

1. شغّل notifications:outbox-status --json في بيئة تشغيل معتمدة؛ احتفظ بالمجاميع فقط.
2. تحقق من worker process وqueue connection وqueues وtimeout/retry_after من إعداد runtime.
3. تذكّر أن mail default هو log وPush معطل؛ backlog لا يعني أن provider خارجي متاح أو أن إرسالًا يجب تشغيله.
4. افحص oldest due age وexpired claims؛ claim lease الحالي 60 ثانية في status command. لا تعتبر كل processing record متوقفًا بمجرد عمره من دون مراجعة claim semantics.
5. احتفظ بـoperation/idempotency receipts. لا تمسح notification_outbox أو jobs/failed_jobs لتصفير الأرقام.
6. لا تنفذ queue:retry all أو dispatch متكررًا قبل مراجعة تكرار الأثر وسلامة provider idempotency.
7. بعد استقرار العامل، راقب انخفاض oldest pending age/failed count عبر أكثر من عينة تشغيلية متباعدة؛ فترة القياس TBD حسب SLO معتمد.

## 8. Runbook — Private media / object storage outage

**الوضع الحالي:** private_media على local private disk افتراضيًا. S3Mock لا يثبت bucket أو IAM أو versioning أو lifecycle أو off-provider copy.

1. تحقق من HTTP response وrequest ID ومن ownership/access path؛ لا تطبع signed URL أو token.
2. ميّز بين metadata row موجودة وblob غير موجود؛ تحقّق من object key وsize_bytes وchecksum_sha256 من سجل آمن/manifest مجمع.
3. لا تجعل disk public ولا تفعّل serve=true لمعالجة 404.
4. لا تشغل media:cleanup-orphans --apply ولا تحذف blobs أو metadata أثناء الحادث.
5. إذا كان الملف مفقودًا ولا توجد نسخة أصلية قابلة للتحقق، صنّفها حادث سلامة بيانات واحتفظ بسجل metadata لإعادة المطابقة.
6. لا تنفذ S3 endpoint/credentials/bucket/lifecycle تغييرًا إلا بعد اختيار مزود وموافقة منفصلة.

## 9. Runbook — Compromised credentials أو suspected token leak

1. حدّد نوع الاعتماد المشكوك فيه دون نسخه إلى incident ticket: user device token، database credential، application key، OAuth verifier، mail/push key، أو deployment identity.
2. إذا كان الاشتباه في حساب مستخدم، استخدم آلية revoke-all-devices أو revoke-device من مسار موثق ومصادق عليه بعد تأكيد الحساب مع المالك؛ لا تحذف سجلات الحساب عشوائيًا.
3. إذا كان DB credential، اعتمد خطة تبديل role منفصلة وتحقق من app/migrator separation؛ لا تدوّر كل الأسرار معًا.
4. APP_KEY له آثار على encrypted casts وnotification payloads وHMAC token hashes وwellness cursor signatures. لا تدوّره عشوائيًا؛ يلزم key-continuity/re-encryption/re-registration plan واختبار restore قبل التغيير.
5. أوقف التكامل الخارجي المتأثر عبر kill switch الموجود بدل إدخال credential عشوائي. Push يبقى disabled افتراضيًا.
6. سجّل زمن الاكتشاف/الإبطال والتحقق دون أي secret value. صلاحية المسؤول وقناة التنبيه تبقى TBD.

## 10. Runbook — Failed migration أو bad deployment/rollback

**Failed migration:**
1. أوقف التقدم إلى إصدار جديد، واحفظ SHA وmigration name والـredacted error/request ID.
2. لا تستخدم down migration افتراضيًا؛ بعض تغييرات البيانات لا يمكن عكسها بأمان.
3. قيّم إن كانت العملية قد غيرت schema/data أم فشلت قبل commit؛ استخدم ledger ونسخة معزولة للتحقق.
4. استعادة الإنتاج لا تبدأ إلا من backup معتمد وموافقة واضحة؛ rehearsal CI لا يكفي.
5. بعد موافقة الإصلاح، نفذ خطة roll-forward أو restore مختبرة على clone أولًا.

**Bad release:**
1. طابق running image digest/commit مع release manifest وprovenance/SBOM.
2. اختر آخر release معروف ناجحًا وموقّعًا، وتحقق من سلامة الأرشيف قبل أي خطوة.
3. لا تفترض أن GitHub Docker artifact قابل للسحب مباشرة إلى production؛ لا registry publication/deployment pipeline مثبت في هذا المستودع.
4. rollback rehearsal الحالي loopback/isolation في CI فقط؛ لا يثبت تغيير production traffic.
5. بعد نشر معتمد مستقبلًا، تحقق من /up و/ready و/api/v1/health وGET smoke، ثم راقب 5xx/latency والـqueue/storage state.

## 11. Runbook — Backup/restore/DR

1. ميّز بوضوح بين: synthetic CI rehearsal، backup فعلي من مصدر معتمد، off-provider copy، وrestore إلى وجهة معزولة.
2. لا تعتبر pg_dump الموجود في CI جدول backup تشغيليًا أو schedule إنتاجي.
3. لا توجد حاليًا سياسة معتمدة لجدولة النسخ أو retention أو encryption key escrow أو RPO/RTO.
4. قبل أي drill حقيقي: source-of-truth، destination، key custody، scope (DB + private blobs)، consistency point، owner approval، stop/go وrollback.
5. بعد restore مستقبلي، قارن schema/migrations، counts/ID set digests، relations/orphans، ledger totals، sequence high-water state، blob checksums، ثم API smoke.
6. سجّل وقت آخر نسخة قابلة للاستعادة ووقت بداية/نهاية الاستعادة وأي فجوة بيانات. لا تستنتج RPO/RTO من نجاح اختبارات CI.

## 12. SLI/SLO وعتبات التنبيه — سجل قيم TBD

| المجال | SLI المقترح | العتبة/الهدف المعتمد |
|---|---|---|
| API availability | نسبة طلبات API الناجحة حسب route class مع استثناءات موثقة للـ4xx | TBD_OWNER |
| API latency | p50/p95/p99 لكل route class | TBD_OWNER |
| Liveness/readiness | حالات GET /up و/ready ومدة الفشل المستمر | TBD_OWNER |
| PostgreSQL | connection success، query latency، pool saturation، transaction failures | TBD_PROVIDER_AND_OWNER |
| Queue | queued jobs، oldest job age، failed jobs، worker heartbeat | TBD_OWNER |
| Outbox | pending due، oldest due age، expired claims، failed count | TBD_OWNER |
| Private objects | get/put/delete errors، checksum mismatches، missing object count | TBD_PROVIDER_AND_OWNER |
| Backup/restore | backup age/size/checksum، restore duration، restore success | TBD_RPO_RTO |
| Release | build/attestation success، deploy result، rollback outcome | TBD_PROVIDER_AND_OWNER |
| Security | auth anomalies، revoked-device events، secret rotation evidence | TBD_OWNER |
| Cost/capacity | compute/DB/storage/egress/logs/backup cost and growth | TBD_PROVIDER_AND_BUDGET |

لا تضع أرقامًا في alert thresholds قبل اعتماد SLO/RPO/RTO والميزانية والمنطقة ونمط الاستخدام. لا تقم بإرسال alerts إلى قناة أو بريد أو webhook غير معتمد.

## 13. نموذج حادث موحد

- Incident ID:
- Severity: TBD_OWNER
- Start/end UTC:
- Affected service/domain:
- User impact (aggregate only):
- Detection source:
- Request IDs / release SHA / image digest:
- Symptoms and exact safe observations:
- Timeline:
- Mitigation applied and approval:
- Data integrity check:
- Recovery/rollback evidence:
- RPO measured / RTO measured: TBD until real drill
- Root cause:
- Follow-up actions / owner / due date:
- Sensitive values checked and excluded from report: yes/no

## 14. سجل دوريات التشغيل

| النشاط | cadence | owner | evidence |
|---|---|---|---|
| Liveness/readiness/API health review | TBD | TBD | timestamped safe results |
| Queue/outbox status review | TBD | TBD | aggregate JSON only |
| Backup freshness/checksum review | TBD | TBD | manifest |
| Restore drill (DB + private objects) | TBD | TBD | restore report and measured RPO/RTO |
| Security/access review | TBD | TBD | redacted access review |
| Patch/image/dependency review | TBD | TBD | locked versions and scan report |
| Cost/capacity review | TBD | TBD | actual provider invoice/usage after selection |
| Incident runbook exercise | TBD | TBD | scenario and outcome report |

## 15. Phase13A acceptance boundary

يمكن اعتبار هذا المستند baseline تصميميًا فقط بعد مراجعة مرجعية الملفات والكود وظهور كل القيم غير المعتمدة بوصفها TBD. ولا تُغلق Phase13 أو أي معيار تشغيل فعلي حتى:
- اعتماد owner/severity/escalation roles وSLO/RPO/RTO والretention والميزانية.
- وجود مزود/بيئة تشغيل معتمدة وموافقات نشر منفصلة.
- اختبار alerts الحقيقية، backups الفعلية، restore للقاعدة والملفات، وقياس RPO/RTO.
- وجود دليل نشر/rollback production واختبار server replacement.
- توثيق availability/cost/capacity/incidents من تشغيل فعلي.
