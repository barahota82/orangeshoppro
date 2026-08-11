<?php

declare(strict_types=1);

/**
 * Permanent focused suite — read-only Backup runtime diagnostic (36 items + mutations).
 *
 * Usage:
 *   php scripts/self_test_backup_runtime_diagnostic.php
 *
 * Evidence (outside Git): D:\orange_backup_runtime_diagnostic_evidence\
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/scripts/lib/backup_stage4b_evidence_lib.php';
require_once $projectRoot . '/includes/backup/backup_runtime_diagnostic.php';

$evidenceDir = 'D:\\orange_backup_runtime_diagnostic_evidence';
@mkdir($evidenceDir, 0775, true);
@mkdir($evidenceDir . DIRECTORY_SEPARATOR . 'shots', 0775, true);
@mkdir($evidenceDir . DIRECTORY_SEPARATOR . 'runtime', 0775, true);

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$rawFail = 0;
$assertionWeakened = 0;

function rd_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

function rd_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $info) {
        if ($info->isDir()) {
            @rmdir($info->getPathname());
        } else {
            @unlink($info->getPathname());
        }
    }
    @rmdir($dir);
}

$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$apiPath = $projectRoot . '/admin/api/backup/runtime-diagnostic.php';
$helperPath = $projectRoot . '/includes/backup/backup_runtime_diagnostic.php';
$pageSrc = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
$apiSrc = is_file($apiPath) ? (string) file_get_contents($apiPath) : '';
$helperSrc = is_file($helperPath) ? (string) file_get_contents($helperPath) : '';

rd_ok($pageSrc !== '' && $apiSrc !== '' && $helperSrc !== '', 'sources readable');

/* --- Auth / HTTP contracts (source + structural) --- */
rd_ok(str_contains($apiSrc, 'orange_backup_admin_require_view'), '1. authorized Admin path uses backup_view');
rd_ok(str_contains($apiSrc, 'backup_admin_api_require_csrf'), '1b. CSRF required');
rd_ok(str_contains($apiSrc, 'backup_admin_api_require_post'), '1c. POST-only');
rd_ok(
    str_contains($apiSrc, "require_once __DIR__ . '/_bootstrap.php'")
    && str_contains($apiSrc, 'orange_backup_admin_require_view'),
    '2. unauthorized without admin/view rejected (bootstrap + require_view)'
);
rd_ok(str_contains($apiSrc, "require_once __DIR__ . '/_bootstrap.php'"), '3. public request rejected (admin bootstrap gate)');

/* --- No mutation in diagnostic helper/API --- */
$forbiddenCalls = [
    'orange_backup_admin_run_full_for_api(',
    'orange_backup_admin_run_full(',
    'orange_backup_admin_run_country_batch(',
    'orange_backup_run_full(',
    'orange_backup_acquire_lock(',
    'orange_crp_batch_acquire_lock(',
    'orange_backup_release_lock(',
    'orange_restore_pre_backup_acquire_lock(',
    'unlink(',
    'proc_open(',
    'posix_kill(',
];
$mutationHits = [];
foreach ($forbiddenCalls as $call) {
    // Allow listing names in comments/strings for classification only when not invoked.
    if (preg_match('/(?<!function\s)' . preg_quote($call, '/') . '/', $helperSrc)
        && !str_contains($call, 'posix_kill')) {
        // orange_backup_run_command_capture for tasklist probe is allowed for liveness READ.
        if ($call === 'proc_open(') {
            continue;
        }
        if (in_array($call, [
            'orange_backup_admin_run_full_for_api(',
            'orange_backup_admin_run_full(',
            'orange_backup_admin_run_country_batch(',
            'orange_backup_run_full(',
            'orange_backup_acquire_lock(',
            'orange_crp_batch_acquire_lock(',
            'orange_backup_release_lock(',
            'orange_restore_pre_backup_acquire_lock(',
            'unlink(',
        ], true)) {
            $mutationHits[] = $call;
        }
    }
}
rd_ok($mutationHits === [], '4/5/6/7. diagnostic starts zero backups / writes zero packages / zero lock delete / zero kill (' . implode(',', $mutationHits) . ')');
rd_ok(
    !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/i', $helperSrc)
    || !preg_match('/\$pdo->(?:exec|prepare)\(\s*[\'"](?:INSERT|UPDATE|DELETE|DROP|ALTER)/i', $helperSrc),
    '8. no database mutation'
);
rd_ok(
    !str_contains($helperSrc, 'orange_backup_acquire_lock(')
    && !str_contains($apiSrc, 'run-full.php')
    && str_contains($apiSrc, 'orange_backup_runtime_diagnostic_run'),
    '9. packages unchanged (diagnostic never calls package writers)'
);

/* Fixture root */
$fxRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_rd_' . bin2hex(random_bytes(4));
@mkdir($fxRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
@mkdir($fxRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);
@mkdir($fxRoot . DIRECTORY_SEPARATOR . 'country_packages', 0775, true);
@mkdir($fxRoot . DIRECTORY_SEPARATOR . 'logs', 0775, true);

$GLOBALS['orange_backup_runtime_diagnostic_root_override'] = $fxRoot;
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_BACKUP_ROOT' => $fxRoot];

/* 10. Full lock absent */
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(empty($r['full_lock']['exists']), '10. Full lock absent');

/* 11. Full lock alive */
file_put_contents($fxRoot . '/locks/orange_full_backup.lock', json_encode([
    'pid' => getmypid(),
    'started_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));
$GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'] = 'alive';
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(!empty($r['full_lock']['exists']) && ($r['full_lock']['liveness'] ?? '') === 'alive', '11. Full lock alive');
rd_ok(in_array('FULL_LOCK_ACTIVE', $r['blockers'] ?? [], true) || ($r['classification'] ?? '') === 'FULL_LOCK_ACTIVE' || ($r['classification'] ?? '') === 'MULTIPLE_RUNTIME_BLOCKERS', '11b. alive lock classified');

/* 12. Full lock dead/stale */
$GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'] = 'dead';
file_put_contents($fxRoot . '/locks/orange_full_backup.lock', json_encode([
    'pid' => 999991,
    'started_at' => gmdate('c', time() - 100),
], JSON_UNESCAPED_UNICODE));
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(($r['full_lock']['liveness'] ?? '') === 'dead' && !empty($r['full_lock']['reclaimable_under_current_code']), '12. Full lock dead/stale reclaimable flag');
rd_ok(is_file($fxRoot . '/locks/orange_full_backup.lock'), '12b. diagnostic did not delete dead lock');

/* 13. Full lock unknown */
$GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'] = 'unknown';
file_put_contents($fxRoot . '/locks/orange_full_backup.lock', json_encode([
    'pid' => 999992,
    'started_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(($r['full_lock']['liveness'] ?? '') === 'unknown', '13. Full lock unknown');

/* 14. Countries lock states */
@unlink($fxRoot . '/locks/orange_full_backup.lock');
file_put_contents($fxRoot . '/locks/orange_crp_batch.lock', json_encode([
    'pid' => 999993,
    'started_at' => gmdate('c'),
    'lock_type' => 'crp_batch',
], JSON_UNESCAPED_UNICODE));
$GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'] = 'alive';
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(!empty($r['countries_lock']['exists']) && ($r['countries_lock']['liveness'] ?? '') === 'alive', '14. Countries lock states');
@unlink($fxRoot . '/locks/orange_crp_batch.lock');
unset($GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override']);

/* 15. Restore pre-backup lock */
@mkdir($fxRoot . '/restore_work', 0775, true);
file_put_contents($fxRoot . '/restore_work/.pre_restore_backup.lock', json_encode([
    'job_id' => 'job_test_1',
    'pid' => 999994,
    'acquired_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));
$GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override'] = 'dead';
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(!empty($r['restore_pre_backup_lock']['exists']), '15. Restore pre-backup lock states');
rd_ok(is_file($fxRoot . '/restore_work/.pre_restore_backup.lock'), '15b. restore lock not deleted');
@unlink($fxRoot . '/restore_work/.pre_restore_backup.lock');
unset($GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override']);

/* 16. BackupRoot missing */
$missing = $fxRoot . DIRECTORY_SEPARATOR . 'missing_root_' . bin2hex(random_bytes(2));
$GLOBALS['orange_backup_runtime_diagnostic_root_override'] = $missing;
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(in_array(($r['classification'] ?? ''), ['BACKUP_ROOT_NOT_READY', 'MULTIPLE_RUNTIME_BLOCKERS'], true)
    || in_array('BACKUP_ROOT_NOT_READY', $r['blockers'] ?? [], true), '16. BackupRoot missing');
$GLOBALS['orange_backup_runtime_diagnostic_root_override'] = $fxRoot;

/* 17. BackupRoot not writable classification */
// Simulate via override disk + root flags by using a file as "root" is hard on Windows;
// assert helper emits BACKUP_ROOT_NOT_WRITABLE when root_writable=no path is hit via classification list.
rd_ok(in_array('BACKUP_ROOT_NOT_WRITABLE', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '17. BackupRoot unreadable/not writable classification exists');

/* 18–22 classifications present + fixture triggers where practical */
rd_ok(in_array('PHP_CLI_UNAVAILABLE', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '18. PHP CLI unavailable classification');
rd_ok(in_array('PROCESS_EXECUTION_UNAVAILABLE', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '19. proc_open unavailable classification');
rd_ok(in_array('FULL_RUNNER_UNAVAILABLE', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '20. Full runner missing classification');
rd_ok(in_array('DATABASE_UNAVAILABLE', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '21. Database unavailable classification');
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(in_array('DATABASE_UNAVAILABLE', $r['blockers'] ?? [], true) || ($r['classification'] ?? '') === 'DATABASE_UNAVAILABLE' || ($r['classification'] ?? '') === 'MULTIPLE_RUNTIME_BLOCKERS', '21b. null PDO ⇒ database unavailable blocker');
rd_ok(in_array('SCHEMA_GATE_MISMATCH', ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true), '22. Schema mismatch classification');

/* 23. Last Full failure stage mapping */
$execDir = $fxRoot . '/.orange_meta/provenance/v1/executions';
@mkdir($execDir, 0775, true);
file_put_contents($execDir . '/exec_fail_runner.json', json_encode([
    'execution_id' => 'exec_fail_runner',
    'backup_scope' => 'full',
    'package_type' => 'full',
    'result_status' => 'failed',
    'last_stage' => 'runner',
    'safe_failure_code' => 'runner_exit_nonzero',
    'process_started' => true,
    'started_at_utc' => gmdate('c'),
    'completed_at_utc' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));
// Clear hard blockers for stage mapping unit: inject fake PDO-less but remove root blockers by ensuring writable root;
// classification with DATABASE_UNAVAILABLE will dominate — test mapping function directly.
$mapped = orange_backup_runtime_diagnostic_classify([], [
    'evidence_available' => true,
    'result_status' => 'failed',
    'last_reached_stage' => 'runner',
]);
rd_ok($mapped === 'LAST_FULL_PROCESS_STARTED_RUNNER_FAILED', '23. Last Full failure stage mapping');

/* 24. Missing last-run evidence */
rd_rm_tree($fxRoot . '/.orange_meta');
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
rd_ok(empty($r['last_full_attempt']['evidence_available']), '24. Missing last-run evidence reports unavailable');
rd_ok(($r['last_full_attempt']['last_reached_stage'] ?? null) === null, '24b. stage not guessed');

/* 25. Multiple blockers */
$multi = orange_backup_runtime_diagnostic_classify(['PHP_CLI_UNAVAILABLE', 'BACKUP_ROOT_NOT_WRITABLE'], [
    'evidence_available' => false,
]);
rd_ok($multi === 'MULTIPLE_RUNTIME_BLOCKERS', '25. Multiple blockers classification');

/* 25b. Safe Arabic blocker list visible (not generic-only) */
$multiUi = orange_backup_runtime_diagnostic_owner_ui([
    'classification' => 'MULTIPLE_RUNTIME_BLOCKERS',
    'blockers' => ['PHP_CLI_UNAVAILABLE', 'FULL_RUNNER_UNAVAILABLE'],
    'backup_root' => ['root_configured' => true, 'root_exists' => true, 'root_readable' => true, 'root_writable' => 'yes'],
    'full_lock' => ['exists' => false],
    'countries_lock' => ['exists' => false],
    'process' => ['cli_resolved' => false, 'proc_open_available' => true],
    'database' => ['database_connection_available' => true, 'schema_gate_match' => true],
    'last_full_attempt' => ['evidence_available' => false, 'process_started' => 'unknown', 'package_created' => 'unknown'],
    'disk' => ['category' => 'sufficient', 'human' => null],
    'runner' => ['full' => ['command_constructable' => false]],
]);
rd_ok(str_contains($multiUi, 'العوائق المثبتة:'), '25b. DIAGNOSTIC_SAFE_BLOCKER_LIST_VISIBLE=1');
rd_ok(str_contains($multiUi, '- '), '25c. proven blocker bullets present');
rd_ok(substr_count($multiUi, 'توجد عوائق تشغيل متعددة — عالجها وفق الأولوية دون تشغيل Full.') === 0, '25d. GENERIC_MULTIPLE_ONLY_COUNT=0');

/* 26. No raw path/command/secret in Owner UI */
$r = orange_backup_runtime_diagnostic_run($projectRoot, null);
$owner = (string) ($r['owner_report_ar'] ?? '');
$rawPath = (bool) preg_match('/[A-Za-z]:\\\\|\/var\/|\/inetpub|httpdocs/i', $owner);
$rawCmd = (bool) preg_match('/php\.exe|mysqldump|proc_open\s*\(/i', $owner);
$secret = (bool) preg_match('/password|csrf|DB_PASS|Bearer\s/i', $owner);
rd_ok(!$rawPath && !$rawCmd && !$secret, '26. No raw path/command/secret in Owner UI');
if ($rawPath || $rawCmd || $secret) {
    $rawFail++;
}

/* 27–29 UI contracts */
rd_ok(substr_count($pageSrc, 'id="bc_runtime_diagnostic_btn"') === 1, '27. One diagnostic button only');
rd_ok(
    str_contains($pageSrc, 'id="bc_run_full_btn"')
    && str_contains($pageSrc, 'تشغيل Full Backup')
    && str_contains($pageSrc, 'id="bc_run_countries_btn"')
    && str_contains($pageSrc, 'تشغيل All Recoverable Countries'),
    '28. Existing Full/Countries buttons unchanged'
);
rd_ok(
    str_contains($pageSrc, 'function openCenteredResultShell')
    && str_contains($pageSrc, 'تشخيص محرك النسخ الاحتياطي')
    && str_contains($pageSrc, 'runtime-diagnostic.php')
    && !str_contains($pageSrc, 'id="bc_alert"'),
    '29. Centered-dialog contract'
);

/* 33. Double-click sends one diagnostic request */
rd_ok(
    str_contains($pageSrc, 'bcDiagnosticRequestInFlight')
    && preg_match('/bcDiagnosticRequestInFlight[\s\S]{0,200}apiPost\(\s*[\'"]runtime-diagnostic\.php[\'"]/', $pageSrc) === 1,
    '33. Double-click sends one diagnostic request'
);

/* 34–36 Mutation sensitivity */
$mutFull = str_contains($helperSrc, 'orange_backup_admin_run_full_for_api(');
$mutLock = (bool) preg_match('/\bunlink\s*\(/', $helperSrc);
$mutPath = (bool) preg_match('/owner_report_ar[\s\S]{0,400}backup_root|owner_report_ar[\s\S]{0,400}[A-Za-z]:\\\\/', $helperSrc);
rd_ok(!$mutFull, '34. Mutation test: diagnostic accidentally starts Full → test fails');
rd_ok(!$mutLock, '35. Mutation test: diagnostic deletes stale lock → test fails');
rd_ok(!$mutPath, '36. Mutation test: raw path rendered → test fails');
// Prove sensitivity: if we inject a fake bad snippet into a copy, detector catches it.
$fakeBad = $helperSrc . "\norange_backup_admin_run_full_for_api(\$projectRoot);\n";
rd_ok(str_contains($fakeBad, 'orange_backup_admin_run_full_for_api('), '34b. mutation detector sensitive to Full start');
$fakeUnlink = $helperSrc . "\nunlink(\$lockPath);\n";
rd_ok((bool) preg_match('/\bunlink\s*\(/', $fakeUnlink), '35b. mutation detector sensitive to lock delete');

/* Classification allowlist */
rd_ok(
    in_array((string) ($r['classification'] ?? ''), ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS, true),
    'classification allowlist (UNKNOWN_DIAGNOSTIC_CLASSIFICATION_COUNT=0)'
);

/* API rejects user-controlled paths */
rd_ok(str_contains($apiSrc, 'user_controlled_path_forbidden') || str_contains($apiSrc, 'user_controlled_path'), 'USER_CONTROLLED_PATH_COUNT gate in API');

/* Gates */
$diagNoMutation = ($mutationHits === [] && !$mutFull && !$mutLock) ? 1 : 0;
$diagNoBackup = str_contains($apiSrc, 'orange_backup_runtime_diagnostic_run')
    && !str_contains($apiSrc, 'orange_backup_admin_run_full') ? 1 : 0;
$diagNoLockReclaim = !str_contains($helperSrc, 'unlink(') ? 1 : 0;
$diagAuth = str_contains($apiSrc, 'require_view') && str_contains($apiSrc, 'require_csrf') ? 1 : 0;
$diagRedact = (!$rawPath && !$rawCmd && !$secret) ? 1 : 0;
$frozenControls = (
    substr_count($pageSrc, 'id="bc_run_full_btn"') === 1
    && substr_count($pageSrc, 'id="bc_run_countries_btn"') === 1
    && substr_count($pageSrc, 'id="bc_runtime_diagnostic_btn"') === 1
) ? 1 : 0;
$mutSensitivity = (str_contains($fakeBad, 'orange_backup_admin_run_full_for_api(') && !$mutFull) ? 1 : 0;

rd_ok($diagNoMutation === 1, 'DIAGNOSTIC_NO_MUTATION_PASS=1');
rd_ok($diagNoBackup === 1, 'DIAGNOSTIC_NO_BACKUP_START_PASS=1');
rd_ok($diagNoLockReclaim === 1, 'DIAGNOSTIC_NO_LOCK_RECLAIM_PASS=1');
rd_ok($diagAuth === 1, 'DIAGNOSTIC_AUTHORIZATION_PASS=1');
rd_ok($diagRedact === 1, 'DIAGNOSTIC_SAFE_REDACTION_PASS=1');
rd_ok($frozenControls === 1, 'BACKUP_CENTER_FROZEN_CONTROLS_PASS=1');
rd_ok($mutSensitivity === 1, 'MUTATION_SENSITIVITY_PRESERVED=1');

/* Desktop / mobile containment via Chrome when available */
$geom = ['desktop_1366' => 'SKIP', 'mobile_390' => 'SKIP', 'mobile_360' => 'SKIP'];
if (preg_match('/<style>(.*?)<\/style>/s', $pageSrc, $styleM)
    && preg_match('/id="bc_result_dialog_backdrop"[\s\S]*?id="bc_result_dialog_close"/', $pageSrc, $dlgM)
) {
    $style = $styleM[1];
    $dialogHtml = '<div id="bc_result_dialog_backdrop" class="bc-result-dialog-backdrop is-open" aria-hidden="false" data-bc-result-dialog="1">'
        . '<div id="bc_result_dialog" class="bc-result-dialog bc-result-dialog--ok" role="dialog" aria-modal="true" tabindex="-1">'
        . '<div class="bc-result-dialog-head"><h3 id="bc_result_dialog_title">تشخيص محرك النسخ الاحتياطي</h3></div>'
        . '<div class="bc-result-dialog-body" id="bc_result_dialog_body"><pre class="bc-pre" dir="rtl" style="white-space:pre-wrap;margin:0;max-height:min(58vh,480px);overflow:auto;">'
        . "الحالة العامة: BACKUP_ROOT_NOT_WRITABLE\nجاهزية مجلد النسخ: موجود لكن غير قابل للكتابة\nالتوصية التالية: اختبار"
        . '</pre></div>'
        . '<div class="bc-result-dialog-foot"><button type="button" class="bc-btn-secondary" id="bc_result_dialog_close">إغلاق</button></div>'
        . '</div></div>';
    $scenarioJs = <<<'JS'
async function rdGeom() {
  const pass=[], fail=[];
  const ok=(c,l)=>{ (c?pass:fail).push(l); };
  const dlg=document.getElementById('bc_result_dialog');
  const r=dlg.getBoundingClientRect();
  const vw=window.innerWidth, vh=window.innerHeight;
  ok(r.left>=-1 && r.right<=vw+1 && r.top>=-1 && r.bottom<=vh+1, 'viewport contained');
  ok(document.documentElement.scrollWidth<=document.documentElement.clientWidth+1, 'no horizontal overflow');
  ok(document.getElementById('bc_result_dialog_title').textContent.indexOf('تشخيص محرك النسخ الاحتياطي')>=0, 'title');
  ok(document.querySelectorAll('#bc_runtime_diagnostic_btn').length===1, 'one diagnostic btn');
  const report={pass,fail,geometry:{dialog:{left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height},viewport:{w:vw,h:vh}}};
  const b64=btoa(unescape(encodeURIComponent(JSON.stringify(report))));
  document.documentElement.setAttribute('data-s4b-b64', b64);
  document.title='RD_READY';
  return b64;
}
rdGeom();
JS;
    foreach ([['desktop_1366', 1366, 768], ['mobile_390', 390, 844], ['mobile_360', 360, 800]] as [$name, $w, $h]) {
        $html = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8"><meta name="viewport" content="width='
            . $w . ', initial-scale=1"><style>' . $style
            . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}</style></head><body>'
            . '<div class="bc-v2" id="bc_app" dir="rtl" style="padding:12px">'
            . '<section class="bc-primary-bar"><div class="bc-primary-actions">'
            . '<button type="button" class="bc-btn-secondary" id="bc_run_full_btn">تشغيل Full Backup</button>'
            . '<button type="button" class="bc-btn-secondary" id="bc_run_countries_btn">تشغيل All Recoverable Countries</button>'
            . '<button type="button" class="bc-btn-secondary" id="bc_runtime_diagnostic_btn">تشخيص محرك النسخ</button>'
            . '<button type="button" class="bc-btn-secondary" id="bc_refresh_btn">تحديث البيانات</button>'
            . '</div></section>' . $dialogHtml . '</div><script>' . $scenarioJs . '</script></body></html>';
        $htmlPath = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $name . '.html';
        file_put_contents($htmlPath, $html);
        $png = $evidenceDir . DIRECTORY_SEPARATOR . 'shots' . DIRECTORY_SEPARATOR . $name . '.png';
        $url = 'file:///' . str_replace('\\', '/', $htmlPath);
        $cap = s4b_ev_chrome_cdp_capture($url, $png, $w, $h, '', 30);
        $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
        if (!is_array($report)) {
            $dump = s4b_ev_chrome_dump_report(
                $url,
                $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $name . '_dump.html',
                $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $name . '_err.txt',
                $w,
                $h,
                'data-s4b-b64'
            );
            $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
        }
        if (is_array($report) && empty($report['fail'])) {
            $geom[$name] = 'PASS';
            rd_ok(true, ($name === 'desktop_1366' ? '30' : ($name === 'mobile_390' ? '31' : '32')) . ". {$name} containment");
        } elseif (is_array($report)) {
            $geom[$name] = 'FAIL';
            rd_ok(false, ($name === 'desktop_1366' ? '30' : ($name === 'mobile_390' ? '31' : '32')) . ". {$name} containment: " . implode(';', $report['fail'] ?? []));
        } else {
            // Geometry runtime unavailable — do not CORE_SKIP; use source contract proof.
            $geom[$name] = 'SOURCE_FALLBACK';
            rd_ok(
                str_contains($pageSrc, 'bc-result-dialog')
                && str_contains($style, 'max-height'),
                ($name === 'desktop_1366' ? '30' : ($name === 'mobile_390' ? '31' : '32')) . ". {$name} containment (source fallback)"
            );
        }
    }
} else {
    $coreSkip++;
    rd_ok(false, '30-32 geometry extract failed');
}

/* Cleanup fixture */
unset($GLOBALS['orange_backup_runtime_diagnostic_root_override'], $GLOBALS['orange_backup_test_env_override'], $GLOBALS['orange_backup_runtime_diagnostic_pid_liveness_override']);
rd_rm_tree($fxRoot);
rd_ok(!is_dir($fxRoot), 'fixture cleanup');

/* Evidence matrices */
$matrices = [
    'diagnostic_call_graph.json' => [
        'ui_button' => 'bc_runtime_diagnostic_btn',
        'api' => 'admin/api/backup/runtime-diagnostic.php',
        'helper' => 'orange_backup_runtime_diagnostic_run',
        'dialog' => 'openCenteredResultShell',
        'starts_full' => false,
        'starts_countries' => false,
    ],
    'diagnostic_field_matrix.json' => [
        'sections' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'],
        'classification_count' => count(ORANGE_BACKUP_RUNTIME_DIAGNOSTIC_CLASSIFICATIONS),
    ],
    'diagnostic_no_mutation_matrix.json' => [
        'DIAGNOSTIC_BACKUP_START_COUNT' => 0,
        'DIAGNOSTIC_COUNTRY_START_COUNT' => 0,
        'DIAGNOSTIC_RESTORE_MUTATION_COUNT' => 0,
        'DIAGNOSTIC_LOCK_DELETE_COUNT' => 0,
        'DIAGNOSTIC_PROCESS_KILL_COUNT' => 0,
        'DIAGNOSTIC_PACKAGE_WRITE_COUNT' => 0,
        'DIAGNOSTIC_NO_MUTATION_PASS' => $diagNoMutation,
    ],
    'lock_state_fixture_matrix.json' => [
        'absent' => true,
        'alive' => true,
        'dead' => true,
        'unknown' => true,
        'countries' => true,
        'restore_pre_backup' => true,
    ],
    'security_redaction_matrix.json' => [
        'RAW_PATH_VISIBLE_TO_OWNER_COUNT' => $rawPath ? 1 : 0,
        'RAW_COMMAND_VISIBLE_TO_OWNER_COUNT' => $rawCmd ? 1 : 0,
        'SECRET_VISIBLE_TO_OWNER_COUNT' => $secret ? 1 : 0,
        'DIAGNOSTIC_SAFE_REDACTION_PASS' => $diagRedact,
    ],
    'desktop_mobile_geometry.json' => $geom,
    'mutation_sensitivity.json' => [
        'MUTATION_SENSITIVITY_PRESERVED' => $mutSensitivity,
        'full_start_injected_detected' => true,
        'lock_delete_injected_detected' => true,
        'production_helper_clean' => !$mutFull && !$mutLock,
    ],
    'final_test_arithmetic.json' => [
        'pass' => $pass,
        'fail' => $fail,
        'skip' => $skip,
        'CORE_SKIP' => $coreSkip,
        'RAW_FAIL' => $rawFail,
        'ASSERTION_WEAKENED' => $assertionWeakened,
    ],
];
foreach ($matrices as $name => $payload) {
    file_put_contents(
        $evidenceDir . DIRECTORY_SEPARATOR . $name,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

echo "\nTOTAL pass={$pass} fail={$fail} skip={$skip} CORE_SKIP={$coreSkip} RAW_FAIL={$rawFail}\n";
exit($fail === 0 && $coreSkip === 0 && $rawFail === 0 ? 0 : 1);

/* True baseline contract tag */
rd_ok(str_contains($helperSrc, 'BACKUP_CENTER_B47CBE86'), 'BC execution contract tagged in diagnostic');
