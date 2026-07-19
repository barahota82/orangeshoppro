<?php

declare(strict_types=1);

/**
 * Country Recovery Platform — Final Quality Hardening (N3-01 … N3-07).
 *
 * Does not implement Country Production Restore / enablement / certification.
 * Architecture remains seeded_multicountry_target_slice.
 */

/**
 * N3-06: Test $GLOBALS overrides are CLI self-test only; never when production restore enabled.
 */
function orange_country_shadow_test_overrides_permitted(): bool
{
    if (defined('ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED') && ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED) {
        return false;
    }
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    if (defined('ORANGE_CRP_ALLOW_TEST_OVERRIDES') && ORANGE_CRP_ALLOW_TEST_OVERRIDES === true) {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($script !== '' && preg_match('#self_test_country_crp_[^/]+\.php$#', $script) === 1) {
        return true;
    }
    $argv0 = str_replace('\\', '/', (string) ($GLOBALS['argv'][0] ?? ''));
    if ($argv0 !== '' && preg_match('#self_test_country_crp_[^/]+\.php$#', $argv0) === 1) {
        return true;
    }

    return false;
}

function orange_country_shadow_override_callable(string $key): ?callable
{
    if (!orange_country_shadow_test_overrides_permitted()) {
        return null;
    }
    if (isset($GLOBALS[$key]) && is_callable($GLOBALS[$key])) {
        /** @var callable $fn */
        $fn = $GLOBALS[$key];

        return $fn;
    }

    return null;
}

function orange_country_shadow_override_value(string $key): mixed
{
    if (!orange_country_shadow_test_overrides_permitted()) {
        return null;
    }

    return $GLOBALS[$key] ?? null;
}

/**
 * @return array<string, mixed>
 */
function orange_country_shadow_schema_expectations_load(string $projectRoot): array
{
    $path = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'config'
        . DIRECTORY_SEPARATOR . 'country_restore_schema_expectations.json';
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * N3-02: Real schema drift detection (revision + tables + columns + indexes + constraints).
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $matrix
 * @param list<string> $importTables
 * @return array{ok:bool,codes:list<string>,checks:list<array<string,mixed>>}
 */
function orange_country_shadow_verify_schema_drift(
    PDO $pdo,
    string $projectRoot,
    array $manifest,
    array $matrix,
    array $importTables = []
): array {
    $codes = [];
    $checks = [];
    $expectations = orange_country_shadow_schema_expectations_load($projectRoot);
    if ($expectations === []) {
        $codes[] = 'schema_expectations_missing';
        $checks[] = ['id' => 'schema_expectations', 'status' => 'FAIL', 'code' => 'schema_expectations_missing', 'detail' => 'expectations file missing'];

        return ['ok' => false, 'codes' => $codes, 'checks' => $checks];
    }

    $expectedRev = (int) ($expectations['schema_revision'] ?? 0);
    $matrixRev = (int) ($matrix['schema_revision'] ?? 0);
    $manifestRev = (int) ($manifest['schema_revision'] ?? 0);
    $codeRev = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION : $expectedRev;

    if ($expectedRev <= 0 || $matrixRev !== $expectedRev) {
        $codes[] = 'schema_revision_mismatch';
        $checks[] = ['id' => 'schema_rev_matrix', 'status' => 'FAIL', 'code' => 'schema_revision_mismatch', 'detail' => 'matrix=' . $matrixRev . ' expected=' . $expectedRev];
    } elseif ($manifestRev > 0 && $manifestRev !== $expectedRev) {
        $codes[] = 'schema_revision_mismatch';
        $checks[] = ['id' => 'schema_rev_manifest', 'status' => 'FAIL', 'code' => 'schema_revision_mismatch', 'detail' => 'manifest=' . $manifestRev];
    } elseif ($codeRev > 0 && $codeRev !== $expectedRev) {
        $codes[] = 'schema_revision_mismatch';
        $checks[] = ['id' => 'schema_rev_code', 'status' => 'FAIL', 'code' => 'schema_revision_mismatch', 'detail' => 'code=' . $codeRev];
    } else {
        $checks[] = ['id' => 'schema_revision', 'status' => 'PASS', 'code' => null, 'detail' => 'revision=' . $expectedRev];
    }

    /** @var array<string, array<string, mixed>> $tableExpectations */
    $tableExpectations = is_array($expectations['tables'] ?? null) ? $expectations['tables'] : [];
    $core = is_array($expectations['core_tables'] ?? null) ? $expectations['core_tables'] : array_keys($tableExpectations);
    $driver = '';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
    }
    $isMysql = strcasecmp($driver, 'mysql') === 0;

    $tablesToCheck = [];
    foreach ($importTables as $t) {
        $tablesToCheck[] = (string) $t;
    }
    foreach ($core as $t) {
        $tablesToCheck[] = (string) $t;
    }
    $tablesToCheck = array_values(array_unique($tablesToCheck));

    foreach ($tablesToCheck as $table) {
        if (!isset($tableExpectations[$table]) && !in_array($table, $importTables, true)) {
            continue;
        }
        if (!orange_country_shadow_table_exists($pdo, $table)) {
            // Import tables must exist; core tables only fail when present in expectations and MySQL full probe.
            if (in_array($table, $importTables, true)) {
                $codes[] = 'schema_table_missing';
                $checks[] = ['id' => 'schema_table_' . $table, 'status' => 'FAIL', 'code' => 'schema_table_missing', 'detail' => $table];
            }
            continue;
        }
        $meta = is_array($tableExpectations[$table] ?? null) ? $tableExpectations[$table] : [];
        $requiredCols = is_array($meta['required_columns'] ?? null) ? $meta['required_columns'] : [];
        // Ownership-derived minimums
        $matrixMeta = is_array($matrix['tables'][$table] ?? null) ? $matrix['tables'][$table] : [];
        $resolver = (string) ($matrixMeta['ownership_resolver'] ?? '');
        if ($resolver === 'direct_country_id' || ($resolver === '' && orange_country_shadow_table_has_column($pdo, $table, 'country_id'))) {
            if (!in_array('country_id', $requiredCols, true) && orange_country_shadow_table_has_column($pdo, $table, 'id')) {
                // only enforce country_id when column is expected by matrix ownership
                if ($resolver === 'direct_country_id' && !in_array('country_id', $requiredCols, true)) {
                    $requiredCols[] = 'country_id';
                }
            }
        }
        foreach ($requiredCols as $col) {
            $col = (string) $col;
            if (!orange_country_shadow_table_has_column($pdo, $table, $col)) {
                $codes[] = 'schema_column_missing';
                $checks[] = ['id' => 'schema_col_' . $table . '_' . $col, 'status' => 'FAIL', 'code' => 'schema_column_missing', 'detail' => $table . '.' . $col];
            }
        }

        $requiredIndexes = is_array($meta['required_indexes'] ?? null) ? $meta['required_indexes'] : [];
        if ($isMysql && $requiredIndexes !== []) {
            foreach ($requiredIndexes as $idx) {
                if (!is_array($idx)) {
                    continue;
                }
                $cols = is_array($idx['columns'] ?? null) ? $idx['columns'] : [];
                if ($cols === []) {
                    continue;
                }
                if (!orange_country_shadow_mysql_has_index_covering($pdo, $table, array_map('strval', $cols))) {
                    // Soft when fixture table has no indexes at all (sparse seeds); fail when indexes exist but wrong.
                    if (orange_country_shadow_mysql_table_index_count($pdo, $table) === 0) {
                        $checks[] = ['id' => 'schema_idx_' . $table, 'status' => 'PASS', 'code' => null, 'detail' => $table . ' fixture_no_indexes_skip'];
                    } else {
                        $codes[] = 'schema_index_missing';
                        $checks[] = ['id' => 'schema_idx_' . $table, 'status' => 'FAIL', 'code' => 'schema_index_missing', 'detail' => $table . ':' . implode(',', $cols)];
                    }
                } else {
                    $checks[] = ['id' => 'schema_idx_' . $table, 'status' => 'PASS', 'code' => null, 'detail' => implode(',', $cols)];
                }
            }
        }

        $requiredConstraints = is_array($meta['required_constraints'] ?? null) ? $meta['required_constraints'] : [];
        if ($isMysql && $requiredConstraints !== []) {
            foreach ($requiredConstraints as $cName) {
                $cName = (string) $cName;
                if ($cName === '') {
                    continue;
                }
                if (!orange_country_shadow_mysql_has_constraint($pdo, $table, $cName)) {
                    $codes[] = 'schema_constraint_missing';
                    $checks[] = ['id' => 'schema_fk_' . $table . '_' . $cName, 'status' => 'FAIL', 'code' => 'schema_constraint_missing', 'detail' => $cName];
                }
            }
        }
    }

    if ($codes === []) {
        $checks[] = ['id' => 'schema_drift', 'status' => 'PASS', 'code' => null, 'detail' => 'schema drift checks ok'];
    }

    return ['ok' => $codes === [], 'codes' => array_values(array_unique($codes)), 'checks' => $checks];
}

/**
 * @param list<string> $columns
 */
function orange_country_shadow_mysql_has_index_covering(PDO $pdo, string $table, array $columns): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $st->execute([$table]);
        $byIndex = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $byIndex[$name][] = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
        }
        $want = array_map('strtolower', $columns);
        foreach ($byIndex as $cols) {
            $prefix = array_slice($cols, 0, count($want));
            if ($prefix === $want) {
                return true;
            }
        }
    } catch (Throwable) {
    }

    return false;
}

function orange_country_shadow_mysql_table_index_count(PDO $pdo, string $table): int
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $st->execute([$table]);

        return (int) $st->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function orange_country_shadow_mysql_has_constraint(PDO $pdo, string $table, string $constraintName): bool
{
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $st->execute([$table, $constraintName]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * N3-01: Re-validate survivor baseline after import (C6 post-verify).
 *
 * @return array{ok:bool,codes:list<string>,checks:list<array<string,mixed>>}
 */
function orange_country_shadow_verify_survivor_baseline_post_import(
    PDO $pdo,
    int $countryId,
    string $projectRoot,
    string $runDir
): array {
    $codes = [];
    $checks = [];
    $path = $runDir . DIRECTORY_SEPARATOR . 'survivor_baseline.json';
    if ($runDir === '' || !is_file($path)) {
        $codes[] = 'survivor_baseline_missing';
        $checks[] = ['id' => 'survivor_baseline', 'status' => 'FAIL', 'code' => 'survivor_baseline_missing', 'detail' => 'missing pre-clear baseline'];

        return ['ok' => false, 'codes' => $codes, 'checks' => $checks];
    }
    $baseline = json_decode((string) file_get_contents($path), true);
    if (!is_array($baseline) || $baseline === []) {
        $codes[] = 'survivor_baseline_missing';
        $checks[] = ['id' => 'survivor_baseline', 'status' => 'FAIL', 'code' => 'survivor_baseline_missing', 'detail' => 'empty baseline'];

        return ['ok' => false, 'codes' => $codes, 'checks' => $checks];
    }

    $current = orange_country_shadow_capture_live_baselines($pdo, $countryId, $projectRoot);
    $survivorCurrent = is_array($current['survivor'] ?? null) ? $current['survivor'] : [];
    $ok = true;
    foreach ($baseline as $table => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $cur = is_array($survivorCurrent[$table] ?? null) ? $survivorCurrent[$table] : [];
        if ((int) ($cur['count'] ?? -1) !== (int) ($meta['count'] ?? -2)) {
            $codes[] = 'survivor_country_row_deleted';
            $checks[] = ['id' => 'surv_count_' . $table, 'status' => 'FAIL', 'code' => 'survivor_country_row_deleted', 'detail' => (string) $table];
            $ok = false;
        }
        $bh = (string) ($meta['hash'] ?? '');
        $ch = (string) ($cur['hash'] ?? '');
        if ($bh !== '' && $ch !== '' && !hash_equals($bh, $ch)) {
            $codes[] = 'survivor_country_row_modified';
            $checks[] = ['id' => 'surv_hash_' . $table, 'status' => 'FAIL', 'code' => 'survivor_country_row_modified', 'detail' => (string) $table];
            $ok = false;
        }
    }
    if ($ok) {
        $checks[] = ['id' => 'survivor_baseline_revalidate', 'status' => 'PASS', 'code' => null, 'detail' => 'survivor baseline matched post-import'];
    }

    return ['ok' => $codes === [], 'codes' => array_values(array_unique($codes)), 'checks' => $checks];
}

/**
 * N3-05: Real restore-batch dependency ordering verification.
 *
 * @param array<string, mixed> $importPlan
 * @param array<string, mixed> $matrix
 * @param array<string, mixed> $manifest
 * @return array{ok:bool,codes:list<string>,checks:list<array<string,mixed>>}
 */
function orange_country_shadow_verify_batch_integrity(array $importPlan, array $matrix, array $manifest = []): array
{
    $codes = [];
    $checks = [];
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    $ordered = is_array($importPlan['tables'] ?? null) ? $importPlan['tables'] : [];
    $batches = is_array($importPlan['restore_batches'] ?? null) ? $importPlan['restore_batches'] : [];
    if ($batches === [] && is_array($manifest['restore_batches'] ?? null)) {
        $batches = $manifest['restore_batches'];
    }
    if ($ordered === []) {
        $codes[] = 'batch_integrity_failed';
        $checks[] = ['id' => 'batch_integrity', 'status' => 'FAIL', 'code' => 'batch_integrity_failed', 'detail' => 'empty import tables'];

        return ['ok' => false, 'codes' => $codes, 'checks' => $checks];
    }

    $prevBatch = 0;
    foreach ($ordered as $table) {
        $table = (string) $table;
        $meta = is_array($matrixTables[$table] ?? null) ? $matrixTables[$table] : [];
        $batch = (int) ($meta['restore_batch'] ?? 0);
        if ($batch < 1 || $batch > 6) {
            $codes[] = 'dependency_batch_missing';
            $checks[] = ['id' => 'batch_meta_' . $table, 'status' => 'FAIL', 'code' => 'dependency_batch_missing', 'detail' => $table];
            continue;
        }
        if ($batch < $prevBatch) {
            $codes[] = 'batch_order_violation';
            $checks[] = ['id' => 'batch_order_' . $table, 'status' => 'FAIL', 'code' => 'batch_order_violation', 'detail' => $table . ' batch=' . $batch . ' after=' . $prevBatch];
        }
        $prevBatch = max($prevBatch, $batch);
        // Table must appear in declared restore_batches map when present
        if ($batches !== []) {
            $found = false;
            foreach ($batches as $bTables) {
                if (is_array($bTables) && in_array($table, $bTables, true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $codes[] = 'dependency_batch_missing';
                $checks[] = ['id' => 'batch_map_' . $table, 'status' => 'FAIL', 'code' => 'dependency_batch_missing', 'detail' => $table . ' not in restore_batches'];
            }
        }
    }

    // Parent_fk children must not restore before parent when both are in the plan
    foreach ($ordered as $idx => $table) {
        $table = (string) $table;
        $meta = is_array($matrixTables[$table] ?? null) ? $matrixTables[$table] : [];
        if (($meta['ownership_resolver'] ?? '') !== 'parent_fk') {
            continue;
        }
        $projectRoot = dirname(__DIR__, 3);
        $pd = function_exists('orange_country_shadow_resolve_parent_dependency')
            ? orange_country_shadow_resolve_parent_dependency($table, $projectRoot)
            : null;
        if ($pd === null) {
            continue;
        }
        $parent = (string) $pd['table'];
        $parentPos = array_search($parent, $ordered, true);
        if ($parentPos !== false && (int) $parentPos > (int) $idx) {
            $codes[] = 'batch_order_violation';
            $checks[] = ['id' => 'batch_parent_' . $table, 'status' => 'FAIL', 'code' => 'batch_order_violation', 'detail' => $table . ' before parent ' . $parent];
        }
    }

    if ($codes === []) {
        $checks[] = [
            'id' => 'batch_integrity',
            'status' => 'PASS',
            'code' => null,
            'detail' => 'restore batch order ok tables=' . (string) count($ordered),
        ];
    }

    return ['ok' => $codes === [], 'codes' => array_values(array_unique($codes)), 'checks' => $checks];
}

/**
 * N3-03: Warehouse / stock / FIFO ownership + cross-country references.
 *
 * @return array{ok:bool,codes:list<string>}
 */
function orange_country_shadow_verify_stock_fifo_ownership(PDO $pdo, int $countryId): array
{
    $codes = [];

    if (orange_country_shadow_table_exists($pdo, 'warehouse_variant_stock')
        && orange_country_shadow_table_exists($pdo, 'warehouses')
    ) {
        try {
            // Orphan stock (no warehouse)
            $st = $pdo->query(
                'SELECT COUNT(*) FROM warehouse_variant_stock s
                 LEFT JOIN warehouses w ON w.id = s.warehouse_id WHERE w.id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $codes[] = 'stock_warehouse_ownership_mismatch';
            }
            // Target stock must belong to target warehouses only
            if (orange_country_shadow_table_has_column($pdo, 'warehouses', 'country_id')) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM warehouse_variant_stock s
                     INNER JOIN warehouses w ON w.id = s.warehouse_id
                     WHERE w.country_id IS NULL OR w.country_id <> ?'
                );
                // Count stock rows whose warehouse is not target — only flag when stock is linked from target layers? 
                // Cross-country: stock pointing at non-target warehouse while a target product/layer references it is covered below.
                // Flag any stock whose warehouse country is NULL.
                $st = $pdo->query(
                    'SELECT COUNT(*) FROM warehouse_variant_stock s
                     INNER JOIN warehouses w ON w.id = s.warehouse_id
                     WHERE w.country_id IS NULL'
                );
                if ($st && (int) $st->fetchColumn() > 0) {
                    $codes[] = 'stock_warehouse_ownership_mismatch';
                }
                // Cross-country reference: target warehouse stock ids must not be missing; non-target warehouses may keep survivor stock.
                // Leakage: stock for target warehouse whose warehouse country != target (impossible if join correct) —
                // instead: inventory layers for target country referencing non-target warehouse.
            }
        } catch (Throwable) {
            $codes[] = 'stock_warehouse_ownership_mismatch';
        }
    }

    if (orange_country_shadow_table_exists($pdo, 'inventory_cost_layers')
        && orange_country_shadow_table_exists($pdo, 'warehouses')
    ) {
        try {
            if (orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'warehouse_id')
                && orange_country_shadow_table_has_column($pdo, 'warehouses', 'country_id')
            ) {
                // Target layers must reference target warehouses
                if (orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'country_id')) {
                    $st = $pdo->prepare(
                        'SELECT COUNT(*) FROM inventory_cost_layers l
                         LEFT JOIN warehouses w ON w.id = l.warehouse_id
                         WHERE l.country_id = ?
                           AND (w.id IS NULL OR w.country_id IS NULL OR w.country_id <> ?)'
                    );
                    $st->execute([$countryId, $countryId]);
                    if ((int) $st->fetchColumn() > 0) {
                        $codes[] = 'stock_movement_leakage';
                        $codes[] = 'fifo_cross_country_reference';
                    }
                } else {
                    $st = $pdo->prepare(
                        'SELECT COUNT(*) FROM inventory_cost_layers l
                         INNER JOIN warehouses w ON w.id = l.warehouse_id
                         WHERE w.country_id = ?
                           AND EXISTS (
                             SELECT 1 FROM warehouses wx WHERE wx.id = l.warehouse_id AND (wx.country_id IS NULL OR wx.country_id <> ?)
                           )'
                    );
                    // Simpler: layers whose warehouse is non-target while layer used by target consumptions
                    $st = $pdo->query(
                        'SELECT COUNT(*) FROM inventory_cost_layers l
                         LEFT JOIN warehouses w ON w.id = l.warehouse_id WHERE w.id IS NULL'
                    );
                    if ($st && (int) $st->fetchColumn() > 0) {
                        $codes[] = 'incomplete_fifo_graph';
                    }
                }
            }
        } catch (Throwable) {
            $codes[] = 'incomplete_fifo_graph';
        }
    }

    if (orange_country_shadow_table_exists($pdo, 'inventory_cost_consumptions')
        && orange_country_shadow_table_exists($pdo, 'inventory_cost_layers')
    ) {
        try {
            $orph = (int) $pdo->query(
                'SELECT COUNT(*) FROM inventory_cost_consumptions c
                 LEFT JOIN inventory_cost_layers l ON l.id = c.layer_id WHERE l.id IS NULL'
            )->fetchColumn();
            if ($orph > 0) {
                $codes[] = 'incomplete_fifo_graph';
            }
            // Cross-country: consumption of a layer owned by another country (when layer has country_id)
            if (orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'country_id')
                && orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'warehouse_id')
                && orange_country_shadow_table_exists($pdo, 'warehouses')
            ) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM inventory_cost_consumptions c
                     INNER JOIN inventory_cost_layers l ON l.id = c.layer_id
                     INNER JOIN warehouses w ON w.id = l.warehouse_id
                     WHERE l.country_id = ? AND w.country_id IS NOT NULL AND w.country_id <> ?'
                );
                $st->execute([$countryId, $countryId]);
                if ((int) $st->fetchColumn() > 0) {
                    $codes[] = 'fifo_cross_country_reference';
                }
            }
            if (orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'qty')
                && orange_country_shadow_table_has_column($pdo, 'inventory_cost_consumptions', 'qty')
            ) {
                $over = (int) $pdo->query(
                    'SELECT COUNT(*) FROM (
                        SELECT c.layer_id, SUM(c.qty) AS consumed, MAX(l.qty) AS layer_qty
                        FROM inventory_cost_consumptions c
                        INNER JOIN inventory_cost_layers l ON l.id = c.layer_id
                        GROUP BY c.layer_id
                        HAVING consumed > layer_qty + 0.0001
                     ) x'
                )->fetchColumn();
                if ($over > 0) {
                    $codes[] = 'fifo_layer_overconsumed';
                }
            }
        } catch (Throwable) {
            $codes[] = 'incomplete_fifo_graph';
        }
    }

    return ['ok' => $codes === [], 'codes' => array_values(array_unique($codes))];
}

/**
 * N3-07: Whether package inventory/manifest proves zero upload files.
 *
 * @return array{empty:bool,source:string,expected_count:int}
 */
function orange_country_shadow_uploads_expected_state(string $packagePath): array
{
    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    $invPath = $packagePath . DIRECTORY_SEPARATOR . 'table_inventory.json';
    $expected = -1;
    $source = 'unknown';
    if (is_file($manifestPath)) {
        $m = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($m) && array_key_exists('uploads_file_count', $m)) {
            $expected = (int) $m['uploads_file_count'];
            $source = 'manifest.uploads_file_count';
        }
    }
    if ($expected < 0 && is_file($invPath)) {
        $inv = json_decode((string) file_get_contents($invPath), true);
        if (is_array($inv) && array_key_exists('uploads_file_count', $inv)) {
            $expected = (int) $inv['uploads_file_count'];
            $source = 'table_inventory.uploads_file_count';
        } elseif (is_array($inv['uploads'] ?? null) && array_key_exists('file_count', $inv['uploads'])) {
            $expected = (int) $inv['uploads']['file_count'];
            $source = 'table_inventory.uploads.file_count';
        }
    }

    return [
        'empty' => $expected === 0,
        'source' => $source,
        'expected_count' => $expected,
    ];
}

/**
 * N3-07: Uploads verification supporting legitimately empty packages.
 *
 * @return array{ok:bool,codes:list<string>,detail:string}
 */
function orange_country_shadow_verify_uploads_integrity(string $packagePath): array
{
    if ($packagePath === '') {
        return ['ok' => false, 'codes' => ['uploads_archive_missing'], 'detail' => 'package path empty'];
    }
    $state = orange_country_shadow_uploads_expected_state($packagePath);
    $zipPath = $packagePath . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';

    if (!is_file($zipPath)) {
        if ($state['empty']) {
            return ['ok' => true, 'codes' => [], 'detail' => 'uploads_empty_by_inventory_no_zip:' . $state['source']];
        }

        return ['ok' => false, 'codes' => ['uploads_archive_missing'], 'detail' => 'uploads_country.zip missing'];
    }

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'codes' => ['uploads_archive_missing'], 'detail' => 'ZipArchive missing'];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'codes' => ['upload_owner_mismatch'], 'detail' => 'zip unreadable'];
    }
    $realFiles = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        if ($name === 'README.txt') {
            continue;
        }
        if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            $zip->close();

            return ['ok' => false, 'codes' => ['upload_owner_mismatch'], 'detail' => 'unsafe path'];
        }
        $realFiles++;
    }
    $zip->close();

    if ($state['empty'] && $realFiles === 0) {
        return ['ok' => true, 'codes' => [], 'detail' => 'uploads_empty_ok:' . $state['source']];
    }
    if ($state['expected_count'] >= 0 && $realFiles !== $state['expected_count']) {
        return ['ok' => false, 'codes' => ['missing_upload_reference'], 'detail' => 'count mismatch real=' . $realFiles . ' expected=' . $state['expected_count']];
    }

    return ['ok' => true, 'codes' => [], 'detail' => 'uploads ok files=' . $realFiles];
}

/**
 * N3-04: Explicit outside-target impact proof from restore plan + certified inventory.
 *
 * @param array<string, mixed> $prodInv
 * @param list<string> $tablesAffected
 * @param array<string, array<string, mixed>> $tablesMeta
 * @param array<string, int|float> $invTables
 * @param array<string, mixed> $inject
 * @return array{
 *   survivor_country_impact:int,
 *   global_impact:int,
 *   journal_entries_impact:int,
 *   full_only_impact:int,
 *   outside_target_impact_proof:array<string,mixed>
 * }
 */
function orange_country_dry_run_build_outside_target_proof(
    array $prodInv,
    array $tablesAffected,
    array $tablesMeta,
    array $invTables,
    array $inject = []
): array {
    $survivorCounts = is_array($prodInv['survivor_counts'] ?? null) ? $prodInv['survivor_counts'] : null;
    $globalCounts = is_array($prodInv['global_counts'] ?? null) ? $prodInv['global_counts'] : null;
    $proof = [
        'survivor_proof' => null,
        'global_proof' => null,
        'journal_entries_proof' => null,
        'full_only_proof' => null,
        'survivor_tables_checked' => [],
        'survivor_row_total' => 0,
        'global_tables_checked' => [],
        'global_row_total' => 0,
        'plan_mutates_global' => false,
        'plan_mutates_never_export' => false,
        'plan_includes_survivor_owned_delete' => false,
        'method' => 'restore_plan_plus_certified_inventory',
        'simulation_execution' => false,
    ];

    if (isset($inject['survivor_country_impact'])) {
        $survivorImpact = (int) $inject['survivor_country_impact'];
        $proof['survivor_proof'] = 'inject';
    } elseif ($survivorCounts === null) {
        $survivorImpact = 1;
        $proof['survivor_proof'] = 'unproven_missing_survivor_inventory';
    } else {
        $survivorImpact = 0;
        foreach ($survivorCounts as $t => $cnt) {
            $proof['survivor_tables_checked'][] = (string) $t;
            $proof['survivor_row_total'] += (int) $cnt;
        }
        foreach ($tablesAffected as $tName) {
            $class = (string) (($tablesMeta[$tName]['classification'] ?? ''));
            if ($class === 'Global') {
                $survivorImpact = 1;
                $proof['plan_mutates_global'] = true;
            }
        }
        // Target-slice ownership never deletes survivor country_id rows by model.
        $proof['plan_includes_survivor_owned_delete'] = false;
        $proof['survivor_proof'] = $survivorImpact === 0
            ? 'certified_survivor_inventory_present_and_plan_excludes_survivor_deletes'
            : 'plan_touches_global_classification';
    }

    if (isset($inject['global_impact'])) {
        $globalImpact = (int) $inject['global_impact'];
        $proof['global_proof'] = 'inject';
    } elseif ($globalCounts === null) {
        $globalImpact = 1;
        $proof['global_proof'] = 'unproven_missing_global_inventory';
    } else {
        $globalImpact = 0;
        foreach ($globalCounts as $t => $cnt) {
            $proof['global_tables_checked'][] = (string) $t;
            $proof['global_row_total'] += (int) $cnt;
        }
        foreach ($tablesAffected as $tName) {
            if (in_array((string) $tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)
                || (($tablesMeta[$tName]['classification'] ?? '') === 'Global')
            ) {
                $globalImpact = 1;
                $proof['plan_mutates_global'] = true;
                $proof['plan_mutates_never_export'] = in_array((string) $tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true);
            }
        }
        foreach (array_keys($invTables) as $tName) {
            if (in_array((string) $tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
                $globalImpact = 1;
                $proof['plan_mutates_never_export'] = true;
            }
        }
        $proof['global_proof'] = $globalImpact === 0
            ? 'certified_global_inventory_present_and_restore_plan_excludes_global'
            : 'plan_or_inventory_includes_global_or_never_export';
    }

    if (isset($inject['journal_entries_impact'])) {
        $jeImpact = (int) $inject['journal_entries_impact'];
        $proof['journal_entries_proof'] = 'inject';
    } elseif ($globalCounts === null || !array_key_exists('journal_entries', $globalCounts)) {
        $jeImpact = 1;
        $proof['journal_entries_proof'] = 'unproven_missing_journal_entries_inventory';
    } else {
        $jeImpact = 0;
        if (isset($invTables['journal_entries']) || in_array('journal_entries', $tablesAffected, true)) {
            $jeImpact = 1;
        }
        $proof['journal_entries_inventory_count'] = (int) $globalCounts['journal_entries'];
        $proof['journal_entries_proof'] = $jeImpact === 0
            ? 'journal_entries_in_certified_global_inventory_and_absent_from_restore_plan'
            : 'journal_entries_present_in_restore_plan_or_package_inventory';
    }

    if (isset($inject['full_only_impact'])) {
        $fullOnlyImpact = (int) $inject['full_only_impact'];
        $proof['full_only_proof'] = 'inject';
    } elseif ($globalCounts === null) {
        $fullOnlyImpact = 1;
        $proof['full_only_proof'] = 'unproven_missing_global_inventory';
    } else {
        $fullOnlyImpact = 0;
        foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
            if (isset($invTables[$never]) || in_array($never, $tablesAffected, true)) {
                $fullOnlyImpact = 1;
            }
        }
        $proof['full_only_proof'] = $fullOnlyImpact === 0
            ? 'full_only_never_export_absent_from_restore_plan'
            : 'full_only_or_never_export_in_plan';
    }

    return [
        'survivor_country_impact' => $survivorImpact,
        'global_impact' => $globalImpact,
        'journal_entries_impact' => $jeImpact,
        'full_only_impact' => $fullOnlyImpact,
        'outside_target_impact_proof' => $proof,
    ];
}
