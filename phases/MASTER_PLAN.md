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
| 07 | المزامنة بين الأجهزة والعمل دون اتصال — COMPLETE؛ Flutter Android CI: 496 Flutter tests و25 Android ABI/offline tests. Backend Docker أثبت 8 عمليات PostgreSQL مستقلة، تقارب السجل/الملخص اليومي، وثبّت retry/account-owner guard وقياسات request/response/latency/conflicts. | 05–06 |
| 08 | تخزين الملفات والإشعارات والمهام الخلفية — IN_PROGRESS؛ 08A–08I ناجحة على `1d354f7`، واختيار Push مؤجل صراحةً لما بعد استكمال باقي نطاق Backend 09–13. يظل sender/credential/device delivery معطلاً، مع S3/mail staging وretention/cost كاعتماديات مستقلة. | 02–05 |
| 09 | الاشتراكات والصلاحيات والميزات المدفوعة — IN_PROGRESS؛ 09A–09F مكتملة برمجيًا واجتازت CI الأربع على `009d2f1`. 09F يضيف wallet read-only ودفتر معاملات append-only وحماية حذف الرصيد؛ قرارات المنتج والدفع WAITING_EXTERNAL. | 03–05؛ لا أسعار أو مشتريات أو provider قبل اعتماد المنتج؛ Phase04 staging PARTIAL |
| 10 | الجودة والاختبارات والإصدارات — IN_PROGRESS (10A/10C/10D/10E COMPLETE؛ 10B ينتظر Admin؛ 10F immutable image/SBOM/provenance قيد التنفيذ؛ 10G لاحقًا) | 01–09 تدريجيًا؛ بوابة الإصدار النهائية تنتظر جاهزية النطاقات والاعتمادات الخارجية |
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

- Current main SHA: `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`.
- Docker Validation [45204060525](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060525): SUCCESS — SQLite Feature 92/1,046؛ PostgreSQL Integration 12/265؛ queue worker configuration guards وruntime smoke وleast-privilege PASS.
- Authentication Regression [45204060572](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060572): SUCCESS — SQLite 20/151؛ PostgreSQL 4/99.
- Pint [45204060578](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060578): SUCCESS.
- Security [45204060417](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45204060417): SUCCESS؛ Gitleaks/Trivy اجتازا، وصورة الإنتاج ظهرت Clean دون security findings في ملخص Trivy.
- Phase07 تبقى IN_PROGRESS؛ شريحة Phase08 لا تغير هذا الحكم ولا تسمح ببدء Phase09 أو AWS.
- Phase08: إشعارات البريد مشفرة في queue database، after-commit، fixed retry deadline، bounded attempts/backoff؛ لم يتم ضبط مزود بريد أو push أو object storage.
- لم تُقرأ secrets ولم تُشغّل worker على بيئة إنتاج؛ لا migrations أو deploy أو موارد مدفوعة.


## 13. متابعة CI وحماية الإنتاج — 23 نوفمبر 2026

تم التحقق من اكتمال تشغيلات CI الأربعة التي كانت قيد التنفيذ على الرأس التوثيقي: Docker #45205731260 وSecurity #45205731207 وPint #45205731265 وAuthentication #45205731245. كلها SUCCESS؛ Pint تخطى فقط خطوات الملفات المتغيرة لعدم وجود PHP changes، بينما strict Pint ضمن Docker نجح.

خلال جرد workflows اكتشفت ستة workflows lifecycle تنشئ وتحذف حسابات اصطناعية على Render production وworkflow route probe. أزيلت الملفات السبعة من main لأنها ليست بوابات CI آمنة. لا يتم حذف/تعديل قاعدة الإنتاج أو أسرارها. Phase07 وPhase08 تظلان IN_PROGRESS ولا ينتقل العمل إلى Phase09.


### تحديث Phase08 — 24 نوفمبر 2026

- آخر SHA موثق: `f4a64407b3c5ca5b8de72def9e7e13009eca57d0`.
- CI الأربعة ناجحة: Docker Validation #45288019564، Authentication #45288019611، Pint #45288020088، Security #45288019627.
- Docker يثبت 109 Feature tests / 1,196 assertions على SQLite و15 Integration tests / 295 assertions على PostgreSQL.
- Phase08 لم تُغلق؛ S3-compatible test/staging وpush وmail provider وoutbox/idempotency وretention ما زالت شروطًا مفتوحة.
- orphan cleanup لا ينفذ حذفًا فعليًا افتراضيًا: يلزم `--apply` مع إعداد تفعيل صريح. لم تُنشأ موارد تشغيل أو تُستخدم credentials.


## 14. تحديث حالة التنفيذ — 24 نوفمبر 2026 (Phase08D / queue worker)

- رأس `main` المرصود: `d0aa6d4a0e467fe6d72619858b13ef30a466722e`.
- أضيف تنفيذ اختباري مباشر لـdatabase queue worker لمعالجة `DeletePrivateObjectJob`، والتحقق من اختفاء object وعدم تسجيل failed job، ثم تكرار job بأمان بعد اختفاء object.
- بوابات CI الأربع على SHA نفسه ناجحة: Docker #45289473199، Authentication #45289472959، Pint #45289473303، Security #45289472973.
- Phase08 ما زالت IN_PROGRESS؛ S3-compatible integration وexternal mail/push وoutbox/idempotency وretention policy ما زالت مفتوحة.
- لا ينتقل العمل إلى Phase09 قبل قرارات المنتج والدفع، ولا إلى AWS/deployment قبل Phase10 والموافقات المطلوبة.


## 15. تحديث Phase08E — 24 نوفمبر 2026

- SHA `8f34a0b0b725eb6704149154e2558eed81bc497f` اجتاز البوابات الأربع: Docker #45296772477، Authentication #45296772381، Pint #45296772496، Security #45296772309.
- تم اختبار transactional notification outbox على SQLite وPostgreSQL: تشفير الحمولات، HMAC unique event keys، after-commit rollback safety، retry/crash replay، وexpired worker-claim recovery.
- تم التحقق من Flysystem S3 adapter عبر S3Mock محلي داخل Docker؛ لا يوجد تكامل staging مع S3 provider فعلي أو مورد AWS.
- Phase08 تبقى IN_PROGRESS حتى مزود البريد/push الحقيقي، provider-native idempotency، S3 staging وسياسة retention/cost المعتمدة.

## 16. تحديث التنفيذ على main — 24 نوفمبر 2026

آخر SHA برمجي اجتاز CI الكامل: df390286cbe2170d4b18a96dad06835379091019.

- Phase07: ثلاثة أجهزة تتقارب بعد تحديثات متضاربة out-of-order؛ اختبارات SQLite/PostgreSQL تؤكد السجل النهائي والملخص اليومي. PostgreSQL pagination يحافظ على snapshot عند commit كتابة بعد الصفحة الأولى، ثم يستعيد full sync بعد cursor تالف.
- Phase08F: مسارات تسجيل Push التي يستهلكها تطبيق Flutter متاحة خلف Sanctum: POST /api/v1/devices/register لـFCM، POST /api/v1/push/subscriptions لـOneSignal، وDELETE /api/v1/push/subscriptions/{subscriptionId}. تخزين token مشفر، وفهرسة hash عبر HMAC، idempotency وowner scoping.
- CI proof: Docker [45320144852](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144852)، Authentication [45320144671](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144671)، Pint [45320144645](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144645)، Security [45320144702](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45320144702) — كلها SUCCESS على SHA نفسه.
- Phase07 و08 تظلان IN_PROGRESS. لا يجوز وصف sequential interleaving بأنه concurrent transaction stress test، أو وصف تخزين Push بأنه actual notification delivery.
- Phase09 تظل WAITING_EXTERNAL حتى قرارات المنتج والدفع. لا production DB/secrets/deploy/AWS paid resources.


## 17. إغلاق Phase07 وتقدم Phase08G–08I — 24 نوفمبر 2026

- Phase07 COMPLETE على أدلة App `d9418ea` / Android CI [45328849434](https://github.com/m5ham3ds/RAVEN23/actions/runs/45328849434) وBackend concurrent sync Docker [45411252479](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252479).
- App: 496 Flutter tests، 25 Android tests، ARM64-only APK بحجم 45.64 MiB.
- Backend 8-process PostgreSQL sync: request_bytes=4191، response_bytes=546، average=166.538ms، max=209.686ms، stale_conflicts=5/8، winning timestamp=5000؛ اختبارات التقارب والملخص اليومي نجحت.
- Phase08G: provider-neutral Push transport contract مع encrypted outbox، stable idempotency key، queue باسم `push`، provider مثبت داخل encrypted event، stale subscription suppression، global kill switch، وdefault binding إلى disabled transport.
- Phase08H: `notifications:outbox-status` يعرض counts/ages فقط دون PII أو payload أو token أو idempotency key.
- Phase08I: relay لا يرسل ولا يغير سجل Push المستحق أثناء تعطيل `RAVEN23_PUSH_ENABLED`؛ يظل pending حتى إعادة التفعيل.
- Final Backend SHA `1d354f7e57ee3b5c03ee003f0fb2dbc1eff78ca3`: Docker #45411252479 SUCCESS (125 SQLite / 1,382 assertions؛ 24 PostgreSQL / 493 assertions، 1 optional skip؛ S3Mock 1/8)، Auth #45411252843 SUCCESS، Pint #45411252483 SUCCESS، Security #45411252485 SUCCESS.
- Phase08 ما زالت IN_PROGRESS لأن real S3/mail/push provider staging وnative idempotency وسياسة retention/cost تتطلب اختيارًا/موافقة/اعتمادات خارجية. Phase09 WAITING_EXTERNAL لقرارات المنتج والدفع.


## 18. Phase09F closeout and Phase10A start — 24 November 2026

- Backend main SHA 009d2f1a9e8bf1a0f7ee82cc7f4939719eda8060 passed Docker [45450292965](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292965), Authentication [45450293025](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293025), Pint [45450293004](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450293004), Security [45450292826](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45450292826): all SUCCESS.
- Phase09F COMPLETE at code/CI; Phase09 remains IN_PROGRESS for commercial/payment decisions. No checkout, top-up or payment provider enabled.
- Phase10 started incrementally with 10A: remove obsolete write-enabled Composer lock bootstrap; audit workflow inventory, triggers and permissions.
- Governance finding: main is the only branch but protected=false and no rulesets exist. Required-check activation needs GitHub admin settings write and remains open; no deployment or production modification is included.



## تحديث 24 نوفمبر 2026 — Phase10

- 10A COMPLETE بعد نجاح بوابات CI الأربع على SHA 8889f4f8da2acaedad2ed7420d30e6a35d8f2f63.
- 10B IN_PROGRESS: تقييم branch protection/required checks مكتمل، لكن main ما زال unprotected ولا توجد rulesets؛ تفعيل إعدادات GitHub يتطلب مالك/Administrator.
- 10C IN_PROGRESS: migration rehearsal مستقل لقاعدة فارغة وsnapshot قديم اصطناعي قيد التحقق عبر CI.
- لا يعتبر هذا جاهزية إصدار أو تفويضًا للنشر أو تغيير قاعدة الإنتاج.


## تحديث 24 نوفمبر 2026 — Phase10C closeout / Phase10D start

- 10C COMPLETE على SHA b8c0bb91cea76db003501e81ec30dce09e3bf858: Docker 146 SQLite / 1,548 assertions؛ PostgreSQL 31 / 541 مع optional skip؛ S3Mock 1/8؛ Auth SQLite 20/172 وPostgreSQL 4/99؛ Pint وSecurity SUCCESS. اختبار empty schema وlegacy snapshot نجحا.
- 10D IN_PROGRESS: API v1 contract manifest (39 routes)، وثيقة العقد، وautomated router/auth/response/error contract tests بدأت على main؛ تنتظر CI.
- 10B تبقى مفتوحة لحماية main وrequired checks لأنها تحتاج صلاحيات GitHub Admin ونموذج enforcement يطابق سياسة single-main branch.
