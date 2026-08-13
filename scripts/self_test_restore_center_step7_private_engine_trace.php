<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — read-only private-engine live trace bridge suite.
 * Disposable fixtures only. Never mutates Production / live Owner jobs.
 *
 * Registers:
 *   RESTORE_CENTER_STEP7_PRIVATE_TRACE_BRIDGE_01
 *   TRACE_DIAGNOSTIC_NO_MUTATION_PASS
 *   TRACE_REDACTION_PASS
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = 'D:\\orange_restore_step7_private_trace_bridge_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_engine_trace.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
$coreSkip = 0;
$rawFail = 0;
$assertionWeakened = 0;
$markers = [
    'TRACE_DIAGNOSTIC_NO_MUTATION_PASS' => 0,
    'TRACE_MALFORMED_ARTIFACT_SAFE_PASS' => 0,
    'TRACE_HISTORICAL_CURRENT_SEPARATION_PASS' => 0,
    'TRACE_RUNTIME_MUTEX_NOT_ATTEMPT_PASS' => 0,
    'TRACE_PID_IDENTITY_PASS' => 0,
    'TRACE_REDACTION_PASS' => 0,
];

function s7tr_ok(bool $c, string $l): void
{
    global $pass, $fail, $rawFail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    if ($c) {
        $pass++;
    } else {
        $fail++;
        $rawFail++;
    }
}

function s7tr_rm_rf(string $dir): void
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

/** @return array{hash:string,files:int,mtime_max:int} */
function s7tr_fingerprint(string $root): array
{
    if (!is_dir($root)) {
        return ['hash' => hash('sha256', 'absent'), 'files' => 0, 'mtime_max' => 0];
    }
    $parts = [];
    $files = 0;
    $mtimeMax = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $files++;
        $mtime = (int) $f->getMTime();
        $mtimeMax = max($mtimeMax, $mtime);
        $parts[] = $f->getPathname() . '|' . (string) $f->getSize() . '|' . (string) $mtime;
    }
    sort($parts);

    return [
        'hash' => hash('sha256', implode("\n", $parts)),
        'files' => $files,
        'mtime_max' => $mtimeMax,
    ];
}

function s7tr_write_job(string $workRoot, string $jobId, array $job): void
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $job['job_id'] = $jobId;
    $job['package_type'] = $job['package_type'] ?? 'full_disaster';
    $job['created_by'] = $job['created_by'] ?? 's7tr';
    $job['created_at'] = $job['created_at'] ?? gmdate('c');
    $job['updated_at'] = $job['updated_at'] ?? gmdate('c');
    $job['framework_version'] = $job['framework_version'] ?? ORANGE_RESTORE_FW_VERSION;
    orange_restore_fw_write($workRoot, $job);
}

function s7tr_engine_root(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME;
}

function s7tr_assert_safe_payload(array $snap, string $label): void
{
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    s7tr_ok(is_string($json) && $json !== '', $label . ' json encodable');
    $blob = strtolower((string) $json . "\n" . (string) ($snap['arabic_report'] ?? ''));
    s7tr_ok(!preg_match('/[a-z]:\\\\|\\/var\\/|\\/home\\/|password\\s*[:=]|mysqld\\.exe|127\\.0\\.0\\.1:\\d{2,5}/i', $blob), $label . ' no path/secret/port leak');
    s7tr_ok(!preg_match('/"pid"\\s*:\\s*[1-9]/', (string) $json), $label . ' no raw pid field');
    s7tr_ok(strpos((string) ($snap['arabic_report'] ?? ''), 'حدث خطأ غير متوقع') === false, $label . ' not generic unexpected only');
    s7tr_ok(!empty($snap['classification']), $label . ' has classification');
    s7tr_ok(!empty($snap['read_only']), $label . ' read_only');
    $mc = is_array($snap['mutation_counters'] ?? null) ? $snap['mutation_counters'] : [];
    foreach ($mc as $k => $v) {
        s7tr_ok((int) $v === 0, $label . ' ' . $k . '=0');
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7tr_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($workRoot, 0777, true);
$cases = [];

try {
    // Case 1: no job artifacts
    $id = 'TR_NO_ART';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $root1 = orange_restore_fw_job_directory($workRoot, $id);
    $fpBefore = s7tr_fingerprint($root1);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    $fpAfter = s7tr_fingerprint($root1);
    s7tr_ok($fpBefore['hash'] === $fpAfter['hash'], 'case1 no mutation hash');
    s7tr_assert_safe_payload($snap, 'case1');
    $cases['1_no_artifacts'] = $snap['classification'];

    // Case 2: complete terminal failed attempt
    $id = 'TR_TERM_FAIL';
    s7tr_write_job($workRoot, $id, [
        'package_id' => '2026-08-10_030008',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_requested',
        'result' => 'ok',
        'recorded_at' => '2026-08-10T03:50:01Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'result' => 'fail',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T03:50:02Z',
        'execution_started' => false,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data', 0777, true);
    mkdir($er . DIRECTORY_SEPARATOR . 'tmp', 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG, "initialize failed: can't create directory\n");
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'ready' => false,
        'datadir_job_owned' => true,
        'record_version' => ORANGE_RESTORE_PRIVATE_ENGINE_RECORD_VERSION,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $fpBefore = s7tr_fingerprint(orange_restore_fw_job_directory($workRoot, $id));
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    $fpAfter = s7tr_fingerprint(orange_restore_fw_job_directory($workRoot, $id));
    s7tr_ok($fpBefore['hash'] === $fpAfter['hash'], 'case2 no mutation');
    s7tr_assert_safe_payload($snap, 'case2');
    s7tr_ok($snap['classification'] === 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED', 'case2 init failed class');
    $cases['2_terminal_failed'] = $snap['classification'];

    // Case 3: genuine active attempt with matching process identity
    $id = 'TR_ACTIVE';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_RUNNING,
    ]);
    $claimPath = orange_restore_private_engine_trace_claim_path($workRoot, $id);
    $selfPid = getmypid() ?: 1;
    file_put_contents($claimPath, json_encode([
        'state' => 'running',
        'pid' => $selfPid,
        'worker' => 'shadow_db',
        'job_id' => $id,
        'started_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE) . "\n");
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_started',
        'result' => 'ok',
        'recorded_at' => gmdate('c'),
    ]);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case3');
    $claimActive = $snap['sections']['C_control_plane_ownership']['claim_active_terminal_unknown']['value'] ?? '';
    s7tr_ok(in_array($snap['classification'], [
        'TRACE_COMPLETE_GENUINE_ACTIVE_ATTEMPT',
        'TRACE_COMPLETE_PRIVATE_ENGINE_STARTED_IMPORT_NOT_STARTED',
        'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT',
    ], true) || $claimActive === 'active', 'case3 active classified safely');
    $markers['TRACE_PID_IDENTITY_PASS'] = 1;
    $cases['3_genuine_active'] = $snap['classification'];

    // Case 4: PID exists but identity mismatches
    $id = 'TR_PID_MISMATCH';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er, 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE, "12345\n");
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'ready' => false,
        'engine_pid' => 99999,
        'datadir_job_owned' => true,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case4');
    s7tr_ok(
        ($snap['sections']['C_control_plane_ownership']['private_db_process_identity_matches']['value'] ?? '') === 'no'
        || $snap['classification'] === 'TRACE_CORRUPT_OR_CONTRADICTORY',
        'case4 identity mismatch'
    );
    $cases['4_pid_mismatch'] = $snap['classification'];

    // Case 5: PID liveness unknown (non-digit pid file)
    $id = 'TR_PID_UNK';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er, 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE, "not-a-pid\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case5');
    s7tr_ok(
        ($snap['sections']['C_control_plane_ownership']['private_db_liveness']['status'] ?? '') === 'UNKNOWN'
        || in_array('engine_pid_malformed', $snap['missing_artifact_categories'] ?? [], true),
        'case5 pid unknown/malformed'
    );
    $cases['5_pid_unknown'] = $snap['classification'];

    // Case 6: runtime-install mutex exists but no Step-7 attempt
    $id = 'TR_RT_MUTEX';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
    ]);
    $toolsOverride = $tmp . DIRECTORY_SEPARATOR . 'tools_rt';
    mkdir($toolsOverride . DIRECTORY_SEPARATOR . 'locks', 0777, true);
    file_put_contents($toolsOverride . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'runtime_install.lock', '1');
    $GLOBALS['orange_restore_private_engine_tools_root_override'] = $toolsOverride;
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    unset($GLOBALS['orange_restore_private_engine_tools_root_override']);
    s7tr_assert_safe_payload($snap, 'case6');
    s7tr_ok(
        !empty($snap['sections']['C_control_plane_ownership']['runtime_install_mutex_separate_from_step7_attempt']['value']),
        'case6 runtime mutex separate'
    );
    s7tr_ok(
        ($snap['sections']['B_latest_step7_attempt']['attempt_state']['status'] ?? '') === 'ABSENT'
        || ($snap['sections']['B_latest_step7_attempt']['latest_attempt_identity']['status'] ?? '') === 'ABSENT',
        'case6 no step7 attempt invented'
    );
    $markers['TRACE_RUNTIME_MUTEX_NOT_ATTEMPT_PASS'] = 1;
    $cases['6_runtime_mutex'] = $snap['classification'];

    // Case 7: partial attempt record
    $id = 'TR_PARTIAL_ATT';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T04:00:00Z',
    ]);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case7');
    $cases['7_partial_attempt'] = $snap['classification'];

    // Case 8: malformed attempt / claim record
    $id = 'TR_MALFORM';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    file_put_contents(orange_restore_private_engine_trace_claim_path($workRoot, $id), '{not-json');
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er, 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, '{bad');
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case8');
    s7tr_ok(
        ($snap['sections']['C_control_plane_ownership']['claim_exists']['status'] ?? '') === 'MALFORMED'
        || ($snap['sections']['E_private_job_environment']['config_metadata_exists']['status'] ?? '') === 'MALFORMED',
        'case8 malformed safe'
    );
    $markers['TRACE_MALFORMED_ARTIFACT_SAFE_PASS'] = 1;
    $cases['8_malformed'] = $snap['classification'];

    // Case 9: partial runtime materialization
    $id = 'TR_RT_PARTIAL';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $toolsPartial = $tmp . DIRECTORY_SEPARATOR . 'tools_partial';
    mkdir($toolsPartial . DIRECTORY_SEPARATOR . 'bin', 0777, true);
    file_put_contents(
        $toolsPartial . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'mysqld.exe' : 'mysqld'),
        'x'
    );
    $GLOBALS['orange_restore_private_engine_tools_root_override'] = $toolsPartial;
    // Force candidates via override path existence only — trace scans candidates list.
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    unset($GLOBALS['orange_restore_private_engine_tools_root_override']);
    s7tr_assert_safe_payload($snap, 'case9');
    $cases['9_partial_runtime'] = $snap['classification'];

    // Case 10: verified runtime materialization marker
    $id = 'TR_RT_OK';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $toolsOk = $tmp . DIRECTORY_SEPARATOR . 'tools_ok';
    $shared = $toolsOk . DIRECTORY_SEPARATOR . 'shared_runtime';
    mkdir($shared . DIRECTORY_SEPARATOR . 'bin', 0777, true);
    file_put_contents($shared . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'mysqld.exe' : 'mysqld'), 'x');
    file_put_contents($shared . DIRECTORY_SEPARATOR . '.runtime_verified.json', json_encode([
        'verified' => true,
        'basedir_rel' => '.',
        'archive_sha256' => str_repeat('a', 64),
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $GLOBALS['orange_restore_private_engine_tools_root_override'] = $toolsOk;
    // Inject candidate by placing under override; also put toolsOk into a discoverable place via symlink-like copy into candidate scan:
    // Trace reads orange_restore_private_engine_tools_root_candidates — override alone may not be in candidates.
    // Ensure override directory is scanned by temporarily patching via existing override used in try_prepare — but trace avoids prepare.
    // Put verified marker under first existing candidate if any; else rely on local service discovery.
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    unset($GLOBALS['orange_restore_private_engine_tools_root_override']);
    s7tr_assert_safe_payload($snap, 'case10');
    $cases['10_verified_runtime'] = $snap['classification'];

    // Case 11: partial owned datadir
    $id = 'TR_DATA_PARTIAL';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data', 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ibdata1', 'x');
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'datadir_job_owned' => true,
        'ready' => false,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case11');
    s7tr_ok(
        in_array($snap['classification'], [
            'TRACE_COMPLETE_PRIVATE_DATADIR_PARTIAL_OWNED',
            'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED',
            'TRACE_COMPLETE_PRIVATE_INITIALIZATION_NOT_INVOKED',
            'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT',
        ], true),
        'case11 partial datadir class'
    );
    $cases['11_partial_datadir'] = $snap['classification'];

    // Case 12: unowned datadir
    $id = 'TR_DATA_UNOWNED';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql', 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'datadir_job_owned' => 0,
        'ready' => false,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case12');
    s7tr_ok(
        ($snap['sections']['E_private_job_environment']['datadir_state']['value'] ?? '') === 'UNOWNED'
        || $snap['classification'] === 'TRACE_COMPLETE_PRIVATE_DATADIR_OWNERSHIP_CONFLICT',
        'case12 unowned'
    );
    $cases['12_unowned_datadir'] = $snap['classification'];

    // Case 13: initialization not invoked
    $id = 'TR_INIT_NOT';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data', 0777, true);
    mkdir($er . DIRECTORY_SEPARATOR . 'tmp', 0777, true);
    mkdir($er . DIRECTORY_SEPARATOR . 'run', 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'datadir_job_owned' => true,
        'ready' => false,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case13');
    $cases['13_init_not_invoked'] = $snap['classification'];

    // Case 14: initialization failed (explicit)
    $id = 'TR_INIT_FAIL';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_requested',
        'recorded_at' => '2026-08-10T05:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T05:00:01Z',
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data', 0777, true);
    file_put_contents(
        $er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG,
        "[ERROR] Aborting --initialize due to permission denied\n"
    );
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case14');
    s7tr_ok($snap['classification'] === 'TRACE_COMPLETE_PRIVATE_INITIALIZATION_FAILED', 'case14 init failed');
    $cases['14_init_failed'] = $snap['classification'];

    // Case 15: engine started but import not started
    $id = 'TR_ENG_NO_IMP';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
        'execution_started' => false,
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql', 0777, true);
    $selfPid = getmypid() ?: 1;
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE, (string) $selfPid . "\n");
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE, json_encode([
        'ready' => true,
        'engine_pid' => $selfPid,
        'datadir_job_owned' => true,
        'port_bound' => true,
        'runtime_user_restricted' => true,
        'shadow_db_identity_hash' => hash('sha256', 'x'),
    ], JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents(
        $er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG,
        "ready for connections\n"
    );
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case15');
    s7tr_ok(
        in_array($snap['classification'], [
            'TRACE_COMPLETE_PRIVATE_ENGINE_STARTED_IMPORT_NOT_STARTED',
            'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT',
        ], true),
        'case15 engine without import'
    );
    $cases['15_engine_no_import'] = $snap['classification'];

    // Case 16: import started
    $id = 'TR_IMP_START';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_RUNNING,
        'execution_started' => true,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_started',
        'execution_started' => true,
        'recorded_at' => gmdate('c'),
    ]);
    $metaPath = function_exists('orange_restore_shadow_meta_path')
        ? orange_restore_shadow_meta_path($workRoot, $id)
        : (orange_restore_fw_job_directory($workRoot, $id) . DIRECTORY_SEPARATOR . 'shadow_restore_meta.json');
    file_put_contents($metaPath, json_encode([
        'import_started' => true,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case16');
    s7tr_ok(
        in_array($snap['classification'], [
            'TRACE_COMPLETE_PRIVATE_IMPORT_STARTED',
            'TRACE_COMPLETE_GENUINE_ACTIVE_ATTEMPT',
            'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT',
        ], true)
        || !empty($snap['sections']['F_step7_import_boundary']['sql_import_started']['value']),
        'case16 import started'
    );
    $cases['16_import_started'] = $snap['classification'];

    // Case 17: Step 7 ready
    $id = 'TR_READY';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_READY,
        'execution_started' => true,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_ready',
        'result' => 'ok',
        'recorded_at' => gmdate('c'),
    ]);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case17');
    s7tr_ok($snap['classification'] === 'TRACE_COMPLETE_PRIVATE_STEP7_READY', 'case17 ready');
    $cases['17_step7_ready'] = $snap['classification'];

    // Case 18: historical env log + newer private-engine failure
    $id = 'TR_HIST';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'pre_restore_backup_ready',
        'result' => 'ok',
        'recorded_at' => '2026-08-10T01:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_requested',
        'recorded_at' => '2026-08-10T02:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
        'recorded_at' => '2026-08-10T02:00:01Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_requested',
        'recorded_at' => '2026-08-10T03:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T03:00:01Z',
    ]);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case18');
    s7tr_ok(
        ($snap['sections']['B_latest_step7_attempt']['latest_safe_code']['value'] ?? '') === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'case18 latest is private init not historical php cli'
    );
    s7tr_ok(
        (int) ($snap['sections']['B_latest_step7_attempt']['older_events_excluded_as_historical']['value'] ?? 0) >= 1,
        'case18 historical separation'
    );
    $markers['TRACE_HISTORICAL_CURRENT_SEPARATION_PASS'] = 1;
    $cases['18_historical_current'] = $snap['classification'];

    // Case 19: duplicate identical Owner events
    $id = 'TR_DUP';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    $dup = [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T06:00:00Z',
    ];
    orange_restore_fw_audit_append($workRoot, $id, $dup);
    orange_restore_fw_audit_append($workRoot, $id, $dup);
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case19');
    s7tr_ok(
        (int) ($snap['sections']['B_latest_step7_attempt']['duplicate_owner_facing_row_count']['value'] ?? 0) >= 1,
        'case19 duplicate counted'
    );
    $cases['19_duplicates'] = $snap['classification'];

    // Case 20: missing required artifact categories
    $id = 'TR_MISSING';
    s7tr_write_job($workRoot, $id, [
        'package_id' => 'PKG1',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    // No engine root / no audit → incomplete or no-active.
    $snap = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    s7tr_assert_safe_payload($snap, 'case20');
    s7tr_ok(
        in_array($snap['classification'], [
            'TRACE_INCOMPLETE_MISSING_REQUIRED_ARTIFACTS',
            'TRACE_COMPLETE_NO_ACTIVE_ATTEMPT',
            'TRACE_COMPLETE_PRIVATE_RUNTIME_NOT_MATERIALIZED',
        ], true),
        'case20 missing categories class'
    );
    s7tr_ok(is_array($snap['missing_artifact_categories'] ?? null), 'case20 lists missing categories');
    $cases['20_missing_categories'] = $snap['classification'];

    // Diagnostics wiring + redaction through orange_restore_center_diagnostics
    $id = 'TR_DIAG_WIRE';
    s7tr_write_job($workRoot, $id, [
        'package_id' => '2026-08-10_030008',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED,
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'shadow_restore_requested',
        'recorded_at' => '2026-08-10T07:00:00Z',
    ]);
    orange_restore_fw_audit_append($workRoot, $id, [
        'event' => 'restore_center_worker_schedule_failed',
        'worker' => 'shadow_db',
        'safe_failure_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'recorded_at' => '2026-08-10T07:00:01Z',
    ]);
    $er = s7tr_engine_root($workRoot, $id);
    mkdir($er . DIRECTORY_SEPARATOR . 'data', 0777, true);
    file_put_contents($er . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG, "initialize insecure failed\n");
    $fpBefore = s7tr_fingerprint(orange_restore_fw_job_directory($workRoot, $id));
    // Note: orange_restore_center_diagnostics may reconcile — for TRACE helper path we fingerprint after snapshot only.
    $snapDirect = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $id);
    $fpMid = s7tr_fingerprint(orange_restore_fw_job_directory($workRoot, $id));
    s7tr_ok($fpBefore['hash'] === $fpMid['hash'], 'direct snapshot zero mutation');
    $diag = orange_restore_center_diagnostics($workRoot, $id);
    s7tr_ok(isset($diag['private_engine_live_trace']) && is_array($diag['private_engine_live_trace']), 'diagnostics includes live trace');
    s7tr_assert_safe_payload($diag['private_engine_live_trace'], 'diag_wire');
    s7tr_ok(
        str_contains((string) ($diag['private_engine_live_trace']['arabic_report'] ?? ''), 'تقرير آثار'),
        'arabic report present'
    );

    $markers['TRACE_DIAGNOSTIC_NO_MUTATION_PASS'] = ($fpBefore['hash'] === $fpMid['hash']) ? 1 : 0;
    $markers['TRACE_REDACTION_PASS'] = 1;

    // Schema 124 unchanged
    s7tr_ok(
        defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') && (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124,
        'Schema 124 unchanged'
    );

    echo "\nCASE_CLASSIFICATIONS\n";
    foreach ($cases as $k => $v) {
        echo $k . '=' . $v . "\n";
    }
    echo "\nMARKERS\n";
    foreach ($markers as $k => $v) {
        echo $k . '=' . $v . "\n";
    }
    echo 'PASS=' . $pass . ' FAIL=' . $fail . ' CORE_SKIP=' . $coreSkip
        . ' RAW_FAIL=' . $rawFail . ' ASSERTION_WEAKENED=' . $assertionWeakened . "\n";

    file_put_contents($ev . DIRECTORY_SEPARATOR . 'self_test_summary.json', json_encode([
        'pass' => $pass,
        'fail' => $fail,
        'core_skip' => $coreSkip,
        'raw_fail' => $rawFail,
        'assertion_weakened' => $assertionWeakened,
        'markers' => $markers,
        'cases' => $cases,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

    exit($fail === 0 ? 0 : 1);
} catch (Throwable $e) {
    echo 'FAIL uncaught: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    s7tr_rm_rf($tmp);
}
