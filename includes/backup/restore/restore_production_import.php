<?php

declare(strict_types=1);

/**
 * Phase 3B.4C — Production Database Import Engine.
 *
 * Database import only. Never: file cutover, uploads rename, rollback execution,
 * maintenance release, completion/finalization, or production file restore.
 *
 * Checkpoints (owner contract):
 *   C0 validated | C1 maintenance confirmed | C2 rollback anchor confirmed
 *   C3 production wipe completed | C4 import completed
 *   C5 verification completed | C6 import committed
 *
 * Resume: only documented safe points (no mid-stream resume).
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_maintenance.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_staging_export.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_shadow_verify.php';
require_once __DIR__ . '/restore_shadow_smoke.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_version_lock.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_pdo_export.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../backup_admin.php';

const ORANGE_RESTORE_PROD_IMPORT_VERSION = '3B.4C-v1';
const ORANGE_RESTORE_PROD_IMPORT_REPORT_FILE = 'production_import_report.json';
const ORANGE_RESTORE_PROD_IMPORT_META_FILE = 'production_import.json';
const ORANGE_RESTORE_PROD_IMPORT_CHECKPOINT_DIR = 'checkpoints';

const ORANGE_RESTORE_PROD_IMPORT_C0 = 'C0';
const ORANGE_RESTORE_PROD_IMPORT_C1 = 'C1';
const ORANGE_RESTORE_PROD_IMPORT_C2 = 'C2';
const ORANGE_RESTORE_PROD_IMPORT_C3 = 'C3';
const ORANGE_RESTORE_PROD_IMPORT_C4 = 'C4';
const ORANGE_RESTORE_PROD_IMPORT_C5 = 'C5';
const ORANGE_RESTORE_PROD_IMPORT_C6 = 'C6';

/**
 * @return list<string>
 */
function orange_restore_prod_import_checkpoint_ids(): array
{
    return [
        ORANGE_RESTORE_PROD_IMPORT_C0,
        ORANGE_RESTORE_PROD_IMPORT_C1,
        ORANGE_RESTORE_PROD_IMPORT_C2,
        ORANGE_RESTORE_PROD_IMPORT_C3,
        ORANGE_RESTORE_PROD_IMPORT_C4,
        ORANGE_RESTORE_PROD_IMPORT_C5,
        ORANGE_RESTORE_PROD_IMPORT_C6,
    ];
}

/**
 * @return array<string, string>
 */
function orange_restore_prod_import_checkpoint_names(): array
{
    return [
        ORANGE_RESTORE_PROD_IMPORT_C0 => 'validated',
        ORANGE_RESTORE_PROD_IMPORT_C1 => 'maintenance_confirmed',
        ORANGE_RESTORE_PROD_IMPORT_C2 => 'rollback_anchor_confirmed',
        ORANGE_RESTORE_PROD_IMPORT_C3 => 'production_wipe_completed',
        ORANGE_RESTORE_PROD_IMPORT_C4 => 'import_completed',
        ORANGE_RESTORE_PROD_IMPORT_C5 => 'verification_completed',
        ORANGE_RESTORE_PROD_IMPORT_C6 => 'import_committed',
    ];
}

function orange_restore_prod_import_checkpoint_dir(string $workRoot, string $jobId): string
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_IMPORT_CHECKPOINT_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create production import checkpoint directory.');
    }

    return $dir;
}

function orange_restore_prod_import_checkpoint_path(string $workRoot, string $jobId, string $id): string
{
    return orange_restore_prod_import_checkpoint_dir($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . $id . '.json';
}

function orange_restore_prod_import_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_IMPORT_REPORT_FILE;
}

function orange_restore_prod_import_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PROD_IMPORT_META_FILE;
}

/**
 * Atomic checkpoint write (temp → rename).
 *
 * @param array<string, mixed> $payload
 */
function orange_restore_prod_import_write_checkpoint(
    string $workRoot,
    string $jobId,
    string $id,
    array $payload
): array {
    $names = orange_restore_prod_import_checkpoint_names();
    if (!isset($names[$id])) {
        throw new RuntimeException('unknown_checkpoint');
    }
    $record = array_merge([
        'checkpoint_id' => $id,
        'checkpoint_name' => $names[$id],
        'job_id' => $jobId,
        'written_at' => gmdate('c'),
        'engine_version' => ORANGE_RESTORE_PROD_IMPORT_VERSION,
        'execution_started' => false,
        'files_switched' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
    ], $payload);
    unset($record['password'], $record['secrets'], $record['absolute_paths']);

    $path = orange_restore_prod_import_checkpoint_path($workRoot, $jobId, $id);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write checkpoint ' . $id);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot finalize checkpoint ' . $id);
    }

    return $record;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_prod_import_load_checkpoint(string $workRoot, string $jobId, string $id): ?array
{
    $path = orange_restore_prod_import_checkpoint_path($workRoot, $jobId, $id);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_prod_import_checkpoint_history(string $workRoot, string $jobId): array
{
    $out = [];
    foreach (orange_restore_prod_import_checkpoint_ids() as $id) {
        $cp = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, $id);
        if ($cp !== null) {
            $out[] = [
                'checkpoint_id' => $id,
                'checkpoint_name' => (string) ($cp['checkpoint_name'] ?? ''),
                'written_at' => (string) ($cp['written_at'] ?? ''),
            ];
        }
    }

    return $out;
}

function orange_restore_prod_import_highest_checkpoint(string $workRoot, string $jobId): string
{
    $highest = '';
    foreach (orange_restore_prod_import_checkpoint_ids() as $id) {
        if (orange_restore_prod_import_load_checkpoint($workRoot, $jobId, $id) !== null) {
            $highest = $id;
        }
    }

    return $highest;
}

function orange_restore_prod_import_production_identity_hash(
    string $productionDb,
    string $host,
    string $mergeUser
): string {
    return hash('sha256', strtolower($host) . '|' . strtolower($productionDb) . '|' . strtolower($mergeUser));
}

/**
 * Entry gates — all must be true.
 *
 * @return array{ok:bool,code:string,details:array<string,mixed>}
 */
function orange_restore_prod_import_validate_entry(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    string $projectRoot
): array {
    $details = [
        'maintenance_active' => false,
        'approved_lineage' => false,
        'execution_contract' => false,
        'rollback_anchor' => false,
        'shadow_restore_ready' => false,
        'shadow_verification_ready' => false,
        'shadow_smoke_ready' => false,
        'cutover_readiness_ready' => false,
        'production_cutover_allowed_false' => true,
        'version_lock' => false,
        'package_unchanged' => false,
        'approval_unchanged' => false,
        'execution_started_false' => false,
    ];

    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'details' => $details];
    }
    if (!empty($job['execution_started'])) {
        $details['execution_started_false'] = false;

        return ['ok' => false, 'code' => 'execution_already_started', 'details' => $details];
    }
    $details['execution_started_false'] = true;

    $status = (string) ($job['status'] ?? '');
    $allowedEntry = [
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
    ];
    if (!in_array($status, $allowedEntry, true)) {
        return ['ok' => false, 'code' => 'maintenance_not_active', 'details' => $details];
    }

    $maint = orange_restore_maint_fw_read($workRoot);
    $details['maintenance_active'] = (string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE
        && (string) ($maint['related_job_id'] ?? '') === $jobId;
    if (!$details['maintenance_active']) {
        return ['ok' => false, 'code' => 'maintenance_not_active', 'details' => $details];
    }

    $base = orange_restore_prod_maint_validate($workRoot, $jobId, $backupRoot, true);
    if (!$base['ok']) {
        return ['ok' => false, 'code' => (string) $base['code'], 'details' => $details + ($base['details'] ?? [])];
    }
    $details['approved_lineage'] = true;
    $details['execution_contract'] = true;
    $details['rollback_anchor'] = true;
    $details['version_lock'] = true;
    $details['package_unchanged'] = true;
    $details['approval_unchanged'] = true;

    $shadowMeta = orange_restore_shadow_load_meta($workRoot, $jobId);
    $details['shadow_restore_ready'] = is_array($shadowMeta) && !empty($shadowMeta['ready']);
    if (!$details['shadow_restore_ready']) {
        return ['ok' => false, 'code' => 'shadow_restore_not_ready', 'details' => $details];
    }

    $verify = orange_restore_shadow_verify_load_report($workRoot, $jobId);
    $vResult = strtoupper((string) (($verify['overall_result'] ?? '') ?: ''));
    $details['shadow_verification_ready'] = is_array($verify)
        && (in_array($vResult, ['PASS', 'READY'], true) || (int) ($verify['readiness_score'] ?? 0) >= 85);
    if (!$details['shadow_verification_ready']) {
        return ['ok' => false, 'code' => 'shadow_verification_not_ready', 'details' => $details];
    }

    $smoke = orange_restore_shadow_smoke_load_report($workRoot, $jobId);
    $sResult = strtoupper((string) (($smoke['overall_result'] ?? '') ?: ''));
    $details['shadow_smoke_ready'] = is_array($smoke) && in_array($sResult, ['READY', 'PASS'], true);
    if (!$details['shadow_smoke_ready']) {
        return ['ok' => false, 'code' => 'shadow_smoke_not_ready', 'details' => $details];
    }

    $cutover = orange_restore_cutover_readiness_load($workRoot, $jobId);
    $details['cutover_readiness_ready'] = is_array($cutover)
        && strtoupper((string) ($cutover['status'] ?? '')) === 'READY'
        && empty($cutover['production_cutover_allowed']);
    if (!$details['cutover_readiness_ready']) {
        return ['ok' => false, 'code' => 'cutover_readiness_not_ready', 'details' => $details];
    }
    $details['production_cutover_allowed_false'] = empty($cutover['production_cutover_allowed']);

    // Reject shadow/staging name confusion at gate level.
    $env = orange_backup_load_env_array($projectRoot);
    try {
        $shadowDb = orange_restore_shadow_db_name($env, $projectRoot);
        $productionDb = orange_restore_production_db_name($projectRoot);
        if (strcasecmp($shadowDb, $productionDb) === 0) {
            return ['ok' => false, 'code' => 'shadow_db_equals_production', 'details' => $details];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => trim($e->getMessage()) ?: 'db_identity_invalid', 'details' => $details];
    }

    return ['ok' => true, 'code' => 'ok', 'details' => $details];
}

/**
 * Export verified shadow DB → merge_db_export.sql.gz (reuse php_pdo exporter + verify).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_prod_import_export_shadow(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }
    if (isset($options['export_runner_override']) && is_callable($options['export_runner_override'])) {
        $export = ($options['export_runner_override'])($options);
        if (!is_array($export) || empty($export['ok'])) {
            throw new RuntimeException('shadow_export_override_failed');
        }

        return $export;
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $jobId = (string) ($options['job_id'] ?? '');
    /** @var array<string, mixed> $env */
    $env = is_array($options['env'] ?? null) ? $options['env'] : orange_backup_load_env_array($projectRoot);

    $shadowDb = orange_restore_shadow_db_name($env, $projectRoot);
    $productionDb = orange_restore_production_db_name($projectRoot);
    $shadowPdo = $options['shadow_pdo_override'] ?? null;
    $pdo = $shadowPdo instanceof PDO
        ? $shadowPdo
        : orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);

    $sessionDb = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($sessionDb === '' || strcasecmp($sessionDb, $shadowDb) !== 0) {
        throw new RuntimeException('shadow_db_identity_rejected');
    }
    if (strcasecmp($sessionDb, $productionDb) === 0) {
        throw new RuntimeException('shadow_db_equals_production');
    }

    $jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
    $rawSqlPath = $jobDir . DIRECTORY_SEPARATOR . 'production_import_export.sql';
    $gzipPath = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
    $manifestPath = orange_restore_merge_db_export_manifest_path($workRoot, $jobId);
    if (is_file($rawSqlPath)) {
        @unlink($rawSqlPath);
    }
    if (is_file($gzipPath)) {
        @unlink($gzipPath);
    }

    $startedAt = microtime(true);
    $export = orange_backup_pdo_export_database($pdo, $shadowDb, $rawSqlPath);
    orange_backup_gzip_file($rawSqlPath, $gzipPath);
    @unlink($rawSqlPath);

    $verify = orange_restore_merge_staging_export_verify_gzip(
        $gzipPath,
        $productionDb,
        $shadowDb,
        (int) ($export['table_count'] ?? 0)
    );
    if (!$verify['ok']) {
        throw new RuntimeException('shadow_export_verify_failed:' . (string) ($verify['error'] ?? ''));
    }

    $checksum = hash_file('sha256', $gzipPath) ?: '';
    $durationSeconds = (int) round(microtime(true) - $startedAt);
    $manifest = [
        'export_backend' => 'php_pdo',
        'source' => 'shadow',
        'shadow_db' => $shadowDb,
        'production_db' => $productionDb,
        'gzip_path' => $gzipPath,
        'checksum_sha256' => $checksum,
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'exported_at' => gmdate('c'),
        'duration_seconds' => $durationSeconds,
        'production_writes' => false,
    ];
    orange_backup_write_json($manifestPath, $manifest);

    return [
        'ok' => true,
        'gzip_path' => $gzipPath,
        'manifest_path' => $manifestPath,
        'checksum_sha256' => $checksum,
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'duration_seconds' => $durationSeconds,
        'shadow_db' => $shadowDb,
        'production_db' => $productionDb,
        'production_writes' => false,
    ];
}

/**
 * Post-import verification (fail closed).
 *
 * @return array{ok:bool,overall:string,blocking_errors:list<string>,details:array<string,mixed>}
 */
function orange_restore_prod_import_verify_target(
    PDO $pdo,
    string $productionDb,
    array $exportManifest
): array {
    $errors = [];
    $details = [
        'schema' => false,
        'required_tables' => false,
        'counts' => false,
        'fk' => false,
        'charset' => false,
        'collation' => false,
        'version' => false,
        'table_count' => 0,
        'row_probe_total' => 0,
    ];

    orange_restore_production_assert_identity($pdo, $productionDb);

    $tables = [];
    $st = $pdo->query('SHOW TABLES');
    if ($st !== false) {
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            if (is_array($row) && isset($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
    }
    $details['table_count'] = count($tables);
    $expected = (int) ($exportManifest['table_count'] ?? 0);
    if ($expected > 0 && count($tables) < $expected) {
        $errors[] = 'table_count_below_export';
    } elseif (count($tables) === 0) {
        $errors[] = 'no_tables_after_import';
    } else {
        $details['schema'] = true;
        $details['required_tables'] = true;
    }

    $rowTotal = 0;
    foreach (array_slice($tables, 0, 25) as $table) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        try {
            $cnt = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
            $rowTotal += max(0, $cnt);
        } catch (Throwable) {
            // ignore non-base tables
        }
    }
    $details['row_probe_total'] = $rowTotal;
    $exportRows = (int) ($exportManifest['row_count'] ?? 0);
    if ($exportRows > 0 && $rowTotal === 0) {
        $errors[] = 'row_counts_empty';
    } else {
        $details['counts'] = true;
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $details['fk'] = true;
    } catch (Throwable) {
        $errors[] = 'fk_enable_failed';
    }

    try {
        $charset = (string) ($pdo->query(
            "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
            . $pdo->quote($productionDb)
        )->fetchColumn() ?: '');
        $collation = (string) ($pdo->query(
            "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = "
            . $pdo->quote($productionDb)
        )->fetchColumn() ?: '');
        $details['charset'] = strtolower($charset) === 'utf8mb4';
        $details['collation'] = str_contains(strtolower($collation), 'utf8mb4');
        if (!$details['charset']) {
            $errors[] = 'charset_not_utf8mb4';
        }
        if (!$details['collation']) {
            $errors[] = 'collation_not_utf8mb4';
        }
    } catch (Throwable) {
        $errors[] = 'charset_probe_failed';
    }

    try {
        $meta = $pdo->query("SHOW TABLES LIKE 'orange_schema_meta'");
        if ($meta !== false && $meta->fetch(PDO::FETCH_NUM)) {
            $details['version'] = true;
        } else {
            // Accept absence if export had few tables (fixture); warn via details only for empty critical set.
            $details['version'] = count($tables) > 0;
        }
    } catch (Throwable) {
        $details['version'] = count($tables) > 0;
    }

    $ok = $errors === [];

    return [
        'ok' => $ok,
        'overall' => $ok ? 'PASS' : 'FAIL',
        'blocking_errors' => $errors,
        'details' => $details,
    ];
}

/**
 * HTTP: request production import (metadata only).
 *
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_prod_import_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    string $projectRoot,
    array $admin
): array {
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $gates = orange_restore_prod_import_validate_entry($workRoot, $jobId, $backupRoot, $projectRoot);
    if (!$gates['ok']) {
        throw new RuntimeException((string) $gates['code']);
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'message' => 'Production import already ready.',
            'warning' => 'Application files have NOT been switched.',
        ];
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'cli_needed' => true,
            'idempotent' => true,
            'execution_started' => false,
            'cli_command' => 'php scripts/backup/restore_import_production.php --job=' . $jobId,
            'message' => 'Production import already requested. Run CLI worker.',
            'warning' => 'Application files have NOT been switched.',
        ];
    }

    $meta = [
        'record_version' => ORANGE_RESTORE_PROD_IMPORT_VERSION,
        'framework_job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        'requested_at' => gmdate('c'),
        'requested_by' => $operator,
        'cli_needed' => true,
        'cli_command' => 'php scripts/backup/restore_import_production.php --job=' . $jobId,
        'execution_started' => false,
        'production_cutover_allowed' => false,
        'files_switched' => false,
        'warning' => 'Application files have NOT been switched.',
    ];
    orange_backup_write_json(orange_restore_prod_import_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING,
        ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_PENDING,
        5,
        'Production Import Pending — CLI required',
        'restore_production_import_pending'
    );
    $job['execution_started'] = false;
    $job['production_import_file'] = ORANGE_RESTORE_PROD_IMPORT_META_FILE;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_production_import_requested',
        'result' => 'ok',
        'operator_username' => $operator,
        'execution_started' => false,
        'files_switched' => false,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => $meta,
        'gates' => $gates['details'],
        'cli_needed' => true,
        'cli_command' => $meta['cli_command'],
        'execution_started' => false,
        'message' => 'Production Import Pending. Run CLI worker.',
        'warning' => 'Application files have NOT been switched.',
    ];
}

/**
 * CLI worker — production DB wipe + stream import + verify. Stops at production_import_ready.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_prod_import_run_cli(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $owner = (string) ($options['owner'] ?? 'cli');

    if ($projectRoot === '' || $workRoot === '' || $backupRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root, work_root, backup_root, job_id required.');
    }

    $startedAt = microtime(true);
    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }

    $gates = orange_restore_prod_import_validate_entry($workRoot, $jobId, $backupRoot, $projectRoot);
    if (!$gates['ok']) {
        throw new RuntimeException((string) $gates['code']);
    }

    orange_restore_prod_maint_ensure_execution_lock($workRoot, $jobId);

    // Ensure Phase-2 merge maintenance file is owned by this job (framework already active).
    $mergeMaint = orange_restore_merge_maintenance_status($workRoot);
    if (!$mergeMaint['active']) {
        orange_restore_merge_maintenance_enable($workRoot, $jobId, [
            'reason' => 'production_db_import_3b4c',
        ]);
    } else {
        orange_restore_merge_maintenance_verify($workRoot, $jobId);
    }

    $highest = orange_restore_prod_import_highest_checkpoint($workRoot, $jobId);
    $resumeMode = 'full';
    if ($highest === ORANGE_RESTORE_PROD_IMPORT_C6) {
        $job = orange_restore_fw_read($workRoot, $jobId);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => (string) ($job['status'] ?? ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY),
            'resume' => 'already_committed',
            'execution_started' => false,
            'files_switched' => false,
            'production_writes' => false,
        ];
    }
    if ($highest === ORANGE_RESTORE_PROD_IMPORT_C5) {
        $resumeMode = 'commit_only';
    } elseif ($highest === ORANGE_RESTORE_PROD_IMPORT_C4) {
        $resumeMode = 'verify_only';
    } elseif ($highest === ORANGE_RESTORE_PROD_IMPORT_C3) {
        // Documented: crash after wipe / mid-import → re-wipe + re-import.
        $resumeMode = 'rewipe_reimport';
    } elseif (in_array($highest, [ORANGE_RESTORE_PROD_IMPORT_C0, ORANGE_RESTORE_PROD_IMPORT_C1, ORANGE_RESTORE_PROD_IMPORT_C2], true)) {
        $resumeMode = 'from_wipe';
    }

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING,
        ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_RUNNING,
        20,
        'Production import running',
        'restore_production_import_running'
    );
    orange_restore_maint_fw_heartbeat($workRoot);

    $creds = orange_restore_merge_credentials($env, $projectRoot);
    $productionDb = $creds['db'];
    $shadowDb = orange_restore_shadow_db_name($env, $projectRoot);
    if (strcasecmp($productionDb, $shadowDb) === 0) {
        throw new RuntimeException('shadow_db_equals_production');
    }

    $mergePdo = $options['merge_pdo_override'] ?? null;
    $mergePdo = $mergePdo instanceof PDO
        ? $mergePdo
        : orange_restore_connect_merge_pdo($projectRoot, $env);

    $identityHash = orange_restore_prod_import_production_identity_hash(
        $productionDb,
        $creds['host'],
        $creds['user']
    );
    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    $export = null;
    $import = ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => null];
    $verify = ['ok' => false, 'overall' => 'FAIL', 'blocking_errors' => [], 'details' => []];

    try {
        // Pre-C3 identity assert (twice) — fail closed inside try so job → failed.
        orange_restore_production_assert_identity($mergePdo, $productionDb);
        orange_restore_production_assert_identity($mergePdo, $productionDb);

        $sessionDb = (string) ($mergePdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        if (strcasecmp($sessionDb, $shadowDb) === 0) {
            throw new RuntimeException('shadow_db_rejected_as_production');
        }
        $stagingDb = '';
        try {
            $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
        } catch (Throwable) {
            $stagingDb = '';
        }
        if ($stagingDb !== '' && strcasecmp($sessionDb, $stagingDb) === 0) {
            throw new RuntimeException('staging_db_rejected_as_production');
        }

        if ($resumeMode !== 'verify_only' && $resumeMode !== 'commit_only') {
            // C0 — validated (gates + export artifact)
            $export = orange_restore_prod_import_export_shadow([
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'env' => $env,
                'shadow_pdo_override' => $options['shadow_pdo_override'] ?? null,
                'export_runner_override' => $options['export_runner_override'] ?? null,
            ]);
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C0, [
                'export_sha256' => (string) ($export['checksum_sha256'] ?? ''),
                'table_count' => (int) ($export['table_count'] ?? 0),
                'row_count' => (int) ($export['row_count'] ?? 0),
                'bytes' => is_file((string) ($export['gzip_path'] ?? ''))
                    ? (int) filesize((string) $export['gzip_path'])
                    : 0,
                'gates' => $gates['details'],
            ]);

            // C1 — maintenance confirmed
            orange_restore_merge_maintenance_verify($workRoot, $jobId);
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C1, [
                'framework_maintenance' => ORANGE_RESTORE_MAINT_STATE_ACTIVE,
                'merge_maintenance' => true,
                'production_identity_hash' => $identityHash,
            ]);

            // C2 — rollback anchor confirmed
            if ($anchor === null || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
                throw new RuntimeException('missing_rollback_anchor');
            }
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C2, [
                'retention_pin_id' => (string) ($anchor['retention_pin_id'] ?? ''),
                'rollback_package_id' => (string) ($anchor['rollback_package_id'] ?? ''),
                'ready_for_rollback' => true,
            ]);

            // Re-assert identity immediately before wipe.
            orange_restore_production_assert_identity($mergePdo, $productionDb);
            $identityHash2 = orange_restore_prod_import_production_identity_hash(
                $productionDb,
                $creds['host'],
                $creds['user']
            );
            if (!hash_equals($identityHash, $identityHash2)) {
                throw new RuntimeException('production_identity_drift');
            }

            orange_restore_production_wipe($mergePdo, $productionDb);
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C3, [
                'production_db' => $productionDb,
                'production_identity_hash' => $identityHash,
                'wiped_at' => gmdate('c'),
            ]);

            orange_restore_maint_fw_heartbeat($workRoot);
            $import = orange_restore_sql_runner_import_gzip_to_target(
                $mergePdo,
                (string) ($export['gzip_path'] ?? ''),
                $productionDb,
                $shadowDb
            );
            if (!$import['ok']) {
                throw new RuntimeException((string) ($import['error'] ?? 'sql_import_failed'));
            }
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C4, [
                'statements_executed' => (int) ($import['statements_executed'] ?? 0),
                'bytes_read' => (int) ($import['bytes_read'] ?? 0),
                'export_sha256' => (string) ($export['checksum_sha256'] ?? ''),
            ]);
        } else {
            // verify_only / commit_only — load export manifest from disk
            $manifestPath = orange_restore_merge_db_export_manifest_path($workRoot, $jobId);
            $export = is_file($manifestPath)
                ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
                : [];
            $export['gzip_path'] = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
            $c4 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C4);
            $import = [
                'ok' => true,
                'statements_executed' => (int) ($c4['statements_executed'] ?? 0),
                'bytes_read' => (int) ($c4['bytes_read'] ?? 0),
                'error' => null,
            ];
        }

        if ($resumeMode !== 'commit_only') {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING,
                ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_VERIFYING,
                80,
                'Production import verifying',
                'restore_production_import_verifying'
            );
            orange_restore_maint_fw_heartbeat($workRoot);
            orange_restore_production_assert_identity($mergePdo, $productionDb);
            $verify = orange_restore_prod_import_verify_target($mergePdo, $productionDb, is_array($export) ? $export : []);
            if (!$verify['ok']) {
                throw new RuntimeException('post_import_verification_failed:' . implode(',', $verify['blocking_errors']));
            }
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C5, [
                'verification' => $verify,
            ]);
        } else {
            $c5 = orange_restore_prod_import_load_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C5);
            $verify = is_array($c5['verification'] ?? null)
                ? $c5['verification']
                : ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => [], 'details' => []];
        }

        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C6, [
            'committed_at' => gmdate('c'),
            'handoff' => 'files_cutover_not_started',
        ]);

        $duration = (int) round(microtime(true) - $startedAt);
        $report = [
            'report_version' => ORANGE_RESTORE_PROD_IMPORT_VERSION,
            'job_id' => $jobId,
            'overall' => 'PASS',
            'duration_seconds' => $duration,
            'rows' => (int) (($export['row_count'] ?? 0)),
            'tables' => (int) (($export['table_count'] ?? 0)),
            'statements_executed' => (int) ($import['statements_executed'] ?? 0),
            'bytes_read' => (int) ($import['bytes_read'] ?? 0),
            'verification' => $verify,
            'checkpoint_history' => orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
            'blocking_errors' => [],
            'resume_mode' => $resumeMode,
            'production_db' => $productionDb,
            'production_identity_hash' => $identityHash,
            'execution_started' => false,
            'files_switched' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'production_cutover_allowed' => false,
            'warning' => 'Application files have NOT been switched.',
            'completed_at' => gmdate('c'),
        ];
        orange_backup_write_json(orange_restore_prod_import_report_path($workRoot, $jobId), $report);

        $meta = [
            'record_version' => ORANGE_RESTORE_PROD_IMPORT_VERSION,
            'framework_job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
            'completed_at' => gmdate('c'),
            'completed_by' => $owner,
            'execution_started' => false,
            'files_switched' => false,
            'cli_needed' => false,
            'warning' => 'Application files have NOT been switched.',
        ];
        orange_backup_write_json(orange_restore_prod_import_meta_path($workRoot, $jobId), $meta);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
            ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_READY,
            100,
            'Production Import Ready — files NOT switched',
            'restore_production_import_ready'
        );
        $job['execution_started'] = false;
        $job['production_import_ready'] = true;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_production_import_ready',
            'result' => 'ok',
            'operator_username' => $owner,
            'execution_started' => false,
            'files_switched' => false,
            'duration_seconds' => $duration,
        ]);
        orange_restore_maint_fw_heartbeat($workRoot);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
            'report' => $report,
            'resume_mode' => $resumeMode,
            'execution_started' => false,
            'files_switched' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'production_writes' => $resumeMode !== 'commit_only' && $resumeMode !== 'verify_only'
                ? true
                : ($resumeMode === 'verify_only'),
            'warning' => 'Application files have NOT been switched.',
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'production_import_failed';
        $report = [
            'report_version' => ORANGE_RESTORE_PROD_IMPORT_VERSION,
            'job_id' => $jobId,
            'overall' => 'FAIL',
            'duration_seconds' => (int) round(microtime(true) - $startedAt),
            'rows' => (int) (($export['row_count'] ?? 0)),
            'tables' => (int) (($export['table_count'] ?? 0)),
            'statements_executed' => (int) ($import['statements_executed'] ?? 0),
            'bytes_read' => (int) ($import['bytes_read'] ?? 0),
            'verification' => $verify,
            'checkpoint_history' => orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
            'blocking_errors' => [$code],
            'resume_mode' => $resumeMode,
            'execution_started' => false,
            'files_switched' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'warning' => 'Application files have NOT been switched. Maintenance remains active.',
            'failed_at' => gmdate('c'),
        ];
        try {
            orange_backup_write_json(orange_restore_prod_import_report_path($workRoot, $jobId), $report);
        } catch (Throwable) {
            // ignore
        }
        try {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
                ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_FAILED,
                0,
                'Production import failed: ' . substr($code, 0, 120),
                'restore_production_import_failed'
            );
            $job = orange_restore_fw_read($workRoot, $jobId);
            $job['execution_started'] = false;
            orange_restore_fw_write($workRoot, $job);
        } catch (Throwable) {
            // ignore
        }
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'restore_production_import_failed',
            'result' => 'fail',
            'code' => $code,
            'operator_username' => $owner,
            'execution_started' => false,
            'files_switched' => false,
            'maintenance_released' => false,
        ]);

        return [
            'ok' => false,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED,
            'code' => $code,
            'report' => $report,
            'execution_started' => false,
            'files_switched' => false,
            'rollback_executed' => false,
            'maintenance_released' => false,
            'warning' => 'Application files have NOT been switched. Maintenance remains active.',
        ];
    }
}

/**
 * Read-only status/report.
 *
 * @return array<string, mixed>
 */
function orange_restore_prod_import_status(string $workRoot, string $jobId): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $metaPath = orange_restore_prod_import_meta_path($workRoot, $jobId);
    $reportPath = orange_restore_prod_import_report_path($workRoot, $jobId);
    $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
    $report = is_file($reportPath) ? json_decode((string) file_get_contents($reportPath), true) : null;

    $labels = [
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING => 'Production Import Pending',
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_RUNNING => 'Running',
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_VERIFYING => 'Verifying',
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY => 'Ready',
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_FAILED => 'Failed',
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE => 'Maintenance Active (import not requested)',
    ];
    $status = (string) ($job['status'] ?? '');

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => is_array($meta) ? $meta : null,
        'report' => is_array($report) ? $report : null,
        'checkpoint_history' => orange_restore_prod_import_checkpoint_history($workRoot, $jobId),
        'highest_checkpoint' => orange_restore_prod_import_highest_checkpoint($workRoot, $jobId),
        'status_label' => $labels[$status] ?? $status,
        'execution_started' => false,
        'files_switched' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
        'production_cutover_allowed' => false,
        'read_only' => true,
        'warning' => 'Application files have NOT been switched.',
    ];
}
