<?php

declare(strict_types=1);

/**
 * أساس الدفع الإلكتروني — المرحلة 0 (طبقة موحّدة، مُعطّلة افتراضياً).
 * @see docs/archive/ORANGE_ONLINE_PAYMENT_READINESS.txt
 *
 * idempotent — آمن لإعادة التشغيل. لا يفعّل أي دفع؛ ينشئ البنية فقط.
 */
function orange_payments_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    require_once __DIR__ . '/../catalog_schema.php';

    if (orange_table_exists($pdo, 'orders')) {
        if (!orange_table_has_column($pdo, 'orders', 'payment_status')) {
            orange_catalog_safe_exec(
                $pdo,
                "ALTER TABLE orders ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid'"
            );
            orange_schema_invalidate_column_check('orders', 'payment_status');
        }
        if (!orange_table_has_column($pdo, 'orders', 'payment_method')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN payment_method VARCHAR(32) NULL DEFAULT NULL'
            );
            orange_schema_invalidate_column_check('orders', 'payment_method');
        }
        if (!orange_table_has_column($pdo, 'orders', 'paid_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN paid_at TIMESTAMP NULL DEFAULT NULL'
            );
            orange_schema_invalidate_column_check('orders', 'paid_at');
        }
    }

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS payment_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NULL DEFAULT NULL,
            country_id INT UNSIGNED NULL DEFAULT NULL,
            method VARCHAR(32) NOT NULL DEFAULT \'\',
            provider VARCHAR(64) NOT NULL DEFAULT \'\',
            amount DECIMAL(18,4) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT \'\',
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            provider_ref VARCHAR(191) NOT NULL DEFAULT \'\',
            txn_uuid VARCHAR(64) NOT NULL,
            raw_payload TEXT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_txn_uuid (txn_uuid),
            KEY idx_payment_txn_order (order_id),
            KEY idx_payment_txn_country (country_id),
            KEY idx_payment_txn_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS payment_methods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_id INT UNSIGNED NULL DEFAULT NULL,
            method VARCHAR(32) NOT NULL DEFAULT \'\',
            provider VARCHAR(64) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_method_scope (country_id, method, provider),
            KEY idx_payment_method_country_active (country_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /* م1 — حسابات بنك الشركة لكل دولة (لعرضها للعميل + مرجع التحويل المباشر). */
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS company_bank_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_id INT UNSIGNED NULL DEFAULT NULL,
            bank_name VARCHAR(191) NOT NULL DEFAULT \'\',
            account_name VARCHAR(191) NOT NULL DEFAULT \'\',
            account_number VARCHAR(64) NOT NULL DEFAULT \'\',
            iban VARCHAR(64) NOT NULL DEFAULT \'\',
            currency VARCHAR(8) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company_bank_country (country_id, is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /* م1 — إثبات تحويل بنكي على حركة الدفع. */
    if (orange_table_exists($pdo, 'payment_transactions')
        && !orange_table_has_column($pdo, 'payment_transactions', 'proof_file')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE payment_transactions ADD COLUMN proof_file VARCHAR(191) NOT NULL DEFAULT '' AFTER provider_ref"
        );
        orange_schema_invalidate_column_check('payment_transactions', 'proof_file');
    }

    $done = true;
}
