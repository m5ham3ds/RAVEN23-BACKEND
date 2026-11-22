# مصفوفة ميزات RAVEN23 Backend — الموجود والجزئي والمفقود

**Backend implementation SHA:** fea7045178e603a7b54150e75475f5d27960d306  
**Flutter SHA:** 6d12023e446db3d444716c3b407572a89cf545be  
**الحالة:** COMPLETE على مستوى source feature matrix — المصفوفة لا تدعي أن التكاملات الخارجية اختُبرت إنتاجيًا.

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
| Change email/password | موجود ومختبر جزئيًا | email verification بعد التغيير غير مكتمل |
| Delete account | موجود ومختبر جزئيًا | يحذف المستخدم والتوكنات؛ user_profiles/preferences/devices/bookmarks/recent media ترتبط بـowner FK cascade؛ wallet-credit contract mismatch ما زال قائمًا |
| Username availability | موجود | route/controller موجود |
| Firebase legacy token exchange | client-only / backend missing | Flutter ينادي auth/firebase/exchange |
| Password reset/email verification | موجود شكليًا/غير مكتمل | endpoints 503؛ لا mail flow كامل |

## 2. الأجهزة والجلسات

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Sanctum bearer session | موجود ومختبر | token persisted in PostgreSQL |
| Device ID/name/platform metadata | موجود ومختبر | migration + feature/PG tests |
| Replace previous token for same install | موجود ومختبر | نفس device_id |
| Replace selected device token ID | موجود جزئيًا | scoped للمستخدم؛ لا توجد API لعرض الأجهزة |
| Active device listing | Backend missing | عميل فيه SignedInDevice / DeviceLimit UI concept |
| Plan-based concurrent device limit | غير موجود | لم نجد backend enforcement أو policy |
| FCM device registration | client-only / backend missing | POST devices/register |
| OneSignal subscription registration | client-only / backend missing | POST push/subscriptions |

## 3. بيانات المكتبة والمشاهدة

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Movie/TV favorites cloud | كود Backend موجود؛ CI غير معتمد | migration 000008 وUserBookmark/BookmarkSyncController؛ POST/DELETE routes؛ BookmarkSyncTest في TEST |
| Custom playlists cloud | كود Backend موجود؛ CI غير معتمد | migration 000010 وPlaylistSyncController؛ العمليات الستة وoperation log وversion conflicts وstable cursor paging؛ اختبارات Feature/PostgreSQL في TEST |
| Import/share playlists | client-only / backend missing | client repository يطلب playlist-imports/shared-lists |
| Recently watched movies | كود Backend موجود؛ CI غير معتمد | migration 000009 وuser_recent_media وsync/recently-watched؛ LWW/tombstones/revisions/clock skew |
| Recently watched episodes | كود Backend موجود؛ CI غير معتمد | episode_id/series_id/season_num/episode_num؛ is_anime يبقى client-local |
| Playback progress cross-device | endpoint موجود؛ CI غير معتمد | elapsed/remaining/UTC milliseconds مع server revision وupload ack؛ full multi-device/offline orchestration يبقى Phase07 |
| Wellness/viewing sessions | client-only / backend missing | repository/SQLite/tombstone/revision client موجود؛ server route غائب |
| Cross-device sync reliability | جزئي / Phase07 | أساس recent revisions/LWW وplaylist snapshot paging موجود؛ offline/device orchestration الكامل لم ينفذ بعد |
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
| Billing plans | client-only / backend missing | BillingRepository GET billing/plans |
| User entitlements | client-only / backend missing | GET user/entitlements |
| Wallet balance/history | client-only / backend missing | GET wallet and wallet/transactions |
| Topups/payment/reconcile | client-only / backend missing | multiple wallet/topups routes |
| Coin catalog | client-only / backend missing | GET billing/coins |
| Wallet subscription renewal | client-only / backend missing | PUT/PATCH billing/subscription/wallet |
| Coupon redemption | client-only / backend missing | POST billing/coupons/redeem |
| Account deletion wallet-credit check | contract mismatch/unimplemented | Backend validates acknowledge_wallet_credit but does not check wallet credit; client expects 409 wallet_credit_remaining semantics |

## 6. الأمن والتشغيل

| الميزة | الحالة | الدليل / الفجوة |
|---|---|---|
| Basic API health | موجود | /up, /api/v1/health |
| Runtime readiness/schema verification | موجود | health + startup probe |
| Safe logs | جزئي | logger omits SQL/password/body but error response still exposes source file/line/diagnostic |
| Rate limit on auth endpoints | موجود جزئيًا | per-route throttle in routes/api.php |
| Shared cache across instances | غير متوفر فعليًا | CACHE_STORE=file imposed |
| Backup and restore rehearsal | غير مثبت | لا workflow أو evidence for successful restore observed |
| Observability/alerts | غير مثبت | no complete metrics/alert runbooks observed |
| Provider-neutral runtime | غير متوفر حاليًا | Render/Neon assumptions + PHP built-in server |
| AWS deploy OIDC | غير موجود | no deployment workflow/oidc role in workflows |
| Provider switch/rollback | غير مختبر | no infrastructure/restore/cutover evidence |

## 7. خلاصة الأولويات

1. لا نعيد كتابة المصادقة الموجودة؛ نكملها ونحافظ على عقدها.
2. الفجوات المتبقية هي imports/public playlist sharing، wellness/config/ads/push/billing، والمزامنة الشاملة offline/device orchestration.
3. قبل بدء schema لهذه المجالات، يجب توثيق عقود JSON والـownership وidempotency وحالات التعارض من Flutter code.
4. لا نبدأ Phase 01 قبل إكمال كل consumer/path inventory في Phase 00.


## 8. تحديث التنفيذ — 23 نوفمبر 2026

Bookmarks وRecently watched وPlaylist sync أصبحت لها migrations/controllers/routes على main. ويشمل playlist backend عمليات create/update/delete/reorder/item.add/item.remove، operation log وversion conflict responses وrevision snapshot pagination. تصدير الحساب v3 يشمل المكتبة والقوائم.

هذه الميزات تصنف «كود موجود / CI غير معتمد» لأن أحدث jobs على الفروع الثلاثة فشلت قبل تخصيص runner (runner_id=0، steps=[]). لذلك لا تصنف «موجود ومختبر» حتى تعمل البوابات فعليًا. استيراد القوائم ومشاركتها العامة ليست ضمن Phase06.
