<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/department_countries.php';
require_once __DIR__ . '/country_provision.php';

/**
 * @return list<int>
 */
function orange_catalog_active_country_ids(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'countries')) {
        return [];
    }
    try {
        $rows = $pdo->query(
            'SELECT id FROM countries WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_filter(array_map('intval', $rows), static fn (int $id): bool => $id > 0));
    } catch (Throwable $e) {
        return [];
    }
}

function orange_catalog_country_department_rows_count(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_department_countries_table_ready($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM department_countries WHERE country_id = ?');
        $st->execute([$countryId]);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function orange_catalog_backfill_products_country_id(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'products') || !orange_table_has_country_id($pdo, 'products')) {
        return 0;
    }
    $defaultId = orange_countries_default_id($pdo);
    if ($defaultId <= 0) {
        return 0;
    }
    try {
        return (int) $pdo->exec(
            'UPDATE products SET country_id = ' . (int) $defaultId . '
             WHERE country_id IS NULL OR country_id <= 0'
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_backfill_products_country_id: ' . $e->getMessage());
        }

        return 0;
    }
}

function orange_catalog_backfill_channels_country_id(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'channels') || !orange_channels_has_country_column($pdo)) {
        return 0;
    }
    $defaultId = orange_countries_default_id($pdo);
    if ($defaultId <= 0) {
        return 0;
    }
    try {
        return (int) $pdo->exec(
            'UPDATE channels SET country_id = ' . (int) $defaultId . '
             WHERE country_id IS NULL OR country_id <= 0'
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * صف department_countries لكل دولة × قسم — نسخ من KW أو seed غير نشط.
 */
function orange_catalog_ensure_department_countries_scaffold(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'countries') || !orange_department_countries_table_ready($pdo)) {
        return 0;
    }
    $sourceId = orange_countries_default_id($pdo);
    $fixed = 0;

    try {
        $countryIds = $pdo->query('SELECT id FROM countries ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($countryIds as $cidRaw) {
            $cid = (int) $cidRaw;
            if ($cid <= 0 || orange_catalog_country_department_rows_count($pdo, $cid) > 0) {
                continue;
            }
            if ($sourceId > 0 && $cid !== $sourceId) {
                $copy = orange_department_countries_copy_from_source($pdo, $cid, $sourceId);
                if ((int) ($copy['copied'] ?? 0) > 0) {
                    $fixed++;
                    continue;
                }
            }
            orange_department_countries_seed_for_new_country($pdo, $cid, false);
            $fixed++;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_department_countries_scaffold: ' . $e->getMessage());
        }
    }

    return $fixed;
}

function orange_catalog_active_country_needs_provision(PDO $pdo, int $countryId): bool
{
    if ($countryId <= 0) {
        return false;
    }
    $status = orange_country_provision_status($pdo, $countryId);
    if (!$status['warehouse'] || (int) ($status['channels_count'] ?? 0) < 1) {
        return true;
    }

    return orange_catalog_country_department_rows_count($pdo, $countryId) <= 0;
}

/**
 * @return array{
 *   products_country_fixed:int,
 *   channels_country_fixed:int,
 *   dept_scaffold:int,
 *   countries_provisioned:int,
 *   active_countries:list<int>
 * }
 */
function orange_catalog_ensure_multicountry_phase4(PDO $pdo): array
{
    $stats = [
        'products_country_fixed' => 0,
        'channels_country_fixed' => 0,
        'dept_scaffold' => 0,
        'countries_provisioned' => 0,
        'active_countries' => orange_catalog_active_country_ids($pdo),
    ];

    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return $stats;
    }

    $stats['products_country_fixed'] = orange_catalog_backfill_products_country_id($pdo);
    $stats['channels_country_fixed'] = orange_catalog_backfill_channels_country_id($pdo);
    $stats['dept_scaffold'] = orange_catalog_ensure_department_countries_scaffold($pdo);

    foreach ($stats['active_countries'] as $countryId) {
        if (!orange_catalog_active_country_needs_provision($pdo, $countryId)) {
            continue;
        }
        try {
            orange_country_provision_full($pdo, $countryId);
            $stats['countries_provisioned']++;
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] multicountry provision cid=' . $countryId . ': ' . $e->getMessage());
            }
        }
    }

    if ($stats['countries_provisioned'] > 0 && function_exists('error_log')) {
        error_log('[orange] multicountry phase4 provisioned=' . $stats['countries_provisioned']);
    }

    return $stats;
}

/**
 * @return array<string,int|bool>
 */
function orange_catalog_multicountry_phase4_gap_report(PDO $pdo, int $countryId): array
{
    $status = orange_country_provision_status($pdo, $countryId);

    return [
        'country_id' => $countryId,
        'warehouse' => (bool) ($status['warehouse'] ?? false),
        'channels_ok' => (int) ($status['channels_count'] ?? 0) > 0,
        'departments_ok' => orange_catalog_country_department_rows_count($pdo, $countryId) > 0,
        'products_count' => (int) ($status['products_count'] ?? 0),
    ];
}
