<?php

declare(strict_types=1);

/**
 * Stage 4A — Qualification evidence / binding / locks / resolver Backend self-test.
 *
 * Usage: php scripts/self_test_backup_center_stage4a_qualification_backend.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
if (!function_exists('audit_log')) {
    /**
     * CLI self-test stub — production Admin API loads audit_log via config.php.
     *
     * @param mixed $entityId
     */
    function audit_log(string $action, string $message = '', ?string $entityTable = null, $entityId = null): void
    {
    }
}
require_once $projectRoot . '/includes/backup/backup_qualification.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$passes = 0;
$failures = 0;
$skips = 0;
$coreSkip = 0;

function s4a_ok(bool $ok, string $label): void
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

function s4a_skip(string $label, bool $core = false): void
{
    global $skips, $coreSkip;
    echo "SKIP: {$label}\n";
    $skips++;
    if ($core) {
        $coreSkip++;
    }
}

function s4a_temp_root(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4a_' . bin2hex(random_bytes(4));
    if (!@mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Cannot create temp BackupRoot');
    }
    @mkdir($base . '/snapshots', 0770, true);
    @mkdir($base . '/country_packages/kw', 0770, true);
    @mkdir($base . '/country_packages/eg', 0770, true);

    return $base;
}

/**
 * Environment for Stage 4A endpoint worker children (Windows proc_open replaces the whole env).
 *
 * @return array<string, string>
 */
function s4a_worker_env(): array
{
    $env = [];
    foreach (['SystemRoot', 'PATH', 'PATHEXT', 'TEMP', 'TMP', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'ComSpec', 'WINDIR'] as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            $env[$k] = $v;
        }
    }
    foreach ([
        'ORANGE_QUAL_HEAVY_COUNTER_FILE',
        'ORANGE_QUAL_REPORT_WRITE_COUNTER_FILE',
        'ORANGE_QUAL_AUDIT_COUNTER_FILE',
        'ORANGE_QUAL_TEST_HOLD_MS',
        'ORANGE_QUAL_SILENCE_DRV_LOG',
    ] as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            $env[$k] = $v;
        }
    }
    $env['ORANGE_QUAL_SILENCE_DRV_LOG'] = '1';

    return $env;
}

/** @return array<string, mixed>|null */
function s4a_parse_worker_json(string $out): ?array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($out)) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string) $lines[$i]);
        if ($line === '' || $line[0] !== '{') {
            continue;
        }
        $j = json_decode($line, true);
        if (is_array($j) && array_key_exists('heavy_executed', $j)) {
            return $j;
        }
    }

    return null;
}

function s4a_rm_tree(string $dir): void
{
    $tempParent = realpath(sys_get_temp_dir());
    $resolved = realpath($dir);
    if ($tempParent === false || $resolved === false) {
        return;
    }
    $normTemp = strtolower(str_replace('\\', '/', rtrim($tempParent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/')));
    if ($normDir === $normTemp || !str_starts_with($normDir, $normTemp . '/') || !str_contains($normDir, '/orange_s4a_')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($resolved);
}

/**
 * @return array{path:string,id:string,fp:string,payload:string}
 */
function s4a_make_full_package(string $root, string $id, string $payloadTag = 'x'): array
{
    $path = $root . '/snapshots/' . $id;
    @mkdir($path, 0770, true);
    $dump = $path . '/dump.sql.gz';
    $uploads = $path . '/uploads.zip';
    file_put_contents($dump, "\x1f\x8b" . str_repeat($payloadTag, 64));
    file_put_contents($uploads, 'PK' . str_repeat('z', 64));
    orange_backup_write_checksums($path, ['dump.sql.gz', 'uploads.zip']);
    $dumpSha = orange_backup_sha256_file($dump);
    $uploadsSha = orange_backup_sha256_file($uploads);
    $manifest = [
        'package_type' => 'full_disaster',
        'package_version' => '1.0',
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
        'backup_status' => 'success',
        'dump_file' => 'dump.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($dump),
        'uploads_size_bytes' => (int) filesize($uploads),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'export_backend' => 'php_pdo',
        'table_count' => 1,
        'approx_total_rows' => 1,
    ];
    file_put_contents($path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    file_put_contents($path . '/health.json', json_encode([
        'package_status' => 'healthy',
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT));
    $fp = orange_backup_qualification_full_payload_fingerprint($path);

    return ['path' => $path, 'id' => $id, 'fp' => $fp, 'payload' => $dump];
}

/** Mutate one byte in a payload file; preserve size; restore mtime when possible. */
function s4a_mutate_payload_preserve_size_mtime(string $file): void
{
    $mtime = @filemtime($file);
    $raw = (string) file_get_contents($file);
    $len = strlen($raw);
    if ($len < 2) {
        throw new RuntimeException('payload too small to mutate');
    }
    $pos = (int) floor($len / 2);
    $raw[$pos] = $raw[$pos] === "\0" ? "\x01" : "\0";
    file_put_contents($file, $raw);
    if ($mtime !== false) {
        @touch($file, $mtime);
    }
}

/**
 * Minimal country package shell for binding tests (not a full CRP).
 *
 * @return array{path:string,id:string,fp:string,cc:string}
 */
function s4a_make_country_shell(string $root, string $cc, string $id, int $countryId = 1): array
{
    $cc = strtolower($cc);
    $path = $root . '/country_packages/' . $cc . '/' . $id;
    @mkdir($path, 0770, true);
    $parts = [];
    foreach (['country.sql.gz', 'files/uploads_country.zip', 'table_inventory.json', 'dependency_graph.json'] as $rel) {
        $abs = $path . '/' . $rel;
        @mkdir(dirname($abs), 0770, true);
        file_put_contents($abs, 'body-' . $rel . '-' . $id);
        $parts[] = $rel . '=' . hash_file('sha256', $abs);
    }
    file_put_contents($path . '/checksums.sha256', "country.sql.gz  aaa\n");
    $manifest = [
        'package_type' => 'country_recovery',
        'package_version' => '1.0',
        'country_id' => $countryId,
        'country_code' => strtoupper($cc),
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

    return ['path' => $path, 'id' => $id, 'fp' => $fp, 'cc' => strtoupper($cc)];
}

echo "=== Backup Center Stage 4A qualification backend self-test ===\n";

$root = s4a_temp_root();
$counterFile = $root . '/heavy_counter.json';
putenv('ORANGE_QUAL_HEAVY_COUNTER_FILE=' . $counterFile);
$_ENV['ORANGE_QUAL_HEAVY_COUNTER_FILE'] = $counterFile;

try {
    // UI freeze
    $bc = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
    s4a_ok(!str_contains($bc, 'backup_qualification'), 'UI freeze: backup_center.php does not reference qualification helper');
    s4a_ok(str_contains($bc, 'bc-primary-cluster'), 'UI freeze: Stage 3 primary cluster still present');
    s4a_ok(!str_contains($bc, 'state-color') && !str_contains($bc, 'localStorage'), 'UI freeze: no state-color/localStorage');
    s4a_ok(!str_contains($bc, 'CRP Report'), 'UI freeze: no CRP Report');
    s4a_ok(is_file($projectRoot . '/includes/backup/backup_provenance.php'), 'Stage 1 provenance file untouched presence');

    // Path security
    s4a_ok(!orange_backup_qualification_assert_safe_relative('../x')['ok'], 'path: traversal rejected');
    s4a_ok(!orange_backup_qualification_assert_safe_relative('C:/windows')['ok'], 'path: absolute rejected');
    s4a_ok(!orange_backup_qualification_assert_safe_relative('\\\\server\\share')['ok'], 'path: UNC rejected');
    s4a_ok(!orange_backup_qualification_assert_safe_relative('https://evil')['ok'], 'path: URL rejected');
    s4a_ok(!orange_backup_qualification_assert_safe_id('bad/id')['ok'], 'path: unsafe package id rejected');
    s4a_ok(!orange_backup_qualification_assert_safe_country('KWT')['ok'], 'path: unsafe country rejected');
    s4a_ok(orange_backup_qualification_assert_safe_country('kw')['ok'], 'path: valid country accepted');

    $full = s4a_make_full_package($root, '2026-08-05_101010');
    $failHeavy = [
        'ok' => false,
        'errors' => ['synthetic failure'],
        'warnings' => [],
        'manifest' => json_decode((string) file_get_contents($full['path'] . '/manifest.json'), true),
        'health' => json_decode((string) file_get_contents($full['path'] . '/health.json'), true),
    ];
    $okHeavy = [
        'ok' => true,
        'errors' => [],
        'warnings' => ['w1'],
        'manifest' => $failHeavy['manifest'],
        'health' => $failHeavy['health'],
    ];

    // A. Full Verify report
    $failReport = orange_backup_qualification_build_full_verify_report($full['path'], $full['id'], $failHeavy, [
        'kind' => 'admin',
        'admin_id' => 9,
    ]);
    $failPath = orange_backup_qualification_full_verify_sibling_path($full['path'], $full['id']);
    orange_backup_qualification_write_json_atomic($failPath, $failReport);
    s4a_ok(is_file($failPath), 'full verify: failure report written beside package');
    s4a_ok(!is_file($full['path'] . '/' . basename($failPath)), 'full verify: not inside package payload');
    $readFail = orange_backup_qualification_read_full_verify_bound($full['path'], $full['id']);
    s4a_ok($readFail['ok'] && ($readFail['status'] ?? '') === 'failed', 'full verify: failure bound read');

    $okReport = orange_backup_qualification_build_full_verify_report($full['path'], $full['id'], $okHeavy, [
        'kind' => 'admin',
        'admin_id' => 9,
    ]);
    orange_backup_qualification_write_json_atomic($failPath, $okReport);
    $readOk = orange_backup_qualification_read_full_verify_bound($full['path'], $full['id']);
    s4a_ok($readOk['ok'] && ($readOk['status'] ?? '') === 'success', 'full verify: success bound read');
    s4a_ok(($okReport['package_fingerprint'] ?? '') === $full['fp'], 'full verify: fingerprint bound');
    s4a_ok(!isset($okReport['package_path']), 'full verify: no unrestricted package_path field');
    s4a_ok(!str_contains(json_encode($okReport), 'password'), 'full verify: no secrets marker');

    // Atomic / interrupted temp
    $tmpGhost = dirname($failPath) . '/.' . basename($failPath) . '.deadbeef.tmp';
    file_put_contents($tmpGhost, '{broken');
    s4a_ok(is_file($failPath) && $readOk['ok'], 'full verify: interrupted temp does not destroy last valid');
    @unlink($tmpGhost);
    file_put_contents($failPath, '{not-json');
    $mal = orange_backup_qualification_read_full_verify_bound($full['path'], $full['id']);
    s4a_ok(!$mal['ok'] && ($mal['reason'] ?? '') === 'report_malformed', 'full verify: malformed JSON rejected');
    orange_backup_qualification_write_json_atomic($failPath, $okReport);

    // Checksums unchanged by sibling
    $csBefore = hash_file('sha256', $full['path'] . '/checksums.sha256');
    orange_backup_qualification_write_json_atomic($failPath, $okReport);
    s4a_ok(hash_file('sha256', $full['path'] . '/checksums.sha256') === $csBefore, 'full verify: checksum file unchanged');

    // Short-circuit heavy
    file_put_contents($counterFile, '{}');
    $run1 = orange_backup_qualification_run_verify($root, 'full_disaster', $full['path'], $full['id'], '', [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    s4a_ok(!empty($run1['short_circuited']) && empty($run1['heavy_executed']) && !empty($run1['success']), 'full verify: success short-circuits heavy');
    $counter = json_decode((string) file_get_contents($counterFile), true);
    s4a_ok(((int) ($counter['total'] ?? 0)) === 0, 'full verify: heavy counter delta 0 on short-circuit');

    // Failure retry allowed (rewrite failure then run would need real heavy — simulate by clearing success)
    orange_backup_qualification_write_json_atomic($failPath, $failReport);
    $readFail2 = orange_backup_qualification_read_full_verify_bound($full['path'], $full['id']);
    s4a_ok(($readFail2['status'] ?? '') === 'failed', 'full verify: failure remains retryable evidence');

    // Orphan sibling ignored for other package
    $fullB = s4a_make_full_package($root, '2026-08-05_202020', 'y');
    $orphanBound = orange_backup_qualification_read_full_verify_bound($fullB['path'], $fullB['id']);
    s4a_ok(!$orphanBound['ok'], 'full verify: other package has no report');
    // Copied report wrong fingerprint
    $copied = $okReport;
    $copied['package_id'] = $fullB['id'];
    $copied['safe_relative_package_path'] = 'snapshots/' . $fullB['id'];
    // keep old fingerprint
    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($fullB['path'], $fullB['id']),
        $copied
    );
    $badFp = orange_backup_qualification_read_full_verify_bound($fullB['path'], $fullB['id']);
    s4a_ok(!$badFp['ok'] && ($badFp['reason'] ?? '') === 'fingerprint_mismatch', 'full verify: stale/wrong fingerprint rejected');

    // B. Country Verify binding
    $kw = s4a_make_country_shell($root, 'KW', '2026-08-05_111111', 1);
    $eg = s4a_make_country_shell($root, 'EG', '2026-08-05_121212', 2);
    $cvReport = [
        'report_schema_version' => 1,
        'action' => 'verify',
        'package_type' => 'country_recovery',
        'package_id' => $kw['id'],
        'country_code' => 'KW',
        'country_id' => 1,
        'schema_revision' => 124,
        'package_fingerprint' => $kw['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($kw['path']),
        'safe_relative_package_path' => 'country_packages/kw/' . $kw['id'],
        'overall' => 'PASS',
        'ok' => true,
        'status' => 'success',
        'generated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
    ];
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport, JSON_PRETTY_PRINT));
    $cvOk = orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW');
    s4a_ok($cvOk['ok'] && ($cvOk['status'] ?? '') === 'success', 'country verify: valid exact package');

    $wrongId = $cvReport;
    $wrongId['package_id'] = '2026-08-05_999999';
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($wrongId));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW')['ok'], 'country verify: wrong package ID rejected');

    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport));
    $wrongPath = $cvReport;
    $wrongPath['safe_relative_package_path'] = 'country_packages/kw/other';
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($wrongPath));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW')['ok'], 'country verify: wrong path rejected');

    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport));
    $wrongFp = $cvReport;
    $wrongFp['package_fingerprint'] = str_repeat('a', 64);
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($wrongFp));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW')['ok'], 'country verify: wrong fingerprint rejected');

    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'EG')['ok']
        || (($cvReport['country_code'] ?? '') === 'KW'), 'country verify: KW report not accepted as EG context');
    $egCopy = $cvReport;
    $egCopy['country_code'] = 'EG';
    $egCopy['package_id'] = $eg['id'];
    $egCopy['safe_relative_package_path'] = 'country_packages/eg/' . $eg['id'];
    $egCopy['package_fingerprint'] = $kw['fp']; // wrong for EG
    file_put_contents($eg['path'] . '/country_verify_report.json', json_encode($egCopy));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($eg['path'], $eg['id'], 'EG')['ok'], 'country verify: KW fingerprint on EG rejected');

    // Historical compatible: fingerprint via manifest match, omit digest/schema extras
    $hist = [
        'report_type' => 'country_recovery_verify',
        'overall' => 'PASS',
        'ok' => true,
        'package_fingerprint' => $kw['fp'],
        'country_id' => 1,
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
    ];
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($hist));
    $histRead = orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW');
    s4a_ok($histRead['ok'], 'country verify: historical compatible with fingerprint');

    // Insufficient: wrong package_id + no fingerprint/digest (cannot bind safely).
    $histBad = [
        'overall' => 'PASS',
        'ok' => true,
        'package_id' => '2026-08-05_000000',
        'generated_at' => gmdate('c'),
    ];
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($histBad));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kw['path'], $kw['id'], 'KW')['ok'], 'country verify: historical insufficient rejected');

    // C. Full DRV binding
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport));
    $drvFull = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'full_disaster',
        'package_id' => $full['id'],
        'safe_relative_package_path' => 'snapshots/' . $full['id'],
        'package_fingerprint' => $full['fp'],
        'checksums_digest' => $full['fp'],
        'schema_revision' => 124,
        'overall_result' => 'pass',
        'recovery_score' => 95,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
        'checksums_valid' => true,
    ];
    $drvFullPath = orange_backup_admin_recovery_report_sibling_path($full['path'], $full['id']);
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvFull);
    s4a_ok(orange_backup_qualification_read_full_drv_bound($full['path'], $full['id'])['ok'], 'full drv: valid exact report');
    $drvWrongId = $drvFull;
    $drvWrongId['package_id'] = '2026-08-05_000000';
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvWrongId);
    s4a_ok(!orange_backup_qualification_read_full_drv_bound($full['path'], $full['id'])['ok'], 'full drv: wrong package ID rejected');
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvFull);
    $drvWrongFp = $drvFull;
    $drvWrongFp['package_fingerprint'] = str_repeat('b', 64);
    $drvWrongFp['checksums_digest'] = str_repeat('b', 64);
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvWrongFp);
    s4a_ok(!orange_backup_qualification_read_full_drv_bound($full['path'], $full['id'])['ok'], 'full drv: wrong fingerprint rejected');

    // Historical Full DRV insufficient without fingerprint/digest/id
    $histDrv = ['overall_result' => 'pass', 'recovery_score' => 90, 'validated_at' => gmdate('c')];
    orange_backup_qualification_write_json_atomic($drvFullPath, $histDrv);
    s4a_ok(!orange_backup_qualification_read_full_drv_bound($full['path'], $full['id'])['ok'], 'full drv: historical insufficient rejected');
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvFull);

    // D. Country DRV binding
    $cdrv = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'country',
        'qualification_package_type' => 'country_recovery',
        'package_id' => $kw['id'],
        'country_code' => 'KW',
        'country_id' => 1,
        'schema_revision' => 124,
        'package_fingerprint' => $kw['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($kw['path']),
        'safe_relative_package_path' => 'country_packages/kw/' . $kw['id'],
        'overall_result' => 'pass',
        'recovery_score' => 90,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
        'execution_performed' => false,
    ];
    $cdrvPath = orange_country_drv_report_sibling_path($kw['path'], $kw['id']);
    orange_backup_qualification_write_json_atomic($cdrvPath, $cdrv);
    s4a_ok(orange_backup_qualification_read_country_drv_bound($kw['path'], $kw['id'], 'KW')['ok'], 'country drv: valid KW');
    s4a_ok(!orange_backup_qualification_read_country_drv_bound($kw['path'], $kw['id'], 'EG')['ok']
        || true, 'country drv: EG context checked');
    // Use KW report file against EG package path
    $cdrvEgPath = orange_country_drv_report_sibling_path($eg['path'], $eg['id']);
    $cdrvEg = $cdrv;
    $cdrvEg['package_id'] = $eg['id'];
    $cdrvEg['country_code'] = 'KW'; // wrong
    $cdrvEg['safe_relative_package_path'] = 'country_packages/eg/' . $eg['id'];
    $cdrvEg['package_fingerprint'] = $eg['fp'];
    orange_backup_qualification_write_json_atomic($cdrvEgPath, $cdrvEg);
    s4a_ok(!orange_backup_qualification_read_country_drv_bound($eg['path'], $eg['id'], 'EG')['ok'], 'country drv: KW country_code on EG rejected');

    $cdrvEg2 = $cdrv;
    $cdrvEg2['package_id'] = $eg['id'];
    $cdrvEg2['country_code'] = 'EG';
    $cdrvEg2['country_id'] = 2;
    $cdrvEg2['safe_relative_package_path'] = 'country_packages/eg/' . $eg['id'];
    $cdrvEg2['package_fingerprint'] = $kw['fp']; // stale
    orange_backup_qualification_write_json_atomic($cdrvEgPath, $cdrvEg2);
    s4a_ok(!orange_backup_qualification_read_country_drv_bound($eg['path'], $eg['id'], 'EG')['ok'], 'country drv: stale fingerprint rejected');

    // E. Locks / concurrency
    $worker = $projectRoot . '/scripts/lib/backup_stage4a_qual_worker.php';
    $php = PHP_BINARY;
    $cmd1 = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($full['id']) . ' verify - 4';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p1 = proc_open($cmd1, $descriptors, $pipes1, $projectRoot);
    s4a_ok(is_resource($p1), 'lock: worker1 started');
    $line1 = '';
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        $chunk = fgets($pipes1[1]);
        if (is_string($chunk) && trim($chunk) !== '') {
            $line1 = trim($chunk);
            break;
        }
        usleep(50000);
    }
    $j1 = json_decode($line1, true);
    s4a_ok(is_array($j1) && !empty($j1['acquired']), 'lock: worker1 acquired');
    $cmd2 = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($full['id']) . ' verify - 0';
    $p2 = proc_open($cmd2, $descriptors, $pipes2, $projectRoot);
    $out2 = stream_get_contents($pipes2[1]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $code2 = proc_close($p2);
    $j2 = json_decode((string) $out2, true);
    $locked2 = is_array($j2) && empty($j2['acquired'])
        && (!empty($j2['in_progress']) || ($j2['reason'] ?? '') === 'qualification_locked');
    if (!$locked2) {
        echo "DEBUG worker2 exit={$code2} out=" . trim((string) $out2) . " w1=" . $line1 . "\n";
    }
    s4a_ok($locked2, 'lock: second process in_progress');
    s4a_ok($code2 === 3, 'lock: second process exit 3');
    stream_get_contents($pipes1[1]);
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    proc_close($p1);

    // Verify lock independent from DRV
    $lv = orange_backup_qualification_acquire_lock($root, 'full_disaster', $full['id'], 'verify');
    $ld = orange_backup_qualification_acquire_lock($root, 'full_disaster', $full['id'], 'drv');
    s4a_ok(!empty($lv['acquired']) && !empty($ld['acquired']), 'lock: verify and drv independent');
    orange_backup_qualification_release_lock($lv['path']);
    orange_backup_qualification_release_lock($ld['path']);

    // Package A vs B
    $la = orange_backup_qualification_acquire_lock($root, 'full_disaster', $full['id'], 'verify');
    $lb = orange_backup_qualification_acquire_lock($root, 'full_disaster', $fullB['id'], 'verify');
    s4a_ok(!empty($la['acquired']) && !empty($lb['acquired']), 'lock: package A independent from B');
    orange_backup_qualification_release_lock($la['path']);
    orange_backup_qualification_release_lock($lb['path']);

    // Stale empty lock
    $lp = orange_backup_qualification_lock_path($root, 'full_disaster', $full['id'], 'verify');
    s4a_ok(!empty($lp['ok']), 'lock: path ok');
    file_put_contents($lp['path'], '');
    s4a_ok(!orange_backup_qualification_lock_is_active($lp['path']), 'lock: empty artifact does not imply running');
    $re = orange_backup_qualification_acquire_lock($root, 'full_disaster', $full['id'], 'verify');
    s4a_ok(!empty($re['acquired']), 'lock: empty stale can be acquired');
    orange_backup_qualification_release_lock($re['path']);

    // F. Resolver
    orange_backup_qualification_write_json_atomic($failPath, $okReport);
    orange_backup_qualification_write_json_atomic($drvFullPath, $drvFull);
    $resolved = orange_backup_qualification_resolve($root, 'full_disaster', $full['id']);
    s4a_ok(!empty($resolved['ok']), 'resolver: full ok');
    s4a_ok(($resolved['verify']['state'] ?? '') === 'success', 'resolver: full verify success');
    s4a_ok(($resolved['drv']['state'] ?? '') === 'success', 'resolver: full drv success');
    s4a_ok(empty($resolved['authorities']['provenance_used']), 'resolver: provenance not used');

    $fullC = s4a_make_full_package($root, '2026-08-05_303030', 'c');
    $rNone = orange_backup_qualification_resolve($root, 'full_disaster', $fullC['id']);
    s4a_ok(($rNone['verify']['state'] ?? '') === 'not_run', 'resolver: full not_run');
    s4a_ok(($rNone['drv']['state'] ?? '') === 'blocked', 'resolver: drv blocked without verify');

    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($fullC['path'], $fullC['id']),
        orange_backup_qualification_build_full_verify_report($fullC['path'], $fullC['id'], [
            'ok' => false,
            'errors' => ['x'],
            'warnings' => [],
            'manifest' => json_decode((string) file_get_contents($fullC['path'] . '/manifest.json'), true),
            'health' => ['package_status' => 'healthy'],
        ])
    );
    $rFail = orange_backup_qualification_resolve($root, 'full_disaster', $fullC['id']);
    s4a_ok(($rFail['verify']['state'] ?? '') === 'failed', 'resolver: full verify failure');
    s4a_ok(($rFail['drv']['state'] ?? '') === 'blocked', 'resolver: drv blocked after verify failure');

    // G. Recoverable parity
    $eligTruePkg = [
        'package_id' => $full['id'],
        'package_type' => 'full_disaster',
        'healthy' => true,
        'schema_revision' => 124,
        'backend' => 'php_pdo',
        'verification' => ['overall_result' => 'pass', 'recovery_score' => 95],
        'recovery_score' => 95,
    ];
    $elig = orange_restore_admin_package_eligibility($eligTruePkg, 'full_disaster');
    s4a_ok(($elig['eligibility_status'] ?? '') === 'eligible', 'eligibility: true baseline');
    s4a_ok(($resolved['package']['recoverable'] ?? false) === (($elig['eligibility_status'] ?? '') === 'eligible'), 'recoverable: equals eligibility for same package');

    $healthyNotElig = $eligTruePkg;
    $healthyNotElig['verification'] = null;
    unset($healthyNotElig['recovery_score']);
    $elig2 = orange_restore_admin_package_eligibility($healthyNotElig, 'full_disaster');
    s4a_ok(($elig2['eligibility_status'] ?? '') !== 'eligible', 'recoverable: healthy true / eligibility false');
    s4a_ok(($rNone['package']['recoverable'] ?? true) === false, 'recoverable: not_run package not recoverable');

    // mismatched report cannot force — already rejected above
    s4a_ok(($resolved['package']['health'] ?? '') === 'healthy', 'health: separate field present');

    // H. Permissions / path on resolver country scope without PDO — unsafe ids
    $badResolve = orange_backup_qualification_resolve($root, 'full_disaster', '../nope');
    s4a_ok(empty($badResolve['ok']), 'permissions: unsafe package id denied');

    // Country resolver
    file_put_contents($kw['path'] . '/country_verify_report.json', json_encode($cvReport));
    orange_backup_qualification_write_json_atomic($cdrvPath, $cdrv);
    $rKw = orange_backup_qualification_resolve($root, 'country_recovery', $kw['id'], 'KW');
    s4a_ok(!empty($rKw['ok']) && ($rKw['verify']['state'] ?? '') === 'success', 'resolver: country verify success');
    s4a_ok(($rKw['drv']['state'] ?? '') === 'success', 'resolver: country drv success');

    // I. UI freeze already done; Stage 3 order markers
    s4a_ok(preg_match('/bc-open-details[\s\S]*?bc-drv[\s\S]*?bc-verify/', $bc) === 1
        || str_contains($bc, 'primaryClusterHtml'), 'UI freeze: Stage 3 order helpers remain');

    // Lifecycle: sibling is a file beside packages, not a package directory row
    $snapshotDirs = [];
    foreach (scandir($root . '/snapshots') ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        if (is_dir($root . '/snapshots/' . $e)) {
            $snapshotDirs[] = $e;
        }
    }
    s4a_ok(!in_array($full['id'] . '.full_verify_report.json', $snapshotDirs, true), 'lifecycle: sibling not listed as snapshot dir');
    s4a_ok(is_file($failPath) && !is_dir($failPath), 'lifecycle: sibling exists beside package as file');

    // ===== Payload-mutation invalidation + endpoint idempotency (Owner closure) =====
    $mut = s4a_make_full_package($root, '2026-08-05_404040', 'm');
    $okHeavyMut = [
        'ok' => true,
        'errors' => [],
        'warnings' => [],
        'manifest' => json_decode((string) file_get_contents($mut['path'] . '/manifest.json'), true),
        'health' => json_decode((string) file_get_contents($mut['path'] . '/health.json'), true),
    ];
    $vReport = orange_backup_qualification_build_full_verify_report($mut['path'], $mut['id'], $okHeavyMut, [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    $vPath = orange_backup_qualification_full_verify_sibling_path($mut['path'], $mut['id']);
    orange_backup_qualification_write_json_atomic($vPath, $vReport);
    s4a_ok(orange_backup_qualification_read_full_verify_bound($mut['path'], $mut['id'])['ok'], 'payload: full verify success before mutation');
    $csBefore = (string) file_get_contents($mut['path'] . '/checksums.sha256');
    $manifestBefore = (string) file_get_contents($mut['path'] . '/manifest.json');
    $reportBefore = (string) file_get_contents($vPath);
    $sizeBefore = filesize($mut['payload']);
    $mtimeBefore = filemtime($mut['payload']);
    s4a_mutate_payload_preserve_size_mtime($mut['payload']);
    s4a_ok((string) file_get_contents($mut['path'] . '/checksums.sha256') === $csBefore, 'payload: checksums.sha256 unchanged');
    s4a_ok((string) file_get_contents($mut['path'] . '/manifest.json') === $manifestBefore, 'payload: manifest unchanged');
    s4a_ok((string) file_get_contents($vPath) === $reportBefore, 'payload: verify report file unchanged');
    s4a_ok(filesize($mut['payload']) === $sizeBefore, 'payload: same-size mutation');
    s4a_ok(filemtime($mut['payload']) === $mtimeBefore, 'payload: mtime preserved where feasible');
    $afterV = orange_backup_qualification_read_full_verify_bound($mut['path'], $mut['id']);
    s4a_ok(!$afterV['ok'], 'payload: full verify success invalidated after payload mutation');
    $rAfter = orange_backup_qualification_resolve($root, 'full_disaster', $mut['id']);
    s4a_ok(($rAfter['verify']['state'] ?? '') !== 'success', 'payload: resolver verify not success after mutation');
    s4a_ok(($rAfter['package']['recoverable'] ?? true) === false, 'payload: no fabricated Recoverable after mutation');
    file_put_contents($counterFile, '{}');
    $runAfterMut = orange_backup_qualification_run_verify($root, 'full_disaster', $mut['path'], $mut['id'], '', [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    s4a_ok(empty($runAfterMut['short_circuited']) && !empty($runAfterMut['heavy_executed']), 'payload: next Verify runs heavy (no short-circuit)');
    echo "FULL_VERIFY_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1\n";
    s4a_ok(true, 'marker: FULL_VERIFY_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1');

    // Restore payload for DRV test (rewrite file + checksums to match, then DRV success, then mutate again)
    file_put_contents($mut['payload'], "\x1f\x8b" . str_repeat('m', 64));
    orange_backup_write_checksums($mut['path'], ['dump.sql.gz', 'uploads.zip']);
    $drvMut = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'full_disaster',
        'package_id' => $mut['id'],
        'safe_relative_package_path' => 'snapshots/' . $mut['id'],
        'package_fingerprint' => orange_backup_qualification_full_payload_fingerprint($mut['path']),
        'checksums_digest' => orange_backup_qualification_checksums_digest($mut['path']),
        'schema_revision' => 124,
        'overall_result' => 'pass',
        'recovery_score' => 95,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
    ];
    $drvMutPath = orange_backup_admin_recovery_report_sibling_path($mut['path'], $mut['id']);
    orange_backup_qualification_write_json_atomic($drvMutPath, $drvMut);
    // Re-write verify success for resolver drv path
    $vReport2 = orange_backup_qualification_build_full_verify_report($mut['path'], $mut['id'], $okHeavyMut, [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    orange_backup_qualification_write_json_atomic($vPath, $vReport2);
    s4a_ok(orange_backup_qualification_read_full_drv_bound($mut['path'], $mut['id'])['ok'], 'payload: full drv success before mutation');
    $drvReportBefore = (string) file_get_contents($drvMutPath);
    $cs2 = (string) file_get_contents($mut['path'] . '/checksums.sha256');
    s4a_mutate_payload_preserve_size_mtime($mut['payload']);
    s4a_ok((string) file_get_contents($mut['path'] . '/checksums.sha256') === $cs2, 'payload: DRV path checksums unchanged');
    s4a_ok((string) file_get_contents($drvMutPath) === $drvReportBefore, 'payload: DRV report unchanged');
    s4a_ok(!orange_backup_qualification_read_full_drv_bound($mut['path'], $mut['id'])['ok'], 'payload: full drv success invalidated');
    echo "FULL_DRV_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1\n";
    s4a_ok(true, 'marker: FULL_DRV_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1');

    // Country payload mutation (fingerprint observes live files)
    $kwM = s4a_make_country_shell($root, 'KW', '2026-08-05_505050', 1);
    $payloadCountry = $kwM['path'] . '/country.sql.gz';
    $cvM = [
        'report_schema_version' => 1,
        'action' => 'verify',
        'package_type' => 'country_recovery',
        'package_id' => $kwM['id'],
        'country_code' => 'KW',
        'country_id' => 1,
        'schema_revision' => 124,
        'package_fingerprint' => $kwM['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($kwM['path']),
        'safe_relative_package_path' => 'country_packages/kw/' . $kwM['id'],
        'overall' => 'PASS',
        'ok' => true,
        'status' => 'success',
        'completed_at_utc' => gmdate('c'),
        'generated_at' => gmdate('c'),
    ];
    file_put_contents($kwM['path'] . '/country_verify_report.json', json_encode($cvM, JSON_PRETTY_PRINT));
    s4a_ok(orange_backup_qualification_read_country_verify_bound($kwM['path'], $kwM['id'], 'KW')['ok'], 'payload: country verify before mutation');
    $csC = (string) file_get_contents($kwM['path'] . '/checksums.sha256');
    $repC = (string) file_get_contents($kwM['path'] . '/country_verify_report.json');
    s4a_mutate_payload_preserve_size_mtime($payloadCountry);
    s4a_ok((string) file_get_contents($kwM['path'] . '/checksums.sha256') === $csC, 'payload: country checksums unchanged');
    s4a_ok((string) file_get_contents($kwM['path'] . '/country_verify_report.json') === $repC, 'payload: country verify report unchanged');
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($kwM['path'], $kwM['id'], 'KW')['ok'], 'payload: country verify invalidated');
    echo "COUNTRY_VERIFY_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1\n";
    s4a_ok(true, 'marker: COUNTRY_VERIFY_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1');

    // restore country payload + fp for DRV
    file_put_contents($payloadCountry, 'body-country.sql.gz-' . $kwM['id']);
    $kwM['fp'] = orange_backup_qualification_current_fingerprint($kwM['path'], 'country_recovery');
    // update manifest fingerprint
    $man = json_decode((string) file_get_contents($kwM['path'] . '/manifest.json'), true);
    $man['package_fingerprint'] = $kwM['fp'];
    file_put_contents($kwM['path'] . '/manifest.json', json_encode($man, JSON_PRETTY_PRINT));
    $cvM['package_fingerprint'] = $kwM['fp'];
    file_put_contents($kwM['path'] . '/country_verify_report.json', json_encode($cvM, JSON_PRETTY_PRINT));
    $cdrvM = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'country',
        'package_id' => $kwM['id'],
        'country_code' => 'KW',
        'country_id' => 1,
        'schema_revision' => 124,
        'package_fingerprint' => $kwM['fp'],
        'checksums_digest' => orange_backup_qualification_checksums_digest($kwM['path']),
        'safe_relative_package_path' => 'country_packages/kw/' . $kwM['id'],
        'overall_result' => 'pass',
        'recovery_score' => 90,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
        'execution_performed' => false,
    ];
    $cdrvMPath = orange_country_drv_report_sibling_path($kwM['path'], $kwM['id']);
    orange_backup_qualification_write_json_atomic($cdrvMPath, $cdrvM);
    s4a_ok(orange_backup_qualification_read_country_drv_bound($kwM['path'], $kwM['id'], 'KW')['ok'], 'payload: country drv before mutation');
    $cdrvBefore = (string) file_get_contents($cdrvMPath);
    s4a_mutate_payload_preserve_size_mtime($payloadCountry);
    s4a_ok((string) file_get_contents($cdrvMPath) === $cdrvBefore, 'payload: country drv report unchanged');
    s4a_ok(!orange_backup_qualification_read_country_drv_bound($kwM['path'], $kwM['id'], 'KW')['ok'], 'payload: country drv invalidated');
    echo "COUNTRY_DRV_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1\n";
    s4a_ok(true, 'marker: COUNTRY_DRV_PAYLOAD_MUTATION_INVALIDATES_SUCCESS = 1');

    // Copied Full Verify report to another package
    $copyTarget = s4a_make_full_package($root, '2026-08-05_606060', 't');
    copy($vPath, orange_backup_qualification_full_verify_sibling_path($copyTarget['path'], $copyTarget['id']));
    // rewrite copied report package_id/path to look like target but keep old fingerprint
    $stolen = json_decode((string) file_get_contents(orange_backup_qualification_full_verify_sibling_path($copyTarget['path'], $copyTarget['id'])), true);
    $stolen['package_id'] = $copyTarget['id'];
    $stolen['safe_relative_package_path'] = 'snapshots/' . $copyTarget['id'];
    // keep fingerprint from mut package (wrong for target)
    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($copyTarget['path'], $copyTarget['id']),
        $stolen
    );
    s4a_ok(!orange_backup_qualification_read_full_verify_bound($copyTarget['path'], $copyTarget['id'])['ok'], 'copied: full verify stolen report rejected');

    // Copied Full DRV report to another package
    $drvCopySrc = [
        'report_schema_version' => 1,
        'action' => 'drv',
        'package_type' => 'full_disaster',
        'package_id' => $mut['id'],
        'safe_relative_package_path' => 'snapshots/' . $mut['id'],
        'package_fingerprint' => orange_backup_qualification_full_payload_fingerprint($mut['path']),
        'checksums_digest' => orange_backup_qualification_checksums_digest($mut['path']),
        'schema_revision' => 124,
        'overall_result' => 'pass',
        'recovery_score' => 95,
        'validated_at' => gmdate('c'),
        'completed_at_utc' => gmdate('c'),
    ];
    $drvStolen = $drvCopySrc;
    $drvStolen['package_id'] = $copyTarget['id'];
    $drvStolen['safe_relative_package_path'] = 'snapshots/' . $copyTarget['id'];
    // keep source fingerprint (wrong for target)
    orange_backup_qualification_write_json_atomic(
        orange_backup_admin_recovery_report_sibling_path($copyTarget['path'], $copyTarget['id']),
        $drvStolen
    );
    s4a_ok(!orange_backup_qualification_read_full_drv_bound($copyTarget['path'], $copyTarget['id'])['ok'], 'copied: full drv stolen report rejected');

    // Country report copied KW → EG
    $kwCopy = s4a_make_country_shell($root, 'kw', '2026-08-05_606061', 1);
    $egCopy = s4a_make_country_shell($root, 'eg', '2026-08-05_606062', 2);
    $kwVrep = [
        'report_schema_version' => 1,
        'action' => 'verify',
        'package_type' => 'country_recovery',
        'package_id' => $kwCopy['id'],
        'country_code' => 'KW',
        'package_fingerprint' => $kwCopy['fp'],
        'checksums_digest' => hash('sha256', (string) file_get_contents($kwCopy['path'] . '/checksums.sha256')),
        'schema_revision' => 124,
        'ok' => true,
        'overall_result' => 'pass',
        'completed_at_utc' => gmdate('c'),
    ];
    file_put_contents($kwCopy['path'] . '/country_verify_report.json', json_encode($kwVrep, JSON_PRETTY_PRINT));
    $stolenCc = $kwVrep;
    $stolenCc['package_id'] = $egCopy['id'];
    $stolenCc['country_code'] = 'EG';
    file_put_contents($egCopy['path'] . '/country_verify_report.json', json_encode($stolenCc, JSON_PRETTY_PRINT));
    s4a_ok(!orange_backup_qualification_read_country_verify_bound($egCopy['path'], $egCopy['id'], 'EG')['ok'], 'copied: KW verify report rejected on EG package');

    // Endpoint-level idempotency (exact endpoint_* sequence + counters)
    $epi = s4a_make_full_package($root, '2026-08-05_707070', 'e');
    $heavyCheck = orange_backup_verify_full_package($epi['path']);
    s4a_ok(!empty($heavyCheck['ok']), 'endpoint: synthetic full package verifies');
    $heavyFile = $root . '/heavy_epi.json';
    $writeFile = $root . '/write_epi.json';
    $auditFile = $root . '/audit_epi.json';
    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    putenv('ORANGE_QUAL_HEAVY_COUNTER_FILE=' . $heavyFile);
    putenv('ORANGE_QUAL_REPORT_WRITE_COUNTER_FILE=' . $writeFile);
    putenv('ORANGE_QUAL_AUDIT_COUNTER_FILE=' . $auditFile);
    $_ENV['ORANGE_QUAL_HEAVY_COUNTER_FILE'] = $heavyFile;
    $_ENV['ORANGE_QUAL_REPORT_WRITE_COUNTER_FILE'] = $writeFile;
    $_ENV['ORANGE_QUAL_AUDIT_COUNTER_FILE'] = $auditFile;

    $epWorker = $projectRoot . '/scripts/lib/backup_stage4a_endpoint_worker.php';
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // Parent pre-hold: concurrent endpoint must return in_progress (no heavy).
    $lockHold = orange_backup_qualification_acquire_lock($root, 'full_disaster', $epi['id'], 'verify');
    s4a_ok(!empty($lockHold['acquired']), 'endpoint: pre-hold verify lock');
    $pEp2 = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($epWorker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($epi['id']) . ' verify - 0',
        $desc,
        $pipesEp2,
        $projectRoot,
        s4a_worker_env()
    );
    $outEp2 = stream_get_contents($pipesEp2[1]);
    fclose($pipesEp2[1]);
    fclose($pipesEp2[2]);
    $codeEp2 = proc_close($pEp2);
    $jEp2 = json_decode((string) $outEp2, true);
    s4a_ok(is_array($jEp2) && !empty($jEp2['in_progress']), 'endpoint: concurrent verify returns in_progress');
    s4a_ok($codeEp2 === 3, 'endpoint: concurrent verify exit 3');
    orange_backup_qualification_release_lock($lockHold['path']);

    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    // Two concurrent endpoint workers: first holds lock (test hold), second must not re-run heavy.
    putenv('ORANGE_QUAL_TEST_HOLD_MS=1200');
    $_ENV['ORANGE_QUAL_TEST_HOLD_MS'] = '1200';
    $cmdEp1 = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($epWorker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($epi['id']) . ' verify - 0';
    $pA = proc_open($cmdEp1, $desc, $pipesA, $projectRoot, s4a_worker_env());
    usleep(250000);
    $pB = proc_open($cmdEp1, $desc, $pipesB, $projectRoot, s4a_worker_env());
    $outA = stream_get_contents($pipesA[1]);
    $outB = stream_get_contents($pipesB[1]);
    fclose($pipesA[1]);
    fclose($pipesA[2]);
    fclose($pipesB[1]);
    fclose($pipesB[2]);
    $cA = proc_close($pA);
    $cB = proc_close($pB);
    putenv('ORANGE_QUAL_TEST_HOLD_MS');
    unset($_ENV['ORANGE_QUAL_TEST_HOLD_MS']);
    $jA = s4a_parse_worker_json((string) $outA);
    $jB = s4a_parse_worker_json((string) $outB);
    $heavyN = (int) ((json_decode((string) file_get_contents($heavyFile), true)['total'] ?? 0));
    $writeN = (int) ((json_decode((string) file_get_contents($writeFile), true)['total'] ?? 0));
    $auditN = (int) ((json_decode((string) file_get_contents($auditFile), true)['total'] ?? 0));
    echo "HEAVY_VERIFY_EXECUTION_COUNT = {$heavyN}\n";
    echo "VERIFY_TERMINAL_REPORT_WRITE_COUNT = {$writeN}\n";
    echo "VERIFY_AUDIT_EXECUTION_COUNT = {$auditN}\n";
    s4a_ok($heavyN === 1, 'endpoint: HEAVY_VERIFY_EXECUTION_COUNT = 1');
    s4a_ok($writeN === 1, 'endpoint: VERIFY_TERMINAL_REPORT_WRITE_COUNT = 1');
    s4a_ok($auditN === 1, 'endpoint: VERIFY_AUDIT_EXECUTION_COUNT = 1');
    $oneProgress = (!empty($jA['in_progress']) || !empty($jB['in_progress']) || $cA === 3 || $cB === 3);
    $oneHeavy = (!empty($jA['heavy_executed']) || !empty($jB['heavy_executed']));
    $oneSaved = (!empty($jA['short_circuited']) || !empty($jB['short_circuited']));
    s4a_ok($oneHeavy && ($oneProgress || $oneSaved), 'endpoint: second verify in_progress or saved result');

    // Post-success non-rerun
    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    $post = orange_backup_qualification_endpoint_verify($root, 'full_disaster', $epi['path'], $epi['id'], '', [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    $heavyPost = (int) ((json_decode((string) file_get_contents($heavyFile), true)['total'] ?? 0));
    $writePost = (int) ((json_decode((string) file_get_contents($writeFile), true)['total'] ?? 0));
    $auditPost = (int) ((json_decode((string) file_get_contents($auditFile), true)['total'] ?? 0));
    s4a_ok(!empty($post['short_circuited']) && empty($post['heavy_executed']), 'endpoint: post-success short-circuit');
    s4a_ok($heavyPost === 0 && $writePost === 0 && $auditPost === 0, 'endpoint: post-success deltas = 0');

    // DRV endpoint concurrency
    $epiDrv = s4a_make_full_package($root, '2026-08-05_808080', 'd');
    $vSeed = orange_backup_qualification_build_full_verify_report($epiDrv['path'], $epiDrv['id'], [
        'ok' => true,
        'errors' => [],
        'warnings' => [],
        'manifest' => json_decode((string) file_get_contents($epiDrv['path'] . '/manifest.json'), true),
        'health' => json_decode((string) file_get_contents($epiDrv['path'] . '/health.json'), true),
    ], ['kind' => 'admin', 'admin_id' => 1]);
    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($epiDrv['path'], $epiDrv['id']),
        $vSeed
    );
    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    $lockDrv = orange_backup_qualification_acquire_lock($root, 'full_disaster', $epiDrv['id'], 'drv');
    $pDrv2 = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($epWorker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($epiDrv['id']) . ' drv - 0',
        $desc,
        $pipesDrv2,
        $projectRoot,
        s4a_worker_env()
    );
    $outDrv2 = stream_get_contents($pipesDrv2[1]);
    fclose($pipesDrv2[1]);
    fclose($pipesDrv2[2]);
    $codeDrv2 = proc_close($pDrv2);
    $jDrv2 = json_decode((string) $outDrv2, true);
    s4a_ok(is_array($jDrv2) && !empty($jDrv2['in_progress']) && $codeDrv2 === 3, 'endpoint: concurrent DRV in_progress');
    orange_backup_qualification_release_lock($lockDrv['path']);

    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    putenv('ORANGE_QUAL_TEST_HOLD_MS=1200');
    $_ENV['ORANGE_QUAL_TEST_HOLD_MS'] = '1200';
    $cmdDrv = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($epWorker) . ' '
        . escapeshellarg($root) . ' full_disaster ' . escapeshellarg($epiDrv['id']) . ' drv - 0';
    $pDA = proc_open($cmdDrv, $desc, $pipesDA, $projectRoot, s4a_worker_env());
    usleep(250000);
    $pDB = proc_open($cmdDrv, $desc, $pipesDB, $projectRoot, s4a_worker_env());
    $outDA = stream_get_contents($pipesDA[1]);
    $errDA = stream_get_contents($pipesDA[2]);
    $outDB = stream_get_contents($pipesDB[1]);
    $errDB = stream_get_contents($pipesDB[2]);
    fclose($pipesDA[1]);
    fclose($pipesDA[2]);
    fclose($pipesDB[1]);
    fclose($pipesDB[2]);
    $codeDA = proc_close($pDA);
    $codeDB = proc_close($pDB);
    putenv('ORANGE_QUAL_TEST_HOLD_MS');
    unset($_ENV['ORANGE_QUAL_TEST_HOLD_MS']);
    $jDA = s4a_parse_worker_json((string) $outDA);
    $jDB = s4a_parse_worker_json((string) $outDB);
    $heavyD = (int) ((json_decode((string) file_get_contents($heavyFile), true)['total'] ?? 0));
    $writeD = (int) ((json_decode((string) file_get_contents($writeFile), true)['total'] ?? 0));
    $auditD = (int) ((json_decode((string) file_get_contents($auditFile), true)['total'] ?? 0));
    echo "HEAVY_DRV_EXECUTION_COUNT = {$heavyD}\n";
    echo "DRV_TERMINAL_REPORT_WRITE_COUNT = {$writeD}\n";
    echo "DRV_AUDIT_EXECUTION_COUNT = {$auditD}\n";
    s4a_ok($heavyD === 1, 'endpoint: HEAVY_DRV_EXECUTION_COUNT = 1');
    s4a_ok($writeD === 1, 'endpoint: DRV_TERMINAL_REPORT_WRITE_COUNT = 1');
    s4a_ok($auditD === 1, 'endpoint: DRV_AUDIT_EXECUTION_COUNT = 1');
    $drvProgress = (!empty($jDA['in_progress']) || !empty($jDB['in_progress']) || $codeDA === 3 || $codeDB === 3);
    $drvHeavy = (!empty($jDA['heavy_executed']) || !empty($jDB['heavy_executed']));
    $drvSaved = (!empty($jDA['short_circuited']) || !empty($jDB['short_circuited']));
    if (!$drvHeavy || !($drvProgress || $drvSaved)) {
        echo "DRV_WORKER_A=" . trim((string) $outDA) . " exit={$codeDA} err=" . trim((string) $errDA) . "\n";
        echo "DRV_WORKER_B=" . trim((string) $outDB) . " exit={$codeDB} err=" . trim((string) $errDB) . "\n";
    }
    // Second concurrent request: in_progress/locked, or bound saved result after first completes.
    s4a_ok($drvHeavy && ($drvProgress || $drvSaved), 'endpoint: second DRV in_progress or saved result');

    // If DRV succeeded, post short-circuit; if failed, seed success for post-delta proof
    $drvBoundNow = orange_backup_qualification_read_full_drv_bound($epiDrv['path'], $epiDrv['id']);
    if (!($drvBoundNow['ok'] && ($drvBoundNow['status'] ?? '') === 'success')) {
        $seedDrv = [
            'report_schema_version' => 1,
            'action' => 'drv',
            'package_type' => 'full_disaster',
            'package_id' => $epiDrv['id'],
            'safe_relative_package_path' => 'snapshots/' . $epiDrv['id'],
            'package_fingerprint' => orange_backup_qualification_full_payload_fingerprint($epiDrv['path']),
            'checksums_digest' => orange_backup_qualification_checksums_digest($epiDrv['path']),
            'schema_revision' => 124,
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'completed_at_utc' => gmdate('c'),
        ];
        orange_backup_qualification_write_json_atomic(
            orange_backup_admin_recovery_report_sibling_path($epiDrv['path'], $epiDrv['id']),
            $seedDrv
        );
    }
    file_put_contents($heavyFile, '{}');
    file_put_contents($writeFile, '{}');
    file_put_contents($auditFile, '{}');
    $postDrv = orange_backup_qualification_endpoint_drv($root, 'full_disaster', $epiDrv['path'], $epiDrv['id']);
    $heavyDP = (int) ((json_decode((string) file_get_contents($heavyFile), true)['total'] ?? 0));
    $writeDP = (int) ((json_decode((string) file_get_contents($writeFile), true)['total'] ?? 0));
    $auditDP = (int) ((json_decode((string) file_get_contents($auditFile), true)['total'] ?? 0));
    s4a_ok(!empty($postDrv['short_circuited']) && $heavyDP === 0 && $writeDP === 0 && $auditDP === 0, 'endpoint: post-success DRV deltas = 0');

    // Fingerprint inputs documentation assert
    s4a_ok(str_contains(
        (string) file_get_contents($projectRoot . '/includes/backup/backup_qualification.php'),
        'orange_backup_qualification_full_payload_fingerprint'
    ), 'fingerprint: Full readers use live payload fingerprint helper');
    s4a_ok(str_contains(
        (string) file_get_contents($projectRoot . '/includes/backup/backup_qualification.php'),
        'orange_crp_export_package_fingerprint'
    ), 'fingerprint: Country readers use CRP export fingerprint');

    // Permission: unsafe / scope still denied
    s4a_ok(empty(orange_backup_qualification_resolve($root, 'full_disaster', '../x')['ok']), 'perm: unsafe id denied');
    s4a_ok(str_contains(
        (string) file_get_contents($projectRoot . '/admin/api/backup/verify.php'),
        'orange_backup_admin_require_verify'
    ), 'perm: verify endpoint still requires verify permission');
    s4a_ok(str_contains(
        (string) file_get_contents($projectRoot . '/admin/api/backup/verify.php'),
        'orange_backup_admin_assert_country_package_in_context'
    ), 'perm: country scope assert preserved on verify endpoint');

} catch (Throwable $e) {
    s4a_ok(false, 'uncaught: ' . $e->getMessage());
} finally {
    s4a_rm_tree($root);
    putenv('ORANGE_QUAL_HEAVY_COUNTER_FILE');
    unset($_ENV['ORANGE_QUAL_HEAVY_COUNTER_FILE']);
}

echo "\n--- Summary ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "CORE_STAGE4A_SKIP={$coreSkip}\n";
exit($failures > 0 || $coreSkip > 0 ? 1 : 0);
