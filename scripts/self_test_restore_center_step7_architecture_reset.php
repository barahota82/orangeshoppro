<?php

declare(strict_types=1);

/**
 * P0 Restore Center architecture reset — disposable local proof.
 * No live job mutation. No Production restore. No Owner manual.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);

function s7a_evidence_dir(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 'D:\\orange_restore_step7_architecture_reset_evidence';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'orange_restore_step7_architecture_reset_evidence';
}

$evDir = s7a_evidence_dir();
if (!is_dir($evDir)) {
    mkdir($evDir, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_db.php';

$pass = 0;
$fail = 0;
$lines = [];

function s7a_ok(bool $c, string $l): void
{
    global $pass, $fail, $lines;
    $row = ($c ? 'PASS ' : 'FAIL ') . $l;
    echo $row . "\n";
    $lines[] = $row;
    $c ? $pass++ : $fail++;
}

// --- Baseline ---
$head = trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse --short=8 HEAD'));
s7a_ok($head === '24444a86' || strlen($head) === 8, 'git HEAD short readable (pre-commit baseline was 24444a86)');

// --- Architecture inventory ---
$inv = orange_restore_shadow_environment_architecture_inventory();
s7a_ok(($inv['selected'] ?? '') === 'C_PRIVATE_MYSQL_INSTANCE', 'selected architecture C private MySQL instance');
s7a_ok(isset($inv['rejected']['A_COUNTRY_CRP_SHADOW']), 'rejected A country CRP for Full Step7');
s7a_ok(isset($inv['rejected']['B_DEDICATED_STAGING_CHANNEL']), 'rejected B staging channel (Owner manual)');
s7a_ok(in_array('7_shadow_db', $inv['shared_steps'] ?? [], true)
    && in_array('10_shadow_smoke', $inv['shared_steps'] ?? [], true), 'shared steps 7-10 listed');

// --- Control-plane: class F false ACTIVE must be gone ---
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7a_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$jobId = '2026-08-13_s7a_' . bin2hex(random_bytes(2));
$jobDir = $workRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId;
mkdir($jobDir, 0777, true);

$job = [
    'job_id' => $jobId,
    'package_type' => 'full_disaster',
    'package_id' => 'pkg_fixture',
    'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
    'phase' => ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
    'progress_percent' => 10,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
];
orange_restore_fw_write($workRoot, $job);
orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
    'framework_job_id' => $jobId,
    'attempt_id' => 's7_fixture_attempt',
    'shadow_db' => 'orange_restore_shadow_fixture',
    'shadow_db_identity_hash' => orange_restore_shadow_target_identity_hash('orange_restore_shadow_fixture', $jobId),
    'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
    'execution_started' => false,
]);

// Stub private engine readiness green for mutation gate unit proof.
$GLOBALS['orange_restore_private_engine_preflight_override'] = static function () use ($jobId): array {
    return [
        'ok' => true,
        'binary_available' => true,
        'engine_ready' => false,
        'materializable' => true,
        'ready_token' => ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING,
        'code' => 'ok',
        'private_capability' => 'materializable',
        'runtime_compatible' => true,
        'tools_root_ready' => true,
        'process_execution_available' => true,
        'runtime_source' => 'materializable_portable',
        'shadow_db_identity_hash' => orange_restore_shadow_target_identity_hash('orange_restore_shadow_fixture', $jobId),
        'db_host_category' => 'LOOPBACK',
    ];
};

// If public_readiness doesn't honor override, still prove claim-based ACTIVE helper.
$activeNone = orange_restore_center_step7_genuine_active_attempt($workRoot, $jobId, $job);
s7a_ok($activeNone['active'] === false, 'pending without claim is NOT genuine ACTIVE (class F eliminated)');

$claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'shadow_db');
orange_restore_center_write_run_claim($claimPath, [
    'job_id' => $jobId,
    'worker' => 'shadow_db',
    'pid' => getmypid(),
    'state' => 'running',
    'started_at' => gmdate('c'),
    'attempt_id' => 's7_fixture_attempt',
]);
$activeYes = orange_restore_center_step7_genuine_active_attempt($workRoot, $jobId, orange_restore_fw_read($workRoot, $jobId));
s7a_ok($activeYes['active'] === true, 'live PID claim is genuine ACTIVE class A');
s7a_ok(($activeYes['class'] ?? '') === ORANGE_RESTORE_STEP7_ACTIVE_CLASS_CLAIM_BLOCKS, 'ACTIVE class A label');
s7a_ok(($activeYes['attempt_id'] ?? '') === 's7_fixture_attempt', 'same attempt_id preserved');

// Mutation readiness must not return ACTIVE solely because status is pending (no claim after clear).
orange_restore_center_clear_run_claim($claimPath, 'test_clear');
$gate = orange_restore_center_step7_mutation_readiness($projectRoot, $workRoot, $jobId);
s7a_ok(($gate['code'] ?? '') !== ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS, 'mutation_readiness never status-only ACTIVE');
s7a_ok(!empty($gate['ok']) || ($gate['code'] ?? '') !== ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS, 'orphan pending not ACTIVE');

// Static proof: run_worker must not re-assert mutation ready after request pending.
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
s7a_ok(!preg_match(
    '/function orange_restore_center_run_worker[\s\S]*?shadow_db[\s\S]{0,1200}?orange_restore_center_assert_step7_mutation_ready/',
    $orchSrc
), 'run_worker does not re-assert mutation_ready for shadow_db (class F fix)');

// Refresh list catch categories present.
$listSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/list.php');
s7a_ok(str_contains($listSrc, 'restore_center_refresh_failed'), 'list.php refresh failure code');
s7a_ok(str_contains($listSrc, 'refresh_error_category'), 'list.php refresh categories');
s7a_ok(str_contains($listSrc, 'orange_restore_center_reconcile_stale_shadow_restore_public_state'), 'list refresh reconciles stale nested status');
s7a_ok(!str_contains($listSrc, 'orange_admin_api_catch'), 'list.php does not use generic admin catch');

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
s7a_ok(str_contains($pageSrc, 'refreshErrorMessage'), 'UI categorizes refresh errors');
s7a_ok(str_contains($pageSrc, 'حدث خطأ غير متوقع'), 'UI maps away generic unexpected on refresh');

// Shared environment service
s7a_ok(function_exists('orange_restore_shadow_environment_ensure'), 'shared ensure exists');
s7a_ok(function_exists('orange_restore_shadow_environment_connect_pdo'), 'shared connect exists');
$verifySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_verify.php');
$smokeSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_smoke.php');
s7a_ok(str_contains($verifySrc, 'orange_restore_shadow_environment_'), 'Step8 verify uses shared environment');
s7a_ok(str_contains($smokeSrc, 'orange_restore_shadow_environment_'), 'Step10 smoke uses shared environment');

// Steps 8-15 readiness (code-level, no execution)
$catalog = orange_restore_center_worker_catalog();
$needed = ['shadow_db', 'shadow_verify', 'shadow_files', 'shadow_smoke', 'production_import', 'uploads_cutover', 'rollback', 'finalize'];
$unknown = 0;
$stageMatrix = [];
foreach ($needed as $w) {
    $rel = (string) ($catalog[$w] ?? '');
    $abs = $rel !== '' ? $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel) : '';
    $ok = $rel !== '' && is_file($abs);
    if (!$ok) {
        $unknown++;
    }
    $stageMatrix[$w] = [
        'entrypoint' => $rel,
        'exists' => $ok,
        'schedulable_map' => isset(orange_restore_center_worker_schedulable_statuses_map()[$w]),
    ];
    s7a_ok($ok, 'stage worker entrypoint: ' . $w);
}
s7a_ok($unknown === 0, 'UNKNOWN_SHARED_DEP_COUNT=0 for Steps 7-15 workers');

// Schema 124 fence still present
s7a_ok(
    defined('ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION')
    && (int) ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION === 124,
    'Step7 expected schema revision 124'
);

// execution_started only at import — request path keeps false
$reqSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-shadow-restore.php');
s7a_ok(str_contains($reqSrc, "'execution_started' => false"), 'request API never sets execution_started true');

// Protected surfaces untouched (Backup Center / Step6 pre-backup)
$backupCenter = $projectRoot . '/admin/pages/backup_center.php';
$preBackup = $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
s7a_ok(is_file($backupCenter) && is_file($preBackup), 'protected Backup/Step6 files still present');

// Cleanup task-created only
function s7a_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            s7a_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
s7a_rrmdir($tmp);
s7a_ok(!is_dir($tmp), 'disposable fixture cleaned');

$summary = [
    'result' => $fail === 0 ? 'PASS' : 'FAIL',
    'pass' => $pass,
    'fail' => $fail,
    'ACTIVE_ATTEMPT_CLASS' => 'F_OWN_PENDING_FALSE_POSITIVE_FIXED',
    'ARCHITECTURE_SELECTED' => 'C_PRIVATE_MYSQL_INSTANCE',
    'ARCHITECTURE_REJECTED' => ['A_COUNTRY_CRP_SHADOW', 'B_DEDICATED_STAGING_CHANNEL'],
    'stage_matrix' => $stageMatrix,
    'UNKNOWN_SHARED_DEP_COUNT' => $unknown,
    'evidence_dir' => $evDir,
    'lines' => $lines,
    'at' => gmdate('c'),
];
file_put_contents(
    $evDir . DIRECTORY_SEPARATOR . 'architecture_reset_self_test.json',
    json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);
file_put_contents(
    $evDir . DIRECTORY_SEPARATOR . 'architecture_inventory.json',
    json_encode($inv, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

echo "\nSUMMARY pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
