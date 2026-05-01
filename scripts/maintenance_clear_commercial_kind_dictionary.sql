-- Orange — تفريغ جدول commercial_kind_dictionary (نوع تجاري المستوى 1)
-- sizing_category_dictionary: حذف CASCADE من الأب عند وجود القيد في المخطط (catalog_schema).
-- راجع size_families قبل التنفيذ؛ القيم القديمة لا تُصفّر تلقائياً (انظر الأسفل).
--
-- استخدم: phpMyAdmin → SQL أو: mysql -u USER -p DB_NAME < scripts/maintenance_clear_commercial_kind_dictionary.sql
-- لا تُرفَع كجزء من نشر روتيني إن كان المالك يفضّل D:\orange_sql_updates.sql فقط.

SET NAMES utf8mb4;

-- معاينة (اختياري):
-- SELECT COUNT(*) FROM commercial_kind_dictionary;
-- SELECT COUNT(*) FROM sizing_category_dictionary;
-- SELECT id, name_ar, commercial_kind_key, sizing_category_key FROM size_families
--   WHERE TRIM(COALESCE(commercial_kind_key,'')) <> '' OR TRIM(COALESCE(sizing_category_key,'')) <> '';

DELETE FROM commercial_kind_dictionary;

-- (اختياري) تصفير مفاتيح الهرم على عائلات المقاسات بعد تفريغ القاموس:
-- UPDATE size_families SET commercial_kind_key = '', sizing_category_key = ''
-- WHERE TRIM(COALESCE(commercial_kind_key,'')) <> '' OR TRIM(COALESCE(sizing_category_key,'')) <> '';
