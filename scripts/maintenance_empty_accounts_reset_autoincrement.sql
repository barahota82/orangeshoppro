-- -----------------------------------------------------------------------------
-- إفراغ دليل accounts (post-v52: لدولة واحدة | legacy: كامل الجدول)
-- ⚠ نسخة احتياطية إلزامية. يدمّر اتساق القيود المحاسبية للنطاق المختار.
-- -----------------------------------------------------------------------------
-- قبل التشغيل (post-v52 — إلزامي):
--   SET @country_id := (SELECT id FROM countries WHERE code = 'KW' LIMIT 1);
-- legacy (جدول accounts بلا country_id): اترك @country_id = NULL
-- -----------------------------------------------------------------------------

SET @country_id := NULL;

SET @has_acct_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'country_id'
);
SET @has_jv_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'journal_vouchers'
      AND COLUMN_NAME = 'country_id'
);
SET @has_gl_set_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orange_gl_account_settings'
      AND COLUMN_NAME = 'country_id'
);
SET @has_gl_alloc_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orange_gl_setting_alloc'
      AND COLUMN_NAME = 'country_id'
);
SET @has_jt_rules_country := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orange_gl_journal_type_rules'
      AND COLUMN_NAME = 'country_id'
);

SET @cid := IF(@country_id IS NULL, 0, CAST(@country_id AS UNSIGNED));

-- post-v52: لا يُنفَّذ حذف accounts/journal بدون @country_id (>0)
SET @sql_guard := IF(
    @has_acct_country > 0 AND @cid = 0,
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''post-v52: SET @country_id before running (e.g. KW)''',
    'SELECT 1'
);
PREPARE p_guard FROM @sql_guard;
EXECUTE p_guard;
DEALLOCATE PREPARE p_guard;

START TRANSACTION;

SET FOREIGN_KEY_CHECKS = 0;

SET @sql_psa := IF(
    @has_jv_country > 0 AND @cid > 0,
    CONCAT(
        'DELETE psa FROM party_subledger_allocations psa ',
        'INNER JOIN party_subledger ps ON ps.id = psa.party_subledger_id ',
        'INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id ',
        'WHERE jv.country_id = ', @cid
    ),
    IF(@has_jv_country > 0, 'SELECT 1', 'DELETE FROM party_subledger_allocations')
);
PREPARE p_psa FROM @sql_psa;
EXECUTE p_psa;
DEALLOCATE PREPARE p_psa;

SET @sql_ps := IF(
    @has_jv_country > 0 AND @cid > 0,
    CONCAT(
        'DELETE ps FROM party_subledger ps ',
        'INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id ',
        'WHERE jv.country_id = ', @cid
    ),
    IF(@has_jv_country > 0, 'SELECT 1', 'DELETE FROM party_subledger')
);
PREPARE p_ps FROM @sql_ps;
EXECUTE p_ps;
DEALLOCATE PREPARE p_ps;

SET @sql_jl := IF(
    @has_jv_country > 0 AND @cid > 0,
    CONCAT(
        'DELETE jl FROM journal_lines jl ',
        'INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id ',
        'WHERE jv.country_id = ', @cid
    ),
    IF(@has_jv_country > 0, 'SELECT 1', 'DELETE FROM journal_lines')
);
PREPARE p_jl FROM @sql_jl;
EXECUTE p_jl;
DEALLOCATE PREPARE p_jl;

SET @sql_jv := IF(
    @has_jv_country > 0 AND @cid > 0,
    CONCAT('DELETE FROM journal_vouchers WHERE country_id = ', @cid),
    IF(@has_jv_country > 0, 'SELECT 1', 'DELETE FROM journal_vouchers')
);
PREPARE p_jv FROM @sql_jv;
EXECUTE p_jv;
DEALLOCATE PREPARE p_jv;

SET @sql_og := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT(
        'DELETE og FROM orange_gl_pending_movements og ',
        'LEFT JOIN accounts ad ON ad.id = og.account_debit AND ad.country_id = ', @cid, ' ',
        'LEFT JOIN accounts ac ON ac.id = og.account_credit AND ac.country_id = ', @cid, ' ',
        'WHERE (og.account_debit IS NOT NULL AND ad.id IS NOT NULL) ',
        '   OR (og.account_credit IS NOT NULL AND ac.id IS NOT NULL)'
    ),
    IF(@has_acct_country > 0, 'SELECT 1', 'DELETE FROM orange_gl_pending_movements')
);
PREPARE p_og FROM @sql_og;
EXECUTE p_og;
DEALLOCATE PREPARE p_og;

SET @sql_gls := IF(
    @has_gl_set_country > 0 AND @cid > 0,
    CONCAT('DELETE FROM orange_gl_account_settings WHERE country_id = ', @cid),
    IF(@has_gl_set_country > 0, 'SELECT 1', 'DELETE FROM orange_gl_account_settings')
);
PREPARE p_gls FROM @sql_gls;
EXECUTE p_gls;
DEALLOCATE PREPARE p_gls;

SET @sql_gla := IF(
    @has_gl_alloc_country > 0 AND @cid > 0,
    CONCAT('DELETE FROM orange_gl_setting_alloc WHERE country_id = ', @cid),
    IF(@has_gl_alloc_country > 0, 'SELECT 1', 'DELETE FROM orange_gl_setting_alloc')
);
PREPARE p_gla FROM @sql_gla;
EXECUTE p_gla;
DEALLOCATE PREPARE p_gla;

SET @sql_jtr := IF(
    @has_jt_rules_country > 0 AND @cid > 0,
    CONCAT('DELETE FROM orange_gl_journal_type_rules WHERE country_id = ', @cid),
    IF(@has_jt_rules_country > 0, 'SELECT 1', 'DELETE FROM orange_gl_journal_type_rules')
);
PREPARE p_jtr FROM @sql_jtr;
EXECUTE p_jtr;
DEALLOCATE PREPARE p_jtr;

SET @sql_exp := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT(
        'UPDATE expenses e ',
        'INNER JOIN accounts a ON a.id = e.expense_account_id AND a.country_id = ', @cid, ' ',
        'SET e.expense_account_id = NULL'
    ),
    IF(@has_acct_country > 0, 'SELECT 1', 'UPDATE expenses SET expense_account_id = NULL WHERE expense_account_id IS NOT NULL')
);
PREPARE p_exp FROM @sql_exp;
EXECUTE p_exp;
DEALLOCATE PREPARE p_exp;

SET @sql_acct := IF(
    @has_acct_country > 0 AND @cid > 0,
    CONCAT('DELETE FROM accounts WHERE country_id = ', @cid),
    IF(@has_acct_country > 0, 'SELECT 1', 'DELETE FROM accounts')
);
PREPARE p_acct FROM @sql_acct;
EXECUTE p_acct;
DEALLOCATE PREPARE p_acct;

-- AUTO_INCREMENT: يُصفَّر فقط عند فراغ الجدول بالكامل (multi-country)
SET @acct_table_total := (SELECT COUNT(*) FROM accounts);
SET @alter_ai := IF(
    @acct_table_total = 0,
    'ALTER TABLE accounts AUTO_INCREMENT = 1',
    'SELECT 1'
);
PREPARE p_ai FROM @alter_ai;
EXECUTE p_ai;
DEALLOCATE PREPARE p_ai;

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- post-v52: يُحذف نطاق @country_id فقط؛ دول أخرى لا تُمس.
-- بعد scoped delete: أعد بذر الدليل للدولة عبر country_provision أو seed.
