-- Orange — حذف عائلة مقاسات id=103 (مثال: بدون قالب) ثم ضبط AUTO_INCREMENT ليعاد استخدام التسلسل التالي = أعلى id + 1
-- نفّذ بعد نسخة احتياطية على القاعدة الفعلية (مثلاً orange_db).
-- إن كان 103 هو أعلى id قبل الحذف، يصبح AUTO_INCREMENT = 103 والإدراج التالي يأخذ id 103.

START TRANSACTION;

DELETE FROM size_family_sizes WHERE size_family_id = 103;

SET @has_pf := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'size_family_id'
);
SET @sql_pf := IF(
    @has_pf > 0,
    'UPDATE products SET size_family_id = NULL WHERE size_family_id = 103',
    'SELECT 1'
);
PREPARE p_pf FROM @sql_pf;
EXECUTE p_pf;
DEALLOCATE PREPARE p_pf;

DELETE FROM size_families WHERE id = 103;

SET @next_ai := (SELECT IFNULL(MAX(id), 0) + 1 FROM size_families);
SET @alter_ai := CONCAT('ALTER TABLE size_families AUTO_INCREMENT = ', @next_ai);
PREPARE p_ai FROM @alter_ai;
EXECUTE p_ai;
DEALLOCATE PREPARE p_ai;

COMMIT;
