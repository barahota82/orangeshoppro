<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/schema_migrations.php';

function orange_catalog_storefront_payment_ensure_schema(PDO $pdo): void
{
    if (
        orange_table_exists($pdo, 'company_settings')
        && !orange_table_has_column($pdo, 'company_settings', 'payment_online_enabled')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE company_settings ADD COLUMN payment_online_enabled TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
}

/**
 * ترحيل v70 — مفتاح الدفع الإلكتروني per دولة في company_settings.
 */
function orange_catalog_migrate_storefront_payment_v70(PDO $pdo): void
{
    orange_catalog_storefront_payment_ensure_schema($pdo);

    $marker = 'php_storefront_payment_v70';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] storefront payment schema v70 OK');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] storefront payment v70 marker: ' . $e->getMessage());
        }
    }
}
