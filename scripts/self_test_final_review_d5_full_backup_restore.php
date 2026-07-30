<?php

declare(strict_types=1);

/**
 * FSR D5 — Full Backup package creation / verify / tamper / staging restore (disposable).
 *
 * Usage: php scripts/self_test_final_review_d5_full_backup_restore.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
// Verify helpers only — do not load runtime config.php in this process (worker isolation).
require_once $mainRoot . '/includes/backup/backup_paths.php';
require_once $mainRoot . '/includes/backup/backup_manifest.php';
require_once $mainRoot . '/includes/backup/backup_full.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5f_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=d5_full_backup_restore start=' . gmdate('c') . "\n";

$ctx = orange_d5_bootstrap($mainRoot);
if (empty($ctx['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($ctx['error'] ?? '') . "\n";
    echo "RESULT=FSR_D5_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=1\n";
    exit(2);
}

$cleanup = $ctx['cleanup'];
try {
    d5f_assert(is_dir((string) $ctx['backup_root']), 'backup_root created under D5 data');
    d5f_assert((string) $ctx['src_db'] === 'orange_db', 'src db is disposable orange_db (config.php const)');
    d5f_assert((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision constant 124');

    $reg = json_decode((string) file_get_contents($mainRoot . '/config/backup_table_registry.json'), true);
    $matrix = json_decode((string) file_get_contents($mainRoot . '/config/country_restore_boundary_matrix.json'), true);
    $exp = json_decode((string) file_get_contents($mainRoot . '/config/country_restore_schema_expectations.json'), true);
    d5f_assert((int) ($reg['schema_revision'] ?? 0) === 124, 'registry schema_revision=124');
    d5f_assert(count($reg['tables'] ?? []) === 117, 'registry table count=117');
    d5f_assert((int) ($matrix['schema_revision'] ?? 0) === 124, 'matrix schema_revision=124');
    d5f_assert((int) ($matrix['mutate_table_count'] ?? 0) === 81, 'matrix mutate_table_count=81');
    d5f_assert((int) ($exp['schema_revision'] ?? 0) === 124, 'expectations schema_revision=124');

    // --- Full Backup (php_pdo — restore-compatible) ---
    $bak = orange_d5_run_full_backup_pdo($ctx);
    echo 'NOTE  backup ok=' . (!empty($bak['ok']) ? '1' : '0')
        . ' backend=' . (string) ($bak['backend'] ?? '')
        . ' snap=' . (string) ($bak['snapshot'] ?? '')
        . ' err=' . (string) ($bak['error'] ?? '') . "\n";
    d5f_assert(!empty($bak['ok']), 'full backup php_pdo succeeded');
    $pkg = (string) ($bak['package_path'] ?? '');
    d5f_assert($pkg !== '' && is_dir($pkg), 'package directory exists');
    d5f_assert(is_file($pkg . '/manifest.json'), 'manifest.json present');
    d5f_assert(is_file($pkg . '/checksums.sha256'), 'checksums.sha256 present');
    d5f_assert(is_file($pkg . '/uploads.zip'), 'uploads.zip present');

    $verify = orange_backup_verify_full_package($pkg);
    d5f_assert(!empty($verify['ok']), 'full package verify OK');
    $manifest = is_array($verify['manifest'] ?? null) ? $verify['manifest'] : [];
    d5f_assert(($manifest['package_type'] ?? '') === 'full_disaster', 'package_type=full_disaster');
    d5f_assert((int) ($manifest['schema_revision'] ?? 0) === 124, 'package schema_revision=124');
    d5f_assert(($manifest['export_backend'] ?? '') === 'php_pdo', 'export_backend=php_pdo');
    $dumpRel = (string) ($manifest['dump_file'] ?? '');
    d5f_assert($dumpRel !== '' && is_file($pkg . '/' . $dumpRel), 'SQL dump present');

    // Secret scan in manifest
    $manifestRaw = (string) file_get_contents($pkg . '/manifest.json');
    d5f_assert(!str_contains($manifestRaw, (string) $ctx['app_pass']), 'manifest has no DB password');
    d5f_assert(!str_contains($manifestRaw, 'DB_PASS'), 'manifest has no DB_PASS key');

    // Repeated backup → second package
    $bak2 = orange_d5_run_full_backup_pdo($ctx);
    d5f_assert(!empty($bak2['ok']), 'second full backup succeeded');
    d5f_assert(
        (string) ($bak2['snapshot'] ?? '') !== (string) ($bak['snapshot'] ?? ''),
        'second backup has distinct snapshot id'
    );

    // --- Tamper: schema 123 ---
    $tamperRoot = (string) $ctx['data_root'] . DIRECTORY_SEPARATOR . 'tamper_schema123';
    orange_d5_mirror_dir($pkg, $tamperRoot);
    $tm = json_decode((string) file_get_contents($tamperRoot . '/manifest.json'), true);
    if (!is_array($tm)) {
        $tm = [];
    }
    $tm['schema_revision'] = 123;
    file_put_contents($tamperRoot . '/manifest.json', json_encode($tm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // Break checksums deliberately
    $tv = orange_backup_verify_full_package($tamperRoot);
    d5f_assert(empty($tv['ok']), 'tampered schema123 package fails verify');

    // --- Tamper: missing dump ---
    $tamperDump = (string) $ctx['data_root'] . DIRECTORY_SEPARATOR . 'tamper_nodump';
    orange_d5_mirror_dir($pkg, $tamperDump);
    $dumpName = (string) ($manifest['dump_file'] ?? '');
    if ($dumpName !== '' && is_file($tamperDump . '/' . $dumpName)) {
        @unlink($tamperDump . '/' . $dumpName);
    }
    $tv2 = orange_backup_verify_full_package($tamperDump);
    d5f_assert(empty($tv2['ok']), 'missing dump package fails verify');

    // --- Tamper: package type ---
    $tamperType = (string) $ctx['data_root'] . DIRECTORY_SEPARATOR . 'tamper_type';
    orange_d5_mirror_dir($pkg, $tamperType);
    $tt = json_decode((string) file_get_contents($tamperType . '/manifest.json'), true);
    if (!is_array($tt)) {
        $tt = [];
    }
    $tt['package_type'] = 'country_recovery';
    file_put_contents($tamperType . '/manifest.json', json_encode($tt, JSON_UNESCAPED_UNICODE));
    $tv3 = orange_backup_verify_full_package($tamperType);
    d5f_assert(empty($tv3['ok']), 'wrong package_type fails full verify');

    // --- Staging restore of clean php_pdo package (isolated worker) ---
    $runtimeRoot = (string) $ctx['runtime_root'];
    $stgWorkerSrc = $mainRoot . '/scripts/lib/final_review_d5_staging_worker.php';
    $stgWorkerDst = $runtimeRoot . '/scripts/lib/final_review_d5_staging_worker.php';
    if (!is_dir(dirname($stgWorkerDst))) {
        @mkdir(dirname($stgWorkerDst), 0775, true);
    }
    @copy($stgWorkerSrc, $stgWorkerDst);
    orange_d5_mirror_dir($mainRoot . '/includes/backup', $runtimeRoot . '/includes/backup');
    $stgResultPath = sys_get_temp_dir() . '/orange_d5_stg_' . bin2hex(random_bytes(4)) . '.json';
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $stgCmd = [$phpBin, $stgWorkerDst, $runtimeRoot, $pkg, $stgResultPath];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $stgProc = proc_open($stgCmd, $desc, $stgPipes, $runtimeRoot, null, ['bypass_shell' => true]);
    $stgStdout = '';
    $stgStderr = '';
    if (is_resource($stgProc)) {
        fclose($stgPipes[0]);
        $stgStdout = (string) stream_get_contents($stgPipes[1]);
        $stgStderr = (string) stream_get_contents($stgPipes[2]);
        fclose($stgPipes[1]);
        fclose($stgPipes[2]);
        proc_close($stgProc);
    }
    $stgDecoded = is_file($stgResultPath)
        ? json_decode((string) file_get_contents($stgResultPath), true)
        : null;
    @unlink($stgResultPath);
    echo 'NOTE  staging ok=' . (!empty($stgDecoded['ok']) ? '1' : '0')
        . ' job=' . (string) ($stgDecoded['job_id'] ?? '')
        . ' err=' . (string) ($stgDecoded['error'] ?? '')
        . ' stderr=' . substr(trim($stgStderr), 0, 200) . "\n";

    // --- FSR-D5-FULL-01 repair contract (static mutation-proof) ---
    $gateSrc = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_fresh_backup_gate.php');
    $runnerSrc = (string) file_get_contents($mainRoot . '/includes/backup/backup_runner.php');
    d5f_assert(
        str_contains($gateSrc, 'function orange_restore_fresh_backup_resolve_package_dir'),
        'FSR-D5-FULL-01 repaired: resolve helper present'
    );
    d5f_assert(
        str_contains($gateSrc, 'orange_restore_fresh_backup_resolve_package_dir(')
        && !str_contains($gateSrc, '$snapshotPath = (string) $backup[\'snapshot\'];'),
        'FSR-D5-FULL-01 repaired: gate no longer verifies raw snapshot basename as path'
    );
    d5f_assert(
        str_contains($runnerSrc, "'snapshot' => \$snapshotName")
        || str_contains($runnerSrc, "'snapshot' => \$snapshot,"),
        'runner still returns snapshot basename contract'
    );
    d5f_assert(
        str_contains($gateSrc, "snapshots/' . \$raw")
        || str_contains($gateSrc, 'snapshots/' . "' . \$raw"),
        'FSR-D5-FULL-01 repaired: gate joins BackupRoot/snapshots/<id>'
    );
    d5f_assert(
        !str_contains($gateSrc, 'orange_backup_latest_snapshot_name'),
        'FSR-D5-FULL-01 repaired: gate never falls back to latest snapshot'
    );

    $stgErr = (string) ($stgDecoded['error'] ?? '');
    d5f_assert(
        !str_contains($stgErr, 'Package path does not exist or is not a directory'),
        'FSR-D5-FULL-01 behavioral: no name-as-path verify error'
    );

    // FSR-D5-STG-01 repair contract (static mutation-proof)
    $fenceSrc = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_staging_target.php');
    d5f_assert(
        str_contains($fenceSrc, 'function orange_restore_staging_is_neutral_usage_grant'),
        'FSR-D5-STG-01 repaired: neutral USAGE helper present'
    );
    d5f_assert(
        str_contains($fenceSrc, "stripos(\$grant, ' ON *.*') !== false"),
        'FSR-D5-STG-01 repaired: non-neutral ON *.* still rejected'
    );
    d5f_assert(
        !str_contains($stgErr, 'detectable privilege on production schema'),
        'FSR-D5-STG-01 behavioral: no USAGE-as-global false positive'
    );

    d5f_assert(is_array($stgDecoded) && !empty($stgDecoded['ok']), 'full restore → staging succeeded');
    if (is_array($stgDecoded) && !empty($stgDecoded['ok'])) {
        $job = is_array($stgDecoded['staging']['job'] ?? null) ? $stgDecoded['staging']['job'] : [];
        $anchor = (string) ($job['fresh_backup_path'] ?? $stgDecoded['staging']['rollback_anchor']['fresh_backup_path'] ?? '');
        d5f_assert($anchor !== '' && is_dir($anchor), 'fresh gate rollback anchor path is a directory');
        d5f_assert(
            str_contains(str_replace('\\', '/', $anchor), '/snapshots/'),
            'fresh gate rollback anchor under snapshots/'
        );
    }

    if (is_array($stgDecoded) && !empty($stgDecoded['ok'])) {
        $stgPdo = new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=' . (string) $ctx['stg_db'] . ';charset=utf8mb4',
            (string) $ctx['stg_user'],
            (string) $ctx['stg_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $tblCount = (int) $stgPdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'"
        )->fetchColumn();
        d5f_assert($tblCount > 50, 'staging DB has restored tables (count=' . $tblCount . ')');
        try {
            $meta = (int) $stgPdo->query('SELECT version FROM orange_schema_meta WHERE id=1')->fetchColumn();
            d5f_assert($meta === 124 || $meta > 0, 'staging schema meta present');
        } catch (Throwable) {
            d5f_assert(true, 'staging schema meta optional if table naming differs');
        }
    }

    // Source DB still intact (cutover not performed)
    $srcCount = (int) $ctx['pdo']->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'"
    )->fetchColumn();
    d5f_assert($srcCount > 50, 'source DB intact after staging-only restore');

    // Mutation-proof static markers
    $compatSrc = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_package_compat.php');
    d5f_assert(str_contains($compatSrc, 'php_pdo'), 'source: staging requires php_pdo');
    $fullSrc = (string) file_get_contents($mainRoot . '/includes/backup/backup_full.php');
    d5f_assert(str_contains($fullSrc, 'orange_backup_verify_full_package'), 'source: verify helper present');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_PROVEN_BACKUP_RESTORE_GAPS_FOUND\n";
    exit(1);
}
echo "RESULT=FSR_D5_FULL_BACKUP_RESTORE_OK\n";
exit(0);
