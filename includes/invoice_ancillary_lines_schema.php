<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/acc10_schema.php';

/**
 * GAP-ACC-07 — مرحلة 0: بنود فاتورة إضافية (قائمة محفوظة + أسطر مستند).
 */
function orange_catalog_ensure_invoice_ancillary_lines_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_invoice_line_presets')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_invoice_line_presets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                country_id INT UNSIGNED NOT NULL,
                account_id INT NOT NULL,
                label_ar VARCHAR(128) NOT NULL DEFAULT \'\',
                label_en VARCHAR(128) NOT NULL DEFAULT \'\',
                invoice_context VARCHAR(16) NOT NULL DEFAULT \'both\',
                line_kind VARCHAR(64) NOT NULL,
                default_show_on_print TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_oilp_country_account_ctx (country_id, account_id, invoice_context),
                KEY idx_oilp_lookup (country_id, invoice_context, is_active, sort_order),
                KEY idx_oilp_account (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('orange_invoice_line_presets');
    }

    if (!orange_table_exists($pdo, 'orange_invoice_extra_lines')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_invoice_extra_lines (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                doc_kind VARCHAR(32) NOT NULL,
                doc_id INT UNSIGNED NOT NULL,
                country_id INT UNSIGNED NOT NULL,
                account_id INT NOT NULL,
                line_kind VARCHAR(64) NOT NULL,
                amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                label_ar VARCHAR(128) NOT NULL DEFAULT \'\',
                show_on_print TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                preset_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_oiel_doc (doc_kind, doc_id, sort_order),
                KEY idx_oiel_country (country_id),
                KEY idx_oiel_account (account_id),
                KEY idx_oiel_preset (preset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('orange_invoice_extra_lines');
    }

    if (
        orange_table_exists($pdo, 'orange_invoice_line_presets')
        && orange_table_exists($pdo, 'accounts')
        && !orange_schema_fk_exists($pdo, 'orange_invoice_line_presets', 'orange_fk_oilp_account')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_invoice_line_presets
                ADD CONSTRAINT orange_fk_oilp_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id)'
        );
    }

    if (
        orange_table_exists($pdo, 'orange_invoice_extra_lines')
        && orange_table_exists($pdo, 'accounts')
        && !orange_schema_fk_exists($pdo, 'orange_invoice_extra_lines', 'orange_fk_oiel_account')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_invoice_extra_lines
                ADD CONSTRAINT orange_fk_oiel_account FOREIGN KEY (account_id)
                    REFERENCES accounts (id)'
        );
    }

    if (
        orange_table_exists($pdo, 'orange_invoice_extra_lines')
        && orange_table_exists($pdo, 'orange_invoice_line_presets')
        && !orange_schema_fk_exists($pdo, 'orange_invoice_extra_lines', 'orange_fk_oiel_preset')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_invoice_extra_lines
                ADD CONSTRAINT orange_fk_oiel_preset FOREIGN KEY (preset_id)
                    REFERENCES orange_invoice_line_presets (id) ON DELETE SET NULL'
        );
    }

    $done = true;
}
