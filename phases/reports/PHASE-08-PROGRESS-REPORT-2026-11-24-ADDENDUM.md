# تقرير متابعة Phase08 — تحديث 08G–08I — 24 نوفمبر 2026

**المستودع:** m5ham3ds/RAVEN23-BACKEND  
**الفرع:** main  
**Backend SHA الذي اجتاز بوابات CI الأربع:** `1d354f7e57ee3b5c03ee003f0fb2dbc1eff78ca3`  
**الحالة:** IN_PROGRESS — 08A–08I منفذة ومختبرة؛ provider staging والقرارات الخارجية مفتوحة.

## 08G — Provider-neutral push outbox

- عقد `PushNotificationTransport` يفصل domain/outbox logic عن FCM/OneSignal.
- الـbinding الافتراضي `DisabledPushNotificationTransport`; قيم config الافتراضية `RAVEN23_PUSH_ENABLED=false` و`RAVEN23_PUSH_PROVIDER=none`.
- كل رسالة لكل subscription في encrypted notification_outbox payload مع provider المستهدف وsubscription ID والرسالة؛ HMAC idempotency key ثابت.
- queue منفصل `push`; queue worker الافتراضي يستمع إلى `mail,push,default`، ويرفض تشغيل Push دون queue `push`.
- يراجع delivery ملكية ووجود subscription قبل استخدام adapter.

## 08H — Outbox health summary

- Artisan command `notifications:outbox-status` و`--json` يعرض counts مجمعة، pending due/scheduled، processing، expired claims، failed/sent، وأقدم pending age.
- لا يخرج user identifiers/emails، tokens، payload، idempotency key أو تفاصيل آخر خطأ.
- Feature وPostgreSQL tests تؤكد aggregate correctness وعدم تسريب الحقول الخاصة.

## 08I — Pause push relay while globally disabled

- قبل إعادة جدولة سجل push يراجع relay مفتاح `RAVEN23_PUSH_ENABLED`.
- عند false لا يغير status أو `last_dispatched_at` ولا يضيف queue job؛ يبقى الحدث pending حتى إعادة تفعيل معتمدة.
- عامل delivery نفسه يراجع مفتاح التعطيل ثانية، ويمنع الإرسال إذا عُطل بعد enqueue.
- regression test يثبت بقاء السجل unchanged وعدم تزايد وظائف queue.

## CI proof

| Gate | Run | Result |
|---|---:|---|
| Docker Validation | [45411252479](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252479) | SUCCESS — SQLite Feature 125/1,382؛ PostgreSQL Integration 24/493، 1 optional skip؛ S3Mock 1/8 |
| Authentication Regression | [45411252843](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252843) | SUCCESS |
| Pint Format | [45411252483](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252483) | SUCCESS |
| Security Scans | [45411252485](https://github.com/m5ham3ds/RAVEN23-BACKEND/actions/runs/45411252485) | SUCCESS |

Docker strict Pint، worker guards، runtime, role separation، no-DB liveness/readiness وnon-root smoke all passed.

## ما ينتظر اعتمادًا خارجيًا

1. اختيار مزود S3-compatible وحساب staging/credentials المعتمدة واختبار اتصال حقيقي. S3Mock لا يثبت اتصال مزود فعلي.
2. اختيار mail provider وإعداد action/deep-link staging وprovider-native idempotency.
3. اختيار FCM أو OneSignal وتركيب sender adapter فعلي واختبار وصول على Android/TV.
4. اعتماد retention، quota/capacity، cost وoutbox audit-retention policies.

لا أسرار أو حسابات مزود أو موارد مدفوعة أو production data أو real device pushes استُخدمت. لا تُفعّل أي integrations خارجية قبل اعتماد المستخدم.

**Phase08 remains IN_PROGRESS; no claim of production provider delivery is made.**
