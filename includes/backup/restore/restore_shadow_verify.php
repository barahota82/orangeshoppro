<?php

declare(strict_types=1);

/**
 * Phase 3B.3B5 — Shadow Database Verification & Production Readiness.
 *
 * Deep structural/functional checks against an already-restored shadow DB.
 * Never modifies production, never cutover, never maintenance, never file restore.
 *
 * Reuses shadow connect/inventory helpers from restore_shadow_db.php (read-only).
 */

require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';

const ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION = '3B.3B5-v1';
const ORANGE_RESTORE_SHADOW_VERIFY_REPORT_FILE = 'shadow_verification_report.json';
const ORANGE_RESTORE_SHADOW_VERIFY_META_FILE = 'shadow_verification.json';
const ORANGE_RESTORE_SHADOW_VERIFY_LOCK_FILE = '.shadow_verify.lock';
const ORANGE_RESTORE_SHADOW_VERIFY_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_SHADOW_VERIFY_CHECKSUM_TABLE_LIMIT = 40;
const ORANGE_RESTORE_SHADOW_VERIFY_SCORE_READY = 85;
const ORANGE_RESTORE_SHADOW_VERIFY_SCORE_WARNING = 60;

function orange_restore_shadow_verify_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_VERIFY_REPORT_FILE;
}

function orange_restore_shadow_verify_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_VERIFY_META_FILE;
}

function orange_restore_shadow_verify_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_VERIFY_LOCK_FILE;
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool}
 */
function orange_restore_shadow_verify_lock_status(string $workRoot): array
{
    $path = orange_restore_shadow_verify_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'stale' => false];
    }
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        return ['held' => true, 'payload' => null, 'stale' => true];
    }
    $acquiredAt = strtotime((string) ($payload['acquired_at'] ?? ''));
    $age = $acquiredAt !== false ? (time() - $acquiredAt) : PHP_INT_MAX;
    $pid = (int) ($payload['pid'] ?? 0);
    $pidAlive = null;
    if ($pid > 0 && function_exists('posix_kill')) {
        $pidAlive = @posix_kill($pid, 0);
    }
    $stale = $age > ORANGE_RESTORE_SHADOW_VERIFY_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/**
 * @return array{ok:bool,message:string}
 */
function orange_restore_shadow_verify_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_shadow_verify_lock_path($workRoot);
    $status = orange_restore_shadow_verify_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_shadow_verify_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        $held = (string) (($status['payload'] ?? [])['job_id'] ?? '');
        if ($held === $jobId) {
            return ['ok' => true, 'message' => 'lock_already_held'];
        }

        return ['ok' => false, 'message' => 'shadow_verify_lock_active'];
    }
    $payload = json_encode([
        'job_id' => $jobId,
        'owner' => $owner,
        'pid' => getmypid(),
        'acquired_at' => gmdate('c'),
        'heartbeat_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false || $payload === false) {
        return ['ok' => false, 'message' => 'shadow_verify_lock_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok'];
}

function orange_restore_shadow_verify_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_shadow_verify_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $held = is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
        if ($held !== '' && $held !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

/**
 * @param array<string, mixed> $record
 */
function orange_restore_shadow_verify_write_json(string $path, array $record): void
{
    unset($record['absolute_paths'], $record['package_path'], $record['dump_path'], $record['password'], $record['secrets']);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write shadow verification metadata.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_verify_load_meta(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_verify_meta_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_verify_load_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_verify_report_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function orange_restore_shadow_verify_public_meta(array $meta): array
{
    unset($meta['absolute_paths'], $meta['package_path'], $meta['dump_path'], $meta['password'], $meta['secrets']);

    return [
        'record_version' => (string) ($meta['record_version'] ?? ''),
        'framework_job_id' => (string) ($meta['framework_job_id'] ?? ''),
        'source_package_id' => (string) ($meta['source_package_id'] ?? ''),
        'shadow_db' => (string) ($meta['shadow_db'] ?? ''),
        'production_db' => (string) ($meta['production_db'] ?? ''),
        'status' => (string) ($meta['status'] ?? ''),
        'created_at' => (string) ($meta['created_at'] ?? ''),
        'created_by' => (string) ($meta['created_by'] ?? ''),
        'overall_result' => (string) ($meta['overall_result'] ?? ''),
        'readiness_score' => (int) ($meta['readiness_score'] ?? 0),
        'verified' => (bool) ($meta['verified'] ?? false),
        'cli_needed' => (bool) ($meta['cli_needed'] ?? false),
        'cli_command' => (string) ($meta['cli_command'] ?? ''),
        'failure_code' => (string) ($meta['failure_code'] ?? ''),
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'warning' => (string) ($meta['warning'] ?? 'Shadow verification only — production database was not modified.'),
    ];
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_shadow_verify_public_report(array $report): array
{
    unset($report['absolute_paths'], $report['package_path'], $report['dump_path'], $report['password']);

    return $report + [
        'production_touched' => false,
        'cutover_performed' => false,
        'execution_started' => false,
        'maintenance_enabled' => false,
        'files_restored' => false,
    ];
}

/**
 * @return array{ok:bool,code:string,job:array<string,mixed>}
 */
function orange_restore_shadow_verify_revalidate(string $workRoot, string $jobId, string $backupRoot): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'job' => $job];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'job' => $job];
    }

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job];
    }

    $shadowMeta = orange_restore_shadow_load_meta($workRoot, $jobId);
    if ($shadowMeta === null || empty($shadowMeta['ready'])) {
        return ['ok' => false, 'code' => 'shadow_restore_not_ready', 'job' => $job];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($anchor === null || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
        return ['ok' => false, 'code' => 'pre_restore_backup_not_ready', 'job' => $job];
    }

    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
        $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
        if (!($validation['ok'] ?? false)) {
            return ['ok' => false, 'code' => (string) ($validation['code'] ?? 'version_mismatch'), 'job' => $job];
        }
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'contract_missing', 'job' => $job];
    }

    return ['ok' => true, 'code' => 'ok', 'job' => $job];
}

/**
 * Collect deep structural inventory from shadow (SELECT / information_schema / CHECKSUM only).
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_deep_inventory(PDO $pdo, string $schema): array
{
    if (isset($GLOBALS['orange_shadow_verify_deep_inventory_override'])
        && is_callable($GLOBALS['orange_shadow_verify_deep_inventory_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_verify_deep_inventory_override'];
        $result = $fn($pdo, $schema);

        return is_array($result) ? $result : [];
    }

    orange_restore_staging_assert_safe_target($pdo, $schema);
    $base = orange_restore_shadow_inventory($pdo, $schema);
    $tables = array_values(array_map('strval', $base['tables'] ?? []));

    $foreignKeys = [];
    $st = $pdo->prepare(
        'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $foreignKeys[] = [
            'constraint' => (string) ($row['CONSTRAINT_NAME'] ?? ''),
            'table' => (string) ($row['TABLE_NAME'] ?? ''),
            'column' => (string) ($row['COLUMN_NAME'] ?? ''),
            'ref_table' => (string) ($row['REFERENCED_TABLE_NAME'] ?? ''),
            'ref_column' => (string) ($row['REFERENCED_COLUMN_NAME'] ?? ''),
        ];
    }

    $indexes = [];
    $st = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, INDEX_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $indexes[] = [
            'table' => (string) ($row['TABLE_NAME'] ?? ''),
            'name' => (string) ($row['INDEX_NAME'] ?? ''),
            'unique' => ((int) ($row['NON_UNIQUE'] ?? 1)) === 0,
            'columns' => (string) ($row['columns'] ?? ''),
        ];
    }

    $autoIncrement = [];
    $tableCollations = [];
    $st = $pdo->prepare(
        "SELECT TABLE_NAME, AUTO_INCREMENT, TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $name = (string) ($row['TABLE_NAME'] ?? '');
        if ($name === '') {
            continue;
        }
        $ai = $row['AUTO_INCREMENT'];
        $autoIncrement[$name] = $ai === null ? null : (int) $ai;
        $tableCollations[$name] = (string) ($row['TABLE_COLLATION'] ?? '');
    }

    $checksums = [];
    $checksumErrors = [];
    $checksumSupported = true;
    $limit = ORANGE_RESTORE_SHADOW_VERIFY_CHECKSUM_TABLE_LIMIT;
    $checked = 0;
    foreach ($tables as $table) {
        if ($checked >= $limit) {
            break;
        }
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        try {
            $row = $pdo->query('CHECKSUM TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            $checksums[$table] = isset($row['Checksum']) ? (int) $row['Checksum'] : null;
            $checked++;
        } catch (Throwable $e) {
            $checksumSupported = false;
            $checksumErrors[] = $table . ': ' . $e->getMessage();
            break;
        }
    }

    $orphanErrors = orange_restore_shadow_verify_orphan_checks($pdo, $tables);

    return $base + [
        'foreign_keys' => $foreignKeys,
        'foreign_key_count' => count($foreignKeys),
        'indexes' => $indexes,
        'index_count' => count($indexes),
        'auto_increment' => $autoIncrement,
        'table_collations' => $tableCollations,
        'checksums' => $checksums,
        'checksum_supported' => $checksumSupported,
        'checksum_errors' => array_slice($checksumErrors, 0, 10),
        'orphan_errors' => $orphanErrors,
    ];
}

/**
 * Lightweight production schema inventory (read-only).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_shadow_verify_production_schema_readonly(string $projectRoot, array $env): array
{
    unset($env);
    if (isset($GLOBALS['orange_shadow_verify_production_schema_override'])
        && is_callable($GLOBALS['orange_shadow_verify_production_schema_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_verify_production_schema_override'];
        $result = $fn($projectRoot);

        return is_array($result) ? $result : [];
    }

    $base = orange_restore_shadow_production_inventory_readonly($projectRoot, []);
    $prodDb = (string) ($base['database'] ?? '');
    if ($prodDb === '') {
        return $base + [
            'foreign_keys' => [],
            'foreign_key_count' => 0,
            'indexes' => [],
            'index_count' => 0,
            'read_only' => true,
        ];
    }

    $settings = orange_backup_load_db_settings($projectRoot);
    $dsn = 'mysql:host=' . $settings['host'] . ';dbname=' . $prodDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    $foreignKeys = [];
    $st = $pdo->prepare(
        'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $foreignKeys[] = [
            'constraint' => (string) ($row['CONSTRAINT_NAME'] ?? ''),
            'table' => (string) ($row['TABLE_NAME'] ?? ''),
            'column' => (string) ($row['COLUMN_NAME'] ?? ''),
            'ref_table' => (string) ($row['REFERENCED_TABLE_NAME'] ?? ''),
            'ref_column' => (string) ($row['REFERENCED_COLUMN_NAME'] ?? ''),
        ];
    }

    $indexes = [];
    $st = $pdo->prepare(
        'SELECT TABLE_NAME, INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
         GROUP BY TABLE_NAME, INDEX_NAME
         ORDER BY TABLE_NAME, INDEX_NAME'
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $indexes[] = [
            'table' => (string) ($row['TABLE_NAME'] ?? ''),
            'name' => (string) ($row['INDEX_NAME'] ?? ''),
        ];
    }

    return $base + [
        'foreign_keys' => $foreignKeys,
        'foreign_key_count' => count($foreignKeys),
        'indexes' => $indexes,
        'index_count' => count($indexes),
        'read_only' => true,
    ];
}

/**
 * @param list<string> $tables
 * @return list<string>
 */
function orange_restore_shadow_verify_orphan_checks(PDO $pdo, array $tables): array
{
    if (isset($GLOBALS['orange_shadow_verify_orphan_override'])
        && is_callable($GLOBALS['orange_shadow_verify_orphan_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_verify_orphan_override'];
        $result = $fn($pdo, $tables);

        return is_array($result) ? array_values(array_map('strval', $result)) : [];
    }

    $errors = [];
    $checks = [
        ['child' => 'warehouse_variant_stock', 'fk' => 'warehouse_id', 'parent' => 'warehouses'],
        ['child' => 'stock_movements', 'fk' => 'warehouse_id', 'parent' => 'warehouses'],
        ['child' => 'journal_lines', 'fk' => 'journal_voucher_id', 'parent' => 'journal_vouchers'],
        ['child' => 'order_items', 'fk' => 'order_id', 'parent' => 'orders'],
        ['child' => 'product_variants', 'fk' => 'product_id', 'parent' => 'products'],
    ];
    foreach ($checks as $check) {
        if (!in_array($check['child'], $tables, true) || !in_array($check['parent'], $tables, true)) {
            continue;
        }
        $child = '`' . str_replace('`', '``', $check['child']) . '`';
        $parent = '`' . str_replace('`', '``', $check['parent']) . '`';
        $fk = str_replace('`', '``', $check['fk']);
        $sql = 'SELECT COUNT(*) FROM ' . $child . ' c LEFT JOIN ' . $parent . ' p ON p.id = c.`' . $fk
            . '` WHERE c.`' . $fk . '` IS NOT NULL AND p.id IS NULL';
        try {
            $count = (int) ($pdo->query($sql)->fetchColumn() ?: 0);
            if ($count > 0) {
                $errors[] = 'Orphan FK in ' . $check['child'] . '.' . $check['fk'] . ' (' . (string) $count . ' rows).';
            }
        } catch (Throwable $e) {
            // Missing id columns or incompatible engines — warn as soft issue via caller if needed.
            $errors[] = 'Orphan check skipped/failed for ' . $check['child'] . ': ' . $e->getMessage();
        }
    }

    return $errors;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<string>
 */
function orange_restore_shadow_verify_fk_keys(array $rows): array
{
    $keys = [];
    foreach ($rows as $row) {
        $keys[] = implode('|', [
            (string) ($row['table'] ?? ''),
            (string) ($row['column'] ?? ''),
            (string) ($row['ref_table'] ?? ''),
            (string) ($row['ref_column'] ?? ''),
        ]);
    }
    sort($keys);

    return $keys;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<string>
 */
function orange_restore_shadow_verify_index_keys(array $rows): array
{
    $keys = [];
    foreach ($rows as $row) {
        $keys[] = (string) ($row['table'] ?? '') . '.' . (string) ($row['name'] ?? '');
    }
    sort($keys);

    return array_values(array_unique($keys));
}

/**
 * Evaluate readiness: READY / WARNING / FAIL + score 0–100.
 *
 * @param array<string, mixed> $shadowDeep
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $prodSchema
 * @param array<string, mixed>|null $restoreReport
 * @return array<string, mixed>
 */
function orange_restore_shadow_verify_evaluate(
    array $shadowDeep,
    array $manifest,
    array $prodSchema,
    ?array $restoreReport
): array {
    $errors = [];
    $warnings = [];
    $score = 100;
    $deductions = [];

    $tableCount = (int) ($shadowDeep['table_count'] ?? 0);
    if ($tableCount <= 0) {
        $errors[] = 'Shadow has no base tables (failed import or empty restore).';
        $score -= 40;
        $deductions[] = ['reason' => 'no_tables', 'points' => 40];
    }

    $charset = strtolower((string) ($shadowDeep['charset'] ?? ''));
    $collation = strtolower((string) ($shadowDeep['collation'] ?? ''));
    if ($charset !== '' && $charset !== 'utf8mb4') {
        $errors[] = 'Shadow charset is not utf8mb4 (' . $charset . ').';
        $score -= 15;
        $deductions[] = ['reason' => 'charset', 'points' => 15];
    }
    if ($collation !== '' && !str_starts_with($collation, 'utf8mb4_')) {
        $errors[] = 'Shadow collation is not utf8mb4_* (' . $collation . ').';
        $score -= 10;
        $deductions[] = ['reason' => 'collation', 'points' => 10];
    }

    $badTableCollations = [];
    foreach (($shadowDeep['table_collations'] ?? []) as $t => $c) {
        $c = strtolower((string) $c);
        if ($c !== '' && !str_starts_with($c, 'utf8mb4_')) {
            $badTableCollations[] = (string) $t;
        }
    }
    if ($badTableCollations !== []) {
        $warnings[] = 'Non-utf8mb4 table collations: ' . implode(', ', array_slice($badTableCollations, 0, 20));
        $score -= 5;
        $deductions[] = ['reason' => 'table_collations', 'points' => 5];
    }

    $expectedTables = (int) ($manifest['table_count'] ?? 0);
    $packageCompare = [
        'expected_table_count' => $expectedTables,
        'actual_table_count' => $tableCount,
        'expected_schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'actual_schema_revision' => (int) ($shadowDeep['schema_revision'] ?? 0),
        'views_count' => (int) ($shadowDeep['view_count'] ?? 0),
        'routines_count' => (int) ($shadowDeep['routine_count'] ?? 0),
        'triggers_count' => (int) ($shadowDeep['trigger_count'] ?? 0),
        'events_count' => (int) ($shadowDeep['event_count'] ?? 0),
        'foreign_key_count' => (int) ($shadowDeep['foreign_key_count'] ?? 0),
        'index_count' => (int) ($shadowDeep['index_count'] ?? 0),
        'total_rows' => (int) ($shadowDeep['total_rows'] ?? 0),
        'charset' => (string) ($shadowDeep['charset'] ?? ''),
        'collation' => (string) ($shadowDeep['collation'] ?? ''),
    ];
    if ($expectedTables > 0 && $tableCount < max(1, (int) floor($expectedTables * 0.9))) {
        $errors[] = 'Shadow table count below 90% of package table_count.';
        $score -= 20;
        $deductions[] = ['reason' => 'table_count_package', 'points' => 20];
    } elseif ($expectedTables > 0 && $tableCount !== $expectedTables) {
        $warnings[] = 'Shadow table_count differs from package metadata.';
        $score -= 5;
        $deductions[] = ['reason' => 'table_count_mismatch_soft', 'points' => 5];
    }

    $expRev = (int) ($manifest['schema_revision'] ?? 0);
    $actRev = (int) ($shadowDeep['schema_revision'] ?? 0);
    if ($expRev > 0 && $actRev > 0 && $expRev !== $actRev) {
        $warnings[] = 'Shadow schema_revision differs from package.';
        $score -= 8;
        $deductions[] = ['reason' => 'schema_revision_package', 'points' => 8];
    }

    if (is_array($restoreReport) && strtoupper((string) ($restoreReport['overall_result'] ?? '')) === 'FAIL') {
        $errors[] = 'Prior shadow_restore_report overall_result is FAIL.';
        $score -= 25;
        $deductions[] = ['reason' => 'prior_restore_fail', 'points' => 25];
    }

    $shadowTables = array_values(array_map('strval', $shadowDeep['tables'] ?? []));
    $prodTables = array_values(array_map('strval', $prodSchema['tables'] ?? []));
    $onlyShadow = array_values(array_diff($shadowTables, $prodTables));
    $onlyProd = array_values(array_diff($prodTables, $shadowTables));
    if ($onlyProd !== []) {
        $errors[] = 'Missing production tables in shadow (' . count($onlyProd) . ').';
        $penalty = min(25, 5 + count($onlyProd));
        $score -= $penalty;
        $deductions[] = ['reason' => 'missing_tables', 'points' => $penalty];
    }
    if ($onlyShadow !== []) {
        $warnings[] = 'Extra tables only in shadow (' . count($onlyShadow) . ').';
        $score -= 3;
        $deductions[] = ['reason' => 'extra_tables', 'points' => 3];
    }

    $shadowFk = orange_restore_shadow_verify_fk_keys($shadowDeep['foreign_keys'] ?? []);
    $prodFk = orange_restore_shadow_verify_fk_keys($prodSchema['foreign_keys'] ?? []);
    $fkOnlyShadow = array_values(array_diff($shadowFk, $prodFk));
    $fkOnlyProd = array_values(array_diff($prodFk, $shadowFk));
    if ($fkOnlyProd !== []) {
        $errors[] = 'Missing foreign keys vs production (' . count($fkOnlyProd) . ').';
        $penalty = min(20, 4 + count($fkOnlyProd));
        $score -= $penalty;
        $deductions[] = ['reason' => 'missing_fks', 'points' => $penalty];
    }
    if ($fkOnlyShadow !== []) {
        $warnings[] = 'Extra foreign keys only in shadow (' . count($fkOnlyShadow) . ').';
        $score -= 2;
        $deductions[] = ['reason' => 'extra_fks', 'points' => 2];
    }

    // Broken FK: referenced table missing in shadow.
    $brokenFk = [];
    foreach (($shadowDeep['foreign_keys'] ?? []) as $fk) {
        $ref = (string) ($fk['ref_table'] ?? '');
        if ($ref !== '' && !in_array($ref, $shadowTables, true)) {
            $brokenFk[] = (string) ($fk['table'] ?? '') . '.' . (string) ($fk['column'] ?? '') . '→' . $ref;
        }
    }
    if ($brokenFk !== []) {
        $errors[] = 'Broken FK references (missing parent tables): ' . implode(', ', array_slice($brokenFk, 0, 20));
        $score -= 15;
        $deductions[] = ['reason' => 'broken_fk', 'points' => 15];
    }

    $shadowIdx = orange_restore_shadow_verify_index_keys($shadowDeep['indexes'] ?? []);
    $prodIdx = orange_restore_shadow_verify_index_keys($prodSchema['indexes'] ?? []);
    $idxOnlyProd = array_values(array_diff($prodIdx, $shadowIdx));
    if ($idxOnlyProd !== []) {
        $warnings[] = 'Missing indexes vs production (' . count($idxOnlyProd) . ').';
        $penalty = min(12, 2 + (int) floor(count($idxOnlyProd) / 5));
        $score -= $penalty;
        $deductions[] = ['reason' => 'missing_indexes', 'points' => $penalty];
    }

    $shadowViews = array_values(array_map('strval', $shadowDeep['views'] ?? []));
    $prodViews = array_values(array_map('strval', $prodSchema['views'] ?? []));
    $viewsOnlyProd = array_values(array_diff($prodViews, $shadowViews));
    if ($viewsOnlyProd !== []) {
        $warnings[] = 'Missing views vs production (' . count($viewsOnlyProd) . ').';
        $score -= 4;
        $deductions[] = ['reason' => 'missing_views', 'points' => 4];
    }

    $prodRoutineCount = (int) ($prodSchema['routine_count'] ?? 0);
    $shadowRoutineCount = (int) ($shadowDeep['routine_count'] ?? 0);
    if ($prodRoutineCount > 0 && $shadowRoutineCount < $prodRoutineCount) {
        $warnings[] = 'Shadow routine count below production.';
        $score -= 3;
        $deductions[] = ['reason' => 'routines', 'points' => 3];
    }
    $prodTriggerCount = (int) ($prodSchema['trigger_count'] ?? 0);
    $shadowTriggerCount = (int) ($shadowDeep['trigger_count'] ?? 0);
    if ($prodTriggerCount > 0 && $shadowTriggerCount < $prodTriggerCount) {
        $warnings[] = 'Shadow trigger count below production.';
        $score -= 3;
        $deductions[] = ['reason' => 'triggers', 'points' => 3];
    }
    $prodEventCount = (int) ($prodSchema['event_count'] ?? 0);
    $shadowEventCount = (int) ($shadowDeep['event_count'] ?? 0);
    if ($prodEventCount > 0 && $shadowEventCount < $prodEventCount) {
        $warnings[] = 'Shadow event count below production.';
        $score -= 2;
        $deductions[] = ['reason' => 'events', 'points' => 2];
    }

    $orphanErrors = array_values(array_map('strval', $shadowDeep['orphan_errors'] ?? []));
    $hardOrphans = [];
    $softOrphans = [];
    foreach ($orphanErrors as $msg) {
        if (str_starts_with($msg, 'Orphan FK in ')) {
            $hardOrphans[] = $msg;
        } else {
            $softOrphans[] = $msg;
        }
    }
    if ($hardOrphans !== []) {
        $errors = array_merge($errors, array_slice($hardOrphans, 0, 20));
        $score -= min(20, 5 * count($hardOrphans));
        $deductions[] = ['reason' => 'orphan_fk', 'points' => min(20, 5 * count($hardOrphans))];
    }
    if ($softOrphans !== []) {
        $warnings = array_merge($warnings, array_slice($softOrphans, 0, 10));
    }

    if (empty($shadowDeep['checksum_supported']) && ($shadowDeep['checksum_errors'] ?? []) !== []) {
        $warnings[] = 'CHECKSUM TABLE not fully supported on this server/driver.';
    }

    $prodRev = (int) ($prodSchema['schema_revision'] ?? 0);
    if ($prodRev > 0 && $actRev > 0 && $prodRev !== $actRev) {
        $warnings[] = 'Shadow schema_revision differs from production.';
        $score -= 6;
        $deductions[] = ['reason' => 'schema_revision_production', 'points' => 6];
    }

    $score = max(0, min(100, $score));
    if ($errors !== []) {
        $overall = 'FAIL';
    } elseif ($score >= ORANGE_RESTORE_SHADOW_VERIFY_SCORE_READY && $warnings === []) {
        $overall = 'READY';
    } elseif ($score >= ORANGE_RESTORE_SHADOW_VERIFY_SCORE_WARNING) {
        $overall = 'WARNING';
    } else {
        $overall = 'FAIL';
        if ($errors === []) {
            $errors[] = 'Readiness score below warning threshold.';
        }
    }

    $productionCompare = [
        'production_database' => (string) ($prodSchema['database'] ?? ''),
        'shadow_table_count' => count($shadowTables),
        'production_table_count' => count($prodTables),
        'tables_only_in_shadow' => array_slice($onlyShadow, 0, 50),
        'tables_only_in_production' => array_slice($onlyProd, 0, 50),
        'foreign_keys_only_in_shadow' => array_slice($fkOnlyShadow, 0, 50),
        'foreign_keys_only_in_production' => array_slice($fkOnlyProd, 0, 50),
        'indexes_only_in_production' => array_slice($idxOnlyProd, 0, 50),
        'views_only_in_production' => array_slice($viewsOnlyProd, 0, 50),
        'broken_foreign_keys' => array_slice($brokenFk, 0, 50),
        'charset_shadow' => (string) ($shadowDeep['charset'] ?? ''),
        'charset_production' => (string) ($prodSchema['charset'] ?? ''),
        'collation_shadow' => (string) ($shadowDeep['collation'] ?? ''),
        'collation_production' => (string) ($prodSchema['collation'] ?? ''),
        'schema_revision_shadow' => $actRev,
        'schema_revision_production' => $prodRev,
        'read_only_production_scan' => true,
    ];

    return [
        'overall_result' => $overall,
        'readiness_score' => $score,
        'errors' => $errors,
        'warnings' => $warnings,
        'deductions' => $deductions,
        'package_compare' => $packageCompare,
        'production_compare' => $productionCompare,
        'verified' => $overall === 'READY' || $overall === 'WARNING',
    ];
}

/**
 * CLI worker — verification only. Stops at shadow_verified / shadow_not_ready.
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_verify_run_cli(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'cli'
): array {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $check = orange_restore_shadow_verify_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $meta = orange_restore_shadow_verify_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_verify_load_report($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED
        && is_array($meta)
        && !empty($meta['verified'])
        && is_array($report)) {
        return [
            'ok' => true,
            'idempotent' => true,
            'result' => (string) ($meta['overall_result'] ?? 'READY'),
            'job_id' => $jobId,
            'readiness_score' => (int) ($meta['readiness_score'] ?? 0),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_verify_public_meta($meta),
            'report' => orange_restore_shadow_verify_public_report($report),
        ];
    }

    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    $lock = orange_restore_shadow_verify_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $shadowMeta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'shadow_db' => (string) ($shadowMeta['shadow_db'] ?? ''),
        'production_db' => (string) ($shadowMeta['production_db'] ?? ''),
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
        'created_at' => gmdate('c'),
        'created_by' => $owner,
        'overall_result' => '',
        'readiness_score' => 0,
        'verified' => false,
        'cli_needed' => false,
        'cli_command' => 'php scripts/backup/restore_shadow_verify.php --job=' . $jobId,
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'warning' => 'Shadow verification only — production database will not be modified.',
    ];

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_verification_started',
            'result' => 'ok',
            'owner' => $owner,
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_VERIFYING,
            40,
            'Deep-verifying shadow database readiness',
            'shadow_verification_started'
        );
        orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_meta_path($workRoot, $jobId), $meta);

        $env = orange_backup_load_env_array($projectRoot);
        if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
            $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
        }

        $productionDb = orange_restore_shadow_production_db_name($projectRoot);
        $shadowDb = (string) ($shadowMeta['shadow_db'] ?? '');
        if ($shadowDb === '') {
            $shadowDb = orange_restore_shadow_db_name($env, $projectRoot);
        }
        if (strcasecmp($shadowDb, $productionDb) === 0) {
            throw new RuntimeException('Shadow database must not equal production database.');
        }
        $meta['shadow_db'] = $shadowDb;
        $meta['production_db'] = $productionDb;

        $packageId = (string) ($job['package_id'] ?? '');
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        $manifestRaw = file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (!is_array($manifest) || ($manifest['package_type'] ?? '') !== 'full_disaster') {
            throw new RuntimeException('package_type_mismatch');
        }

        if (isset($GLOBALS['orange_shadow_verify_connect_override'])
            && is_callable($GLOBALS['orange_shadow_verify_connect_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_verify_connect_override'];
            $pdo = $fn($projectRoot, $env, $shadowDb);
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('shadow_verify_connect_override_invalid');
            }
        } else {
            $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        }

        $shadowDeep = orange_restore_shadow_deep_inventory($pdo, $shadowDb);
        $prodSchema = orange_restore_shadow_verify_production_schema_readonly($projectRoot, $env);
        $restoreReport = orange_restore_shadow_load_report($workRoot, $jobId);
        $eval = orange_restore_shadow_verify_evaluate($shadowDeep, $manifest, $prodSchema, $restoreReport);

        $report = [
            'report_version' => ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION,
            'generated_at' => gmdate('c'),
            'framework_job_id' => $jobId,
            'source_package_id' => $packageId,
            'shadow_db' => $shadowDb,
            'production_db' => $productionDb,
            'overall_result' => (string) $eval['overall_result'],
            'readiness_score' => (int) $eval['readiness_score'],
            'verified' => (bool) $eval['verified'],
            'errors' => $eval['errors'],
            'warnings' => $eval['warnings'],
            'deductions' => $eval['deductions'],
            'package_compare' => $eval['package_compare'],
            'production_compare' => $eval['production_compare'],
            'shadow_inventory' => [
                'table_count' => (int) ($shadowDeep['table_count'] ?? 0),
                'view_count' => (int) ($shadowDeep['view_count'] ?? 0),
                'routine_count' => (int) ($shadowDeep['routine_count'] ?? 0),
                'trigger_count' => (int) ($shadowDeep['trigger_count'] ?? 0),
                'event_count' => (int) ($shadowDeep['event_count'] ?? 0),
                'foreign_key_count' => (int) ($shadowDeep['foreign_key_count'] ?? 0),
                'index_count' => (int) ($shadowDeep['index_count'] ?? 0),
                'charset' => (string) ($shadowDeep['charset'] ?? ''),
                'collation' => (string) ($shadowDeep['collation'] ?? ''),
                'schema_revision' => (int) ($shadowDeep['schema_revision'] ?? 0),
                'total_rows' => (int) ($shadowDeep['total_rows'] ?? 0),
                'tables' => array_slice($shadowDeep['tables'] ?? [], 0, 200),
                'row_counts_sample' => array_slice($shadowDeep['row_counts'] ?? [], 0, 50, true),
                'auto_increment_sample' => array_slice($shadowDeep['auto_increment'] ?? [], 0, 50, true),
                'checksum_sample' => array_slice($shadowDeep['checksums'] ?? [], 0, 40, true),
                'checksum_supported' => (bool) ($shadowDeep['checksum_supported'] ?? false),
            ],
            'production_touched' => false,
            'cutover_performed' => false,
            'files_restored' => false,
            'maintenance_enabled' => false,
            'execution_started' => false,
            'application_switched_to_shadow' => false,
            'warning' => 'Shadow verification only — production database was not modified.',
        ];
        orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_report_path($workRoot, $jobId), $report);

        $overall = (string) $eval['overall_result'];
        $verified = (bool) $eval['verified'];
        $nextStatus = $verified
            ? ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED
            : ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY;
        $nextPhase = $verified
            ? ORANGE_RESTORE_FW_PHASE_SHADOW_VERIFIED
            : ORANGE_RESTORE_FW_PHASE_SHADOW_NOT_READY;

        $meta['status'] = $nextStatus;
        $meta['overall_result'] = $overall;
        $meta['readiness_score'] = (int) $eval['readiness_score'];
        $meta['verified'] = $verified;
        $meta['failure_code'] = $verified ? '' : 'shadow_not_ready';
        $meta['execution_started'] = false;
        orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => $verified ? 'shadow_verified' : 'shadow_not_ready',
            'result' => $verified ? 'ok' : 'fail',
            'overall_result' => $overall,
            'readiness_score' => (int) $eval['readiness_score'],
        ]);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            $nextStatus,
            $nextPhase,
            100,
            $verified
                ? ('Shadow verified (' . $overall . '), score=' . (string) (int) $eval['readiness_score'])
                : ('Shadow not ready (' . $overall . '), score=' . (string) (int) $eval['readiness_score']),
            $verified ? 'shadow_verified' : 'shadow_not_ready'
        );
        $job['shadow_verification_file'] = ORANGE_RESTORE_SHADOW_VERIFY_META_FILE;
        $job['shadow_verification_report_file'] = ORANGE_RESTORE_SHADOW_VERIFY_REPORT_FILE;
        $job['shadow_verification_status'] = $nextStatus;
        $job['shadow_readiness_score'] = (int) $eval['readiness_score'];
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_shadow_verify_release_lock($workRoot, $jobId);

        return [
            'ok' => $verified,
            'idempotent' => false,
            'result' => $overall,
            'job_id' => $jobId,
            'readiness_score' => (int) $eval['readiness_score'],
            'code' => $verified ? 'ok' : 'shadow_not_ready',
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_verify_public_meta($meta),
            'report' => orange_restore_shadow_verify_public_report($report),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'shadow_verification_failed';
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY;
        $meta['verified'] = false;
        $meta['overall_result'] = 'FAIL';
        $meta['failure_code'] = $code;
        $meta['cli_needed'] = true;
        $meta['execution_started'] = false;
        $meta['production_touched'] = false;
        try {
            orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_meta_path($workRoot, $jobId), $meta);
            $failReport = [
                'report_version' => ORANGE_RESTORE_SHADOW_VERIFY_RECORD_VERSION,
                'generated_at' => gmdate('c'),
                'framework_job_id' => $jobId,
                'overall_result' => 'FAIL',
                'readiness_score' => 0,
                'verified' => false,
                'failure_code' => $code,
                'errors' => [$code],
                'warnings' => [],
                'production_touched' => false,
                'cutover_performed' => false,
                'execution_started' => false,
            ];
            orange_restore_shadow_verify_write_json(
                orange_restore_shadow_verify_report_path($workRoot, $jobId),
                $failReport
            );
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
                ORANGE_RESTORE_FW_PHASE_SHADOW_NOT_READY,
                100,
                'Shadow verification failed: ' . $code,
                'shadow_not_ready'
            );
            $failed = orange_restore_fw_read($workRoot, $jobId);
            $failed['shadow_verification_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY;
            $failed['execution_started'] = false;
            orange_restore_fw_write($workRoot, $failed);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_verification_failed',
                'result' => 'fail',
                'code' => $code,
            ]);
        } catch (Throwable) {
            // best-effort forensic preserve
        }
        orange_restore_shadow_verify_release_lock($workRoot, $jobId);

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'readiness_score' => 0,
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_verify_public_meta($meta),
        ];
    }
}
