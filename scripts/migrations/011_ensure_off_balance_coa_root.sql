-- جذر 7: حسابات نظامية (خارج الميزانية) — per country بعد v52.
-- يحدّث التسمية القديمة «الحسابات التحليلية» ويُدرج الجذر الناقص لكل دولة.
--
-- post-v52 (اختياري — دولة واحدة):
--   SET @country_id := (SELECT id FROM countries WHERE code = 'KW' LIMIT 1);

SET @country_id := NULL;

SET @has_acct_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'country_id'
);

SET @cid := IF(@country_id IS NULL, 0, CAST(@country_id AS UNSIGNED));

SET @upd_scope := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT(' AND country_id = ', @cid),
    ''
);

SET @sql_upd := CONCAT(
    'UPDATE accounts SET ',
    'name = ''حسابات نظامية (خارج الميزانية)'', ',
    'name_en = ''Off-balance sheet accounts'' ',
    'WHERE TRIM(code) = ''7'' ',
    'AND (parent_id IS NULL OR parent_id = 0) ',
    'AND (name = ''الحسابات التحليلية'' OR name_en = ''Analytical accounts'')',
    @upd_scope
);

PREPARE p_upd FROM @sql_upd;
EXECUTE p_upd;
DEALLOCATE PREPARE p_upd;

SET @ins_country_filter := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT(' AND c.id = ', @cid),
    ''
);

SET @sql_ins := IF(
    @has_acct_country > 0,
    CONCAT(
        'INSERT INTO accounts (country_id, name, code, parent_id, is_group, name_en, is_suspended, normal_balance) ',
        'SELECT c.id, ',
        '''حسابات نظامية (خارج الميزانية)'', ',
        '''7'', NULL, 1, ''Off-balance sheet accounts'', 0, NULL ',
        'FROM countries c ',
        'WHERE 1=1', @ins_country_filter, ' ',
        'AND NOT EXISTS ( ',
        '  SELECT 1 FROM accounts a ',
        '  WHERE a.country_id = c.id ',
        '    AND TRIM(a.code) = ''7'' ',
        '    AND (a.parent_id IS NULL OR a.parent_id = 0) ',
        ')'
    ),
    'INSERT INTO accounts (name, code, parent_id, is_group, name_en, is_suspended, normal_balance)
     SELECT ''حسابات نظامية (خارج الميزانية)'', ''7'', NULL, 1, ''Off-balance sheet accounts'', 0, NULL
     FROM (SELECT 1 AS _x) AS _mig
     WHERE NOT EXISTS (
         SELECT 1
         FROM accounts AS a
         WHERE TRIM(a.code) = ''7'' AND (a.parent_id IS NULL OR a.parent_id = 0)
     )'
);

PREPARE p_ins FROM @sql_ins;
EXECUTE p_ins;
DEALLOCATE PREPARE p_ins;
