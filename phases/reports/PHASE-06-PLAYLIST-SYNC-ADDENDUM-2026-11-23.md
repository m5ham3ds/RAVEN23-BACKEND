# ملحق Phase06 — مزامنة القوائم ونتائج CI — 23 نوفمبر 2026

**الحالة:** IN_PROGRESS / CI BLOCKED. لا تمثل هذه الوثيقة تقرير إغلاق.
**main code SHA:** `fea7045178e603a7b54150e75475f5d27960d306`.
**TEST SHA:** `15369516309ddd6122a358a82063a48ca6e8b7d3`.
**USERS SHA:** `c789b333574b140d1aed23ce9982dbe6a5c945e0`.

## 1. ما أضيف

- Migration `2026_11_23_000010_create_user_playlist_sync_tables.php` للجداول user_playlists وuser_playlist_items وuser_playlist_operations وuser_playlist_changes.
- PlaylistSyncController ومسار POST /api/v1/sync/playlists خلف auth:sanctum.
- دعم create/update/delete/reorder/item.add/item.remove.
- operation log فريد لكل (user_id, operation_id)، payload hash وresult لإعادة المحاولة؛ operation id reuse مع payload مختلف مرفوض.
- base_version conflicts تعرض current_version.
- stable snapshot pagination via since_revision/until_revision/cursor؛ list snapshot يعاد في الصفحة الأولى وitem records فقط تتصفح في الصفحات اللاحقة.
- حدود 500 عملية/طلب، 200 قائمة/مستخدم، 2500 عنصر/قائمة.
- إعادة ترقيم العناصر بعد item.remove وإضافة تغييرات positions إلى نفس revision.
- Data export format_version=3 يتضمن playlists/items، إضافة إلى bookmarks وrecent history.

## 2. الاختبارات

- TEST: PlaylistSyncTest يغطي التكرار، تعارض النسخة، حذف القائمة والعناصر، إعادة الترتيب، ضغط المواضع بعد إزالة عنصر، ثبات snapshot pagination، عزل المالك وإصدار التصدير v3.
- TEST: PostgresPlaylistSyncTest يغطي create/add/update/stale reorder/operation replay/owner isolation باستخدام دور PostgreSQL المقيد.
- UserDataFoundationTest في main تم تحديثه إلى v3 لأن Dockerfile يشغّل Feature tests على main؛ TEST يأخذ النسخة نفسها.
- RecentlyWatchedSyncTest وUserDataFoundationTest على TEST محدثان أيضًا إلى توقع export v3.

## 3. إصلاحات خلال المراجعة

- Pagination query أعاد Laravel Collection؛ تم تحويل عناصر الصفحة إلى array قبل array_pop/end لحساب next_cursor.
- item.add يستخدم max(position)+1.
- item.remove يعيد ضغط مواضع العناصر الباقية، ويسجل صفوف العناصر التي تغير موضعها في revision نفسه.
- تم التأكد من أن Docker integration workflow يشغّل مجلد tests/Integration كاملاً، وبالتالي يشمل PostgreSQL playlist test على TEST.

## 4. نتائج CI

- main: Docker #360 / run 45083888323، Pint #96 / run 45083888275، Security #98 / run 45083888350.
- TEST: Docker #361 / run 45083923781، Pint #97 / run 45083923772، Security #99 / run 45083923791.
- USERS: Auth #8 / run 45083951166، Pint #98 / run 45083951162، Security #100 / run 45083951138.
- jobs المفحوصة على main/TEST/USERS أظهرت runner_id=0، runner_name=""، steps=[]؛ لم تبدأ أي خطوة تنفيذ. جلب logs يعيد BlobNotFound.
- هذا ليس دليلاً على فشل اختبار PHP؛ ولا هو PASS. السبب المحتمل quota/billing أو عدم توفر runner غير مؤكد دون صلاحية Billing الخاصة بمالك المستودع.

## 5. سلامة الفروع والبيانات

- ثلاث فروع دائمة فقط، ولا PRs مفتوحة.
- TEST يحتوي اختبارات عامة فقط، وUSERS يحتفظ بملف scope الخاص باختبارات الحسابات فقط.
- لم يتم الاتصال بقاعدة Neon/Render الإنتاجية، ولم تتم قراءة أسرار أو تنفيذ migrations إنتاجية أو نشر أو إنشاء موارد AWS.

## 6. قرار المرحلة

Phase06 لا تغلق حتى تخصص GitHub Actions runners وتنجح Docker/SQLite/PostgreSQL/Pint/Security على SHA موحد. بعد نجاح هذه البوابات، تنتقل الخطة مباشرة إلى Phase07. لا يُبدأ Phase07 قبل ذلك.