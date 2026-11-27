# Phase 04 — Authentication, account lifecycle and devices

**Current status:** PARTIAL — backend implementation and CI regression are present; real Android Google OAuth and actual mail-provider delivery are not verified. Historical PR/branch details below describe the original Nov 22 phase review only; current work is on `main` and global provider selections are deferred by D-050.
**Verified head:** `459fee8019228828013600fd7a6b13a4a3aa4f4f`
**Verified:** 22 November 2026
**Historical parent:** Phase 03 baseline `6b14d55fb87bdd6948b0f896db9a4c99c43f7748` (historical branch-chain record; not an active branch)

## Scope delivered

- Email registration and email-change verification flow with short-lived, one-time tokens stored as SHA-256 hashes.
- Password reset via Laravel PasswordBroker, expiry/throttling, generic non-enumerating responses, reset notification, and revocation of existing sessions after successful reset.
- Account-scoped device/session listing and exact-session or all-session revocation.
- Per-IP and per-account throttles for verification and device management.
- Mail delivery remains opt-in and disabled until provider and action URL are explicitly configured.
- Google ID-token verification remains dependent on correct production Android OAuth client configuration.

## Verified CI gates on the exact same head

| Gate | Workflow run | Result |
|---|---:|---|
| Docker Validation | #320 | SUCCESS |
| Pint Format | #52 | SUCCESS |
| Security Scans | #54 | SUCCESS |

### Docker Validation #320 detail

- Production FrankenPHP image build: passed.
- PHP syntax and Composer validation/audit: passed.
- SQLite Feature suite: **40 passed, 269 assertions**.
- PostgreSQL 16 integration suite: **4 passed, 99 assertions**.
- Restricted PostgreSQL application/migration role checks: passed.
- Application liveness/readiness, non-root runtime, and PHP asset blocking checks: passed.
- Docker job: all steps completed successfully.

### Security and formatting

- Pint #52: job and all steps successful.
- Security #54: Gitleaks, filesystem scan, production-image scan, vulnerability gate, and report publication successful.

## Failure history and resolution

Earlier failures referenced in progress notes (#308 Docker and #42 Pint, and #44 Security) are not the current result set. The latest completed runs are #320, #52, and #54, all attached to the exact verified head above. The Docker log confirms the successful SQLite and PostgreSQL test totals.

The earlier test corrections include PasswordBroker validation payload, refreshing Laravel auth guards before token-revocation assertions, PHP syntax corrections, and Pint formatting. Do not replay these fixes unless a later regression reproduces them.

## External checks not claimed as complete

CI does not prove production provider configuration. The following remain pre-production tasks:
- Configure and test real mail delivery and the externally hosted verification/reset action URL.
- Verify real Google sign-in with the production Android OAuth client on a real device.
- Verify provider-side database roles, TLS and trusted proxy settings.
- Approve and test centralized log collection/retention and backup/restore policy.
- Conduct production/staging deployment checks only after explicit configuration and authorization.

No production database, secrets, provider settings, or deployments were accessed or changed during this verification.

## Phase 04 exit assessment

**Code and CI exit criteria: PASS.** All three mandatory CI workflows passed on one SHA, with SQLite Feature and PostgreSQL integration suites green.

**Repository governance / phase-chain exit: NOT YET CLOSED.** PR #5 remains open and draft. Its parent PR #4 (Phase 03) also remains open and draft; Phase 03 production-operational items are still explicitly outstanding in PR #4. Do not merge Phase 04 into a base that has not passed the project's phase governance.

## Continuity checkpoint

- Current Phase 04 branch: `feature/phase-04-authentication-completion`
- Current verified head: `459fee8019228828013600fd7a6b13a4a3aa4f4f`
- PR: #5
- Latest CI: Docker #320, Pint #52, Security #54 — all SUCCESS.
- No Phase 04 CI rerun is needed unless the branch head changes.

## Next phase handoff

Phase 05 must start from the agreed phase-chain base after Phase 03/04 governance is resolved. This repository snapshot does not contain an approved Phase 05 specification. Before implementation, add a reviewed Phase 05 scope document describing requirements, exclusions, acceptance criteria, dependencies, tests, migrations, security implications, and CI gates. Do not infer a production feature scope from the Phase 04 implementation.

## تحديث إثبات CI وتسليم النطاق — 22 نوفمبر 2026

**النتيجة الصحيحة لبوابات Phase 04:** SHA 459fee8019228828013600fd7a6b13a4a3aa4f4f.
- Docker Validation #320 — SUCCESS: SQLite 40/269، PostgreSQL 16 4/99، image build، lint/Composer، restricted DB roles وHTTP runtime smoke.
- Pint Format #52 — SUCCESS.
- Security Scans #54 — SUCCESS؛ Gitleaks وTrivy filesystem/image وfixable HIGH/CRITICAL gate.
كل الوظائف وخطواتها انتهت completed/success. التشغيلات #308/#42/#44 كانت نتائج وسيطة قديمة وليست النتائج النهائية.

**تقييم الخروج:** code/CI = PASS؛ مرحلة المنتج والحوكمة = PARTIAL. PR #4 وPR #5 Draft/Open وغير مدمجين. Google OAuth الحقيقي من Android والبريد الفعلي في staging لم يختبرا. كما تبقى متطلبات Phase 03 لدى مزود الإنتاج (TLS/proxy/log retention/backup/restore/RPO/RTO) قبل rollout.

يجوز استمرار Phase 05 schema/API على قواعد اختبار معزولة وفق الاستثناء غير المانع في MASTER_PLAN وDECISIONS. لا يعني ذلك دمج PRs أو إجراء migration إنتاجية أو نشرًا.

## تحديث قرار الاعتماديات الخارجية — 27 نوفمبر 2026

**الحالة المعتمدة الآن:** PARTIAL — التنفيذ واختبارات CI موجودة، لكن Google Android OAuth الحقيقي والبريد الفعلي لم يختبرا على staging.

بموجب القرار D-050، يؤجل اختيار مزود البريد وأي إعداد OAuth/موفر خارجي أو credentials أو تجربة staging تحتاج حسابًا حتى ما بعد الانتهاء النهائي من خطة تطوير Backend بالكامل (D-051). يستمر الآن فقط العمل الذي يمكن بناؤه واختباره محليًا أو داخل CI دون حسابات خارجية أو أسرار.

- لا تختَر الآن SMTP/Postmark/Resend/Mailgun/SES أو أي mail provider.
- يبقى `RAVEN23_AUTH_MAIL_ENABLED=false`، ولا تُرسل رسائل تحقق أو استعادة حقيقية.
- لا تغيّر Google client IDs أو OAuth provider settings بالافتراض؛ اختبار claims المزيفة لا يثبت تسجيل دخول Android حقيقي.
- لا تُغلق Phase04 بسبب نجاح CI أو mocks؛ ستعاد مراجعة staging بعد قرار المزود الشامل.

هذا التحديث يعلو على handoff التاريخي السابق في هذه الوثيقة، ولا يغيّر أدلة CI التاريخية أو معايير قبول التكامل الحقيقي.
