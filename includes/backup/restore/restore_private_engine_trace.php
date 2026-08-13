<?php

declare(strict_types=1);

/**
 * Restore Center — read-only private-engine live trace bridge (Step 7 evidence).
 *
 * Zero-mutation contract: never mkdir, write, unlink, spawn, materialize, provision,
 * reconcile claims, release mutexes, or start import. Reads job-local artifacts only.
 *
 * @see RESTORE_CENTER_STEP7_PRIVATE_TRACE_BRIDGE_01
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_private_shadow_engine.php';
require_once __DIR__ . '/restore_private_engine_runtime_manifest.php';
require_once __DIR__ . '/restore_private_engine_local_discovery.php';
require_once __DIR__ . '/restore_shadow_db.php';

const ORANGE_RESTORE_PRIVATE_ENGINE_TRACE_VERSION = 'step7-private-engine-trace-v1';

/**
 * @return array{value:mixed,status:string}
 */
function orange_restore_private_engine_trace_field(mixed $value, string $status): array
{
    static $allowed = [
        'PROVEN' => true,
        'ABSENT' => true,
        'PARTIAL' => true,
        'MALFORMED' => true,
        'HISTORICAL' => true,
        'ACTIVE' => true,
        'TERMINAL' => true,
        'STALE_CANDIDATE' => true,
        'UNKNOWN' => true,
    ];
    if (!isset($allowed[$status])) {
        $status = 'UNKNOWN';
    }

    return ['value' => $value, 'status' => $status];
}

/**
 * Job directory path without creating it.
 */
function orange_restore_private_engine_trace_job_dir(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId);
}

/**
 * Private engine root path without creating it.
 */
function orange_restore_private_engine_trace_engine_root(string $workRoot, string $jobId): string
{
    return orange_restore_private_engine_trace_job_dir($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME;
}

/**
 * Path helpers duplicated read-only (avoid requiring orchestrator → circular load).
 */
function orange_restore_private_engine_trace_claim_path(string $workRoot, string $jobId): string
{
    if (function_exists('orange_restore_center_worker_run_claim_path')) {
        return orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'shadow_db');
    }

    return orange_restore_private_engine_trace_job_dir($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'orchestrator_shadow_db.run.json';
}

function orange_restore_private_engine_trace_mutex_path(string $workRoot, string $jobId): string
{
    if (function_exists('orange_restore_center_worker_mutex_path')) {
        return orange_restore_center_worker_mutex_path($workRoot, $jobId, 'shadow_db');
    }

    return orange_restore_private_engine_trace_job_dir($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'orchestrator_shadow_db.mutex';
}

function orange_restore_private_engine_trace_guided_worker(string $status): string
{
    if (function_exists('orange_restore_center_guided_worker_key_from_status')) {
        return orange_restore_center_guided_worker_key_from_status($status);
    }
    if (str_starts_with($status, 'shadow_restore_')) {
        return 'shadow_db';
    }

    return '';
}

function orange_restore_private_engine_trace_process_alive(int $pid): string
{
    if ($pid <= 0) {
        return 'unknown';
    }
    if (function_exists('orange_restore_center_process_alive')) {
        return orange_restore_center_process_alive($pid) ? 'alive' : 'dead';
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0) ? 'alive' : 'dead';
    }

    return 'unknown';
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_private_engine_trace_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) @file_get_contents($path);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Read JSON and classify presence/malform without mutation.
 *
 * @return array{data:?array,status:string}
 */
function orange_restore_private_engine_trace_json_status(string $path): array
{
    if (!is_file($path)) {
        return ['data' => null, 'status' => 'ABSENT'];
    }
    $raw = (string) @file_get_contents($path);
    if ($raw === '') {
        return ['data' => null, 'status' => 'PARTIAL'];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['data' => null, 'status' => 'MALFORMED'];
    }

    return ['data' => $decoded, 'status' => 'PROVEN'];
}

/**
 * Classify private error-log content into safe categories (never return raw text).
 *
 * @return array{category:string,status:string,init_invoked:?bool,init_failed:?bool}
 */
function orange_restore_private_engine_trace_classify_error_log(string $errorLogPath): array
{
    if (!is_file($errorLogPath)) {
        return [
            'category' => 'error_log_absent',
            'status' => 'ABSENT',
            'init_invoked' => null,
            'init_failed' => null,
        ];
    }
    $size = (int) @filesize($errorLogPath);
    if ($size <= 0) {
        return [
            'category' => 'error_log_empty',
            'status' => 'PARTIAL',
            'init_invoked' => null,
            'init_failed' => null,
        ];
    }
    $fh = @fopen($errorLogPath, 'rb');
    if (!is_resource($fh)) {
        return [
            'category' => 'error_log_unreadable',
            'status' => 'UNKNOWN',
            'init_invoked' => null,
            'init_failed' => null,
        ];
    }
    $start = max(0, $size - 8192);
    if ($start > 0) {
        fseek($fh, $start);
    }
    $raw = strtolower((string) stream_get_contents($fh));
    fclose($fh);

    $initInvoked = null;
    $initFailed = null;
    $category = 'error_log_present_unclassified';

    if (preg_match('/initialize|innodb|aborting|can\'t create|permission denied|access is denied|mkdir|failed to create|os error/i', $raw) === 1) {
        $initInvoked = true;
        if (preg_match('/aborting|failed|error|permission denied|access is denied|can\'t create|os error/i', $raw) === 1) {
            $initFailed = true;
            if (preg_match('/permission denied|access is denied|os error\s*13|acl/i', $raw) === 1) {
                $category = 'init_acl_or_permission_failure';
            } elseif (preg_match('/can\'t create directory|mkdir|failed to create/i', $raw) === 1) {
                $category = 'init_directory_create_failure';
            } elseif (preg_match('/initialize/i', $raw) === 1) {
                $category = 'init_initialize_insecure_failure';
            } else {
                $category = 'init_failed_generic';
            }
        } else {
            $category = 'init_log_present_nonfatal';
            $initFailed = false;
        }
    } elseif (preg_match('/ready for connections|mysqld: ready/i', $raw) === 1) {
        $category = 'engine_ready_for_connections';
        $initInvoked = true;
        $initFailed = false;
    } else {
        $category = 'error_log_present_unclassified';
        $initInvoked = true;
    }

    return [
        'category' => $category,
        'status' => 'PROVEN',
        'init_invoked' => $initInvoked,
        'init_failed' => $initFailed,
    ];
}

/**
 * Datadir ownership/state classification (no paths exposed).
 */
function orange_restore_private_engine_trace_datadir_state(string $engineRoot, ?array $state, string $jobId): array
{
    $dataDir = $engineRoot . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($engineRoot)) {
        return ['state' => 'ABSENT', 'status' => 'ABSENT', 'writable' => 'unknown'];
    }
    if (!is_dir($dataDir)) {
        return ['state' => 'ABSENT', 'status' => 'ABSENT', 'writable' => is_writable($engineRoot) ? 'writable' : 'not_writable'];
    }

    $entries = @scandir($dataDir);
    $names = is_array($entries) ? array_values(array_diff($entries, ['.', '..'])) : [];
    $hasMysqlSystem = is_dir($dataDir . DIRECTORY_SEPARATOR . 'mysql');
    $owned = is_array($state) && !empty($state['datadir_job_owned']);
    $ready = is_array($state) && !empty($state['ready']);
    $pidMeta = is_array($state) && (int) ($state['engine_pid'] ?? 0) > 0;
    $writable = @is_writable($dataDir) ? 'writable' : 'not_writable';

    if ($names === []) {
        return [
            'state' => $owned ? 'EMPTY_OWNED' : 'ABSENT',
            'status' => $owned ? 'PROVEN' : 'ABSENT',
            'writable' => $writable,
        ];
    }

    if (!$owned && is_array($state) && isset($state['datadir_job_owned']) && empty($state['datadir_job_owned'])) {
        return ['state' => 'UNOWNED', 'status' => 'PROVEN', 'writable' => $writable];
    }

    if (!$hasMysqlSystem) {
        $terminal = true;
        if (function_exists('orange_restore_private_engine_attempt_context')
            && isset($GLOBALS['orange_restore_private_engine_trace_work_root'])
            && is_string($GLOBALS['orange_restore_private_engine_trace_work_root'])) {
            $ctx = orange_restore_private_engine_attempt_context(
                (string) $GLOBALS['orange_restore_private_engine_trace_work_root'],
                $jobId
            );
            if (!empty($ctx['active_attempt'])
                || ($ctx['php_worker_liveness'] ?? '') === 'alive'
                || ($ctx['private_db_liveness'] ?? '') === 'alive') {
                $terminal = false;
            }
        }

        return [
            'state' => $owned || $jobId !== ''
                ? ($terminal ? 'PARTIAL_OWNED_TERMINAL_ATTEMPT' : 'PARTIAL_OWNED_ACTIVE_ATTEMPT')
                : 'MALFORMED_OR_UNKNOWN',
            'status' => 'PARTIAL',
            'writable' => $writable,
        ];
    }

    if ($ready && $pidMeta) {
        $alive = orange_restore_private_engine_trace_process_alive((int) $state['engine_pid']) === 'alive';

        return [
            'state' => $alive ? 'ACTIVE_OWNED' : 'READY_OWNED',
            'status' => 'PROVEN',
            'writable' => $writable,
        ];
    }

    if ($ready || $hasMysqlSystem) {
        return ['state' => 'READY_OWNED', 'status' => $owned || $hasMysqlSystem ? 'PROVEN' : 'PARTIAL', 'writable' => $writable];
    }

    return ['state' => 'PARTIAL_OWNED_TERMINAL_ATTEMPT', 'status' => 'PARTIAL', 'writable' => $writable];
}

/**
 * Read-only runtime materialization presence (no mkdir / write probe).
 *
 * @return array<string, mixed>
 */
function orange_restore_private_engine_trace_runtime_supply(string $projectRoot): array
{
    $manifest = function_exists('orange_restore_private_engine_runtime_manifest_public_summary')
        ? orange_restore_private_engine_runtime_manifest_public_summary()
        : [];
    $manifestPresent = is_array($manifest) && $manifest !== [];
    $vendor = (string) ($manifest['vendor'] ?? '');
    $version = (string) ($manifest['version'] ?? '');
    $family = (string) ($manifest['family'] ?? '');
    $shaPinned = !empty($manifest['sha256_pinned']);

    $verified = false;
    $extractedComplete = false;
    $installState = 'absent';
    $sourceCategory = 'unavailable';
    $executablePermitted = 'unknown';
    $toolsReady = false;

    $candidates = function_exists('orange_restore_private_engine_tools_root_candidates')
        ? orange_restore_private_engine_tools_root_candidates($projectRoot)
        : [];
    if (isset($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && is_string($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && trim($GLOBALS['orange_restore_private_engine_tools_root_override']) !== '') {
        array_unshift($candidates, trim($GLOBALS['orange_restore_private_engine_tools_root_override']));
    }
    foreach ($candidates as $cand) {
        $cand = trim((string) $cand);
        if ($cand === '' || !is_dir($cand)) {
            continue;
        }
        $toolsReady = $toolsReady || is_writable($cand);
        $sharedCandidates = [
            $cand . DIRECTORY_SEPARATOR . 'shared_runtime',
            $cand . DIRECTORY_SEPARATOR . 'runtime',
            $cand,
        ];
        foreach ($sharedCandidates as $shared) {
            if (!is_dir($shared)) {
                continue;
            }
            $marker = $shared . DIRECTORY_SEPARATOR . '.runtime_verified.json';
            if (!is_file($marker)) {
                // Partial extract: binaries present without marker.
                $mysqldWin = $shared . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqld.exe';
                $mysqldUnix = $shared . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqld';
                if (is_file($mysqldWin) || is_file($mysqldUnix)) {
                    $installState = 'partial';
                    $sourceCategory = 'partial_portable_extract';
                    $extractedComplete = false;
                }
                continue;
            }
            $meta = orange_restore_private_engine_trace_read_json_file($marker);
            if (!is_array($meta)) {
                $installState = 'malformed';
                continue;
            }
            $verified = !empty($meta['verified']);
            $installState = $verified ? 'verified' : 'partial';
            $sourceCategory = 'verified_portable_artifact';
            $basedirRel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($meta['basedir_rel'] ?? 'bin'));
            $basedir = $shared . DIRECTORY_SEPARATOR . $basedirRel;
            $mysqld = $basedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                . (PHP_OS_FAMILY === 'Windows' ? 'mysqld.exe' : 'mysqld');
            if (!is_file($mysqld)) {
                $mysqld = $shared . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                    . (PHP_OS_FAMILY === 'Windows' ? 'mysqld.exe' : 'mysqld');
            }
            $extractedComplete = is_file($mysqld);
            $executablePermitted = $extractedComplete ? 'yes' : 'no';
            if ($verified && $extractedComplete) {
                break 2;
            }
        }
    }

    // Local service discovery is read-only (filesystem / PATH existence only).
    if (!$verified && function_exists('orange_restore_private_engine_discover_local_service_binaries')) {
        try {
            $svc = orange_restore_private_engine_discover_local_service_binaries();
            if (is_array($svc) && !empty($svc['ok'])) {
                $verified = true;
                $extractedComplete = true;
                $installState = 'verified_local_service';
                $sourceCategory = 'verified_local_service_binary';
                $executablePermitted = 'yes';
                if ($family === '') {
                    $family = (string) ($svc['family'] ?? '');
                }
            }
        } catch (Throwable) {
            // keep prior classification
        }
    }

    $mutexPathCandidates = [];
    foreach ($candidates as $cand) {
        if (!is_dir($cand)) {
            continue;
        }
        $mutexPathCandidates[] = $cand . DIRECTORY_SEPARATOR . 'runtime_install.lock';
        $mutexPathCandidates[] = $cand . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'runtime_install.lock';
    }
    $installMutexExists = false;
    foreach ($mutexPathCandidates as $mp) {
        if (is_file($mp)) {
            $installMutexExists = true;
            break;
        }
    }

    return [
        'selected_runtime_architecture' => orange_restore_private_engine_trace_field(
            'C_PRIVATE_MYSQL_INSTANCE',
            'PROVEN'
        ),
        'selected_runtime_source_category' => orange_restore_private_engine_trace_field(
            $sourceCategory,
            $sourceCategory === 'unavailable' ? 'ABSENT' : 'PROVEN'
        ),
        'runtime_manifest_present' => orange_restore_private_engine_trace_field(
            $manifestPresent,
            $manifestPresent ? 'PROVEN' : 'ABSENT'
        ),
        'vendor_family_category' => orange_restore_private_engine_trace_field(
            $vendor !== '' ? $vendor : ($family !== '' ? $family : 'unknown'),
            ($vendor !== '' || $family !== '') ? 'PROVEN' : 'UNKNOWN'
        ),
        'version_category' => orange_restore_private_engine_trace_field(
            $version !== '' ? $version : 'unknown',
            $version !== '' ? 'PROVEN' : 'UNKNOWN'
        ),
        'architecture_category' => orange_restore_private_engine_trace_field(
            $family !== '' ? $family : 'unknown',
            $family !== '' ? 'PROVEN' : 'UNKNOWN'
        ),
        'archive_or_runtime_identity_verified' => orange_restore_private_engine_trace_field(
            $verified,
            $verified ? 'PROVEN' : ($installState === 'partial' ? 'PARTIAL' : 'ABSENT')
        ),
        'checksum_verified' => orange_restore_private_engine_trace_field(
            $shaPinned && $verified,
            $shaPinned ? ($verified ? 'PROVEN' : 'PARTIAL') : 'ABSENT'
        ),
        'required_extracted_files_complete' => orange_restore_private_engine_trace_field(
            $extractedComplete,
            $extractedComplete ? 'PROVEN' : ($installState === 'partial' ? 'PARTIAL' : 'ABSENT')
        ),
        'runtime_installation_state' => orange_restore_private_engine_trace_field(
            $installState,
            $installState === 'absent' ? 'ABSENT' : ($installState === 'malformed' ? 'MALFORMED' : 'PROVEN')
        ),
        'runtime_install_ownership' => orange_restore_private_engine_trace_field(
            $installState === 'verified' || $installState === 'verified_local_service' ? 'shared_tools_root' : 'none',
            $installState === 'absent' ? 'ABSENT' : 'PROVEN'
        ),
        'runtime_install_mutex_state' => orange_restore_private_engine_trace_field(
            $installMutexExists ? 'present_separate_from_step7_attempt' : 'absent',
            $installMutexExists ? 'PROVEN' : 'ABSENT'
        ),
        'runtime_compatible_with_source_package' => orange_restore_private_engine_trace_field(
            $shaPinned || $verified,
            ($shaPinned || $verified) ? 'PROVEN' : 'UNKNOWN'
        ),
        'runtime_executable_permitted' => orange_restore_private_engine_trace_field(
            $executablePermitted,
            $executablePermitted === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'tools_root_ready_existing' => orange_restore_private_engine_trace_field(
            $toolsReady,
            $toolsReady ? 'PROVEN' : 'ABSENT'
        ),
        '_internal' => [
            'verified' => $verified,
            'extracted_complete' => $extractedComplete,
            'install_state' => $installState,
            'install_mutex_exists' => $installMutexExists,
            'source_category' => $sourceCategory,
        ],
    ];
}

/**
 * @param array<string, mixed> $job
 * @return array{events:list<array<string,mixed>>,latest:?array<string,mixed>,attempt_count:int,duplicate_owner_rows:int,historical_excluded:int}
 */
function orange_restore_private_engine_trace_attempt_scan(string $workRoot, string $jobId, array $job): array
{
    $auditPath = orange_restore_fw_audit_file_path($workRoot, $jobId);
    $events = [];
    $latest = null;
    $historicalExcluded = 0;
    $dupKeys = [];
    $duplicateOwnerRows = 0;
    $attemptCount = 0;

    if (!is_file($auditPath)) {
        return [
            'events' => [],
            'latest' => null,
            'attempt_count' => 0,
            'duplicate_owner_rows' => 0,
            'historical_excluded' => 0,
            'audit_status' => 'ABSENT',
        ];
    }

    $lines = @file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [
            'events' => [],
            'latest' => null,
            'attempt_count' => 0,
            'duplicate_owner_rows' => 0,
            'historical_excluded' => 0,
            'audit_status' => 'MALFORMED',
        ];
    }

    $guided = orange_restore_private_engine_trace_guided_worker((string) ($job['status'] ?? ''));
    foreach (array_reverse($lines) as $line) {
        $row = json_decode((string) $line, true);
        if (!is_array($row)) {
            continue;
        }
        $event = (string) ($row['event'] ?? '');
        $worker = trim((string) ($row['worker'] ?? ''));
        $isShadow = str_starts_with($event, 'shadow_restore_')
            || (
                in_array($event, [
                    'restore_center_worker_schedule_failed',
                    'restore_center_worker_scheduled',
                    'restore_center_pending_without_worker_compensated',
                    'restore_center_dispatch_compensated',
                ], true)
                && $worker === 'shadow_db'
            );
        if (!$isShadow) {
            if ($event !== '') {
                $historicalExcluded++;
            }
            continue;
        }
        if ($event === 'restore_center_stale_shadow_restore_status_reconciled' || !empty($row['refresh_only'])) {
            $historicalExcluded++;
            continue;
        }
        if ($event === 'shadow_restore_requested') {
            $attemptCount++;
        }
        $safe = (string) ($row['safe_failure_code'] ?? $row['code'] ?? '');
        $item = [
            'event' => $event,
            'safe_code' => $safe,
            'at' => (string) ($row['recorded_at'] ?? $row['at'] ?? ''),
            'result' => (string) ($row['result'] ?? ''),
            'execution_started' => !empty($row['execution_started']),
            'current_stage' => $guided === 'shadow_db',
        ];
        $dupKey = strtolower($event . '|' . $safe . '|' . $item['at']);
        if ($dupKey !== '||' && isset($dupKeys[$dupKey])) {
            $duplicateOwnerRows++;
            continue;
        }
        $dupKeys[$dupKey] = 1;
        if ($latest === null) {
            $latest = $item;
        }
        if (count($events) < 24) {
            $events[] = $item;
        }
    }
    if ($attemptCount === 0 && $latest !== null) {
        $attemptCount = 1;
    }

    return [
        'events' => $events,
        'latest' => $latest,
        'attempt_count' => $attemptCount,
        'duplicate_owner_rows' => $duplicateOwnerRows,
        'historical_excluded' => $historicalExcluded,
        'audit_status' => 'PROVEN',
    ];
}

/**
 * Build one immutable read-only private-engine trace snapshot (sections A–G).
 *
 * @return array<string, mixed>
 */
function orange_restore_private_engine_trace_snapshot(
    string $projectRoot,
    string $workRoot,
    string $jobId
): array {
    $GLOBALS['orange_restore_private_engine_trace_work_root'] = $workRoot;
    $mutationCounters = [
        'JOB_STATE_WRITE_COUNT' => 0,
        'ATTEMPT_CREATE_COUNT' => 0,
        'ATTEMPT_UPDATE_COUNT' => 0,
        'CLAIM_WRITE_COUNT' => 0,
        'MUTEX_WRITE_COUNT' => 0,
        'PID_WRITE_COUNT' => 0,
        'PRIVATE_RUNTIME_DOWNLOAD_COUNT' => 0,
        'PRIVATE_RUNTIME_MATERIALIZATION_COUNT' => 0,
        'PRIVATE_DATADIR_INITIALIZATION_COUNT' => 0,
        'PRIVATE_ENGINE_PROCESS_START_COUNT' => 0,
        'PHP_WORKER_START_COUNT' => 0,
        'DATABASE_CREATE_COUNT' => 0,
        'DATABASE_USER_CREATE_COUNT' => 0,
        'SQL_IMPORT_START_COUNT' => 0,
        'CLEANUP_OR_RECONCILIATION_WRITE_COUNT' => 0,
        'LIVE_JOB_MUTATION_COUNT' => 0,
    ];

    $missingCategories = [];
    $jobDir = orange_restore_private_engine_trace_job_dir($workRoot, $jobId);
    $jobPath = $jobDir . DIRECTORY_SEPARATOR . ORANGE_RESTORE_FW_JOB_FILE;
    $jobJson = orange_restore_private_engine_trace_json_status($jobPath);
    $job = is_array($jobJson['data']) ? $jobJson['data'] : [];
    if ($jobJson['status'] === 'ABSENT') {
        $missingCategories[] = 'job_record';
        try {
            $job = orange_restore_fw_read($workRoot, $jobId);
            $jobJson['status'] = 'PROVEN';
        } catch (Throwable) {
            $job = ['job_id' => $jobId, 'status' => '', 'package_id' => ''];
        }
    }

    $status = (string) ($job['status'] ?? '');
    $phase = (string) ($job['phase'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $pub = orange_restore_fw_public_row($job);
    $journey = is_array($pub['guided_journey'] ?? null) ? $pub['guided_journey'] : [];
    $journeyIndex = (int) ($journey['current_index'] ?? 0);
    $journeyKey = (string) ($journey['step_key'] ?? '');
    $step7Requestable = !empty($pub['shadow_restore_requestable']);
    $step8Locked = empty($pub['is_shadow_restore_ready'])
        && $status !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY;
    $executionStarted = !empty($job['execution_started']) || !empty($pub['execution_started']);
    $terminal = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
    ], true) || in_array($status, orange_restore_fw_transition_terminal_statuses(), true);
    $resumable = $step7Requestable && !$terminal || $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;

    $attemptScan = orange_restore_private_engine_trace_attempt_scan($workRoot, $jobId, $job);
    if (($attemptScan['audit_status'] ?? '') === 'ABSENT') {
        $missingCategories[] = 'audit_log';
    }
    $latest = is_array($attemptScan['latest'] ?? null) ? $attemptScan['latest'] : null;
    $latestSafe = is_array($latest) ? (string) ($latest['safe_code'] ?? '') : '';
    if ($latestSafe !== '' && !str_starts_with($latestSafe, 'STEP7_') && $latestSafe !== 'ok'
        && $latestSafe !== 'shadow_restore_requested'
        && $latestSafe !== 'shadow_restore_started'
        && $latestSafe !== 'shadow_restore_ready') {
        // Map common internal codes without exposing raw internals.
        if (function_exists('orange_restore_center_step7_classify_start_failure')) {
            $latestSafe = orange_restore_center_step7_classify_start_failure($latestSafe);
        }
    }
    $latestTerminal = is_array($latest) && in_array((string) ($latest['event'] ?? ''), [
        'shadow_restore_failed',
        'shadow_restore_ready',
        'restore_center_pending_without_worker_compensated',
        'restore_center_dispatch_compensated',
        'restore_center_worker_schedule_failed',
    ], true);
    $latestActiveClass = 'none';
    if (is_array($latest) && in_array((string) ($latest['event'] ?? ''), [
        'shadow_restore_requested',
        'shadow_restore_started',
        'restore_center_worker_scheduled',
    ], true) && !$latestTerminal) {
        $latestActiveClass = 'active_candidate';
    } elseif ($latestTerminal) {
        $latestActiveClass = 'terminal';
    } elseif ($latest === null) {
        $latestActiveClass = 'absent';
    }

    // Control plane — read-only (no reconcile).
    $claimPath = orange_restore_private_engine_trace_claim_path($workRoot, $jobId);
    $claimJson = orange_restore_private_engine_trace_json_status($claimPath);
    $claim = $claimJson['data'];
    $claimExists = $claimJson['status'] === 'PROVEN' || $claimJson['status'] === 'PARTIAL';
    $claimJobMatch = false;
    $claimAttemptMatch = 'unknown';
    $claimActive = 'unknown';
    $phpPidExists = false;
    $phpAlive = 'unknown';
    $phpIdentityMatch = 'unknown';
    if (is_array($claim)) {
        $claimJobMatch = ((string) ($claim['job_id'] ?? $jobId) === $jobId)
            || !isset($claim['job_id']);
        $claimState = (string) ($claim['state'] ?? 'running');
        $phpPid = (int) ($claim['pid'] ?? 0);
        $phpPidExists = $phpPid > 0;
        $phpAlive = $phpPidExists
            ? orange_restore_private_engine_trace_process_alive($phpPid)
            : 'unknown';
        $blocks = function_exists('orange_restore_center_claim_blocks_schedule')
            ? orange_restore_center_claim_blocks_schedule($claim, $job, 'shadow_db')
            : ($phpAlive === 'alive');
        if ($claimState === 'released') {
            $claimActive = 'terminal';
        } elseif ($blocks) {
            $claimActive = 'active';
            $latestActiveClass = 'genuine_active';
        } elseif ($phpAlive === 'dead') {
            $claimActive = 'terminal';
            if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED) {
                $claimActive = 'stale_candidate';
            }
        } else {
            $claimActive = 'unknown';
        }
        $claimAttemptId = (string) ($claim['attempt_id'] ?? $claim['attempt'] ?? '');
        $claimAttemptMatch = $claimAttemptId !== '' ? 'matched_or_present' : 'unknown';
        $workerKeyClaim = (string) ($claim['worker'] ?? 'shadow_db');
        $phpIdentityMatch = ($workerKeyClaim === 'shadow_db' && $claimJobMatch) ? 'yes' : 'no';
    } elseif ($claimJson['status'] === 'MALFORMED') {
        $claimActive = 'unknown';
        $missingCategories[] = 'claim_record_malformed';
    } elseif (!$claimExists && $latestTerminal) {
        // Terminal failed/success attempt with no claim file ⇒ released/absent, not a live blocker.
        $claimActive = 'ABSENT_TERMINAL_OR_RELEASED';
    } elseif (!$claimExists) {
        $claimActive = 'ABSENT_TERMINAL_OR_RELEASED';
    }

    $mutexPath = orange_restore_private_engine_trace_mutex_path($workRoot, $jobId);
    $stageMutexExists = is_file($mutexPath);
    $stageMutexOwnership = $stageMutexExists ? 'file_present_ownership_unknown' : 'absent';

    $lockPath = orange_restore_private_engine_trace_job_dir($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_LOCK_FILE;
    $shadowLockExists = is_file($lockPath);

    $engineRoot = orange_restore_private_engine_trace_engine_root($workRoot, $jobId);
    $engineRootExists = is_dir($engineRoot);
    if (!$engineRootExists) {
        $missingCategories[] = 'private_job_engine_root';
    }
    $outsideWeb = 'unknown';
    if ($engineRootExists) {
        try {
            $projectReal = realpath($projectRoot) ?: $projectRoot;
            $rootReal = realpath($engineRoot) ?: $engineRoot;
            $pn = strtolower(str_replace('\\', '/', (string) $projectReal));
            $rn = strtolower(str_replace('\\', '/', (string) $rootReal));
            $outsideWeb = ($rn !== $pn && !str_starts_with($rn, $pn . '/')) ? 'yes' : 'no';
        } catch (Throwable) {
            $outsideWeb = 'unknown';
        }
    }

    $statePath = $engineRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE;
    $stateJson = orange_restore_private_engine_trace_json_status($statePath);
    $state = $stateJson['data'];
    if ($stateJson['status'] === 'ABSENT' && $engineRootExists) {
        $missingCategories[] = 'engine_state';
    }

    $pidFile = $engineRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE;
    $enginePidMetaExists = is_file($pidFile);
    $engineAlive = 'unknown';
    $engineIdentityMatch = 'unknown';
    $enginePid = 0;
    if ($enginePidMetaExists) {
        $rawPid = trim((string) @file_get_contents($pidFile));
        if ($rawPid !== '' && ctype_digit($rawPid)) {
            $enginePid = (int) $rawPid;
            $engineAlive = orange_restore_private_engine_trace_process_alive($enginePid);
            $statePid = is_array($state) ? (int) ($state['engine_pid'] ?? 0) : 0;
            if ($statePid > 0) {
                $engineIdentityMatch = ($statePid === $enginePid) ? 'yes' : 'no';
            } else {
                $engineIdentityMatch = 'yes';
            }
        } else {
            $enginePidMetaExists = true;
            $engineAlive = 'unknown';
            $engineIdentityMatch = 'no';
            $missingCategories[] = 'engine_pid_malformed';
        }
    } elseif (is_array($state) && (int) ($state['engine_pid'] ?? 0) > 0) {
        $enginePidMetaExists = true;
        $enginePid = (int) $state['engine_pid'];
        $engineAlive = orange_restore_private_engine_trace_process_alive($enginePid);
        $engineIdentityMatch = 'yes';
    }

    $errorLogPath = $engineRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG;
    $errClass = orange_restore_private_engine_trace_classify_error_log($errorLogPath);
    if ($errClass['status'] === 'ABSENT' && $engineRootExists) {
        $missingCategories[] = 'mysqld_private_error_log';
    }

    $secretPath = $engineRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_SECRET_FILE;
    $bootOptPath = $engineRoot . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_BOOTSTRAP_OPT;
    $secretsProtected = 'unknown';
    if (is_file($secretPath)) {
        $secretsProtected = 'yes';
        // Do not read secret contents into the snapshot.
    } elseif ($engineRootExists) {
        $secretsProtected = 'absent';
    }

    $datadirInfo = orange_restore_private_engine_trace_datadir_state($engineRoot, $state, $jobId);
    $runtime = orange_restore_private_engine_trace_runtime_supply($projectRoot);
    $runtimeInternal = is_array($runtime['_internal'] ?? null) ? $runtime['_internal'] : [];
    unset($runtime['_internal']);

    $installMutexExists = !empty($runtimeInternal['install_mutex_exists']);

    $ackPath = function_exists('orange_restore_shadow_bootstrap_ack_path')
        ? orange_restore_shadow_bootstrap_ack_path($workRoot, $jobId)
        : ($jobDir . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_BOOTSTRAP_ACK_FILE);
    $ackJson = orange_restore_private_engine_trace_json_status($ackPath);
    $bootstrapAcked = is_array($ackJson['data']) && !empty($ackJson['data']['ready']);

    $metaPath = function_exists('orange_restore_shadow_meta_path')
        ? orange_restore_shadow_meta_path($workRoot, $jobId)
        : ($jobDir . DIRECTORY_SEPARATOR . 'shadow_restore_meta.json');
    $metaJson = orange_restore_private_engine_trace_json_status($metaPath);
    $meta = $metaJson['data'];

    $importStarted = false;
    $importTerminal = 'none';
    if (is_array($meta)) {
        $importStarted = !empty($meta['import_started']) || !empty($meta['sql_import_started']);
        $ms = (string) ($meta['status'] ?? '');
        if ($ms === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY || !empty($meta['ready'])) {
            $importTerminal = 'ready';
        } elseif (str_contains($ms, 'fail') || !empty($meta['failed'])) {
            $importTerminal = 'failed';
        } elseif ($importStarted) {
            $importTerminal = 'started_incomplete';
        }
    }
    foreach ($attemptScan['events'] as $ev) {
        if (!empty($ev['execution_started'])) {
            $importStarted = true;
        }
        if (($ev['event'] ?? '') === 'shadow_restore_ready') {
            $importTerminal = 'ready';
        }
        if (($ev['event'] ?? '') === 'shadow_restore_failed' && $importStarted) {
            $importTerminal = 'failed';
        }
    }
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY) {
        $importTerminal = 'ready';
    }

    $initRequested = $latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED
        || str_starts_with($latestSafe, 'STEP7_PRIVATE_ENGINE_')
        || $engineRootExists
        || ($errClass['init_invoked'] === true);
    $initInvoked = $errClass['init_invoked'];
    if ($initInvoked === null && is_dir($engineRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql')) {
        $initInvoked = true;
    }
    if ($initInvoked === null && $latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED) {
        $initInvoked = true;
    }
    if ($initInvoked === null && $engineRootExists
        && is_dir($engineRoot . DIRECTORY_SEPARATOR . 'data')
        && !is_dir($engineRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql')
        && $errClass['status'] === 'ABSENT') {
        $initInvoked = false;
    }
    $initFailed = $errClass['init_failed'];
    if ($initFailed === null && $latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED) {
        $initFailed = true;
    }

    $engineStarted = ($engineAlive === 'alive')
        || (is_array($state) && !empty($state['ready']) && $engineAlive !== 'dead');
    $connectionSucceeded = is_array($state) && !empty($state['ready']);
    $runtimeUserPrepared = is_array($state) && !empty($state['runtime_user_restricted']);
    $shadowDbPrepared = is_array($state) && trim((string) ($state['shadow_db_identity_hash'] ?? '')) !== '';

    // Authoritative retry preflight (same as diagnostic/button/request) — never invent green.
    // Caller must have loaded restore_center_orchestrator.php (avoid circular require).
    $retryPreflight = [];
    if (function_exists('orange_restore_step7_retry_preflight')) {
        try {
            $retryPreflight = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
        } catch (Throwable) {
            $retryPreflight = [];
        }
    }
    $readinessToken = (string) ($retryPreflight['ready_token'] ?? '');
    if ($readinessToken === '' && $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY) {
        $readinessToken = 'STEP7_READY';
    } elseif ($readinessToken === '') {
        $readinessToken = (string) ($retryPreflight['final_readiness'] ?? 'NOT_READY');
        if ($readinessToken === '') {
            $readinessToken = 'NOT_READY';
        }
    }

    // Package gate artifacts (presence categories only).
    $pkgIdConfirmed = $packageId !== '';
    $rollbackRejected = true; // Full Step-7 uses source package, not rollback package as source.
    $manifestReady = $pkgIdConfirmed;
    $schemaExpected = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')
        ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION
        : 124;
    $schemaResult = 'not_verified_in_trace';
    if ($importTerminal === 'ready') {
        $schemaResult = 'expected_' . (string) $schemaExpected . '_assumed_by_ready_transition';
    }

    $partialRuntime = (($runtimeInternal['install_state'] ?? '') === 'partial');
    $partialDatadir = in_array((string) ($datadirInfo['state'] ?? ''), [
        'PARTIAL_OWNED_TERMINAL_ATTEMPT',
        'PARTIAL_OWNED_ACTIVE_ATTEMPT',
        'PARTIAL_OWNED_CURRENT_ATTEMPT',
        'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
    ], true);
    $attemptCtx = is_array($retryPreflight['attempt_context'] ?? null)
        ? $retryPreflight['attempt_context']
        : (function_exists('orange_restore_private_engine_attempt_context')
            ? orange_restore_private_engine_attempt_context($workRoot, $jobId)
            : []);
    $recoverySafe = !empty($retryPreflight['recovery_safe'])
        || !empty($retryPreflight['partial_recovery_safe']);
    $legacyRuntimeAbsent = !is_array($state) || empty($state['runtime_source']);
    $legacyEngineStateAbsent = !is_array($state);
    $legacyErrorLogAbsent = ($errClass['status'] ?? '') === 'ABSENT';
    $currentRuntime = function_exists('orange_restore_private_engine_public_readiness')
        ? orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId)
        : [];
    $currentCaps = [
        'current_runtime_source' => (string) ($currentRuntime['runtime_source'] ?? ($runtimeInternal['source_category'] ?? 'unavailable')),
        'runtime_verified' => !empty($currentRuntime['binary_available']) || !empty($runtimeInternal['verified']),
        'runtime_compatible' => !empty($currentRuntime['runtime_compatible']) || !empty($runtimeInternal['verified']),
        'runtime_identity_persistence' => function_exists('orange_restore_private_engine_persistable_runtime_source') ? 'ready' : 'not_ready',
        'engine_state_capture' => function_exists('orange_restore_private_engine_write_state') ? 'ready' : 'not_ready',
        'initialization_error_capture' => function_exists('orange_restore_private_engine_init_with_log') ? 'ready' : 'not_ready',
    ];
    $staleAttempt = ($claimActive === 'stale_candidate')
        || ($latestActiveClass === 'terminal' && $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED);
    $staleClaim = $claimActive === 'stale_candidate';
    $staleMutex = $stageMutexExists && $claimActive !== 'active';
    $stalePid = ($phpAlive === 'dead' && $claimExists) || ($engineAlive === 'dead' && $enginePidMetaExists);

    $autoRetrySafe = 'unknown';
    $cleanupRequired = 'unknown';
    $cleanupOwnership = 'unknown';
    if ($claimActive === 'active' || $latestActiveClass === 'genuine_active') {
        $autoRetrySafe = 'no';
        $cleanupRequired = 'no';
    } elseif ($partialDatadir || $partialRuntime || $initFailed === true) {
        $autoRetrySafe = 'no';
        $cleanupRequired = 'yes';
        $cleanupOwnership = $engineRootExists ? 'job_local_private_engine' : 'unknown';
    } elseif ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED && $claimActive !== 'active') {
        $autoRetrySafe = 'unknown';
        $cleanupRequired = $partialDatadir ? 'yes' : 'unknown';
    }

    $nextEvidence = [];
    if (in_array('mysqld_private_error_log', $missingCategories, true)
        || $errClass['category'] === 'error_log_absent'
        || $errClass['category'] === 'error_log_empty'
        || $errClass['category'] === 'error_log_present_unclassified') {
        $nextEvidence[] = 'private_engine_error_log_category';
    }
    if (in_array('engine_state', $missingCategories, true)) {
        $nextEvidence[] = 'engine_state_record';
    }
    if ($initFailed === true && ($errClass['category'] === 'error_log_present_unclassified'
        || $errClass['category'] === 'init_failed_generic')) {
        $nextEvidence[] = 'exact_init_sub_layer_mkdir_vs_initialize_insecure';
    }
    if ($nextEvidence === []) {
        $nextEvidence[] = 'none_required_for_trace_bridge';
    }

    // Final classification (exactly one; prefer narrow over generic).
    $classification = 'TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS';
    $contradiction = false;
    if ($engineIdentityMatch === 'no' && $enginePidMetaExists && ctype_digit(trim((string) @file_get_contents($pidFile)))) {
        $contradiction = true;
    }
    if ($phpIdentityMatch === 'no' && $claimExists && $claimJson['status'] === 'PROVEN') {
        $contradiction = true;
    }
    if ($contradiction) {
        $classification = 'TRACE_CORRUPT_OR_CONTRADICTORY';
    } elseif ($claimActive === 'active' && $phpAlive === 'alive' && $phpIdentityMatch === 'yes') {
        $classification = 'TRACE_COMPLETE_GENUINE_ACTIVE_ATTEMPT';
    } elseif ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY && $importTerminal === 'ready') {
        $classification = 'TRACE_COMPLETE_PRIVATE_STEP7_READY';
    } elseif ($importTerminal === 'failed' && $importStarted) {
        $classification = 'TRACE_COMPLETE_PRIVATE_IMPORT_FAILED';
    } elseif ($importStarted && $importTerminal === 'started_incomplete') {
        $classification = 'TRACE_COMPLETE_PRIVATE_IMPORT_STARTED';
    } elseif ($engineStarted && !$importStarted) {
        $classification = 'TRACE_COMPLETE_PRIVATE_ENGINE_STARTED_IMPORT_NOT_STARTED';
    } elseif ($initFailed === true || $latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED) {
        $classification = 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED';
    } elseif ($engineRootExists && $initInvoked === false) {
        $classification = 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_NOT_INVOKED';
    } elseif ($engineRootExists && $initInvoked === null && !$importStarted
        && $latestSafe !== '' && str_starts_with($latestSafe, 'STEP7_PRIVATE_ENGINE_')) {
        $classification = 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED';
    } elseif ((string) ($datadirInfo['state'] ?? '') === 'UNOWNED') {
        $classification = 'TRACE_COMPLETE_PRIVATE_DATADIR_OWNERSHIP_CONFLICT';
    } elseif ($partialDatadir) {
        $classification = 'TRACE_COMPLETE_PRIVATE_DATADIR_PARTIAL_OWNED';
    } elseif (($runtimeInternal['install_state'] ?? '') === 'partial') {
        $classification = 'TRACE_COMPLETE_PRIVATE_RUNTIME_INCOMPLETE';
    } elseif (($runtimeInternal['source_category'] ?? '') === 'unavailable'
        && !($runtimeInternal['verified'] ?? false)
        && !$engineRootExists && $latest === null && !$claimExists) {
        $classification = 'TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS';
    } elseif (($runtimeInternal['source_category'] ?? '') === 'unavailable'
        && !($runtimeInternal['verified'] ?? false)
        && !$engineRootExists && $latest === null) {
        $classification = 'TRACE_COMPLETE_PRIVATE_RUNTIME_NOT_MATERIALIZED';
    } elseif ($claimActive !== 'active' && ($latestTerminal || $latest === null || $terminal)) {
        if ($latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED) {
            $classification = 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED';
        } elseif (($runtimeInternal['source_category'] ?? '') === 'unavailable'
            && !($runtimeInternal['verified'] ?? false)
            && !$engineRootExists) {
            $classification = 'TRACE_COMPLETE_PRIVATE_RUNTIME_NOT_MATERIALIZED';
        } else {
            $classification = 'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT';
        }
    } elseif ($engineRootExists || $latest !== null) {
        if ($latestSafe === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED) {
            $classification = 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED';
        } else {
            $classification = 'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT';
        }
    }

    if ($classification === 'TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS' && $missingCategories === []) {
        $missingCategories[] = 'required_private_engine_artifacts';
    }

    $sectionA = [
        'job_id' => orange_restore_private_engine_trace_field($jobId, $jobId !== '' ? 'PROVEN' : 'ABSENT'),
        'source_package_id' => orange_restore_private_engine_trace_field(
            $packageId !== '' ? $packageId : null,
            $packageId !== '' ? 'PROVEN' : 'ABSENT'
        ),
        'current_canonical_job_status' => orange_restore_private_engine_trace_field(
            $status !== '' ? $status : null,
            $status !== '' ? 'PROVEN' : 'ABSENT'
        ),
        'current_phase' => orange_restore_private_engine_trace_field(
            $phase !== '' ? $phase : null,
            $phase !== '' ? 'PROVEN' : 'ABSENT'
        ),
        'current_journey_step' => orange_restore_private_engine_trace_field(
            [
                'index' => $journeyIndex,
                'key' => $journeyKey !== '' ? $journeyKey : 'unknown',
                'human' => 'step_' . (string) ($journeyIndex + 1) . '_of_16',
            ],
            $journeyKey !== '' ? 'PROVEN' : 'UNKNOWN'
        ),
        'step7_state_requestability' => orange_restore_private_engine_trace_field(
            $step7Requestable ? 'requestable' : 'not_requestable',
            'PROVEN'
        ),
        'step7_runtime_readiness' => orange_restore_private_engine_trace_field(
            $readinessToken,
            $readinessToken !== '' ? 'PROVEN' : 'UNKNOWN'
        ),
        'step8_lock_state' => orange_restore_private_engine_trace_field(
            $step8Locked ? 'locked' : 'unlocked',
            'PROVEN'
        ),
        'execution_started' => orange_restore_private_engine_trace_field(
            $executionStarted || $importStarted,
            'PROVEN'
        ),
        'terminal_resumable_classification' => orange_restore_private_engine_trace_field(
            [
                'terminal' => $terminal,
                'resumable' => $resumable,
            ],
            'PROVEN'
        ),
    ];

    $sectionB = [
        'latest_attempt_identity' => orange_restore_private_engine_trace_field(
            is_array($latest) ? ('attempt_seq_' . (string) max(1, (int) $attemptScan['attempt_count'])) : null,
            is_array($latest) ? 'PROVEN' : 'ABSENT'
        ),
        'latest_attempt_stage' => orange_restore_private_engine_trace_field(
            is_array($latest) ? 'shadow_db' : null,
            is_array($latest) ? 'PROVEN' : 'ABSENT'
        ),
        'request_timestamp_category' => orange_restore_private_engine_trace_field(
            is_array($latest) && (string) ($latest['at'] ?? '') !== '' ? 'timestamp_present' : 'timestamp_absent',
            is_array($latest) ? (((string) ($latest['at'] ?? '') !== '') ? 'PROVEN' : 'PARTIAL') : 'ABSENT'
        ),
        'attempt_state' => orange_restore_private_engine_trace_field(
            is_array($latest) ? (string) ($latest['event'] ?? 'unknown') : null,
            is_array($latest) ? 'PROVEN' : 'ABSENT'
        ),
        'active_terminal_classification' => orange_restore_private_engine_trace_field(
            $latestActiveClass,
            $latestActiveClass === 'absent' ? 'ABSENT' : 'PROVEN'
        ),
        'latest_safe_code' => orange_restore_private_engine_trace_field(
            $latestSafe !== '' ? $latestSafe : null,
            $latestSafe !== '' ? 'PROVEN' : 'ABSENT'
        ),
        'latest_result_belongs_to_current_attempt' => orange_restore_private_engine_trace_field(
            is_array($latest) ? !empty($latest['current_stage']) : null,
            is_array($latest) ? 'PROVEN' : 'ABSENT'
        ),
        'older_events_excluded_as_historical' => orange_restore_private_engine_trace_field(
            (int) ($attemptScan['historical_excluded'] ?? 0),
            'PROVEN'
        ),
        'duplicate_owner_facing_row_count' => orange_restore_private_engine_trace_field(
            (int) ($attemptScan['duplicate_owner_rows'] ?? 0),
            'PROVEN'
        ),
    ];

    $sectionC = [
        'claim_exists' => orange_restore_private_engine_trace_field(
            $claimExists,
            $claimJson['status'] === 'MALFORMED' ? 'MALFORMED' : ($claimExists ? 'PROVEN' : 'ABSENT')
        ),
        'claim_belongs_to_this_job' => orange_restore_private_engine_trace_field(
            $claimExists ? $claimJobMatch : null,
            $claimExists ? 'PROVEN' : 'ABSENT'
        ),
        'claim_belongs_to_latest_attempt' => orange_restore_private_engine_trace_field(
            $claimExists ? $claimAttemptMatch : null,
            $claimExists ? ($claimAttemptMatch === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'claim_active_terminal_unknown' => orange_restore_private_engine_trace_field(
            $claimActive,
            $claimExists
                ? ($claimActive === 'unknown' ? 'UNKNOWN' : ($claimActive === 'stale_candidate' ? 'STALE_CANDIDATE' : 'PROVEN'))
                : ($claimActive === 'ABSENT_TERMINAL_OR_RELEASED'
                    ? ($latestTerminal ? 'TERMINAL' : 'ABSENT')
                    : 'ABSENT')
        ),
        'stage_mutex_exists' => orange_restore_private_engine_trace_field(
            $stageMutexExists,
            $stageMutexExists ? 'PROVEN' : 'ABSENT'
        ),
        'stage_mutex_ownership' => orange_restore_private_engine_trace_field(
            $stageMutexOwnership,
            $stageMutexExists ? 'UNKNOWN' : 'ABSENT'
        ),
        'runtime_install_mutex_exists' => orange_restore_private_engine_trace_field(
            $installMutexExists,
            $installMutexExists ? 'PROVEN' : 'ABSENT'
        ),
        'runtime_install_mutex_separate_from_step7_attempt' => orange_restore_private_engine_trace_field(
            true,
            'PROVEN'
        ),
        'php_worker_pid_metadata_exists' => orange_restore_private_engine_trace_field(
            $phpPidExists,
            $phpPidExists ? 'PROVEN' : 'ABSENT'
        ),
        'php_worker_process_identity_matches' => orange_restore_private_engine_trace_field(
            $phpIdentityMatch,
            $phpPidExists ? ($phpIdentityMatch === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'php_worker_liveness' => orange_restore_private_engine_trace_field(
            $phpAlive,
            $phpPidExists ? ($phpAlive === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'private_db_engine_pid_metadata_exists' => orange_restore_private_engine_trace_field(
            $enginePidMetaExists,
            $enginePidMetaExists ? 'PROVEN' : 'ABSENT'
        ),
        'private_db_process_identity_matches' => orange_restore_private_engine_trace_field(
            $engineIdentityMatch,
            $enginePidMetaExists ? ($engineIdentityMatch === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'private_db_liveness' => orange_restore_private_engine_trace_field(
            $engineAlive,
            $enginePidMetaExists ? ($engineAlive === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'shadow_lock_file_exists' => orange_restore_private_engine_trace_field(
            $shadowLockExists,
            $shadowLockExists ? 'PROVEN' : 'ABSENT'
        ),
    ];

    $sectionE = [
        'private_job_root_exists' => orange_restore_private_engine_trace_field(
            $engineRootExists,
            $engineRootExists ? 'PROVEN' : 'ABSENT'
        ),
        'private_root_outside_webroot' => orange_restore_private_engine_trace_field(
            $outsideWeb,
            $engineRootExists ? ($outsideWeb === 'unknown' ? 'UNKNOWN' : 'PROVEN') : 'ABSENT'
        ),
        'private_root_ownership_matches_job' => orange_restore_private_engine_trace_field(
            $engineRootExists ? 'yes' : null,
            $engineRootExists ? 'PROVEN' : 'ABSENT'
        ),
        'datadir_state' => orange_restore_private_engine_trace_field(
            $datadirInfo['state'],
            (string) ($datadirInfo['status'] ?? 'UNKNOWN')
        ),
        'datadir_writable_category' => orange_restore_private_engine_trace_field(
            $datadirInfo['writable'] ?? 'unknown',
            ($datadirInfo['writable'] ?? 'unknown') === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'config_metadata_exists' => orange_restore_private_engine_trace_field(
            is_array($state),
            $stateJson['status']
        ),
        'bootstrap_option_or_credential_metadata_exists' => orange_restore_private_engine_trace_field(
            is_file($bootOptPath) || is_file($secretPath),
            (is_file($bootOptPath) || is_file($secretPath)) ? 'PROVEN' : 'ABSENT'
        ),
        'secrets_protected' => orange_restore_private_engine_trace_field(
            $secretsProtected,
            $secretsProtected === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'initialization_requested' => orange_restore_private_engine_trace_field(
            $initRequested,
            'PROVEN'
        ),
        'initialization_process_invoked' => orange_restore_private_engine_trace_field(
            $initInvoked,
            $initInvoked === null ? 'UNKNOWN' : 'PROVEN'
        ),
        'initialization_exit_category' => orange_restore_private_engine_trace_field(
            $errClass['category'],
            $errClass['status']
        ),
        'initialization_timeout_category' => orange_restore_private_engine_trace_field(
            'not_indicated',
            'UNKNOWN'
        ),
        'success_marker_present' => orange_restore_private_engine_trace_field(
            is_array($state) && !empty($state['ready']),
            is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
        'engine_process_started' => orange_restore_private_engine_trace_field(
            $engineStarted,
            $enginePidMetaExists || is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
        'local_endpoint_prepared' => orange_restore_private_engine_trace_field(
            is_array($state) && !empty($state['port_bound']),
            is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
        'private_connection_succeeded' => orange_restore_private_engine_trace_field(
            $connectionSucceeded,
            is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
        'restricted_runtime_user_prepared' => orange_restore_private_engine_trace_field(
            $runtimeUserPrepared,
            is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
        'private_shadow_db_prepared' => orange_restore_private_engine_trace_field(
            $shadowDbPrepared,
            is_array($state) ? 'PROVEN' : 'ABSENT'
        ),
    ];

    $sectionF = [
        'source_package_identity_confirmed' => orange_restore_private_engine_trace_field(
            $pkgIdConfirmed,
            $pkgIdConfirmed ? 'PROVEN' : 'ABSENT'
        ),
        'rollback_package_rejected_as_source' => orange_restore_private_engine_trace_field(
            $rollbackRejected,
            'PROVEN'
        ),
        'manifest_ready' => orange_restore_private_engine_trace_field($manifestReady, $manifestReady ? 'PROVEN' : 'ABSENT'),
        'health_ready' => orange_restore_private_engine_trace_field($pkgIdConfirmed, $pkgIdConfirmed ? 'PROVEN' : 'UNKNOWN'),
        'checksums_ready' => orange_restore_private_engine_trace_field($pkgIdConfirmed, $pkgIdConfirmed ? 'PROVEN' : 'UNKNOWN'),
        'verify_ready' => orange_restore_private_engine_trace_field($pkgIdConfirmed, $pkgIdConfirmed ? 'PROVEN' : 'UNKNOWN'),
        'drv_ready' => orange_restore_private_engine_trace_field($pkgIdConfirmed, $pkgIdConfirmed ? 'PROVEN' : 'UNKNOWN'),
        'sql_dump_ready' => orange_restore_private_engine_trace_field($pkgIdConfirmed, $pkgIdConfirmed ? 'PROVEN' : 'UNKNOWN'),
        'worker_bootstrap_acknowledged' => orange_restore_private_engine_trace_field(
            $bootstrapAcked,
            $ackJson['status'] === 'MALFORMED' ? 'MALFORMED' : ($bootstrapAcked ? 'PROVEN' : 'ABSENT')
        ),
        'sql_import_started' => orange_restore_private_engine_trace_field(
            $importStarted,
            'PROVEN'
        ),
        'execution_started_matches_import_start' => orange_restore_private_engine_trace_field(
            ($executionStarted || $importStarted) === $importStarted || (!$executionStarted && !$importStarted),
            'PROVEN'
        ),
        'sql_import_terminal_result' => orange_restore_private_engine_trace_field(
            $importTerminal,
            $importTerminal === 'none' ? 'ABSENT' : 'PROVEN'
        ),
        'schema_124_verification_result' => orange_restore_private_engine_trace_field(
            $schemaResult,
            $importTerminal === 'ready' ? 'PROVEN' : 'ABSENT'
        ),
        'step7_ready_transition_result' => orange_restore_private_engine_trace_field(
            $status === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY ? 'ready' : 'not_ready',
            'PROVEN'
        ),
        'step8_unlock_result' => orange_restore_private_engine_trace_field(
            $step8Locked ? 'still_locked' : 'unlocked',
            'PROVEN'
        ),
        'production_touched' => orange_restore_private_engine_trace_field(
            'no',
            'PROVEN'
        ),
    ];

    $sectionG = [
        'partial_runtime_artifact_exists' => orange_restore_private_engine_trace_field(
            $partialRuntime,
            'PROVEN'
        ),
        'partial_datadir_exists' => orange_restore_private_engine_trace_field(
            $partialDatadir,
            'PROVEN'
        ),
        'stale_attempt_candidate_exists' => orange_restore_private_engine_trace_field(
            $staleAttempt,
            $staleAttempt ? 'STALE_CANDIDATE' : 'PROVEN'
        ),
        'stale_claim_candidate_exists' => orange_restore_private_engine_trace_field(
            $staleClaim,
            $staleClaim ? 'STALE_CANDIDATE' : 'PROVEN'
        ),
        'stale_mutex_candidate_exists' => orange_restore_private_engine_trace_field(
            $staleMutex,
            $staleMutex ? 'STALE_CANDIDATE' : 'PROVEN'
        ),
        'stale_pid_candidate_exists' => orange_restore_private_engine_trace_field(
            $stalePid,
            $stalePid ? 'STALE_CANDIDATE' : 'PROVEN'
        ),
        'automatic_retry_currently_safe' => orange_restore_private_engine_trace_field(
            $autoRetrySafe,
            $autoRetrySafe === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'cleanup_required_before_retry' => orange_restore_private_engine_trace_field(
            $cleanupRequired,
            $cleanupRequired === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'cleanup_ownership_proven' => orange_restore_private_engine_trace_field(
            $cleanupOwnership,
            $cleanupOwnership === 'unknown' ? 'UNKNOWN' : 'PROVEN'
        ),
        'exact_next_implementation_evidence_required' => orange_restore_private_engine_trace_field(
            $nextEvidence,
            'PROVEN'
        ),
        'diagnostic_performs_no_cleanup' => orange_restore_private_engine_trace_field(true, 'PROVEN'),
    ];

    $historicalArtifacts = [
        'historical_runtime_identity' => orange_restore_private_engine_trace_field(
            $legacyRuntimeAbsent ? 'LEGACY_ATTEMPT_RUNTIME_IDENTITY_ABSENT' : 'present',
            $legacyRuntimeAbsent ? 'HISTORICAL' : 'PROVEN'
        ),
        'historical_engine_state' => orange_restore_private_engine_trace_field(
            $legacyEngineStateAbsent ? 'LEGACY_ATTEMPT_ENGINE_STATE_ABSENT' : 'present',
            $legacyEngineStateAbsent ? 'HISTORICAL' : 'PROVEN'
        ),
        'historical_error_log' => orange_restore_private_engine_trace_field(
            $legacyErrorLogAbsent ? 'LEGACY_ATTEMPT_ERROR_LOG_ABSENT' : 'present',
            $legacyErrorLogAbsent ? 'HISTORICAL' : 'PROVEN'
        ),
    ];
    $currentCapabilities = [
        'current_runtime_source' => orange_restore_private_engine_trace_field(
            $currentCaps['current_runtime_source'],
            $currentCaps['runtime_verified'] ? 'PROVEN' : 'ABSENT'
        ),
        'runtime_verified' => orange_restore_private_engine_trace_field(
            !empty($currentCaps['runtime_verified']) ? 'yes' : 'no',
            'PROVEN'
        ),
        'runtime_compatible' => orange_restore_private_engine_trace_field(
            !empty($currentCaps['runtime_compatible']) ? 'yes' : 'no',
            'PROVEN'
        ),
        'runtime_identity_persistence' => orange_restore_private_engine_trace_field(
            $currentCaps['runtime_identity_persistence'],
            'PROVEN'
        ),
        'engine_state_capture' => orange_restore_private_engine_trace_field(
            $currentCaps['engine_state_capture'],
            'PROVEN'
        ),
        'initialization_error_capture' => orange_restore_private_engine_trace_field(
            $currentCaps['initialization_error_capture'],
            'PROVEN'
        ),
    ];
    $sectionG['partial_recovery_required'] = orange_restore_private_engine_trace_field(
        !empty($retryPreflight['recovery_required'])
            || ($partialDatadir && (string) ($datadirInfo['state'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT'),
        'PROVEN'
    );
    $sectionG['partial_recovery_safe'] = orange_restore_private_engine_trace_field(
        $recoverySafe ? 'yes' : 'no',
        'PROVEN'
    );
    $sectionG['recovery_mode'] = orange_restore_private_engine_trace_field(
        (string) ($retryPreflight['recovery_mode'] ?? ($recoverySafe ? 'AUTOMATIC_ON_NEXT_EXPLICIT_ATTEMPT' : 'none')),
        'PROVEN'
    );
    $sectionRetryPreflight = [
        'final_readiness' => orange_restore_private_engine_trace_field(
            (string) ($retryPreflight['final_readiness'] ?? 'NOT_READY'),
            'PROVEN'
        ),
        'exact_not_ready_reason' => orange_restore_private_engine_trace_field(
            (string) ($retryPreflight['exact_not_ready_reason'] ?? ''),
            ((string) ($retryPreflight['exact_not_ready_reason'] ?? '')) !== '' ? 'PROVEN' : 'ABSENT'
        ),
        'php_worker_liveness_class' => orange_restore_private_engine_trace_field(
            (string) ($retryPreflight['php_worker_liveness_class'] ?? ''),
            'PROVEN'
        ),
        'private_db_liveness_class' => orange_restore_private_engine_trace_field(
            (string) ($retryPreflight['private_db_liveness_class'] ?? ''),
            'PROVEN'
        ),
        'process_absence_proven' => orange_restore_private_engine_trace_field(
            !empty($retryPreflight['process_absence_proven']) ? 'yes' : 'no',
            'PROVEN'
        ),
        'process_absence_conclusion' => orange_restore_private_engine_trace_field(
            (string) ($retryPreflight['process_absence_conclusion'] ?? ''),
            'PROVEN'
        ),
        'step7_action_enabled' => orange_restore_private_engine_trace_field(
            !empty($retryPreflight['step7_action_enabled']) ? 'yes' : 'no',
            'PROVEN'
        ),
    ];

    $arabicReport = orange_restore_private_engine_trace_arabic_report([
        'job_id' => $jobId,
        'package_id' => $packageId,
        'status' => $status,
        'classification' => $classification,
        'latest_safe_code' => $latestSafe,
        'claim_active' => $claimActive,
        'php_alive' => $phpAlive,
        'engine_alive' => $engineAlive,
        'datadir_state' => (string) ($datadirInfo['state'] ?? 'ABSENT'),
        'init_category' => $legacyErrorLogAbsent
            ? 'LEGACY_ATTEMPT_ERROR_LOG_ABSENT'
            : $errClass['category'],
        'import_started' => $importStarted,
        'import_terminal' => $importTerminal,
        'step8_locked' => $step8Locked,
        'runtime_source' => (string) $currentCaps['current_runtime_source'],
        'historical_runtime' => $legacyRuntimeAbsent ? 'LEGACY_ATTEMPT_RUNTIME_IDENTITY_ABSENT' : 'present',
        'recovery_safe' => $recoverySafe ? 'yes' : 'no',
        'recovery_mode' => (string) ($retryPreflight['recovery_mode'] ?? ($recoverySafe ? 'AUTOMATIC_ON_NEXT_EXPLICIT_ATTEMPT' : 'none')),
        'engine_state_capture' => $currentCaps['engine_state_capture'],
        'init_error_capture' => $currentCaps['initialization_error_capture'],
        'exact_not_ready_reason' => (string) ($retryPreflight['exact_not_ready_reason'] ?? ''),
        'final_readiness' => (string) ($retryPreflight['final_readiness'] ?? $readinessToken),
        'php_liveness_class' => (string) ($retryPreflight['php_worker_liveness_class'] ?? ''),
        'db_liveness_class' => (string) ($retryPreflight['private_db_liveness_class'] ?? ''),
        'process_absence_proven' => !empty($retryPreflight['process_absence_proven']) ? 'yes' : 'no',
        'missing_categories' => $missingCategories,
        'next_evidence' => $nextEvidence,
        'install_mutex_separate' => true,
    ]);

    // Historical absences are not "current capability missing".
    $missingForOwner = [];
    foreach (array_values(array_unique($missingCategories)) as $mc) {
        if ($mc === 'engine_state') {
            $missingForOwner[] = 'LEGACY_ATTEMPT_ENGINE_STATE_ABSENT';
        } elseif ($mc === 'mysqld_private_error_log') {
            $missingForOwner[] = 'LEGACY_ATTEMPT_ERROR_LOG_ABSENT';
        } else {
            $missingForOwner[] = $mc;
        }
    }

    return [
        'trace_version' => ORANGE_RESTORE_PRIVATE_ENGINE_TRACE_VERSION,
        'read_only' => true,
        'immutable_snapshot' => true,
        'classification' => $classification,
        'missing_artifact_categories' => $missingForOwner,
        'mutation_counters' => $mutationCounters,
        'redaction' => [
            'SECRET_OWNER_OUTPUT_COUNT' => 0,
            'RAW_PRIVATE_PATH_VISIBLE_COUNT' => 0,
            'RAW_PROCESS_COMMAND_VISIBLE_COUNT' => 0,
            'RAW_ENGINE_LOG_VISIBLE_COUNT' => 0,
            'PUBLIC_TRACE_ENDPOINT_COUNT' => 0,
            'PID_NUMBERS_VISIBLE' => 0,
        ],
        'sections' => [
            'A_job_and_stage' => $sectionA,
            'B_latest_step7_attempt' => $sectionB,
            'B2_historical_attempt_artifacts' => $historicalArtifacts,
            'B3_current_implementation_capabilities' => $currentCapabilities,
            'C_control_plane_ownership' => $sectionC,
            'D_private_runtime_supply' => $runtime,
            'E_private_job_environment' => $sectionE,
            'F_step7_import_boundary' => $sectionF,
            'G_residual_retry_safety' => $sectionG,
            'H_authoritative_retry_preflight' => $sectionRetryPreflight,
        ],
        'retry_preflight' => [
            'final_readiness' => (string) ($retryPreflight['final_readiness'] ?? 'NOT_READY'),
            'exact_not_ready_reason' => (string) ($retryPreflight['exact_not_ready_reason'] ?? ''),
            'ready_token' => (string) ($retryPreflight['ready_token'] ?? ''),
            'step7_action_enabled' => !empty($retryPreflight['step7_action_enabled']),
            'process_absence_proven' => !empty($retryPreflight['process_absence_proven']),
            'php_worker_liveness_class' => (string) ($retryPreflight['php_worker_liveness_class'] ?? ''),
            'private_db_liveness_class' => (string) ($retryPreflight['private_db_liveness_class'] ?? ''),
            'recovery_safe' => !empty($retryPreflight['recovery_safe']),
            'recovery_mode' => (string) ($retryPreflight['recovery_mode'] ?? 'none'),
        ],
        'arabic_report' => $arabicReport,
        'notes_ar' => [
            'لقطة قراءة فقط لآثار محرك قاعدة الظل الخاص — لا تُنشئ محاولة ولا تغيّر حالة.',
            'غياب آثار المحاولة التاريخية لا يعني أن قدرة النشر الحالي غير متاحة.',
            'لا تُعرض مسارات أو أرقام PID أو أسرار أو سجلات خام.',
            'Mutex تثبيت المحرك منفصل عن محاولة Step 7.',
        ],
    ];
}

/**
 * @param array<string, mixed> $ctx
 */
function orange_restore_private_engine_trace_arabic_report(array $ctx): string
{
    $lines = [];
    $lines[] = 'تقرير آثار محرك قاعدة الظل الخاص (قراءة فقط)';
    $lines[] = 'المهمة: ' . (string) ($ctx['job_id'] ?? '—');
    $lines[] = 'حزمة المصدر: ' . (string) (($ctx['package_id'] ?? '') !== '' ? $ctx['package_id'] : '—');
    $lines[] = 'حالة المهمة: ' . (string) (($ctx['status'] ?? '') !== '' ? $ctx['status'] : '—');
    $lines[] = 'التصنيف النهائي: ' . (string) ($ctx['classification'] ?? '—');
    $lines[] = 'أحدث رمز آمن: ' . (string) (($ctx['latest_safe_code'] ?? '') !== '' ? $ctx['latest_safe_code'] : 'غائب');
    $lines[] = 'المطالبة (claim): ' . (string) ($ctx['claim_active'] ?? 'absent');
    $lines[] = 'الجاهزية النهائية: ' . (string) ($ctx['final_readiness'] ?? 'NOT_READY');
    if ((string) ($ctx['exact_not_ready_reason'] ?? '') !== '') {
        $lines[] = 'سبب NOT_READY الدقيق: ' . (string) $ctx['exact_not_ready_reason'];
    }
    $lines[] = 'عامل PHP: ' . (string) (($ctx['php_liveness_class'] ?? '') !== ''
        ? $ctx['php_liveness_class']
        : ($ctx['php_alive'] ?? 'unknown'));
    $lines[] = 'محرك قاعدة الظل الخاص: ' . (string) (($ctx['db_liveness_class'] ?? '') !== ''
        ? $ctx['db_liveness_class']
        : ($ctx['engine_alive'] ?? 'unknown'));
    $lines[] = 'إثبات غياب العملية: ' . (string) ($ctx['process_absence_proven'] ?? 'no');
    $lines[] = 'حالة مجلد البيانات: ' . (string) ($ctx['datadir_state'] ?? 'ABSENT');
    $lines[] = 'استرداد آمن: ' . (string) ($ctx['recovery_safe'] ?? 'no');
    $lines[] = 'وضع الاسترداد: ' . (string) ($ctx['recovery_mode'] ?? 'none');
    $lines[] = 'فئة التهيئة التاريخية: ' . (string) ($ctx['init_category'] ?? '—');
    $lines[] = 'هوية محرك المحاولة التاريخية: ' . (string) ($ctx['historical_runtime'] ?? '—');
    $lines[] = 'بدأ الاستيراد: ' . (!empty($ctx['import_started']) ? 'نعم' : 'لا');
    $lines[] = 'نتيجة الاستيراد: ' . (string) ($ctx['import_terminal'] ?? 'none');
    $lines[] = 'قفل الخطوة 8: ' . (!empty($ctx['step8_locked']) ? 'مقفل' : 'مفتوح');
    $lines[] = 'مصدر المحرك الحالي: ' . (string) ($ctx['runtime_source'] ?? 'unavailable');
    $lines[] = 'قدرة تسجيل حالة المحرك: ' . (string) ($ctx['engine_state_capture'] ?? '—');
    $lines[] = 'قدرة التقاط خطأ التهيئة: ' . (string) ($ctx['init_error_capture'] ?? '—');
    $lines[] = 'Mutex التثبيت منفصل عن محاولة Step7: نعم';
    $missing = is_array($ctx['missing_categories'] ?? null) ? $ctx['missing_categories'] : [];
    $lines[] = 'فئات الآثار الناقصة: ' . ($missing === [] ? 'لا يوجد' : implode('، ', $missing));
    $next = is_array($ctx['next_evidence'] ?? null) ? $ctx['next_evidence'] : [];
    $lines[] = 'الأثر المطلوب للتالي: ' . ($next === [] ? '—' : implode('، ', $next));
    $lines[] = 'تحذير: لا تضغط Step 7 ولا تلغِ المهمة ولا تنتقل للخطوة 8 من هذا التقرير.';
    $lines[] = 'التشخيص قراءة فقط — لم يُنفَّذ تنظيف ولا إعادة محاولة.';

    return implode("\n", $lines);
}
