<?php

declare(strict_types=1);

/**
 * Phase 3B.3B4 — Shadow Database Restore Engine.
 *
 * Imports Full package SQL into an isolated shadow/staging database only.
 * Never modifies production DB, never cutover, never file restore, never maintenance.
 *
 * Reuses:
 *   - orange_restore_staging_* credentials / safety fences
 *   - orange_restore_sql_runner_import_gzip()
 *   - approved Full package dump format (manifest.dump_file gzip SQL)
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_package_compat.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_full.php';

const ORANGE_RESTORE_SHADOW_RECORD_VERSION = '3B.3B4-v1';
const ORANGE_RESTORE_SHADOW_REPORT_FILE = 'shadow_restore_report.json';
const ORANGE_RESTORE_SHADOW_META_FILE = 'shadow_restore.json';
const ORANGE_RESTORE_SHADOW_LOCK_FILE = '.shadow_restore.lock';
const ORANGE_RESTORE_SHADOW_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_ENV_SHADOW_DB = 'ORANGE_RESTORE_SHADOW_DB';
const ORANGE_RESTORE_SHADOW_CHARSET = 'utf8mb4';
const ORANGE_RESTORE_SHADOW_COLLATION = 'utf8mb4_unicode_ci';

function orange_restore_shadow_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_REPORT_FILE;
}

function orange_restore_shadow_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_META_FILE;
}

function orange_restore_shadow_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_LOCK_FILE;
}

/**
 * Production DB name for fences/compare. Overrideable for isolated self-tests.
 */
function orange_restore_shadow_production_db_name(string $projectRoot): string
{
    if (isset($GLOBALS['orange_shadow_production_db_override'])
        && is_string($GLOBALS['orange_shadow_production_db_override'])
        && trim($GLOBALS['orange_shadow_production_db_override']) !== '') {
        return trim($GLOBALS['orange_shadow_production_db_override']);
    }

    return orange_restore_production_db_name($projectRoot);
}

/**
 * Shadow DB name: ORANGE_RESTORE_SHADOW_DB if set, else ORANGE_RESTORE_STAGING_DB.
 * Never equals production.
 *
 * @param array<string, mixed> $env
 */
function orange_restore_shadow_db_name(array $env, string $projectRoot): string
{
    $shadow = trim((string) ($env[ORANGE_RESTORE_ENV_SHADOW_DB] ?? ''));
    if ($shadow === '') {
        // Prefer env staging name without requiring production .env.php when override is set.
        $shadow = trim((string) ($env['ORANGE_RESTORE_STAGING_DB'] ?? ''));
        if ($shadow === '') {
            $shadow = orange_restore_staging_db_name($env, $projectRoot);
        }
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $shadow)) {
        throw new RuntimeException('Shadow database name contains invalid characters.');
    }
    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadow, $productionDb) === 0) {
        throw new RuntimeException('Shadow database must not equal production database (' . $productionDb . ').');
    }

    return $shadow;
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool}
 */
function orange_restore_shadow_lock_status(string $workRoot): array
{
    $path = orange_restore_shadow_lock_path($workRoot);
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
    $stale = $age > ORANGE_RESTORE_SHADOW_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/**
 * @return array{ok:bool,message:string}
 */
function orange_restore_shadow_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_shadow_lock_path($workRoot);
    $status = orange_restore_shadow_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_shadow_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        $held = (string) (($status['payload'] ?? [])['job_id'] ?? '');
        if ($held === $jobId) {
            return ['ok' => true, 'message' => 'lock_already_held'];
        }

        return ['ok' => false, 'message' => 'shadow_restore_lock_active'];
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
        return ['ok' => false, 'message' => 'shadow_restore_lock_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok'];
}

function orange_restore_shadow_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_shadow_lock_path($workRoot);
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
function orange_restore_shadow_write_json(string $path, array $record): void
{
    unset($record['absolute_paths'], $record['package_path'], $record['dump_path'], $record['password'], $record['secrets']);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write shadow restore metadata.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_load_meta(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_meta_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_load_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_report_path($workRoot, $jobId);
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
function orange_restore_shadow_public_meta(array $meta): array
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
        'schema_revision' => (int) ($meta['schema_revision'] ?? 0),
        'backend' => (string) ($meta['backend'] ?? ''),
        'statements_executed' => (int) ($meta['statements_executed'] ?? 0),
        'verify_result' => (string) ($meta['verify_result'] ?? ''),
        'ready' => (bool) ($meta['ready'] ?? false),
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'cli_needed' => (bool) ($meta['cli_needed'] ?? false),
        'failure_code' => (string) ($meta['failure_code'] ?? ''),
        'warning' => (string) ($meta['warning'] ?? 'Shadow restore only — production database was not modified.'),
    ];
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_shadow_public_report(array $report): array
{
    unset($report['absolute_paths'], $report['package_path'], $report['dump_path'], $report['password']);

    return $report + [
        'production_touched' => false,
        'cutover_performed' => false,
        'execution_started' => false,
    ];
}

/**
 * @return array{ok:bool,code:string,job:array<string,mixed>}
 */
function orange_restore_shadow_revalidate(string $workRoot, string $jobId, string $backupRoot): array
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
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job];
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
 * HTTP: request shadow restore (metadata only).
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $check = orange_restore_shadow_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
        && is_array($meta)
        && !empty($meta['ready'])) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report(orange_restore_shadow_load_report($workRoot, $jobId) ?? []),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Shadow restore already ready.',
        ];
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_public_meta($meta ?? [
                'framework_job_id' => $jobId,
                'status' => $status,
                'cli_needed' => true,
                'execution_started' => false,
            ]),
            'cli_needed' => true,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Shadow restore already requested. Run CLI worker.',
        ];
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED) {
        $lock = orange_restore_shadow_lock_status($workRoot);
        if ($lock['held'] && !$lock['stale']) {
            throw new RuntimeException('shadow_restore_lock_active');
        }
    } elseif ($status !== ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) {
        throw new RuntimeException('invalid_status');
    }

    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'shadow_db' => '',
        'production_db' => '',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'created_at' => gmdate('c'),
        'created_by' => $operator,
        'schema_revision' => 0,
        'backend' => 'php_pdo',
        'statements_executed' => 0,
        'verify_result' => '',
        'ready' => false,
        'production_touched' => false,
        'cutover_performed' => false,
        'files_restored' => false,
        'maintenance_enabled' => false,
        'execution_started' => false,
        'cli_needed' => true,
        'cli_command' => 'php scripts/backup/restore_shadow_db.php --job=' . $jobId,
        'warning' => 'Shadow restore only — production database will not be modified.',
    ];
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
        10,
        'Shadow DB restore pending — CLI worker required',
        'shadow_restore_requested'
    );
    $job['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => orange_restore_shadow_public_meta($meta),
        'cli_needed' => true,
        'idempotent' => false,
        'execution_started' => false,
        'message' => 'Shadow restore requested. Run CLI: php scripts/backup/restore_shadow_db.php --job=' . $jobId,
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array{ok:bool,created:bool,shadow_db:string,message:string}
 */
function orange_restore_shadow_ensure_database(string $projectRoot, array $env, string $shadowDb): array
{
    if (isset($GLOBALS['orange_shadow_ensure_override']) && is_callable($GLOBALS['orange_shadow_ensure_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_ensure_override'];
        $result = $fn($projectRoot, $env, $shadowDb);

        return is_array($result) ? $result : ['ok' => false, 'created' => false, 'shadow_db' => $shadowDb, 'message' => 'ensure_override_invalid'];
    }

    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('Refusing to create/use production database as shadow.');
    }

    // Reuse staging credentials (must differ from production user).
    $creds = orange_restore_staging_credentials($env, $projectRoot);
    $dsn = 'mysql:host=' . $creds['host'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    $quoted = '`' . str_replace('`', '``', $shadowDb) . '`';
    $exists = false;
    $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
    $st->execute([$shadowDb]);
    $exists = (string) ($st->fetchColumn() ?: '') !== '';

    $created = false;
    if (!$exists) {
        $pdo->exec(
            'CREATE DATABASE ' . $quoted
            . ' CHARACTER SET ' . ORANGE_RESTORE_SHADOW_CHARSET
            . ' COLLATE ' . ORANGE_RESTORE_SHADOW_COLLATION
        );
        $created = true;
    }

    return [
        'ok' => true,
        'created' => $created,
        'shadow_db' => $shadowDb,
        'message' => $created ? 'created' : 'already_exists',
    ];
}

/**
 * Connect to shadow DB using staging credentials with shadow db name override.
 *
 * @param array<string, mixed> $env
 */
function orange_restore_shadow_connect_pdo(string $projectRoot, array $env, string $shadowDb): PDO
{
    if (isset($GLOBALS['orange_shadow_connect_override']) && is_callable($GLOBALS['orange_shadow_connect_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_connect_override'];
        $pdo = $fn($projectRoot, $env, $shadowDb);
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('shadow_connect_override_invalid');
        }

        return $pdo;
    }

    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('Refusing to connect to production as shadow.');
    }
    $creds = orange_restore_staging_credentials($env, $projectRoot);
    $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $shadowDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
    orange_restore_staging_assert_no_production_privileges($pdo, $shadowDb, $productionDb);

    return $pdo;
}

/**
 * Wipe shadow schema objects (tables/views/routines/triggers/events). Never touches production.
 */
function orange_restore_shadow_wipe(PDO $pdo, string $shadowDb): void
{
    if (isset($GLOBALS['orange_shadow_wipe_override']) && is_callable($GLOBALS['orange_shadow_wipe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_wipe_override'];
        $fn($pdo, $shadowDb);

        return;
    }

    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Events
    $st = $pdo->prepare('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP EVENT IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Triggers
    $st = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP TRIGGER IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Routines
    $st = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'
    );
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = strtoupper((string) ($row['ROUTINE_TYPE'] ?? 'PROCEDURE'));
        if ($type !== 'FUNCTION' && $type !== 'PROCEDURE') {
            $type = 'PROCEDURE';
        }
        $pdo->exec('DROP ' . $type . ' IF EXISTS `' . str_replace('`', '``', (string) ($row['ROUTINE_NAME'] ?? '')) . '`');
    }

    // Views
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW'"
    );
    $st->execute([$shadowDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $pdo->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', (string) $name) . '`');
    }

    // Base tables
    orange_restore_staging_wipe($pdo, $shadowDb);
    orange_restore_staging_assert_safe_target($pdo, $shadowDb);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_shadow_inventory(PDO $pdo, string $schema): array
{
    if (isset($GLOBALS['orange_shadow_inventory_override']) && is_callable($GLOBALS['orange_shadow_inventory_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_inventory_override'];
        $result = $fn($pdo, $schema);

        return is_array($result) ? $result : [];
    }

    orange_restore_staging_assert_safe_target($pdo, $schema);

    $charset = '';
    $collation = '';
    $st = $pdo->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $st->execute([$schema]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $charset = (string) ($row['DEFAULT_CHARACTER_SET_NAME'] ?? '');
        $collation = (string) ($row['DEFAULT_COLLATION_NAME'] ?? '');
    }

    $tables = [];
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $tables[] = (string) $name;
    }

    $views = [];
    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $views[] = (string) $name;
    }

    $routines = [];
    $st = $pdo->prepare(
        'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $routines[] = [
            'name' => (string) ($r['ROUTINE_NAME'] ?? ''),
            'type' => (string) ($r['ROUTINE_TYPE'] ?? ''),
        ];
    }

    $triggers = [];
    $st = $pdo->prepare(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $triggers[] = (string) $name;
    }

    $events = [];
    $st = $pdo->prepare(
        'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME'
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $events[] = (string) $name;
    }

    $rowCounts = [];
    $totalRows = 0;
    foreach ($tables as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        } catch (Throwable) {
            $count = -1;
        }
        $rowCounts[$table] = $count;
        if ($count > 0) {
            $totalRows += $count;
        }
    }

    $schemaRevision = 0;
    if (function_exists('orange_backup_schema_revision_live')) {
        $schemaRevision = orange_backup_schema_revision_live($pdo);
    }

    return [
        'database' => $schema,
        'charset' => $charset,
        'collation' => $collation,
        'tables' => $tables,
        'table_count' => count($tables),
        'views' => $views,
        'view_count' => count($views),
        'routines' => $routines,
        'routine_count' => count($routines),
        'triggers' => $triggers,
        'trigger_count' => count($triggers),
        'events' => $events,
        'event_count' => count($events),
        'row_counts' => $rowCounts,
        'total_rows' => $totalRows,
        'schema_revision' => $schemaRevision,
    ];
}

/**
 * Read-only production inventory (SELECT/information_schema only).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_shadow_production_inventory_readonly(string $projectRoot, array $env): array
{
    unset($env);
    if (isset($GLOBALS['orange_shadow_production_inventory_override'])
        && is_callable($GLOBALS['orange_shadow_production_inventory_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_production_inventory_override'];
        $result = $fn($projectRoot);

        return is_array($result) ? $result : [];
    }

    $settings = orange_backup_load_db_settings($projectRoot);
    $prodDb = (string) $settings['name'];
    $dsn = 'mysql:host=' . $settings['host'] . ';dbname=' . $prodDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    // Inventory only — no writes.
    $inv = [
        'database' => $prodDb,
        'charset' => '',
        'collation' => '',
        'tables' => [],
        'table_count' => 0,
        'views' => [],
        'view_count' => 0,
        'routines' => [],
        'routine_count' => 0,
        'triggers' => [],
        'trigger_count' => 0,
        'events' => [],
        'event_count' => 0,
        'schema_revision' => function_exists('orange_backup_schema_revision_live')
            ? orange_backup_schema_revision_live($pdo)
            : 0,
        'read_only' => true,
    ];

    $st = $pdo->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $st->execute([$prodDb]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $inv['charset'] = (string) ($row['DEFAULT_CHARACTER_SET_NAME'] ?? '');
        $inv['collation'] = (string) ($row['DEFAULT_COLLATION_NAME'] ?? '');
    }

    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $inv['tables'][] = (string) $name;
    }
    $inv['table_count'] = count($inv['tables']);

    $st = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
    );
    $st->execute([$prodDb]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
        $inv['views'][] = (string) $name;
    }
    $inv['view_count'] = count($inv['views']);

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'
    );
    $st->execute([$prodDb]);
    $inv['routine_count'] = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?');
    $st->execute([$prodDb]);
    $inv['trigger_count'] = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?');
    $st->execute([$prodDb]);
    $inv['event_count'] = (int) $st->fetchColumn();

    return $inv;
}

/**
 * @param array<string, mixed> $shadowInv
 * @param array<string, mixed> $manifest
 * @param array<string, mixed> $prodInv
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,package_compare:array<string,mixed>,production_compare:array<string,mixed>}
 */
function orange_restore_shadow_verify(
    array $shadowInv,
    array $manifest,
    array $prodInv
): array {
    $errors = [];
    $warnings = [];

    if ((int) ($shadowInv['table_count'] ?? 0) <= 0) {
        $errors[] = 'Shadow database has no base tables after restore.';
    }

    $charset = strtolower((string) ($shadowInv['charset'] ?? ''));
    $collation = strtolower((string) ($shadowInv['collation'] ?? ''));
    if ($charset !== '' && $charset !== ORANGE_RESTORE_SHADOW_CHARSET) {
        $errors[] = 'Shadow charset mismatch (expected utf8mb4, got ' . $charset . ').';
    }
    if ($collation !== '' && !str_starts_with($collation, 'utf8mb4_')) {
        $errors[] = 'Shadow collation is not utf8mb4_* (got ' . $collation . ').';
    }

    $expectedTables = (int) ($manifest['table_count'] ?? 0);
    $actualTables = (int) ($shadowInv['table_count'] ?? 0);
    $packageCompare = [
        'expected_table_count' => $expectedTables,
        'actual_table_count' => $actualTables,
        'expected_schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'actual_schema_revision' => (int) ($shadowInv['schema_revision'] ?? 0),
        'tables_exist' => $actualTables > 0,
        'views_count' => (int) ($shadowInv['view_count'] ?? 0),
        'routines_count' => (int) ($shadowInv['routine_count'] ?? 0),
        'triggers_count' => (int) ($shadowInv['trigger_count'] ?? 0),
        'events_count' => (int) ($shadowInv['event_count'] ?? 0),
        'charset' => (string) ($shadowInv['charset'] ?? ''),
        'collation' => (string) ($shadowInv['collation'] ?? ''),
        'total_rows' => (int) ($shadowInv['total_rows'] ?? 0),
    ];
    if ($expectedTables > 0 && $actualTables < max(1, (int) floor($expectedTables * 0.5))) {
        $errors[] = 'Shadow table count far below package table_count.';
    }
    $expRev = (int) ($manifest['schema_revision'] ?? 0);
    $actRev = (int) ($shadowInv['schema_revision'] ?? 0);
    if ($expRev > 0 && $actRev > 0 && $expRev !== $actRev) {
        $warnings[] = 'Shadow schema_revision differs from package.';
    }

    $shadowTables = array_values(array_map('strval', $shadowInv['tables'] ?? []));
    $prodTables = array_values(array_map('strval', $prodInv['tables'] ?? []));
    $onlyShadow = array_values(array_diff($shadowTables, $prodTables));
    $onlyProd = array_values(array_diff($prodTables, $shadowTables));
    $productionCompare = [
        'production_database' => (string) ($prodInv['database'] ?? ''),
        'shadow_table_count' => count($shadowTables),
        'production_table_count' => count($prodTables),
        'tables_only_in_shadow' => array_slice($onlyShadow, 0, 50),
        'tables_only_in_production' => array_slice($onlyProd, 0, 50),
        'charset_shadow' => (string) ($shadowInv['charset'] ?? ''),
        'charset_production' => (string) ($prodInv['charset'] ?? ''),
        'collation_shadow' => (string) ($shadowInv['collation'] ?? ''),
        'collation_production' => (string) ($prodInv['collation'] ?? ''),
        'schema_revision_shadow' => (int) ($shadowInv['schema_revision'] ?? 0),
        'schema_revision_production' => (int) ($prodInv['schema_revision'] ?? 0),
        'read_only_production_scan' => true,
    ];
    if ($onlyShadow !== [] || $onlyProd !== []) {
        $warnings[] = 'Shadow/production table sets differ (reported; not a cutover).';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'package_compare' => $packageCompare,
        'production_compare' => $productionCompare,
    ];
}

/**
 * CLI worker — shadow DB only. Stops at shadow_restore_ready.
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_run_cli(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'cli'
): array {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $check = orange_restore_shadow_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
        && is_array($meta)
        && !empty($meta['ready'])) {
        return [
            'ok' => true,
            'idempotent' => true,
            'result' => 'PASS',
            'job_id' => $jobId,
            'shadow_db' => (string) ($meta['shadow_db'] ?? ''),
            'verify' => (string) ($meta['verify_result'] ?? 'PASS'),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report(orange_restore_shadow_load_report($workRoot, $jobId) ?? []),
        ];
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) {
        orange_restore_shadow_request($workRoot, $jobId, $backupRoot, ['username' => $owner, 'id' => 0]);
        $job = orange_restore_fw_read($workRoot, $jobId);
        $status = (string) ($job['status'] ?? '');
    }
    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    $lock = orange_restore_shadow_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'execution_started' => false,
    ];

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_restore_started',
            'result' => 'ok',
            'owner' => $owner,
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_RUNNING,
            35,
            'Importing package SQL into shadow database',
            'shadow_restore_started'
        );

        $env = orange_backup_load_env_array($projectRoot);
        if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
            $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
        }

        $productionDb = orange_restore_shadow_production_db_name($projectRoot);
        $shadowDb = orange_restore_shadow_db_name($env, $projectRoot);
        $meta['shadow_db'] = $shadowDb;
        $meta['production_db'] = $productionDb;
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING;
        $meta['cli_needed'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        $packageId = (string) ($job['package_id'] ?? '');
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestRaw = file_get_contents($manifestPath);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (!is_array($manifest) || ($manifest['package_type'] ?? '') !== 'full_disaster') {
            throw new RuntimeException('package_type_mismatch');
        }
        $dumpFile = (string) ($manifest['dump_file'] ?? '');
        if ($dumpFile === '') {
            throw new RuntimeException('dump_file_missing');
        }
        $dumpPath = $packagePath . DIRECTORY_SEPARATOR . $dumpFile;
        if (!is_file($dumpPath)) {
            throw new RuntimeException('dump_file_missing');
        }

        $compat = orange_restore_package_staging_import_compat($packagePath, $manifest, $shadowDb, $productionDb);
        if (!($compat['ok'] ?? false)) {
            throw new RuntimeException((string) ($compat['error'] ?? 'package_incompatible'));
        }

        $ensured = orange_restore_shadow_ensure_database($projectRoot, $env, $shadowDb);
        if (!($ensured['ok'] ?? false)) {
            throw new RuntimeException('shadow_db_create_failed');
        }

        $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        orange_restore_shadow_wipe($pdo, $shadowDb);

        if (isset($GLOBALS['orange_shadow_import_override']) && is_callable($GLOBALS['orange_shadow_import_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_import_override'];
            $sqlResult = $fn($pdo, $dumpPath, $shadowDb, $productionDb);
            if (!is_array($sqlResult)) {
                throw new RuntimeException('import_override_invalid');
            }
        } else {
            $sqlResult = orange_restore_sql_runner_import_gzip($pdo, $dumpPath, $shadowDb, $productionDb);
            // Safety: session must still be shadow (skip when import is mocked for self-tests).
            orange_restore_staging_assert_safe_target($pdo, $shadowDb);
        }
        if (!($sqlResult['ok'] ?? false)) {
            throw new RuntimeException('sql_import_failed');
        }

        $meta['statements_executed'] = (int) ($sqlResult['statements_executed'] ?? 0);
        $meta['backend'] = (string) ($manifest['export_backend'] ?? 'php_pdo');
        $meta['schema_revision'] = (int) ($manifest['schema_revision'] ?? 0);
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_VERIFYING,
            70,
            'Verifying shadow database restore',
            'shadow_restore_verification_started'
        );

        $shadowInv = orange_restore_shadow_inventory($pdo, $shadowDb);
        $prodInv = orange_restore_shadow_production_inventory_readonly($projectRoot, $env);
        $verify = orange_restore_shadow_verify($shadowInv, $manifest, $prodInv);
        if (!($verify['ok'] ?? false)) {
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_restore_verification_failed',
                'result' => 'fail',
                'errors' => array_slice($verify['errors'] ?? [], 0, 10),
            ]);
            throw new RuntimeException('shadow_verify_failed');
        }

        $report = [
            'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
            'generated_at' => gmdate('c'),
            'framework_job_id' => $jobId,
            'source_package_id' => $packageId,
            'shadow_db' => $shadowDb,
            'production_db' => $productionDb,
            'overall_result' => 'PASS',
            'sql_import' => [
                'ok' => true,
                'statements_executed' => (int) ($sqlResult['statements_executed'] ?? 0),
                'bytes_read' => (int) ($sqlResult['bytes_read'] ?? 0),
            ],
            'shadow_inventory' => [
                'table_count' => (int) ($shadowInv['table_count'] ?? 0),
                'view_count' => (int) ($shadowInv['view_count'] ?? 0),
                'routine_count' => (int) ($shadowInv['routine_count'] ?? 0),
                'trigger_count' => (int) ($shadowInv['trigger_count'] ?? 0),
                'event_count' => (int) ($shadowInv['event_count'] ?? 0),
                'charset' => (string) ($shadowInv['charset'] ?? ''),
                'collation' => (string) ($shadowInv['collation'] ?? ''),
                'schema_revision' => (int) ($shadowInv['schema_revision'] ?? 0),
                'total_rows' => (int) ($shadowInv['total_rows'] ?? 0),
                'tables' => array_slice($shadowInv['tables'] ?? [], 0, 200),
                'row_counts_sample' => array_slice($shadowInv['row_counts'] ?? [], 0, 50, true),
            ],
            'package_compare' => $verify['package_compare'],
            'production_compare' => $verify['production_compare'],
            'warnings' => $verify['warnings'],
            'errors' => [],
            'production_touched' => false,
            'cutover_performed' => false,
            'files_restored' => false,
            'maintenance_enabled' => false,
            'execution_started' => false,
            'application_switched_to_shadow' => false,
            'warning' => 'Shadow restore only — production database was not modified.',
        ];
        orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), $report);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_restore_verification_passed',
            'result' => 'ok',
            'shadow_db' => $shadowDb,
            'table_count' => (int) ($shadowInv['table_count'] ?? 0),
        ]);

        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
        $meta['verify_result'] = 'PASS';
        $meta['ready'] = true;
        $meta['failure_code'] = '';
        $meta['execution_started'] = false;
        $meta['production_touched'] = false;
        $meta['cutover_performed'] = false;
        $meta['files_restored'] = false;
        $meta['maintenance_enabled'] = false;
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_READY,
            100,
            'Shadow database restore ready (production untouched)',
            'shadow_restore_ready'
        );
        $job['shadow_restore_file'] = ORANGE_RESTORE_SHADOW_META_FILE;
        $job['shadow_restore_report_file'] = ORANGE_RESTORE_SHADOW_REPORT_FILE;
        $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_shadow_release_lock($workRoot, $jobId);

        return [
            'ok' => true,
            'idempotent' => false,
            'result' => 'PASS',
            'job_id' => $jobId,
            'shadow_db' => $shadowDb,
            'verify' => 'PASS',
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
            'report' => orange_restore_shadow_public_report($report),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'shadow_restore_failed';
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
        $meta['ready'] = false;
        $meta['failure_code'] = $code;
        $meta['verify_result'] = 'FAIL';
        $meta['execution_started'] = false;
        $meta['cli_needed'] = true;
        $meta['production_touched'] = false;
        try {
            orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
            $failReport = [
                'report_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
                'generated_at' => gmdate('c'),
                'framework_job_id' => $jobId,
                'overall_result' => 'FAIL',
                'failure_code' => $code,
                'production_touched' => false,
                'cutover_performed' => false,
                'execution_started' => false,
            ];
            orange_restore_shadow_write_json(orange_restore_shadow_report_path($workRoot, $jobId), $failReport);
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
                ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
                100,
                'Shadow restore failed: ' . $code,
                'shadow_restore_failed'
            );
            $failed = orange_restore_fw_read($workRoot, $jobId);
            $failed['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
            $failed['execution_started'] = false;
            orange_restore_fw_write($workRoot, $failed);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_restore_failed',
                'result' => 'fail',
                'code' => $code,
            ]);
        } catch (Throwable) {
            // best-effort forensic preserve
        }
        orange_restore_shadow_release_lock($workRoot, $jobId);

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'shadow_db' => (string) ($meta['shadow_db'] ?? ''),
            'verify' => 'FAIL',
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_public_meta($meta),
        ];
    }
}
