<?php

declare(strict_types=1);

/**
 * Country Shadow integrity helpers (Remediation Sprint 1 + Sprint 2).
 *
 * Shadow model: seeded_multicountry_target_slice
 * - Target-slice clear only (never full-table wipe of mutate tables)
 * - Global / never-export tables never cleared; proven by baseline delta
 * - Live survivor/global baselines + probes
 * - Read-only SQL integrity pillars (accounting/FIFO/composites + EA-03)
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/restore_country_shadow_ea.php';

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
    string $projectRoot,
    string $packagePath = ''
): array {
    if (isset($GLOBALS['orange_country_shadow_c7_probe_override']) && is_callable($GLOBALS['orange_country_shadow_c7_probe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_c7_probe_override'];
        $probe = $fn($projectRoot, [], '', []);

        return is_array($probe) ? $probe : [];
    }

    $baselines = orange_country_shadow_capture_live_baselines($pdo, $countryId, $projectRoot);
    $sql = orange_country_shadow_sql_integrity_checks($pdo, $countryId, $projectRoot, $packagePath);

    return [
        'probe_mode' => 'live',
        'survivor_current' => $baselines['survivor'],
        'global_current' => $baselines['global'],
        'boundary_violations' => $sql['boundary_violations'],
        'sql_checks' => $sql,
        'accounting_ok' => $sql['accounting_ok'],
        'stock_fifo_ok' => $sql['stock_fifo_ok'],
        'composite_ok' => $sql['composite_ok'],
        'dependency_ok' => $sql['dependency_ok'],
        'commercial_ok' => $sql['commercial_ok'],
        'catalog_ok' => $sql['catalog_ok'],
        'sequences_ok' => $sql['sequences_ok'],
        'uploads_ok' => $sql['uploads_ok'],
        'id_preservation_ok' => $sql['id_preservation_ok'],
        'schema_ok' => $sql['schema_ok'],
        'documents_ok' => $sql['documents_ok'],
        'accounting_codes' => $sql['accounting_codes'],
        'stock_fifo_codes' => $sql['stock_fifo_codes'],
        'composite_codes' => $sql['composite_codes'],
        'dependency_codes' => $sql['dependency_codes'],
        'commercial_codes' => $sql['commercial_codes'],
        'catalog_codes' => $sql['catalog_codes'],
        'sequences_codes' => $sql['sequences_codes'],
        'uploads_codes' => $sql['uploads_codes'],
        'id_preservation_codes' => $sql['id_preservation_codes'],
        'schema_codes' => $sql['schema_codes'],
        'documents_codes' => $sql['documents_codes'],
    ];
}

/**
 * Read-only SQL integrity (F-03).
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_sql_integrity_checks(
    PDO $pdo,
    int $countryId,
    string $projectRoot = '',
    string $packagePath = ''
): array {
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__, 3);
    }

    return orange_country_shadow_sql_integrity_checks_v2($pdo, $countryId, $projectRoot, $packagePath);
}

/**
 * Target-slice clear (EA-05). Matrix ownership resolvers only. Fail closed.
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
    array $matrix,
    string $projectRoot = ''
): void {
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__, 3);
    }
    $result = orange_country_shadow_clear_target_slice_strict(
        $pdo,
        $shadowDb,
        $productionDb,
        $tables,
        $countryId,
        $matrix,
        $projectRoot
    );
    if (!$result['ok']) {
        throw new RuntimeException((string) (($result['codes'][0] ?? 'unresolved_ownership')));
    }
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
