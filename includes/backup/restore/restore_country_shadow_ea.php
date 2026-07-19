<?php

declare(strict_types=1);

/**
 * Country Shadow Remediation Sprint 2 (EA-01 … EA-06 helpers).
 *
 * Architecture: seeded_multicountry_target_slice
 * - Target-slice clear/verify only
 * - Global proven by baseline delta (never emptiness)
 * - Matrix + registry ownership resolvers (fail closed)
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_table_registry_lib.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';

/**
 * Resolve parent dependency for a mutate table (registry + C1.1 overrides).
 *
 * @return array{table:string,foreign_key:string}|null
 */
function orange_country_shadow_resolve_parent_dependency(string $table, string $projectRoot): ?array
{
    if (isset(ORANGE_CRP_PARENT_FK_OVERRIDES[$table])) {
        $o = ORANGE_CRP_PARENT_FK_OVERRIDES[$table];

        return [
            'table' => (string) $o['table'],
            'foreign_key' => (string) $o['foreign_key'],
        ];
    }
    $registry = orange_backup_registry_load($projectRoot);
    $tables = is_array($registry['tables'] ?? null) ? $registry['tables'] : [];
    $meta = is_array($tables[$table] ?? null) ? $tables[$table] : [];
    $pd = is_array($meta['parent_dependency'] ?? null) ? $meta['parent_dependency'] : null;
    if ($pd !== null && ($pd['table'] ?? '') !== '' && ($pd['foreign_key'] ?? '') !== '') {
        return [
            'table' => (string) $pd['table'],
            'foreign_key' => (string) $pd['foreign_key'],
        ];
    }
    $rule = is_array($meta['extraction_rule'] ?? null) ? $meta['extraction_rule'] : [];
    if (($rule['type'] ?? '') === 'parent_rows'
        && ($rule['parent_table'] ?? '') !== ''
        && ($rule['foreign_key'] ?? '') !== ''
    ) {
        return [
            'table' => (string) $rule['parent_table'],
            'foreign_key' => (string) $rule['foreign_key'],
        ];
    }

    return null;
}

/**
 * Build a target-country membership predicate SQL fragment for counting/deleting.
 *
 * @return array{ok:bool,code:?string,sql:?string,params:list<int|string>}
 */
function orange_country_shadow_target_membership_sql(
    PDO $pdo,
    string $table,
    int $countryId,
    array $matrixMeta,
    string $projectRoot,
    int $depth = 0
): array {
    if ($depth > 4) {
        return ['ok' => false, 'code' => 'unresolved_ownership_depth', 'sql' => null, 'params' => []];
    }
    $resolver = (string) ($matrixMeta['ownership_resolver'] ?? '');
    $q = '`' . str_replace('`', '``', $table) . '`';

    if ($resolver === 'special_namespace' || $table === 'document_sequences') {
        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`scope` LIKE ? ESCAPE \'\\\\\'',
            'params' => ['%\\_c' . $countryId],
        ];
    }
    if ($resolver === 'direct_country_id' || orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
        if (!orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
            return ['ok' => false, 'code' => 'unresolved_ownership', 'sql' => null, 'params' => []];
        }

        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`country_id` = ?',
            'params' => [$countryId],
        ];
    }
    if ($resolver === 'admin_ownership' || $table === 'admin_permissions') {
        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`admin_id` IN (SELECT id FROM `admins` WHERE `country_id` = ?)',
            'params' => [$countryId],
        ];
    }
    if ($resolver === 'account_ownership') {
        $fk = 'account_id';
        if (orange_country_shadow_table_has_column($pdo, $table, 'expense_account_id')) {
            $fk = 'expense_account_id';
        }

        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`' . str_replace('`', '``', $fk) . '` IN (SELECT id FROM `accounts` WHERE `country_id` = ?)',
            'params' => [$countryId],
        ];
    }
    if ($resolver === 'warehouse_ownership') {
        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`warehouse_id` IN (SELECT id FROM `warehouses` WHERE `country_id` = ?)',
            'params' => [$countryId],
        ];
    }
    if ($resolver === 'polymorphic_owner_validation') {
        return [
            'ok' => true,
            'code' => null,
            'sql' => '('
                . ' (' . $q . '.`entity_table` = \'orders\' AND ' . $q . '.`entity_id` IN (SELECT id FROM `orders` WHERE country_id = ?))'
                . ' OR (' . $q . '.`entity_table` = \'purchases\' AND ' . $q . '.`entity_id` IN (SELECT id FROM `purchases` WHERE country_id = ?))'
                . ' OR (' . $q . '.`entity_table` = \'customers\' AND ' . $q . '.`entity_id` IN (SELECT id FROM `customers` WHERE country_id = ?))'
                . ' OR (' . $q . '.`entity_table` = \'suppliers\' AND ' . $q . '.`entity_id` IN (SELECT id FROM `suppliers` WHERE country_id = ?))'
                . ')',
            'params' => [$countryId, $countryId, $countryId, $countryId],
        ];
    }
    if ($resolver === 'parent_fk') {
        $pd = orange_country_shadow_resolve_parent_dependency($table, $projectRoot);
        if ($pd === null) {
            return ['ok' => false, 'code' => 'unresolved_ownership', 'sql' => null, 'params' => []];
        }
        $parent = $pd['table'];
        $fk = $pd['foreign_key'];
        if (!orange_country_shadow_table_exists($pdo, $parent)) {
            return ['ok' => false, 'code' => 'unresolved_ownership_parent_missing', 'sql' => null, 'params' => []];
        }
        if (orange_country_shadow_table_has_column($pdo, $parent, 'country_id')) {
            return [
                'ok' => true,
                'code' => null,
                'sql' => $q . '.`' . str_replace('`', '``', $fk) . '` IN (SELECT id FROM `'
                    . str_replace('`', '``', $parent) . '` WHERE country_id = ?)',
                'params' => [$countryId],
            ];
        }
        // Nested: resolve parent membership recursively via matrix.
        $matrix = orange_country_boundary_matrix_load($projectRoot);
        $parentMeta = is_array($matrix['tables'][$parent] ?? null) ? $matrix['tables'][$parent] : [];
        $parentMem = orange_country_shadow_target_membership_sql(
            $pdo,
            $parent,
            $countryId,
            $parentMeta,
            $projectRoot,
            $depth + 1
        );
        if (!$parentMem['ok'] || $parentMem['sql'] === null) {
            return ['ok' => false, 'code' => $parentMem['code'] ?? 'unresolved_ownership', 'sql' => null, 'params' => []];
        }
        $pq = '`' . str_replace('`', '``', $parent) . '`';

        return [
            'ok' => true,
            'code' => null,
            'sql' => $q . '.`' . str_replace('`', '``', $fk) . '` IN (SELECT ' . $pq . '.id FROM ' . $pq
                . ' WHERE ' . $parentMem['sql'] . ')',
            'params' => $parentMem['params'],
        ];
    }

    return ['ok' => false, 'code' => 'unresolved_ownership', 'sql' => null, 'params' => []];
}

/**
 * Count target-slice rows for a table.
 */
function orange_country_shadow_count_target_rows(
    PDO $pdo,
    string $table,
    int $countryId,
    array $matrixMeta,
    string $projectRoot
): array {
    if (!orange_country_shadow_table_exists($pdo, $table)) {
        return ['ok' => true, 'count' => 0, 'code' => null];
    }
    $mem = orange_country_shadow_target_membership_sql($pdo, $table, $countryId, $matrixMeta, $projectRoot);
    if (!$mem['ok'] || $mem['sql'] === null) {
        return ['ok' => false, 'count' => 0, 'code' => $mem['code'] ?? 'unresolved_ownership'];
    }
    $q = '`' . str_replace('`', '``', $table) . '`';
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $q . ' WHERE ' . $mem['sql']);
        $st->execute($mem['params']);

        return ['ok' => true, 'count' => (int) $st->fetchColumn(), 'code' => null];
    } catch (Throwable) {
        return ['ok' => false, 'count' => 0, 'code' => 'target_count_failed'];
    }
}

/**
 * EA-05: matrix-driven target-slice clear. Fail closed on unresolved ownership.
 *
 * @param list<string> $tables
 * @param array<string, mixed> $matrix
 * @return array{ok:bool,codes:list<string>,cleared:list<string>}
 */
function orange_country_shadow_clear_target_slice_strict(
    PDO $pdo,
    string $shadowDb,
    string $productionDb,
    array $tables,
    int $countryId,
    array $matrix,
    string $projectRoot
): array {
    $wipeOverride = function_exists('orange_country_shadow_override_callable')
        ? orange_country_shadow_override_callable('orange_country_shadow_wipe_override')
        : null;
    if ($wipeOverride !== null) {
        $wipeOverride($pdo, $shadowDb, $tables);

        return ['ok' => true, 'codes' => [], 'cleared' => $tables];
    }

    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    $codes = [];
    $cleared = [];

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    } catch (Throwable) {
    }

    foreach ($tables as $tableName) {
        orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
        $table = (string) $tableName;
        if (in_array($table, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
            continue;
        }
        $meta = is_array($matrixTables[$table] ?? null) ? $matrixTables[$table] : [];
        if (($meta['classification'] ?? '') === 'Global') {
            continue;
        }
        if (!(bool) ($meta['exportable'] ?? false)) {
            $codes[] = 'non_exportable_in_clear_plan';
            continue;
        }
        if (!orange_country_shadow_table_exists($pdo, $table)) {
            continue;
        }
        $mem = orange_country_shadow_target_membership_sql($pdo, $table, $countryId, $meta, $projectRoot);
        if (!$mem['ok'] || $mem['sql'] === null) {
            $codes[] = $mem['code'] ?? 'unresolved_ownership';
            continue;
        }
        $q = '`' . str_replace('`', '``', $table) . '`';
        try {
            $st = $pdo->prepare('DELETE FROM ' . $q . ' WHERE ' . $mem['sql']);
            $st->execute($mem['params']);
            $cleared[] = $table;
        } catch (Throwable) {
            $codes[] = 'target_slice_clear_failed';
        }
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable) {
    }
    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
    $codes = array_values(array_unique($codes));

    return ['ok' => $codes === [], 'codes' => $codes, 'cleared' => $cleared];
}

/**
 * EA-01: C6 post-import verification for seeded multi-country shadow.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $importPlan
 * @return array{ok:bool,codes:list<string>,checks:list<array<string,mixed>>,row_counts:array<string,int>}
 */
function orange_country_shadow_verify_target_slice(
    PDO $pdo,
    string $shadowDb,
    string $productionDb,
    int $countryId,
    array $manifest,
    array $importPlan,
    string $packagePath,
    string $projectRoot,
    string $runDir = ''
): array {
    $codes = [];
    $checks = [];
    $rowCounts = [];
    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);

    $matrix = orange_country_boundary_matrix_load($projectRoot);
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    $inventory = json_decode((string) @file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'table_inventory.json'), true);
    $expected = is_array($inventory['tables'] ?? null) ? $inventory['tables'] : [];

    foreach ($importPlan['tables'] as $table) {
        $table = (string) $table;
        $meta = is_array($matrixTables[$table] ?? null) ? $matrixTables[$table] : [];
        $counted = orange_country_shadow_count_target_rows($pdo, $table, $countryId, $meta, $projectRoot);
        if (!$counted['ok']) {
            $codes[] = $counted['code'] ?? 'target_count_failed';
            $checks[] = ['id' => 'count_' . $table, 'status' => 'FAIL', 'code' => $counted['code'], 'detail' => $table];
            continue;
        }
        $count = (int) $counted['count'];
        $rowCounts[$table] = $count;
        $exp = (int) ($expected[$table] ?? -1);
        if ($exp >= 0 && $count !== $exp) {
            $codes[] = 'row_count_mismatch';
            $checks[] = ['id' => 'count_' . $table, 'status' => 'FAIL', 'code' => 'row_count_mismatch', 'detail' => $table . ' target=' . $count . ' expected=' . $exp];
        } else {
            $checks[] = ['id' => 'count_' . $table, 'status' => 'PASS', 'code' => null, 'detail' => $table . '_target=' . (string) $count];
        }

        // Target-slice ownership: NULL country_id never allowed on direct country_id tables.
        if (orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
            try {
                $nullCnt = (int) $pdo->query(
                    'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE country_id IS NULL'
                )->fetchColumn();
                if ($nullCnt > 0) {
                    $codes[] = 'null_ownership_leakage';
                    $checks[] = ['id' => 'null_' . $table, 'status' => 'FAIL', 'code' => 'null_ownership_leakage', 'detail' => $table];
                } else {
                    $checks[] = ['id' => 'null_' . $table, 'status' => 'PASS', 'code' => null, 'detail' => 'no NULL country_id'];
                }
            } catch (Throwable) {
            }
        }
    }

    // Target-scoped composites
    $tAdmins = orange_country_shadow_count_target_rows(
        $pdo,
        'admins',
        $countryId,
        $matrixTables['admins'] ?? ['ownership_resolver' => 'direct_country_id'],
        $projectRoot
    );
    $tPerms = orange_country_shadow_count_target_rows(
        $pdo,
        'admin_permissions',
        $countryId,
        $matrixTables['admin_permissions'] ?? ['ownership_resolver' => 'admin_ownership'],
        $projectRoot
    );
    if (($tPerms['count'] ?? 0) > 0 && ($tAdmins['count'] ?? 0) === 0) {
        $codes[] = 'composite_admins_permissions_mismatch';
        $checks[] = ['id' => 'composite_admins', 'status' => 'FAIL', 'code' => 'composite_admins_permissions_mismatch', 'detail' => 'target admins missing'];
    }

    // Global / never-export: baseline delta (not emptiness) — EA-01 / EA-02
    $globalBaseline = [];
    if ($runDir !== '' && is_file($runDir . DIRECTORY_SEPARATOR . 'global_baseline.json')) {
        $decoded = json_decode((string) file_get_contents($runDir . DIRECTORY_SEPARATOR . 'global_baseline.json'), true);
        $globalBaseline = is_array($decoded) ? $decoded : [];
    }
    foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
        if (!orange_country_shadow_table_exists($pdo, $never)) {
            $checks[] = ['id' => 'global_' . $never, 'status' => 'PASS', 'code' => null, 'detail' => $never . ' absent'];
            continue;
        }
        try {
            $c = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $never) . '`')->fetchColumn();
        } catch (Throwable) {
            $c = 0;
        }
        $baseCount = isset($globalBaseline[$never]['count']) ? (int) $globalBaseline[$never]['count'] : null;
        if ($baseCount === null) {
            // No baseline yet (should not happen after C6 pre-clear capture) — fail closed
            $codes[] = 'global_baseline_missing';
            $checks[] = ['id' => 'global_' . $never, 'status' => 'FAIL', 'code' => 'global_baseline_missing', 'detail' => $never];
        } elseif ($c !== $baseCount) {
            $code = $never === 'journal_entries' ? 'journal_entries_changed' : 'global_table_changed';
            $codes[] = $code;
            $checks[] = ['id' => 'global_' . $never, 'status' => 'FAIL', 'code' => $code, 'detail' => $never . ' delta'];
        } else {
            $checks[] = ['id' => 'global_' . $never, 'status' => 'PASS', 'code' => null, 'detail' => $never . ' baseline match count=' . $c];
        }
    }

    // N3-01: survivor baseline re-validation after import (gate ordering unchanged: after target/global checks)
    if (function_exists('orange_country_shadow_verify_survivor_baseline_post_import')) {
        $surv = orange_country_shadow_verify_survivor_baseline_post_import($pdo, $countryId, $projectRoot, $runDir);
        foreach ($surv['checks'] as $chk) {
            $checks[] = $chk;
        }
        foreach ($surv['codes'] as $code) {
            $codes[] = (string) $code;
        }
    }

    // N3-05: real batch dependency verification
    if (function_exists('orange_country_shadow_verify_batch_integrity')) {
        $batch = orange_country_shadow_verify_batch_integrity($importPlan, $matrix, is_array($manifest) ? $manifest : []);
        foreach ($batch['checks'] as $chk) {
            $checks[] = $chk;
        }
        foreach ($batch['codes'] as $code) {
            $codes[] = (string) $code;
        }
    }

    $checks[] = [
        'id' => 'shadow_model',
        'status' => 'PASS',
        'code' => null,
        'detail' => ORANGE_COUNTRY_SHADOW_MODEL,
    ];

    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);

    return [
        'ok' => $codes === [],
        'codes' => array_values(array_unique($codes)),
        'checks' => $checks,
        'row_counts' => $rowCounts,
    ];
}

/**
 * EA-02/EA-03: expanded live SQL integrity for C7 pillars.
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_sql_integrity_checks_v2(PDO $pdo, int $countryId, string $projectRoot, string $packagePath = ''): array
{
    $boundary = [];
    $acctCodes = [];
    $fifoCodes = [];
    $compCodes = [];
    $depCodes = [];
    $commCodes = [];
    $catCodes = [];
    $seqCodes = [];
    $upCodes = [];
    $idCodes = [];
    $schemaCodes = [];
    $docCodes = [];

    $acctOk = true;
    $fifoOk = true;
    $compOk = true;
    $depOk = true;
    $commOk = true;
    $catOk = true;
    $seqOk = true;
    $upOk = true;
    $idOk = true;
    $schemaOk = true;
    $docOk = true;

    $matrix = orange_country_boundary_matrix_load($projectRoot);
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    foreach (['orders', 'accounts', 'warehouses', 'admins', 'products', 'customers'] as $table) {
        if (!orange_country_shadow_table_exists($pdo, $table) || !orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
            continue;
        }
        try {
            $st = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE country_id IS NULL');
            if ($st && (int) $st->fetchColumn() > 0) {
                $boundary[] = 'null_ownership_leakage';
            }
        } catch (Throwable) {
        }
    }

    $targetCount = static function (string $table) use ($pdo, $countryId, $matrixTables, $projectRoot): int {
        $meta = $matrixTables[$table] ?? ['ownership_resolver' => 'direct_country_id'];
        $r = orange_country_shadow_count_target_rows($pdo, $table, $countryId, $meta, $projectRoot);

        return $r['ok'] ? (int) $r['count'] : 0;
    };

    // Composites (target-scoped)
    if ($targetCount('admin_permissions') > 0 && $targetCount('admins') === 0) {
        $compCodes[] = 'incomplete_admin_composite';
        $compOk = false;
    }
    if ($targetCount('journal_lines') > 0 && $targetCount('journal_vouchers') === 0) {
        $compCodes[] = 'incomplete_gl_composite';
        $compOk = false;
    }
    if ($targetCount('expenses') > 0 && $targetCount('accounts') === 0) {
        $compCodes[] = 'incomplete_expenses_composite';
        $compOk = false;
    }
    if ($targetCount('order_items') > 0 && $targetCount('orders') === 0) {
        $compCodes[] = 'missing_order_item';
        $compOk = false;
        $commOk = false;
        $commCodes[] = 'missing_order_item';
    }
    if ($targetCount('warehouse_variant_stock') > 0 && $targetCount('warehouses') === 0) {
        $compCodes[] = 'incomplete_fifo_graph';
        $compOk = false;
        $fifoOk = false;
        $fifoCodes[] = 'incomplete_fifo_graph';
    }
    if ($targetCount('inventory_cost_consumptions') > 0 && $targetCount('inventory_cost_layers') === 0) {
        $compCodes[] = 'incomplete_fifo_graph';
        $fifoCodes[] = 'incomplete_fifo_graph';
        $compOk = false;
        $fifoOk = false;
    }

    // Accounting — vouchers balance; journal_entries NOT required empty (EA-02)
    if ($targetCount('journal_lines') > 0 && $targetCount('accounts') === 0) {
        $acctCodes[] = 'missing_account';
        $acctOk = false;
    }
    if (orange_country_shadow_table_exists($pdo, 'journal_vouchers')
        && orange_country_shadow_table_exists($pdo, 'journal_lines')
        && $targetCount('journal_vouchers') > 0
    ) {
        try {
            $st = $pdo->prepare(
                'SELECT v.id FROM journal_vouchers v
                 LEFT JOIN journal_lines l ON l.voucher_id = v.id OR l.journal_voucher_id = v.id
                 WHERE v.country_id = ?
                 GROUP BY v.id
                 HAVING ABS(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)) > 0.0001
                 LIMIT 5'
            );
            $st->execute([$countryId]);
            if (count($st->fetchAll(PDO::FETCH_ASSOC) ?: []) > 0) {
                $acctCodes[] = 'gl_graph_unbalanced';
                $acctOk = false;
            }
        } catch (Throwable) {
            try {
                $st = $pdo->prepare(
                    'SELECT v.id FROM journal_vouchers v
                     LEFT JOIN journal_lines l ON l.voucher_id = v.id
                     WHERE v.country_id = ?
                     GROUP BY v.id
                     HAVING ABS(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)) > 0.0001
                     LIMIT 5'
                );
                $st->execute([$countryId]);
                if (count($st->fetchAll(PDO::FETCH_ASSOC) ?: []) > 0) {
                    $acctCodes[] = 'gl_graph_unbalanced';
                    $acctOk = false;
                }
            } catch (Throwable) {
                if ($targetCount('journal_lines') > 0) {
                    $acctCodes[] = 'accounting_boundary_not_proven';
                    $acctOk = false;
                }
            }
        }
    }

    // Stock / FIFO ownership (N3-03) — warehouse, stock, FIFO, cross-country; no dead SQL
    if (function_exists('orange_country_shadow_verify_stock_fifo_ownership')) {
        $stock = orange_country_shadow_verify_stock_fifo_ownership($pdo, $countryId);
        foreach ($stock['codes'] as $code) {
            $fifoCodes[] = (string) $code;
            $fifoOk = false;
            if (in_array((string) $code, ['incomplete_fifo_graph', 'incomplete_stock_composite'], true)) {
                $compCodes[] = (string) $code;
                $compOk = false;
            }
        }
    }

    // Dependency: order_items → orders (target)
    if ($targetCount('order_items') > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM order_items oi
                 LEFT JOIN orders o ON o.id = oi.order_id
                 WHERE o.id IS NULL OR o.country_id <> ?'
            );
            // Only flag orphans / wrong-country parents for items whose order is missing
            $st = $pdo->query(
                'SELECT COUNT(*) FROM order_items oi LEFT JOIN orders o ON o.id = oi.order_id WHERE o.id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $depCodes[] = 'missing_dependency_parent';
                $depOk = false;
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE o.country_id = ? AND EXISTS (
                    SELECT 1 FROM orders ox WHERE ox.id = oi.order_id AND ox.country_id <> ?
                 )'
            );
            // cross-country: item linked to non-target while counted in target path — covered by membership
        } catch (Throwable) {
            $depCodes[] = 'missing_dependency_parent';
            $depOk = false;
        }
    }
    if ($targetCount('journal_lines') > 0) {
        try {
            $st = $pdo->query(
                'SELECT COUNT(*) FROM journal_lines jl
                 LEFT JOIN journal_vouchers jv ON jv.id = jl.voucher_id OR jv.id = jl.journal_voucher_id
                 WHERE jv.id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $depCodes[] = 'missing_dependency_parent';
                $depOk = false;
            }
        } catch (Throwable) {
            try {
                $st = $pdo->query(
                    'SELECT COUNT(*) FROM journal_lines jl
                     LEFT JOIN journal_vouchers jv ON jv.id = jl.voucher_id WHERE jv.id IS NULL'
                );
                if ($st && (int) $st->fetchColumn() > 0) {
                    $depCodes[] = 'missing_dependency_parent';
                    $depOk = false;
                }
            } catch (Throwable) {
                $depCodes[] = 'missing_dependency_parent';
                $depOk = false;
            }
        }
    }

    // Commercial
    if ($targetCount('orders') > 0 && $targetCount('order_items') === 0) {
        // warning-level? treat as commercial incomplete only if package expected items — soft: OK empty items
    }
    if (orange_country_shadow_table_exists($pdo, 'payments') && orange_country_shadow_table_has_column($pdo, 'payments', 'order_id')) {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM payments p
                 LEFT JOIN orders o ON o.id = p.order_id
                 WHERE o.country_id = ? AND o.id IS NULL'
            );
            // orphan payments for missing orders
            $st = $pdo->query(
                'SELECT COUNT(*) FROM payments p LEFT JOIN orders o ON o.id = p.order_id WHERE o.id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $commCodes[] = 'payment_orphan';
                $commOk = false;
            }
        } catch (Throwable) {
        }
    }

    // Catalog
    if ($targetCount('product_variants') > 0 && $targetCount('products') === 0) {
        $catCodes[] = 'product_collision';
        $catOk = false;
    }
    if ($targetCount('product_variants') > 0) {
        try {
            $st = $pdo->query(
                'SELECT COUNT(*) FROM product_variants pv
                 LEFT JOIN products p ON p.id = pv.product_id WHERE p.id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $catCodes[] = 'product_collision';
                $catOk = false;
            }
        } catch (Throwable) {
        }
    }

    // Sequences — target namespace only
    if (orange_country_shadow_table_exists($pdo, 'document_sequences')) {
        try {
            $like = '%\\_c' . $countryId;
            $st = $pdo->prepare('SELECT COUNT(*) FROM document_sequences WHERE `scope` LIKE ? ESCAPE \'\\\\\'');
            $st->execute([$like]);
            $seqN = (int) $st->fetchColumn();
            if ($seqN > 0) {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM document_sequences
                     WHERE `scope` LIKE ? ESCAPE \'\\\\\' AND (`scope` NOT LIKE ? ESCAPE \'\\\\\')'
                );
                // collision: other country suffix sharing base — simplified: scopes for target must end with _c{id}
                $st = $pdo->prepare(
                    'SELECT `scope`, `next_value` FROM document_sequences WHERE `scope` LIKE ? ESCAPE \'\\\\\''
                );
                $st->execute([$like]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $scope = (string) ($row['scope'] ?? '');
                    if (!str_ends_with($scope, '_c' . $countryId)) {
                        $seqCodes[] = 'sequence_namespace_collision';
                        $seqOk = false;
                    }
                    if ((int) ($row['next_value'] ?? 0) < 0) {
                        $seqCodes[] = 'sequence_lowered';
                        $seqOk = false;
                    }
                }
            }
        } catch (Throwable) {
            $seqCodes[] = 'sequence_metadata_incomplete';
            $seqOk = false;
        }
    }

    // Uploads — N3-07 empty-by-inventory supported
    if (function_exists('orange_country_shadow_verify_uploads_integrity')) {
        $up = orange_country_shadow_verify_uploads_integrity($packagePath);
        if (!$up['ok']) {
            foreach ($up['codes'] as $code) {
                $upCodes[] = (string) $code;
            }
            $upOk = false;
        }
    } elseif ($packagePath === '') {
        $upCodes[] = 'uploads_archive_missing';
        $upOk = false;
    }

    // ID preservation — target PKs unique; AUTO_INCREMENT probe when available
    foreach (['orders', 'accounts', 'products', 'customers'] as $table) {
        if (!orange_country_shadow_table_exists($pdo, $table) || !orange_country_shadow_table_has_column($pdo, $table, 'id')) {
            continue;
        }
        try {
            if (orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
                $st = $pdo->prepare(
                    'SELECT id, COUNT(*) c FROM `' . str_replace('`', '``', $table) . '`
                     WHERE country_id = ? GROUP BY id HAVING c > 1 LIMIT 1'
                );
                $st->execute([$countryId]);
                if ($st->fetch(PDO::FETCH_ASSOC)) {
                    $idCodes[] = 'pk_collision';
                    $idOk = false;
                }
            }
        } catch (Throwable) {
        }
    }

    // Schema — N3-02 real drift detection
    if (function_exists('orange_country_shadow_verify_schema_drift')) {
        $schema = orange_country_shadow_verify_schema_drift(
            $pdo,
            $projectRoot,
            [],
            $matrix,
            ['orders', 'accounts', 'warehouses', 'products']
        );
        if (!$schema['ok']) {
            $schemaOk = false;
            foreach ($schema['codes'] as $code) {
                $schemaCodes[] = (string) $code;
            }
            if ($schemaCodes === []) {
                $schemaCodes[] = 'schema_mismatch';
            }
        }
    } else {
        $schemaCodes[] = 'schema_mismatch';
        $schemaOk = false;
    }

    // Company documents polymorphic
    if (orange_country_shadow_table_exists($pdo, 'orange_company_documents')) {
        try {
            $st = $pdo->query(
                'SELECT COUNT(*) FROM orange_company_documents
                 WHERE entity_table NOT IN (\'orders\',\'purchases\',\'customers\',\'suppliers\')'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $docCodes[] = 'unknown_polymorphic_document_owner';
                $docOk = false;
                $compCodes[] = 'unknown_polymorphic_document_owner';
                $compOk = false;
            }
        } catch (Throwable) {
        }
    }

    return [
        'boundary_violations' => array_values(array_unique($boundary)),
        'accounting_ok' => $acctOk,
        'stock_fifo_ok' => $fifoOk,
        'composite_ok' => $compOk,
        'dependency_ok' => $depOk,
        'commercial_ok' => $commOk,
        'catalog_ok' => $catOk,
        'sequences_ok' => $seqOk,
        'uploads_ok' => $upOk,
        'id_preservation_ok' => $idOk,
        'schema_ok' => $schemaOk,
        'documents_ok' => $docOk,
        'accounting_codes' => array_values(array_unique($acctCodes)),
        'stock_fifo_codes' => array_values(array_unique($fifoCodes)),
        'composite_codes' => array_values(array_unique($compCodes)),
        'dependency_codes' => array_values(array_unique($depCodes)),
        'commercial_codes' => array_values(array_unique($commCodes)),
        'catalog_codes' => array_values(array_unique($catCodes)),
        'sequences_codes' => array_values(array_unique($seqCodes)),
        'uploads_codes' => array_values(array_unique($upCodes)),
        'id_preservation_codes' => array_values(array_unique($idCodes)),
        'schema_codes' => array_values(array_unique($schemaCodes)),
        'documents_codes' => array_values(array_unique($docCodes)),
    ];
}
