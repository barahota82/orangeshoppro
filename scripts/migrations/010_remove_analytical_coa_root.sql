-- إزالة جذر «الحسابات التحليلية» القديم (كود 7) إن وُجد بدون أبناء — per country بعد v52.
-- لا يحذف جذر «حسابات نظامية (خارج الميزانية)» الحالي.
-- إن وُجدت حسابات فرعية تحت هذا الجذر فلن يُحذف الصف؛ انقلها أو احذفها يدوياً أولاً.
--
-- post-v52 (اختياري — دولة واحدة):
--   SET @country_id := (SELECT id FROM countries WHERE code = 'KW' LIMIT 1);
-- بدون @country_id: يُطبَّق على كل الدول ذات التسمية القديمة.

SET @country_id := NULL;

SET @has_acct_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'country_id'
);

SET @cid := IF(@country_id IS NULL, 0, CAST(@country_id AS UNSIGNED));

SET @child_scope := IF(
    @has_acct_country > 0,
    ' AND c.country_id = a.country_id',
    ''
);

SET @country_scope := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT(' AND a.country_id = ', @cid),
    ''
);

SET @sql_del := CONCAT(
    'DELETE a FROM accounts a ',
    'WHERE TRIM(a.code) = ''7'' ',
    'AND (a.parent_id IS NULL OR a.parent_id = 0) ',
    'AND (a.name = ''الحسابات التحليلية'' OR a.name_en = ''Analytical accounts'') ',
    'AND NOT EXISTS (SELECT 1 FROM accounts AS c WHERE c.parent_id = a.id', @child_scope, ')',
    @country_scope
);

PREPARE p_del FROM @sql_del;
EXECUTE p_del;
DEALLOCATE PREPARE p_del;
