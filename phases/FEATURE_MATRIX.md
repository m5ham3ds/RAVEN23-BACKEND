# مصفوفة ميزات RAVEN23 Backend — الموجود والجزئي والمفقود

**Backend application/runtime baseline:** `27cfcc9e577cca33c28e9845096a0c5564b02045`؛ **آخر SHA اجتاز CI الأربع قبل مزامنة الوثائق:** `617ce7efd669b4d3af61c42f77b4cd1f986e3fae` (التغييرات بينهما توثيقية فقط)  
**Flutter SHA:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** COMPLETE كجرد مصدر؛ آخر مزامنة 27 نوفمبر 2026. لا تدعي هذه المصفوفة تشغيل أي مزود خارجي أو staging/production.

الحالات:
- **موجود ومختبر آليًا:** تنفيذ في Backend وله اختبار يمر.
- **موجود جزئيًا:** بعض التنفيذ موجود لكن جزء من العقد أو التحقق الخارجي غير مكتمل.
- **client-only / backend missing:** Flutter يطلب route أو يملك repo لكن Backend لا يحتوي تنفيذًا.
- **غير مثبت:** توجد إشارة أو اعتماد خارجي لكن لم يثبت تشغيله الحقيقي.

## 1. الحسابات والمصادقة

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Email registration | موجود ومختبر آليًا | AuthController + Feature وPostgreSQL tests |
| Email/password login | موجود ومختبر آليًا | AuthController + Feature tests |
| Guest identity | موجود ومختبر آليًا | إنشاء حساب خادمي وSanctum token |
| Guest -> Email account | موجود ومختبر آليًا | يحتفظ بمعرف user row ويبدل guest fields |
| Guest -> Google account | موجود ومختبر آليًا بclaims مزيفة | يلزم Google حقيقي على Android |
| Google ID token verification | تنفيذ خادمي موجود | لا يوجد إثبات real-device OAuth في run #127 |
| Logout | موجود ومختبر آليًا | إلغاء presented token |
| Profile read/update | موجود ومختبر | لا يوجد إثبات client live لكل الحقول |
| Change email/password | موجود ومختبر آليًا؛ التسليم الخارجي غير مثبت | token verification وpassword change وsession revocation؛ mail notification queued/encrypted لكن mail delivery معطل حتى اختيار مزود بعد تطوير Backend |
| Delete account | موجود ومختبر آليًا | يحذف الحساب وبيانات owner عبر FK cascade؛ إذا كان wallet credit موجبًا يرفض افتراضيًا بـHTTP 422 ويحتاج `acknowledge_wallet_credit=true` صراحة؛ اختبارات Feature/PostgreSQL |
| Username availability | موجود | route/controller موجود |
| Firebase legacy token exchange | client-only / backend missing | Flutter ينادي auth/firebase/exchange |
| Password reset/email verification | تنفيذ خادمي وqueue موجود؛ التسليم الحقيقي مؤجل | token flow/notifications مشفرة ومجدولة؛ provider/action URL غير معتمدين والإرسال disabled؛ Google/mail staging بعد أعمال Backend المستقلة عن المزود |

## 2. الأجهزة والجلسات

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Sanctum bearer session | موجود ومختبر | token persisted in PostgreSQL |
| Device ID/name/platform metadata | موجود ومختبر | migration + feature/PG tests |
| Replace previous token for same install | موجود ومختبر | نفس device_id |
| Replace selected device token ID | موجود جزئيًا | scoped للمستخدم؛ لا توجد API لعرض الأجهزة |
| Active device listing | موجود ومختبر آليًا | GET /api/v1/user/devices يعرض جلسات الحساب؛ DELETE /user/devices وDELETE /user/devices/{tokenId} للإلغاء، owner-scoped مع Feature/PostgreSQL tests |
| Plan-based concurrent device limit | غير موجود | لم نجد backend enforcement أو policy |
| FCM device registration | endpoint تخزين/تسجيل موجود ومختبر | POST devices/register؛ token مشفر وHMAC lookup؛ لا sender ولا إثبات FCM delivery أو اعتماد FCM provider |
| OneSignal subscription registration | endpoint تخزين/تسجيل موجود ومختبر | POST/DELETE push/subscriptions؛ UUID مشفر وowner-scoped/idempotent؛ لا sender أو delivery، وOneSignal غير مختار كمزود |

## 3. بيانات المكتبة والمشاهدة

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Movie/TV favorites cloud | موجود ومختبر آليًا | migration 000008 وBookmarkSyncController؛ Feature + PostgreSQL tests ضمن main/CI |
| Custom playlists cloud | موجود ومختبر آليًا | migration 000010 وPlaylistSyncController؛ 6 operations وoperation log/version conflict/cursor paging؛ Feature + PostgreSQL tests |
| Import/share playlists | client-only / backend missing | client repository يطلب playlist-imports/shared-lists |
| Recently watched movies | موجود ومختبر آليًا | migration 000009 وrecent sync؛ LWW/tombstones/revisions/clock skew؛ Feature + PostgreSQL tests |
| Recently watched episodes | موجود ومختبر آليًا | movie/episode progress؛ media metadata وserver revisions؛ اختبارات Feature/PostgreSQL |
| Playback progress cross-device | endpoint موجود؛ CI غير معتمد | elapsed/remaining/UTC milliseconds مع server revision وupload ack؛ full multi-device/offline orchestration يبقى Phase07 |
| Wellness/viewing sessions | موجود ومختبر آليًا | POST sync/wellness؛ owner-scoped revisions/cursor/tombstones؛ Feature + PostgreSQL tests |
| Cross-device sync reliability | COMPLETE وفق Phase07 | 496 Flutter tests و25 Android ABI/offline tests؛ Backend PostgreSQL concurrency و8 workers/daily aggregate evidence في تقرير الإغلاق |
| Guest local data merge | client orchestration exists | يحتاج backend sync paths حتى يثبت نقل البيانات السحابية |

## 4. Config, ads, notifications, services

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Config bootstrap + ETag | client-only / backend missing | ConfigRepository وsmoke test يتوقعانه |
| Dynamic ads catalog | client-only / backend missing | AdsRepository يطلب GET ads |
| Ad impressions/clicks | client-only / backend missing | POST ads/{id}/impression/click |
| Announcements/in-app messages | client-only / backend missing | GET messages/active |
| Push message deep link fetch | client-only / backend missing | GET push/messages/{id} |
| Flix AI API | client repository exists; runtime wiring/server absent | route implementation not found |
| Scraper API v2 | separate service contract | root openapi.json has /api/v2/*; not this account backend |
| TMDB metadata | client/public service path | not a user-data backend responsibility |

## 5. Billing / subscription / wallet

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Billing plans | route موجود؛ default-off | GET /api/v1/billing/plans؛ `purchases_enabled=false` و`plans=[]` حتى اعتماد الخطط والأسعار والشروط؛ لا payment provider |
| User entitlements | موجود ومختبر آليًا بعد 09A | GET /api/v1/user/entitlements؛ Sanctum owner-scoped، server-calculated، start/expiry/revocation، default-deny. لا write API للعميل. |
| Subscription event ledger, projection and expiry | موجود ومختبر آليًا حتى 09F | append-only ledger، atomic projection/stale-event ordering؛ `subscriptions:expire-due` preview-first؛ no payment webhook/provider |
| Wallet balance/history | موجود ومختبر آليًا | GET /api/v1/wallet وGET /api/v1/wallet/transactions؛ owner-scoped cursor pagination، internal append-only ledger؛ لا public credit/top-up API |
| Topups/payment/reconcile | غير منفذ في Backend | client expectations فقط؛ payment provider وwebhooks مؤجلان بعد تطوير Backend |
| Coin catalog | غير منفذ في Backend | لا GET billing/coins ولا coin purchase system |
| Wallet subscription renewal | غير منفذ في Backend | لا wallet renewal API أو auto-renewal |
| Coupon redemption | غير منفذ كـpublic API | ledger source supports internal coupon classification only؛ لا redemption endpoint |
| Account deletion wallet-credit check | موجود ومختبر آليًا | Backend يرفض حذف حساب ذي رصيد موجب افتراضيًا، ويتطلب acknowledge_wallet_credit=true؛ HTTP 422 contract |

## 6. الأمن والتشغيل

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Basic API health | موجود | /up, /api/v1/health |
| Runtime readiness/schema verification | موجود | health + startup probe |
| Safe logs | جزئي | logger omits SQL/password/body but error response still exposes source file/line/diagnostic |
| Rate limit on auth endpoints | موجود جزئيًا | per-route throttle in routes/api.php |
| Shared cache across instances | baseline database-backed | `CACHE_STORE=database` recommended/production default؛ ليس file-only؛ provider-specific cache deferred |
| Backup and restore rehearsal | synthetic CI PASS فقط | PostgreSQL encrypted dump/restore على قواعد مؤقتة اصطناعية؛ لا backup/restore إنتاجي أو مزود مختار |
| Observability/alerts | غير مثبت | no complete metrics/alert runbooks observed |
| Provider-neutral runtime | موجود ومختبر آليًا | Laravel 13 + PHP 8.4 + FrankenPHP/Caddy OCI image؛ PostgreSQL standard URL/TLS؛ local private storage default؛ لا provider-specific production deployment |
| AWS deploy OIDC | غير موجود | no deployment workflow/oidc role in workflows |
| Provider switch/rollback | rollback rehearsal محلي فقط | HTTP 200→502→200 في حاويات معزولة؛ لا provider switch أو cloud load-balancer rollback حقيقي |

## 7. خلاصة الأولويات

1. لا نعيد كتابة المصادقة الموجودة؛ نكملها ونحافظ على عقدها.
2. الفجوات المتبقية هي imports/public playlist sharing، wellness/config/ads/push/billing، والمزامنة الشاملة offline/device orchestration.
3. قبل بدء schema لهذه المجالات، يجب توثيق عقود JSON والـownership وidempotency وحالات التعارض من Flutter code.
4. لا نبدأ Phase 01 قبل إكمال كل consumer/path inventory في Phase 00.


## 8. تحديث التنفيذ — 23 نوفمبر 2026

Bookmarks وRecently watched وPlaylist sync أصبحت لها migrations/controllers/routes على main. ويشمل playlist backend عمليات create/update/delete/reorder/item.add/item.remove، operation log وversion conflict responses وrevision snapshot pagination. تصدير الحساب v3 يشمل المكتبة والقوائم.

هذه الميزات تصنف «كود موجود / CI غير معتمد» لأن أحدث jobs على الفروع الثلاثة فشلت قبل تخصيص runner (runner_id=0، steps=[]). لذلك لا تصنف «موجود ومختبر» حتى تعمل البوابات فعليًا. استيراد القوائم ومشاركتها العامة ليست ضمن Phase06.

## 9. توحيد الاختبارات على main — 23 نوفمبر 2026
- أصبحت اختبارات bookmarks وrecently-watched وplaylists وpreferences وPostgreSQL المقابلة كلها في main.
- اجتاز SHA `800cff70` عدد 81 Feature tests /824 assertions و9 PostgreSQL Integration tests /219 assertions؛ كما نجح Pint وSecurity.
- لا يثبت ذلك تكامل Google Android أو تسليم البريد الحقيقي أو push/storage/billing أو cross-device/offline orchestration؛ تظل هذه عناصر مراحل مستقبلية أو اختبارات staging.

## 10. مزامنة المصدر الحالية — 27 نوفمبر 2026

- Auth: email/password/guest lifecycle وGoogle ID-token verifier على الخادم؛ claims المزيفة في الاختبارات لا تثبت Google OAuth من Android. Verification/reset notifications مشفرة وqueued؛ الإرسال الخارجي مغلق.
- User data: profile/preferences/devices/export وbookmarks/recently watched/playlists/wellness/media metadata موجودة مع owner-scoping ومigrations واختبارات SQLite/PostgreSQL.
- Notifications/storage: private filesystem storage default وS3-compatible adapter contract مع S3Mock؛ push subscriptions registry وencrypted outbox وworker retry/dead-letter. لا يوجد S3 bucket أو mail sender أو Push sender خارجي مفعل.
- Billing: entitlement read API، subscription event append-only ledger/projection/expiry command، billing plans route default-off، wallet read-only APIs وinternal ledger. لا checkout/top-up/payment/webhook/renewal/coupon public APIs، ولا مشتريات مفعلة.
- Runtime/security/release: Laravel 13، PHP 8.4، FrankenPHP/Caddy non-root، PostgreSQL roles split، TLS checks، readiness، log redaction، API contract manifest، image provenance/SBOM/signing، وisolated rollback rehearsal.
- IaC: OpenTofu 1.13.10 provider-neutral scaffold فقط؛ لا providers/resources/modules/backend/cloud/apply أو deployment identity.
- Current CI evidence on `617ce7e`: Docker SQLite 151/1,719؛ PostgreSQL 31/546 مع optional skip واحد؛ S3Mock 1/8؛ Auth SQLite 20/172 وPostgreSQL 4/99؛ Docker/Auth/Pint/Security SUCCESS. Pint changed-PHP steps skipped لعدم وجود PHP changes بينما strict Pint داخل Docker PASS.
- Provider selection deferral D-050: لا اختيار مزود/منطقة/خطة/تكلفة الآن. تُستكمل الأعمال المحايدة ثم تُراجع جميع الاختيارات معًا؛ لا يحوّل ذلك أي integration غير مجرب إلى COMPLETE.
