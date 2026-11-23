# الخطة الرئيسية لتطوير RAVEN23 Backend

## 1. الهدف العام

تحويل المستودع الحالي إلى Backend متكامل وآمن وقابل للاختبار والنقل بين AWS وأي مزود متوافق، مع الحفاظ على وظائف الحسابات والمصادقة التي ثبت وجودها، وإضافة خدمات بيانات المستخدم التي يحتاجها تطبيق RAVEN23.

GitHub هو مصدر الكود والمخططات والاختبارات وتعريفات النشر. أما خادم الاستضافة وقاعدة البيانات والتخزين فهي خدمات تشغيل قابلة للاستبدال، ولا يجوز أن يصبح منطق التطبيق مرتبطًا بمزود واحد.

## 2. مبادئ التصميم

- Laravel 13 مع PHP واعتماديات مُدارة بملفات Composer ثابتة.
- PostgreSQL كقاعدة بيانات علائقية قياسية.
- Docker لصورة تشغيل قابلة للنقل.
- بيئة HTTP إنتاجية حقيقية؛ الاختيار بين Nginx + PHP-FPM أو FrankenPHP يكون بعد التحقق والقياس.
- واجهات مستقلة للتخزين والملفات والرسائل والمهام الخلفية.
- GitHub Actions للبناء والاختبار والنشر.
- AWS OIDC بصلاحيات مؤقتة عند اختيار AWS، دون مفاتيح ثابتة داخل المستودع.
- Infrastructure as Code لإعادة إنشاء الموارد.
- نسخ احتياطية مستقلة واختبارات استعادة فعلية.
- التوافق مع واجهات تطبيق RAVEN23 الحالية وعدم تغييرها بصمت.
- عزل إعدادات مزود الاستضافة عن منطق التطبيق.

## 3. تقسيم المشروع إلى مراحل

| المرحلة | الاسم والنتيجة المطلوبة | الاعتماديات |
|---|---|---|
| 00 | جرد المستودع وعقود API وخط الأساس | لا شيء |
| 01 | مشروع Laravel مستقل وقابل لإعادة البناء — COMPLETE (Docker #210 SUCCESS على SHA موحد؛ SQLite 14/100 وPostgreSQL 3/72) | 00 |
| 02 | بيئة تشغيل إنتاجية مستقلة عن المزود — COMPLETE (Docker #249 SUCCESS؛ SQLite 20/138، PostgreSQL 3/72، HTTP/non-root/readiness/PHP asset block PASS) | 01 |
| 03 | الأمن والرصد والتشغيل الأساسي — COMPLETE (Docker #291 SUCCESS: SQLite 34/210، PostgreSQL 3/72؛ least-privilege DB roles، TLS guard، log redaction؛ Pint #27 وSecurity #29 SUCCESS على SHA `6b14d55fb87bdd6948b0f896db9a4c99c43f7748`) | 01–02 COMPLETE |
| 04 | إكمال المصادقة والحسابات والأجهزة — PARTIAL (code/CI PASS؛ تكامل Google Android والبريد الحقيقي وحوكمة PRs ما زالت تنتظر) | 01–03 COMPLETE |
| 05 | أساس بيانات المستخدم وعقود الحساب — COMPLETE (Docker #339/#340، Pint #68/#69، Security #70/#71) | 00–04 code/CI؛ Google/mail الحقيقيان يظلان شرط staging مستقلًا |
| 06 | المكتبة الشخصية وسجل المشاهدة والقوائم — COMPLETE؛ Docker #397: 81 Feature/824 assertions و9 PostgreSQL/219 assertions؛ Auth #19: 20 SQLite/151 assertions و4 PostgreSQL/99 assertions؛ Security #143 PASS على main SHA `608c94f` | 05 COMPLETE؛ عقد Flutter موثق ومطبق على main |
| 07 | المزامنة بين الأجهزة والعمل دون اتصال — IN_PROGRESS؛ اجتازت اختبارات device convergence (2 devices)، out-of-order، idempotent retry، full resync، token revoke per-device وdaily aggregates؛ بقي 3-device/concurrent cursor/device-ID rotation/latency | 05–06 |
| 08 | تخزين الملفات والإشعارات والمهام الخلفية — IN_PROGRESS؛ mail notifications مشفرة ومجدولة عبر database queue، worker منفصل وCI guards؛ storage/push/mail provider ما زالت مفتوحة | 02–05 (Phase04 external mail/Google staging remains partial) |
| 09 | الاشتراكات والصلاحيات والميزات المدفوعة | 03–05 وقرارات المنتج |
| 10 | الجودة والاختبارات والإصدارات | 01–09 |
| 11 | بنية AWS والنشر عبر GitHub | 02–03 و08 و10 |
| 12 | ترحيل البيانات والتحويل الإنتاجي | 04–11 مع نسخة احتياطية مستعادة وموافقة المستخدم |
| 13 | تشغيل الإنتاج والتعافي وإدارة التكلفة | 12 |

يمكن تطوير بعض نطاقات 06 و08 بالتوازي بعد تثبيت عقود البيانات المشتركة، لكن لا يجوز تجاوز الاعتماديات المسجلة. المرحلة 09 تنتظر قرارات المنتج ومزود الدفع؛ لا نخترع طرق دفع أو أسعارًا أو شروط اشتراك من تلقاء أنفسنا.

## 4. خريطة الاعتماديات

```
00 جرد المستودع والعقود
 └── 01 مشروع Laravel مستقل
      └── 02 بيئة تشغيل محمولة
           └── 03 الأمن والرصد
                ├── 04 إكمال المصادقة
                └── 05 أساس بيانات المستخدم
                     ├── 06 المكتبة وسجل المشاهدة
                     │    └── 07 المزامنة
                     ├── 08 التخزين والإشعارات والمهام
                     └── 09 الاشتراكات والصلاحيات
01–09 ──> 10 بوابة الجودة والإصدارات
02 + 03 + 08 + 10 ──> 11 AWS والنشر
04–11 + استعادة نسخة احتياطية ناجحة ──> 12 النقل والتحويل
12 ──> 13 التشغيل والتعافي المستمر
```

## 5. بوابة جاهزية المرحلة

لا تبدأ أي مرحلة إلا بعد:
1. قراءة حالة المشروع وآخر تقرير.
2. التأكد من اكتمال الاعتماديات أو تسجيل اعتماد غير مانع بوضوح.
3. حصر الملفات والواجهات والجداول المتأثرة.
4. تحديد السلوك القديم الذي يجب الحفاظ عليه.
5. تحديد مخاطر البيانات والأمن والتراجع.
6. كتابة معايير قبول يمكن اختبارها.
7. حسم قرارات المستخدم المطلوبة، أو تسجيلها كاعتماد خارجي بدل التخمين.

## 6. تعريف اكتمال المرحلة

لا تسجل COMPLETE إلا بعد تحقق جميع ما ينطبق:
- التنفيذ كامل ضمن النطاق المتفق عليه.
- جميع معايير القبول اجتازت الاختبار.
- اختبارات PostgreSQL عند وجود تغييرات بيانات أو migrations.
- التوافق مع عقد تطبيق RAVEN23.
- فحص الأمن وحماية البيانات.
- تحديث الوثائق وملفات البيئة النموذجية.
- نجاح GitHub Actions على نفس commit الذي يذكره التقرير.
- وجود تقرير إغلاق مستقل داخل phases/reports/.
- تحديث حالة المرحلة والخطة وسجل القرارات.

نجاح compile أو Docker build وحده لا يعني اكتمال ميزة للمستخدم. اختبار mock لا يثبت تكامل Google أو البريد أو الدفع الحقيقي.

## 7. الأولويات الملزمة

1. حماية حسابات المستخدمين والبيانات الحالية.
2. توثيق ما هو موجود قبل تغييره.
3. بناء مشروع قابل لإعادة الإنتاج.
4. جعل التشغيل والإعدادات مستقلة عن المزود.
5. إكمال الأمن والمصادقة.
6. بناء نماذج بيانات المستخدم ثم المكتبة والمزامنة.
7. استكمال الخدمات الخلفية والاشتراكات وفق قرارات المستخدم.
8. اختبار النسخ الاحتياطي والاستعادة قبل AWS والنقل.
9. عدم تغيير وجهة الإنتاج إلا بعد موافقة مباشرة.

## 8. القرار المعماري الحالي

**المسار المعتمد: تطوير تدريجي للمنظومة الحالية، وليس إعادة كتابة من الصفر.** نحافظ على AuthController وUser model وSanctum وGoogle verifier والمigrations والاختبارات بعد التحقق منها. نعيد بناء هيكل المشروع والتشغيل حولها، ثم نضيف وحدات البيانات الناقصة.

## 9. ما لا يجوز فعله قبل موافقة صريحة

- حذف أو استبدال Neon أو قاعدة الإنتاج الحالية.
- حذف حساب مستخدم أو token أو بيانات مشاهدة.
- تنفيذ cutover أو تغيير DNS/API الإنتاجي.
- إنشاء موارد AWS مدفوعة أو اختيار خطة شهرية دون الموافقة على التكاليف.
- تخزين الفيديوهات على قرص خادم التطبيق.
- إضافة نظام دفع أو اشتراكات بأسعار أو قواعد غير معتمدة.
- استكشاف مستودعات أو أرشيفات غير مرتبطة بالمشروع دون طلب مباشر.

## 10. سجل الانتقالات والحالة الموحدة — 23 نوفمبر 2026

### حالة التنفيذ
- Phase00–03 COMPLETE وفق أدلتها التاريخية؛ Phase04 PARTIAL للتكاملات الخارجية؛ Phase05 COMPLETE على مستوى schema/API/code/CI.
- Phase06: bookmarks وrecently-watched وplaylists منفذة، وثمانية ملفات اختبار Feature/Integration نُقلت إلى main في SHA `800cff70b9663815e57b47db2be8340e8c19215e`.
- Docker على SHA الدمج نجح: 81 Feature tests /824 assertions و9 PostgreSQL Integration tests /219 assertions؛ Pint وSecurity نجحا.
- Workflows Docker/Pint/Security/Auth regression أصبحت تستهدف main فقط. Pint وAuth regression على إعداد main-only SHA `5a1b0d5` نجحا؛ Docker/Security كانا قيد الإكمال.
- Phase06 COMPLETE. Phase07 بدأت؛ Wellness API اجتاز Docker/Pint/Security/Auth على main SHA `ef3db49dea53c33622a716f8f46aa1dadccd5109` (Docker 45118862759، Pint 45118862746، Security 45118862763، Auth 45118862792). Phase07 تبقى IN_PROGRESS حتى اختبارات multi-device/offline/account-switch وdaily-summary conflict.

### سياسة الفروع
- `main` هو الفرع الدائم الوحيد؛ التطبيق والاختبارات والوثائق وCI كلها عليه.
- `RAVEN23-BACKEND-TEST` و`RAVEN23-BACKEND-USERS` حُذفا؛ GitHub branch listing أعاد `main` فقط. جميع ملفات الاختبارات الوظيفية باقية في main.

### اعتماديات AWS
- Phase08 التخزين/المهام/الإشعارات وPhase09 قرارات الاشتراكات والاستحقاقات لم تكتمل بعد.
- Phase10 release engineering تعتمد على إكمال نطاقات 01–09.
- Phase11 تعتمد على 02–03 و08 و10، وتحتاج قرار المنطقة والميزانية والتنبيهات وموافقة صريحة.
- Phase12 تتطلب backup/restore rehearsal وموافقة cutover؛ Phase13 تعتمد عليها.
- نجاح CI لا يثبت Google Android أو تسليم البريد أو مزود دفع أو استعادة إنتاجية حقيقية.


## 11. تحديث التنفيذ — 23 نوفمبر 2026 (Phase06 closure / Phase07 progress)

- Phase06 COMPLETE؛ تقرير الإغلاق موجود في `phases/reports/PHASE-06-COMPLETION-REPORT-2026-11-23.md`.
- حذف المستخدم TEST وUSERS، وتحقق branch listing من وجود `main` وحده.
- بدأ Phase07 على main دون إنشاء فرع إضافي. أضيف `POST /api/v1/sync/wellness` وmigration 000013 وجداول الجلسات اليومية وسجل التغييرات وإيصالات upload.
- CI على SHA `ef3db49dea53c33622a716f8f46aa1dadccd5109`: Docker 85/925 SQLite و10/240 PostgreSQL، Pint، Security، Authentication 20/151 SQLite و4/99 PostgreSQL — جميعها SUCCESS.
- Phase07 IN_PROGRESS؛ تبقى multi-device convergence، offline retry/recovery، account switch/token revoke، full resync وdaily-summary conflict contract.
- لا production DB/secret reads أو migrations أو deploy أو AWS resources.

### سياسة الفروع الحالية

الفرع الدائم الوحيد هو `main`. تم حذف refs TEST وUSERS والتحقق من ذلك بعد الحذف. لا تنشئ فروع اختبار أو phase دائمة جديدة.


## 12. متابعة CI والتنفيذ — Phase07/08، 23 نوفمبر 2026

- Current main SHA: `dd861fa9afd6f876ff300f65aae58a35b16f3112`.
- Docker Validation [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525): SUCCESS — SQLite Feature 92/1,046؛ PostgreSQL Integration 12/265؛ queue worker configuration guards وruntime smoke وleast-privilege PASS.
- Authentication Regression [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572): SUCCESS — SQLite 20/151؛ PostgreSQL 4/99.
- Pint [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578): SUCCESS.
- Security [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417): SUCCESS؛ Gitleaks/Trivy اجتازا، وصورة الإنتاج ظهرت Clean دون security findings في ملخص Trivy.
- Phase07 تبقى IN_PROGRESS؛ شريحة Phase08 لا تغير هذا الحكم ولا تسمح ببدء Phase09 أو AWS.
- Phase08: إشعارات البريد مشفرة في queue database، after-commit، fixed retry deadline، bounded attempts/backoff؛ لم يتم ضبط مزود بريد أو push أو object storage.
- لم تُقرأ secrets ولم تُشغّل worker على بيئة إنتاج؛ لا migrations أو deploy أو موارد مدفوعة.
