<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/schema_migrations.php';
require_once __DIR__ . '/countries.php';

/**
 * إصلاح country_id من قناة التسجيل — idempotent (يشمل تصحيح country_id الخاطئ).
 */
function orange_country_scope_repair_from_channels(PDO $pdo): array
{
    $out = [
        'storefront_accounts' => 0,
        'merge_requests' => 0,
        'customers' => 0,
    ];
    if (!orange_table_exists($pdo, 'channels') || !orange_table_has_column($pdo, 'channels', 'country_id')) {
        return $out;
    }

    if (orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'country_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        $out['storefront_accounts'] = (int) $pdo->exec(
            'UPDATE storefront_accounts sa
             INNER JOIN channels ch ON ch.slug = sa.registered_channel_slug AND ch.country_id > 0
             SET sa.country_id = ch.country_id
             WHERE sa.registered_channel_slug IS NOT NULL
               AND TRIM(sa.registered_channel_slug) <> \'\'
               AND (sa.country_id IS NULL OR sa.country_id = 0 OR sa.country_id <> ch.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'storefront_phone_merge_requests')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
        $out['merge_requests'] = (int) $pdo->exec(
            'UPDATE storefront_phone_merge_requests r
             INNER JOIN channels ch ON ch.slug = r.proposed_channel_slug AND ch.country_id > 0
             SET r.country_id = ch.country_id
             WHERE r.proposed_channel_slug IS NOT NULL
               AND TRIM(r.proposed_channel_slug) <> \'\'
               AND (r.country_id IS NULL OR r.country_id = 0 OR r.country_id <> ch.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'customers')
        && orange_table_has_column($pdo, 'customers', 'country_id')
        && orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        $out['customers'] += (int) $pdo->exec(
            'UPDATE customers c
             INNER JOIN storefront_accounts sa ON sa.customer_id = c.id
             INNER JOIN channels ch ON ch.slug = sa.registered_channel_slug AND ch.country_id > 0
             SET c.country_id = ch.country_id
             WHERE sa.registered_channel_slug IS NOT NULL
               AND TRIM(sa.registered_channel_slug) <> \'\'
               AND (c.country_id IS NULL OR c.country_id = 0 OR c.country_id <> ch.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'country_id')) {
        $kwId = orange_countries_default_id($pdo);
        if (orange_table_exists($pdo, 'orders')
            && orange_table_has_column($pdo, 'orders', 'customer_id')
            && orange_table_has_column($pdo, 'orders', 'country_id')) {
            $out['customers'] += (int) $pdo->exec(
                'UPDATE customers c
                 INNER JOIN (
                     SELECT o.customer_id, o.country_id
                     FROM orders o
                     WHERE o.customer_id IS NOT NULL AND o.customer_id > 0
                       AND o.country_id IS NOT NULL AND o.country_id > 0
                     GROUP BY o.customer_id, o.country_id
                 ) x ON x.customer_id = c.id
                 SET c.country_id = x.country_id
                 WHERE c.country_id IS NULL OR c.country_id = 0
                    OR (c.country_id = ' . (int) $kwId . ' AND x.country_id <> c.country_id)'
            );
        }
    }

    return $out;
}

function orange_catalog_migrate_country_scope_repair_v71(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v71';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v71 OK');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v71 marker: ' . $e->getMessage());
        }
    }
}

/** إعادة إصلاح country_id بعد v71 (تصحيح صفوف عُيّنت بالكويت خطأً). */
function orange_catalog_migrate_country_scope_repair_v72(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v72';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v72 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v72 marker: ' . $e->getMessage());
        }
    }
}
