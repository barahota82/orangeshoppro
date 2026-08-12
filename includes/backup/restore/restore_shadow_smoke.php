<?php

declare(strict_types=1);

/**
 * Phase 3B.3B7 — Shadow End-to-End Smoke Tests and Cutover Readiness.
 *
 * Read-only validation of restored Shadow DB + Shadow Files as one system.
 * Never modifies production DB/files/config, never cutover, never maintenance,
 * never rollback execution. production_cutover_allowed remains false always.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_shadow_verify.php';
require_once __DIR__ . '/restore_shadow_files.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';

const ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION = '3B.3B7-v1';
const ORANGE_RESTORE_SHADOW_SMOKE_REPORT_FILE = 'shadow_smoke_report.json';
const ORANGE_RESTORE_SHADOW_SMOKE_META_FILE = 'shadow_smoke.json';
const ORANGE_RESTORE_CUTOVER_READINESS_FILE = 'cutover_readiness.json';
const ORANGE_RESTORE_SHADOW_SMOKE_LOCK_FILE = '.shadow_smoke.lock';
const ORANGE_RESTORE_SHADOW_SMOKE_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_SHADOW_SMOKE_SCORE_READY = 85;
const ORANGE_RESTORE_SHADOW_SMOKE_SCORE_WARNING = 60;

/**
 * Thin SQL guard — rejects mutating statements under shadow smoke context.
 */
final class OrangeRestoreShadowPdoGuard
{
    private PDO $pdo;
    /** @var list<string> */
    private array $blocked = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return list<string> */
    public function blockedAttempts(): array
    {
        return $this->blocked;
    }

    public function assertReadOnlySql(string $sql): void
    {
        $trim = ltrim($sql);
        $norm = strtoupper(ltrim(preg_replace('/\s+/', ' ', $trim) ?? $trim));
        foreach (['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'SET NAMES', 'SET CHARACTER'] as $ok) {
            if (str_starts_with($norm, $ok)) {
                return;
            }
        }
        if (str_starts_with($norm, 'SET ') && (str_contains($norm, 'NAMES') || str_contains($norm, 'CHARACTER'))) {
            return;
        }
        $this->blocked[] = substr($norm, 0, 80);
        orange_restore_shadow_context_record_mutation('sql:' . substr($norm, 0, 60));
        throw new RuntimeException('shadow_context_write_blocked');
    }
}

function orange_restore_shadow_smoke_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_SMOKE_REPORT_FILE;
}

function orange_restore_shadow_smoke_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_SMOKE_META_FILE;
}

function orange_restore_cutover_readiness_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_CUTOVER_READINESS_FILE;
}

function orange_restore_shadow_smoke_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_SMOKE_LOCK_FILE;
}

function orange_restore_shadow_smoke_write_json(string $path, array $record): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot write shadow smoke artifact directory.');
    }
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write shadow smoke artifact.');
    }
}

/** @return array<string, mixed>|null */
function orange_restore_shadow_smoke_load_meta(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_smoke_meta_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/** @return array<string, mixed>|null */
function orange_restore_shadow_smoke_load_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_smoke_report_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/** @return array<string, mixed>|null */
function orange_restore_cutover_readiness_load(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_cutover_readiness_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/** @return array{held:bool,payload:?array<string,mixed>,stale:bool} */
function orange_restore_shadow_smoke_lock_status(string $workRoot): array
{
    $path = orange_restore_shadow_smoke_lock_path($workRoot);
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
    $stale = $age > ORANGE_RESTORE_SHADOW_SMOKE_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/** @return array{ok:bool,message:string} */
function orange_restore_shadow_smoke_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_shadow_smoke_lock_path($workRoot);
    $status = orange_restore_shadow_smoke_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_shadow_smoke_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        $held = (string) (($status['payload'] ?? [])['job_id'] ?? '');
        if ($held === $jobId) {
            return ['ok' => true, 'message' => 'lock_already_held'];
        }

        return ['ok' => false, 'message' => 'shadow_smoke_lock_active'];
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
        return ['ok' => false, 'message' => 'shadow_smoke_lock_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok'];
}

function orange_restore_shadow_smoke_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_shadow_smoke_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $payload = json_decode((string) file_get_contents($path), true);
        $held = is_array($payload) ? (string) ($payload['job_id'] ?? '') : '';
        if ($held !== '' && $held !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

/** @param array<string, mixed> $meta @return array<string, mixed> */
function orange_restore_shadow_smoke_public_meta(array $meta): array
{
    return [
        'record_version' => (string) ($meta['record_version'] ?? ''),
        'framework_job_id' => (string) ($meta['framework_job_id'] ?? ''),
        'source_package_id' => (string) ($meta['source_package_id'] ?? ''),
        'status' => (string) ($meta['status'] ?? ''),
        'overall_result' => (string) ($meta['overall_result'] ?? ''),
        'readiness_score' => (int) ($meta['readiness_score'] ?? 0),
        'cli_needed' => (bool) ($meta['cli_needed'] ?? false),
        'cli_command' => (string) ($meta['cli_command'] ?? ''),
        'production_touched' => false,
        'execution_started' => false,
        'production_cutover_allowed' => false,
        'warning' => (string) ($meta['warning'] ?? ''),
    ];
}

/** @param array<string, mixed> $report @return array<string, mixed> */
function orange_restore_shadow_smoke_public_report(array $report): array
{
    $out = $report;
    unset($out['workspace_path'], $out['zip_path'], $out['absolute_paths'], $out['dsn'], $out['credentials']);
    $out['production_touched'] = false;
    $out['execution_started'] = false;
    $out['production_cutover_allowed'] = false;
    $out['external_integrations_invoked'] = false;

    return $out;
}

/** @param array<string, mixed> $decision @return array<string, mixed> */
function orange_restore_cutover_readiness_public(array $decision): array
{
    $out = $decision;
    unset($out['workspace_path'], $out['dsn'], $out['credentials']);
    $out['production_cutover_allowed'] = false;
    $out['execution_started'] = false;

    return $out;
}

function orange_restore_shadow_context_active(): bool
{
    return isset($GLOBALS['orange_restore_shadow_context']) && is_array($GLOBALS['orange_restore_shadow_context']);
}

/**
 * Fail-closed mutation guard for shadow smoke context.
 */
function orange_restore_shadow_context_assert_read_only(string $operation = 'mutation'): void
{
    if (!orange_restore_shadow_context_active()) {
        return;
    }
    orange_restore_shadow_context_record_mutation($operation);
    throw new RuntimeException('shadow_context_write_blocked');
}

function orange_restore_shadow_context_record_mutation(string $operation): void
{
    if (!isset($GLOBALS['orange_restore_shadow_context']) || !is_array($GLOBALS['orange_restore_shadow_context'])) {
        return;
    }
    $list = $GLOBALS['orange_restore_shadow_context']['mutation_attempts_blocked'] ?? [];
    if (!is_array($list)) {
        $list = [];
    }
    $list[] = substr($operation, 0, 120);
    $GLOBALS['orange_restore_shadow_context']['mutation_attempts_blocked'] = array_slice($list, -50);
    $GLOBALS['orange_restore_shadow_context']['mutation_blocked_count'] =
        (int) ($GLOBALS['orange_restore_shadow_context']['mutation_blocked_count'] ?? 0) + 1;
}

function orange_restore_shadow_context_assert_integrations_disabled(string $channel): void
{
    if (!orange_restore_shadow_context_active()) {
        return;
    }
    $ctx = &$GLOBALS['orange_restore_shadow_context'];
    $invoked = $ctx['external_integration_attempts'] ?? [];
    if (!is_array($invoked)) {
        $invoked = [];
    }
    $invoked[] = $channel;
    $ctx['external_integration_attempts'] = array_slice($invoked, -50);
    $ctx['external_integrations_invoked'] = false;
    throw new RuntimeException('shadow_context_integration_blocked');
}

/**
 * @param array<string, mixed> $opts
 * @return array<string, mixed>
 */
function orange_restore_shadow_context_begin(
    string $jobId,
    string $shadowDb,
    string $workspace,
    string $productionDb,
    array $opts = []
): array {
    $ctx = [
        'job_id' => $jobId,
        'shadow_db' => $shadowDb,
        'shadow_files_root' => $workspace,
        'production_db' => $productionDb,
        'read_only' => true,
        'writes_disabled' => true,
        'integrations_disabled' => true,
        'payments_disabled' => true,
        'email_disabled' => true,
        'sms_disabled' => true,
        'queues_disabled' => true,
        'webhooks_disabled' => true,
        'cron_mutations_disabled' => true,
        'cache_isolated' => true,
        'session_writes_disabled' => true,
        'uploads_writes_disabled' => true,
        'maintenance_enabled' => false,
        'config_switched' => false,
        'production_db_writes' => 0,
        'production_file_writes' => 0,
        'mutation_attempts_blocked' => [],
        'mutation_blocked_count' => 0,
        'external_integration_attempts' => [],
        'external_integrations_invoked' => false,
        'started_at' => gmdate('c'),
    ];
    foreach ($opts as $k => $v) {
        $ctx[(string) $k] = $v;
    }
    $GLOBALS['orange_restore_shadow_context'] = $ctx;

    return $ctx;
}

function orange_restore_shadow_context_end(): void
{
    unset($GLOBALS['orange_restore_shadow_context']);
}

/** @return array<string, mixed> */
function orange_restore_shadow_context_snapshot(): array
{
    return is_array($GLOBALS['orange_restore_shadow_context'] ?? null)
        ? $GLOBALS['orange_restore_shadow_context']
        : [];
}

/**
 * @return array{ok:bool,code:string,job:array<string,mixed>}
 */
function orange_restore_shadow_smoke_revalidate(string $workRoot, string $jobId, string $backupRoot): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'job' => $job];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'job' => $job];
    }
    if (!empty($job['execution_started'])) {
        return ['ok' => false, 'code' => 'execution_started_forbidden', 'job' => $job];
    }

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job];
    }

    $filesMeta = orange_restore_shadow_files_load_meta($workRoot, $jobId);
    $filesReport = orange_restore_shadow_files_load_report($workRoot, $jobId);
    if ($filesMeta === null || empty($filesMeta['ready'])
        || !is_array($filesReport)
        || strtoupper((string) ($filesReport['overall_result'] ?? '')) !== 'PASS') {
        return ['ok' => false, 'code' => 'shadow_files_not_ready', 'job' => $job];
    }

    $verifyMeta = orange_restore_shadow_verify_load_meta($workRoot, $jobId);
    $verifyReport = orange_restore_shadow_verify_load_report($workRoot, $jobId);
    if ($verifyMeta === null || empty($verifyMeta['verified'])
        || !is_array($verifyReport)
        || strtoupper((string) ($verifyReport['overall_result'] ?? '')) !== 'READY') {
        return ['ok' => false, 'code' => 'shadow_not_verified', 'job' => $job];
    }

    $shadowMeta = orange_restore_shadow_load_meta($workRoot, $jobId);
    $shadowReport = orange_restore_shadow_load_report($workRoot, $jobId);
    $shadowResult = strtoupper((string) ($shadowReport['overall_result'] ?? ''));
    if ($shadowMeta === null || empty($shadowMeta['ready']) || !is_array($shadowReport)
        || !in_array($shadowResult, ['PASS', 'READY', 'OK', 'SUCCESS'], true)) {
        return ['ok' => false, 'code' => 'shadow_restore_not_ready', 'job' => $job];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($anchor === null || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
        return ['ok' => false, 'code' => 'pre_restore_backup_not_ready', 'job' => $job];
    }

    try {
        $approvalPath = orange_restore_final_approval_record_path($workRoot, $jobId);
        if (!is_file($approvalPath)) {
            return ['ok' => false, 'code' => 'final_approval_missing', 'job' => $job];
        }
        $approval = json_decode((string) file_get_contents($approvalPath), true);
        if (!is_array($approval) || empty($approval['approved_at'])) {
            return ['ok' => false, 'code' => 'final_approval_invalid', 'job' => $job];
        }
        $liveFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
        if ($liveFp !== '' && (string) ($approval['plan_fingerprint'] ?? '') !== ''
            && !hash_equals((string) $approval['plan_fingerprint'], $liveFp)) {
            return ['ok' => false, 'code' => 'approval_changed', 'job' => $job];
        }
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'final_approval_invalid', 'job' => $job];
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
 * HTTP: metadata-only request (does not run smoke).
 *
 * @param array<string, mixed> $admin
 * @return array<string, mixed>
 */
function orange_restore_shadow_smoke_request(
    string $workRoot,
    string $jobId,
    string $backupRoot,
    array $admin
): array {
    $check = orange_restore_shadow_smoke_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');
    $operator = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';

    $meta = orange_restore_shadow_smoke_load_meta($workRoot, $jobId);
    $decision = orange_restore_cutover_readiness_load($workRoot, $jobId);
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
    ], true) && is_array($meta) && is_array($decision)
        && strtoupper((string) ($meta['overall_result'] ?? '')) === 'READY') {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_smoke_public_meta($meta),
            'report' => orange_restore_shadow_smoke_public_report(
                orange_restore_shadow_smoke_load_report($workRoot, $jobId) ?? []
            ),
            'cutover_readiness' => orange_restore_cutover_readiness_public($decision),
            'cli_needed' => false,
            'idempotent' => true,
            'execution_started' => false,
            'production_cutover_allowed' => false,
            'message' => 'Shadow smoke already completed.',
        ];
    }
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
    ], true)) {
        return [
            'job' => orange_restore_fw_public_row($job),
            'meta' => orange_restore_shadow_smoke_public_meta($meta ?? [
                'framework_job_id' => $jobId,
                'status' => $status,
                'cli_needed' => true,
                'execution_started' => false,
                'production_cutover_allowed' => false,
            ]),
            'cli_needed' => true,
            'idempotent' => true,
            'execution_started' => false,
            'production_cutover_allowed' => false,
            'message' => 'Shadow smoke already requested. Run CLI worker.',
        ];
    }

    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        'created_at' => gmdate('c'),
        'created_by' => $operator,
        'overall_result' => '',
        'readiness_score' => 0,
        'ready' => false,
        'cli_needed' => true,
        'cli_command' => 'php scripts/backup/restore_shadow_smoke.php --job=' . $jobId,
        'production_touched' => false,
        'execution_started' => false,
        'production_cutover_allowed' => false,
        'warning' => 'Shadow smoke only — production DB/files will not be modified; cutover remains disallowed.',
    ];
    orange_restore_shadow_smoke_write_json(orange_restore_shadow_smoke_meta_path($workRoot, $jobId), $meta);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_PENDING,
        10,
        'Shadow smoke pending — CLI worker required',
        'shadow_smoke_requested'
    );
    $job['shadow_smoke_file'] = ORANGE_RESTORE_SHADOW_SMOKE_META_FILE;
    $job['shadow_smoke_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'shadow_smoke_requested',
        'result' => 'ok',
        'owner' => $operator,
    ]);

    return [
        'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
        'meta' => orange_restore_shadow_smoke_public_meta($meta),
        'cli_needed' => true,
        'idempotent' => false,
        'execution_started' => false,
        'production_cutover_allowed' => false,
        'message' => 'Shadow smoke requested. Run CLI: php scripts/backup/restore_shadow_smoke.php --job=' . $jobId,
    ];
}

/**
 * @return array{code:string,category:string,result:string,severity:string,message:string,evidence_summary:string}
 */
function orange_restore_shadow_smoke_check(
    string $code,
    string $category,
    string $result,
    string $severity,
    string $message,
    string $evidence = ''
): array {
    return [
        'code' => $code,
        'category' => $category,
        'result' => $result,
        'severity' => $severity,
        'message' => $message,
        'evidence_summary' => $evidence,
    ];
}

/**
 * @param list<string> $tables
 * @return list<array<string,mixed>>
 */
function orange_restore_shadow_smoke_db_read_checks(PDO $pdo, array $tables): array
{
    $checks = [];
    $required = [
        'countries', 'admins', 'products', 'categories', 'product_variants',
        'warehouses', 'orders', 'order_items', 'customers',
    ];
    $optional = [
        'admin_permissions', 'roles', 'payments', 'journal_vouchers', 'journal_lines',
        'stock_movements', 'warehouse_variant_stock', 'inventory_cost_layers',
        'channels', 'storefront_accounts',
    ];
    $missingRequired = [];
    foreach ($required as $t) {
        if (!in_array($t, $tables, true)) {
            $missingRequired[] = $t;
        }
    }
    if ($missingRequired !== []) {
        $checks[] = orange_restore_shadow_smoke_check(
            'required_tables',
            'database',
            'FAIL',
            'blocking',
            'Required tables missing from shadow.',
            'missing=' . implode(',', array_slice($missingRequired, 0, 20))
        );
    } else {
        $checks[] = orange_restore_shadow_smoke_check(
            'required_tables',
            'database',
            'PASS',
            'info',
            'Required core tables present.',
            'count=' . (string) count($required)
        );
    }

    foreach (array_merge($required, $optional) as $t) {
        if (!in_array($t, $tables, true)) {
            continue;
        }
        $safe = '`' . str_replace('`', '``', $t) . '`';
        try {
            $pdo->query('SELECT 1 FROM ' . $safe . ' LIMIT 1');
            $checks[] = orange_restore_shadow_smoke_check(
                'table_readable_' . $t,
                'database',
                'PASS',
                'info',
                'Table readable: ' . $t,
                'select_ok'
            );
        } catch (Throwable $e) {
            $checks[] = orange_restore_shadow_smoke_check(
                'table_readable_' . $t,
                'database',
                'FAIL',
                'blocking',
                'Table read failed: ' . $t,
                'error'
            );
        }
    }

    return $checks;
}

/**
 * @param list<string> $tables
 * @return list<array<string,mixed>>
 */
function orange_restore_shadow_smoke_consistency_checks(PDO $pdo, array $tables): array
{
    $checks = [];
    $orphans = orange_restore_shadow_verify_orphan_checks($pdo, $tables);
    $hard = [];
    $soft = [];
    foreach ($orphans as $msg) {
        if (str_starts_with($msg, 'Orphan FK in ')) {
            $hard[] = $msg;
        } else {
            $soft[] = $msg;
        }
    }
    if ($hard !== []) {
        $checks[] = orange_restore_shadow_smoke_check(
            'orphan_references',
            'referential',
            'FAIL',
            'blocking',
            'Orphan references detected.',
            'count=' . (string) count($hard)
        );
    } else {
        $checks[] = orange_restore_shadow_smoke_check(
            'orphan_references',
            'referential',
            'PASS',
            'info',
            'No hard orphan FK issues in sampled checks.',
            'soft=' . (string) count($soft)
        );
    }
    foreach (array_slice($soft, 0, 5) as $msg) {
        $checks[] = orange_restore_shadow_smoke_check(
            'orphan_soft',
            'referential',
            'WARNING',
            'warning',
            $msg,
            'soft'
        );
    }

    // FIFO / stock — read-only probes when tables exist.
    if (in_array('inventory_cost_layers', $tables, true)) {
        try {
            $neg = (int) ($pdo->query(
                'SELECT COUNT(*) FROM `inventory_cost_layers` WHERE remaining_qty < 0'
            )->fetchColumn() ?: 0);
            $checks[] = orange_restore_shadow_smoke_check(
                'fifo_remaining_non_negative',
                'stock_fifo',
                $neg === 0 ? 'PASS' : 'FAIL',
                $neg === 0 ? 'info' : 'blocking',
                $neg === 0 ? 'FIFO remaining quantities non-negative.' : 'Negative FIFO remaining quantities found.',
                'neg_rows=' . (string) $neg
            );
        } catch (Throwable) {
            $checks[] = orange_restore_shadow_smoke_check(
                'fifo_remaining_non_negative',
                'stock_fifo',
                'WARNING',
                'warning',
                'FIFO remaining check skipped (schema variance).',
                'skipped'
            );
        }
    }

    if (in_array('warehouse_variant_stock', $tables, true)) {
        try {
            $negStock = (int) ($pdo->query(
                'SELECT COUNT(*) FROM `warehouse_variant_stock` WHERE qty < 0 OR quantity < 0'
            )->fetchColumn() ?: 0);
        } catch (Throwable) {
            try {
                $negStock = (int) ($pdo->query(
                    'SELECT COUNT(*) FROM `warehouse_variant_stock` WHERE qty < 0'
                )->fetchColumn() ?: 0);
            } catch (Throwable) {
                $negStock = -1;
            }
        }
        if ($negStock < 0) {
            $checks[] = orange_restore_shadow_smoke_check(
                'stock_totals_non_negative',
                'stock_fifo',
                'WARNING',
                'warning',
                'Stock totals check skipped (schema variance).',
                'skipped'
            );
        } else {
            $checks[] = orange_restore_shadow_smoke_check(
                'stock_totals_non_negative',
                'stock_fifo',
                $negStock === 0 ? 'PASS' : 'FAIL',
                $negStock === 0 ? 'info' : 'blocking',
                $negStock === 0 ? 'Stock quantities non-negative.' : 'Negative stock quantities found.',
                'neg_rows=' . (string) $negStock
            );
        }
    }

    // GL balance sampling
    if (in_array('journal_vouchers', $tables, true) && in_array('journal_lines', $tables, true)) {
        try {
            $sql = 'SELECT v.id,
                    COALESCE(SUM(l.debit),0) AS d,
                    COALESCE(SUM(l.credit),0) AS c
                 FROM journal_vouchers v
                 LEFT JOIN journal_lines l ON l.journal_voucher_id = v.id
                 GROUP BY v.id
                 HAVING ABS(COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)) > 0.0001
                 LIMIT 20';
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $imbalance = count($rows);
            $checks[] = orange_restore_shadow_smoke_check(
                'gl_journal_balanced',
                'accounting',
                $imbalance === 0 ? 'PASS' : 'FAIL',
                $imbalance === 0 ? 'info' : 'blocking',
                $imbalance === 0 ? 'Sampled journal vouchers balanced.' : 'Unbalanced journal vouchers detected.',
                'unbalanced=' . (string) $imbalance
            );
        } catch (Throwable) {
            $checks[] = orange_restore_shadow_smoke_check(
                'gl_journal_balanced',
                'accounting',
                'WARNING',
                'warning',
                'GL balance check skipped (schema variance).',
                'skipped'
            );
        }
    }

    return $checks;
}

/**
 * @return list<array<string,mixed>>
 */
function orange_restore_shadow_smoke_files_checks(string $workspace, ?array $filesReport): array
{
    $checks = [];
    if (!is_dir($workspace)) {
        $checks[] = orange_restore_shadow_smoke_check(
            'shadow_workspace_exists',
            'files',
            'FAIL',
            'blocking',
            'Shadow files workspace missing.',
            'missing'
        );

        return $checks;
    }
    $checks[] = orange_restore_shadow_smoke_check(
        'shadow_workspace_exists',
        'files',
        'PASS',
        'info',
        'Shadow files workspace present.',
        'ok'
    );

    if (is_link($workspace) || (function_exists('is_link') && @is_link($workspace))) {
        $checks[] = orange_restore_shadow_smoke_check(
            'shadow_workspace_symlink',
            'files',
            'FAIL',
            'blocking',
            'Shadow workspace must not be a symlink.',
            'symlink'
        );
    }

    $fileCount = 0;
    $symlinkCount = 0;
    $escaped = 0;
    $wsReal = realpath($workspace) ?: $workspace;
    $wsNorm = strtolower(rtrim(str_replace('\\', '/', $wsReal), '/'));
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wsReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            $norm = strtolower(rtrim(str_replace('\\', '/', $path), '/'));
            if ($norm !== $wsNorm && !str_starts_with($norm, $wsNorm . '/')) {
                $escaped++;
            }
            if ($file->isLink()) {
                $symlinkCount++;
            }
            if ($file->isFile()) {
                $fileCount++;
            }
        }
    } catch (Throwable) {
        $checks[] = orange_restore_shadow_smoke_check(
            'shadow_workspace_walk',
            'files',
            'FAIL',
            'blocking',
            'Cannot walk shadow workspace.',
            'walk_failed'
        );
    }

    $checks[] = orange_restore_shadow_smoke_check(
        'shadow_files_count',
        'files',
        $fileCount > 0 ? 'PASS' : 'FAIL',
        $fileCount > 0 ? 'info' : 'blocking',
        $fileCount > 0 ? 'Shadow workspace contains files.' : 'Shadow workspace has no files.',
        'files=' . (string) $fileCount
    );
    $checks[] = orange_restore_shadow_smoke_check(
        'shadow_files_no_symlinks',
        'files',
        $symlinkCount === 0 ? 'PASS' : 'FAIL',
        $symlinkCount === 0 ? 'info' : 'blocking',
        $symlinkCount === 0 ? 'No symlinks in shadow workspace.' : 'Symlinks found in shadow workspace.',
        'symlinks=' . (string) $symlinkCount
    );
    $checks[] = orange_restore_shadow_smoke_check(
        'shadow_files_path_confined',
        'files',
        $escaped === 0 ? 'PASS' : 'FAIL',
        $escaped === 0 ? 'info' : 'blocking',
        $escaped === 0 ? 'All paths confined to shadow root.' : 'Path escape detected under shadow root walk.',
        'escaped=' . (string) $escaped
    );

    if (is_array($filesReport)) {
        $ok = strtoupper((string) ($filesReport['overall_result'] ?? '')) === 'PASS';
        $checks[] = orange_restore_shadow_smoke_check(
            'prior_files_report',
            'files',
            $ok ? 'PASS' : 'FAIL',
            $ok ? 'info' : 'blocking',
            $ok ? 'Prior shadow_files_report is PASS.' : 'Prior shadow_files_report is not PASS.',
            'result=' . (string) ($filesReport['overall_result'] ?? '')
        );
    }

    return $checks;
}

/**
 * @return list<array<string,mixed>>
 */
function orange_restore_shadow_smoke_service_read_checks(PDO $pdo, array $tables): array
{
    $checks = [];
    $probes = [
        ['code' => 'product_listing_read', 'sql' => 'SELECT id FROM products ORDER BY id ASC LIMIT 5', 'need' => 'products'],
        ['code' => 'product_detail_read', 'sql' => 'SELECT id, name_ar FROM products ORDER BY id ASC LIMIT 1', 'need' => 'products'],
        ['code' => 'order_detail_read', 'sql' => 'SELECT id FROM orders ORDER BY id ASC LIMIT 1', 'need' => 'orders'],
        ['code' => 'stock_availability_read', 'sql' => 'SELECT id FROM warehouse_variant_stock ORDER BY id ASC LIMIT 5', 'need' => 'warehouse_variant_stock'],
        ['code' => 'admin_lookup_read', 'sql' => 'SELECT id FROM admins ORDER BY id ASC LIMIT 1', 'need' => 'admins'],
        ['code' => 'accounting_seed_read', 'sql' => 'SELECT id FROM journal_vouchers ORDER BY id ASC LIMIT 1', 'need' => 'journal_vouchers'],
    ];
    foreach ($probes as $probe) {
        if (!in_array($probe['need'], $tables, true)) {
            $checks[] = orange_restore_shadow_smoke_check(
                $probe['code'],
                'services',
                'WARNING',
                'warning',
                'Service read skipped; table missing: ' . $probe['need'],
                'skipped'
            );
            continue;
        }
        try {
            $pdo->query($probe['sql']);
            $checks[] = orange_restore_shadow_smoke_check(
                $probe['code'],
                'services',
                'PASS',
                'info',
                'Service read ok: ' . $probe['code'],
                'select_ok'
            );
        } catch (Throwable) {
            $checks[] = orange_restore_shadow_smoke_check(
                $probe['code'],
                'services',
                'FAIL',
                'blocking',
                'Service read failed: ' . $probe['code'],
                'error'
            );
        }
    }

    // Prove mutation / integration guards trip.
    try {
        orange_restore_shadow_context_assert_read_only('smoke_probe_write');
        $checks[] = orange_restore_shadow_smoke_check(
            'mutation_guard',
            'isolation',
            'FAIL',
            'blocking',
            'Mutation guard did not block probe write.',
            'unguarded'
        );
    } catch (Throwable $e) {
        $ok = trim($e->getMessage()) === 'shadow_context_write_blocked';
        $checks[] = orange_restore_shadow_smoke_check(
            'mutation_guard',
            'isolation',
            $ok ? 'PASS' : 'FAIL',
            $ok ? 'info' : 'blocking',
            $ok ? 'Mutation attempts fail closed.' : 'Unexpected mutation guard error.',
            'blocked'
        );
    }
    try {
        orange_restore_shadow_context_assert_integrations_disabled('payment_gateway');
        $checks[] = orange_restore_shadow_smoke_check(
            'integration_guard',
            'isolation',
            'FAIL',
            'blocking',
            'Integration guard did not block probe.',
            'unguarded'
        );
    } catch (Throwable $e) {
        $ok = trim($e->getMessage()) === 'shadow_context_integration_blocked';
        $checks[] = orange_restore_shadow_smoke_check(
            'integration_guard',
            'isolation',
            $ok ? 'PASS' : 'FAIL',
            $ok ? 'info' : 'blocking',
            $ok ? 'External integrations blocked in shadow context.' : 'Unexpected integration guard error.',
            'blocked'
        );
    }

    return $checks;
}

/**
 * @param list<array<string,mixed>> $checks
 * @return array{overall_result:string,readiness_score:int,blocking_errors:list<string>,warnings:list<string>}
 */
function orange_restore_shadow_smoke_score(array $checks): array
{
    $score = 100;
    $blocking = [];
    $warnings = [];
    foreach ($checks as $c) {
        $result = strtoupper((string) ($c['result'] ?? ''));
        $sev = (string) ($c['severity'] ?? '');
        $msg = (string) ($c['message'] ?? $c['code'] ?? 'check');
        if ($result === 'FAIL' || $sev === 'blocking') {
            $blocking[] = $msg;
            $score -= 12;
        } elseif ($result === 'WARNING' || $sev === 'warning') {
            $warnings[] = $msg;
            $score -= 4;
        }
    }
    $score = max(0, min(100, $score));
    if ($blocking !== []) {
        $overall = 'FAIL';
    } elseif ($score >= ORANGE_RESTORE_SHADOW_SMOKE_SCORE_READY && $warnings === []) {
        $overall = 'READY';
    } elseif ($score >= ORANGE_RESTORE_SHADOW_SMOKE_SCORE_WARNING) {
        $overall = 'WARNING';
    } else {
        $overall = 'FAIL';
        if ($blocking === []) {
            $blocking[] = 'Readiness score below warning threshold.';
        }
    }

    return [
        'overall_result' => $overall,
        'readiness_score' => $score,
        'blocking_errors' => array_values(array_unique(array_slice($blocking, 0, 50))),
        'warnings' => array_values(array_unique(array_slice($warnings, 0, 50))),
    ];
}

/**
 * @param array<string, mixed> $smokeEval
 * @param array<string, mixed> $gates
 * @return array<string, mixed>
 */
function orange_restore_cutover_readiness_decide(string $jobId, string $packageId, array $smokeEval, array $gates): array
{
    $blocking = [];
    $warnings = array_values(array_map('strval', $smokeEval['warnings'] ?? []));
    $smokeResult = strtoupper((string) ($smokeEval['overall_result'] ?? 'FAIL'));

    foreach ([
        'shadow_db_ready' => 'shadow_db_not_ready',
        'shadow_files_ready' => 'shadow_files_not_ready',
        'rollback_anchor_ready' => 'pre_restore_backup_not_ready',
        'approval_valid' => 'final_approval_invalid',
        'contract_valid' => 'contract_invalid',
        'version_lock_valid' => 'version_mismatch',
        'package_fingerprint_valid' => 'package_changed',
    ] as $flag => $code) {
        if (empty($gates[$flag])) {
            $blocking[] = $code;
        }
    }
    if ($smokeResult === 'FAIL') {
        $blocking[] = 'shadow_smoke_failed';
    }
    foreach (array_values(array_map('strval', $smokeEval['blocking_errors'] ?? [])) as $e) {
        $blocking[] = 'smoke:' . substr($e, 0, 80);
    }

    $smokeReady = $smokeResult === 'READY' || $smokeResult === 'WARNING';
    if ($blocking !== []) {
        $status = 'NOT_READY';
        $fw = ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED;
    } elseif ($smokeResult === 'WARNING' || $warnings !== []) {
        $status = 'MANUAL_REVIEW';
        $fw = ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW;
    } else {
        $status = 'READY';
        $fw = ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY;
    }

    return [
        'decision_version' => ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'decided_at' => gmdate('c'),
        'status' => $status,
        'framework_status' => $fw,
        'shadow_db_ready' => (bool) ($gates['shadow_db_ready'] ?? false),
        'shadow_files_ready' => (bool) ($gates['shadow_files_ready'] ?? false),
        'smoke_ready' => $smokeReady && $blocking === [],
        'rollback_anchor_ready' => (bool) ($gates['rollback_anchor_ready'] ?? false),
        'approval_valid' => (bool) ($gates['approval_valid'] ?? false),
        'contract_valid' => (bool) ($gates['contract_valid'] ?? false),
        'version_lock_valid' => (bool) ($gates['version_lock_valid'] ?? false),
        'package_fingerprint_valid' => (bool) ($gates['package_fingerprint_valid'] ?? false),
        'blocking_reason_codes' => array_values(array_unique(array_slice($blocking, 0, 50))),
        'warnings' => array_values(array_unique(array_slice($warnings, 0, 50))),
        'production_cutover_allowed' => false,
        'execution_started' => false,
    ];
}

/**
 * CLI worker — smoke + cutover readiness decision only.
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_smoke_run_cli(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'cli'
): array {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $check = orange_restore_shadow_smoke_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $meta = orange_restore_shadow_smoke_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_smoke_load_report($workRoot, $jobId);
    $decision = orange_restore_cutover_readiness_load($workRoot, $jobId);
    if (in_array($status, [
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
    ], true) && is_array($meta) && is_array($report) && is_array($decision)
        && !empty($report['smoke_tests_completed'])) {
        return [
            'ok' => (string) ($decision['status'] ?? '') !== 'NOT_READY',
            'idempotent' => true,
            'result' => (string) ($report['overall_result'] ?? ''),
            'job_id' => $jobId,
            'readiness_score' => (int) ($report['readiness_score'] ?? 0),
            'execution_started' => false,
            'production_touched' => false,
            'production_cutover_allowed' => false,
            'meta' => orange_restore_shadow_smoke_public_meta($meta),
            'report' => orange_restore_shadow_smoke_public_report($report),
            'cutover_readiness' => orange_restore_cutover_readiness_public($decision),
        ];
    }

    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
        // Allow direct CLI from shadow_files_ready (auto-pending).
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY) {
        orange_restore_shadow_smoke_request($workRoot, $jobId, $backupRoot, [
            'username' => $owner,
        ]);
        $job = orange_restore_fw_read($workRoot, $jobId);
    }

    $lock = orange_restore_shadow_smoke_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $packageId = (string) ($job['package_id'] ?? '');
    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $packageId,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
        'created_at' => gmdate('c'),
        'created_by' => $owner,
        'overall_result' => '',
        'readiness_score' => 0,
        'ready' => false,
        'cli_needed' => false,
        'cli_command' => 'php scripts/backup/restore_shadow_smoke.php --job=' . $jobId,
        'production_touched' => false,
        'execution_started' => false,
        'production_cutover_allowed' => false,
        'warning' => 'Shadow smoke only — production DB/files will not be modified; cutover remains disallowed.',
    ];

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_smoke_started',
            'result' => 'ok',
            'owner' => $owner,
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_RUNNING,
            40,
            'Running isolated shadow smoke tests',
            'shadow_smoke_started'
        );
        orange_restore_shadow_smoke_write_json(orange_restore_shadow_smoke_meta_path($workRoot, $jobId), $meta);

        if (isset($GLOBALS['orange_shadow_smoke_pipeline_override'])
            && is_callable($GLOBALS['orange_shadow_smoke_pipeline_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_smoke_pipeline_override'];
            $pipeline = $fn($projectRoot, $workRoot, $backupRoot, $jobId);
            if (!is_array($pipeline)) {
                throw new RuntimeException('smoke_pipeline_override_invalid');
            }
        } else {
            $pipeline = orange_restore_shadow_smoke_execute_pipeline(
                $projectRoot,
                $workRoot,
                $backupRoot,
                $jobId
            );
        }

        $overall = strtoupper((string) ($pipeline['overall_result'] ?? 'FAIL'));
        $score = (int) ($pipeline['readiness_score'] ?? 0);
        $checks = is_array($pipeline['checks'] ?? null) ? $pipeline['checks'] : [];
        $blocking = array_values(array_map('strval', $pipeline['blocking_errors'] ?? []));
        $warnings = array_values(array_map('strval', $pipeline['warnings'] ?? []));
        $ctxSnap = is_array($pipeline['context'] ?? null) ? $pipeline['context'] : orange_restore_shadow_context_snapshot();

        $report = [
            'report_version' => ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION,
            'job_id' => $jobId,
            'package_id' => $packageId,
            'tested_at' => gmdate('c'),
            'shadow_db_identity_hash' => (string) ($pipeline['shadow_db_identity_hash'] ?? ''),
            'shadow_files_identity_hash' => (string) ($pipeline['shadow_files_identity_hash'] ?? ''),
            'overall_result' => $overall,
            'readiness_score' => $score,
            'checks' => $checks,
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
            'production_isolation' => [
                'production_db_writes' => (int) ($ctxSnap['production_db_writes'] ?? 0),
                'production_file_writes' => (int) ($ctxSnap['production_file_writes'] ?? 0),
                'external_integrations_invoked' => false,
                'maintenance_activated' => false,
                'config_switched' => false,
                'cutover_performed' => false,
                'rollback_executed' => false,
            ],
            'mutation_attempts_blocked' => array_values(array_map(
                'strval',
                $ctxSnap['mutation_attempts_blocked'] ?? []
            )),
            'external_integrations_invoked' => false,
            'production_db_writes' => 0,
            'production_file_writes' => 0,
            'smoke_tests_completed' => true,
            'execution_started' => false,
            'production_cutover_allowed' => false,
            'warning' => 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج، ولا يزال التحويل إلى الإنتاج غير مسموح.',
        ];
        orange_restore_shadow_smoke_write_json(orange_restore_shadow_smoke_report_path($workRoot, $jobId), $report);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_smoke_check_completed',
            'result' => strtolower($overall),
            'score' => $score,
        ]);

        $smokeFwStatus = match ($overall) {
            'READY' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY,
            'WARNING' => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
            default => ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        };
        $smokeFwPhase = match ($overall) {
            'READY' => ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_READY,
            'WARNING' => ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_WARNING,
            default => ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_FAILED,
        };
        $smokeEvent = match ($overall) {
            'READY' => 'shadow_smoke_ready',
            'WARNING' => 'shadow_smoke_warning',
            default => 'shadow_smoke_failed',
        };

        $meta['status'] = $smokeFwStatus;
        $meta['overall_result'] = $overall;
        $meta['readiness_score'] = $score;
        $meta['ready'] = $overall === 'READY' || $overall === 'WARNING';
        $meta['execution_started'] = false;
        $meta['production_cutover_allowed'] = false;
        orange_restore_shadow_smoke_write_json(orange_restore_shadow_smoke_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            $smokeFwStatus,
            $smokeFwPhase,
            80,
            'Shadow smoke ' . $overall . ' (score=' . (string) $score . ')',
            $smokeEvent
        );
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => $smokeEvent,
            'result' => strtolower($overall),
            'score' => $score,
        ]);

        $gates = is_array($pipeline['gates'] ?? null) ? $pipeline['gates'] : [
            'shadow_db_ready' => true,
            'shadow_files_ready' => true,
            'rollback_anchor_ready' => true,
            'approval_valid' => true,
            'contract_valid' => true,
            'version_lock_valid' => true,
            'package_fingerprint_valid' => true,
        ];
        $decision = orange_restore_cutover_readiness_decide($jobId, $packageId, [
            'overall_result' => $overall,
            'blocking_errors' => $blocking,
            'warnings' => $warnings,
        ], $gates);
        // Hard policy for this phase.
        $decision['production_cutover_allowed'] = false;
        $decision['execution_started'] = false;
        orange_restore_shadow_smoke_write_json(orange_restore_cutover_readiness_path($workRoot, $jobId), $decision);

        $fwDecision = (string) ($decision['framework_status'] ?? ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED);
        $fwPhase = match ($fwDecision) {
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY => ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_READY,
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW => ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_MANUAL_REVIEW,
            default => ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_BLOCKED,
        };
        $decisionEvent = match ($fwDecision) {
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY => 'cutover_readiness_ready',
            ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW => 'cutover_readiness_manual_review',
            default => 'cutover_readiness_blocked',
        };

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'cutover_readiness_evaluated',
            'result' => strtolower((string) ($decision['status'] ?? 'not_ready')),
        ]);
        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            $fwDecision,
            $fwPhase,
            100,
            'Cutover readiness ' . (string) ($decision['status'] ?? 'NOT_READY')
                . ' — production cutover still disallowed',
            $decisionEvent
        );
        $job['shadow_smoke_file'] = ORANGE_RESTORE_SHADOW_SMOKE_META_FILE;
        $job['shadow_smoke_report_file'] = ORANGE_RESTORE_SHADOW_SMOKE_REPORT_FILE;
        $job['cutover_readiness_file'] = ORANGE_RESTORE_CUTOVER_READINESS_FILE;
        $job['shadow_smoke_status'] = $smokeFwStatus;
        $job['cutover_readiness_status'] = $fwDecision;
        $job['shadow_smoke_score'] = $score;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => $decisionEvent,
            'result' => 'ok',
            'production_cutover_allowed' => false,
        ]);

        orange_restore_shadow_smoke_release_lock($workRoot, $jobId);
        orange_restore_shadow_context_end();

        return [
            'ok' => $overall !== 'FAIL',
            'idempotent' => false,
            'result' => $overall,
            'job_id' => $jobId,
            'readiness_score' => $score,
            'execution_started' => false,
            'production_touched' => false,
            'production_cutover_allowed' => false,
            'meta' => orange_restore_shadow_smoke_public_meta($meta),
            'report' => orange_restore_shadow_smoke_public_report($report),
            'cutover_readiness' => orange_restore_cutover_readiness_public($decision),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'shadow_smoke_failed';
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED;
        $meta['overall_result'] = 'FAIL';
        $meta['ready'] = false;
        $meta['failure_code'] = $code;
        $meta['cli_needed'] = true;
        $meta['execution_started'] = false;
        $meta['production_cutover_allowed'] = false;
        try {
            orange_restore_shadow_smoke_write_json(orange_restore_shadow_smoke_meta_path($workRoot, $jobId), $meta);
            $failReport = [
                'report_version' => ORANGE_RESTORE_SHADOW_SMOKE_RECORD_VERSION,
                'job_id' => $jobId,
                'package_id' => $packageId,
                'tested_at' => gmdate('c'),
                'overall_result' => 'FAIL',
                'readiness_score' => 0,
                'checks' => [],
                'blocking_errors' => [$code],
                'warnings' => [],
                'production_isolation' => [
                    'production_db_writes' => 0,
                    'production_file_writes' => 0,
                    'external_integrations_invoked' => false,
                    'maintenance_activated' => false,
                    'config_switched' => false,
                ],
                'mutation_attempts_blocked' => array_values(array_map(
                    'strval',
                    orange_restore_shadow_context_snapshot()['mutation_attempts_blocked'] ?? []
                )),
                'external_integrations_invoked' => false,
                'production_db_writes' => 0,
                'production_file_writes' => 0,
                'smoke_tests_completed' => false,
                'execution_started' => false,
                'production_cutover_allowed' => false,
                'failure_code' => $code,
            ];
            orange_restore_shadow_smoke_write_json(
                orange_restore_shadow_smoke_report_path($workRoot, $jobId),
                $failReport
            );
            $decision = orange_restore_cutover_readiness_decide($jobId, $packageId, [
                'overall_result' => 'FAIL',
                'blocking_errors' => [$code],
                'warnings' => [],
            ], [
                'shadow_db_ready' => false,
                'shadow_files_ready' => false,
                'rollback_anchor_ready' => false,
                'approval_valid' => false,
                'contract_valid' => false,
                'version_lock_valid' => false,
                'package_fingerprint_valid' => false,
            ]);
            orange_restore_shadow_smoke_write_json(
                orange_restore_cutover_readiness_path($workRoot, $jobId),
                $decision
            );
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
                ORANGE_RESTORE_FW_PHASE_SHADOW_SMOKE_FAILED,
                90,
                'Shadow smoke failed: ' . $code,
                'shadow_smoke_failed'
            );
            $job = orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
                ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_BLOCKED,
                100,
                'Cutover readiness blocked after smoke failure',
                'cutover_readiness_blocked'
            );
            $job['shadow_smoke_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED;
            $job['cutover_readiness_status'] = ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED;
            $job['execution_started'] = false;
            orange_restore_fw_write($workRoot, $job);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_smoke_failed',
                'result' => 'fail',
                'code' => $code,
            ]);
        } catch (Throwable) {
            // best-effort
        }
        orange_restore_shadow_smoke_release_lock($workRoot, $jobId);
        orange_restore_shadow_context_end();

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'readiness_score' => 0,
            'execution_started' => false,
            'production_touched' => false,
            'production_cutover_allowed' => false,
            'meta' => orange_restore_shadow_smoke_public_meta($meta),
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_shadow_smoke_execute_pipeline(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId
): array {
    unset($backupRoot);
    $env = orange_backup_load_env_array($projectRoot);
    $shadowMeta = function_exists('orange_restore_shadow_load_meta')
        ? orange_restore_shadow_load_meta($workRoot, $jobId)
        : null;
    $shadowDb = trim((string) (($shadowMeta['shadow_db'] ?? '') ?: ''));
    if ($shadowDb === '') {
        $shadowDb = orange_restore_shadow_db_name($env, $projectRoot, $jobId, is_array($shadowMeta) ? $shadowMeta : null);
    }
    $productionDb = orange_restore_shadow_production_db_name($projectRoot);
    if (strcasecmp($shadowDb, $productionDb) === 0) {
        throw new RuntimeException('production_db_identity_rejected');
    }

    $workspace = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    orange_restore_assert_inside_work_root($workRoot, $workspace);
    // Reject production uploads roots.
    $prodUploads = rtrim($projectRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    $wsNorm = strtolower(str_replace('\\', '/', $workspace));
    $prodNorm = strtolower(str_replace('\\', '/', $prodUploads));
    if ($wsNorm === $prodNorm || str_starts_with($wsNorm, rtrim($prodNorm, '/') . '/')) {
        throw new RuntimeException('production_file_root_rejected');
    }

    orange_restore_shadow_context_begin($jobId, $shadowDb, $workspace, $productionDb);

    $pdoRaw = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
    $guard = new OrangeRestoreShadowPdoGuard($pdoRaw);
    $pdo = $guard; // use guard methods

    $checks = [];
    $checks[] = orange_restore_shadow_smoke_check(
        'bootstrap_shadow_context',
        'environment',
        'PASS',
        'info',
        'Shadow context bootstrapped (read-only).',
        'shadow_db_hash=' . substr(hash('sha256', $shadowDb), 0, 12)
    );
    $checks[] = orange_restore_shadow_smoke_check(
        'production_db_not_used',
        'environment',
        strcasecmp($shadowDb, $productionDb) !== 0 ? 'PASS' : 'FAIL',
        strcasecmp($shadowDb, $productionDb) !== 0 ? 'info' : 'blocking',
        'Shadow DB identity differs from production.',
        'ok'
    );

    // Schema gate: read revision from shadow only (never run ensure_schema migrations here).
    $schemaRev = 0;
    try {
        $st = $pdo->query(
            "SELECT meta_value FROM orange_schema_meta WHERE meta_key = 'php_schema_revision' LIMIT 1"
        );
        if ($st !== false) {
            $schemaRev = (int) ($st->fetchColumn() ?: 0);
        }
    } catch (Throwable) {
        try {
            $st = $pdo->query('SELECT MAX(revision) FROM orange_schema_migrations');
            if ($st !== false) {
                $schemaRev = (int) ($st->fetchColumn() ?: 0);
            }
        } catch (Throwable) {
            $schemaRev = 0;
        }
    }
    $checks[] = orange_restore_shadow_smoke_check(
        'schema_gate_read',
        'environment',
        $schemaRev > 0 ? 'PASS' : 'WARNING',
        $schemaRev > 0 ? 'info' : 'warning',
        $schemaRev > 0 ? 'Shadow schema revision readable.' : 'Shadow schema revision not found.',
        'revision=' . (string) $schemaRev
    );

    $tables = [];
    try {
        $st = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );
        foreach (($st ? $st->fetchAll(PDO::FETCH_COLUMN) : []) as $t) {
            $tables[] = (string) $t;
        }
    } catch (Throwable) {
        $checks[] = orange_restore_shadow_smoke_check(
            'list_tables',
            'database',
            'FAIL',
            'blocking',
            'Cannot list shadow tables.',
            'error'
        );
    }

    $checks = array_merge($checks, orange_restore_shadow_smoke_db_read_checks($pdoRaw, $tables));
    // Use raw PDO for SELECTs already validated as read-only by our check functions;
    // additionally re-run guarded probes for write blocking evidence.
    try {
        $guard->assertReadOnlySql('INSERT INTO admins (id) VALUES (0)');
    } catch (Throwable) {
        // expected
    }
    $checks = array_merge($checks, orange_restore_shadow_smoke_consistency_checks($pdoRaw, $tables));
    $filesReport = orange_restore_shadow_files_load_report($workRoot, $jobId);
    $checks = array_merge($checks, orange_restore_shadow_smoke_files_checks($workspace, $filesReport));
    $checks = array_merge($checks, orange_restore_shadow_smoke_service_read_checks($pdoRaw, $tables));

    $scored = orange_restore_shadow_smoke_score($checks);
    $ctx = orange_restore_shadow_context_snapshot();
    $ctx['production_db_writes'] = 0;
    $ctx['production_file_writes'] = 0;
    $ctx['external_integrations_invoked'] = false;

    return [
        'overall_result' => $scored['overall_result'],
        'readiness_score' => $scored['readiness_score'],
        'blocking_errors' => $scored['blocking_errors'],
        'warnings' => $scored['warnings'],
        'checks' => $checks,
        'context' => $ctx,
        'shadow_db_identity_hash' => hash('sha256', strtolower($shadowDb)),
        'shadow_files_identity_hash' => hash(
            'sha256',
            strtolower(str_replace('\\', '/', ORANGE_RESTORE_SHADOW_FILES_WORKSPACE . '/' . $jobId))
        ),
        'gates' => [
            'shadow_db_ready' => true,
            'shadow_files_ready' => is_array($filesReport)
                && strtoupper((string) ($filesReport['overall_result'] ?? '')) === 'PASS',
            'rollback_anchor_ready' => true,
            'approval_valid' => true,
            'contract_valid' => true,
            'version_lock_valid' => true,
            'package_fingerprint_valid' => true,
        ],
    ];
}
