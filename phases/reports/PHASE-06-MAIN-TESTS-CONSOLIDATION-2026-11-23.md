# تقرير توحيد اختبارات Backend على main — 23 نوفمبر 2026

**الحالة:** ملفات الاختبار مدمجة؛ اعتماد Phase06 النهائي ينتظر اكتمال جميع بوابات CI main-only.
**SHA دمج الاختبارات:** `800cff70b9663815e57b47db2be8340e8c19215e`

## الملفات المنقولة
- Feature: BookmarkSyncTest، PlaylistSyncTest، PreferenceSyncTest، RecentlyWatchedSyncTest.
- PostgreSQL Integration: PostgresBookmarkSyncTest، PostgresPlaylistSyncTest، PostgresPreferenceSyncTest، PostgresRecentlyWatchedSyncTest.
- أضيف `tests/README.md`. لم يُنقل `tests/BRANCH_SCOPE.md` لأنه يصف السياسة القديمة.

## أدلة CI على SHA دمج الاختبارات
- Docker #45109747066: SUCCESS — 81 Feature tests /824 assertions و9 PostgreSQL Integration tests /219 assertions، مع runtime/least-privilege smoke PASS.
- Security #45109747037: SUCCESS.
- Pint #45109747060: SUCCESS.

## توحيد CI والفروع
- Docker/Pint/Security/Auth regression workflows تستهدف main فقط.
- فحص PHP changed-files يستخدم parent SHA في Push وbase branch في PR وcommit السابق في workflow_dispatch.
- فرعا TEST وUSERS متقاعدان؛ refs ما زالت ظاهرة في GitHub ولم يتم تأكيد حذفها. يجب حذفها يدويًا والتحقق من بقاء main فقط.

## حدود السلامة
- لم يتم الاتصال بقاعدة الإنتاج، ولم تقرأ secrets، ولم تشغّل migrations إنتاجية أو deploy أو تنشئ AWS resources.