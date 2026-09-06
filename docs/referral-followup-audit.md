# تدقيق نظام الإحالة والمتابعة — Referral & Follow-Up Audit

تاريخ التدقيق: 2026-09-06 · Laravel 12.68 · Filament 4.12 · PHP 8.3

---

## 0. الخلاصة أولاً — Executive summary

النظام الذي طلبته **موجود بالفعل بنسبة كبيرة**. الـ commit الأخير `82b0350 Referral Centre` أضاف طبقة إحالة كاملة ومختبَرة (26 اختباراً ناجحاً). ما كان ناقصاً هو **العرض**: الشاشة كانت تُخفي الحالات غير المؤهلة بدل أن تعرضها بحالتها.

ثلاث نقاط من طلبك **لا يمكن تنفيذها دون تعديل يمسّ الاستيراد أو قاعدة البيانات**، وهي موقوفة بانتظار موافقتك (القسم 9).

نقطة واحدة في طلبك **تصف منطق `Visit Type` بشكل مختلف عمّا هو مُنفَّذ فعلياً**. لم أغيّر شيئاً، لكن يجب أن تعرف (القسم 2).

---

## 1. الملفات التي فحصتها

| الطبقة | الملفات |
| --- | --- |
| النماذج | `Child`, `FollowUpChild`, `FollowUpChildVisit`, `ReferralBatch` |
| الإحالة | `ReferralCandidates`, `ReferralProcessor`, `ChildFollowUpTransfer`, `ReferralCenter` |
| التصنيف | `MuacClassifier`, `ChildDuplicateChecker` |
| الاستيراد | `AbstractTableImport`, `ImportDefinition`, `ImportSchema`, `ExcelImportService`, `ImportedRowDeriver`, `ChildrenImport`, `FollowUpChildImport` |
| الصلاحيات | `RolesAndPermissionsSeeder`, `SyncPermissionsSeeder`, `ChildPolicy`, `FollowUpChildPolicy`, `AuthorizesModuleActions` |
| قاعدة البيانات | 41 migration، منها جداول الأطفال والمتابعة والزيارات ودفعات الإحالة والفهارس |
| الاختبارات | 604 اختباراً، منها `ReferralWorkflowTest` و `ChildVisitTypeAndDuplicateTest` و `ChildFollowUpTransferTest` |

**لم أحذف أي كود أو Import.**

---

## 2. ⚠️ منطق Visit Type الفعلي يختلف عن وصفك

هذه أهم نقطة في التدقيق كله.

أنت وصفت القاعدة هكذا:

> أول ظهور = `New` · ظهور مرة أخرى = `Follow-up`

المنطق **المُنفَّذ فعلياً** في `app/Support/ChildDuplicateChecker.php:96` هو **قاعدة انتكاسة** (relapse rule):

1. لا يوجد سجل نشط سابق لنفس `child_id` ← `new`
2. يوجد سجل سابق ← تُقارَن شدة الحالة الغذائية الحالية بالسابقة:
   - تدهور (`Normal → MAM`، `Normal → SAM`، `MAM → SAM`) ← **`new` مرة أخرى**، لأنه إدخال جديد
   - ثبات أو تحسّن ← `follow_up`
3. لا يوجد MUAC للمقارنة ← `follow_up`

الترتيب معرَّف في `ChildDuplicateChecker::FI_SEVERITY` (`Normal`=0، `MAM`=1، `SAM`=2).

هذا المنطق **مغطّى باختبارات كاملة** (`ChildVisitTypeAndDuplicateTest`، مصفوفة 9 حالات) ويُطبَّق أيضاً على استيراد Excel عبر `ImportedRowDeriver::children()`، أي أن قيمة `Visit Type` المكتوبة في الملف تُهمَل ويُعاد اشتقاقها.

**لم أمسّ هذا المنطق إطلاقاً.** لكن انتبه: طفل SAM يظهر للمرة الثانية بقياس أسوأ سيُسجَّل `new` وليس `follow_up` — وهذا هو السلوك القائم والمقصود، لا خطأ.

أضفت اختبارات تُثبّت أن الإحالة وفتح ملف المتابعة وتسجيل Visit 1 **لا تغيّر `visit_type` أبداً**.

---

## 3. الـ Business Logic القائم كما وجدته

### 3.1 التصنيف — `MuacClassifier`

مصدر واحد للحقيقة، لا نسخة ثانية في أي مكان:

- `MUAC ≤ 115` ← `SAM`
- `115 < MUAC < 125` ← `MAM`
- `MUAC ≥ 125` ← `Normal`
- `MUAC` فارغ ← **`null`**، وليس `Normal`

النقطة الأخيرة موثّقة صراحة في الكود: «قياس فارغ ليس له تصنيف إطلاقاً، وهذا ليس نفس شيء Normal». هذا يعني أن **متطلبك رقم 13 محقَّق أصلاً في طبقة التصنيف**.

`Child::fi` و `FollowUpChildVisit::fi` كلاهما accessor يُعيد الاشتقاق من `muac` الحالي، فلا يمكن أن يحمل السجل تصنيفاً يخالف قياسه.

### 3.2 فصل Screening عن CMAM — قائم بالفعل

`ChildFollowUpTransfer` يوثّق الفصل صراحة. الاستيراد **لا يُحيل أحداً**، واختبار `test_a_children_import_still_refers_nobody_on_its_own` يحرس ذلك. فتح ملف المتابعة قرار بشري فقط، من ثلاث نقاط دخول:

1. نموذج إنشاء طفل (`CreateChild`) — نافذة تأكيد
2. نموذج تعديل طفل (`EditChild`) — عند تدهور القياس
3. مركز الإحالة (`ReferralCenter`) — إجراء جماعي

### 3.3 قفل الحالة — `FollowUpChild::CLOSING_OUTCOMES`

خمس نتائج تُغلق الملف، معرَّفة في النموذج نفسه:

`cured` · `defaulted` · `discharge_to_opt` · `discharge_to_other` · `died`

والنتيجة السادسة `under_follow_up` تعني أن الملف مفتوح. `isLocked()` تجعل السجل للقراءة فقط عند الإغلاق.

**لم أخترع أي Status جديد.** كل ما بنيته يقرأ من هذه القائمة.

### 3.4 منع التكرار — ثلاث طبقات قائمة

1. `ChildFollowUpTransfer::hasOpenEpisode()` — فحص قبل كل إنشاء
2. `ReferralCandidates::childIdsAlreadyUnderFollowUp()` — استعلام واحد للدفعة كاملها
3. `ReferralProcessor` يُحدّث المجموعة داخل الحلقة، فصفّان لنفس الطفل في نفس الملف يفتحان ملفاً واحداً

النظام **Idempotent فعلاً** ومُختبَر: `test_referring_the_same_selection_twice_opens_only_one_episode`.

### 3.5 قاعدة إعادة الفتح القائمة

طفل ملفّه **مقفل** يعود مؤهلاً للإحالة — الانتكاسة إدخال جديد. هذا **مُختبَر صراحة** في `test_a_child_whose_previous_episode_is_closed_is_a_candidate_again`.

طلبك رقم 11 يقول «لا تعيد فتح الحالات المقفلة تلقائياً» — وهذا محقَّق: **لا شيء تلقائي**. لكن القاعدة القائمة **تسمح** بإعادة الإحالة اليدوية، ولم أغيّرها لأنك قلت «لا تخترع Reopen Logic». ما فعلته هو أن الشاشة صارت تقول بوضوح `Closed / previously followed` بدل أن تعرضه كحالة جديدة.

---

## 4. ما كان ناقصاً فعلاً — الفجوات

| # | الفجوة | الحالة |
| --- | --- | --- |
| 1 | مركز الإحالة يُخفي من هو في المتابعة أو مقفل بدل عرضهم بحالتهم | ✅ نُفِّذ |
| 2 | لا يوجد عرض لحالات بدون MUAC | ✅ نُفِّذ |
| 3 | لا تبويب Active / Closed في وحدة المتابعة | ✅ نُفِّذ |
| 4 | لا عدّادات للحالات النشطة والمقفلة والناقصة | ✅ نُفِّذ |
| 5 | `follow_up_child_visits.muac` غير قابل لـ NULL | ⛔ موقوف |
| 6 | استيراد Follow-Up Excel بلا أي كشف تكرار | ⛔ موقوف |
| 7 | لا توجد Normalization لـ Child ID | ⛔ موقوف |

---

## 5. ما نُفِّذ — الملفات المتأثرة

### مُعدَّل

| الملف | التغيير |
| --- | --- |
| `app/Support/Referral/ReferralCandidates.php` | **إضافة فقط**. أربع حالات، `overview()`, `scopeToStatus()`, `statusCase()`, `statusSummary()` مع cache، استعلامات Active/Closed/Missing. `query()` و `summary()` و `scopeToBatch()` **لم تتغيّر حرفاً**. |
| `app/Filament/Pages/ReferralCenter.php` | الجدول صار يقرأ `overview()` مع عمود حالة مُختار في SQL، وفلتر حالة افتراضه يُعيد نفس القائمة القديمة بالضبط. أعمدة تاريخ الميلاد والحالة الحالية وحالة الإحالة. |
| `app/Support/Referral/ReferralProcessor.php` | سطران: إبطال cache العدّادات بعد إحالة ناجحة. |
| `app/Filament/Resources/FollowUpChildResource/Pages/ListFollowUpChildren.php` | `getTabs()` بثلاثة تبويبات: All / Active / Closed. |
| `app/Filament/Resources/FollowUpChildResource.php` | ثلاثة أعمدة: `admitted_with`، رقم آخر زيارة، تاريخ آخر زيارة. وعمود آخر قياس صار يكتب «غير متوفر» بدل خانة فارغة. |
| `resources/views/filament/pages/referral-center.blade.php` | صف عدّادات ثانٍ. |
| `lang/{en,ar}/ui.php`, `lang/{en,ar}/fields.php` | مفاتيح ترجمة جديدة فقط. |

### جديد

`tests/Feature/ReferralStatusAndFollowUpViewsTest.php` — 26 اختباراً.

### لم يُمسّ إطلاقاً

`ChildrenImport` · `FollowUpChildImport` · `AbstractTableImport` · `ImportSchema` · `ImportDefinition` · `ExcelImportService` · `ImportedRowDeriver` · `ChildDuplicateChecker` · `MuacClassifier` · `ChildFollowUpTransfer` · `Child` · `FollowUpChild` · `FollowUpChildVisit` · أي migration · أي Policy · أي Seeder

---

## 6. كيف حافظتُ على كل قاعدة طلبتها

**Children Visit Type.** لم أفتح `ChildDuplicateChecker` ولا `ImportedRowDeriver` للكتابة. أضفت خمسة اختبارات تُثبّت أن الإحالة وVisit 1 لا تلمس `visit_type`، وأن رقم زيارة CMAM لا يتسرّب إلى العمود أبداً.

**فصل Screening عن CMAM.** الفصل كان قائماً؛ ما فعلته هو إظهاره. مركز الإحالة الآن يعرض `Current follow-up` و `Referral status` كعمودين منفصلين، وسجل الطفل الأصلي يبقى كما هو — مُختبَر في `test_the_original_child_record_is_left_exactly_as_it_was` القائم.

**تكرار Screening للطفل Normal.** اختبار جديد يُثبت أن ظهوره مرتين لا يفتح أي ملف متابعة ولا يظهر في مركز الإحالة أصلاً.

**Closed Cases.** تبويب منفصل يقرأ `FollowUpChild::CLOSING_OUTCOMES` حرفياً. اختبار يمرّ على النتائج الخمس واحدة واحدة. حالة مقفلة لا تُحتسب Active أبداً، ولا يُعاد كتابة نتيجتها عند ظهور الطفل مجدداً في Children.

**Missing MUAC.** حالة رابعة اسمها `needs_review`، لها فلتر خاص وعدّاد خاص. لا تُصنَّف Normal ولا MAM ولا SAM، ولا تدخل قائمة المؤهلين للإحالة، ومحاولة إحالتها تُرجع `skipped`.

**منع التكرار.** الطبقات الثلاث القائمة لم تتغيّر. الفلتر الافتراضي في الشاشة يُنتج نفس مجموعة الصفوف التي كان `query()` يُنتجها، فلا تتغيّر مجموعة ما يمكن إحالته.

---

## 7. الأداء

| القياس | النتيجة |
| --- | --- |
| عمود الحالة لـ 40 صفاً | **استعلام واحد** (مُختبَر) |
| قائمة المؤهلين لـ 40 طفلاً | استعلام واحد (اختبار قائم) |
| إحالة 30 طفلاً | ≤ 3 استعلامات (اختبار قائم) |
| العدّادات | استعلام مُجمَّع واحد + 3 عدّات، مع cache 60 ثانية |

الحالة محسوبة داخل `SELECT` كتعبير `CASE` فيه `EXISTS` مترابطان — تُقيَّم بعد `LIMIT`، أي 25 مرة لصفحة واحدة لا 150,000. الفهرس `follow_up_children.id_number` قائم ويخدمها.

الـ cache مختوم بطابع يُزاد بعد كل إحالة ناجحة، فلا يبقى رقم قديم على الشاشة. **لم أغيّر chunk size** (500) ولا أي شيء في الاستيراد.

---

## 8. الصلاحيات والتدقيق

**الصلاحيات:** لم أضف صلاحية واحدة. كل شيء يمرّ عبر `children.refer` القائمة (Admin و Data Entry فقط، وSuper Admin عبر `Gate::before`). تبويبات المتابعة تحت `follow_up_children.view` القائمة. اختبار يُثبّت أن Viewer ما زال محجوباً.

**التدقيق:** `ReferralProcessor::log()` القائم يكتب في `spatie/laravel-activitylog` تحت `log_name = 'referral'`، ويسجّل من أحال ومن أي دفعة ومن أي سجل طفل. `FollowUpChild` و `Child` يسجّلان تغييراتهما عبر `LogsActivity`. **لم أضف نظام تدقيق ثانٍ.**

---

## 9. ⛔ ثلاثة تعديلات موقوفة بانتظار موافقتك

### 9.1 عمود MUAC في الزيارات غير قابل لـ NULL

```
$table->decimal('muac', 5, 1);   // NOT NULL
$table->date('visit_date');       // NOT NULL
```

**الأثر:** متطلبك رقم 14 (زيارة موجودة بلا قياس، `MUAC = Missing`) **مستحيل تقنياً اليوم**. قاعدة البيانات ترفض الصف.

بنيتُ الاستعلام والعدّاد `Missing follow-up measurements` وهما يعملان بشكل صحيح، لكنهما سيُظهران صفراً دائماً حتى يُصبح العمود قابلاً لـ NULL.

**ما يتطلبه الإصلاح:** migration تجعل `muac` (وربما `visit_date`) `nullable`.

**لماذا أوقفته:** اليوم، صفّ في ملف Follow-Up Excel فيه قياس بلا تاريخ يُسقط **الملف كله** (rollback كامل، لأن الاستيراد all-or-nothing). جعل العمود nullable يغيّر ما يقبله الاستيراد — وهذا بالضبط ما طلبت شرحه قبل تنفيذه.

### 9.2 استيراد Follow-Up Excel بلا أي كشف تكرار

`ExcelImportService::createRecord()` يبدأ بـ `new $modelClass()` دائماً. لا `updateOrCreate`، لا `firstOrCreate`، لا قيد `unique` على `id_number`.

**النتيجة العملية:** رفع نفس ملف المتابعة مرتين يُنشئ نسخة كاملة ثانية من كل الأطفال وكل زياراتهم، **بلا أي تحذير**.

هذا يصطدم مباشرة بمتطلبك رقم 7 (Match بـ Child ID · لا FollowUpChild مكرر · لا Visit مكرر · reconciliation).

**ما يتطلبه الإصلاح:** تعديل `ExcelImportService::createRecord()` أو إضافة مسار reconciliation خاص بوحدة المتابعة.

**لماذا أوقفته:** `createRecord()` مشتركة بين **الوحدات السبع كلها**. أي تعديل عليها يمسّ استيراد Children أيضاً — وهو الشيء الذي قلت صراحة ألّا يُمسّ.

**اقتراحي:** مسار منفصل يُفعَّل فقط عند `moduleKey === 'follow_up_children'`، يترك السلوك الحالي لباقي الوحدات حرفياً كما هو.

ملاحظة إضافية وجدتها في الطريق: ملف التصدير يكتب عموداً ثالثاً لكل زيارة (`Visit :n FI`) لا يعرفه الاستيراد. إعادة رفع ملف مُصدَّر تُسقط 16 عموداً في `unknownHeadings` بصمت. غير ضار للبيانات، لكنه يشوّش رسالة الخطأ.

### 9.3 لا توجد Normalization لـ Child ID

المطابقة اليوم مساواة نصية مباشرة بين `children.child_id` و `follow_up_children.id_number`.

**الأثر:** `"123456789"` و `" 123456789"` و `"123-456-789"` أطفال مختلفون.

**لماذا أوقفته:** تطبيق دالة تنظيف داخل شرط المقارنة (`REPLACE(TRIM(...))`) **يُعطّل الفهرس** ويحوّل كل استعلام إلى مسح كامل للجدول — وهو عكس متطلبك رقم 16 تماماً.

**الحل الصحيح:** عمود مُخزَّن `child_id_normalized` مفهرس على الجدولين، يُملأ بـ migration للبيانات القائمة. هذا تعديل على المخطط وعلى مسار كتابة الاستيراد، لذلك ينتظر قرارك.

**السؤال الذي أحتاج جوابه:** ما هي أشكال `Child ID` الفعلية في ملفاتكم؟ إن كانت كلها 9 أرقام نظيفة، فالفائدة قليلة والخطر أقل من الثمن.

---

## 10. حالة الاختبارات

| المجموعة | النتيجة |
| --- | --- |
| `ReferralWorkflowTest` (قائم) | 26/26 ✅ |
| `ChildVisitTypeAndDuplicateTest` (قائم) | ✅ |
| `ChildFollowUpTransferTest` (قائم) | ✅ |
| `ExcelImportTest` (قائم) | ✅ |
| `ReferralStatusAndFollowUpViewsTest` (جديد) | 26/26 ✅ |
| **الحزمة الكاملة** | **604 اختباراً، 8318 تأكيداً، فشل واحد** |

الفشل الوحيد `ExampleTest::test_the_application_redirects_guests_to_login` يتوقع تحويل الزائر إلى `/admin/login` بينما التطبيق يحوّله إلى `/admin`. **تحققتُ منه على نسخة نظيفة قبل تعديلاتي وهو يفشل هناك أيضاً** — عطل سابق لا علاقة له بهذا العمل.

---

## 11. تغطية متطلباتك الـ 23 للاختبار

| # | المتطلب | التغطية |
| --- | --- | --- |
| 1 | أول Screening = New | ✅ جديد + قائم |
| 2 | Screening لاحق لطفل Normal = Follow-up | ✅ جديد + قائم |
| 3 | SAM/MAM الأول لا يغيّر Visit Type | ✅ جديد |
| 4 | SAM/MAM لا يحوّل Visit Type إلى 1/2/3 | ✅ جديد |
| 5 | SAM/MAM بلا متابعة = Pending | ✅ جديد |
| 6 | Confirm ينشئ FollowUpChild | ✅ قائم |
| 7 | Confirm مرتين لا يُكرِّر | ✅ قائم |
| 8 | Active FollowUp = لا إحالة جديدة | ✅ قائم + جديد |
| 9 | Closed FollowUp = لا Reopen تلقائي | ✅ جديد |
| 10 | Normal لا يدخل CMAM بتكرار Screening | ✅ جديد |
| 11–15 | البيانات التاريخية | ⛔ موقوفة على 9.2 |
| 16 | طفل بلا MUAC يظهر في Missing MUAC | ✅ جديد |
| 17 | لا يُصنَّف Normal بسبب غياب MUAC | ✅ جديد |
| 18 | زيارة بلا MUAC تظهر في القسم الخاص | ⛔ موقوفة على 9.1 |
| 19 | لا يُخترع MUAC | ✅ جديد |
| 20 | Closed Case يظهر في Closed Cases | ✅ جديد |
| 21 | لا يظهر كـ Active | ✅ جديد |
| 22 | لا إحالة جديدة تلقائياً | ✅ جديد |
| 23 | لا يتحوّل إلى Recovered بلا سبب | ✅ جديد |

---

## 12. ما أحتاجه منك للمتابعة

1. **موافقة على جعل `follow_up_child_visits.muac` قابلاً لـ NULL** — يفتح متطلب 14 و 18.
2. **موافقة على مسار reconciliation خاص باستيراد Follow-Up** لا يمسّ باقي الوحدات — يفتح متطلبات 7 و 11–15.
3. **جواب عن أشكال Child ID الفعلية** — يحدّد إن كانت Normalization تستحق الثمن.
