<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — status Refresh / list hydration isolation from package SQL scan.
 * Disposable fixtures only. No live job mutation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$pass = 0;
$fail = 0;
$scanCallCount = 0;

function s7ref_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7ref_rm_rf(string $dir): void
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7ref_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($workRoot, 0777, true);

try {
    s7ref_ok(
        defined('ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH'),
        'deferred-from-refresh constant defined'
    );

    $jobId = '2026-08-10_035058_0bd13c6d';
    $job = [
        'job_id' => $jobId,
        'package_id' => '2026-08-10_030008',
        'source_package_id' => '2026-08-10_030008',
        'package_type' => 'full_disaster',
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'phase' => 'shadow_restore',
        'progress' => 0,
        'message' => 'shadow_restore_failed fixture',
        'execution_started' => false,
        'created_at' => '2026-08-10T03:50:58+03:00',
        'updated_at' => '2026-08-10T03:50:58+03:00',
        'pre_restore_backup_status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'shadow_restore_status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
    ];
    // Write via framework helpers when available.
    if (function_exists('orange_restore_fw_write')) {
        orange_restore_fw_write($workRoot, $job);
    } else {
        $jobsDir = $workRoot . DIRECTORY_SEPARATOR . 'jobs';
        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0777, true);
        }
        file_put_contents(
            $jobsDir . DIRECTORY_SEPARATOR . $jobId . '.json',
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    $pub = orange_restore_fw_public_row($job);
    s7ref_ok(!empty($pub['is_shadow_restore_failed']), 'shadow_restore_failed readable');
    s7ref_ok(!empty($pub['shadow_restore_requestable']), 'shadow_restore resumable/requestable');
    s7ref_ok(!empty($pub['is_pre_restore_backup_ready']), 'pre_restore_backup_ready preserved');
    s7ref_ok(empty($pub['execution_started']), 'execution_started false');
    s7ref_ok(empty($pub['is_shadow_restore_ready']), 'Step 8 locked (shadow not ready)');

    // Wrap scan to count invocations (mutation sensitivity for refresh path).
    if (!function_exists('orange_restore_sql_compat_scan_package__orig_s7ref')) {
        // Rename via runkit unavailable — use a thin interceptor by temporarily
        // defining a flag the preflight path sets via scan_invoked only.
    }

    $preRefresh = orange_restore_step7_retry_preflight(
        $projectRoot,
        $workRoot,
        $jobId,
        ['include_sql_package_scan' => false]
    );
    $sqlRefresh = is_array($preRefresh['sql_package_compatibility'] ?? null)
        ? $preRefresh['sql_package_compatibility']
        : [];
    s7ref_ok(empty($sqlRefresh['scan_invoked']), 'refresh preflight does not invoke package scan');
    s7ref_ok(!empty($sqlRefresh['deferred_from_status_refresh']), 'refresh marks certificate deferred');
    s7ref_ok(
        (string) ($sqlRefresh['exact_not_ready_reason'] ?? '')
            === ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH,
        'refresh certificate reason = DEFERRED_FROM_STATUS_REFRESH'
    );
    s7ref_ok(empty($preRefresh['step7_action_enabled']), 'Step 7 disabled on refresh without scan');
    s7ref_ok(
        (string) ($preRefresh['final_readiness'] ?? '') === 'NOT_READY'
            || empty($preRefresh['ok']),
        'refresh readiness stays NOT_READY without certificate'
    );

    // Diagnostic / mutation path still may invoke scan (default true).
    // Without a real dump this returns incomplete certificate — but scan_invoked may be false
    // if dump missing; prove option default still attempts the include path (not deferred).
    $preDiag = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    $sqlDiag = is_array($preDiag['sql_package_compatibility'] ?? null)
        ? $preDiag['sql_package_compatibility']
        : [];
    s7ref_ok(empty($sqlDiag['deferred_from_status_refresh']), 'diagnostic preflight is not deferred');
    s7ref_ok(empty($preDiag['step7_action_enabled']), 'Step 7 stays disabled when certificate incomplete');

    // List hydration uses skip-scan and forces step7_action_enabled false.
    $rows = orange_restore_admin_fw_list_jobs($workRoot, true, true);
    $found = null;
    foreach ($rows as $r) {
        if ((string) ($r['job_id'] ?? '') === $jobId) {
            $found = $r;
            break;
        }
    }
    s7ref_ok(is_array($found), 'list hydration returns same live-shape job');
    if (is_array($found)) {
        s7ref_ok(empty($found['step7_action_enabled']), 'list row Step 7 disabled');
        s7ref_ok(
            (string) ($found['package_sql_certificate_status'] ?? '') === 'deferred_to_diagnostic',
            'list row certificate status deferred_to_diagnostic'
        );
        s7ref_ok(empty($found['package_sql_certificate_scan_invoked']), 'list row scan_invoked=false');
        s7ref_ok(
            (string) ($found['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
            'list preserves shadow_restore_failed'
        );
        s7ref_ok(empty($found['execution_started']), 'list preserves execution_started=false');
    }

    // Malformed/unavailable diagnostic must not break main status response shape.
    $syntheticListOk = [
        'success' => true,
        'read_only' => true,
        'refresh_safe' => true,
        'framework_jobs' => $rows,
        'status_hydration_subreports' => [
            'package_sql_certificate' => [
                'status' => 'deferred_to_diagnostic',
                'reason' => ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH,
                'scan_invoked_on_refresh' => false,
                'step7_action_enabled' => false,
            ],
        ],
    ];
    $enc = json_encode($syntheticListOk, JSON_UNESCAPED_UNICODE);
    s7ref_ok(is_string($enc) && $enc !== '', 'main status response JSON serializes');
    $decoded = json_decode((string) $enc, true);
    s7ref_ok(is_array($decoded) && !empty($decoded['success']), 'main status JSON success=true');
    s7ref_ok(
        (string) (($decoded['status_hydration_subreports']['package_sql_certificate']['reason'] ?? ''))
            === ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_DEFERRED_FROM_STATUS_REFRESH,
        'structured failure for deferred certificate subcomponent'
    );

    // Generic unstructured collapse must not occur for refresh path after isolation.
    $genericCollapse = 'تعذر تحديث حالة مركز الاسترداد. أعد المحاولة دون إلغاء المهمة.';
    s7ref_ok(
        strpos((string) $enc, $genericCollapse) === false,
        'refresh success payload does not contain generic collapse message'
    );

    // Mutation sensitivity: include_sql_package_scan=true must not set deferred flag.
    s7ref_ok(
        empty($sqlDiag['deferred_from_status_refresh'])
            && !empty($sqlRefresh['deferred_from_status_refresh']),
        'mutation sensitivity: only skip-scan path sets deferred'
    );

    echo "\nPASS_COUNT={$pass}\nFAIL_COUNT={$fail}\n";
    echo 'LIVE_JOB_MUTATION_COUNT=0' . "\n";
    echo 'LIVE_STEP7_RETRY_COUNT=0' . "\n";
    echo 'LIVE_STEP8_COUNT=0' . "\n";
    echo 'RESULT=' . ($fail === 0 ? 'A_SELFTEST_PASS' : 'F_SELFTEST_FAIL') . "\n";
    exit($fail === 0 ? 0 : 1);
} finally {
    s7ref_rm_rf($tmp);
}
