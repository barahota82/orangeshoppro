<?php

declare(strict_types=1);

/**
 * FSR D5 — Country Export → Shadow → Shadow Verify → Dry Run (disposable).
 * Country Production Cutover remains hard-disabled.
 *
 * Usage: php scripts/self_test_final_review_d5_country_shadow_dry_run.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
require_once $mainRoot . '/includes/backup/country_crp_verify.php';
require_once $mainRoot . '/includes/backup/country_crp_drv.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5sh_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=d5_country_shadow_dry_run start=' . gmdate('c') . "\n";

$ctx = orange_d5_bootstrap($mainRoot);
if (empty($ctx['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($ctx['error'] ?? '') . "\n";
    echo "RESULT=FSR_D5_ENVIRONMENT_BLOCKER\n";
    exit(2);
}

$cleanup = $ctx['cleanup'];
try {
    d5sh_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country production restore hard-disabled');

    $runtimeRoot = (string) $ctx['runtime_root'];
    $backupRoot = (string) $ctx['backup_root'];
    $mainRootReal = (string) $ctx['main_root'];
    orange_d5_mirror_dir($mainRootReal . '/includes/backup', $runtimeRoot . '/includes/backup');
    orange_d5_mirror_dir($mainRootReal . '/config', $runtimeRoot . '/config');

    try {
        orange_d5_clone_database((string) $ctx['src_db'], (string) $ctx['shd_db']);
        d5sh_assert(true, 'shadow DB cloned from disposable source');
    } catch (Throwable $e) {
        d5sh_assert(false, 'shadow DB cloned: ' . $e->getMessage());
    }

    $pdo = $ctx['pdo'];
    $kwId = (int) $pdo->query("SELECT id FROM countries WHERE code IN ('KW','kw') ORDER BY id ASC LIMIT 1")->fetchColumn();
    $egId = (int) $pdo->query("SELECT id FROM countries WHERE code IN ('EG','eg') ORDER BY id ASC LIMIT 1")->fetchColumn();
    d5sh_assert($kwId > 0 && $egId > 0 && $kwId !== $egId, 'KW/EG country ids distinct');

    $worker = <<<'PHP'
<?php
declare(strict_types=1);
$runtime=$argv[1]; $countryId=(int)$argv[2]; $out=$argv[3];
require_once $runtime.'/config.php';
require_once $runtime.'/includes/catalog_schema.php';
require_once $runtime.'/includes/backup/country_export.php';
$flag=sys_get_temp_dir().'/orange_d5_schema_ok_shce_'.getmypid().'.flag';
file_put_contents($flag,'124');
putenv('ORANGE_SCHEMA_OK_FLAG_PATH='.$flag);
$_ENV['ORANGE_SCHEMA_OK_FLAG_PATH']=$flag;
try {
  $r=orange_country_export_run(db(),['country_id'=>$countryId,'project_root'=>$runtime]);
  file_put_contents($out,json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  exit(!empty($r['ok'])?0:1);
} catch (Throwable $e) {
  file_put_contents($out,json_encode(['ok'=>false,'message'=>$e->getMessage()]));
  exit(1);
}
PHP;
    $workerPath = (string) $ctx['data_root'] . '/d5_sh_export_worker.php';
    file_put_contents($workerPath, $worker);
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $outKw = sys_get_temp_dir() . '/orange_d5_sh_kw_' . bin2hex(random_bytes(3)) . '.json';
    $p = proc_open([$phpBin, $workerPath, $runtimeRoot, (string) $kwId, $outKw], $desc, $pipes, $runtimeRoot, null, ['bypass_shell' => true]);
    if (is_resource($p)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }
    $expKw = is_file($outKw) ? json_decode((string) file_get_contents($outKw), true) : null;
    @unlink($outKw);
    d5sh_assert(!empty($expKw['ok']), 'KW country export for shadow');
    $pkgKw = (string) ($expKw['package_path'] ?? '');
    $packageId = $pkgKw !== '' ? basename(str_replace('\\', '/', $pkgKw)) : '';
    d5sh_assert($packageId !== '' && is_dir($pkgKw), 'KW package_id/dir resolved');

    $verify = orange_crp_verify_run($pkgKw, ['project_root' => $mainRootReal]);
    d5sh_assert(!empty($verify['ok']), 'CRP verify KW before shadow');
    d5sh_assert(
        is_file($pkgKw . '/' . ORANGE_CRP_VERIFY_REPORT_FILENAME),
        'verify report persisted in package'
    );
    $drv = orange_country_drv_run($pkgKw, [
        'project_root' => $mainRootReal,
        'country_id' => $kwId,
    ]);
    $drvOverall = strtolower((string) ($drv['overall_result'] ?? ''));
    echo 'NOTE  DRV score=' . (int) ($drv['recovery_score'] ?? 0) . ' overall=' . $drvOverall . "\n";
    d5sh_assert($drvOverall === 'pass', 'DRV pass before shadow');
    $drvReport = dirname($pkgKw) . DIRECTORY_SEPARATOR . $packageId . '.' . ORANGE_COUNTRY_DRV_REPORT_SUFFIX;
    d5sh_assert(is_file($drvReport), 'DRV report file present beside package');

    $shWorkerSrc = $mainRootReal . '/scripts/lib/final_review_d5_country_shadow_worker.php';
    $shWorkerDst = $runtimeRoot . '/scripts/lib/final_review_d5_country_shadow_worker.php';
    if (!is_dir(dirname($shWorkerDst))) {
        @mkdir(dirname($shWorkerDst), 0775, true);
    }
    @copy($shWorkerSrc, $shWorkerDst);

    $shOut = sys_get_temp_dir() . '/orange_d5_sh_run_' . bin2hex(random_bytes(3)) . '.json';
    $sp = proc_open([$phpBin, $shWorkerDst, $runtimeRoot, $packageId, 'kw', $shOut], $desc, $spipes, $runtimeRoot, null, ['bypass_shell' => true]);
    $shStderr = '';
    if (is_resource($sp)) {
        fclose($spipes[0]);
        stream_get_contents($spipes[1]);
        $shStderr = (string) stream_get_contents($spipes[2]);
        fclose($spipes[1]);
        fclose($spipes[2]);
        proc_close($sp);
    }
    $sh = is_file($shOut) ? json_decode((string) file_get_contents($shOut), true) : null;
    @unlink($shOut);
    echo 'NOTE  shadow_pipeline ok=' . (!empty($sh['ok']) ? '1' : '0')
        . ' err=' . (string) ($sh['error'] ?? '')
        . ' shadow=' . json_encode($sh['shadow'] ?? [], JSON_UNESCAPED_SLASHES)
        . ' verify=' . json_encode($sh['verify'] ?? [], JSON_UNESCAPED_SLASHES)
        . ' dry=' . json_encode($sh['dry_run'] ?? [], JSON_UNESCAPED_SLASHES)
        . ' stderr=' . substr(trim($shStderr), 0, 220) . "\n";

    $schemaCols = is_array($sh['verify']['schema_column_missing'] ?? null)
        ? $sh['verify']['schema_column_missing']
        : [];
    if ($schemaCols !== []) {
        echo 'NOTE  schema_column_missing_details=' . json_encode($schemaCols, JSON_UNESCAPED_SLASHES) . "\n";
    }

    d5sh_assert(is_array($sh) && !empty($sh['shadow']['ok']), 'country shadow restore KW');
    d5sh_assert(empty($sh['shadow']['production_touched']), 'shadow production_touched=false');
    d5sh_assert(!empty($sh['verify']['ok']), 'country shadow verify KW');
    d5sh_assert(!empty($sh['dry_run']['ok']), 'country dry run KW');
    d5sh_assert(($sh['production_enabled'] ?? true) === false, 'worker observes production restore disabled');
    d5sh_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'cutover still hard-disabled after shadow path');
    echo "NOTE  country_production_cutover=NOT_IMPLEMENTED_HARD_DISABLED\n";
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_COUNTRY_SHADOW_GAPS\n";
    exit(1);
}
echo "RESULT=FSR_D5_COUNTRY_SHADOW_DRY_RUN_OK\n";
exit(0);
