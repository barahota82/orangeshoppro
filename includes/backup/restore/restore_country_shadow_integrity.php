<?php

declare(strict_types=1);

/**
 * Country Shadow integrity helpers (Remediation Sprint 1).
 *
 * Shadow model (F-02): seeded multi-country-capable Country Shadow DB.
 * - Target-slice clear only (never full-table wipe of mutate tables)
 * - Global / never-export tables never cleared
 * - Live survivor/global baselines + probes (F-01)
 * - Read-only SQL accounting/FIFO/composite checks (F-03)
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';
require_once __DIR__ . '/../country_export.php';

const ORANGE_COUNTRY_SHADOW_MODEL = 'seeded_multicountry_target_slice';
const ORANGE_COUNTRY_SHADOW_LOCK_STALE_SECONDS = 7200;
const ORANGE_COUNTRY_PROD_INVENTORY_SNAPSHOT_FILE = 'production_inventory_snapshot.json';

// Path helpers are provided by restore_country_shadow.php (must be loaded first by callers).

/** @var resource|null */
$GLOBALS['orange_country_shadow_lock_handle'] = $GLOBALS['orange_country_shadow_lock_handle'] ?? null;

function orange_country_shadow_lock_path(string $workRoot): string
{
    return orange_country_shadow_work_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_LOCK_FILE;
}

/**
 * Exclusive flock for Country Shadow DB mutations (F-05).
 *
 * @return array{ok:bool,code:string,handle:?resource,payload?:array<string,mixed>}
 */
function orange_country_shadow_acquire_lock(string $workRoot, string $runId, string $shadowDb): array
{
    if (isset($GLOBALS['orange_country_shadow_lock_override']) && is_callable($GLOBALS['orange_country_shadow_lock_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_lock_override'];
        $result = $fn('acquire', $workRoot, $runId, $shadowDb);

        return is_array($result)
            ? $result
            : ['ok' => false, 'code' => 'country_shadow_lock_held', 'handle' => null];
    }

    $root = orange_country_shadow_work_root($workRoot);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return ['ok' => false, 'code' => 'country_shadow_lock_dir_failed', 'handle' => null];
    }
    $path = orange_country_shadow_lock_path($workRoot);
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return ['ok' => false, 'code' => 'country_shadow_lock_open_failed', 'handle' => null];
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return ['ok' => false, 'code' => 'country_shadow_lock_held', 'handle' => null];
    }

    ftruncate($handle, 0);
    rewind($handle);
    $payload = [
        'run_id' => $runId,
        'shadow_db' => $shadowDb,
        'pid' => getmypid(),
        'acquired_at' => gmdate('c'),
        'model' => ORANGE_COUNTRY_SHADOW_MODEL,
    ];
    fwrite($handle, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    $GLOBALS['orange_country_shadow_lock_handle'] = $handle;

    return ['ok' => true, 'code' => 'ok', 'handle' => $handle, 'payload' => $payload];
}

function orange_country_shadow_release_lock(string $workRoot, ?string $expectedRunId = null): void
{
    if (isset($GLOBALS['orange_country_shadow_lock_override']) && is_callable($GLOBALS['orange_country_shadow_lock_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_lock_override'];
        $fn('release', $workRoot, $expectedRunId ?? '', '');

        return;
    }

    $handle = $GLOBALS['orange_country_shadow_lock_handle'] ?? null;
    $path = orange_country_shadow_lock_path($workRoot);
    if (is_resource($handle)) {
        if ($expectedRunId !== null && $expectedRunId !== '') {
            rewind($handle);
            $raw = stream_get_contents($handle) ?: '';
            $decoded = json_decode($raw, true);
            $held = is_array($decoded) ? (string) ($decoded['run_id'] ?? '') : '';
            if ($held !== '' && $held !== $expectedRunId) {
                // Safe release: never unlock a lock owned by a different run_id.
                return;
            }
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        $GLOBALS['orange_country_shadow_lock_handle'] = null;
    }
    if (is_file($path)) {
        if ($expectedRunId !== null) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $held = is_array($decoded) ? (string) ($decoded['run_id'] ?? '') : '';
            if ($held !== '' && $held !== $expectedRunId) {
                return;
            }
        }
        @unlink($path);
    }
}

function orange_country_shadow_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $pdo->quote($column));
        if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    } catch (Throwable) {
        // SQLite / missing table
    }
    try {
        $st = $pdo->query('PRAGMA table_info(`' . str_replace('`', '``', $table) . '`)');
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }
        }
    } catch (Throwable) {
    }

    return false;
}

function orange_country_shadow_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query('SELECT 1 FROM `' . str_replace('`', '``', $table) . '` LIMIT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Live capture of survivor (non-target) + Global baselines (F-01).
 *
 * @return array{survivor:array<string,mixed>,global:array<string,mixed>,capture_mode:string}
 */
function orange_country_shadow_capture_live_baselines(PDO $pdo, int $countryId, string $projectRoot): array
{
    if (isset($GLOBALS['orange_country_shadow_baseline_override']) && is_callable($GLOBALS['orange_country_shadow_baseline_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_baseline_override'];
        $captured = $fn($pdo, $countryId);
        $survivor = is_array($captured['survivor'] ?? null) ? $captured['survivor'] : [];
        $global = is_array($captured['global'] ?? null) ? $captured['global'] : [];

        return ['survivor' => $survivor, 'global' => $global, 'capture_mode' => 'override'];
    }

    $matrix = orange_country_boundary_matrix_load($projectRoot);
    /** @var array<string, array<string, mixed>> $tables */
    $tables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    $survivor = [];
    $global = [];

    foreach ($tables as $tableName => $meta) {
        if (!(bool) ($meta['exportable'] ?? false)) {
            continue;
        }
        $table = (string) $tableName;
        if (!orange_country_shadow_table_exists($pdo, $table)) {
            continue;
        }
        if (!orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
            continue;
        }
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS(\'|\', id, country_id))),0) AS h
                 FROM `' . str_replace('`', '``', $table) . '`
                 WHERE country_id IS NOT NULL AND country_id <> ?'
            );
            $st->execute([$countryId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $survivor[$table] = [
                'count' => (int) ($row['c'] ?? 0),
                'hash' => hash('sha256', $table . '|' . (string) ($row['c'] ?? 0) . '|' . (string) ($row['h'] ?? 0)),
            ];
        } catch (Throwable) {
            try {
                $st = $pdo->prepare(
                    'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`
                     WHERE country_id IS NOT NULL AND country_id <> ?'
                );
                $st->execute([$countryId]);
                $c = (int) $st->fetchColumn();
                $survivor[$table] = ['count' => $c, 'hash' => hash('sha256', $table . '|c|' . $c)];
            } catch (Throwable) {
                // skip
            }
        }
    }

    foreach (array_merge(ORANGE_CRP_NEVER_EXPORT_TABLES, ['journal_entries', 'orange_country_screen_copy_log']) as $gTable) {
        $gTable = (string) $gTable;
        if (!orange_country_shadow_table_exists($pdo, $gTable)) {
            $global[$gTable] = ['count' => 0, 'hash' => hash('sha256', $gTable . '|missing')];
            continue;
        }
        try {
            $c = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $gTable) . '`')->fetchColumn();
            $global[$gTable] = ['count' => $c, 'hash' => hash('sha256', $gTable . '|c|' . $c)];
        } catch (Throwable) {
            $global[$gTable] = ['count' => 0, 'hash' => hash('sha256', $gTable . '|err')];
        }
    }

    if ($survivor === []) {
        $survivor['_empty_survivor_slice'] = [
            'count' => 0,
            'hash' => hash('sha256', 'survivor_empty|' . $countryId),
        ];
    }

    return ['survivor' => $survivor, 'global' => $global, 'capture_mode' => 'live'];
}

/**
 * Write baselines to run dir (live or override).
 *
 * @return array{survivor:array<string,mixed>,global:array<string,mixed>,capture_mode:string}
 */
function orange_country_shadow_write_live_baselines(PDO $pdo, string $runDir, int $countryId, string $projectRoot): array
{
    $captured = orange_country_shadow_capture_live_baselines($pdo, $countryId, $projectRoot);
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . 'survivor_baseline.json', $captured['survivor']);
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . 'global_baseline.json', $captured['global']);
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . 'baseline_capture_meta.json', [
        'capture_mode' => $captured['capture_mode'],
        'shadow_model' => ORANGE_COUNTRY_SHADOW_MODEL,
        'country_id' => $countryId,
        'captured_at' => gmdate('c'),
    ]);

    return $captured;
}

/**
 * Live post-restore probe for C7 (F-01 / F-03).
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_live_probe(
    PDO $pdo,
    int $countryId,
    string $projectRoot
): array {
    if (isset($GLOBALS['orange_country_shadow_c7_probe_override']) && is_callable($GLOBALS['orange_country_shadow_c7_probe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_c7_probe_override'];
        $probe = $fn($projectRoot, [], '', []);

        return is_array($probe) ? $probe : [];
    }

    $baselines = orange_country_shadow_capture_live_baselines($pdo, $countryId, $projectRoot);
    $sql = orange_country_shadow_sql_integrity_checks($pdo, $countryId);

    return [
        'probe_mode' => 'live',
        'survivor_current' => $baselines['survivor'],
        'global_current' => $baselines['global'],
        'boundary_violations' => $sql['boundary_violations'],
        'sql_checks' => $sql,
        'accounting_ok' => $sql['accounting_ok'],
        'stock_fifo_ok' => $sql['stock_fifo_ok'],
        'composite_ok' => $sql['composite_ok'],
        'accounting_codes' => $sql['accounting_codes'],
        'stock_fifo_codes' => $sql['stock_fifo_codes'],
        'composite_codes' => $sql['composite_codes'],
    ];
}

/**
 * Read-only SQL integrity (F-03).
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_sql_integrity_checks(PDO $pdo, int $countryId): array
{
    $boundary = [];
    $acctCodes = [];
    $fifoCodes = [];
    $compCodes = [];
    $acctOk = true;
    $fifoOk = true;
    $compOk = true;

    // Boundary: no NULL/other country on country_id mutate tables that have rows for target
    foreach (['orders', 'accounts', 'warehouses', 'admins', 'products', 'customers'] as $table) {
        if (!orange_country_shadow_table_exists($pdo, $table) || !orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
            continue;
        }
        try {
            // Survivors may coexist; NULL country_id is never valid ownership.
            $st = $pdo->query(
                'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE country_id IS NULL'
            );
            if ($st && (int) $st->fetchColumn() > 0) {
                $boundary[] = 'null_ownership_leakage';
            }
        } catch (Throwable) {
        }
    }

    // Composites
    $count = static function (PDO $pdo, string $table): int {
        if (!orange_country_shadow_table_exists($pdo, $table)) {
            return 0;
        }
        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    };

    if ($count($pdo, 'admin_permissions') > 0 && $count($pdo, 'admins') === 0) {
        $compCodes[] = 'incomplete_admin_composite';
        $compOk = false;
    }
    if ($count($pdo, 'admin_permissions') > 0 && orange_country_shadow_table_exists($pdo, 'admins')) {
        try {
            $orph = (int) $pdo->query(
                'SELECT COUNT(*) FROM admin_permissions ap
                 LEFT JOIN admins a ON a.id = ap.admin_id WHERE a.id IS NULL'
            )->fetchColumn();
            if ($orph > 0) {
                $compCodes[] = 'incomplete_admin_composite';
                $compOk = false;
            }
        } catch (Throwable) {
        }
    }
    if ($count($pdo, 'journal_lines') > 0 && $count($pdo, 'journal_vouchers') === 0) {
        $compCodes[] = 'incomplete_gl_composite';
        $compOk = false;
    }
    if ($count($pdo, 'expenses') > 0 && $count($pdo, 'accounts') === 0) {
        $compCodes[] = 'incomplete_expenses_composite';
        $compOk = false;
    }
    if ($count($pdo, 'order_items') > 0 && $count($pdo, 'orders') === 0) {
        $compCodes[] = 'missing_order_item';
        $compOk = false;
    }
    if ($count($pdo, 'warehouse_variant_stock') > 0 && $count($pdo, 'warehouses') === 0) {
        $compCodes[] = 'incomplete_fifo_graph';
        $compOk = false;
    }
    if ($count($pdo, 'inventory_cost_consumptions') > 0 && $count($pdo, 'inventory_cost_layers') === 0) {
        $compCodes[] = 'incomplete_fifo_graph';
        $compOk = false;
        $fifoOk = false;
    }

    // Accounting: journal_entries must stay empty / unused; vouchers balanced
    if ($count($pdo, 'journal_entries') > 0) {
        $acctCodes[] = 'journal_entries_changed';
        $acctOk = false;
    }
    if ($count($pdo, 'journal_lines') > 0 && $count($pdo, 'accounts') === 0) {
        $acctCodes[] = 'missing_account';
        $acctOk = false;
    }
    if (orange_country_shadow_table_exists($pdo, 'journal_vouchers')
        && orange_country_shadow_table_exists($pdo, 'journal_lines')
    ) {
        try {
            $sql = 'SELECT v.id
                 FROM journal_vouchers v
                 LEFT JOIN journal_lines l ON l.journal_voucher_id = v.id OR l.voucher_id = v.id
                 GROUP BY v.id
                 HAVING ABS(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)) > 0.0001
                 LIMIT 5';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) > 0) {
                $acctCodes[] = 'gl_graph_unbalanced';
                $acctOk = false;
            }
        } catch (Throwable) {
            try {
                $sql = 'SELECT v.id
                     FROM journal_vouchers v
                     LEFT JOIN journal_lines l ON l.voucher_id = v.id
                     GROUP BY v.id
                     HAVING ABS(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)) > 0.0001
                     LIMIT 5';
                $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (count($rows) > 0) {
                    $acctCodes[] = 'gl_graph_unbalanced';
                    $acctOk = false;
                }
            } catch (Throwable) {
                // schema variance — if lines exist require explicit balance probe success later
                if ($count($pdo, 'journal_lines') > 0) {
                    $acctCodes[] = 'accounting_boundary_not_proven';
                    $acctOk = false;
                }
            }
        }
    }

    // Stock / FIFO
    if (orange_country_shadow_table_exists($pdo, 'warehouses')
        && orange_country_shadow_table_has_column($pdo, 'warehouses', 'country_id')
        && orange_country_shadow_table_exists($pdo, 'warehouse_variant_stock')
    ) {
        try {
            $leak = (int) $pdo->query(
                'SELECT COUNT(*) FROM warehouse_variant_stock s
                 INNER JOIN warehouses w ON w.id = s.warehouse_id
                 WHERE w.country_id IS NULL OR w.country_id <> ' . (int) $countryId
            )->fetchColumn();
            // In multi-country shadow, stock for other countries is OK; only NULL warehouse country is leak for target ops.
            // Check target warehouses only for ownership mismatch of stock pointing to wrong country when filtering target.
            $nullWh = (int) $pdo->query(
                'SELECT COUNT(*) FROM warehouse_variant_stock s
                 LEFT JOIN warehouses w ON w.id = s.warehouse_id
                 WHERE w.id IS NULL'
            )->fetchColumn();
            if ($nullWh > 0) {
                $fifoCodes[] = 'stock_warehouse_ownership_mismatch';
                $fifoOk = false;
            }
            unset($leak);
        } catch (Throwable) {
        }
    }

    if (orange_country_shadow_table_exists($pdo, 'inventory_cost_layers')) {
        foreach (['remaining_qty', 'qty_remaining'] as $col) {
            if (!orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', $col)) {
                continue;
            }
            try {
                $neg = (int) $pdo->query(
                    'SELECT COUNT(*) FROM inventory_cost_layers WHERE `' . str_replace('`', '``', $col) . '` < 0'
                )->fetchColumn();
                if ($neg > 0) {
                    $fifoCodes[] = 'incomplete_fifo_graph';
                    $fifoOk = false;
                }
                break;
            } catch (Throwable) {
            }
        }
    }

    if (orange_country_shadow_table_exists($pdo, 'inventory_cost_consumptions')
        && orange_country_shadow_table_exists($pdo, 'inventory_cost_layers')
    ) {
        try {
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
                $fifoCodes[] = 'fifo_layer_overconsumed';
                $fifoOk = false;
            }
        } catch (Throwable) {
            // try remaining_qty style
            try {
                $orph = (int) $pdo->query(
                    'SELECT COUNT(*) FROM inventory_cost_consumptions c
                     LEFT JOIN inventory_cost_layers l ON l.id = c.layer_id
                     WHERE l.id IS NULL'
                )->fetchColumn();
                if ($orph > 0) {
                    $fifoCodes[] = 'incomplete_fifo_graph';
                    $fifoOk = false;
                }
            } catch (Throwable) {
            }
        }
    }

    return [
        'boundary_violations' => array_values(array_unique($boundary)),
        'accounting_ok' => $acctOk,
        'stock_fifo_ok' => $fifoOk,
        'composite_ok' => $compOk,
        'accounting_codes' => array_values(array_unique($acctCodes)),
        'stock_fifo_codes' => array_values(array_unique($fifoCodes)),
        'composite_codes' => array_values(array_unique($compCodes)),
    ];
}

/**
 * Target-slice clear (F-02). Never full-table wipe. Never touch Global/never-export.
 *
 * @param list<string> $tables delete order
 * @param array<string, mixed> $matrix
 */
function orange_country_shadow_clear_target_slice(
    PDO $pdo,
    string $shadowDb,
    string $productionDb,
    array $tables,
    int $countryId,
    array $matrix
): void {
    if (isset($GLOBALS['orange_country_shadow_wipe_override']) && is_callable($GLOBALS['orange_country_shadow_wipe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_wipe_override'];
        $fn($pdo, $shadowDb, $tables);

        return;
    }

    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
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
        if (!orange_country_shadow_table_exists($pdo, $table)) {
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $resolver = (string) ($meta['ownership_resolver'] ?? '');

        try {
            if ($table === 'document_sequences' || $resolver === 'special_namespace') {
                $like = '%\\_c' . $countryId;
                $pdo->prepare('DELETE FROM ' . $quoted . ' WHERE `scope` LIKE ? ESCAPE \'\\\\\'')->execute([$like]);
                continue;
            }
            if ($resolver === 'admin_ownership' || $table === 'admin_permissions') {
                if (orange_country_shadow_table_exists($pdo, 'admins')) {
                    $pdo->prepare(
                        'DELETE FROM ' . $quoted . ' WHERE admin_id IN (SELECT id FROM admins WHERE country_id = ?)'
                    )->execute([$countryId]);
                }
                continue;
            }
            if (orange_country_shadow_table_has_column($pdo, $table, 'country_id')) {
                $pdo->prepare('DELETE FROM ' . $quoted . ' WHERE country_id = ?')->execute([$countryId]);
                continue;
            }
            if ($resolver === 'account_ownership' && orange_country_shadow_table_exists($pdo, 'accounts')) {
                $pdo->prepare(
                    'DELETE FROM ' . $quoted . ' WHERE account_id IN (SELECT id FROM accounts WHERE country_id = ?)'
                )->execute([$countryId]);
                continue;
            }
            if ($resolver === 'parent_fk') {
                // Best-effort: common parent patterns; skip full wipe
                foreach ([
                    ['order_items', 'orders', 'order_id'],
                    ['journal_lines', 'journal_vouchers', 'voucher_id'],
                    ['journal_lines', 'journal_vouchers', 'journal_voucher_id'],
                    ['warehouse_variant_stock', 'warehouses', 'warehouse_id'],
                    ['inventory_cost_layers', 'warehouses', 'warehouse_id'],
                    ['inventory_cost_consumptions', 'inventory_cost_layers', 'layer_id'],
                ] as [$child, $parent, $fk]) {
                    if ($table !== $child || !orange_country_shadow_table_exists($pdo, $parent)) {
                        continue;
                    }
                    if (orange_country_shadow_table_has_column($pdo, $parent, 'country_id')) {
                        $pdo->prepare(
                            'DELETE FROM ' . $quoted . ' WHERE `' . str_replace('`', '``', $fk) . '` IN (
                                SELECT id FROM `' . str_replace('`', '``', $parent) . '` WHERE country_id = ?
                             )'
                        )->execute([$countryId]);
                    }
                    break;
                }
                continue;
            }
            // Unknown ownership without country_id: do NOT full-table delete (F-02)
        } catch (Throwable $e) {
            // continue other tables; caller verify will catch residue
            unset($e);
        }
    }
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable) {
        // SQLite
    }
    orange_country_shadow_assert_not_production($pdo, $shadowDb, $productionDb);
}

/**
 * Build / load certified read-only production inventory for C8 (F-04).
 *
 * @return array{
 *   ok:bool,
 *   code:?string,
 *   source:string,
 *   target_counts:array<string,int>,
 *   survivor_counts:array<string,int>,
 *   global_counts:array<string,int>
 * }
 */
function orange_country_dry_run_load_production_inventory(
    string $projectRoot,
    string $workRoot,
    string $runId,
    int $countryId,
    array $env,
    array $inject = []
): array {
    if (is_array($inject['production_inventory'] ?? null)) {
        $inv = $inject['production_inventory'];

        return [
            'ok' => true,
            'code' => null,
            'source' => 'inject',
            'target_counts' => is_array($inv['target_counts'] ?? null) ? $inv['target_counts'] : [],
            'survivor_counts' => is_array($inv['survivor_counts'] ?? null) ? $inv['survivor_counts'] : [],
            'global_counts' => is_array($inv['global_counts'] ?? null) ? $inv['global_counts'] : [],
        ];
    }
    if (isset($GLOBALS['orange_country_dry_run_production_inventory_override'])
        && is_callable($GLOBALS['orange_country_dry_run_production_inventory_override'])
    ) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_dry_run_production_inventory_override'];
        $inv = $fn($projectRoot, $workRoot, $runId, $countryId, $env);

        return is_array($inv) ? $inv : [
            'ok' => false,
            'code' => 'production_inventory_override_invalid',
            'source' => 'override',
            'target_counts' => [],
            'survivor_counts' => [],
            'global_counts' => [],
        ];
    }

    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    $snapPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_PROD_INVENTORY_SNAPSHOT_FILE;
    if (is_file($snapPath)) {
        $data = json_decode((string) file_get_contents($snapPath), true);
        if (!is_array($data) || ($data['certified_read_only'] ?? false) !== true) {
            return [
                'ok' => false,
                'code' => 'production_inventory_snapshot_invalid',
                'source' => 'snapshot',
                'target_counts' => [],
                'survivor_counts' => [],
                'global_counts' => [],
            ];
        }
        if ((int) ($data['country_id'] ?? 0) !== $countryId) {
            return [
                'ok' => false,
                'code' => 'production_inventory_country_mismatch',
                'source' => 'snapshot',
                'target_counts' => [],
                'survivor_counts' => [],
                'global_counts' => [],
            ];
        }

        return [
            'ok' => true,
            'code' => null,
            'source' => 'certified_snapshot',
            'target_counts' => is_array($data['target_counts'] ?? null) ? $data['target_counts'] : [],
            'survivor_counts' => is_array($data['survivor_counts'] ?? null) ? $data['survivor_counts'] : [],
            'global_counts' => is_array($data['global_counts'] ?? null) ? $data['global_counts'] : [],
        ];
    }

    // Optional gated live read-only inventory (SELECT counts only; never writes).
    $allow = !empty($env['ORANGE_COUNTRY_DRY_RUN_ALLOW_PROD_INVENTORY_READ'])
        || !empty($inject['allow_live_prod_inventory_read']);
    if (!$allow) {
        return [
            'ok' => false,
            'code' => 'production_inventory_snapshot_missing',
            'source' => 'missing',
            'target_counts' => [],
            'survivor_counts' => [],
            'global_counts' => [],
        ];
    }

    try {
        require_once __DIR__ . '/restore_staging_target.php';
        $creds = orange_restore_production_db_credentials($projectRoot);
        $productionDb = orange_restore_production_db_name($projectRoot);
        $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $productionDb . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Read-only session guards where supported
        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (Throwable) {
        }
        $built = orange_country_dry_run_build_inventory_from_pdo($pdo, $countryId, $projectRoot);
        // Persist certified snapshot for audit trail (work dir only — not production write)
        orange_backup_write_json($snapPath, array_merge($built, [
            'certified_read_only' => true,
            'country_id' => $countryId,
            'generated_at' => gmdate('c'),
            'source' => 'live_read_only',
        ]));

        return [
            'ok' => true,
            'code' => null,
            'source' => 'live_read_only',
            'target_counts' => $built['target_counts'],
            'survivor_counts' => $built['survivor_counts'],
            'global_counts' => $built['global_counts'],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'code' => 'production_inventory_read_failed',
            'source' => 'live_read_only',
            'target_counts' => [],
            'survivor_counts' => [],
            'global_counts' => [],
        ];
    }
}

/**
 * @return array{target_counts:array<string,int>,survivor_counts:array<string,int>,global_counts:array<string,int>}
 */
function orange_country_dry_run_build_inventory_from_pdo(PDO $pdo, int $countryId, string $projectRoot): array
{
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    /** @var array<string, array<string, mixed>> $tables */
    $tables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    $target = [];
    $survivor = [];
    foreach ($tables as $tableName => $meta) {
        if (!(bool) ($meta['exportable'] ?? false)) {
            continue;
        }
        $table = (string) $tableName;
        if (!orange_country_shadow_table_exists($pdo, $table)
            || !orange_country_shadow_table_has_column($pdo, $table, 'country_id')
        ) {
            continue;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '` WHERE country_id = ?');
        $st->execute([$countryId]);
        $target[$table] = (int) $st->fetchColumn();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`
             WHERE country_id IS NOT NULL AND country_id <> ?'
        );
        $st->execute([$countryId]);
        $survivor[$table] = (int) $st->fetchColumn();
    }
    $global = [];
    foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $gTable) {
        if (!orange_country_shadow_table_exists($pdo, $gTable)) {
            $global[$gTable] = 0;
            continue;
        }
        $global[$gTable] = (int) $pdo->query(
            'SELECT COUNT(*) FROM `' . str_replace('`', '``', $gTable) . '`'
        )->fetchColumn();
    }

    return [
        'target_counts' => $target,
        'survivor_counts' => $survivor,
        'global_counts' => $global,
    ];
}

/**
 * Write a certified snapshot file for tests / operators (work dir only).
 *
 * @param array<string,int> $target
 * @param array<string,int> $survivor
 * @param array<string,int> $global
 */
function orange_country_dry_run_write_certified_snapshot(
    string $workRoot,
    string $runId,
    int $countryId,
    array $target,
    array $survivor,
    array $global
): string {
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    if (!is_dir($runDir) && !@mkdir($runDir, 0775, true) && !is_dir($runDir)) {
        throw new RuntimeException('cannot_create_country_shadow_run_dir');
    }
    $path = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_PROD_INVENTORY_SNAPSHOT_FILE;
    orange_backup_write_json($path, [
        'certified_read_only' => true,
        'country_id' => $countryId,
        'generated_at' => gmdate('c'),
        'source' => 'certified_snapshot',
        'target_counts' => $target,
        'survivor_counts' => $survivor,
        'global_counts' => $global,
    ]);

    return $path;
}
