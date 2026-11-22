# تقرير تقدم Phase 04 — إكمال المصادقة والحسابات وإدارة الأجهزة

**التاريخ:** 22 نوفمبر 2026  
**الحالة:** IN_PROGRESS  
**الفرع:** `feature/phase-04-authentication-completion`  
**PR:** #5 (Draft/Open)، stacked على `feature/phase-03-security-observability`  
**SHA عند هذا التحديث:** `f66e0ea1de23e6d4510d5b8dfaa2a4ea8c9a4525`

## 1. ما تم تنفيذه في الكود

- إبقاء email/password وGoogle/Guest الحاليين ومعرفات المستخدمين وSanctum session behavior.
- Email verification: random one-time token، SHA-256 hash at rest، expiry، row lock، consume once، وإبطال/استبدال الرمز السابق عند إعادة الإصدار أو تغيير البريد.
- Password reset: Laravel password broker، token hash-at-rest، expiry/throttling، response ثابت لا يكشف وجود الحساب، وعند reset ناجح يتم تدوير remember_token وإلغاء جميع Sanctum sessions.
- Device API: list sessions للحساب الحالي فقط، revoke session واحد ضمن علاقة المستخدم، أو revoke all sessions؛ كل المسارات محمية بـauth:sanctum وrate-limited.
- Mail delivery gate: `RAVEN23_AUTH_MAIL_ENABLED=false` افتراضيًا؛ لا يرسل النظام إلى mailer log/array في production، ويتطلب HTTPS app/deep-link action URL.
- Change-email يزيل verification token القديم وpassword-reset token للبريد القديم؛ delete-account ينظف tokens المؤقتة وSanctum tokens ضمن transaction.

## 2. اختبارات مضافة

- Feature tests لـhashed verification token، expiry، replay، hashed password reset، account enumeration response، session revocation، device ownership/revocation، وتصفية tokens عند تغيير البريد أو حذف الحساب.
- PostgreSQL integration test للتحقق من email verification/reset/device APIs باستخدام PostgreSQL app role الأقل صلاحية.
- يجب أن تجتاز اختبارات SQLite/PostgreSQL وPint وSecurity على SHA موحد قبل إعلان نجاح الكود.

## 3. نتائج CI عند آخر تحديث

- التشغيلات على SHA الحالي أعلاه قيد التحقق/الانتظار. لا تُحسب التشغيلات الملغاة على commits الوسيطة PASS أو FAIL.
- دوّن نتائج Docker/Pint/Security النهائية في تحديث addendum بعد اكتمالها.

## 4. ما ينتظر إجراء خارجي

- اختيار مزود بريد، إعداد runtime secrets، تعيين `RAVEN23_AUTH_ACTION_URL` إلى التطبيق/الرابط العميق الحقيقي، وإثبات وصول verification/reset emails في staging.
- اختبار Google ID token حقيقي عبر نسخة Android مع OAuth client IDs المطابقة وجهاز فعلي.
- لا توجد قراءة secrets أو تغييرات production أو نشر في هذا العمل.

## 5. الاستمرارية

استمر من آخر SHA على الفرع نفسه. اقرأ سجلات أي CI فاشل، أصلح السبب المثبت فقط، ولا تعِد Phase 00–03 أو الأعمال التي اجتازت بواباتها. لا تفعّل mail أو تغيّر provider أو app URL بالافتراض.
