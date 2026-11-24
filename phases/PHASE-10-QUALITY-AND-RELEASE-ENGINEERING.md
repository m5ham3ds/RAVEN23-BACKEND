# المرحلة 10 — الجودة والاختبارات وهندسة الإصدارات

**الحالة:** IN_PROGRESS — 10A COMPLETE؛ 10B IN_PROGRESS لوجود إعدادات حماية main المطلوبة خارج صلاحيات اتصال GitHub المتاح؛ بدأ 10C باختبار migration rehearsal مستقل.  
**الاعتماديات:** 01–09 تدريجيًا على مستوى domain/code/CI؛ بوابة الإصدار النهائية تبقى مشروطة بجاهزية النطاقات المعتمدة، مع إبقاء اعتمادات Phase08/09 الخارجية صريحة.

## 10A — جرد CI وسلامة workflows

- جرد workflows من شجرة Git ومقارنة inventory مع الملفات الموجودة.
- إزالة bootstrap workflow انتهت مهمته وتملك صلاحيات كتابة غير لازمة؛ حزمة S3 مثبتة بالفعل في Composer lock.
- توثيق triggers والـpermissions والتداخل بين بوابات الاختبار.
- التحقق من حماية main وrequired checks؛ المرصود قبل هذه الشريحة: main فقط، protected=false ولا توجد rulesets.
- إعدادات الحماية تحتاج صلاحيات إدارة GitHub غير متاحة من إجراءات المستودع الحالية؛ تبقى OPEN ولا يُدعى أنها فُعّلت.
- أغلقت 10A بعد نجاح البوابات الأربع على commit 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63.

### إثبات إغلاق 10A — 24 نوفمبر 2026

| البوابة | التشغيل | النتيجة |
|---|---:|---|
| Docker / SQLite + PostgreSQL + S3Mock | [45455970338](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970338) | SUCCESS — SQLite Feature 144 / 1,508 assertions؛ PostgreSQL Integration 31 passed / 541 assertions، واختبار S3 optional واحد skipped؛ S3Mock منفصل 1 / 8 |
| Authentication Regression | [45455970334](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970334) | SUCCESS — SQLite 20 / 172 assertions؛ PostgreSQL 4 / 99 |
| Pint | [45455970270](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970270) | SUCCESS؛ لا تغييرات PHP في commit 10A فتخطت خطوات changed-file format؛ strict Pint داخل Docker نجح |
| Security | [45455970393](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45455970393) | SUCCESS — Gitleaks وTrivy تقارير filesystem/image؛ بوابة fixable HIGH/CRITICAL للصورة نجحت |

كل check run الأربع أُسندت إلى SHA 10A نفسه. لم يُسجل فشل أو تعديل runtime/API/schema في هذه الشريحة.

## 10B — required checks وحماية main وتقييم التداخل

**الحالة:** IN_PROGRESS — اكتمل تدقيق جهة المستودع والـworkflows؛ تفعيل branch protection/required checks ينتظر صلاحيات Admin.

- GitHub branch listing يعرض main وحده مع protected=false؛ rulesets endpoint يعيد قائمة فارغة.
- أدوات GitHub المتاحة في جلسة التنفيذ تدعم قراءة الحماية فقط ولا تعرض عملية كتابة لإعدادات repository/branch protection/rulesets؛ لم تتغير إعدادات GitHub ولم يُدّع تفعيلها.
- لا يُعد تشغيل CI بعد push حماية قبل الدمج؛ سياسة الفرع الواحد والكتابة المباشرة إلى main لا توفر required-check gate قبل تحديث الفرع. يتطلب فرض required checks قبل الدمج اعتماد PR/merge workflow أو آلية تحديث محمية متوافقة مع السياسة.
- البوابات ذات القيمة الحاجزة: Build and test portable Laravel runtime، Email, password, Google verifier and guest authentication، وSecret, dependency, filesystem and image scans.
- Pint report check الحالي ينسق نسخة مؤقتة ويرفع artifact ولا يفشل لمجرد وجود تنسيق مخالف؛ لذلك لا يصنف required check مستقلًا. strict pint --test داخل Docker هو بوابة التنسيق الملزمة.
- Authentication Regression يعيد جزءًا من تغطية Docker، لكنه فحص متخصص أسرع لدورة المصادقة؛ يُحتفظ به حاليًا لتقديم إشارة مستقلة إلى أن تُجمع بيانات وقت التنفيذ/التكلفة ثم يُعاد تقييمه.
- Docker وSecurity يبنيان صورة الإنتاج كلٌ على حدة؛ هذا تكرار تكلفة/وقت واضح، لكنه لم يُدمج في هذه الخطوة حتى لا نغيّر أسماء checks قبل إعداد required contexts رسميًا.

**قرار 10B:** إبقاء الـworkflows الخمسة كما هي في هذه الدفعة، وتوثيق أسماء check runs الفعلية دون حذف أو دمج متسرع. تظل الحماية required-check setup اعتمادًا خارجيًا مفتوحًا.

## 10C — migration rehearsal

**الحالة:** IN_PROGRESS — أضيف اختبار مستقل على SQLite لقاعدة فارغة وsnapshot حسابات/جلسات/queue اصطناعي؛ ينتظر CI على commit التغيير.

- يتأكد الاختبار من تطبيق جميع ملفات migrations من الصفر وعدّ سجلات جدول migrations وموجودية الجداول الأساسية.
- يعيد تشغيل السلسلة على schema قديم اصطناعي يحتوي حسابًا قديمًا وSanctum token وcache row وqueued job؛ ويتحقق من بقاء البيانات وbackfill للملف الشخصي والتفضيلات والجهاز.
- هذا ليس dump من الإنتاج، ولا يبرر تشغيل migration على قاعدة إنتاج.
- PostgreSQL fresh migration مغطى في Docker؛ توسيع synthetic legacy rehearsal إلى PostgreSQL يبقى تحسينًا لاحقًا إذا أظهرت نتائج الاختبار حاجة لذلك.

## الشرائح التالية

- 10B: required checks وحماية main وتقييم تداخل workflows.
- 10C: migrations على schema فارغ وsnapshot اصطناعي سابق.
- 10D: API contract regression.
- 10E: synthetic load وقياس latency/volume/conflict دون production.
- 10F: immutable image digest وربط artifact بالـSHA وSBOM/provenance.
- 10G: release manifest وتجربة rollback في بيئة معزولة.

## الهدف
منع regressions، والتأكد من أن كل commit يمر بسلسلة اختبارات واضحة وأن كل إصدار يمكن تتبعه والرجوع عنه.

## العمل
1. توحيد workflows المكررة وإزالة retry files المتشابهة بعد نقل وظائفها وعدم فقد أي تحقق مفيد.
2. تقسيم CI إلى lint/static/dependency/test/build/container/security/contract jobs.
3. تشغيل SQLite لاختبارات سريعة فقط، وPostgreSQL disposable لكل migrations/integration.
4. اختبار migration من schema فارغ ومن snapshot اصطناعي قريب من schema السابق.
5. API contract tests تغطي Android clients والمسارات والحقول والأخطاء.
6. عدم استخدام production DB لأي اختبار CI.
7. اختبارات تحميل/ضغط تدريجية مع أهداف مبنية على الاستخدام المتوقع.
8. اختبار Docker image من حيث user permissions، healthcheck، startup، signals، secrets والنسخ.
9. اعتماد immutable image tags/digests وربط الإصدار بـ Git SHA.
10. إعداد قواعد main branch/PR checks/reviews وdeployment environments.
11. تنفيذ security scans وإدارة CVEs حسب severity وخطة تصحيح.
12. حفظ artifacts/logs المهمة دون أي بيانات شخصية أو أسرار.

## معايير القبول
- جميع workflows موحدة ومفهومة ولا توجد triggers مخادعة أو شروط لا تعمل.
- كل SHA مرشح للإصدار يمر بجميع required checks.
- توجد نتائج migrations وPostgreSQL integration وAPI contract.
- image التي تُنشر هي نفسها التي تم اختبارها (digest).
- يوجد rollback إلى image سابقة موثقة.
- تقارير الفشل تحدد root cause ولا تخفي الفشل بإعادة محاولات عشوائية.

## ملاحظة
لا تعني هذه المرحلة أن الميزات المكتوبة في الخطة كلها موجودة تلقائيًا؛ يجب أن تكون scope phase feature مكتملة قبل إدخالها في release gate.
