<?php

declare(strict_types=1);

/**
 * FSR D5 — focused Fresh Backup Gate path-contract / containment tests.
 *
 * Usage: php scripts/self_test_final_review_d5_fresh_gate_path.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
require_once $mainRoot . '/includes/backup/restore/restore_fresh_backup_gate.php';
require_once $mainRoot . '/includes/backup/backup_full.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5g_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=d5_fresh_gate_path start=' . gmdate('c') . "\n";

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

    // Create real php_pdo package via isolated worker.
    $bak = orange_d5_run_full_backup_pdo($ctx);
    d5g_assert(!empty($bak['ok']), 'php_pdo backup created');
    $snapId = (string) ($bak['snapshot'] ?? '');
    d5g_assert(preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $snapId) === 1, 'runner snapshot is approved basename');
    d5g_assert(
        !str_contains($snapId, '/') && !str_contains($snapId, '\\'),
        'runner snapshot has no path separators'
    );

    $resolved = orange_restore_fresh_backup_resolve_package_dir($runtimeRoot, $snapId, $backupRoot);
    d5g_assert(!empty($resolved['ok']), 'resolve succeeds for exact runner snapshot');
    $path = (string) ($resolved['path'] ?? '');
    d5g_assert($path !== '' && is_dir($path), 'resolved path exists as directory');
    $snapRoot = realpath($backupRoot . DIRECTORY_SEPARATOR . 'snapshots') ?: '';
    $pathNorm = strtolower(str_replace('\\', '/', $path));
    $rootNorm = strtolower(str_replace('\\', '/', $snapRoot));
    d5g_assert($rootNorm !== '' && str_starts_with($pathNorm, $rootNorm . '/'), 'resolved under snapshots root');
    d5g_assert(basename(str_replace('\\', '/', $path)) === $snapId, 'directory leaf equals snapshot id');

    $verify = orange_backup_verify_full_package($path);
    d5g_assert(!empty($verify['ok']), 'full package verify on resolved path');
    $manifest = is_array($verify['manifest'] ?? null) ? $verify['manifest'] : [];
    d5g_assert(($manifest['package_type'] ?? '') === 'full_disaster', 'package_type=full_disaster');
    d5g_assert((int) ($manifest['schema_revision'] ?? 0) === 124, 'schema_revision=124');

    // Live Fresh Gate (creates a SECOND distinct safety package).
    // Mirror Production includes into runtime for gate helpers.
    orange_d5_mirror_dir($mainRoot . '/includes/backup', $runtimeRoot . '/includes/backup');
    $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_gate_' . getmypid() . '.flag';
    file_put_contents($flagPath, '124');
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    // Gate must run inside runtime process for .env.php — use a tiny worker.
    $gateWorker = <<<'PHP'
<?php
declare(strict_types=1);
$runtime=$argv[1]; $backupRoot=$argv[2]; $out=$argv[3];
require_once $runtime.'/config.php';
require_once $runtime.'/includes/catalog_schema.php';
require_once $runtime.'/includes/backup/restore/restore_fresh_backup_gate.php';
$flag=sys_get_temp_dir().'/orange_d5_schema_ok_gw_'.getmypid().'.flag';
file_put_contents($flag,'124');
putenv('ORANGE_SCHEMA_OK_FLAG_PATH='.$flag);
$_ENV['ORANGE_SCHEMA_OK_FLAG_PATH']=$flag;
try {
  $r=orange_restore_fresh_backup_gate($runtime,$backupRoot);
  file_put_contents($out,json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  exit(!empty($r['ok'])?0:1);
} catch (Throwable $e) {
  file_put_contents($out,json_encode(['ok'=>false,'errors'=>[$e->getMessage()]]));
  exit(1);
}
PHP;
    $gateWorkerPath = (string) $ctx['data_root'] . DIRECTORY_SEPARATOR . 'd5_gate_worker.php';
    file_put_contents($gateWorkerPath, $gateWorker);
    $gateOut = sys_get_temp_dir() . '/orange_d5_gate_' . bin2hex(random_bytes(4)) . '.json';
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$phpBin, $gateWorkerPath, $runtimeRoot, $backupRoot, $gateOut], $desc, $pipes, $runtimeRoot, null, ['bypass_shell' => true]);
    $stderr = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    $gate = is_file($gateOut) ? json_decode((string) file_get_contents($gateOut), true) : null;
    @unlink($gateOut);
    echo 'NOTE  gate ok=' . (!empty($gate['ok']) ? '1' : '0')
        . ' snap=' . (string) ($gate['backup']['snapshot'] ?? '')
        . ' path=' . (string) ($gate['snapshot_path'] ?? '')
        . ' err=' . implode('; ', is_array($gate['errors'] ?? null) ? $gate['errors'] : [])
        . ' stderr=' . substr(trim($stderr), 0, 160) . "\n";
    d5g_assert(is_array($gate) && !empty($gate['ok']), 'fresh gate success');
    $gatePath = (string) ($gate['snapshot_path'] ?? '');
    $gateSnap = (string) ($gate['backup']['snapshot'] ?? '');
    d5g_assert($gatePath !== '' && is_dir($gatePath), 'gate snapshot_path is directory');
    d5g_assert($gateSnap !== '' && $gateSnap !== $snapId, 'second fresh backup is distinct package id');
    d5g_assert(basename(str_replace('\\', '/', $gatePath)) === $gateSnap, 'gate path leaf matches runner snapshot id');
    d5g_assert(!str_ends_with(str_replace('\\', '/', $gatePath), '/' . $snapId), 'gate did not select stale first package');

    // Containment rejects
    $rejects = [
        'missing' => '2099-01-01_000000',
        'malformed' => 'not-a-snapshot',
        'traversal' => '../' . $snapId,
        'slash' => 'snapshots/' . $snapId,
        'backslash' => 'snapshots\\' . $snapId,
        'abs_unix' => '/tmp/' . $snapId,
        'abs_win' => 'C:\\Windows\\' . $snapId,
        'unc' => '\\\\server\\share\\' . $snapId,
        'url' => 'file:///' . $snapId,
        'nul' => $snapId . "\0x",
    ];
    foreach ($rejects as $label => $bad) {
        $r = orange_restore_fresh_backup_resolve_package_dir($runtimeRoot, $bad, $backupRoot);
        d5g_assert(empty($r['ok']), "reject {$label}");
    }

    // Deleted package fails
    $tmpId = '2099-12-31_235959';
    $tmpDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $tmpId;
    @mkdir($tmpDir, 0775, true);
    $okTmp = orange_restore_fresh_backup_resolve_package_dir($runtimeRoot, $tmpId, $backupRoot);
    d5g_assert(!empty($okTmp['ok']), 'temp snapshot dir resolves');
    orange_d5_rmdir_safe($tmpDir);
    $gone = orange_restore_fresh_backup_resolve_package_dir($runtimeRoot, $tmpId, $backupRoot);
    d5g_assert(empty($gone['ok']), 'deleted package fails resolve');

    // Wrong package type / schema 123 / tampered checksum — verify path rejects via gate helpers
    $tamper = (string) $ctx['data_root'] . DIRECTORY_SEPARATOR . 'tamper_gate';
    orange_d5_mirror_dir((string) $bak['package_path'], $tamper);
    // Place under snapshots with valid id then mutate
    $badId = '2099-06-15_120000';
    $badPkg = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $badId;
    orange_d5_mirror_dir((string) $bak['package_path'], $badPkg);
    $tm = json_decode((string) file_get_contents($badPkg . '/manifest.json'), true);
    if (!is_array($tm)) {
        $tm = [];
    }
    $tm['schema_revision'] = 123;
    file_put_contents($badPkg . '/manifest.json', json_encode($tm, JSON_UNESCAPED_UNICODE));
    $v123 = orange_backup_verify_full_package($badPkg);
    d5g_assert(empty($v123['ok']) || (int) ($v123['manifest']['schema_revision'] ?? 0) === 123, 'schema123 fixture prepared');
    // Resolve still finds dir; full gate verify/schema check would fail — exercise resolve+verify
    $r123 = orange_restore_fresh_backup_resolve_package_dir($runtimeRoot, $badId, $backupRoot);
    d5g_assert(!empty($r123['ok']), 'resolve finds schema123 dir');
    $v123b = orange_backup_verify_full_package((string) $r123['path']);
    // checksum mismatch after manifest rewrite without regenerating checksums
    d5g_assert(empty($v123b['ok']), 'tampered schema123/checksum fails verify');

    $typeId = '2099-06-15_130000';
    $typePkg = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $typeId;
    orange_d5_mirror_dir((string) $bak['package_path'], $typePkg);
    $tt = json_decode((string) file_get_contents($typePkg . '/manifest.json'), true);
    if (!is_array($tt)) {
        $tt = [];
    }
    $tt['package_type'] = 'country_recovery';
    file_put_contents($typePkg . '/manifest.json', json_encode($tt, JSON_UNESCAPED_UNICODE));
    $vType = orange_backup_verify_full_package($typePkg);
    d5g_assert(empty($vType['ok']), 'wrong package type fails full verify');

    // Mutation-proof: no latest fallback
    $gateFile = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_fresh_backup_gate.php');
    d5g_assert(!str_contains($gateFile, 'orange_backup_latest_snapshot_name'), 'no latest-snapshot fallback in gate');
    d5g_assert(str_contains($gateFile, 'orange_restore_fresh_backup_resolve_package_dir'), 'resolve helper committed in Production gate');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_FRESH_GATE_PATH_FAIL\n";
    exit(1);
}
echo "RESULT=FSR_D5_FRESH_GATE_PATH_OK\n";
exit(0);
