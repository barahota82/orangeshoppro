<?php

declare(strict_types=1);

/**
 * FSR Batch D3 — disposable MySQL Accounting/GL fixture helpers (test-only).
 * Extends D2 bootstrap; never touches Production .env.php / Production data.
 */

require_once __DIR__ . '/final_review_d2_fixture.php';

/**
 * Load Accounting/GL production helpers without config.php / .env.php.
 */
function orange_d3_load_production_helpers(string $projectRoot): void
{
    orange_d2_load_production_helpers($projectRoot);
    require_once $projectRoot . '/includes/currency.php';
    require_once $projectRoot . '/includes/account_tree.php';
    require_once $projectRoot . '/includes/gl_settings.php';
    require_once $projectRoot . '/includes/gl_posting_time.php';
    require_once $projectRoot . '/includes/gl_pending_movements.php';
    require_once $projectRoot . '/includes/gl_voucher_slot.php';
    require_once $projectRoot . '/includes/journal_types.php';
    require_once $projectRoot . '/includes/journal_voucher.php';
    require_once $projectRoot . '/includes/fiscal_years.php';
    require_once $projectRoot . '/includes/edit_lock.php';
    require_once $projectRoot . '/includes/party_subledger.php';
    require_once $projectRoot . '/includes/purchase_gl_accounts.php';
    require_once $projectRoot . '/includes/sales_gl_accounts.php';
    require_once $projectRoot . '/includes/supplier_payable_account.php';
    require_once $projectRoot . '/includes/loyalty.php';
    require_once $projectRoot . '/includes/year_end_close.php';
    require_once $projectRoot . '/includes/bank_reconciliation.php';
    require_once $projectRoot . '/includes/admin_settings_country.php';
    require_once $projectRoot . '/includes/order_item_gl_slot.php';

    if (!isset($GLOBALS['env']) || !is_array($GLOBALS['env'])) {
        $GLOBALS['env'] = [];
    }
    // Default: immediate posting (Production default when queue flag unset).
    $GLOBALS['env']['ORANGE_GL_USE_PENDING_QUEUE'] = false;
    $GLOBALS['env']['ORANGE_GL_IMMEDIATE_POSTING'] = true;
}

/**
 * @return array{
 *   ok:bool,
 *   pdo?:PDO,
 *   db_name?:string,
 *   cleanup?:callable,
 *   ids?:array<string,int|string>,
 *   schema?:array<string,mixed>,
 *   error?:string,
 *   env?:string
 * }
 */
function orange_d3_bootstrap_isolated_db(string $projectRoot): array
{
    $boot = orange_d2_bootstrap_isolated_db($projectRoot);
    if (empty($boot['ok'])) {
        return $boot;
    }

    /** @var PDO $pdo */
    $pdo = $boot['pdo'];
    /** @var array<string,int|string> $ids */
    $ids = $boot['ids'] ?? [];
    $dbName = (string) ($boot['db_name'] ?? '');

    try {
        orange_d3_load_production_helpers($projectRoot);
        orange_d2_set_admin_country((int) ($ids['kw_country_id'] ?? 1), 'kw');
        $acct = orange_d3_seed_accounting_spine($pdo, $ids);
        $ids = array_merge($ids, $acct);
        orange_d3_verify_accounting_schema($pdo);
        // Re-seal after accounting seed (still Schema 124).
        orange_d2_seal_schema_gate($pdo, $dbName);
        $boot['ids'] = $ids;
        $boot['env'] = 'MYSQL_DISPOSABLE_D3';

        return $boot;
    } catch (Throwable $e) {
        if (isset($boot['cleanup']) && is_callable($boot['cleanup'])) {
            ($boot['cleanup'])();
        }

        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }
}

/**
 * @param array<string,int|string> $ids
 * @return array<string,int|string>
 */
function orange_d3_seed_accounting_spine(PDO $pdo, array $ids): array
{
    $kw = (int) ($ids['kw_country_id'] ?? 1);
    $eg = (int) ($ids['eg_country_id'] ?? 2);

    if (orange_d1_has_column($pdo, 'countries', 'timezone')) {
        $pdo->prepare('UPDATE countries SET timezone = ? WHERE id = ?')->execute(['Asia/Kuwait', $kw]);
        $pdo->prepare('UPDATE countries SET timezone = ? WHERE id = ?')->execute(['Africa/Cairo', $eg]);
    }

    // Fiscal years: open 2026 KW/EG, closed 2025 KW.
    orange_d1_insert_if_table($pdo, 'fiscal_years', [
        [
            'id' => 1,
            'country_id' => $kw,
            'label_ar' => 'KW 2026 مفتوحة',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => 0,
            'closed_at' => null,
        ],
        [
            'id' => 2,
            'country_id' => $kw,
            'label_ar' => 'KW 2025 مغلقة',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_closed' => 1,
            'closed_at' => '2026-01-02 00:00:00',
        ],
        [
            'id' => 3,
            'country_id' => $eg,
            'label_ar' => 'EG 2026 مفتوحة',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_closed' => 0,
            'closed_at' => null,
        ],
    ]);

    $kwAccts = orange_d3_seed_country_coa($pdo, $kw, 1000);
    $egAccts = orange_d3_seed_country_coa($pdo, $eg, 2000);

    // Bind supplier payable leafs.
    if (orange_d1_has_column($pdo, 'suppliers', 'payable_account_id')) {
        $pdo->prepare('UPDATE suppliers SET payable_account_id = ? WHERE id = ?')
            ->execute([(int) $kwAccts['ap'], (int) ($ids['kw_supplier_id'] ?? 200)]);
        $pdo->prepare('UPDATE suppliers SET payable_account_id = ? WHERE id = ?')
            ->execute([(int) $egAccts['ap'], (int) ($ids['eg_supplier_id'] ?? 201)]);
    }

    orange_d3_seed_journal_types_for_country($pdo, $kw);
    orange_d3_seed_journal_types_for_country($pdo, $eg);

    orange_d3_bind_gl_settings($pdo, $kw, $kwAccts);
    orange_d3_bind_gl_settings($pdo, $eg, $egAccts);

    return [
        'kw_fy_open_id' => 1,
        'kw_fy_closed_id' => 2,
        'eg_fy_open_id' => 3,
        'kw_acct_cash' => $kwAccts['cash'],
        'kw_acct_bank' => $kwAccts['bank'],
        'kw_acct_ar' => $kwAccts['ar'],
        'kw_acct_ap' => $kwAccts['ap'],
        'kw_acct_inventory' => $kwAccts['inventory'],
        'kw_acct_sales_cash' => $kwAccts['sales_cash'],
        'kw_acct_sales_credit' => $kwAccts['sales_credit'],
        'kw_acct_sales_online' => $kwAccts['sales_online'],
        'kw_acct_cogs' => $kwAccts['cogs'],
        'kw_acct_cogs_returns' => $kwAccts['cogs_returns'],
        'kw_acct_sales_ret' => $kwAccts['sales_returns'],
        'kw_acct_delivery_exp' => $kwAccts['delivery_expense'],
        'kw_acct_delivery_pay' => $kwAccts['delivery_payable'],
        'kw_acct_saj_gain' => $kwAccts['saj_gain'],
        'kw_acct_saj_loss' => $kwAccts['saj_loss'],
        'kw_acct_loy_exp' => $kwAccts['loy_exp'],
        'kw_acct_loy_liab' => $kwAccts['loy_liab'],
        'kw_acct_income_summary' => $kwAccts['income_summary'],
        'kw_acct_retained' => $kwAccts['retained'],
        'kw_acct_equity' => $kwAccts['equity'],
        'kw_acct_group' => $kwAccts['group'],
        'kw_acct_suspended' => $kwAccts['suspended'],
        'eg_acct_cash' => $egAccts['cash'],
        'eg_acct_ar' => $egAccts['ar'],
        'eg_acct_inventory' => $egAccts['inventory'],
        'eg_acct_sales_cash' => $egAccts['sales_cash'],
    ];
}

/**
 * Minimal posting COA: one group + leaf accounts for a country.
 *
 * @return array<string,int>
 */
function orange_d3_seed_country_coa(PDO $pdo, int $countryId, int $baseId): array
{
    $groupId = $baseId;
    $midId = $baseId + 1;
    $leaf = static function (int $offset) use ($baseId): int {
        return $baseId + $offset;
    };

    $rows = [
        [
            'id' => $groupId,
            'country_id' => $countryId,
            'name' => 'D3 Root ' . $countryId,
            'code' => (string) (20 + $countryId),
            'parent_id' => null,
            'is_group' => 1,
            'name_en' => 'D3 Root',
            'is_suspended' => 0,
            'normal_balance' => null,
            'account_type' => 'asset',
            'report_section' => 'balance_sheet',
            'cashflow_section' => 'none',
        ],
        [
            'id' => $midId,
            'country_id' => $countryId,
            'name' => 'D3 Mid ' . $countryId,
            'code' => (string) (1100 + $countryId),
            'parent_id' => $groupId,
            'is_group' => 1,
            'name_en' => 'D3 Mid',
            'is_suspended' => 0,
            'normal_balance' => null,
            'account_type' => 'asset',
            'report_section' => 'balance_sheet',
            'cashflow_section' => 'none',
        ],
    ];

    $defs = [
        'cash' => [2, 'D3 Cash', 'debit', 'asset'],
        'bank' => [3, 'D3 Bank', 'debit', 'asset'],
        'ar' => [4, 'D3 AR', 'debit', 'asset'],
        'ap' => [5, 'D3 AP', 'credit', 'liability'],
        'inventory' => [6, 'D3 Inventory', 'debit', 'asset'],
        'sales_cash' => [7, 'D3 Sales Cash', 'credit', 'revenue'],
        'sales_credit' => [8, 'D3 Sales Credit', 'credit', 'revenue'],
        'sales_online' => [9, 'D3 Sales Online', 'credit', 'revenue'],
        'cogs' => [10, 'D3 COGS', 'debit', 'cogs'],
        'cogs_returns' => [11, 'D3 COGS Ret', 'credit', 'cogs'],
        'sales_returns' => [12, 'D3 Sales Ret', 'debit', 'revenue'],
        'delivery_expense' => [13, 'D3 Del Exp', 'debit', 'expense'],
        'delivery_payable' => [14, 'D3 Del Pay', 'credit', 'liability'],
        'saj_gain' => [15, 'D3 SAJ Gain', 'credit', 'revenue'],
        'saj_loss' => [16, 'D3 SAJ Loss', 'debit', 'expense'],
        'loy_exp' => [17, 'D3 Loy Exp', 'debit', 'expense'],
        'loy_liab' => [18, 'D3 Loy Liab', 'credit', 'liability'],
        'income_summary' => [19, 'D3 Income Sum', 'credit', 'equity'],
        'retained' => [20, 'D3 Retained', 'credit', 'equity'],
        'equity' => [21, 'D3 Equity OB', 'credit', 'equity'],
        'purchase_discount' => [22, 'D3 Purch Disc', 'credit', 'revenue'],
        'suspended' => [23, 'D3 Suspended', 'debit', 'asset'],
    ];

    $map = ['group' => $groupId, 'mid' => $midId];
    foreach ($defs as $key => [$off, $name, $nb, $atype]) {
        $id = $leaf($off);
        $map[$key] = $id;
        $rows[] = [
            'id' => $id,
            'country_id' => $countryId,
            'name' => $name,
            'code' => (string) (11010100000 + $countryId * 1000 + $off),
            'parent_id' => $midId,
            'is_group' => 0,
            'name_en' => $name,
            'is_suspended' => $key === 'suspended' ? 1 : 0,
            'normal_balance' => $nb,
            'account_type' => $atype,
            'report_section' => in_array($atype, ['revenue', 'cogs', 'expense'], true) ? 'pnl' : 'balance_sheet',
            'cashflow_section' => 'none',
        ];
    }

    orange_d1_insert_if_table($pdo, 'accounts', $rows);

    return $map;
}

function orange_d3_seed_journal_types_for_country(PDO $pdo, int $countryId): void
{
    if (!orange_table_exists($pdo, 'journal_types') || $countryId <= 0) {
        return;
    }
    $rows = orange_journal_types_canonical_rows();
    $hasCountry = orange_journal_types_has_country_column($pdo);
    foreach ($rows as $r) {
        $code = (string) ($r['code'] ?? '');
        if ($code === '') {
            continue;
        }
        if ($hasCountry) {
            $st = $pdo->prepare('SELECT id FROM journal_types WHERE country_id = ? AND code = ? LIMIT 1');
            $st->execute([$countryId, $code]);
            if ((int) $st->fetchColumn() > 0) {
                continue;
            }
            $row = [
                'country_id' => $countryId,
                'code' => $code,
                'name_ar' => (string) ($r['name_ar'] ?? $code),
                'name_en' => (string) ($r['name_en'] ?? $code),
                'sort_order' => (int) ($r['sort_order'] ?? 0),
            ];
            if (orange_d1_has_column($pdo, 'journal_types', 'is_active')) {
                $row['is_active'] = 1;
            }
            if (orange_d1_has_column($pdo, 'journal_types', 'name_fil')) {
                $row['name_fil'] = (string) ($r['name_fil'] ?? '');
            }
            if (orange_d1_has_column($pdo, 'journal_types', 'name_hi')) {
                $row['name_hi'] = (string) ($r['name_hi'] ?? '');
            }
            orange_d1_insert_if_table($pdo, 'journal_types', [$row]);
        }
    }
}

/**
 * @param array<string,int> $accts
 */
function orange_d3_bind_gl_settings(PDO $pdo, int $countryId, array $accts): void
{
    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        return;
    }
    $map = [
        'cash' => $accts['cash'],
        'inventory' => $accts['inventory'],
        'accounts_payable' => $accts['ap'],
        'sales_revenue_cash' => $accts['sales_cash'],
        'sales_revenue_credit' => $accts['sales_credit'],
        'sales_revenue_online' => $accts['sales_online'],
        'ar_cash' => $accts['ar'],
        'ar_credit' => $accts['ar'],
        'sales_returns_cash' => $accts['sales_returns'],
        'sales_returns_credit' => $accts['sales_returns'],
        'sales_returns_online' => $accts['sales_returns'],
        'cogs' => $accts['cogs'],
        'cogs_returns' => $accts['cogs_returns'],
        'purchase_discount' => $accts['purchase_discount'],
        'delivery_expense' => $accts['delivery_expense'],
        'delivery_payable_default' => $accts['delivery_payable'],
        'loyalty_program_expense' => $accts['loy_exp'],
        'loyalty_points_liability' => $accts['loy_liab'],
        'stock_adjustment_gain' => $accts['saj_gain'],
        'stock_adjustment_loss' => $accts['saj_loss'],
        'income_summary' => $accts['income_summary'],
        'retained_earnings' => $accts['retained'],
    ];
    foreach ($map as $key => $aid) {
        if ($aid <= 0) {
            continue;
        }
        $del = $pdo->prepare(
            'DELETE FROM orange_gl_account_settings WHERE setting_key = ? AND country_id = ?'
        );
        $del->execute([$key, $countryId]);
        if (orange_d1_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')) {
            $pdo->prepare(
                'INSERT INTO orange_gl_account_settings (setting_key, country_id, account_id, journal_type_id)
                 VALUES (?,?,?,NULL)'
            )->execute([$key, $countryId, $aid]);
        } else {
            $pdo->prepare(
                'INSERT INTO orange_gl_account_settings (setting_key, country_id, account_id)
                 VALUES (?,?,?)'
            )->execute([$key, $countryId, $aid]);
        }
    }
}

function orange_d3_verify_accounting_schema(PDO $pdo): void
{
    $required = [
        'accounts',
        'journal_types',
        'journal_vouchers',
        'journal_lines',
        'fiscal_years',
        'orange_gl_account_settings',
        'orange_gl_pending_movements',
        'orange_gl_voucher_slots',
        'party_subledger',
        'orange_edit_lock_registry',
    ];
    foreach ($required as $t) {
        if (!orange_table_exists($pdo, $t)) {
            throw new RuntimeException('D3 schema missing table: ' . $t);
        }
    }
    if ((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION !== 124) {
        throw new RuntimeException('Schema revision must remain 124');
    }
}

/**
 * @param list<array{account_id:int,debit:float,credit:float,memo?:string}> $lines
 */
function orange_d3_post_manual(
    PDO $pdo,
    int $countryId,
    string $description,
    array $lines,
    string $entryType = 'manual',
    string $voucherDateYmd = '2026-07-15',
    ?int $journalTypeId = null
): int {
    $times = orange_gl_posting_times_for_country($pdo, $countryId, $voucherDateYmd);
    $header = [
        'voucher_date' => $times['voucher_date'],
        'document_entered_at' => $times['document_entered_at'],
        'description' => $description,
        'entry_type' => $entryType,
        'country_id' => $countryId,
    ];
    if ($journalTypeId !== null && $journalTypeId > 0) {
        $header['journal_type_id'] = $journalTypeId;
    }

    return orange_voucher_post($pdo, $header, $lines);
}

function orange_d3_voucher_line_totals(PDO $pdo, int $voucherId): array
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c, COUNT(*) AS n
         FROM journal_lines WHERE voucher_id = ?'
    );
    $st->execute([$voucherId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'debit' => (float) ($row['d'] ?? 0),
        'credit' => (float) ($row['c'] ?? 0),
        'lines' => (int) ($row['n'] ?? 0),
    ];
}

function orange_d3_count_vouchers_by_entry(PDO $pdo, string $entryType, int $countryId): int
{
    if (orange_d1_has_column($pdo, 'journal_vouchers', 'country_id')) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = ? AND country_id = ? AND COALESCE(is_void,0) = 0'
        );
        $st->execute([$entryType, $countryId]);
    } else {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM journal_vouchers WHERE entry_type = ? AND COALESCE(is_void,0) = 0'
        );
        $st->execute([$entryType]);
    }

    return (int) $st->fetchColumn();
}

function orange_d3_enable_pending_queue(bool $on): void
{
    if (!isset($GLOBALS['env']) || !is_array($GLOBALS['env'])) {
        $GLOBALS['env'] = [];
    }
    if ($on) {
        $GLOBALS['env']['ORANGE_GL_USE_PENDING_QUEUE'] = true;
        $GLOBALS['env']['ORANGE_GL_IMMEDIATE_POSTING'] = false;
    } else {
        $GLOBALS['env']['ORANGE_GL_USE_PENDING_QUEUE'] = false;
        $GLOBALS['env']['ORANGE_GL_IMMEDIATE_POSTING'] = true;
    }
}

function orange_d3_php_bin(): string
{
    return orange_d2_php_bin();
}
