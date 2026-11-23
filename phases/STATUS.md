# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 23 نوفمبر 2026
**الفرع الدائم الوحيد:** `main`
**آخر SHA تم التحقق منه:** `608c94fe057816ee582af7d3e578e14a2bfb3a37`
**نطاق CI:** `main` فقط؛ refs TEST/USERS متقاعدة ولم تُحذف بعد.

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
| 07 — المزامنة بين الأجهزة والعمل دون اتصال | NOT_STARTED | تبدأ بعد إغلاق Phase06. |
| 08 — التخزين والإشعارات والمهام | NOT_STARTED | تعتمد على عقود storage/mail/push/queue وموافقة المزود. |
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
- `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS` متقاعدان؛ لا تدفع إليهما commits جديدة.
- refs القديمة ما زالت موجودة في GitHub وقت آخر تحقق؛ نقل الملفات وتعديل triggers لا يحذف refs.
- آخر فحص سابق لم يظهر PRs مفتوحة.

## 4. ما يلزم بعد ذلك

1. بدء Phase07: cross-device sync/offline behavior بعد Phase06 COMPLETE.
2. Phase08: storage/mail/push/queue adapters.
3. Phase09: entitlement/subscription model بعد قرارات المنتج ومزود الدفع.
4. Phase10: release engineering/contract/load/rollback gates بعد إكمال نطاقات 01–09.
5. Phase11–13: AWS, cutover, production operations بعد الموافقات والاعتماديات.

## 5. حماية البيانات وحدود التنفيذ

- لم يتم الاتصال بقاعدة Neon/Render الإنتاجية أو قراءة بيانات المستخدمين.
- لم تُقرأ قيم GitHub Secrets أو `.env` أو مفاتيح التطبيق.
- لم تُنفذ migrations إنتاجية أو deploy أو DNS cutover.
- لم ينشأ حساب أو مورد AWS من خلال هذه الأعمال، ولا يوجد في المستودع دليل على AWS deployment/OIDC.
- لم تُحذف refs TEST/USERS بعد؛ يجب حذفها يدويًا من GitHub والتحقق من وجود main فقط.