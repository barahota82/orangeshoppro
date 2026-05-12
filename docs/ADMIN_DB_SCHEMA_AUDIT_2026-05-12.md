# تقرير تدقيق شامل: شاشات الأدمن مقابل مخطط قاعدة البيانات

التاريخ: 2026-05-12

## نطاق التدقيق

- تم فحص جميع صفحات الأدمن في `admin/pages` (عدد 66 شاشة).
- تم فحص واجهات الحفظ/الإدارة في `admin/api` (عدد 111 Endpoint).
- تمت المطابقة مع:
  - لقطة القاعدة الفعلية: `scripts/orange_db.sql`
  - مخطط الإنشاء الكامل: `scripts/mysql-create-orange-database-full.sql`
  - مخطط الترقية أثناء التشغيل: `includes/catalog_schema.php`

## نتيجة التدقيق التنفيذي

- الحالة العامة: حقول الإدخال في شاشات الأدمن لها أماكن حفظ فعلية في القاعدة.
- الفجوة الواضحة التي كانت موجودة في نطاق دليل المقاسات الإرشادي تم إغلاقها:
  - اعتماد اسم داخلي عربي فقط في `advisory_sizing_guides`.
  - إزالة الأعمدة الزائدة الخاصة باسم الدليل متعدد اللغات (`name_en`, `name_fil`, `name_hi`) من مسار الكود والمخطط.

## التصحيحات التي تم تنفيذها

### 1) تصحيح زوائد جدول `advisory_sizing_guides`

- إزالة الاعتماد البرمجي على:
  - `advisory_sizing_guides.name_en`
  - `advisory_sizing_guides.name_fil`
  - `advisory_sizing_guides.name_hi`
- تحديث حفظ/قراءة الأدلة ليعتمد على `name_ar` فقط كاسم داخلي.

الملفات المعدلة:
- `admin/pages/advisory_sizing_guides.php`
- `admin/api/advisory_sizing_guides/manage.php`
- `admin/api/advisory_sizing_library/manage.php`
- `includes/advisory_sizing_library.php`
- `admin/pages/products.php`

### 2) مزامنة مخطط الترقية والإنشاء الكامل

- تحديث `includes/catalog_schema.php` إلى مراجعة:
  - `ORANGE_CATALOG_SCHEMA_PHP_REVISION = 34`
- تحديث تعريف جدول `advisory_sizing_guides` وإزالة أعمدة الاسم غير المستخدمة.
- إضافة إسقاط آمن لأعمدة الاسم الزائدة في مسار الترقية.
- تحديث `scripts/mysql-create-orange-database-full.sql` ليطابق الواقع التشغيلي:
  - إضافة أعمدة النطاق المفقودة في `advisory_sizing_guides`:
    - `department_id`
    - `size_scheme_template_id`
    - `commercial_kind_key`
  - إضافة العمود:
    - `products.sizing_advisory_guide_id`
  - إضافة جداول كانت مفقودة من ملف الإنشاء الكامل رغم استخدامها في الأدمن:
    - `commercial_kind_dictionary`
    - `sizing_category_dictionary`
    - `delivery_areas`
    - `product_colorway_images`
    - `cart_gift_promotions`
    - `cart_bogo_promotions`
    - `cart_combo_promotions`
    - `storefront_copy_lines`

## ملخص الامتثال

- لا توجد خانة إدخال في شاشة الأدمن بدون مقابل حفظ في SQL ضمن النطاق الذي تم تدقيقه.
- لا توجد أعمدة زائدة مؤكدة ضمن هذا النطاق بعد إغلاق فجوة `advisory_sizing_guides` المذكورة أعلاه.
