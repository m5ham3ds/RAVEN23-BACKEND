# تقرير Phase12A — عقد تبادل جلسة Firebase القديمة

- **التاريخ:** 2026-11-28
- **الحالة:** CONTRACT DESIGN DRAFT COMPLETE؛ implementation and acceptance tests NOT RUN
- **Backend baseline:** `be14f83568e5abd51a9628d21b4e8a0c55f23d13`
- **Flutter source reviewed read-only:** `387feafb4865d991223897088b82eacb7a42061a`
- **النطاق:** وثائق Backend فقط؛ لا تغييرات على التطبيق أو API أو schema.

## 1. نتائج مطابقة العميل والخادم

أكدت مراجعة المصدر أن Flutter يطلب `POST /api/v1/auth/firebase/exchange` ويرسل `id_token` وحقول الجهاز، ثم ينتظر غلاف جلسة Laravel (`token` و`user`). لكن route غير موجود في Backend.

كما أن Google verifier الحالي يتحقق من Google OAuth client audience؛ لذلك لا يجوز استعماله للتحقق من Firebase Secure Token. مخطط `users` يحتوي `google_id` ولا يملك mapping مخصصًا لـFirebase UID، ولا يجوز إعادة استخدام `google_id` لتخزين UID من namespace آخر.

## 2. نتيجة أمنية مهمة في دورة العميل

وجدت نافذة فشل إضافية في `FirebaseSessionBridge`: بعد نجاح تبادل الخادم، ينشئ التطبيق جلسة Laravel ثم ينفذ نقل المالك المحلي. إذا فشل نقل البيانات، فإن المحاولة التالية قد ترى جلسة Laravel موجودة وتنهي جسر Firebase دون إعادة محاولة نقل المالك. لذلك يلزم حفظ حالة owner-rekey pending/completed مستقلة عن نجاح المصادقة، وألا يتم تعليم migration مكتملة قبل التحقق من جميع المخازن والـjournals.

هذا finding على كود العميل، ولم يتغير مستودع Flutter.

## 3. ما أُنجز

أضيفت مواصفة `PHASE-12A-FIREBASE-SESSION-EXCHANGE-CONTRACT.md` وتغطي:
- claims والتحقق المنفصل من Firebase token؛
- mapping فريد لـFirebase project/UID دون خلطه بـGoogle ID؛
- منع ربط الحساب عبر email وحده أو حذف guest من دون proof؛
- success/error envelope المتوافق مع Flutter؛
- rate limit وdevice/session policy وlog redaction؛
- 15 اختبارًا اصطناعيًا مطلوبًا، بما في ذلك retry بعد فشل نقل المالك محليًا؛
- القرارات المطلوبة قبل أي implementation.

## 4. الحدود والقرارات المفتوحة

لا توجد موافقة حالية على Firebase باعتباره مصدر الحقيقة أو على project/tenant أو سياسة auto-link/account creation أو revocation check. لذلك لم تتم إضافة route أو verifier أو migration أو dependency أو credentials. لم يتم الاتصال بمشروع Firebase أو قاعدة إنتاج أو جهاز.

**التالي:** حسم قرارات هوية Firebase وربط الحسابات قبل كتابة التنفيذ؛ وتنفيذ owner-rekey state machine والاختبارات في مستودع Flutter بعد اعتماد نطاق تعديل ذلك المستودع.

Phase12A تظل IN_PROGRESS؛ Phase12B/12C وPhase13B تبقى gated.
