# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 23 نوفمبر 2026
**الفرع الدائم الوحيد:** `main`
**Latest fully CI-verified implementation SHA:** `dd861fa9afd6f876ff300f65aae58a35b16f3112`
**نطاق CI:** `main` فقط؛ تم حذف TEST وUSERS والتحقق من أن branch listing يعيد `main` وحده.

## 1. ملخص المراحل

| المرحلة | الحالة | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | جرد المصدر والعقود والتقارير محفوظ. |
| 01 — مشروع Laravel قابل لإعادة البناء | COMPLETE | Laravel 13 وComposer lock وبناء Docker قابل للتكرار. |
| 02 — runtime محمول | COMPLETE | FrankenPHP/Caddy وnon-root وliveness/readiness وPHP asset blocking. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | Gitleaks/Trivy وlog redaction وTLS guard وleast-privilege؛ إعدادات المزود والنسخ الاحتياطي قبل الإنتاج. |
| 04 — المصادقة والحسابات والأجهزة | PARTIAL | Backend code/tests موجودة؛ Google Android الحقيقي والبريد الحقيقي في staging غير مثبتين. |
| 05 — أساس بيانات المستخدم | COMPLETE | profile/preferences/devices وAPI/export واختبارات SQLite/PostgreSQL. |
| 06 — المكتبة وسجل المشاهدة والقوائم | COMPLETE | Docker #397: 81 Feature/824 assertions و9 PostgreSQL/219 assertions؛ Auth #19: 20 SQLite/151 assertions و4 PostgreSQL/99 assertions؛ Security #143 ناجح. |
| 07 — المزامنة بين الأجهزة والعمل دون اتصال | IN_PROGRESS | أضيفت اختبارات جهازين، جلسات out-of-order، tombstones، lost-response retry على SQLite/PostgreSQL، full resync، token revocation per-device وعبور منتصف الليل؛ بقيت three-device/concurrent cursor وdevice-ID change وقياس retry/latency. |
| 08 — التخزين والإشعارات والمهام | IN_PROGRESS | شريحة queue/mail الأولى منفذة: إشعارات verification/reset مشفرة وبعد commit، retry/backoff ثابت، worker منفصل وفحوص CI؛ storage/S3 وpush وmail sink/provider ما زالت مفتوحة. |
| 09 — الاشتراكات والصلاحيات | WAITING_EXTERNAL | تتطلب قرارات الخطط والأسعار والاستحقاقات ومزود الدفع. |
| 10 — الجودة والإصدارات | NOT_STARTED | release engineering وcontract/load/rollback gates. |
| 11 — AWS والنشر | WAITING_EXTERNAL / DEPENDENCY | لا توجد بنية AWS أو OIDC deployment؛ تعتمد على مراحل سابقة وقرار التكلفة والمنطقة. |
| 12 — نقل البيانات والتحويل الإنتاجي | WAITING_DEPENDENCY | يتطلب backup/restore rehearsal وموافقة cutover مباشرة. |
| 13 — تشغيل الإنتاج والتعافي | WAITING_DEPENDENCY | يعتمد على Phase12 وقياس RPO/RTO. |

## 2. Phase06 — التنفيذ والاختبارات

- Bookmarks: جدول `user_bookmarks`، owner-scoped، unique per user/media، وإضافة/حذف idempotent.
- Recently watched: `user_recent_media` و`user_sync_revisions`؛ revisions، LWW، tombstones، UTC milliseconds، clock-skew، upload acknowledgements.
- Playlists: `user_playlists`, `user_playlist_items`, `user_playlist_operations`, `user_playlist_changes`؛ ست عمليات، idempotency، base-version conflicts، pagination ثابتة، tombstones.
- Export: `format_version=3` ويضم account/profile/preferences/devices/bookmarks/recently_watched/playlists ويستبعد credentials/tokens/secrets.
- اختبارات Feature وIntegration كلها الآن داخل `tests/` على main؛ أضيف `tests/README.md` لتوضيح SQLite/PostgreSQL المؤقتة.

### أدلة CI النهائية على main SHA `608c94fe057816ee582af7d3e578e14a2bfb3a37`

- Docker [45111592814](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592814): SUCCESS — 81 Feature/824 assertions، 9 PostgreSQL/219 assertions، runtime/readiness/non-root/least-privilege PASS.
- Auth [45111592724](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592724): SUCCESS — 20 SQLite/151 assertions، 4 PostgreSQL/99 assertions.
- Security [45111592767](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592767): SUCCESS — Gitleaks/Trivy/image scan.
- Pint [45111592764](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45111592764): SUCCESS؛ changed-PHP Pint steps skipped لعدم وجود PHP files متغيرة، بينما Docker strict Pint check نجح.

### قرار Phase06
جميع بوابات الاختبار المطلوبة على main نجحت، لذا Phase06 COMPLETE. Phase07 هي المرحلة التالية المسموح ببدئها.

## 3. سياسة الفروع

- `main` هو الفرع الدائم الوحيد المعتمد، ويحمل التطبيق والمigrations والاختبارات والوثائق وCI.
- `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS` حُذفا بواسطة المستخدم؛ branch listing بعد الحذف يعيد `main` فقط.
- جميع الاختبارات الوظيفية تبقى في `main`؛ حذف refs لم يحذف ملفات الاختبار أو يعيد كتابة التاريخ.
- آخر فحص سابق لم يظهر PRs مفتوحة.

## 4. ما يلزم بعد ذلك

1. متابعة Phase07: إثبات تقارب جهازين/ثلاثة أجهزة، offline retries، account switching/token revocation، cursor loss/full resync، وإصلاح daily-summary conflict.
2. Phase08: storage/mail/push/queue adapters.
3. Phase09: entitlement/subscription model بعد قرارات المنتج ومزود الدفع.
4. Phase10: release engineering/contract/load/rollback gates بعد إكمال نطاقات 01–09.
5. Phase11–13: AWS, cutover, production operations بعد الموافقات والاعتماديات.

## 5. حماية البيانات وحدود التنفيذ

- لم يتم الاتصال بقاعدة Neon/Render الإنتاجية أو قراءة بيانات المستخدمين.
- لم تُقرأ قيم GitHub Secrets أو `.env` أو مفاتيح التطبيق.
- لم تُنفذ migrations إنتاجية أو deploy أو DNS cutover.
- لم ينشأ حساب أو مورد AWS من خلال هذه الأعمال، ولا يوجد في المستودع دليل على AWS deployment/OIDC.
- تم التحقق من وجود `main` فقط؛ refs TEST/USERS غير موجودة.

## 6. تحديث Phase07 — 23 نوفمبر 2026

- أضيف `POST /api/v1/sync/wellness` مع migration والجداول واختبارات SQLite/PostgreSQL.
- جميع بوابات CI الأربع ناجحة على `ef3db49dea53c33622a716f8f46aa1dadccd5109`; التفاصيل في `phases/reports/PHASE-07-PROGRESS-REPORT-2026-11-23.md`.
- Phase07 ما زالت IN_PROGRESS: endpoint وowner isolation وrevisions وtombstones وstable pagination مختبرة، لكن multi-device convergence وoffline recovery وaccount switching وdaily-summary conflict لم تغلق بعد.
- لم يتم الاتصال بقاعدة الإنتاج أو قراءة الأسرار أو تنفيذ migration إنتاجية أو deploy.


## 7. تحديث التنفيذ — 23 نوفمبر 2026، Phase07/08

- آخر SHA اجتاز بوابات CI الأربع: `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- Docker: 92 Feature / 1,046 assertions على SQLite، و12 PostgreSQL Integration / 265 assertions؛ جميع خطوات runtime وleast-privilege وqueue-worker guards نجحت.
- Authentication Regression: 20 SQLite / 151 assertions، و4 PostgreSQL / 99 assertions.
- Pint وSecurity كلاهما SUCCESS؛ Security image scan لم يسجل findings في صورة الإنتاج ضمن تقرير Trivy.
- Phase07 ما زالت IN_PROGRESS مع تغطية إضافية للـretry/full-resync/device-revoke/daily aggregation؛ تبقى اختبارات 3 أجهزة والتزامن المتداخل وdevice-ID rotation وقياس sync latency/retry.
- Phase08 بدأت بشريحة mail queue فقط؛ لا يوجد مزود بريد فعلي ولا تخزين S3 أو push adapter مكتمل.
- البريد ما زال معطلاً في الإعدادات النموذجية: `RAVEN23_AUTH_MAIL_ENABLED=false` و`MAIL_MAILER=log`.
- لا production DB/secrets/deploy/DNS/AWS resources.


## 8. تحقق لاحق وإصلاح حوكمة CI — 23 نوفمبر 2026

- رأس main الذي خضعت عليه هذه المراجعة: `729163a909679cf50cd0cd1275dcd9ab8f7061f6`؛ آخر SHA برمجي اختُبر بالكامل قبل تعديلات حوكمة CI: `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- تم فحص تشغيلات CI الأربعة على 729163a: Docker #45205731260 SUCCESS، Security #45205731207 SUCCESS، Authentication #45205731245 SUCCESS، Pint #45205731265 SUCCESS. Pint تخطى فقط خطوات فحص/تنسيق الملفات المتغيرة لعدم وجود PHP changes؛ Docker strict Pint على Wellness controller نجح.
- تمت مراجعة مواصفات Phase07 وPhase08 وتقاريرهما؛ المرحلتان تبقيان IN_PROGRESS ولا يوجد أساس لبدء Phase09.
- حُذفت سبعة workflows تستهدف Render production مباشرة: ستة workflows لإنشاء/تسجيل دخول/حذف حسابات إنتاج اصطناعية، وworkflow route probe يرسل POST إلى production. السبب منع CI من كتابة بيانات على الإنتاج عند تعديل ملفات workflow نفسها.
- لم يتم تشغيل هذه workflows أو إرسال طلب إلى Render، ولم تُقرأ GitHub Secrets ولم يتم لمس قاعدة الإنتاج أو تنفيذ deploy.
