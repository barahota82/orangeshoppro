-- Orange — تفريغ جدولي عائلات المقاسات ومقاساتها، وإعادة AUTO_INCREMENT من 1
-- يطابق قيود المخطط في scripts/mysql-create-orange-database-full.sql (وما يعادله في scripts/orange_db.sql إن وُجد محلياً).
--
-- ملاحظة MySQL: TRUNCATE على size_family_sizes يفشل بـ #1701 إذا وُجد قيد أجنبي من
-- product_variants حتى بعد تصفير القيم؛ لذلك نستخدم DELETE ثم إعادة العدّاد.
--
-- استخدم: phpMyAdmin → SQL أو: mysql -u USER -p DB_NAME < scripts/maintenance_empty_size_families.sql
-- نفّذ الملف كاملاً من الأعلى (لا تنفّذ TRUNCATE منفرداً). نسخة احتياطية أولاً.

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE `product_variants`
SET `size_family_size_id` = NULL
WHERE `size_family_size_id` IS NOT NULL;

UPDATE `products`
SET `size_family_id` = NULL
WHERE `size_family_id` IS NOT NULL;

DELETE FROM `size_family_sizes`;
DELETE FROM `size_families`;

ALTER TABLE `size_families` AUTO_INCREMENT = 1;
ALTER TABLE `size_family_sizes` AUTO_INCREMENT = 1;

COMMIT;
