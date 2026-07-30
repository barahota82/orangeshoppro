<?php

declare(strict_types=1);

/**
 * FSR D5 — Country Export / Verify / DRV on disposable Schema 124 fixture.
 *
 * Usage: php scripts/self_test_final_review_d5_country_export_verify.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
require_once $mainRoot . '/includes/backup/country_export.php';
require_once $mainRoot . '/includes/backup/country_crp_verify.php';
require_once $mainRoot . '/includes/backup/country_crp_drv.php';
require_once $mainRoot . '/includes/backup/backup_full.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5c_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo 'NOTE  suite=d5_country_export_verify start=' . gmdate('c') . "\n";

$ctx = orange_d5_bootstrap($mainRoot);
if (empty($ctx['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($ctx['error'] ?? '') . "\n";
    echo "RESULT=FSR_D5_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=1\n";
    exit(2);
}

$cleanup = $ctx['cleanup'];
try {
    $runtimeRoot = (string) $ctx['runtime_root'];
    $backupRoot = (string) $ctx['backup_root'];
    orange_d5_mirror_dir($mainRoot . '/includes/backup', $runtimeRoot . '/includes/backup');
    orange_d5_mirror_dir($mainRoot . '/config', $runtimeRoot . '/config');

    $ids = is_array($ctx['ids'] ?? null) ? $ctx['ids'] : [];
    $kwId = (int) ($ids['kw_country_id'] ?? $ids['kuwait_id'] ?? 0);
    $egId = (int) ($ids['eg_country_id'] ?? $ids['egypt_id'] ?? 0);
    if ($kwId <= 0 || $egId <= 0) {
        // Resolve from DB
        $pdo = $ctx['pdo'];
        $kwId = (int) $pdo->query("SELECT id FROM countries WHERE code IN ('KW','kw') ORDER BY id ASC LIMIT 1")->fetchColumn();
        $egId = (int) $pdo->query("SELECT id FROM countries WHERE code IN ('EG','eg') ORDER BY id ASC LIMIT 1")->fetchColumn();
    }
    d5c_assert($kwId > 0 && $egId > 0 && $kwId !== $egId, 'fixture has distinct KW and EG country ids');

    $flagPath = sys_get_temp_dir() . '/orange_d5_schema_ok_c_' . getmypid() . '.flag';
    file_put_contents($flagPath, '124');
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    // Export must use runtime project root (.env.php + BackupRoot).
    $worker = <<<'PHP'
<?php
declare(strict_types=1);
$runtime=$argv[1]; $countryId=(int)$argv[2]; $out=$argv[3];
require_once $runtime.'/config.php';
require_once $runtime.'/includes/catalog_schema.php';
require_once $runtime.'/includes/backup/country_export.php';
$flag=sys_get_temp_dir().'/orange_d5_schema_ok_ce_'.getmypid().'.flag';
file_put_contents($flag,'124');
putenv('ORANGE_SCHEMA_OK_FLAG_PATH='.$flag);
$_ENV['ORANGE_SCHEMA_OK_FLAG_PATH']=$flag;
try {
  $pdo=db();
  $r=orange_country_export_run($pdo,['country_id'=>$countryId,'project_root'=>$runtime]);
  file_put_contents($out,json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  exit(!empty($r['ok'])?0:1);
} catch (Throwable $e) {
  file_put_contents($out,json_encode(['ok'=>false,'message'=>$e->getMessage()]));
  exit(1);
}
PHP;
    $workerPath = (string) $ctx['data_root'] . '/d5_country_export_worker.php';
    file_put_contents($workerPath, $worker);
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';

    $packages = [];
    foreach (['KW' => $kwId, 'EG' => $egId] as $code => $cid) {
        $outPath = sys_get_temp_dir() . '/orange_d5_ce_' . strtolower($code) . '_' . bin2hex(random_bytes(3)) . '.json';
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([$phpBin, $workerPath, $runtimeRoot, (string) $cid, $outPath], $desc, $pipes, $runtimeRoot, null, ['bypass_shell' => true]);
        $stderr = '';
        if (is_resource($proc)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        $decoded = is_file($outPath) ? json_decode((string) file_get_contents($outPath), true) : null;
        @unlink($outPath);
        echo 'NOTE  export ' . $code . ' ok=' . (!empty($decoded['ok']) ? '1' : '0')
            . ' path=' . (string) ($decoded['package_path'] ?? '')
            . ' msg=' . (string) ($decoded['message'] ?? '')
            . ' stderr=' . substr(trim($stderr), 0, 160) . "\n";
        d5c_assert(is_array($decoded) && !empty($decoded['ok']), "country export {$code} succeeded");
        $pkg = (string) ($decoded['package_path'] ?? '');
        d5c_assert($pkg !== '' && is_dir($pkg), "country package dir exists {$code}");
        d5c_assert(is_file($pkg . '/manifest.json'), "manifest present {$code}");
        $manifest = json_decode((string) file_get_contents($pkg . '/manifest.json'), true);
        d5c_assert(is_array($manifest), "manifest json {$code}");
        d5c_assert(($manifest['package_type'] ?? '') === 'country_recovery', "package_type country_recovery {$code}");
        d5c_assert((int) ($manifest['schema_revision'] ?? 0) === 124, "schema 124 {$code}");
        d5c_assert((int) ($manifest['country_id'] ?? 0) === $cid, "country_id matches {$code}");
        $packages[$code] = ['path' => $pkg, 'manifest' => $manifest, 'country_id' => $cid];
    }

    // Verify + DRV
    foreach ($packages as $code => $info) {
        $verify = orange_crp_verify_run($info['path'], ['project_root' => $mainRoot]);
        d5c_assert(!empty($verify['ok']) || strtolower((string) ($verify['overall_result'] ?? '')) === 'pass', "CRP verify {$code}");
        $drv = orange_country_drv_run($info['path'], [
            'project_root' => $mainRoot,
            'country_id' => $info['country_id'],
        ]);
        $score = (int) ($drv['recovery_score'] ?? 0);
        $overall = strtolower((string) ($drv['overall_result'] ?? ''));
        echo 'NOTE  DRV ' . $code . ' score=' . $score . ' overall=' . $overall . "\n";
        d5c_assert($overall === 'pass' || $score >= 70, "DRV pass/score {$code}");
        $drv2 = orange_country_drv_run($info['path'], [
            'project_root' => $mainRoot,
            'country_id' => $info['country_id'],
        ]);
        d5c_assert(
            (int) ($drv2['recovery_score'] ?? -1) === $score,
            "DRV deterministic {$code}"
        );
    }

    // Cross-country: KW package must not claim EG id
    if (isset($packages['KW'], $packages['EG'])) {
        $kwMan = $packages['KW']['manifest'];
        $egMan = $packages['EG']['manifest'];
        d5c_assert((int) ($kwMan['country_id'] ?? 0) !== (int) ($egMan['country_id'] ?? 0), 'KW/EG country ids differ in manifests');
        $relabel = (string) $ctx['data_root'] . '/tamper_crp_wrong_country';
        orange_d5_mirror_dir($packages['KW']['path'], $relabel);
        $rm = json_decode((string) file_get_contents($relabel . '/manifest.json'), true);
        if (!is_array($rm)) {
            $rm = [];
        }
        $rm['country_id'] = $packages['EG']['country_id'];
        $rm['country_code'] = 'eg';
        file_put_contents($relabel . '/manifest.json', json_encode($rm, JSON_UNESCAPED_UNICODE));
        $wrong = orange_crp_verify_run($relabel, ['project_root' => $mainRoot]);
        d5c_assert(
            empty($wrong['ok']) || strtolower((string) ($wrong['overall_result'] ?? '')) === 'fail',
            'wrong Country relabel fails verify'
        );
    }

    // Schema 123 ineligible: tamper KW package copy
    $tamper = (string) $ctx['data_root'] . '/tamper_crp_123';
    orange_d5_mirror_dir($packages['KW']['path'], $tamper);
    $tm = json_decode((string) file_get_contents($tamper . '/manifest.json'), true);
    if (!is_array($tm)) {
        $tm = [];
    }
    $tm['schema_revision'] = 123;
    file_put_contents($tamper . '/manifest.json', json_encode($tm, JSON_UNESCAPED_UNICODE));
    $v123 = orange_crp_verify_run($tamper, ['project_root' => $mainRoot]);
    d5c_assert(empty($v123['ok']) && strtolower((string) ($v123['overall_result'] ?? '')) !== 'pass', 'schema 123 country package fails verify');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_COUNTRY_EXPORT_VERIFY_FAIL\n";
    exit(1);
}
echo "RESULT=FSR_D5_COUNTRY_EXPORT_VERIFY_OK\n";
exit(0);
