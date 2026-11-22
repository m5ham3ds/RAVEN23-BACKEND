# المرحلة 05 — أساس بيانات المستخدم وعقود الحساب

**الحالة:** IN_PROGRESS — التنفيذ محفوظ على feature/phase-05-user-data-foundation؛ لا توجد نتيجة CI لهذه المرحلة بعد.  
**الاعتماديات:** Phase 00–03 code/CI COMPLETE؛ Phase 04 code/CI PASS على SHA 459fee8019228828013600fd7a6b13a4a3aa4f4f، مع بقاء Google/mail وPR governance متطلبات خارجية غير مغلقة. يسمح باستكمال schema/API work المعزول دون وصف Phase 04 بأنها COMPLETE للإنتاج.

## الهدف
تصميم مخطط بيانات مستقر يدعم ميزات RAVEN23 الشخصية وقابل للنقل بين PostgreSQL providers دون الاعتماد على خصائص Neon الخاصة.

## الأعمال
1. إقرار نموذج user/profile/preferences/device مع فصل هوية الحساب عن إعداداته.
2. تحديد المفاتيح الأساسية والأجنبية وunique constraints والفهارس وسياسة soft-delete/retention عند الحاجة.
3. تحديد ownership: كل سجل مستخدم يجب أن يرتبط بصاحبه ويُحمى server-side.
4. تعريف هوية المحتوى باستخدام IDs مستقرة من مصادر metadata المعتمدة، وعدم نسخ بيانات محتوى عامة إلى سجلات المستخدم بلا ضرورة.
5. توحيد API versioning وpagination/filter/sorting/error envelope.
6. تعريف timestamps بتوقيت UTC ومعنى timezone الخاص بالجهاز.
7. تعريف سياسة privacy/export/delete وحق المستخدم في حذف الحساب والبيانات التابعة له.
8. إعداد migrations forward-only قدر الإمكان واختبارات upgrade من schema سابقة.
9. تحديد idempotency وunique constraints للطلبات التي قد تتكرر بسبب انقطاع الاتصال.
10. توثيق ERD وقاموس البيانات وعقود JSON.

## معايير القبول
- اعتماد ERD وقاموس بيانات.
- كل جدول شخصي لديه owner constraint أو تحقق authorization واضح.
- migrations تعمل على قاعدة فارغة وعلى نسخة schema سابقة اصطناعية.
- اختبارات تمنع وصول مستخدم إلى بيانات مستخدم آخر.
- لا توجد ميزات DB خاصة بمزود غير موثقة.

## 11. حدود النطاق وعقود البيانات

### نطاق Phase 05
- user_profiles: فصل حقول الصورة/ملف المستخدم مع إبقاء users.profile_id وusers.photo_url للتوافق مع تطبيق Android؛ تتم مزامنة الحقول القديمة عند updateProfile.
- user_preferences: locale وIANA timezone فقط، مع افتراضي timezone=UTC؛ لا يغيّر هذا timezone قاعدة البيانات أو Laravel session.
- user_devices: installations مملوكة للحساب، مبنية على stable device_id الذي يرسله Flutter، مع device_name/platform/first_seen_at/last_seen_at. جلسات Sanctum تبقى في personal_access_tokens.
- GET/PUT /api/v1/user/preferences وGET /api/v1/user/data-export.
- كل البيانات الشخصية لها owner FK وunique constraints. migration additive وforward-only ولا تحذف جداول أو صفوف في down().
- timestamps تخزن UTC. معرف المحتوى المستقبلي يعرّف في العقد على هيئة (metadata_provider, media_type, external_id) مع external_id كسلسلة؛ لا تنشئ Phase 05 جداول محتوى.

### خارج نطاق Phase 05
- bookmarks، recently watched، playback progress، playlists، wellness، sync revisions/cursors — Phase 06/07.
- FCM/OneSignal subscriptions، uploads، object storage، background jobs — Phase 08.
- billing/wallet/entitlements/pricing/payment rules — Phase 09 وقرارات المنتج.
- theme/player/audio/subtitle/source-order preferences؛ تظل محلية على الجهاز حتى اعتماد عقد حسابي صريح.
- mail provider، Google OAuth production، production database changes أو deployment.

### الخصوصية والحذف
- جميع تفضيلات/export تستعلم عبر المستخدم المصادق الحالي فقط.
- export format_version=1 يشمل بيانات Backend المتوفرة (account/profile/preferences/devices) ولا يتضمن كلمة المرور أو Google identity أو tokens/hashes أو رموز الاستعادة والتحقق.
- حذف الحساب الحالي ينظف رموز المصادقة المؤقتة، وتزيل FK cascade بيانات profile/preferences/device التابعة.
- migration ترفض الجداول الموجودة التي تفتقد owner FK cascade أو unique owner key؛ لا تكتفي بفحص أسماء الأعمدة.

## 12. حالة التنفيذ

- أضيفت migration 2026_11_22_000007_create_user_data_foundation_tables.php.
- أضيفت Eloquent models UserProfile/UserPreference/UserDevice وعلاقاتها.
- أضيفت endpoints preferences/export مع auth:sanctum.
- تم إصلاح حفظ photo_url أثناء التسجيل للحفاظ على Flutter contract.
- تم توحيد empty preferences payload مع Laravel validation response envelope.
- تم إضافة backfill من users وpersonal_access_tokens، واختبارات رفض المخطط السابق غير الآمن.
- أضيفت اختبارات SQLite Feature وsynthetic schema upgrade وPostgreSQL owner isolation/export.
- CI Phase 05 لم يبدأ بعد؛ يجب فتح PR #6 إلى feature/phase-04-authentication-completion ثم التحقق من Docker/Pint/Security على SHA واحد.

## 13. معايير القبول المضافة

- ERD وقاموس البيانات وعقود JSON موثقة في PHASE-05-ERD-AND-DATA-DICTIONARY.md وPHASE-05-API-CONTRACTS.md.
- SQLite tests تغطي التفضيلات/الملكية/validation/export/photo_url/delete cascade/migration idempotency/backfill/rejection of missing owner constraints.
- PostgreSQL 16 integration tests تغطي owner-scoped preferences/export بدور التطبيق المقيد.
- Docker/Pint/Security جميعها SUCCESS على SHA واحد.
- لا production DB changes أو secrets أو provider edits أو deployment.
- **الحالة الحالية:** implementation PARTIAL؛ الوثائق/ERD/contract/CI لم تُثبت بعد، لذا لا تسجل COMPLETE.