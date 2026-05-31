<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/schema_migrations.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/country_provision.php';

/**
 * إصلاح country_id من قناة التسجيل — idempotent.
 * slug القناة مكرّر بين الدول (نسخ provision) — لا UPDATE بـ JOIN slug وحده إلا عند slug فريد عالمياً.
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
        $out['storefront_accounts'] += (int) $pdo->exec(
            'UPDATE storefront_accounts sa
             INNER JOIN (
                 SELECT slug, MIN(country_id) AS country_id
                 FROM channels
                 WHERE country_id IS NOT NULL AND country_id > 0
                 GROUP BY slug
                 HAVING COUNT(DISTINCT country_id) = 1
             ) u ON u.slug = sa.registered_channel_slug
             SET sa.country_id = u.country_id
             WHERE sa.registered_channel_slug IS NOT NULL
               AND TRIM(sa.registered_channel_slug) <> \'\'
               AND (sa.country_id IS NULL OR sa.country_id = 0 OR sa.country_id <> u.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'storefront_phone_merge_requests')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
        $out['merge_requests'] += (int) $pdo->exec(
            'UPDATE storefront_phone_merge_requests r
             INNER JOIN (
                 SELECT slug, MIN(country_id) AS country_id
                 FROM channels
                 WHERE country_id IS NOT NULL AND country_id > 0
                 GROUP BY slug
                 HAVING COUNT(DISTINCT country_id) = 1
             ) u ON u.slug = r.proposed_channel_slug
             SET r.country_id = u.country_id
             WHERE r.proposed_channel_slug IS NOT NULL
               AND TRIM(r.proposed_channel_slug) <> \'\'
               AND (r.country_id IS NULL OR r.country_id = 0 OR r.country_id <> u.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'customers')
        && orange_table_has_column($pdo, 'customers', 'country_id')
        && orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'country_id')) {
        $out['customers'] += (int) $pdo->exec(
            'UPDATE customers c
             INNER JOIN storefront_accounts sa ON sa.customer_id = c.id
             SET c.country_id = sa.country_id
             WHERE sa.country_id IS NOT NULL AND sa.country_id > 0
               AND (c.country_id IS NULL OR c.country_id = 0 OR c.country_id <> sa.country_id)'
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

/** v73 — إصلاح بعد اكتشاف JOIN slug المكرّر + مزامنة customers من storefront_accounts.country_id. */
function orange_catalog_migrate_country_scope_repair_v73(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v73';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v73 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v73 marker: ' . $e->getMessage());
        }
    }
}

/**
 * v74 — فصل دليل الحسابات per country: عمود country_id + نسخ الدليل لكل دولة بلا حسابات.
 *
 * @return array{backfill_kw:int, countries_provisioned:int, accounts_copied:int}
 */
function orange_country_scope_repair_accounts_per_country(PDO $pdo): array
{
    $out = [
        'backfill_kw' => 0,
        'countries_provisioned' => 0,
        'accounts_copied' => 0,
    ];
    if (!orange_table_exists($pdo, 'accounts') || !orange_table_exists($pdo, 'countries')) {
        return $out;
    }

    orange_catalog_ensure_country_id_columns($pdo);
    orange_schema_invalidate_column_check('accounts', 'country_id');

    if (!orange_table_has_column($pdo, 'accounts', 'country_id')) {
        return $out;
    }

    $kwId = orange_countries_default_id($pdo);
    if ($kwId > 0) {
        $out['backfill_kw'] = (int) $pdo->exec(
            'UPDATE accounts SET country_id = ' . (int) $kwId
            . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }

    $countries = $pdo->query('SELECT id FROM countries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($countries as $cRow) {
        $cid = (int) ($cRow['id'] ?? 0);
        if ($cid <= 0 || $cid === $kwId) {
            continue;
        }
        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE country_id = ?');
        $stCnt->execute([$cid]);
        if ((int) $stCnt->fetchColumn() > 0) {
            continue;
        }
        $copy = orange_country_copy_accounts_from_source($pdo, $cid, $kwId > 0 ? $kwId : null);
        if ((int) ($copy['accounts_copied'] ?? 0) > 0) {
            $out['countries_provisioned']++;
            $out['accounts_copied'] += (int) $copy['accounts_copied'];
        }
    }

    return $out;
}

/** v74 — إصلاح تسريب شجرة الحسابات بين الدول. */
function orange_catalog_migrate_country_scope_repair_v74(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v74';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_accounts_per_country($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v74 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v74 marker: ' . $e->getMessage());
        }
    }
}
