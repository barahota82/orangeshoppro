-- =============================================================================
-- Orange — تقوية نزاهة قاعدة البيانات (مرجعية قوية، ليست «قوة سيرفر»)
-- =============================================================================
-- الهدف: مفاتيح أجنبية + تنظيف يتامى بحيث ترفض MariaDB/MySQL بيانات مكسورة.
--
-- ملاحظة post-v52 (تعدد الدول):
--   JOIN على accounts / journal_vouchers هنا **بالمعرّف (PK)** لفحص اليتامى والقيود —
--   ليس استعلام list/report. لا يُفلتر country_id لأن المرجع = id محدد.
--   لتشخيص يتامى **ضمن دولة**: استخدم (0b) أدناه مع @country_id.
--
-- قبل التشغيل:
--   1) نسخة احتياطية كاملة (mysqldump أو أداة الاستضافة).
--   2) يُفضّل تنفيذ `mysql-day1-hardening.sql.txt` ثم `mysql-day1-hardening-part2.sql`
--      أولاً (لتفادي تكرار أسماء قيود موجودة).
--   3) نفّذ على نسخة تجريبية، ثم الإنتاج.
--
-- إن ظهر خطأ Duplicate foreign key constraint: احذف القيد القديم بنفس الاسم أو
-- علّق سطر الـ ALTER المقابل (القيد موجود مسبقاً).
--
-- الترميز: UTF-8 بدون BOM — MariaDB 10.11 / MySQL 8.x
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- (0) فحوصات تشخيصية — شغّلها وحدها أولاً إن أحببت؛ يجب أن ترجع 0 صفوف قبل القيود
-- -----------------------------------------------------------------------------
-- SELECT COUNT(*) AS bad_jl FROM journal_lines jl
--   LEFT JOIN accounts a ON a.id = jl.account_id WHERE jl.account_id IS NOT NULL AND a.id IS NULL;
-- SELECT COUNT(*) AS bad_pcw FROM product_colorways cw
--   LEFT JOIN products p ON p.id = cw.product_id WHERE p.id IS NULL;
-- SELECT COUNT(*) AS bad_sfs FROM size_family_sizes s
--   LEFT JOIN size_families f ON f.id = s.size_family_id WHERE f.id IS NULL;

-- -----------------------------------------------------------------------------
-- (0b) فحوصات يتامى scoped بدولة (post-v52 — اختياري)
-- -----------------------------------------------------------------------------
-- SET @country_id := (SELECT id FROM countries WHERE code = 'KW' LIMIT 1);
-- SET @cid := CAST(@country_id AS UNSIGNED);
-- SELECT COUNT(*) AS bad_jl_country FROM journal_lines jl
--   INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
--   LEFT JOIN accounts a ON a.id = jl.account_id AND a.country_id = jv.country_id
--   WHERE jv.country_id = @cid AND jl.account_id IS NOT NULL AND a.id IS NULL;
-- SELECT COUNT(*) AS bad_og_debit_country FROM orange_gl_pending_movements og
--   LEFT JOIN accounts a ON a.id = og.account_debit AND a.country_id = @cid
--   WHERE og.account_debit IS NOT NULL AND a.id IS NULL;

-- -----------------------------------------------------------------------------
-- (1) تنظيف يتامى (آمن قدر الإمكان: NULL حيث العمود يسمح، وإلا حذف صفوف بلا مرجع)
-- -----------------------------------------------------------------------------

-- منتجات: عائلة مقاسات غير موجودة → إلغاء الربط
UPDATE `products` p
LEFT JOIN `size_families` f ON f.id = p.size_family_id
SET p.size_family_id = NULL
WHERE p.size_family_id IS NOT NULL AND f.id IS NULL;

-- مقاسات ضمن عائلة محذوفة — لا يمكن تركها (size_family_id إلزامي في المخطط)
DELETE s FROM `size_family_sizes` s
LEFT JOIN `size_families` f ON f.id = s.size_family_id
WHERE f.id IS NULL;

-- مسارات ألوان المنتج: منتج غير موجود — الصف عديم الفائدة ويمنع المفتاح الأجنبي
DELETE cw FROM `product_colorways` cw
LEFT JOIN `products` p ON p.id = cw.product_id
WHERE p.id IS NULL;

-- صلاحيات مسؤولين: مسؤول محذوف
DELETE ap FROM `admin_permissions` ap
LEFT JOIN `admins` ad ON ad.id = ap.admin_id
WHERE ad.id IS NULL;

-- طابور الطلبات: طلب غير موجود
UPDATE `order_intake_queue` q
LEFT JOIN `orders` o ON o.id = q.order_id
SET q.order_id = NULL
WHERE q.order_id IS NOT NULL AND o.id IS NULL;

-- مصروفات: حساب مصروف غير موجود
UPDATE `expenses` e
LEFT JOIN `accounts` a ON a.id = e.expense_account_id
SET e.expense_account_id = NULL
WHERE e.expense_account_id IS NOT NULL AND a.id IS NULL;

-- إعدادات GL: نوع قيد غير موجود
UPDATE `orange_gl_account_settings` g
LEFT JOIN `journal_types` jt ON jt.id = g.journal_type_id
SET g.journal_type_id = NULL
WHERE g.journal_type_id IS NOT NULL AND jt.id IS NULL;

-- مستندات الشركة: مسؤول منشئ غير موجود
UPDATE `orange_company_documents` d
LEFT JOIN `admins` ad ON ad.id = d.created_by_admin_id
SET d.created_by_admin_id = NULL
WHERE d.created_by_admin_id IS NOT NULL AND ad.id IS NULL;

-- مردود مشتريات
UPDATE `purchase_returns` pr
LEFT JOIN `purchases` pu ON pu.id = pr.purchase_id
SET pr.purchase_id = NULL
WHERE pr.purchase_id IS NOT NULL AND pu.id IS NULL;

UPDATE `purchase_returns` pr
LEFT JOIN `suppliers` s ON s.id = pr.supplier_id
SET pr.supplier_id = NULL
WHERE pr.supplier_id IS NOT NULL AND s.id IS NULL;

DELETE pi FROM `purchase_return_items` pi
LEFT JOIN `purchase_returns` pr ON pr.id = pi.purchase_return_id
WHERE pr.id IS NULL;

-- منع إدراج product_id غير صالح: احذف البنود اليتيمة أو أصلحها يدوياً
DELETE pi FROM `purchase_return_items` pi
LEFT JOIN `products` p ON p.id = pi.product_id
WHERE p.id IS NULL;

UPDATE `purchase_return_items` pi
LEFT JOIN `product_variants` v ON v.id = pi.variant_id
SET pi.variant_id = NULL
WHERE pi.variant_id IS NOT NULL AND v.id IS NULL;

-- مردود مبيعات
UPDATE `sales_returns` sr
LEFT JOIN `orders` o ON o.id = sr.order_id
SET sr.order_id = NULL
WHERE sr.order_id IS NOT NULL AND o.id IS NULL;

UPDATE `sales_returns` sr
LEFT JOIN `customers` c ON c.id = sr.customer_id
SET sr.customer_id = NULL
WHERE sr.customer_id IS NOT NULL AND c.id IS NULL;

UPDATE `sales_returns` sr
LEFT JOIN `channels` ch ON ch.id = sr.channel_id
SET sr.channel_id = NULL
WHERE sr.channel_id IS NOT NULL AND ch.id IS NULL;

DELETE si FROM `sales_return_items` si
LEFT JOIN `sales_returns` sr ON sr.id = si.sales_return_id
WHERE sr.id IS NULL;

DELETE si FROM `sales_return_items` si
LEFT JOIN `products` p ON p.id = si.product_id
WHERE p.id IS NULL;

UPDATE `sales_return_items` si
LEFT JOIN `product_variants` v ON v.id = si.variant_id
SET si.variant_id = NULL
WHERE si.variant_id IS NOT NULL AND v.id IS NULL;

-- طابور الترحيل المحاسبي: سند غير موجود
UPDATE `orange_gl_pending_movements` og
LEFT JOIN `journal_vouchers` jv ON jv.id = og.journal_voucher_id
SET og.journal_voucher_id = NULL
WHERE og.journal_voucher_id IS NOT NULL AND jv.id IS NULL;

-- -----------------------------------------------------------------------------
-- (2) journal_lines.account_id → accounts
--     لا تحذف أسطر السند تلقائياً — إن فشل الـ ALTER، أصلح المراجع يدوياً ثم أعد التشغيل.
-- -----------------------------------------------------------------------------
ALTER TABLE `journal_lines`
  ADD CONSTRAINT `orange_fk_jl_account`
  FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- -----------------------------------------------------------------------------
-- (3) ألوان المنتج → المنتج (كان مؤجّلاً في part2)
-- -----------------------------------------------------------------------------
ALTER TABLE `product_colorways`
  ADD CONSTRAINT `fk_pcw_product`
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- -----------------------------------------------------------------------------
-- (4) عائلات المقاسات
-- -----------------------------------------------------------------------------
ALTER TABLE `size_family_sizes`
  ADD CONSTRAINT `fk_sfs_size_family`
  FOREIGN KEY (`size_family_id`) REFERENCES `size_families` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_size_family`
  FOREIGN KEY (`size_family_id`) REFERENCES `size_families` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- -----------------------------------------------------------------------------
-- (5) مصروفات، صلاحيات، طابور الطلبات، إعدادات GL، مستندات، مردودات
-- -----------------------------------------------------------------------------
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_account`
  FOREIGN KEY (`expense_account_id`) REFERENCES `accounts` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `admin_permissions`
  ADD CONSTRAINT `fk_admin_permissions_admin`
  FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `order_intake_queue`
  ADD CONSTRAINT `fk_order_intake_order`
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orange_gl_account_settings`
  ADD CONSTRAINT `fk_gl_settings_journal_type`
  FOREIGN KEY (`journal_type_id`) REFERENCES `journal_types` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orange_company_documents`
  ADD CONSTRAINT `fk_ocd_created_by_admin`
  FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `fk_pr_purchase`
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `fk_pr_supplier`
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_return`
  FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_product`
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_variant`
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_order`
  FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_customer`
  FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_channel`
  FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_return`
  FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_product`
  FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_variant`
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- -----------------------------------------------------------------------------
-- (6) طابور GL: الحسابات المدينة/الدائنة — بعد حذف أي صفوف بمرجع كسر (إن وُجد)
-- -----------------------------------------------------------------------------
DELETE og FROM `orange_gl_pending_movements` og
LEFT JOIN `accounts` ad ON ad.id = og.account_debit
LEFT JOIN `accounts` ac ON ac.id = og.account_credit
WHERE ad.id IS NULL OR ac.id IS NULL;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_debit_account`
  FOREIGN KEY (`account_debit`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_credit_account`
  FOREIGN KEY (`account_credit`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_journal_voucher`
  FOREIGN KEY (`journal_voucher_id`) REFERENCES `journal_vouchers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- (7) اختياري — سياسة المخزن: منع حذف «فئة» إن وُجدت أقسام فرعية
--     ينفَّذ يدوياً فقط بعد موافقة الإدارة (يغيّر سلوك الحذف عن CASCADE).
-- =============================================================================
-- ALTER TABLE `subcategories` DROP FOREIGN KEY `fk_subcategories_category`;
-- ALTER TABLE `subcategories`
--   ADD CONSTRAINT `fk_subcategories_category`
--   FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
--   ON DELETE RESTRICT ON UPDATE CASCADE;

-- =============================================================================
-- ما زال خارج نطاق المفتاح الأجنبي (تصميم مقصود): party_subledger.party_id
-- متعدد الأنواع (عميل/مورد/…) — النزامة تبقى في طبقة التطبيق.
-- =============================================================================
