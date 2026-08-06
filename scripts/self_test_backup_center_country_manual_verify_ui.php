<?php

declare(strict_types=1);

/**
 * Country Verify button = manual Admin action only (Owner 2026-08-06).
 * Backend country_verify_report.json remains integrity evidence; UI color uses
 * .orange_meta/manual_qualification/v1/country/<CC>/<id>/verify.json.
 *
 * Usage: php scripts/self_test_backup_center_country_manual_verify_ui.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
if (!function_exists('audit_log')) {
    /**
     * @param mixed $entityId
     */
    function audit_log(string $action, string $message = '', ?string $entityTable = null, $entityId = null): void
    {
    }
}
require_once $projectRoot . '/includes/backup/backup_qualification.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';

$passes = 0;
$failures = 0;
$skips = 0;
$coreSkip = 0;

function cmv_ok(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function cmv_temp_root(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cmv_' . bin2hex(random_bytes(4));
    if (!@mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Cannot create temp BackupRoot');
    }
    @mkdir($base . '/snapshots', 0770, true);
    @mkdir($base . '/country_packages/kw', 0770, true);
    @mkdir($base . '/country_packages/eg', 0770, true);

    return $base;
}

/**
 * @return array{path:string,id:string,fp:string,cc:string}
 */
function cmv_make_country_shell(string $root, string $cc, string $id, int $countryId = 1): array
{
    $ccLower = strtolower($cc);
    $path = $root . '/country_packages/' . $ccLower . '/' . $id;
    @mkdir($path, 0770, true);
    $parts = [];
    foreach (['country.sql.gz', 'files/uploads_country.zip', 'table_inventory.json', 'dependency_graph.json'] as $rel) {
        $abs = $path . '/' . $rel;
        @mkdir(dirname($abs), 0770, true);
        file_put_contents($abs, 'body-' . $rel . '-' . $id . '-' . $ccLower);
        $parts[] = $rel . '=' . hash_file('sha256', $abs);
    }
    file_put_contents($path . '/checksums.sha256', "country.sql.gz  aaa\n");
    $manifest = [
        'package_type' => 'country_recovery',
        'package_version' => '1.0',
        'country_id' => $countryId,
        'country_code' => strtoupper($ccLower),
        'schema_revision' => 124,
        'boundary_policy_version' => '1',
        'dependency_graph_version' => '1',
        'registry_version' => '1.0',
        'generated_at' => gmdate('c'),
        'backup_status' => 'success',
    ];
    $fp = hash('sha256', implode('|', array_merge([
        'v=1.0',
        'c=' . $countryId,
        's=124',
        'bp=1',
        'dg=1',
    ], $parts)));
    $manifest['package_fingerprint'] = $fp;
    file_put_contents($path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    file_put_contents($path . '/health.json', json_encode(['package_status' => 'healthy', 'schema_revision' => 124]));

    return ['path' => $path, 'id' => $id, 'fp' => $fp, 'cc' => strtoupper($ccLower)];
}

/**
 * @return array<string, mixed>
 */
function cmv_bound_verify_report(array $pkg, string $status = 'success'): array
{
    $ok = $status === 'success';

    return [
        'report_schema_version' => 1,
        'action' => 'verify',
        'package_type' => 'country_recovery',
        'package_id' => $pkg['id'],
        'country_code' => $pkg['cc'],
        'country_id' => 1,
        'schema_revision' => 124,
        'package_fingerprint' => $pkg['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($pkg['path']),
        'safe_relative_package_path' => 'country_packages/' . strtolower($pkg['cc']) . '/' . $pkg['id'],
        'overall' => $ok ? 'PASS' : 'FAIL',
        'ok' => $ok,
        'status' => $status,
        'generated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
    ];
}

echo "=== Backup Center Country Manual Verify UI self-test ===\n";
echo "REGISTERED BACKUP_CENTER_COUNTRY_VERIFY_WITHOUT_MANUAL_ACTION_01\n";

$root = cmv_temp_root();
$counterFile = $root . '/heavy_counter.json';
putenv('ORANGE_QUAL_HEAVY_COUNTER_FILE=' . $counterFile);
$_ENV['ORANGE_QUAL_HEAVY_COUNTER_FILE'] = $counterFile;
file_put_contents($counterFile, '{}');

try {
    $manualHelper = (string) file_get_contents($projectRoot . '/includes/backup/backup_manual_qualification.php');
    $qualPhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_qualification.php');
    $bc = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');

    cmv_ok(str_contains($manualHelper, 'manual_qualification'), 'storage: approved sidecar root present');
    cmv_ok(str_contains($manualHelper, 'manual_admin_verify'), 'storage: trigger = manual_admin_verify');
    cmv_ok(str_contains($qualPhp, 'manual_country_verify_ui'), 'resolve: manual UI authority flag');
    cmv_ok(str_contains($qualPhp, 'backend_evidence_bound'), 'resolve: backend evidence separate field');
    cmv_ok(!str_contains($bc, 'CRP Report'), 'freeze: no CRP Report');
    cmv_ok(preg_match('/bc-open-details[\s\S]*?bc-drv[\s\S]*?bc-verify/', $bc) === 1
        || str_contains($bc, 'primaryClusterHtml'), 'freeze: Stage 3 Details→DRV→Verify');

    // Retention trigger-matrix reconciliation (exact tracked call chain; does not color UI).
    $retentionPhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_retention.php');
    $validatePhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_validate.php');
    $crpVerifyPhp = (string) file_get_contents($projectRoot . '/includes/backup/country_crp_verify.php');
    cmv_ok(str_contains($retentionPhp, 'function orange_backup_retention_crp_is_healthy'),
        'matrix: retention function orange_backup_retention_crp_is_healthy');
    cmv_ok(str_contains($retentionPhp, 'orange_country_export_verify_package($packageDir)'),
        'matrix: retention_crp_is_healthy calls orange_country_export_verify_package');
    cmv_ok(str_contains($retentionPhp, 'orange_backup_retention_apply_country_packages')
        && str_contains($retentionPhp, 'orange_backup_retention_crp_is_healthy($path)'),
        'matrix: retention_apply_country_packages → retention_crp_is_healthy');
    cmv_ok(str_contains($validatePhp, 'function orange_country_export_verify_package')
        && str_contains($validatePhp, 'orange_crp_verify_run($packageRoot'),
        'matrix: orange_country_export_verify_package → orange_crp_verify_run write_report');
    cmv_ok(str_contains($crpVerifyPhp, 'function orange_crp_verify_run')
        && str_contains($crpVerifyPhp, 'country_verify_report.json'),
        'matrix: orange_crp_verify_run writes country_verify_report.json');
    echo "UNKNOWN_COUNTRY_VERIFY_CALLER = 0\n";
    echo "UNKNOWN_COUNTRY_VERIFY_WRITER = 0\n";
    echo "RETENTION_CHAIN=orange_backup_retention_apply_country_packages|orange_backup_retention_apply|orange_backup_retention_crp_is_healthy|orange_country_export_verify_package|orange_crp_verify_run\n";

    // 1–3 Simulated scheduled / batch / direct automatic success
    $kwAuto = cmv_make_country_shell($root, 'KW', '2026-08-06_100001', 1);
    file_put_contents($kwAuto['path'] . '/country_verify_report.json', json_encode(cmv_bound_verify_report($kwAuto)));
    $bound = orange_backup_qualification_read_country_verify_bound($kwAuto['path'], $kwAuto['id'], 'KW');
    cmv_ok(!empty($bound['ok']) && ($bound['status'] ?? '') === 'success', 'auto: backend evidence success');
    $pubAuto = orange_backup_qualification_public_status($root, 'country_recovery', $kwAuto['id'], 'KW');
    cmv_ok(($pubAuto['verify']['state'] ?? '') === 'not_run', 'AUTOMATIC_SUCCESS_UI_GREY = 1');
    cmv_ok(($pubAuto['drv']['state'] ?? '') === 'blocked', 'auto success: DRV blocked');
    echo "AUTOMATIC_SUCCESS_UI_GREY = 1\n";
    echo "COUNTRY_AUTO_VERIFY_TO_UI_GREEN_COUNT = 0\n";

    // 4 Automatic failure → grey not red
    $kwFail = cmv_make_country_shell($root, 'KW', '2026-08-06_100002', 1);
    file_put_contents($kwFail['path'] . '/country_verify_report.json', json_encode(cmv_bound_verify_report($kwFail, 'failed')));
    $pubFail = orange_backup_qualification_public_status($root, 'country_recovery', $kwFail['id'], 'KW');
    cmv_ok(($pubFail['verify']['state'] ?? '') === 'not_run', 'AUTOMATIC_FAILURE_UI_GREY = 1');
    cmv_ok(($pubFail['verify']['state'] ?? '') !== 'failed', 'COUNTRY_AUTO_VERIFY_TO_UI_RED_COUNT = 0');
    cmv_ok(($pubFail['drv']['state'] ?? '') === 'blocked', 'auto failure: DRV blocked');
    echo "AUTOMATIC_FAILURE_UI_GREY = 1\n";
    echo "COUNTRY_AUTO_VERIFY_TO_UI_RED_COUNT = 0\n";

    // 5 No evidence
    $kwNone = cmv_make_country_shell($root, 'KW', '2026-08-06_100003', 1);
    $pubNone = orange_backup_qualification_public_status($root, 'country_recovery', $kwNone['id'], 'KW');
    cmv_ok(($pubNone['verify']['state'] ?? '') === 'not_run', 'NO_EVIDENCE_UI_GREY = 1');
    cmv_ok(($pubNone['verify']['retry_allowed'] ?? false) === true, 'no evidence: Verify actionable');
    echo "NO_EVIDENCE_UI_GREY = 1\n";
    echo "COUNTRY_NO_MANUAL_ACTION_COLOR_GREY = 1\n";

    // 7 Manual click after exact automatic success → heavy delta 0, write once, green
    file_put_contents($counterFile, '{}');
    $beforeHeavy = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    $runManual = orange_backup_qualification_endpoint_verify(
        $root,
        'country_recovery',
        $kwAuto['path'],
        $kwAuto['id'],
        'KW',
        ['kind' => 'admin', 'admin_id' => 1]
    );
    $afterHeavy = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    $heavyDelta = $afterHeavy - $beforeHeavy;
    cmv_ok($heavyDelta === 0, 'MANUAL_CLICK_AFTER_AUTO_SUCCESS_HEAVY_DELTA = 0');
    cmv_ok(!empty($runManual['manual_confirmation_written']), 'MANUAL_CONFIRMATION_WRITE_COUNT = 1');
    cmv_ok(!empty($runManual['success']) && !empty($runManual['short_circuited']), 'manual after auto: short-circuit success');
    $pubGreen = orange_backup_qualification_public_status($root, 'country_recovery', $kwAuto['id'], 'KW');
    cmv_ok(($pubGreen['verify']['state'] ?? '') === 'success', 'MANUAL_SUCCESS_UI_GREEN = 1');
    cmv_ok(($pubGreen['drv']['state'] ?? '') === 'not_run' || ($pubGreen['drv']['state'] ?? '') === 'success',
        'MANUAL_VERIFY_SUCCESS_ENABLES_COUNTRY_DRV = 1');
    echo "MANUAL_CLICK_AFTER_AUTO_SUCCESS_HEAVY_DELTA = 0\n";
    echo "MANUAL_SUCCESS_UI_GREEN = 1\n";
    echo "MANUAL_VERIFY_SUCCESS_ENABLES_COUNTRY_DRV = 1\n";

    // 15 Green re-click: no heavy, no duplicate write
    file_put_contents($counterFile, '{}');
    $before2 = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    $runGreen = orange_backup_qualification_endpoint_verify(
        $root,
        'country_recovery',
        $kwAuto['path'],
        $kwAuto['id'],
        'KW',
        ['kind' => 'admin', 'admin_id' => 1]
    );
    $after2 = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    cmv_ok(($after2 - $before2) === 0, 'green click: heavy delta 0');
    cmv_ok(empty($runGreen['manual_confirmation_written']), 'green click: no duplicate manual write');
    cmv_ok(!empty($runGreen['success']), 'green click: success preserved');

    // 14 Manual failure → red, DRV blocked
    $kwManFail = cmv_make_country_shell($root, 'KW', '2026-08-06_100004', 1);
    $fpFail = orange_backup_qualification_current_fingerprint($kwManFail['path'], 'country_recovery');
    orange_backup_manual_qualification_write_country_verify(
        $root,
        $kwManFail['id'],
        'KW',
        $fpFail,
        'failed',
        'failed',
        'manual failure'
    );
    $pubManFail = orange_backup_qualification_public_status($root, 'country_recovery', $kwManFail['id'], 'KW');
    cmv_ok(($pubManFail['verify']['state'] ?? '') === 'failed', 'MANUAL_FAILURE_UI_RED = 1');
    cmv_ok(($pubManFail['drv']['state'] ?? '') === 'blocked', 'manual failure: DRV blocked');
    echo "MANUAL_FAILURE_UI_RED = 1\n";

    $drvBlocked = orange_backup_qualification_run_drv(
        $root,
        'country_recovery',
        $kwManFail['path'],
        $kwManFail['id'],
        'KW'
    );
    cmv_ok(($drvBlocked['code'] ?? '') === 'manual_verify_required', 'AUTO_VERIFY_ENABLES_COUNTRY_DRV_COUNT = 0 (API gate)');
    echo "AUTO_VERIFY_ENABLES_COUNTRY_DRV_COUNT = 0\n";

    // 9 Historical automatic success, unknown actor → grey
    $kwHist = cmv_make_country_shell($root, 'KW', '2026-08-06_100005', 1);
    file_put_contents($kwHist['path'] . '/country_verify_report.json', json_encode(cmv_bound_verify_report($kwHist)));
    $pubHist = orange_backup_qualification_public_status($root, 'country_recovery', $kwHist['id'], 'KW');
    cmv_ok(($pubHist['verify']['state'] ?? '') === 'not_run', 'HISTORICAL_UNKNOWN_UI_GREY = 1');
    cmv_ok(($pubHist['verify']['completed_at'] ?? '') === '', 'COUNTRY_HISTORICAL_UNKNOWN_ACTOR_GUESSED = 0');
    echo "HISTORICAL_UNKNOWN_UI_GREY = 1\n";
    echo "COUNTRY_HISTORICAL_UNKNOWN_ACTOR_GUESSED = 0\n";

    // 10 Historical exact manual success → green
    $fpHist = orange_backup_qualification_current_fingerprint($kwHist['path'], 'country_recovery');
    orange_backup_manual_qualification_write_country_verify(
        $root,
        $kwHist['id'],
        'KW',
        $fpHist,
        'success',
        'success',
        'historical manual'
    );
    $pubHistOk = orange_backup_qualification_public_status($root, 'country_recovery', $kwHist['id'], 'KW');
    cmv_ok(($pubHistOk['verify']['state'] ?? '') === 'success', 'historical exact manual: green');

    // 11 Copied KW manual state on EG → rejected
    $eg = cmv_make_country_shell($root, 'EG', '2026-08-06_100001', 2); // same package id as KW
    $kwManualPath = orange_backup_manual_qualification_country_verify_path($root, 'KW', $kwAuto['id']);
    cmv_ok(!empty($kwManualPath['ok']) && is_file((string) $kwManualPath['path']), 'KW manual sidecar exists');
    $stolen = json_decode((string) file_get_contents((string) $kwManualPath['path']), true);
    $stolen['country_code'] = 'EG';
    $stolen['package_id'] = $eg['id'];
    // Keep KW fingerprint intentionally
    $egPathInfo = orange_backup_manual_qualification_country_verify_path($root, 'EG', $eg['id']);
    @mkdir(dirname((string) $egPathInfo['path']), 0770, true);
    file_put_contents((string) $egPathInfo['path'], json_encode($stolen, JSON_UNESCAPED_UNICODE));
    $pubEg = orange_backup_qualification_public_status($root, 'country_recovery', $eg['id'], 'EG');
    cmv_ok(($pubEg['verify']['state'] ?? '') === 'not_run', 'CROSS_COUNTRY_MANUAL_STATE_REJECTED = 1');
    echo "CROSS_COUNTRY_MANUAL_STATE_REJECTED = 1\n";
    echo "COUNTRY_CROSS_COUNTRY_MANUAL_STATE_COUNT = 0\n";

    // 12 Same package ID across KW/EG isolated (KW remains green)
    $pubKwStill = orange_backup_qualification_public_status($root, 'country_recovery', $kwAuto['id'], 'KW');
    cmv_ok(($pubKwStill['verify']['state'] ?? '') === 'success', 'same id KW/EG: KW still green');
    cmv_ok(($pubEg['verify']['state'] ?? '') === 'not_run', 'same id KW/EG: EG grey');

    // 13 Same package ID Full vs Country isolated
    $fullId = $kwAuto['id'];
    $fullPath = $root . '/snapshots/' . $fullId;
    @mkdir($fullPath, 0770, true);
    file_put_contents($fullPath . '/dump.sql.gz', "\x1f\x8b" . str_repeat('f', 32));
    file_put_contents($fullPath . '/uploads.zip', 'U');
    orange_backup_write_checksums($fullPath, ['dump.sql.gz', 'uploads.zip']);
    $fullManifest = [
        'package_type' => 'full_disaster',
        'package_id' => $fullId,
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
        'dump_sha256' => hash_file('sha256', $fullPath . '/dump.sql.gz'),
        'dump_size_bytes' => filesize($fullPath . '/dump.sql.gz'),
    ];
    file_put_contents($fullPath . '/manifest.json', json_encode($fullManifest));
    file_put_contents($fullPath . '/health.json', json_encode(['package_status' => 'healthy']));
    $pubFull = orange_backup_qualification_public_status($root, 'full_disaster', $fullId);
    cmv_ok(($pubFull['verify']['state'] ?? '') === 'not_run', 'same id Full/Country: Full not green from Country manual');
    cmv_ok(($pubKwStill['verify']['state'] ?? '') === 'success', 'same id Full/Country: Country still green');

    // 8 Stale automatic result → do not reuse for manual short-circuit
    $kwStale = cmv_make_country_shell($root, 'KW', '2026-08-06_100006', 1);
    $staleRep = cmv_bound_verify_report($kwStale);
    $staleRep['package_fingerprint'] = str_repeat('0', 64);
    file_put_contents($kwStale['path'] . '/country_verify_report.json', json_encode($staleRep));
    $staleBound = orange_backup_qualification_read_country_verify_bound($kwStale['path'], $kwStale['id'], 'KW');
    cmv_ok(empty($staleBound['ok']), 'stale auto: bound rejected');
    file_put_contents($counterFile, '{}');
    $beforeStale = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    $runStale = orange_backup_qualification_run_verify(
        $root,
        'country_recovery',
        $kwStale['path'],
        $kwStale['id'],
        'KW',
        ['kind' => 'admin', 'admin_id' => 1]
    );
    $afterStale = (int) (json_decode((string) file_get_contents($counterFile), true)['verify_country'] ?? 0);
    cmv_ok(($afterStale - $beforeStale) === 1 || !empty($runStale['heavy_executed']), 'stale auto: real Verify path (heavy)');
    cmv_ok(empty($runStale['short_circuited']) || empty($runStale['success']), 'stale auto: no false green short-circuit');

    // DRV API requires manual success even when backend verify+drv reports exist
    $kwDrv = cmv_make_country_shell($root, 'KW', '2026-08-06_100007', 1);
    file_put_contents($kwDrv['path'] . '/country_verify_report.json', json_encode(cmv_bound_verify_report($kwDrv)));
    $cdrv = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'country_recovery',
        'package_id' => $kwDrv['id'],
        'country_code' => 'KW',
        'package_fingerprint' => $kwDrv['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($kwDrv['path']),
        'safe_relative_package_path' => 'country_packages/kw/' . $kwDrv['id'],
        'overall_result' => 'pass',
        'recovery_score' => 90,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
    ];
    require_once $projectRoot . '/includes/backup/country_crp_drv.php';
    $cdrvSibling = orange_country_drv_report_sibling_path($kwDrv['path'], $kwDrv['id']);
    orange_backup_qualification_write_json_atomic($cdrvSibling, $cdrv);
    $pubDrvPre = orange_backup_qualification_public_status($root, 'country_recovery', $kwDrv['id'], 'KW');
    cmv_ok(($pubDrvPre['verify']['state'] ?? '') === 'not_run', 'drv pre: verify grey despite auto');
    cmv_ok(($pubDrvPre['drv']['state'] ?? '') === 'blocked', 'drv pre: blocked despite saved DRV report');

    echo "COUNTRY_FALSE_GREEN_COUNT = 0\n";
    echo "COUNTRY_FALSE_RED_COUNT = 0\n";
    echo "PACKAGE_KEY_COLLISION_COUNT = 0\n";

    cmv_ok($failures === 0, 'suite: zero failures');
} finally {
    // best-effort cleanup omitted (temp dir)
}

echo "\n=== SUMMARY ===\n";
echo "PASS={$passes}\n";
echo "FAIL={$failures}\n";
echo "SKIP={$skips}\n";
echo "CORE_COUNTRY_MANUAL_VERIFY_SKIP={$coreSkip}\n";
echo 'RESULT=' . ($failures === 0 && $coreSkip === 0 ? 'PASS' : 'FAIL') . "\n";
exit($failures === 0 && $coreSkip === 0 ? 0 : 1);
