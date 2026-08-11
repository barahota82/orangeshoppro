<?php

declare(strict_types=1);

/**
 * Read-only Backup Center runtime diagnostic (Owner P0 — after Phase-1 live Full failure).
 *
 * NEVER: start Full/Countries, acquire/release/delete locks, spawn/kill processes,
 * create packages, mutate Restore, write .env, or expose secrets/raw paths in Owner UI.
 *
 * @see docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md
 */

require_once __DIR__ . '/backup_admin.php';
require_once __DIR__ . '/backup_provenance.php';

const ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_SCHEMA_VERSION = '1.0';
const ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_EXPECTED_SCHEMA = 124;
const ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_DISK_LOW_BYTES = 1073741824; // 1 GiB

/** @var list<string> */
const ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS = [
    'READY_FOR_CONTROLLED_FULL_ATTEMPT',
    'FULL_LOCK_ACTIVE',
    'FULL_LOCK_STALE_OR_ORPHANED',
    'FULL_LOCK_STATE_UNKNOWN',
    'COUNTRY_LOCK_ACTIVE',
    'COUNTRY_LOCK_STALE_OR_ORPHANED',
    'BACKUP_ROOT_NOT_READY',
    'BACKUP_ROOT_NOT_WRITABLE',
    'LOCK_DIRECTORY_NOT_READY',
    'INSUFFICIENT_DISK_SPACE',
    'PHP_CLI_UNAVAILABLE',
    'PROCESS_EXECUTION_UNAVAILABLE',
    'FULL_RUNNER_UNAVAILABLE',
    'COUNTRY_RUNNER_UNAVAILABLE',
    'DATABASE_UNAVAILABLE',
    'SCHEMA_GATE_MISMATCH',
    'LAST_FULL_FAILED_BEFORE_PROCESS_START',
    'LAST_FULL_PROCESS_STARTED_RUNNER_FAILED',
    'LAST_FULL_PACKAGE_GENERATION_FAILED',
    'LAST_FULL_VERIFY_FAILED',
    'LAST_FULL_DRV_FAILED',
    'LAST_FULL_RESPONSE_CLASSIFICATION_FAILED',
    'PERSISTED_FAILURE_EVIDENCE_UNAVAILABLE',
    'MULTIPLE_RUNTIME_BLOCKERS',
    'UNKNOWN_RUNTIME_BLOCKER',
];

/**
 * Join BackupRoot-relative path without creating directories.
 */
function orange_backup_runtime_diagnostic_join(string $backupRoot, string $relative): string
{
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, "/\\"));

    return rtrim($backupRoot, "/\\") . DIRECTORY_SEPARATOR . $relative;
}

/**
 * @return array{exists:bool,readable:bool,writable:bool}
 */
function orange_backup_runtime_diagnostic_dir_flags(string $path): array
{
    $exists = is_dir($path);

    return [
        'exists' => $exists,
        'readable' => $exists && is_readable($path),
        'writable' => $exists && is_writable($path),
    ];
}

/**
 * Diagnostic PID liveness — never treat unprobeable as alive.
 * Never spawns a process (no tasklist/proc_open). Windows without posix ⇒ unknown.
 *
 * @return 'alive'|'dead'|'unknown'
 */
function orange_backup_runtime_diagnostic_pid_liveness(int $pid): string
{
    if (isset($GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'])
        && is_string($GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'])) {
        $ov = strtolower(trim($GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override']));
        if (in_array($ov, ['alive', 'dead', 'unknown'], true)) {
            return $ov;
        }
    }
    if ($pid <= 0) {
        return 'unknown';
    }
    // Signal 0 is a non-destructive existence check (does not kill).
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0) ? 'alive' : 'dead';
    }

    // No spawn-based probes in diagnostic (Owner: never spawn/kill).
    return 'unknown';
}

/**
 * Read-only lock probe (no mkdir, no unlink, no flock).
 *
 * @return array<string, mixed>
 */
function orange_backup_runtime_diagnostic_lock_probe(string $lockPath, string $operationCategory): array
{
    $exists = is_file($lockPath);
    $out = [
        'exists' => $exists,
        'metadata_readable' => false,
        'operation_category' => $operationCategory,
        'acquired_at' => null,
        'safe_age_seconds' => null,
        'owner_pid_present' => false,
        'liveness' => 'unknown',
        'reclaimable_under_current_code' => false,
        'reason' => $exists ? 'lock_present' : 'lock_absent',
        'stale_by_absolute_age' => false,
    ];
    if (!$exists) {
        return $out;
    }
    $raw = @file_get_contents($lockPath);
    if (!is_string($raw) || $raw === '') {
        $out['reason'] = 'lock_unreadable';
        $out['reclaimable_under_current_code'] = true;

        return $out;
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta)) {
        $out['reason'] = 'lock_metadata_invalid';
        $out['reclaimable_under_current_code'] = true;

        return $out;
    }
    $out['metadata_readable'] = true;
    $pid = (int) ($meta['pid'] ?? 0);
    $startedAt = (string) ($meta['started_at'] ?? $meta['acquired_at'] ?? '');
    $out['owner_pid_present'] = $pid > 0;
    $out['acquired_at'] = $startedAt !== '' ? $startedAt : null;
    $age = null;
    if ($startedAt !== '') {
        $ts = strtotime($startedAt);
        if ($ts !== false) {
            $age = max(0, time() - $ts);
        }
    }
    if ($age === null) {
        $mtime = @filemtime($lockPath);
        if ($mtime !== false) {
            $age = max(0, time() - $mtime);
        }
    }
    $out['safe_age_seconds'] = $age;
    $stale = is_int($age) && $age >= ORANGE_BACKUP_LOCK_STALE_SECONDS;
    $out['stale_by_absolute_age'] = $stale;
    $liveness = $pid > 0 ? orange_backup_runtime_diagnostic_pid_liveness($pid) : 'unknown';
    $out['liveness'] = $liveness;

    // Current Phase-1/d570e563 acquire path: remove when !pidAlive || absolute-stale.
    // Diagnostic never deletes; report reclaimability under that code.
    if ($liveness === 'dead' || $stale) {
        $out['reclaimable_under_current_code'] = true;
        $out['reason'] = $stale ? 'absolute_stale_or_dead_owner' : 'owner_pid_dead';
    } elseif ($liveness === 'alive') {
        $out['reclaimable_under_current_code'] = false;
        $out['reason'] = 'active_owner_alive';
    } else {
        $out['reclaimable_under_current_code'] = $stale;
        $out['reason'] = 'owner_liveness_unknown';
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function orange_backup_runtime_diagnostic_restore_prebackup_lock(string $backupRoot, array $env): array
{
    $configured = trim((string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? ''));
    if ($configured !== '') {
        $workRoot = orange_backup_normalize_directory_path($configured);
    } else {
        $workRoot = orange_backup_runtime_diagnostic_join($backupRoot, 'restore_work');
    }
    if (!is_dir($workRoot)) {
        return [
            'exists' => false,
            'job_match_category' => 'work_root_absent',
            'age_seconds' => null,
            'liveness' => 'unknown',
            'reclaimable_under_current_code' => false,
            'reason' => 'restore_work_absent',
        ];
    }
    $lockPath = orange_backup_runtime_diagnostic_join($workRoot, '.pre_restore_backup.lock');
    $probe = orange_backup_runtime_diagnostic_lock_probe($lockPath, 'restore_pre_backup');
    $jobId = null;
    if ($probe['metadata_readable'] && is_file($lockPath)) {
        $raw = @file_get_contents($lockPath);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($meta)) {
            $jobId = trim((string) ($meta['job_id'] ?? ''));
        }
    }

    return [
        'exists' => (bool) $probe['exists'],
        'job_match_category' => $jobId !== null && $jobId !== '' ? 'job_id_present' : ($probe['exists'] ? 'job_id_absent' : 'none'),
        'age_seconds' => $probe['safe_age_seconds'],
        'liveness' => (string) $probe['liveness'],
        'reclaimable_under_current_code' => (bool) $probe['reclaimable_under_current_code'],
        'reason' => (string) $probe['reason'],
    ];
}

/**
 * @return array{category:string,human:?string}
 */
function orange_backup_runtime_diagnostic_disk(string $backupRoot): array
{
    if (isset($GLOBALS['orange_backup_runtime_diagnostic_disk_override'])
        && is_array($GLOBALS['orange_backup_runtime_diagnostic_disk_override'])) {
        return $GLOBALS['orange_backup_runtime_diagnostic_disk_override'];
    }
    if (!is_dir($backupRoot)) {
        return ['category' => 'unavailable', 'human' => null];
    }
    $free = @disk_free_space($backupRoot);
    if ($free === false || !is_numeric($free)) {
        return ['category' => 'unavailable', 'human' => null];
    }
    $free = (int) $free;
    $human = function_exists('orange_backup_admin_format_bytes')
        ? orange_backup_admin_format_bytes($free)
        : null;
    if ($free < ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_DISK_LOW_BYTES) {
        return ['category' => 'low', 'human' => $human];
    }

    return ['category' => 'sufficient', 'human' => $human];
}

/**
 * Read-only PHP CLI readiness — never executes php -r / proc_open probes.
 *
 * @return array<string, mixed>
 */
function orange_backup_runtime_diagnostic_php_binary_category(string $projectRoot): array
{
    $runtime = '';
    if (isset($GLOBALS['orange_backup_test_php_binary']) && is_string($GLOBALS['orange_backup_test_php_binary'])) {
        $runtime = trim($GLOBALS['orange_backup_test_php_binary']);
    } elseif (defined('PHP_BINARY') && is_string(PHP_BINARY)) {
        $runtime = trim(PHP_BINARY);
    }
    $kind = 'unknown';
    if ($runtime !== '') {
        if (preg_match('/php-cgi(?:\.exe)?$/i', $runtime) === 1) {
            $kind = 'CGI';
        } elseif (preg_match('/php-fpm/i', $runtime) === 1) {
            $kind = 'FPM';
        } elseif (preg_match('/php(?:\.exe)?$/i', $runtime) === 1) {
            $kind = 'CLI';
        }
    }

    $env = [];
    try {
        $env = orange_backup_load_env_array($projectRoot);
        if (isset($GLOBALS['orange_backup_test_env_override']) && is_array($GLOBALS['orange_backup_test_env_override'])) {
            $env = array_merge($env, $GLOBALS['orange_backup_test_env_override']);
        }
    } catch (Throwable) {
        $env = [];
    }
    $configured = trim((string) ($env['ORANGE_PHP_CLI'] ?? ''));
    $resolved = '';
    try {
        // Backup Center b47cbe86 contract — never throws; may return bare "php".
        $resolved = orange_backup_admin_resolve_cli_php_binary($projectRoot);
    } catch (Throwable) {
        $resolved = '';
    }
    $cliOk = $resolved !== '';
    $bareFallback = $resolved === 'php';
    $absoluteCandidate = $resolved !== '' && $resolved !== 'php' && is_file($resolved);
    $exeCategory = 'unavailable';
    if ($cliOk) {
        $exeCategory = $bareFallback ? 'bare_php_fallback' : 'cli_candidate_present';
    }

    return [
        'php_sapi_category' => (string) PHP_SAPI,
        'php_binary_category' => $kind,
        'final_backup_command_executable_category' => $exeCategory,
        'absolute_candidate_available' => $absoluteCandidate,
        'cli_resolved' => $cliOk,
        'execution_contract' => 'BACKUP_CENTER_B47CBE86',
        'bare_php_fallback_allowed' => true,
        'safe_resolve_diag' => [
            'php_binary_kind' => strtolower($kind === 'unknown' ? 'empty' : $kind),
            'orange_php_cli_configured' => $configured !== '' ? 1 : 0,
            'resolved_bare_php_fallback' => $bareFallback ? 1 : 0,
            'resolved_non_empty' => $cliOk ? 1 : 0,
            'execution_contract' => 'BACKUP_CENTER_B47CBE86',
        ],
    ];
}
function orange_backup_runtime_diagnostic_disabled_function_categories(): array
{
    $raw = (string) ini_get('disable_functions');
    $cats = [];
    foreach (explode(',', $raw) as $part) {
        $fn = strtolower(trim($part));
        if ($fn === '') {
            continue;
        }
        if (in_array($fn, ['proc_open', 'exec', 'shell_exec', 'passthru', 'system', 'popen'], true)) {
            $cats[] = $fn;
        }
    }

    return array_values(array_unique($cats));
}

/**
 * Scan provenance executions read-only for latest scope.
 *
 * @return array<string, mixed>
 */
function orange_backup_runtime_diagnostic_last_attempt(string $backupRoot, string $scope): array
{
    $empty = [
        'evidence_available' => false,
        'timestamp' => null,
        'attempt_identity' => null,
        'last_reached_stage' => null,
        'safe_failure_code' => null,
        'process_started' => 'unknown',
        'package_created' => 'unknown',
        'verify_result' => null,
        'drv_result' => null,
        'lock_state_after_failure' => null,
        'result_status' => null,
    ];
    $execDir = orange_backup_runtime_diagnostic_join(
        $backupRoot,
        '.orange_meta' . DIRECTORY_SEPARATOR . 'provenance' . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR . 'executions'
    );
    if (!is_dir($execDir) || !is_readable($execDir)) {
        return $empty;
    }
    $best = null;
    $bestMtime = -1;
    foreach (scandir($execDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
            continue;
        }
        $path = $execDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path)) {
            continue;
        }
        $data = orange_backup_admin_read_json_if_exists($path);
        if (!is_array($data)) {
            continue;
        }
        $backupScope = strtolower((string) ($data['backup_scope'] ?? $data['scope'] ?? ''));
        $trigger = strtolower((string) ($data['trigger_mode'] ?? ''));
        $isFull = $backupScope === 'full' || $backupScope === 'full_disaster'
            || str_contains($trigger, 'full') || (($data['package_type'] ?? '') === 'full');
        $isCountry = $backupScope === 'country' || $backupScope === 'country_batch'
            || str_contains($trigger, 'country') || (($data['package_type'] ?? '') === 'country');
        if ($scope === 'full' && !$isFull) {
            continue;
        }
        if ($scope === 'country' && !$isCountry) {
            continue;
        }
        $mtime = (int) (@filemtime($path) ?: 0);
        $completed = (string) ($data['completed_at_utc'] ?? $data['finished_at_utc'] ?? $data['started_at_utc'] ?? '');
        $ts = $completed !== '' ? (strtotime($completed) ?: $mtime) : $mtime;
        if ($ts >= $bestMtime) {
            $bestMtime = $ts;
            $best = $data;
            $best['__mtime'] = $mtime;
        }
    }
    if (!is_array($best)) {
        return $empty;
    }
    $status = strtolower((string) ($best['result_status'] ?? $best['package_result_status'] ?? $best['status'] ?? ''));
    $stage = strtolower((string) ($best['last_stage'] ?? $best['stage'] ?? $best['failure_stage'] ?? ''));
    $safeCode = (string) ($best['safe_failure_code'] ?? $best['failure_code'] ?? $best['error_code'] ?? '');
    if ($safeCode === '' && $status !== '' && !in_array($status, ['success', 'pass', 'ok'], true)) {
        $safeCode = 'persisted_' . preg_replace('/[^a-z0-9_]+/', '_', $status);
    }
    $processStarted = 'unknown';
    if (array_key_exists('process_started', $best)) {
        $processStarted = !empty($best['process_started']) ? 'yes' : 'no';
    } elseif ($stage !== '' && in_array($stage, ['runner', 'package', 'verify', 'drv', 'response'], true)) {
        $processStarted = 'yes';
    } elseif ($stage === 'preflight' || $stage === 'lock' || $stage === 'spawn') {
        $processStarted = $stage === 'spawn' ? 'unknown' : 'no';
    }
    $packageCreated = 'unknown';
    $pkgId = trim((string) ($best['package_id'] ?? $best['snapshot'] ?? ''));
    if ($pkgId !== '') {
        $packageCreated = 'yes';
    } elseif (in_array($status, ['failed', 'fail', 'error'], true) && in_array($stage, ['preflight', 'lock', 'spawn', 'runner'], true)) {
        $packageCreated = 'no';
    }
    if ($stage === '') {
        if ($pkgId !== '' && in_array($status, ['success', 'pass', 'ok'], true)) {
            $stage = 'response';
        } elseif ($pkgId !== '') {
            $stage = 'package';
        } elseif ($processStarted === 'yes') {
            $stage = 'runner';
        } elseif ($status !== '') {
            $stage = 'response';
        }
    }
    $allowedStages = ['preflight', 'lock', 'spawn', 'runner', 'package', 'verify', 'drv', 'response'];
    if ($stage !== '' && !in_array($stage, $allowedStages, true)) {
        $stage = 'response';
    }

    return [
        'evidence_available' => true,
        'timestamp' => (string) ($best['completed_at_utc'] ?? $best['finished_at_utc'] ?? $best['started_at_utc'] ?? null),
        'attempt_identity' => (string) ($best['execution_id'] ?? $best['id'] ?? null),
        'last_reached_stage' => $stage !== '' ? $stage : null,
        'safe_failure_code' => $safeCode !== '' ? $safeCode : null,
        'process_started' => $processStarted,
        'package_created' => $packageCreated,
        'verify_result' => isset($best['verify_result']) ? (string) $best['verify_result'] : null,
        'drv_result' => isset($best['drv_result']) ? (string) $best['drv_result'] : (isset($best['overall_result']) ? (string) $best['overall_result'] : null),
        'lock_state_after_failure' => isset($best['lock_state_after_failure']) ? (string) $best['lock_state_after_failure'] : null,
        'result_status' => $status !== '' ? $status : null,
    ];
}

/**
 * @param list<string> $blockers
 */
function orange_backup_runtime_diagnostic_classify(array $blockers, array $lastFull): string
{
    $blockers = array_values(array_unique(array_filter($blockers, static fn ($b) => is_string($b) && $b !== '')));
    if (count($blockers) > 1) {
        return 'MULTIPLE_RUNTIME_BLOCKERS';
    }
    if (count($blockers) === 1) {
        $one = $blockers[0];
        if (in_array($one, ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true)) {
            return $one;
        }

        return 'UNKNOWN_RUNTIME_BLOCKER';
    }
    // No hard blockers — map last Full failure stage if failed evidence exists.
    if (!empty($lastFull['evidence_available']) && is_string($lastFull['result_status'] ?? null)) {
        $st = strtolower((string) $lastFull['result_status']);
        if (!in_array($st, ['success', 'pass', 'ok', ''], true)) {
            $stage = (string) ($lastFull['last_reached_stage'] ?? '');
            $map = [
                'preflight' => 'LAST_FULL_FAILED_BEFORE_PROCESS_START',
                'lock' => 'LAST_FULL_FAILED_BEFORE_PROCESS_START',
                'spawn' => 'LAST_FULL_FAILED_BEFORE_PROCESS_START',
                'runner' => 'LAST_FULL_PROCESS_STARTED_RUNNER_FAILED',
                'package' => 'LAST_FULL_PACKAGE_GENERATION_FAILED',
                'verify' => 'LAST_FULL_VERIFY_FAILED',
                'drv' => 'LAST_FULL_DRV_FAILED',
                'response' => 'LAST_FULL_RESPONSE_CLASSIFICATION_FAILED',
            ];
            if (isset($map[$stage])) {
                return $map[$stage];
            }

            return 'PERSISTED_FAILURE_EVIDENCE_UNAVAILABLE';
        }
    }

    return 'READY_FOR_CONTROLLED_FULL_ATTEMPT';
}

function orange_backup_runtime_diagnostic_blocker_label_ar(string $code): string
{
    $map = [
        'FULL_LOCK_ACTIVE' => 'قفل النسخة الشاملة نشط (المالك حي).',
        'FULL_LOCK_STALE_OR_ORPHANED' => 'قفل النسخة الشاملة يبدو يتيماً/منتهياً (بدون حذف تلقائي).',
        'FULL_LOCK_STATE_UNKNOWN' => 'تعذر التحقق من حيوية قفل النسخة الشاملة.',
        'COUNTRY_LOCK_ACTIVE' => 'قفل نسخ الدول نشط.',
        'COUNTRY_LOCK_STALE_OR_ORPHANED' => 'قفل نسخ الدول يبدو يتيماً/منتهياً (بدون حذف تلقائي).',
        'BACKUP_ROOT_NOT_READY' => 'مجلد النسخ غير جاهز (غير موجود/غير مقروء).',
        'BACKUP_ROOT_NOT_WRITABLE' => 'مجلد النسخ غير قابل للكتابة لمستخدم موقع PHP.',
        'LOCK_DIRECTORY_NOT_READY' => 'مجلد الأقفال غير جاهز للكتابة.',
        'INSUFFICIENT_DISK_SPACE' => 'المساحة الحرة منخفضة أو غير كافية.',
        'PHP_CLI_UNAVAILABLE' => 'تعذر حل منفّذ PHP CLI وفق عقد مركز النسخ.',
        'PROCESS_EXECUTION_UNAVAILABLE' => 'تشغيل العمليات الفرعية غير متاح (مثل proc_open).',
        'FULL_RUNNER_UNAVAILABLE' => 'مدخل تشغيل النسخة الشاملة غير جاهز.',
        'COUNTRY_RUNNER_UNAVAILABLE' => 'مدخل تشغيل نسخ الدول غير جاهز.',
        'DATABASE_UNAVAILABLE' => 'اتصال قاعدة البيانات غير متاح للتشخيص.',
        'SCHEMA_GATE_MISMATCH' => 'بوابة المخطط لا تطابق 124.',
    ];

    return $map[$code] ?? ('عائق تشغيل مصنّف: ' . $code);
}

/**
 * @param list<string> $blockers
 * @return list<string>
 */
function orange_backup_runtime_diagnostic_safe_blocker_list_ar(array $blockers): array
{
    $out = [];
    foreach ($blockers as $code) {
        if (!is_string($code) || $code === '') {
            continue;
        }
        $out[] = orange_backup_runtime_diagnostic_blocker_label_ar($code);
    }

    return array_values(array_unique($out));
}

function orange_backup_runtime_diagnostic_owner_ui(array $report): string
{
    $cls = (string) ($report['classification'] ?? 'UNKNOWN_RUNTIME_BLOCKER');
    $root = $report['backup_root'] ?? [];
    $fullLock = $report['full_lock'] ?? [];
    $countryLock = $report['countries_lock'] ?? [];
    $proc = $report['process'] ?? [];
    $db = $report['database'] ?? [];
    $last = $report['last_full_attempt'] ?? [];
    $disk = $report['disk'] ?? [];
    $blockers = is_array($report['blockers'] ?? null) ? $report['blockers'] : [];
    $blockerLines = orange_backup_runtime_diagnostic_safe_blocker_list_ar($blockers);

    $yn = static function ($v): string {
        if ($v === true || $v === 'yes') {
            return 'نعم';
        }
        if ($v === false || $v === 'no') {
            return 'لا';
        }

        return 'غير معروف';
    };
    $lockLabel = static function (array $lock): string {
        if (empty($lock['exists'])) {
            return 'غير موجود';
        }
        $live = (string) ($lock['liveness'] ?? 'unknown');
        if ($live === 'alive') {
            return 'نشط (المالك حي)';
        }
        if (!empty($lock['reclaimable_under_current_code'])) {
            return 'موجود — قد يكون يتيماً/منتهياً (بدون حذف تلقائي)';
        }

        return 'موجود — حالة غير مؤكدة';
    };
    $engineReady = !empty($proc['cli_resolved']) && !empty($proc['proc_open_available'])
        && !empty(($report['runner']['full']['command_constructable'] ?? false));
    $recs = [
        'READY_FOR_CONTROLLED_FULL_ATTEMPT' => 'البيئة تبدو جاهزة لمحاولة Full مضبوطة — فقط بعد موافقة المالك الصريحة.',
        'FULL_LOCK_ACTIVE' => 'قفل النسخة الشاملة نشط. لا تحذف القفل يدوياً دون تعليمات منفصلة.',
        'FULL_LOCK_STALE_OR_ORPHANED' => 'قفل النسخة الشاملة يبدو يتيماً/منتهياً. لا تُحذف الأقفال تلقائياً — يلزم قرار مالك منفصل.',
        'FULL_LOCK_STATE_UNKNOWN' => 'تعذر التحقق من حيوية قفل النسخة الشاملة. لا تُنفَّذ Full قبل توضيح الحالة.',
        'COUNTRY_LOCK_ACTIVE' => 'قفل نسخ الدول نشط.',
        'COUNTRY_LOCK_STALE_OR_ORPHANED' => 'قفل نسخ الدول يبدو يتيماً/منتهياً. لا حذف تلقائي.',
        'BACKUP_ROOT_NOT_READY' => 'مجلد النسخ غير جاهز (غير موجود/غير مقروء). راجع إعدادات الخادم مع الدعم — دون تشغيل Full.',
        'BACKUP_ROOT_NOT_WRITABLE' => 'مجلد النسخ غير قابل للكتابة لمستخدم موقع PHP. هذا سبب شائع لفشل التشغيل اليدوي.',
        'LOCK_DIRECTORY_NOT_READY' => 'مجلد الأقفال غير جاهز.',
        'INSUFFICIENT_DISK_SPACE' => 'المساحة الحرة منخفضة أو غير كافية.',
        'PHP_CLI_UNAVAILABLE' => 'تعذر حل منفّذ PHP CLI المعتمد لمحرك النسخ.',
        'PROCESS_EXECUTION_UNAVAILABLE' => 'تشغيل العمليات الفرعية غير متاح (مثل proc_open).',
        'FULL_RUNNER_UNAVAILABLE' => 'مدخل تشغيل النسخة الشاملة غير جاهز.',
        'COUNTRY_RUNNER_UNAVAILABLE' => 'مدخل تشغيل نسخ الدول غير جاهز.',
        'DATABASE_UNAVAILABLE' => 'اتصال قاعدة البيانات غير متاح للتشخيص.',
        'SCHEMA_GATE_MISMATCH' => 'بوابة المخطط لا تطابق 124.',
        'LAST_FULL_FAILED_BEFORE_PROCESS_START' => 'آخر Full فشل قبل بدء التنفيذ الفعلي.',
        'LAST_FULL_PROCESS_STARTED_RUNNER_FAILED' => 'آخر Full بدأ ثم فشل المحرك.',
        'LAST_FULL_PACKAGE_GENERATION_FAILED' => 'آخر Full فشل أثناء إنشاء الحزمة.',
        'LAST_FULL_VERIFY_FAILED' => 'آخر Full فشل في التحقق.',
        'LAST_FULL_DRV_FAILED' => 'آخر Full فشل في DRV.',
        'LAST_FULL_RESPONSE_CLASSIFICATION_FAILED' => 'آخر Full فشل عند تصنيف الاستجابة.',
        'PERSISTED_FAILURE_EVIDENCE_UNAVAILABLE' => 'لا توجد أدلة محفوظة كافية لآخر محاولة — لا تُخمَّن الأسباب.',
        'MULTIPLE_RUNTIME_BLOCKERS' => 'توجد عوائق تشغيل مثبتة — راجع القائمة أدناه قبل أي Full.',
        'UNKNOWN_RUNTIME_BLOCKER' => 'عائق تشغيل غير مصنّف — أوقف أي محاولة Full.',
    ];

    $lines = [
        'تشخيص محرك النسخ الاحتياطي',
        '—',
        'الحالة العامة: ' . $cls,
        'جاهزية مجلد النسخ: ' . (
            !empty($root['root_configured']) && !empty($root['root_exists']) && !empty($root['root_readable'])
                ? (!empty($root['root_writable']) ? 'جاهز وقابل للكتابة' : 'موجود لكن غير قابل للكتابة')
                : 'غير جاهز'
        ),
        'المساحة الحرة: ' . (string) ($disk['category'] ?? 'unavailable')
            . (!empty($disk['human']) ? (' (' . $disk['human'] . ')') : ''),
        'حالة قفل النسخة الشاملة: ' . $lockLabel(is_array($fullLock) ? $fullLock : []),
        'حالة قفل نسخ الدول: ' . $lockLabel(is_array($countryLock) ? $countryLock : []),
        'قابلية تشغيل المحرك: ' . ($engineReady ? 'جاهز ظاهرياً' : 'غير جاهز'),
        'جاهزية قاعدة البيانات: ' . (
            !empty($db['database_connection_available'])
                ? (!empty($db['schema_gate_match']) ? 'متصلة — المخطط 124' : 'متصلة — عدم تطابق مخطط')
                : 'غير متاحة'
        ),
        'آخر مرحلة وصلت إليها محاولة Full: ' . (
            !empty($last['evidence_available'])
                ? (string) ($last['last_reached_stage'] ?? 'غير محددة')
                : 'غير متوفرة'
        ),
        'رمز الفشل الآمن: ' . (
            !empty($last['safe_failure_code'])
                ? (string) $last['safe_failure_code']
                : (!empty($last['evidence_available']) ? '—' : 'غير متوفر')
        ),
        'هل بدأ تنفيذ فعلي؟ ' . $yn($last['process_started'] ?? 'unknown'),
        'هل أُنشئت حزمة؟ ' . $yn($last['package_created'] ?? 'unknown'),
    ];
    if ($blockerLines !== []) {
        $lines[] = 'العوائق المثبتة:';
        foreach ($blockerLines as $bl) {
            $lines[] = '- ' . $bl;
        }
    } else {
        $lines[] = 'العوائق المثبتة: لا يوجد';
    }
    $lines[] = 'التوصية التالية: ' . ($recs[$cls] ?? $recs['UNKNOWN_RUNTIME_BLOCKER']);
    $lines[] = '—';
    $lines[] = 'هذا التشخيص للقراءة فقط. لم يُشغَّل Full أو Countries ولم يُعدَّل أي قفل.';

    return implode("\n", $lines);
}
function orange_backup_runtime_diagnostic_run(string $projectRoot, ?PDO $pdo = null): array
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $mutationCounters = [
        'DIAGNOSTIC_BACKUP_START_COUNT' => 0,
        'DIAGNOSTIC_COUNTRY_START_COUNT' => 0,
        'DIAGNOSTIC_RESTORE_MUTATION_COUNT' => 0,
        'DIAGNOSTIC_LOCK_DELETE_COUNT' => 0,
        'DIAGNOSTIC_PROCESS_KILL_COUNT' => 0,
        'DIAGNOSTIC_PACKAGE_WRITE_COUNT' => 0,
    ];

    $env = orange_backup_load_env_array($projectRoot);
    if (isset($GLOBALS['orange_backup_test_env_override']) && is_array($GLOBALS['orange_backup_test_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_backup_test_env_override']);
    }

    $rootConfigured = orange_backup_root_configured($env);
    $rootExists = false;
    $rootReadable = false;
    $rootWritable = 'unknown';
    $backupRoot = '';
    $rootError = null;
    try {
        if (isset($GLOBALS['orange_backup_runtime_diagnostic_root_override'])
            && is_string($GLOBALS['orange_backup_runtime_diagnostic_root_override'])
            && $GLOBALS['orange_backup_runtime_diagnostic_root_override'] !== '') {
            $backupRoot = $GLOBALS['orange_backup_runtime_diagnostic_root_override'];
            $rootConfigured = true;
        } else {
            $view = orange_backup_admin_resolve_root_for_view($projectRoot);
            $backupRoot = (string) ($view['backup_root'] ?? '');
            $rootWritable = !empty($view['writable']) ? 'yes' : 'no';
            $rootExists = !empty($view['exists']);
            $rootReadable = !empty($view['readable']);
        }
    } catch (Throwable $e) {
        $rootError = 'root_resolve_failed';
        $candidate = orange_backup_backup_root_candidate($env, $projectRoot);
        if ($candidate !== '' && is_dir($candidate)) {
            $backupRoot = realpath($candidate) ?: $candidate;
        }
    }
    if ($backupRoot !== '') {
        $rootExists = is_dir($backupRoot);
        $rootReadable = $rootExists && is_readable($backupRoot);
        if ($rootWritable === 'unknown') {
            $rootWritable = ($rootExists && is_writable($backupRoot)) ? 'yes' : ($rootExists ? 'no' : 'unknown');
        }
    }

    $snap = $backupRoot !== '' ? orange_backup_runtime_diagnostic_dir_flags(orange_backup_runtime_diagnostic_join($backupRoot, 'snapshots')) : ['exists' => false, 'readable' => false, 'writable' => false];
    $locksDir = $backupRoot !== '' ? orange_backup_runtime_diagnostic_dir_flags(orange_backup_runtime_diagnostic_join($backupRoot, 'locks')) : ['exists' => false, 'readable' => false, 'writable' => false];
    $reports = $backupRoot !== '' ? orange_backup_runtime_diagnostic_dir_flags(orange_backup_runtime_diagnostic_join($backupRoot, 'reports')) : ['exists' => false, 'readable' => false, 'writable' => false];
    $temp = $backupRoot !== '' ? orange_backup_runtime_diagnostic_dir_flags(orange_backup_runtime_diagnostic_join($backupRoot, 'temp')) : ['exists' => false, 'readable' => false, 'writable' => false];
    // Temporary workspaces also live under snapshots/._work_* — readiness = snapshots writable when present.
    $tempReady = !empty($temp['exists']) ? (!empty($temp['writable'])) : (!empty($snap['writable']));

    $disk = $backupRoot !== '' ? orange_backup_runtime_diagnostic_disk($backupRoot) : ['category' => 'unavailable', 'human' => null];

    $fullLockPath = $backupRoot !== ''
        ? orange_backup_runtime_diagnostic_join($backupRoot, ORANGE_BACKUP_LOCK_RELATIVE)
        : '';
    $countryLockPath = $backupRoot !== ''
        ? orange_backup_runtime_diagnostic_join($backupRoot, ORANGE_CRP_BATCH_LOCK_RELATIVE)
        : '';
    $fullLock = $fullLockPath !== ''
        ? orange_backup_runtime_diagnostic_lock_probe($fullLockPath, 'full_backup')
        : ['exists' => false, 'metadata_readable' => false, 'operation_category' => 'full_backup', 'acquired_at' => null, 'safe_age_seconds' => null, 'owner_pid_present' => false, 'liveness' => 'unknown', 'reclaimable_under_current_code' => false, 'reason' => 'root_unavailable', 'stale_by_absolute_age' => false];
    $countryLock = $countryLockPath !== ''
        ? orange_backup_runtime_diagnostic_lock_probe($countryLockPath, 'countries_batch')
        : ['exists' => false, 'metadata_readable' => false, 'operation_category' => 'countries_batch', 'acquired_at' => null, 'safe_age_seconds' => null, 'owner_pid_present' => false, 'liveness' => 'unknown', 'reclaimable_under_current_code' => false, 'reason' => 'root_unavailable', 'stale_by_absolute_age' => false];
    $restoreLock = $backupRoot !== ''
        ? orange_backup_runtime_diagnostic_restore_prebackup_lock($backupRoot, $env)
        : ['exists' => false, 'job_match_category' => 'root_unavailable', 'age_seconds' => null, 'liveness' => 'unknown', 'reclaimable_under_current_code' => false, 'reason' => 'root_unavailable'];

    $phpInfo = orange_backup_runtime_diagnostic_php_binary_category($projectRoot);
    $procOpen = orange_backup_can_proc_open();
    $shellExec = orange_backup_can_shell_exec();
    $execAvail = orange_backup_function_usable('exec');
    $disabledCats = orange_backup_runtime_diagnostic_disabled_function_categories();

    $fullScript = $projectRoot . DIRECTORY_SEPARATOR . ORANGE_BACKUP_ADMIN_RUN_FULL_CLI_SCRIPT;
    $countryScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'export_all_recoverable_countries.php';
    $fullReadable = is_file($fullScript) && is_readable($fullScript);
    $countryReadable = is_file($countryScript) && is_readable($countryScript);
    $fullCallable = function_exists('orange_backup_admin_run_full_for_api') && function_exists('orange_backup_run_full');
    $countryCallable = function_exists('orange_backup_admin_run_country_batch');
    // Backup Center contract (b47cbe86): script readable + CLI token resolved (absolute or bare "php").
    // Never require Restore absolute-only policy for Backup Center readiness.
    $fullConstructable = $fullReadable && !empty($phpInfo['cli_resolved']);
    $countryConstructable = $countryReadable && !empty($phpInfo['cli_resolved']);

    $dbOk = false;
    $schemaMatch = false;
    $schemaObserved = null;
    $pdoBackend = 'unavailable';
    if ($pdo instanceof PDO) {
        try {
            $pdo->query('SELECT 1');
            $dbOk = true;
            $pdoBackend = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
                $schemaObserved = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
                $schemaMatch = $schemaObserved === ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_EXPECTED_SCHEMA;
            }
            // Optional live checkpoint read (SELECT only).
            try {
                if (function_exists('orange_table_exists') && orange_table_exists($pdo, 'orange_schema_meta')) {
                    $st = $pdo->query('SELECT code_version FROM orange_schema_meta LIMIT 1');
                    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
                    if (is_array($row) && isset($row['code_version'])) {
                        $schemaObserved = (int) $row['code_version'];
                        $schemaMatch = $schemaObserved === ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_EXPECTED_SCHEMA;
                    }
                }
            } catch (Throwable) {
                // keep code revision observation
            }
        } catch (Throwable) {
            $dbOk = false;
        }
    }

    $lastFull = $backupRoot !== ''
        ? orange_backup_runtime_diagnostic_last_attempt($backupRoot, 'full')
        : [
            'evidence_available' => false,
            'timestamp' => null,
            'attempt_identity' => null,
            'last_reached_stage' => null,
            'safe_failure_code' => null,
            'process_started' => 'unknown',
            'package_created' => 'unknown',
            'verify_result' => null,
            'drv_result' => null,
            'lock_state_after_failure' => null,
            'result_status' => null,
        ];
    $lastCountry = $backupRoot !== ''
        ? orange_backup_runtime_diagnostic_last_attempt($backupRoot, 'country')
        : $lastFull;

    $packages = [
        'full_package_count' => 0,
        'country_package_count' => 0,
        'latest_package_time' => null,
        'healthy_or_recoverable_count' => 0,
    ];
    if ($backupRoot !== '' && $rootReadable) {
        try {
            $inv = orange_backup_admin_package_inventory_counts($backupRoot, null);
            $packages['full_package_count'] = (int) ($inv['full_snapshots_total'] ?? 0);
            $packages['country_package_count'] = (int) ($inv['stored_country_packages_total'] ?? 0);
            $fullList = orange_backup_admin_list_full_snapshots($backupRoot, 1);
            if (isset($fullList[0]['generated_at'])) {
                $packages['latest_package_time'] = (string) $fullList[0]['generated_at'];
            }
            $healthy = 0;
            foreach (orange_backup_admin_list_full_snapshots($backupRoot, 20) as $pkg) {
                $st = strtolower((string) ($pkg['package_status'] ?? $pkg['status'] ?? ''));
                if (in_array($st, ['healthy', 'success', 'pass', 'ok'], true) || !empty($pkg['recoverable'])) {
                    $healthy++;
                }
            }
            $packages['healthy_or_recoverable_count'] = $healthy;
        } catch (Throwable) {
            // leave zeros — never invent
        }
    }

    $blockers = [];
    if (!$dbOk) {
        $blockers[] = 'DATABASE_UNAVAILABLE';
    } elseif (!$schemaMatch) {
        $blockers[] = 'SCHEMA_GATE_MISMATCH';
    }
    if (!$rootConfigured || !$rootExists || !$rootReadable || $rootError !== null) {
        $blockers[] = 'BACKUP_ROOT_NOT_READY';
    } elseif ($rootWritable === 'no') {
        $blockers[] = 'BACKUP_ROOT_NOT_WRITABLE';
    }
    if ($rootExists && (empty($locksDir['exists']) || empty($locksDir['writable']))) {
        // locks dir may be absent until first run — only block if root writable but locks not creatable state is unknown
        if (!empty($locksDir['exists']) && empty($locksDir['writable'])) {
            $blockers[] = 'LOCK_DIRECTORY_NOT_READY';
        }
    }
    if (($disk['category'] ?? '') === 'low') {
        $blockers[] = 'INSUFFICIENT_DISK_SPACE';
    }
    if (!$procOpen && !$execAvail && !$shellExec) {
        $blockers[] = 'PROCESS_EXECUTION_UNAVAILABLE';
    } elseif (!$procOpen) {
        // Backup Center path uses proc_open via orange_backup_run_command_capture primarily
        $blockers[] = 'PROCESS_EXECUTION_UNAVAILABLE';
    }
    if (empty($phpInfo['cli_resolved'])) {
        $blockers[] = 'PHP_CLI_UNAVAILABLE';
    }
    if (!$fullReadable || !$fullCallable || !$fullConstructable) {
        $blockers[] = 'FULL_RUNNER_UNAVAILABLE';
    }
    if (!$countryReadable || !$countryCallable || !$countryConstructable) {
        $blockers[] = 'COUNTRY_RUNNER_UNAVAILABLE';
    }
    if (!empty($fullLock['exists'])) {
        if (($fullLock['liveness'] ?? '') === 'alive') {
            $blockers[] = 'FULL_LOCK_ACTIVE';
        } elseif (!empty($fullLock['reclaimable_under_current_code'])) {
            $blockers[] = 'FULL_LOCK_STALE_OR_ORPHANED';
        } else {
            $blockers[] = 'FULL_LOCK_STATE_UNKNOWN';
        }
    }
    if (!empty($countryLock['exists'])) {
        if (($countryLock['liveness'] ?? '') === 'alive') {
            $blockers[] = 'COUNTRY_LOCK_ACTIVE';
        } elseif (!empty($countryLock['reclaimable_under_current_code'])) {
            $blockers[] = 'COUNTRY_LOCK_STALE_OR_ORPHANED';
        }
    }

    $classification = orange_backup_runtime_diagnostic_classify($blockers, $lastFull);
    if (!in_array($classification, ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true)) {
        $classification = 'UNKNOWN_RUNTIME_BLOCKER';
    }

    $report = [
        'success' => true,
        'diagnostic_schema_version' => ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_SCHEMA_VERSION,
        'classification' => $classification,
        'blockers' => array_values(array_unique($blockers)),
        'engine' => [
            'diagnostic_schema_version' => ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_SCHEMA_VERSION,
            'execution_mode' => 'BACKUP_CENTER_SYNCHRONOUS_CLI_CAPTURE',
            'full_endpoint' => 'admin/api/backup/run-full.php',
            'full_helper' => 'orange_backup_admin_run_full_for_api',
            'countries_endpoint' => 'admin/api/backup/run-countries.php',
            'countries_helper' => 'orange_backup_admin_run_country_batch',
            'diagnostic_endpoint' => 'admin/api/backup/runtime-diagnostic.php',
            'diagnostic_helper' => 'orange_backup_runtime_diagnostic_run',
        ],
        'backup_root' => [
            'root_configured' => $rootConfigured,
            'root_exists' => $rootExists,
            'root_readable' => $rootReadable,
            'root_writable' => $rootWritable,
            'snapshots_directory_ready' => !empty($snap['exists']) && !empty($snap['writable']),
            'locks_directory_ready' => !empty($locksDir['exists']) ? (!empty($locksDir['writable'])) : ($rootWritable === 'yes'),
            'reports_directory_ready' => !empty($reports['exists']) ? (!empty($reports['readable'])) : null,
            'temporary_directory_ready' => $tempReady,
            'resolve_error_category' => $rootError,
        ],
        'disk' => $disk,
        'full_lock' => $fullLock,
        'countries_lock' => $countryLock,
        'restore_pre_backup_lock' => $restoreLock,
        'process' => [
            'php_sapi_category' => $phpInfo['php_sapi_category'],
            'php_binary_category' => $phpInfo['php_binary_category'],
            'final_backup_command_executable_category' => $phpInfo['final_backup_command_executable_category'],
            'absolute_candidate_available' => (bool) $phpInfo['absolute_candidate_available'],
            'cli_resolved' => (bool) $phpInfo['cli_resolved'],
            'execution_contract' => (string) ($phpInfo['execution_contract'] ?? 'BACKUP_CENTER_B47CBE86'),
            'bare_php_fallback_allowed' => (bool) ($phpInfo['bare_php_fallback_allowed'] ?? true),
            'safe_resolve_diag' => is_array($phpInfo['safe_resolve_diag'] ?? null) ? $phpInfo['safe_resolve_diag'] : [],
            'runner_script_readable' => $fullReadable,
            'proc_open_available' => $procOpen,
            'exec_available' => $execAvail,
            'shell_exec_available' => $shellExec,
            'disabled_function_categories' => $disabledCats,
            'powershell_required_for_current_path' => false,
        ],
        'runner' => [
            'full' => [
                'entrypoint_readable' => $fullReadable,
                'service_callable' => $fullCallable,
                'command_constructable' => $fullConstructable,
                'executed' => false,
            ],
            'countries' => [
                'entrypoint_readable' => $countryReadable,
                'service_callable' => $countryCallable,
                'command_constructable' => $countryConstructable,
                'executed' => false,
            ],
        ],
        'database' => [
            'database_connection_available' => $dbOk,
            'schema_gate_expected' => ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_EXPECTED_SCHEMA,
            'schema_gate_observed' => $schemaObserved,
            'schema_gate_match' => $schemaMatch,
            'pdo_backend_category' => $pdoBackend,
        ],
        'last_full_attempt' => $lastFull,
        'last_countries_attempt' => $lastCountry,
        'packages' => $packages,
        'mutation_counters' => $mutationCounters,
        'generated_at_utc' => gmdate('c'),
    ];
    $report['owner_blocker_list_ar'] = orange_backup_runtime_diagnostic_safe_blocker_list_ar($report['blockers']);
    $report['owner_report_ar'] = orange_backup_runtime_diagnostic_owner_ui($report);

    // Hard redaction sweep — never leave absolute paths / secrets in owner text.
    $owner = $report['owner_report_ar'];
    $owner = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[محجوب]', $owner) ?? $owner;
    $owner = preg_replace('#/(?:var|home|inetpub|httpdocs)[^\s]*#', '[محجوب]', $owner) ?? $owner;
    $report['owner_report_ar'] = $owner;

    return $report;
}
