# المرحلة 04 — إكمال المصادقة والحسابات وإدارة الأجهزة

**الحالة:** IN_PROGRESS — بدأت في 22 نوفمبر 2026 على PR #5  
**الاعتماديات:** المراحل 01–03 COMPLETE

## الهدف
إكمال دورة حياة الحساب مع الحفاظ على معرّف المستخدم وسجلاته وجلساته الصحيحة وسلوك Google/Guest الحالي.

## الأعمال
1. وضع عقد نهائي لتسجيل Email/Password وGoogle وGuest وفق تطبيق RAVEN23.
2. اختبار Google ID token حقيقي من نسخة Android: issuer/audience/signature/expiry/email_verified وربط OAuth client IDs.
3. إكمال email verification برمز محدود العمر ومخزن بشكل آمن.
4. تنفيذ password reset برمز one-time، مخزن hashed-at-rest، محدود العمر، مع rate limit ومنع كشف وجود البريد.
5. اختيار مزود إرسال البريد بعد مقارنة الدعم والتكلفة والتوفر وموافقة المستخدم.
6. بناء API لقائمة الأجهزة النشطة واسم الجهاز وآخر استخدام وإلغاء جلسة محددة أو الكل.
7. ضبط استبدال token للجهاز نفسه مع الحفاظ على الجلسات الخاصة بالأجهزة الأخرى.
8. إضافة اختبارات التزامن وreplay وانتهاء الرموز ومحاولات التخمين.
9. توثيق أثر تغيير البريد/كلمة المرور على الجلسات وحالة التحقق.
10. اختبار ترقية Guest إلى حساب دائم دون إنشاء هوية جديدة أو فقد بيانات مرتبطة.

## معايير القبول
- تدفقات Email/Google/Guest تجتاز Feature وPostgreSQL Integration tests.
- Google sign-in مثبت على جهاز Android حقيقي، لا mock فقط.
- رسائل verification/reset تصل وتُختبر في staging بعد اختيار مزود.
- الرموز one-time وآمنة ومحدودة العمر.
- جلسات الأجهزة قابلة للعرض والإلغاء دون التأثير في حساب آخر.
- عقد Android الحالي محفوظ أو تغييره موثق ومتوافق.

## انتظار خارجي محتمل
قد يتوقف اختبار البريد أو Google الحي على حساب أو credentials أو نطاق أو موافقة المستخدم. افصل اكتمال الكود عن الاختبار الخارجي.


## حالة التنفيذ — 22 نوفمبر 2026

- فرع التنفيذ: `feature/phase-04-authentication-completion`.
- PR #5: Draft/Open، stacked على `feature/phase-03-security-observability`، ولا يدمج PR #4 أو يعيد تنفيذ Phase 00–03.
- SHA الجاري اختباره عند آخر تحديث: `f66e0ea1de23e6d4510d5b8dfaa2a4ea8c9a4525`.
- أضيفت تدفقات hashed one-time email verification، password reset عبر Laravel password broker، وواجهات device listing/session revoke مع rate limits.
- أضيفت اختبارات Feature وPostgreSQL للتوكن المنتهي/المعاد استخدامه، hash-at-rest، عدم كشف وجود البريد، إبطال الجلسات، وصلاحيات حساب التطبيق.
- إرسال البريد مغلق افتراضيًا عبر `RAVEN23_AUTH_MAIL_ENABLED=false`؛ يلزم مزود حقيقي ورابط `RAVEN23_AUTH_ACTION_URL` واعتماد إعدادات staging قبل إثبات التسليم.
- Google verifier والربط الحالي محفوظان؛ إثبات Google ID token حقيقي من نسخة Android ما زال WAITING_EXTERNAL.

لا تسجل Phase 04 COMPLETE قبل نجاح Docker/Pint/Security على SHA موحد، واختبارات PostgreSQL، واكتمال ما يمكن اختباره من الكود. افصل تكامل staging الحقيقي للبريد وGoogle عن نتائج mocks/notifications fake.
