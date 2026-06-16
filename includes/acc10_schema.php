<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * GAP-ACC-10 — schema (phase 0): dimensions, bank/inventory reconciliation, journal_lines.dimension_value_id.
 */
function orange_catalog_ensure_acc10_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!orange_table_exists($pdo, 'analytical_dimension')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE analytical_dimension (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(32) NOT NULL,
                label_ar VARCHAR(128) NOT NULL,
                label_en VARCHAR(128) NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_analytical_dimension_code_country (code, country_id),
                KEY idx_analytical_dimension_active (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'analytical_dimension_value')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE analytical_dimension_value (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                dimension_id INT UNSIGNED NOT NULL,
                code VARCHAR(32) NOT NULL,
                label_ar VARCHAR(128) NOT NULL,
                label_en VARCHAR(128) NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_analytical_dimension_value_dim_code (dimension_id, code),
                KEY idx_analytical_dimension_value_dim (dimension_id, is_active, sort_order),
                CONSTRAINT orange_fk_adv_dimension FOREIGN KEY (dimension_id)
                    REFERENCES analytical_dimension (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'bank_reconciliation')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE bank_reconciliation (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id INT NOT NULL,
                fiscal_year_id INT UNSIGNED NULL DEFAULT NULL,
                period_from DATE NULL DEFAULT NULL,
                period_to DATE NULL DEFAULT NULL,
                gl_balance DECIMAL(18,4) NOT NULL DEFAULT 0,
                statement_balance DECIMAL(18,4) NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                notes VARCHAR(512) NULL DEFAULT NULL,
                journal_voucher_id INT NULL DEFAULT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_bank_recon_account (account_id, status),
                KEY idx_bank_recon_fy (fiscal_year_id),
                KEY idx_bank_recon_country (country_id),
                CONSTRAINT orange_fk_bank_recon_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'bank_reconciliation_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE bank_reconciliation_line (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reconciliation_id INT UNSIGNED NOT NULL,
                line_date DATE NULL DEFAULT NULL,
                description VARCHAR(255) NULL DEFAULT NULL,
                amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                source VARCHAR(16) NOT NULL DEFAULT \'manual\',
                PRIMARY KEY (id),
                KEY idx_bank_recon_line_parent (reconciliation_id, sort_order),
                CONSTRAINT orange_fk_bank_recon_line_parent FOREIGN KEY (reconciliation_id)
                    REFERENCES bank_reconciliation (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'inventory_reconciliation')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE inventory_reconciliation (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                warehouse_id INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                counted_at DATE NULL DEFAULT NULL,
                notes VARCHAR(512) NULL DEFAULT NULL,
                journal_voucher_id INT NULL DEFAULT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                approved_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_inv_recon_wh (warehouse_id, status),
                KEY idx_inv_recon_country (country_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'inventory_reconciliation')
        && orange_table_exists($pdo, 'warehouses')
        && !orange_schema_fk_exists($pdo, 'inventory_reconciliation', 'orange_fk_inv_recon_warehouse')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE inventory_reconciliation
                ADD CONSTRAINT orange_fk_inv_recon_warehouse FOREIGN KEY (warehouse_id)
                    REFERENCES warehouses (id)'
        );
    }

    // أرشيف الجرد (قرار المالك 2026-06-16): تخزين مرفقات تقرير الجرد الموقّع (PDF/صور/Office) كقائمة JSON.
    if (orange_table_exists($pdo, 'inventory_reconciliation')
        && !orange_table_has_column($pdo, 'inventory_reconciliation', 'attachments_json')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE inventory_reconciliation ADD COLUMN attachments_json TEXT NULL DEFAULT NULL'
        );
    }
    // نطاق الجرد قد يكون عهدة مندوب (بجانب المخزن) — معرّف اختياري للمندوب.
    if (orange_table_exists($pdo, 'inventory_reconciliation')
        && !orange_table_has_column($pdo, 'inventory_reconciliation', 'delivery_agent_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE inventory_reconciliation ADD COLUMN delivery_agent_id INT UNSIGNED NULL DEFAULT NULL'
        );
    }

    if (!orange_table_exists($pdo, 'inventory_reconciliation_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE inventory_reconciliation_line (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reconciliation_id INT UNSIGNED NOT NULL,
                variant_id INT UNSIGNED NOT NULL,
                qty_system INT NOT NULL DEFAULT 0,
                qty_counted INT NOT NULL DEFAULT 0,
                qty_variance INT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_inv_recon_line_parent (reconciliation_id),
                KEY idx_inv_recon_line_variant (variant_id),
                CONSTRAINT orange_fk_inv_recon_line_parent FOREIGN KEY (reconciliation_id)
                    REFERENCES inventory_reconciliation (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'inventory_reconciliation_line')
        && orange_table_exists($pdo, 'product_variants')
        && !orange_schema_fk_exists($pdo, 'inventory_reconciliation_line', 'orange_fk_inv_recon_line_variant')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE inventory_reconciliation_line
                ADD CONSTRAINT orange_fk_inv_recon_line_variant FOREIGN KEY (variant_id)
                    REFERENCES product_variants (id)'
        );
    }

    if (orange_table_exists($pdo, 'journal_lines')
        && !orange_table_has_column($pdo, 'journal_lines', 'dimension_value_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_lines ADD COLUMN dimension_value_id INT UNSIGNED NULL DEFAULT NULL AFTER memo'
        );
        orange_schema_invalidate_column_check('journal_lines', 'dimension_value_id');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_jl_dimension_value ON journal_lines (dimension_value_id)'
        );
    }

    if (orange_table_exists($pdo, 'journal_lines')
        && orange_table_exists($pdo, 'analytical_dimension_value')
        && orange_table_has_column($pdo, 'journal_lines', 'dimension_value_id')
        && !orange_schema_fk_exists($pdo, 'journal_lines', 'orange_fk_jl_dimension_value')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_lines
                ADD CONSTRAINT orange_fk_jl_dimension_value FOREIGN KEY (dimension_value_id)
                    REFERENCES analytical_dimension_value (id) ON DELETE SET NULL'
        );
    }

    require_once __DIR__ . '/analytical_dimensions.php';
    orange_analytical_dimension_seed_v1($pdo);

    $done = true;
}

/**
 * Best-effort FK existence check (MySQL / MariaDB).
 */
function orange_schema_fk_exists(PDO $pdo, string $table, string $constraintName): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
        );
        $st->execute([$table, $constraintName]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
