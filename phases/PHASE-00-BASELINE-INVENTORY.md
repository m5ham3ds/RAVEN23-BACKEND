# المرحلة 00 — جرد المستودع وتثبيت خط الأساس

**الحالة:** COMPLETE — اكتمل الجرد المصدري الثابت.  
**الاعتماديات:** لا توجد  
**نوع العمل:** قراءة وتحليل وتوثيق؛ دون تغيير في التشغيل أو البيانات.

## الهدف
تكوين صورة موثقة لما يملكه Backend حاليًا، وما يستهلكه تطبيق RAVEN23، وما هي البيانات والخدمات والإعدادات الموجودة. تمنع هذه المرحلة إعادة تنفيذ شيء موجود أو إسقاط وظيفة مستخدمة دون قصد.

## نطاق العمل والنتيجة

1. **جرد Backend recursive:** اكتمل. Git tree على baseline commit غير truncated ويحتوي 34 tracked files في 17 مجلدًا.
2. **فحص source/config/Docker/migrations/tests:** اكتمل للمجموعة الكاملة من الملفات المتتبعة؛ راجع BACKEND_FILE_CATALOG.md.
3. **API inventory:** اكتمل source-level؛ راجع API_CONTRACT_INVENTORY.md.
4. **مطابقة Flutter:** اكتملت مطابقة repositories وsync/network clients الأساسية على Android baseline؛ تمت تفرقة Laravel account/data APIs عن Scraper API/TMDB.
5. **Database/data inventory:** اكتمل من models/migrations/client stores؛ لم يتم الاتصال بproduction DB حسب قواعد السلامة.
6. **Environment/secrets names:** اكتمل من config وDocker وworkflows وFlutter .env.example؛ لم تُقرأ القيم.
7. **GitHub Actions:** اكتمل جرد 10 workflows مع triggers/permissions/coupling/duplicates.
8. **CI history:** تم تصنيف runs الأساسية وأمثلة failure/cancelled وربطها بالـSHA.
9. **Feature matrix:** اكتمل source-level؛ يفرق التنفيذ الخادمي الحقيقي عن client-only والميزات غير المثبتة خارجيًا.
10. **Risks/decisions:** وثقت في BASELINE_AUDIT وFEATURE_MATRIX وتقارير المرحلة.

## المخرجات

- BACKEND_FILE_CATALOG.md
- API_CONTRACT_INVENTORY.md
- DATABASE_AND_DATA_INVENTORY.md
- ENVIRONMENT_AND_SECRET_NAMES.md
- WORKFLOW_INVENTORY.md
- FEATURE_MATRIX.md
- BASELINE_AUDIT.md
- reports/PHASE-00-REPORT-2026-11-21.md

## حدود التحقق التي لا تمنع إغلاق الجرد

- لم يتم الاتصال بقاعدة Neon الإنتاجية أو قراءة أي سجلات.
- لم تتم قراءة قيم أسرار GitHub أو DB URLs أو APP_KEY أو OAuth secrets.
- Google OAuth الحقيقي والبريد وOneSignal والدفع تحتاج staging/حسابات/اختبارًا لاحقًا.
- تم توثيق هذه البنود كاختبارات لاحقة وليس كوظائف مؤكدة.

## قرار المرحلة

**COMPLETE.** لا تعد فحص الملفات التي يثبت هذا الجرد اكتمالها ما لم يتغير baseline أو يظهر دليل جديد. المرحلة التالية المسموح بها هي Phase 01 — Reproducible Laravel Project، وفق MASTER_PLAN.md وEXECUTION_PROTOCOL.md.
