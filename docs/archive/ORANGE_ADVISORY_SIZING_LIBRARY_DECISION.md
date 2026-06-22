# Orange — قرار تصميم: الجداول الإرشادية للمقاسات (مكتبة + عائلات + إرث)

**تاريخ التثبيت:** 2026-05-10  
**السياق:** نقاش مع المالك حول تقليل التكرار، سهولة التسجيل، وعدم ربط «ملف الدليل» حصرياً بمستوى 2 من هرَم المقاس (`clothing_tops`, `clothing_bottoms`, …) لأن ذلك يكرر نفس المحتوى على كل مفتاح.

**الحالة التقنية وقت القرار (للمرجع):** الأدلة الإرشادية في الكود الحالي مربوطة بـ **`size_family_id` + `scope_kind`** (علوي / سفلي / واحد)، والمنتج يحدد **`sizing_guide_scope`** ولا يملك اختيار «دليل حر» من شاشة المنتج.

### تنفيذ في المستودع (محدَّث)

- **ترحيل:** `scripts/migrations/031_advisory_sizing_library.sql` — جداول `advisory_sizing_library_bundles` و `size_family_advisory_library_map`؛ أعمدة **قسم + قالب** تُضاف تلقائياً عبر `orange_catalog_ensure_schema` (مسار سريع + نواة) بـ `ALTER` شرطي إن غابت.
- **حزمة المكتبة:** `department_id` (قسم رئيسي)، `size_scheme_template_id` (قالب مقاسات)، `commercial_kind_key` (مستوى 1 من القاموس عند توفره)، ثم `source_size_family_id`؛ التحقق يفرض تطابق عائلة المصدر مع **القالب + النوع التجاري**؛ حفظ الربط والمزامنة يفرضان تطابق عائلة المستهلك مع الحزمة.
- **منطق:** `includes/advisory_sizing_library.php` — `orange_advisory_sizing_library_validate_size_family_matches_bundle` + مزامنة النسخ حسب ترتيب المقاسات.
- **أدمن:** صفحة `advisory_sizing_library` بخطوات 1→4 ثم حفظ، زر «تصميم الأدلة» يفتح `advisory_sizing_guides&size_family_id=…`؛ القائمة تحت «دليل المقاس الاسترشادي».
- **إرث المنتج:** بدون عمود جديد — المنتج يبقى على `size_family_id`.
- **لم يُنفَّذ بعد:** تجاوز دليل على مستوى المنتج (نادر).

---

## القرار المتفق عليه (ملخّص تنفيذي)

1. **تصميم «أصل» الأدلة كمكتبة** لتفادي تكرار المحتوى: الإطار المنطقي للتصميم يمر بـ **قسم رئيسي** + **قالب مقاسات** + **النوع التجاري (مستوى 1 من هرَم المقاس)** — ثم بناء/تعديل الجداول الإرشادية على هذا الأساس (وليس امتلاك نسخة منفصلة إلزامياً لكل مفتاح من مستوى 2).

2. **مستوى 2** (`clothing_tops`, `clothing_dresses`, …) يبقى **للتمييز التجاري والفلترة واختيار القالب/السياسة** حيث يلزم؛ **لا** يُفترض أن يكون «مالكاً حصرياً» لملف دليل يُعاد نسخه لكل مفتاح إذا كان نفس المحتوى يُعاد استخدامه.

3. **الدقة التشغيلية مع الـ SKU** تُثبت عبر **عائلة المقاسات**: ربط مخرجات المكتبة (دليل/حزمة) بـ **عائلة مقاسات** — أكثر دقة من مستوى 2 وحده لمطابقة صفوف الدليل مع مقاسات البيع.

4. **واجهة التشغيل المفضّلة (مركّبة):**
   - **شاشة/قسم إعداد:** ربط **عائلة مقاسات → دليل/حزمة من المكتبة** (ضبط مرة واحدة على مستوى الكتالوج).
   - **سلوك يومي:** المنتج **يرث افتراضياً** الإرشاد من العائلة عند اختيار العائلة (بدون قرارات إضافية في المسار السعيد).
   - **اختياري نادر:** **تجاوز** على مستوى المنتج لحالات الشذوذ فقط — ليس المسار الافتراضي لكل الكتالوج.

5. **العلاقة بين المستويين:** مستوى 2 يكمّل العائلة — واحد للمعنى التجاري، والثاني لدقة شبكة المقاسات على الأرض؛ لا يُستبدل أحدهما بالآخر بل يُفصل دور كل منهما.

---

## ملاحظات للتنفيذ لاحقاً

- عند أي تغيير يمس **عرض الدليل في المتجر** أو الطلب/السلة: راجع **`docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt`** و**`docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt`** قبل الدمج.
- أي ترحيل مخطط: **`scripts/orange_db.sql`** محلياً إن وُجد؛ ترقيات يدوية خارج الريبو حسب **`orange-php-utf8-workflow.mdc`**.

---

## مرجع سريع (إنجليزي للوكلاء)

**Decision:** Author advisory content as a **reusable library** keyed by broad catalog context (section + size template + commercial kind L1). **Map** library bundle(s) to **`size_family`** for SKU-aligned rows. **Products inherit** default advisory from family; **optional product-level override** for rare cases. Keep **L2** for merchandising/filtering, not forced 1:1 ownership of duplicated guide files.

---

## قرار المالك — سياسة التشغيل والواجهة (2026-06-21)

**الحالة:** **تنفيذ** — `layout_kind` + `panel_kind` في `includes/catalog_schema.php`؛ `admin/pages/advisory_sizing_guides.php` + `admin/api/advisory_sizing_guides/manage.php`؛ `includes/advisory_sizing_guides.php`؛ `admin/pages/products.php`؛ `pages/product.php` + `assets/css/main.css`؛ حذف بيانات قديمة: `scripts/maintenance_wipe_advisory_sizing_guides.php` (مرة واحدة قبل التسجيل النظيف).

### أسئلة/قرارات

**س1:** هل الدليل يُشارك بين أقسام (رجali/نسائي)؟  
**ج:** **لا.** كل قسم له أدلته؛ `department_id` **إلزامي** عند التسجيل؛ لا يُعرض دليل قسم لمنتج قسم آخر.

**س2:** هل نربط دليلاً لكل `product_type` (169 نوعاً)؟  
**ج:** **لا.** الربط عبر **`size_family_id` + القسم**؛ أنواع منتج كثيرة على نفس L2 (مثل `clothing_tops`) **تشارك** نفس عائلات/أدلة المقاس (Alpha / أرقام / Free / One size = **4 عائلات ≈ 4 أدلة** لكل قسم+L2، وليس دليلاً لكل slug).

**س3:** ما خانات تسجيل الدليل في المعالج؟  
**ج:** **(1) القسم** → **(2) عائلة المقاسات** (يستنتج القالب + L1 + L2 من العائلة) → **(3) شكل الدليل:** `جدول واحد` | `جدولان (علوي+سفلي)` → اسم + أعمدة/صفوف.

**س4:** الأطقم (`clothing_sets`) — كم دليل؟  
**ج:** **دليل واحد** في الأدمن والمنتج (`sizing_advisory_guide_id` واحد)؛ داخل الدليل **قسمان** (علوي / سفلي) عند `layout_kind = dual`؛ للعميل **تاب علوي + تاب سفلي** (جدول لكل تاب).

**س5:** التيشيرت/العلوي — كم دليل؟  
**ج:** **دليل واحد لكل (قسم + عائلة)** — ليس «دليلاً واحداً لكل علوي» بكل أنظمة المقاس؛ Alpha ≠ أرقام = عائلتان = دليلان.

**س6:** المنتج — اختيار الدليل؟  
**ج:** بعد **عائلة المقاس** → إن وُجد **دليل واحد** مطابق (قسم + عائلة) **يُختار تلقائياً**؛ يبقى **«بدون»** لإلغاء الاختيار؛ لا إعادة فرض بعد الإلغاء إلا بتغيير العائلة.

**س7:** بيانات قديمة قبل التسجيل النظيف؟  
**ج:** **حذف** كل صفوف `advisory_sizing_guides` والتابعة + مسح `products.sizing_advisory_guide_id` و `product_types.default_advisory_sizing_guide_id` + خرائط المكتبة — عبر `scripts/maintenance_wipe_advisory_sizing_guides.php` **مرة واحدة** بعد الرفع وقبل إعادة التسجيل.

### تنفيذ (مسارات)

- **مخطط:** `advisory_sizing_guides.layout_kind` (`single`|`dual`); `advisory_sizing_guide_columns.panel_kind` و `advisory_sizing_guide_rows.panel_kind` (`upper`|`lower`).
- **حفظ:** ربط إلزامي `size_family_id` + `department_id` في المسار السعيد؛ `scope_kind` على الدليل: `single` أو `dual`.
- **منتج:** `list_by_family` + `department_id`؛ `sizing_guide_scope` = `both` عند `layout_kind=dual` وإلا `single`.
- **متجر:** `orange_advisory_sizing_build_sections_for_guide_id` → قسمان عند dual؛ واجهة تابين في `pages/product.php`.
