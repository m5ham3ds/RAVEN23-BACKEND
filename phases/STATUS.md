# الحالة التنفيذية الموحدة — RAVEN23 Backend

**آخر تحديث:** 23 نوفمبر 2026
**الفرع الدائم الوحيد:** `main`
**SHA نقل الاختبارات:** `800cff70b9663815e57b47db2be8340e8c19215e`
**SHA إعداد CI main-only:** `5a1b0d5effe79dec8da15c5d3db435222fcfa1c1`

## 1. ملخص المراحل

| المرحلة | الحالة | ما يثبتها / ما بقي |
|---|---|---|
| 00 — جرد خط الأساس | COMPLETE | جرد المصدر والعقود والتقارير محفوظ. |
| 01 — مشروع Laravel قابل لإعادة البناء | COMPLETE | Laravel 13 وComposer lock وبناء Docker قابل للتكرار. |
| 02 — runtime محمول | COMPLETE | FrankenPHP/Caddy وnon-root وliveness/readiness وPHP asset blocking. |
| 03 — الأمن والرصد | COMPLETE على مستوى الكود وCI | Gitleaks/Trivy وlog redaction وTLS guard وleast-privilege؛ إعدادات المزود والنسخ الاحتياطي قبل الإنتاج. |
| 04 — المصادقة والحسابات والأجهزة | PARTIAL | Backend code/tests موجودة؛ Google Android الحقيقي والبريد الحقيقي في staging غير مثبتين. |
| 05 — أساس بيانات المستخدم | COMPLETE | profile/preferences/devices وAPI/export واختبارات SQLite/PostgreSQL. |
| 06 — المكتبة وسجل المشاهدة والقوائم | IN_PROGRESS — اختبارات الدمج نجحت | 81 Feature و9 PostgreSQL tests اجتازت على SHA الدمج؛ Docker/Security على إعداد main-only النهائي ما زالا قيد التنفيذ. |
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

### أدلة CI على SHA نقل الاختبارات

- Docker [45109747066](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45109747066): SUCCESS — 81 Feature tests / 824 assertions، و9 PostgreSQL Integration tests / 219 assertions.
- Security [45109747037](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45109747037): SUCCESS.
- Pint [45109747060](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45109747060): SUCCESS.

### إعداد CI main-only

- Pint [45110903710](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45110903710): SUCCESS على SHA `5a1b0d5`.
- Auth regression [45110903688](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45110903688): SUCCESS على SHA `5a1b0d5`.
- Docker run 45110903634 وSecurity run 45110903620 كانا قيد التنفيذ عند آخر تحديث.

## 3. سياسة الفروع

- `main` هو الفرع الدائم الوحيد المعتمد، ويحمل التطبيق والمigrations والاختبارات والوثائق وCI.
- `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS` متقاعدان؛ لا تدفع إليهما commits جديدة.
- refs القديمة ما زالت موجودة في GitHub وقت آخر تحقق؛ نقل الملفات وتعديل triggers لا يحذف refs.
- آخر فحص سابق لم يظهر PRs مفتوحة.

## 4. ما يلزم بعد ذلك

1. إكمال Docker وSecurity على SHA main-only، ثم إغلاق Phase06 بتقرير نهائي.
2. بدء Phase07 بعد إغلاق Phase06 فقط.
3. Phase08 و09 ثم Phase10 وفق قرارات المنتج والاعتماديات.
4. Phase11 AWS تتطلب تحديد region/budget/cost alerts وموافقة صريحة وإكمال الاعتماديات.
5. Phase12 تتطلب backup/restore rehearsal وموافقة cutover.

## 5. حماية البيانات وحدود التنفيذ

- لم يتم الاتصال بقاعدة Neon/Render الإنتاجية أو قراءة بيانات المستخدمين.
- لم تُقرأ قيم GitHub Secrets أو `.env` أو مفاتيح التطبيق.
- لم تُنفذ migrations إنتاجية أو deploy أو DNS cutover.
- لم ينشأ حساب أو مورد AWS من خلال هذه الأعمال، ولا يوجد في المستودع دليل على AWS deployment/OIDC.
- لم تُحذف refs TEST/USERS بعد؛ يجب حذفها يدويًا من GitHub والتحقق من وجود main فقط.