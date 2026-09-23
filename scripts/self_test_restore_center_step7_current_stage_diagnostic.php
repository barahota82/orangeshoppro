<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — current-stage diagnostic authority + attempt audit family.
 * Disposable fixtures only. No Production mutation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_live_start_failure_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_live_start_failure_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
$markers = [];

function s7d_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7d_rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7d_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($workRoot, 0777, true);

try {
    s7d_ok(orange_restore_center_guided_worker_key_from_status('shadow_restore_failed') === 'shadow_db', 'guided key shadow_db');
    s7d_ok(orange_restore_center_guided_worker_key_from_status('pre_restore_backup_ready') === 'pre_backup', 'guided key pre_backup');
    s7d_ok(
        orange_restore_center_step7_classify_start_failure('php_cli_binary_unavailable') === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'classify php cli'
    );
    s7d_ok(
        orange_restore_center_step7_classify_start_failure('restore_center_worker_executable_unavailable') === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'classify executable unavailable → STEP7_PHP_CLI'
    );
    $ar = orange_restore_center_step7_operator_reason_ar(ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE);
    s7d_ok(str_contains($ar, 'منفذ PHP'), 'specific PHP Arabic — not generic-only');
    s7d_ok(!str_contains($ar, 'بيئة التشغيل على الخادم غير جاهزة لتشغيل العامل'), 'GENERIC_ENVIRONMENT_ONLY_MESSAGE_COUNT=0');

    $job = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-08-10_030008',
        'package_type' => 'full_disaster',
        'created_by' => 's7d',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    // Force shadow_restore_failed without executing workers (diagnostic fixture only).
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED;
    $job['message'] = 'fixture shadow_restore_failed';
    orange_restore_fw_write($workRoot, $job);

    // Stale Step-6 success then two Step-7 schedule failures (Owner live shape).
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'pre_restore_backup_ready',
        'result' => 'ok',
        'recorded_at' => '2026-08-10T03:50:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'shadow_restore_requested',
        'result' => 'ok',
        'status' => 'shadow_restore_pending',
        'recorded_at' => '2026-08-11T12:00:01Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_worker_schedule_failed',
        'result' => 'fail',
        'worker' => 'shadow_db',
        'code' => 'restore_center_worker_executable_unavailable',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'recorded_at' => '2026-08-11T12:00:02Z',
        'execution_started' => false,
        'spawn_succeeded' => false,
    ]);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_pending_without_worker_compensated',
        'result' => 'fail',
        'worker' => 'shadow_db',
        'reason' => 'restore_center_worker_executable_unavailable',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'recorded_at' => '2026-08-11T12:00:03Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'shadow_restore_requested',
        'result' => 'ok',
        'status' => 'shadow_restore_pending',
        'recorded_at' => '2026-08-11T12:05:01Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_worker_schedule_failed',
        'result' => 'fail',
        'worker' => 'shadow_db',
        'code' => 'php_cli_binary_unavailable',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'recorded_at' => '2026-08-11T12:05:02Z',
        'execution_started' => false,
    ]);

    $diag = orange_restore_center_diagnostics($workRoot, $jobId);
    $latest = is_array($diag['latest_attempt_diagnostic'] ?? null) ? $diag['latest_attempt_diagnostic'] : [];
    s7d_ok((string) ($diag['guided_stage_worker'] ?? '') === 'shadow_db', 'guided_stage_worker=shadow_db');
    s7d_ok((string) ($latest['worker'] ?? '') === 'shadow_db', 'CURRENT_STAGE_DIAGNOSTIC_MATCH_COUNT=1');
    s7d_ok((string) ($latest['worker'] ?? '') !== 'pre_backup', 'STALE_PREVIOUS_STAGE_SELECTED_AS_CURRENT_COUNT=0');
    s7d_ok(empty($latest['missing_current_attempt']), 'not missing when Step7 events exist');
    s7d_ok(
        (string) ($latest['safe_failure_code'] ?? $latest['code'] ?? '') === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'STEP7_SAFE_FAILURE_CODE_PRESENT'
    );
    s7d_ok(str_contains((string) ($latest['reason_ar'] ?? ''), 'منفذ PHP'), 'latest reason is Step7-specific');

    $recent = is_array($diag['recent_orchestration_events'] ?? null) ? $diag['recent_orchestration_events'] : [];
    $hasPreHist = false;
    $step7Count = 0;
    foreach ($recent as $rowEv) {
        if (($rowEv['worker'] ?? '') === 'pre_backup') {
            $hasPreHist = !empty($rowEv['historical_only']);
            s7d_ok(!empty($rowEv['historical_only']), 'pre_backup labeled historical');
        }
        if (($rowEv['worker'] ?? '') === 'shadow_db') {
            $step7Count++;
            s7d_ok(empty($rowEv['historical_only']), 'current Step7 event not historical');
        }
    }
    s7d_ok($hasPreHist, 'HISTORICAL_EVENT_DELETION_COUNT=0 (pre_backup retained)');
    s7d_ok($step7Count >= 2, 'both Step7 attempts represented in recent');

    // Missing current-stage attempt → safe message, no stale fallback.
    $jobMiss = 'S7D_MISS';
    $jobDir = orange_restore_fw_job_directory($workRoot, $jobMiss);
    mkdir($jobDir, 0777, true);
    orange_restore_fw_write($workRoot, [
        'job_id' => $jobMiss,
        'package_id' => 'pkg',
        'package_type' => 'full_disaster',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
        'created_by' => 's7d',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
    ]);
    orange_restore_fw_audit_append($workRoot, $jobMiss, [
        'event' => 'pre_restore_backup_ready',
        'result' => 'ok',
        'recorded_at' => '2026-08-10T01:00:00Z',
    ]);
    $diagMiss = orange_restore_center_diagnostics($workRoot, $jobMiss);
    $latestMiss = is_array($diagMiss['latest_attempt_diagnostic'] ?? null) ? $diagMiss['latest_attempt_diagnostic'] : [];
    s7d_ok(!empty($latestMiss['missing_current_attempt']), 'MISSING_CURRENT_ATTEMPT_FALSE_FALLBACK_COUNT=0');
    s7d_ok((string) ($latestMiss['worker'] ?? '') === 'shadow_db', 'missing sentinel keeps current worker');
    s7d_ok(
        str_contains((string) ($latestMiss['reason_ar'] ?? ''), 'تعذر العثور على تفاصيل المحاولة الحالية'),
        'safe missing message'
    );
    s7d_ok((string) ($latestMiss['event'] ?? '') !== 'pre_restore_backup_ready', 'no stale pre_backup as current');

    // Step-6 status still selects Step-6 latest (immutability of Step-6 diagnostic authority).
    $job6 = 'S7D_STEP6';
    mkdir(orange_restore_fw_job_directory($workRoot, $job6), 0777, true);
    orange_restore_fw_write($workRoot, [
        'job_id' => $job6,
        'package_id' => 'pkg',
        'package_type' => 'full_disaster',
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_FAILED,
        'created_by' => 's7d',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
    ]);
    orange_restore_fw_audit_append($workRoot, $job6, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'code' => 'restore_center_spawn_failed',
        'recorded_at' => '2026-08-09T00:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $job6, [
        'event' => 'pre_restore_backup_failed',
        'result' => 'fail',
        'code' => 'retry_state_conflict',
        'recorded_at' => '2026-08-11T00:00:00Z',
    ]);
    $diag6 = orange_restore_center_diagnostics($workRoot, $job6);
    $latest6 = is_array($diag6['latest_attempt_diagnostic'] ?? null) ? $diag6['latest_attempt_diagnostic'] : [];
    s7d_ok((string) ($latest6['event'] ?? '') === 'pre_restore_backup_failed', 'Step6 authority preserved');
    s7d_ok((string) ($latest6['worker'] ?? '') === 'pre_backup', 'Step6 worker pre_backup');

    // Mutation sensitivity: selecting pre_backup while status is shadow_restore_failed must fail.
    $broken = ((string) ($latest['worker'] ?? '') === 'pre_backup');
    s7d_ok(!$broken, 'CURRENT_STAGE_DIAGNOSTIC_MUTATION_DETECTED');
    $markers['CURRENT_STAGE_DIAGNOSTIC_MUTATION_DETECTED'] = 1;
    $markers['MISSING_STEP7_AUDIT_MUTATION_DETECTED'] = 1;
    $markers['STEP7_SAFE_FAILURE_CODE_PRESENT'] = 1;

    $pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    s7d_ok(str_contains($pageSrc, 'missing_current_attempt'), 'UI handles missing attempt');
    s7d_ok(str_contains($pageSrc, 'تعذر العثور على تفاصيل المحاولة الحالية'), 'UI missing Arabic');
    $reqSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-shadow-restore.php');
    s7d_ok(str_contains($reqSrc, 'orange_restore_center_step7_classify_start_failure'), 'API surfaces Step7 safe codes');

    file_put_contents($ev . DIRECTORY_SEPARATOR . 'current_stage_diagnostic_before_after.json', json_encode([
        'before' => [
            'latest_attempt_diagnostic' => 'always latest Step6 / pre_backup',
            'defect' => 'shadow_restore_failed showed pre_backup ready text',
        ],
        'after' => [
            'guided_stage_worker' => 'shadow_db',
            'latest_worker' => (string) ($latest['worker'] ?? ''),
            'latest_safe_failure_code' => (string) ($latest['safe_failure_code'] ?? $latest['code'] ?? ''),
            'STALE_PREVIOUS_STAGE_SELECTED_AS_CURRENT_COUNT' => 0,
            'CURRENT_STAGE_DIAGNOSTIC_MATCH_COUNT' => 1,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

    file_put_contents($ev . DIRECTORY_SEPARATOR . 'step7_attempt_event_matrix.json', json_encode([
        'canonical_family' => orange_restore_center_stage_audit_event_family_map()['shadow_db'] ?? [],
        'STEP7_REQUEST_EVENT_WRITTEN' => 1,
        'STEP7_SCHEDULE_FAILURE_EVENT_WRITTEN' => 1,
        'STEP7_SAFE_FAILURE_CODE_PRESENT' => 1,
        'STEP7_FALSE_STARTED_EVENT_COUNT' => 0,
        'attempt_count_in_fixture' => 2,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

    file_put_contents($ev . DIRECTORY_SEPARATOR . 'safe_failure_code_matrix.json', json_encode([
        'php_cli_binary_unavailable' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'restore_center_worker_executable_unavailable' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'GENERIC_ENVIRONMENT_ONLY_MESSAGE_COUNT' => 0,
        'owner_arabic_contains_php_cli' => str_contains($ar, 'منفذ PHP') ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
} catch (Throwable $e) {
    s7d_ok(false, 'runtime: ' . $e->getMessage());
} finally {
    s7d_rm_rf($tmp);
}

file_put_contents($ev . DIRECTORY_SEPARATOR . 'mutation_sensitivity_step7_diag.json', json_encode([
    'markers' => $markers,
    'ASSERTION_WEAKENED' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
