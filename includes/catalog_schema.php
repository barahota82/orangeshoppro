<?php

declare(strict_types=1);

/**
 * Ensures catalog tables and columns for colors, size families, colorways, and variant FKs exist.
 * Safe to call multiple times per request (uses static guard).
 */
function orange_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$table] = $exists;

    return $exists;
}

function orange_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if ($exists) {
        $cache[$k] = true;
    }
    return $exists;
}

function orange_catalog_safe_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_schema: ' . $e->getMessage());
        }
    }
}

function orange_catalog_ensure_schema(PDO $pdo): void
{
    // Per-connection charset (avoids editing config.php; some hosts break PDO::MYSQL_ATTR_INIT_COMMAND).
    static $charsetApplied = false;
    if (!$charsetApplied) {
        orange_catalog_safe_exec($pdo, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $charsetApplied = true;
    }

    static $done = false;
    if ($done) {
        return;
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS color_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            hex_code VARCHAR(16) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_family_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_family_id INT NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_size_family_sizes_family (size_family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS product_colorways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            primary_color_id INT NULL,
            secondary_color_id INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_colorways_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!orange_table_has_column($pdo, 'products', 'size_family_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN size_family_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'sizing_guide_scope')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE products ADD COLUMN sizing_guide_scope VARCHAR(16) NOT NULL DEFAULT 'none'"
        );
    }
    if (!orange_table_has_column($pdo, 'product_variants', 'product_colorway_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN product_colorway_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'product_variants', 'size_family_size_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN size_family_size_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'order_items', 'variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE order_items ADD COLUMN variant_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'orders', 'order_source')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN order_source VARCHAR(32) NOT NULL DEFAULT 'website'"
        );
    }
    if (!orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN payment_terms VARCHAR(16) NOT NULL DEFAULT 'cash'"
        );
    }
    if (!orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes ADD COLUMN foot_length_cm DECIMAL(6,2) NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'name_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_en VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (!orange_table_has_column($pdo, 'products', 'name_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_fil VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (!orange_table_has_column($pdo, 'products', 'name_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_hi VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (!orange_table_has_column($pdo, 'products', 'description_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_en TEXT NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'description_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_fil TEXT NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'description_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_hi TEXT NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'sort_order')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
    }
    if (!orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE stock_movements ADD COLUMN reference VARCHAR(100) NULL');
    }

    /*
     |--------------------------------------------------------------------------
     | Departments + categories.department_id
     |--------------------------------------------------------------------------
     | المنتج يبقى مربوطاً بالفئة فقط؛ القسم يُستنتج من categories.department_id.
     */
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_departments_slug (slug),
            KEY idx_departments_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'categories') && !orange_table_has_column($pdo, 'categories', 'department_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE categories ADD COLUMN department_id INT NULL');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE categories ADD INDEX idx_categories_department (department_id)');
    }

    static $productSubOrphansCleaned = false;
    if (
        !$productSubOrphansCleaned
        && orange_table_exists($pdo, 'subcategories')
        && orange_table_has_column($pdo, 'products', 'subcategory_id')
    ) {
        $productSubOrphansCleaned = true;
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE products p
             LEFT JOIN subcategories s ON s.id = p.subcategory_id
             SET p.subcategory_id = NULL
             WHERE p.subcategory_id IS NOT NULL AND s.id IS NULL'
        );
    }

    /*
     |--------------------------------------------------------------------------
     | كود الحساب في الشجرة + ربط الحسابات الأساسية للقيود التلقائية
     |--------------------------------------------------------------------------
     */
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts ADD COLUMN code VARCHAR(64) NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_accounts_code ON accounts (code)');
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_gl_account_settings (
                setting_key VARCHAR(64) NOT NULL,
                account_id INT NOT NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE current_timestamp(),
                PRIMARY KEY (setting_key),
                KEY idx_gl_set_account (account_id),
                CONSTRAINT orange_fk_gl_setting_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    /*
     |--------------------------------------------------------------------------
     | السنوات المالية — إغلاق سنة / فتح سنة جديدة / ربط القيود
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE fiscal_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label_ar VARCHAR(160) NOT NULL DEFAULT \'\',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                closed_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fiscal_years_range (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'journal_entries') && !orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE journal_entries ADD COLUMN fiscal_year_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_journal_entries_fiscal_year ON journal_entries (fiscal_year_id)');
    }

    static $fiscalYearsSeeded = false;
    if (!$fiscalYearsSeeded && orange_table_exists($pdo, 'fiscal_years')) {
        $fiscalYearsSeeded = true;
        try {
            $cnt = (int) $pdo->query('SELECT COUNT(*) FROM fiscal_years')->fetchColumn();
            if ($cnt === 0) {
                $y = (int) date('Y');
                $ins = $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, 0)');
                $ins->execute(['سنة مالية ' . $y, sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)]);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] fiscal_years seed: ' . $e->getMessage());
            }
        }
    }

    static $fiscalYearBackfillDone = false;
    if (
        !$fiscalYearBackfillDone
        && orange_table_exists($pdo, 'journal_entries')
        && orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')
        && orange_table_exists($pdo, 'fiscal_years')
    ) {
        $fiscalYearBackfillDone = true;
        try {
            $nulls = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id IS NULL')->fetchColumn();
            if ($nulls > 0) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE journal_entries je
                     INNER JOIN fiscal_years fy ON DATE(je.date) BETWEEN fy.start_date AND fy.end_date
                     SET je.fiscal_year_id = fy.id
                     WHERE je.fiscal_year_id IS NULL'
                );
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] fiscal_year backfill: ' . $e->getMessage());
            }
        }
    }

    /*
     |--------------------------------------------------------------------------
     | تصنيف الحسابات + سندات متعددة الأسطر (journal_vouchers / journal_lines)
     |--------------------------------------------------------------------------
     */
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'account_class')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE accounts ADD COLUMN account_class VARCHAR(32) NOT NULL DEFAULT 'unclassified'"
        );
    }

    static $accountClassHeuristicDone = false;
    if (!$accountClassHeuristicDone && orange_table_exists($pdo, 'accounts') && orange_table_has_column($pdo, 'accounts', 'account_class')) {
        $accountClassHeuristicDone = true;
        try {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE accounts SET account_class = 'asset' WHERE name IN ('Cash','Inventory') AND account_class = 'unclassified'"
            );
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE accounts SET account_class = 'liability' WHERE name = 'Accounts Payable' AND account_class = 'unclassified'"
            );
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE accounts SET account_class = 'revenue' WHERE name = 'Sales' AND account_class = 'unclassified'"
            );
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE accounts SET account_class = 'expense' WHERE name IN ('COGS','Expenses') AND account_class = 'unclassified'"
            );
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] account_class heuristic: ' . $e->getMessage());
            }
        }
    }

    if (!orange_table_exists($pdo, 'journal_vouchers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE journal_vouchers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_date DATETIME NOT NULL,
                reference VARCHAR(100) NULL,
                description TEXT NULL,
                entry_type VARCHAR(64) NOT NULL DEFAULT \'general\',
                fiscal_year_id INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_jv_reference (reference),
                INDEX idx_jv_fiscal_year (fiscal_year_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'journal_lines')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE journal_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id INT NOT NULL,
                line_no SMALLINT NOT NULL DEFAULT 0,
                account_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0,
                memo VARCHAR(255) NULL,
                INDEX idx_jl_voucher (voucher_id),
                INDEX idx_jl_account (account_id),
                CONSTRAINT orange_fk_jl_voucher FOREIGN KEY (voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE,
                CONSTRAINT orange_fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    static $journalLegacyMigrated = false;
    if (
        !$journalLegacyMigrated
        && orange_table_exists($pdo, 'journal_entries')
        && orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_exists($pdo, 'journal_lines')
    ) {
        $journalLegacyMigrated = true;
        try {
            $lc = (int) $pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
            $ec = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            if ($lc === 0 && $ec > 0) {
                $hasJeEt = orange_table_has_column($pdo, 'journal_entries', 'entry_type');
                $hasJeFy = orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id');
                $rows = $pdo->query('SELECT * FROM journal_entries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
                $vIns = $pdo->prepare(
                    'INSERT INTO journal_vouchers (voucher_date, reference, description, entry_type, fiscal_year_id)
                     VALUES (?,?,?,?,?)'
                );
                $lIns = $pdo->prepare(
                    'INSERT INTO journal_lines (voucher_id, line_no, account_id, debit, credit, memo) VALUES (?,?,?,?,?,?)'
                );
                $migrated = 0;
                $pdo->beginTransaction();
                try {
                    foreach ($rows as $je) {
                        $d = (string) ($je['date'] ?? date('Y-m-d H:i:s'));
                        $ref = isset($je['reference']) ? (string) $je['reference'] : null;
                        if ($ref === '') {
                            $ref = null;
                        }
                        $desc = (string) ($je['description'] ?? '');
                        $et = ($hasJeEt && isset($je['entry_type'])) ? (string) $je['entry_type'] : 'migrated';
                        if ($et === '') {
                            $et = 'migrated';
                        }
                        $fy = ($hasJeFy && isset($je['fiscal_year_id'])) ? (int) $je['fiscal_year_id'] : null;
                        if ($fy <= 0) {
                            $fy = null;
                        }
                        $amt = (float) ($je['amount'] ?? 0);
                        $ad = (int) ($je['account_debit'] ?? 0);
                        $ac = (int) ($je['account_credit'] ?? 0);
                        if ($ad <= 0 || $ac <= 0 || $amt <= 0) {
                            continue;
                        }
                        $vIns->execute([$d, $ref, $desc, $et, $fy]);
                        $vid = (int) $pdo->lastInsertId();
                        $lIns->execute([$vid, 1, $ad, $amt, 0, null]);
                        $lIns->execute([$vid, 2, $ac, 0, $amt, null]);
                        ++$migrated;
                    }
                    if ($migrated > 0) {
                        $pdo->exec('DELETE FROM journal_entries');
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal legacy migrate: ' . $e->getMessage());
            }
        }
    }

    /*
     |--------------------------------------------------------------------------
     | العملاء + الذمم الفرعية (ذمم مدينة / دائنة لكل طرف)
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'customers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_ar VARCHAR(160) NOT NULL DEFAULT \'\',
                phone VARCHAR(40) NOT NULL DEFAULT \'\',
                notes VARCHAR(255) NULL,
                credit_limit DECIMAL(18,4) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customers_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'credit_limit')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(18,4) NULL DEFAULT NULL');
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'customer_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN customer_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_customer_id ON orders (customer_id)');
    }

    if (!orange_table_exists($pdo, 'party_subledger')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE party_subledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_kind VARCHAR(20) NOT NULL,
                party_id INT NOT NULL,
                voucher_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0,
                ref_type VARCHAR(32) NULL,
                ref_id INT NULL,
                memo VARCHAR(255) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ps_party (party_kind, party_id),
                KEY idx_ps_voucher (voucher_id),
                CONSTRAINT orange_fk_ps_voucher FOREIGN KEY (voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'party_subledger_allocations')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE party_subledger_allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_kind VARCHAR(20) NOT NULL,
                party_id INT NOT NULL,
                payment_voucher_id INT NOT NULL,
                target_ref_type VARCHAR(32) NOT NULL,
                target_ref_id INT NOT NULL,
                amount DECIMAL(18,4) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_psa_party (party_kind, party_id),
                KEY idx_psa_payment (payment_voucher_id),
                KEY idx_psa_target (target_ref_type, target_ref_id),
                CONSTRAINT orange_fk_psa_voucher FOREIGN KEY (payment_voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts ADD COLUMN parent_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_accounts_parent_id ON accounts (parent_id)');
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'is_group')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE accounts ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    if (orange_table_exists($pdo, 'admins') && !orange_table_has_column($pdo, 'admins', 'is_superuser')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE admins ADD COLUMN is_superuser TINYINT(1) NOT NULL DEFAULT 0'
        );
        orange_catalog_safe_exec($pdo, 'UPDATE admins SET is_superuser = 1');
    }

    if (!orange_table_exists($pdo, 'admin_permissions')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE admin_permissions (
                admin_id INT NOT NULL,
                resource_key VARCHAR(80) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (admin_id, resource_key),
                KEY idx_admin_permissions_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'document_sequences')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE document_sequences (
                scope VARCHAR(64) NOT NULL,
                last_value BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'purchase_items') && !orange_table_has_column($pdo, 'purchase_items', 'variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_items ADD COLUMN variant_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_purchase_items_variant ON purchase_items (variant_id)');
    }

    $done = true;
}

/**
 * @param mixed $raw subcategory_id من الطلب (فارغ = NULL)
 * @return array{0: bool, 1: int|null, 2: string} [نجح، القيمة أو null، رسالة خطأ عربية]
 */
function orange_product_resolve_subcategory_id(PDO $pdo, int $categoryId, $raw): array
{
    if ($categoryId <= 0) {
        return [false, null, 'الفئة غير صالحة'];
    }
    $sid = ($raw === null || $raw === '') ? 0 : (int) $raw;
    if ($sid <= 0) {
        return [true, null, ''];
    }
    if (!orange_table_exists($pdo, 'subcategories')) {
        return [false, null, 'جدول الفئات الفرعية غير متوفر'];
    }
    $st = $pdo->prepare('SELECT id FROM subcategories WHERE id = ? AND category_id = ? LIMIT 1');
    $st->execute([$sid, $categoryId]);
    if (!$st->fetch()) {
        return [false, null, 'التصنيف الفرعي غير موجود أو لا يتبع الفئة المختارة'];
    }

    return [true, $sid, ''];
}
