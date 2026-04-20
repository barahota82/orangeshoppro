-- Orange — فحص سريع للمخطط مقابل توقعات المشروع (MariaDB / MySQL 8+)
-- مراجعة لقطة الحقيقة المحلية: D:\orange\scripts\orange_db.sql (اتفاق المالك).
-- نفّذ على قاعدتك بعد: USE orange_db; (أو اسم DB في config.php)
--
-- تفسير مختصر:
--   • journal_entries: غياب الجدول = متوقع في قاعدة من mysql-create الحالي (لا يُنشأ).
--     وجود الجدول = تراث؛ الترحيل/الصيانة: catalog_schema + IBRAHIM_ORANGE_MASTER.txt قسم (6).
--   • product_channels: يُفضّل قيدان FK فقط باسمي orange_fk_pc_*.
--   • categories.slug: يُفضّل varchar(191) utf8mb4.

SELECT 'tables_base' AS check_id, COUNT(*) AS cnt
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

SELECT 'journal_entries_exists' AS check_id,
       COUNT(*) AS cnt
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_entries';

-- صفوف السندات (يفشل إن لم تُنشأ الجداول بعد):
SELECT 'journal_voucher_rows' AS check_id, COUNT(*) AS cnt FROM `journal_vouchers`;
SELECT 'journal_line_rows' AS check_id, COUNT(*) AS cnt FROM `journal_lines`;

SELECT 'product_channels_fk' AS check_id,
       CONSTRAINT_NAME,
       TABLE_NAME
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'product_channels'
  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
ORDER BY CONSTRAINT_NAME;

SELECT 'categories_slug' AS check_id,
       COLUMN_TYPE, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'categories'
  AND COLUMN_NAME = 'slug';

SELECT 'subcategories_slug' AS check_id,
       COLUMN_TYPE, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'subcategories'
  AND COLUMN_NAME = 'slug';
