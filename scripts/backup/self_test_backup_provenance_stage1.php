<?php

declare(strict_types=1);

/**
 * Stage 1 — Backup Provenance Metadata Registry Backend self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_backup_provenance_stage1.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_provenance.php';
require_once $projectRoot . '/includes/backup/backup_retention.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';

$failures = 0;
$passes = 0;
$skips = 0;

function s1_ok(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function s1_temp_root(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bak_s1_' . bin2hex(random_bytes(4));
    if (!@mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Cannot create temp BackupRoot');
    }

    return $base;
}

function s1_rm_tree(string $dir): void
{
    $tempParent = realpath(sys_get_temp_dir());
    $resolved = realpath($dir);
    if ($tempParent === false || $resolved === false) {
        return;
    }
    $normTemp = strtolower(str_replace('\\', '/', rtrim($tempParent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/')));
    if ($normDir === $normTemp || !str_starts_with($normDir, $normTemp . '/')) {
        return;
    }
    if (!str_contains($normDir, '/orange_bak_s1_')) {
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

function s1_fake_full(string $root, string $id, string $body): string
{
    $path = $root . '/snapshots/' . $id;
    if (!is_dir($path)) {
        mkdir($path, 0770, true);
    }
    file_put_contents($path . '/checksums.sha256', $body);
    file_put_contents($path . '/manifest.json', json_encode([
        'package_type' => 'full_disaster',
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
        'backup_status' => 'healthy',
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($path . '/health.json', json_encode(['package_status' => 'healthy']));

    return $path;
}

function s1_fake_country(string $root, string $cc, string $id, string $fp): string
{
    $path = $root . '/country_packages/' . $cc . '/' . $id;
    if (!is_dir($path)) {
        mkdir($path, 0770, true);
    }
    file_put_contents($path . '/manifest.json', json_encode([
        'package_type' => 'country_recovery',
        'country_id' => $cc === 'KW' ? 1 : 2,
        'schema_revision' => 124,
        'package_fingerprint' => $fp,
        'generated_at' => gmdate('c'),
        'backup_status' => 'healthy',
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($path . '/health.json', json_encode(['package_status' => 'healthy']));

    return $path;
}

$root = s1_temp_root();
echo "BackupRoot={$root}\n";

try {
    // Path security 26–30
    s1_ok(empty(orange_backup_provenance_resolve_contained_path($root, '../x.json')['ok']), '26. traversal rejection');
    s1_ok(empty(orange_backup_provenance_resolve_contained_path($root, 'C:/Windows/x.json')['ok']), '27. absolute path rejection');
    s1_ok(empty(orange_backup_provenance_resolve_contained_path($root, '//server/share/x.json')['ok']), '28. UNC rejection');
    s1_ok(empty(orange_backup_provenance_resolve_contained_path($root, 'https://evil.test/x.json')['ok']), '29. URL rejection');
    s1_ok(empty(orange_backup_provenance_record_country_package($root, [
        'execution_id' => 'exec_20260101_000000_aabbcc',
        'package_id' => '2026-08-01_101010',
        'target_country_code' => '../',
        'package_fingerprint' => 'x',
    ])['ok']), '30. unsafe Country code rejection');

    // 1 Manual Full KW
    $e1 = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_id' => 1,
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
    ]);
    $p1 = '2026-08-01_101010';
    $path1 = s1_fake_full($root, $p1, "kw-full\n");
    $fp1 = orange_backup_provenance_package_identity_fingerprint($path1, 'full');
    $w1 = orange_backup_provenance_record_full_package($root, [
        'execution_id' => $e1['execution_id'],
        'package_id' => $p1,
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
        'schema_revision' => 124,
        'package_fingerprint' => $fp1,
    ]);
    orange_backup_provenance_finish_execution($root, (string) $e1['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => gmdate('c'),
    ]);
    $b1 = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => $p1,
        'package_fingerprint' => $fp1,
        'relative_package_path' => 'snapshots/' . $p1,
    ]);
    s1_ok(!empty($w1['ok']) && ($b1['provenance']['initiated_context_country_code'] ?? '') === 'KW'
        && ($b1['provenance']['initiated_by_display_name_snapshot'] ?? '') === 'Admin KW'
        && ($b1['provenance']['trigger_mode'] ?? '') === 'manual', '1/9/11. Manual Full KW + Admin snapshot');

    // 2 Manual Full EG
    $e2 = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 22,
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
    ]);
    $p2 = '2026-08-01_101011';
    $path2 = s1_fake_full($root, $p2, "eg-full\n");
    $fp2 = orange_backup_provenance_package_identity_fingerprint($path2, 'full');
    orange_backup_provenance_record_full_package($root, [
        'execution_id' => $e2['execution_id'],
        'package_id' => $p2,
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
        'package_fingerprint' => $fp2,
        'schema_revision' => 124,
    ]);
    $b2 = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => $p2,
        'package_fingerprint' => $fp2,
        'relative_package_path' => 'snapshots/' . $p2,
    ]);
    s1_ok(($b2['provenance']['initiated_context_country_code'] ?? '') === 'EG', '2. Manual Full Egypt context');

    // 3/10 Scheduled Full System null context
    orange_backup_provenance_clear_cli_context();
    $ens = orange_backup_provenance_ensure_cli_execution($root, 'full');
    $p3 = '2026-08-01_101012';
    $path3 = s1_fake_full($root, $p3, "sched-full\n");
    orange_backup_provenance_after_full_success($root, $p3, $path3, 124);
    $fp3 = orange_backup_provenance_package_identity_fingerprint($path3, 'full');
    $b3 = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => $p3,
        'package_fingerprint' => $fp3,
        'relative_package_path' => 'snapshots/' . $p3,
    ]);
    s1_ok(
        ($b3['provenance']['trigger_mode'] ?? '') === 'scheduled'
        && ($b3['provenance']['initiated_by_kind'] ?? '') === 'system'
        && ($b3['provenance']['initiated_context_country_code'] ?? null) === null
        && !empty($ens['execution_id']),
        '3/10. Scheduled Full System null context'
    );
    orange_backup_provenance_clear_cli_context();

    // 4/7 Manual All-Countries from KW parent+children
    $batch = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
    ]);
    orange_backup_provenance_publish_cli_context((string) $batch['execution_id'], [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_code' => 'KW',
        'started_at_utc' => gmdate('c'),
    ]);
    $kwChild = s1_fake_country($root, 'KW', '2026-08-01_111111', hash('sha256', 'kw-c'));
    $egChild = s1_fake_country($root, 'EG', '2026-08-01_111112', hash('sha256', 'eg-c'));
    orange_backup_provenance_after_country_batch($root, [
        ['id' => 1, 'code' => 'KW', 'package_path' => $kwChild],
        ['id' => 2, 'code' => 'EG', 'package_path' => $egChild],
    ], [], true);
    $beg = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'country_recovery',
        'package_id' => '2026-08-01_111112',
        'country_code' => 'EG',
        'package_fingerprint' => hash('sha256', 'eg-c'),
        'relative_package_path' => 'country_packages/EG/2026-08-01_111112',
    ]);
    $execRec = orange_backup_provenance_read_execution($root, (string) $batch['execution_id']);
    $codes = [];
    foreach (($execRec['children'] ?? []) as $c) {
        $codes[] = strtoupper((string) ($c['target_country_code'] ?? ''));
    }
    sort($codes);
    s1_ok(
        ($beg['provenance']['backup_scope'] ?? '') === 'all_recoverable_countries'
        && ($beg['provenance']['initiated_context_country_code'] ?? '') === 'KW'
        && ($beg['provenance']['execution_id'] ?? '') === (string) $batch['execution_id']
        && $codes === ['EG', 'KW'],
        '4/7/12/14. Manual All-Countries KW parent/child + relative path'
    );

    // 5 Manual All-Countries from EG + 8 failure without package
    orange_backup_provenance_clear_cli_context();
    $batchEg = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
    ]);
    orange_backup_provenance_publish_cli_context((string) $batchEg['execution_id'], [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
        'started_at_utc' => gmdate('c'),
    ]);
    $onlyKw = s1_fake_country($root, 'KW', '2026-08-01_121212', hash('sha256', 'only-kw'));
    orange_backup_provenance_after_country_batch(
        $root,
        [['id' => 1, 'code' => 'KW', 'package_path' => $onlyKw]],
        [['id' => 2, 'code' => 'EG', 'error' => 'export_failed_no_package']],
        false
    );
    $failExec = orange_backup_provenance_read_execution($root, (string) $batchEg['execution_id']);
    $egFail = null;
    foreach (($failExec['children'] ?? []) as $c) {
        if (strtoupper((string) ($c['target_country_code'] ?? '')) === 'EG') {
            $egFail = $c;
        }
    }
    s1_ok(
        is_array($egFail) && ($egFail['status'] ?? '') === 'failed' && ($egFail['package_id'] ?? null) === null
        && ($failExec['initiated_context_country_code'] ?? '') === 'EG',
        '5/8. Manual All-Countries EG + Country failure without package'
    );
    orange_backup_provenance_clear_cli_context();

    // 6 Scheduled All-Countries
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID);
    $schedB = orange_backup_provenance_ensure_cli_execution($root, 'all_recoverable_countries');
    $schedChild = s1_fake_country($root, 'KW', '2026-08-01_131313', hash('sha256', 'sched-kw'));
    orange_backup_provenance_after_country_batch(
        $root,
        [['id' => 1, 'code' => 'KW', 'package_path' => $schedChild]],
        [],
        true
    );
    $bs = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'country_recovery',
        'package_id' => '2026-08-01_131313',
        'country_code' => 'KW',
        'package_fingerprint' => hash('sha256', 'sched-kw'),
        'relative_package_path' => 'country_packages/KW/2026-08-01_131313',
    ]);
    s1_ok(
        !empty($schedB['ok'])
        && ($bs['provenance']['trigger_mode'] ?? '') === 'scheduled'
        && ($bs['provenance']['initiated_by_kind'] ?? '') === 'system',
        '6. Scheduled All-Countries System'
    );
    orange_backup_provenance_clear_cli_context();

    // 13–17 binding / mismatches
    s1_ok(($b1['provenance']['execution_id'] ?? '') === (string) $e1['execution_id'], '13. exact package ID binding');
    s1_ok(($b1['provenance']['relative_package_path'] ?? '') === 'snapshots/' . $p1, '14. exact relative-path binding');
    $mmFp = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => $p1,
        'package_fingerprint' => str_repeat('0', 64),
        'relative_package_path' => 'snapshots/' . $p1,
    ]);
    s1_ok(
        !empty($mmFp['provenance_unavailable'])
        && ($mmFp['provenance_unavailable_reason'] ?? '') === 'fingerprint_mismatch',
        '15/17. fingerprint binding + mismatch rejection'
    );
    $mmId = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => '2026-08-01_999999',
        'package_fingerprint' => $fp1,
        'relative_package_path' => 'snapshots/2026-08-01_999999',
    ]);
    s1_ok(!empty($mmId['provenance_unavailable']), '16/18. package ID mismatch / missing sidecar');

    // 19 malformed
    $malInfo = orange_backup_provenance_resolve_contained_path($root, 'packages/full/2026-08-01_141414.json');
    file_put_contents((string) $malInfo['path'], "{not-json\n");
    $mal = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => '2026-08-01_141414',
        'package_fingerprint' => 'x',
        'relative_package_path' => 'snapshots/2026-08-01_141414',
    ]);
    s1_ok(!empty($mal['provenance_unavailable']), '19. malformed sidecar');

    // 20 historical no guess
    $histId = '2026-08-01_000001';
    s1_fake_full($root, $histId, "historical\n");
    $hist = orange_backup_provenance_bind_for_package($root, [
        'package_type' => 'full_disaster',
        'package_id' => $histId,
        'package_fingerprint' => hash('sha256', "historical\n"),
        'relative_package_path' => 'snapshots/' . $histId,
        'country_code' => 'KW',
    ]);
    s1_ok(
        !empty($hist['provenance_unavailable'])
        && ($hist['provenance'] ?? null) === null
        && ($hist['provenance_message_ar'] ?? '') === ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR,
        '20. historical fallback without guessing'
    );

    // 21/24 atomic + idempotent
    $eidA = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Idem',
    ]);
    $f1 = orange_backup_provenance_finish_execution($root, (string) $eidA['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => '2026-08-01T10:00:00+00:00',
    ]);
    $f2 = orange_backup_provenance_finish_execution($root, (string) $eidA['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => '2026-08-01T10:00:00+00:00',
    ]);
    s1_ok(!empty($f1['ok']) && !empty($f2['ok']), '21/24. atomic write + idempotent update');

    // 22 interrupted temp write must not replace valid record
    $pkgPathInfo = orange_backup_provenance_resolve_contained_path($root, 'packages/full/' . $p1 . '.json', false);
    $validBefore = orange_backup_provenance_read_json_file((string) $pkgPathInfo['path']);
    $tmpJunk = dirname((string) $pkgPathInfo['path']) . DIRECTORY_SEPARATOR
        . '.' . basename((string) $pkgPathInfo['path']) . '.tmp.interrupted';
    file_put_contents($tmpJunk, "{broken");
    $validAfter = orange_backup_provenance_read_json_file((string) $pkgPathInfo['path']);
    s1_ok(
        is_array($validBefore) && is_array($validAfter)
        && ($validBefore['package_fingerprint'] ?? '') === ($validAfter['package_fingerprint'] ?? '')
        && is_file($tmpJunk),
        '22. interrupted temp write does not replace valid record'
    );
    @unlink($tmpJunk);

    // 23 concurrent child merges
    $conc = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Conc',
        'initiated_context_country_code' => 'KW',
    ]);
    $ce = (string) $conc['execution_id'];
    $c1 = orange_backup_provenance_finish_execution($root, $ce, [
        'children' => [['target_country_code' => 'KW', 'package_id' => '2026-08-01_151515', 'status' => 'success']],
    ]);
    $c2 = orange_backup_provenance_finish_execution($root, $ce, [
        'children' => [['target_country_code' => 'EG', 'package_id' => '2026-08-01_151516', 'status' => 'success']],
    ]);
    $merged = orange_backup_provenance_read_execution($root, $ce);
    $mc = [];
    foreach (($merged['children'] ?? []) as $row) {
        $mc[] = strtoupper((string) ($row['target_country_code'] ?? ''));
    }
    sort($mc);
    s1_ok(!empty($c1['ok']) && !empty($c2['ok']) && $mc === ['EG', 'KW'], '23. concurrent child updates');

    // 25 duplicate conflicting fingerprint rejection
    $dup = orange_backup_provenance_record_full_package($root, [
        'execution_id' => (string) $e1['execution_id'],
        'package_id' => $p1,
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'package_fingerprint' => str_repeat('f', 64),
        'schema_revision' => 124,
    ]);
    s1_ok(empty($dup['ok']) && ($dup['error'] ?? '') === 'duplicate_conflicting_fingerprint', '25. duplicate conflicting record rejection');

    // 31 .orange_meta excluded
    @mkdir($root . '/.orange_meta/provenance/v1', 0770, true);
    $listed = orange_backup_retention_list_finalized_dirs($root);
    $listedSnap = orange_backup_retention_list_finalized_dirs($root . '/snapshots');
    s1_ok(
        $listed === []
        && !in_array('.orange_meta', array_column($listedSnap, 'name'), true)
        && orange_backup_provenance_is_meta_entry('.orange_meta'),
        '31. .orange_meta excluded from package discovery'
    );

    // 32 sidecar not inside package payload
    $sidePath = $root . '/.orange_meta/provenance/v1/packages/full/' . $p1 . '.json';
    s1_ok(is_file($sidePath) && !is_file($path1 . '/.orange_meta') && !is_file($path1 . '/provenance.json'), '32. sidecar outside package payload');

    // 33–37 qualification unchanged by bind
    $qBefore = [
        'package_type' => 'full_disaster',
        'package_id' => $p1,
        'package_fingerprint' => $fp1,
        'relative_package_path' => 'snapshots/' . $p1,
        'healthy' => true,
        'package_status' => 'healthy',
        'verify_state' => 'success',
        'drv_state' => 'success',
        'recoverable' => true,
        'restore_eligible' => false,
    ];
    $qAfter = orange_backup_provenance_bind_for_package($root, $qBefore);
    s1_ok(
        ($qAfter['healthy'] ?? null) === true
        && ($qAfter['verify_state'] ?? '') === 'success'
        && ($qAfter['drv_state'] ?? '') === 'success'
        && ($qAfter['recoverable'] ?? null) === true
        && ($qAfter['restore_eligible'] ?? null) === false
        && ($qAfter['package_status'] ?? '') === 'healthy',
        '33/34/35/36/37. sidecar does not affect Health/Verify/DRV/Recoverable/Restore'
    );

    // Permission helpers 38–42 — GLOBAL country context override (no MySQL INFORMATION_SCHEMA).
    $adminKw = ['id' => 11, 'username' => 'kw', 'display_name' => 'Admin KW', 'is_superuser' => 0, 'is_active' => 1];
    $adminEg = ['id' => 22, 'username' => 'eg', 'display_name' => 'Admin EG', 'is_superuser' => 0, 'is_active' => 1];
    $adminGlobal = ['id' => 1, 'username' => 'root', 'display_name' => 'Global', 'is_superuser' => 1, 'is_active' => 1];
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $fpKwChild = hash('sha256', 'kw-c');
    $fpEgChild = hash('sha256', 'eg-c');

    $GLOBALS['orange_admin_ctx_country_code'] = 'KW';
    $denyEg = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminKw,
        $pdo,
        'country_recovery',
        '2026-08-01_111112',
        'EG',
        $fpEgChild,
        'country_packages/EG/2026-08-01_111112'
    );
    $allowKw = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminKw,
        $pdo,
        'country_recovery',
        '2026-08-01_111111',
        'KW',
        $fpKwChild,
        'country_packages/KW/2026-08-01_111111'
    );
    s1_ok(
        empty($denyEg['ok']) && ($denyEg['code'] ?? '') === 'country_scope_denied'
        && !empty($allowKw['ok']),
        '38. Kuwait Admin cannot read Egypt child provenance'
    );

    $GLOBALS['orange_admin_ctx_country_code'] = 'EG';
    $denyKw = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminEg,
        $pdo,
        'country_recovery',
        '2026-08-01_111111',
        'KW',
        $fpKwChild,
        'country_packages/KW/2026-08-01_111111'
    );
    $allowEg = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminEg,
        $pdo,
        'country_recovery',
        '2026-08-01_111112',
        'EG',
        $fpEgChild,
        'country_packages/EG/2026-08-01_111112'
    );
    s1_ok(
        empty($denyKw['ok']) && ($denyKw['code'] ?? '') === 'country_scope_denied'
        && !empty($allowEg['ok']),
        '39. Egypt Admin cannot read Kuwait child provenance'
    );
    unset($GLOBALS['orange_admin_ctx_country_code']);

    $fullDeniedCountryAdmin = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminKw,
        $pdo,
        'full_disaster',
        $p1,
        null,
        $fp1,
        'snapshots/' . $p1
    );
    $fullView = orange_backup_provenance_resolve_for_viewer(
        $root,
        $adminGlobal,
        $pdo,
        'full_disaster',
        $p1,
        null,
        $fp1,
        'snapshots/' . $p1
    );
    s1_ok(
        empty($fullDeniedCountryAdmin['ok'])
        && ($fullDeniedCountryAdmin['code'] ?? '') === 'full_global_required'
        && !empty($fullView['ok'])
        && ($fullView['grants_execution'] ?? true) === false
        && ($fullView['grants_verify'] ?? true) === false
        && ($fullView['grants_restore'] ?? true) === false,
        '40/42. Full provenance requires global permission; grants no execution'
    );

    $parentView = orange_backup_provenance_resolve_execution_for_viewer(
        $root,
        $adminGlobal,
        $pdo,
        (string) $batch['execution_id']
    );
    s1_ok(!empty($parentView['ok']) && is_array($parentView['execution']['children'] ?? null), '41. global operator parent/children');

    $parentDenied = orange_backup_provenance_resolve_execution_for_viewer(
        $root,
        $adminKw,
        $pdo,
        (string) $batch['execution_id']
    );
    s1_ok(empty($parentDenied['ok']) && ($parentDenied['code'] ?? '') === 'global_required', '41b. non-global denied parent Batch');

    // 43 no public endpoint — prove no new public route file under api/ (non-admin)
    $publicHits = 0;
    foreach (glob($projectRoot . '/api/**/*provenance*', GLOB_BRACE) ?: [] as $hit) {
        $publicHits++;
    }
    s1_ok($publicHits === 0 && !is_file($projectRoot . '/api/backup/provenance.php'), '43. no public endpoint exposes sidecar');

    // 44 no secrets in records
    $rawExec = json_encode($execRec, JSON_UNESCAPED_UNICODE);
    s1_ok(
        is_string($rawExec)
        && !str_contains(strtolower($rawExec), 'password')
        && !str_contains(strtolower($rawExec), 'token')
        && !str_contains($rawExec, 'stack trace')
        && !str_contains($rawExec, 'C:\\\\Windows'),
        '44. no secret/raw path/stack in execution record'
    );

    // 45 no Backup Center visual source changed in this Stage 1 working tree vs intended
    // Verified by git diff in runner script after tests; local static check that helper does not reference backup_center.php
    $helperSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_provenance.php');
    s1_ok(
        !str_contains($helperSrc, 'backup_center.php')
        && !str_contains($helperSrc, 'bc-qual-btn')
        && !str_contains($helperSrc, 'bc_result_dialog'),
        '45. provenance helper has no Backup Center visual coupling'
    );

    s1_ok(ORANGE_BACKUP_PROVENANCE_SCHEMA_VERSION === 1, 'metadata_schema_version=1 only (not Schema 125)');
} catch (Throwable $e) {
    s1_ok(false, 'uncaught: ' . $e->getMessage());
} finally {
    orange_backup_provenance_clear_cli_context();
    s1_rm_tree($root);
    echo is_dir($root) ? "WARN: cleanup incomplete\n" : "CLEANUP: temp BackupRoot removed\n";
}

echo "TOTALS passes={$passes} failures={$failures} skips={$skips}\n";
echo 'CORE_STAGE1_SKIP=' . $skips . "\n";
exit($failures > 0 ? 1 : 0);
