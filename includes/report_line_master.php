<?php

declare(strict_types=1);

if (!function_exists('orange_catalog_safe_exec')) {
    require_once __DIR__ . '/catalog_schema.php';
}

/**
 * المرجع الثابت لأسطر التقارير (report_line) — لا يُدخل نصًا حرًا في الحساب.
 */

function orange_report_line_master_ensure_table(PDO $pdo): void
{
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS report_line_master (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            account_type VARCHAR(32) NULL DEFAULT NULL,
            report_section VARCHAR(32) NULL DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uk_report_line_master_code (code),
            KEY idx_report_line_master_section (report_section),
            KEY idx_report_line_master_acc_type (account_type),
            KEY idx_report_line_master_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * قائمة المرجعية النشطة (لقوائم الاختيار في الأدمن).
 *
 * @return list<array{id:int,code:string,label_ar:string,label_en:string,account_type:?string,report_section:?string,sort_order:int}>
 */
function orange_report_line_master_list_active(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'report_line_master')) {
        return [];
    }

    $st = $pdo->query(
        'SELECT id, code, label_ar, label_en, account_type, report_section, sort_order
         FROM report_line_master WHERE is_active = 1
         ORDER BY sort_order ASC, code ASC'
    );

    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

    return is_array($rows) ? $rows : [];
}

/**
 * @param list<string> $codes
 * @return array<string, int> code => id
 */
function orange_report_line_master_ids_by_codes(PDO $pdo, array $codes): array
{
    if ($codes === [] || !orange_table_exists($pdo, 'report_line_master')) {
        return [];
    }
    $norm = [];
    foreach ($codes as $c) {
        $k = strtolower(trim((string) $c));
        if ($k !== '') {
            $norm[$k] = true;
        }
    }
    if ($norm === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($norm), '?'));
    $st = $pdo->prepare(
        'SELECT id, code FROM report_line_master WHERE LOWER(TRIM(code)) IN (' . $ph . ')'
    );
    $st->execute(array_keys($norm));
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $map[strtolower(trim((string) ($row['code'] ?? '')))] = (int) ($row['id'] ?? 0);
    }

    return array_filter($map, static fn (int $id): bool => $id > 0);
}

/**
 * بحث المعرف بحسب الرمز الموحّد (صغير بحروف).
 */
function orange_report_line_id_for_code(PDO $pdo, string $code): ?int
{
    $code = strtolower(trim($code));
    if ($code === '' || !orange_table_exists($pdo, 'report_line_master')) {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM report_line_master WHERE LOWER(TRIM(code)) = ? LIMIT 1');
    $st->execute([$code]);
    $id = (int) $st->fetchColumn();

    return $id > 0 ? $id : null;
}

/**
 * التحقق من أن المعرف موجود ونشط.
 */
function orange_report_line_validate_id(PDO $pdo, int $id): bool
{
    if ($id <= 0 || !orange_table_exists($pdo, 'report_line_master')) {
        return false;
    }
    $st = $pdo->prepare('SELECT 1 FROM report_line_master WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$id]);

    return (bool) $st->fetchColumn();
}

function orange_report_line_master_seed_defaults(PDO $pdo): void
{
    orange_report_line_master_ensure_table($pdo);
    $rows = [
        ['asset', 'أصول ثابتة ومتداولة — إجمالي', 'Assets (total)', 'asset', 'balance_sheet', 10],
        ['liability', 'خصوم', 'Liabilities', 'liability', 'balance_sheet', 20],
        ['equity', 'حقوق ملكية', 'Equity', 'equity', 'balance_sheet', 30],
        ['cash_and_equivalents', 'النقد وما في حكمه', 'Cash and equivalents', 'asset', 'balance_sheet', 40],
        ['inventory', 'المخزون', 'Inventory', 'asset', 'balance_sheet', 50],
        ['revenue', 'إيرادات', 'Revenue', 'revenue', 'trading', 100],
        ['sales', 'المبيعات', 'Sales', 'revenue', 'trading', 101],
        ['sales_returns', 'مرتجعات مبيعات', 'Sales returns', 'revenue', 'trading', 102],
        ['sales_discounts', 'خصومات مبيعات', 'Sales discounts', 'revenue', 'trading', 103],
        ['cogs', 'تكلفة المبيعات', 'Cost of goods sold', 'cogs', 'trading', 110],
        ['cogs_goods', 'تكلفة البضاعة المباعة', 'COGS goods', 'cogs', 'trading', 111],
        ['cogs_returns', 'تكلفة مرتجعات', 'COGS returns', 'cogs', 'trading', 112],
        ['purchase_expenses', 'مصاريف توريد وشحن (ضمن تكلفة المبيعات)', 'Landed cost & purchase charges (COGS)', 'cogs', 'trading', 115],
        ['operating_expenses', 'مصاريف تشغيلية', 'Operating expenses', 'expense', 'pnl', 210],
        ['finance_expenses', 'مصاريف تمويلية', 'Finance expenses', 'expense', 'pnl', 220],
        ['depreciation', 'استهلاك', 'Depreciation', 'expense', 'pnl', 230],
        ['expense', 'مصروفات عمومية (افتراضي)', 'Expense (default)', 'expense', 'pnl', 240],
        ['other_pl', 'أخرى (قائمة الدخل)', 'Other P&L', 'expense', 'pnl', 899],
        ['other', 'أخرى / غير مصنّف', 'Other / unclassified', null, null, 900],
    ];

    $ins = $pdo->prepare(
        'INSERT INTO report_line_master (code, label_ar, label_en, account_type, report_section, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            label_ar = VALUES(label_ar),
            label_en = VALUES(label_en),
            account_type = VALUES(account_type),
            report_section = VALUES(report_section),
            sort_order = VALUES(sort_order)'
    );
    foreach ($rows as $r) {
        try {
            $ins->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] report_line_master seed row ' . ($r[0] ?? '?') . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * بعد إضافة عمود report_line_id وترحيل البيانات من report_line القديم إلى معرف مرجعي.
 */
function orange_report_line_migrate_legacy_text_column(PDO $pdo): void
{
    if (
        !orange_table_exists($pdo, 'accounts')
        || !orange_table_has_column($pdo, 'accounts', 'report_line_id')
    ) {
        return;
    }

    orange_report_line_master_seed_defaults($pdo);
    orange_schema_invalidate_column_check('accounts', 'report_line');

    if (orange_table_has_column($pdo, 'accounts', 'report_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE accounts a
             INNER JOIN report_line_master r ON LOWER(TRIM(a.report_line)) = LOWER(TRIM(r.code))
             SET a.report_line_id = r.id
             WHERE a.report_line IS NOT NULL AND TRIM(COALESCE(a.report_line,\'\')) <> \'\'
               AND (a.report_line_id IS NULL OR a.report_line_id = 0)'
        );
        $otherId = orange_report_line_id_for_code($pdo, 'other');
        if ($otherId !== null) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE accounts SET report_line_id = ' . (int) $otherId . '
                 WHERE report_line IS NOT NULL
                   AND TRIM(COALESCE(report_line,\'\')) <> \'\'
                   AND (report_line_id IS NULL OR report_line_id = 0)'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts DROP COLUMN report_line');
    }

    orange_schema_invalidate_column_check('accounts', 'report_line');
    orange_schema_invalidate_column_check('accounts', 'report_line_id');
}
