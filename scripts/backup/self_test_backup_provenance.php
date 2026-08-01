<?php

declare(strict_types=1);

/**
 * Focused self-tests for Backup Provenance Metadata Registry (sidecar).
 *
 * Usage:
 *   php scripts/backup/self_test_backup_provenance.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_provenance.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';

$failures = 0;
$passes = 0;
$skips = 0;

function st_ok(bool $ok, string $label): void
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

function st_skip(string $label, string $reason): void
{
    global $skips;
    echo "SKIP: {$label} ({$reason})\n";
    $skips++;
}

function st_temp_root(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_bak_prov_' . bin2hex(random_bytes(4));
    if (!@mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Cannot create temp BackupRoot');
    }

    return $base;
}

function st_rm_tree(string $dir): void
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
    if (!str_contains($normDir, '/orange_bak_prov_')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($resolved);
}

function st_fake_full_package(string $backupRoot, string $packageId, string $checksumBody = "abc  dump.sql.gz\n"): string
{
    $path = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $packageId;
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create package dir');
    }
    file_put_contents($path . DIRECTORY_SEPARATOR . 'checksums.sha256', $checksumBody);
    file_put_contents(
        $path . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode([
            'package_type' => 'full_disaster',
            'schema_revision' => 124,
            'generated_at' => gmdate('c'),
            'backup_status' => 'healthy',
        ], JSON_UNESCAPED_UNICODE)
    );
    file_put_contents(
        $path . DIRECTORY_SEPARATOR . 'health.json',
        json_encode(['package_status' => 'healthy'], JSON_UNESCAPED_UNICODE)
    );

    return $path;
}

function st_fake_country_package(
    string $backupRoot,
    string $cc,
    string $packageId,
    string $fingerprint
): string {
    $path = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . $cc
        . DIRECTORY_SEPARATOR . $packageId;
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create country package dir');
    }
    file_put_contents(
        $path . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode([
            'package_type' => 'country_recovery',
            'country_id' => $cc === 'KW' ? 1 : 2,
            'schema_revision' => 124,
            'package_fingerprint' => $fingerprint,
            'generated_at' => gmdate('c'),
            'backup_status' => 'healthy',
        ], JSON_UNESCAPED_UNICODE)
    );
    file_put_contents(
        $path . DIRECTORY_SEPARATOR . 'health.json',
        json_encode(['package_status' => 'healthy'], JSON_UNESCAPED_UNICODE)
    );

    return $path;
}

$root = st_temp_root();
echo "BackupRoot={$root}\n";

try {
    st_ok(orange_backup_provenance_is_meta_entry('.orange_meta'), 'meta entry detector');
    st_ok(!orange_backup_provenance_is_meta_entry('snapshots'), 'non-meta entry detector');

    // Path rejection
    $bad = orange_backup_provenance_resolve_contained_path($root, '../outside.json');
    st_ok(empty($bad['ok']), 'reject traversal relative');
    $bad = orange_backup_provenance_resolve_contained_path($root, 'C:/Windows/x.json');
    st_ok(empty($bad['ok']), 'reject absolute path');
    $bad = orange_backup_provenance_resolve_contained_path($root, '//server/share/x.json');
    st_ok(empty($bad['ok']), 'reject UNC');
    $bad = orange_backup_provenance_resolve_contained_path($root, 'https://evil.example/x.json');
    st_ok(empty($bad['ok']), 'reject URL');

    // Manual Full from Kuwait context
    $execKw = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_id' => 1,
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
    ]);
    st_ok(!empty($execKw['ok']) && !empty($execKw['execution_id']), '1. begin manual full KW');
    $pkgIdKw = '2026-07-17_101010';
    $pkgPathKw = st_fake_full_package($root, $pkgIdKw, "kw-full dump\n");
    $fpKw = orange_backup_provenance_package_identity_fingerprint($pkgPathKw, 'full');
    $recKw = orange_backup_provenance_record_full_package($root, [
        'execution_id' => $execKw['execution_id'],
        'package_id' => $pkgIdKw,
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_id' => 1,
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
        'schema_revision' => 124,
        'package_fingerprint' => $fpKw,
        'package_result_status' => 'success',
    ]);
    st_ok(!empty($recKw['ok']), '1. record manual full KW package');
    orange_backup_provenance_finish_execution($root, (string) $execKw['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => gmdate('c'),
    ]);
    $boundKw = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => $pkgIdKw,
        'package_fingerprint' => $fpKw,
    ]);
    st_ok(
        empty($boundKw['provenance_unavailable'])
        && ($boundKw['provenance']['trigger_mode'] ?? '') === 'manual'
        && ($boundKw['provenance']['initiated_context_country_code'] ?? '') === 'KW'
        && ($boundKw['provenance']['initiated_by_display_name_snapshot'] ?? '') === 'Admin KW',
        '1/9. bind manual full KW identity+context'
    );

    // Manual Full from Egypt
    $execEg = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 22,
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_id' => 2,
        'initiated_context_country_code' => 'EG',
        'initiated_context_country_name' => 'مصر',
    ]);
    $pkgIdEg = '2026-07-17_101011';
    $pkgPathEg = st_fake_full_package($root, $pkgIdEg, "eg-full dump\n");
    $fpEg = orange_backup_provenance_package_identity_fingerprint($pkgPathEg, 'full');
    orange_backup_provenance_record_full_package($root, [
        'execution_id' => $execEg['execution_id'],
        'package_id' => $pkgIdEg,
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 22,
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
        'schema_revision' => 124,
        'package_fingerprint' => $fpEg,
    ]);
    $boundEg = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => $pkgIdEg,
        'package_fingerprint' => $fpEg,
    ]);
    st_ok(
        ($boundEg['provenance']['initiated_context_country_code'] ?? '') === 'EG'
        && ($boundEg['provenance']['initiated_by_display_name_snapshot'] ?? '') === 'Admin EG',
        '2/9. manual full EG context+admin snapshot'
    );

    // Scheduled Full — System / null context
    $sched = orange_backup_provenance_ensure_cli_execution($root, 'full');
    st_ok(!empty($sched['ok']) && !empty($sched['execution_id']), '3. scheduled full ensure execution');
    $pkgIdSched = '2026-07-17_101012';
    $pkgPathSched = st_fake_full_package($root, $pkgIdSched, "sched-full dump\n");
    $fpSched = orange_backup_provenance_package_identity_fingerprint($pkgPathSched, 'full');
    orange_backup_provenance_after_full_success($root, $pkgIdSched, $pkgPathSched, 124);
    $boundSched = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => $pkgIdSched,
        'package_fingerprint' => $fpSched,
    ]);
    st_ok(
        ($boundSched['provenance']['trigger_mode'] ?? '') === 'scheduled'
        && ($boundSched['provenance']['initiated_by_kind'] ?? '') === 'system'
        && ($boundSched['provenance']['initiated_context_country_code'] ?? null) === null,
        '3/10/30. scheduled full System null context'
    );
    orange_backup_provenance_clear_cli_context();

    // Manual All-Countries from Kuwait (parent + children)
    $batchKw = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
    ]);
    $kwChildId = '2026-07-17_111111';
    $egChildId = '2026-07-17_111112';
    $kwChildPath = st_fake_country_package($root, 'KW', $kwChildId, hash('sha256', 'kw-child'));
    $egChildPath = st_fake_country_package($root, 'EG', $egChildId, hash('sha256', 'eg-child'));
    orange_backup_provenance_publish_cli_context((string) $batchKw['execution_id'], [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 11,
        'initiated_by_display_name_snapshot' => 'Admin KW',
        'initiated_context_country_code' => 'KW',
        'initiated_context_country_name' => 'الكويت',
        'started_at_utc' => gmdate('c'),
    ]);
    $batchProv = orange_backup_provenance_after_country_batch(
        $root,
        [
            ['id' => 1, 'code' => 'KW', 'package_path' => $kwChildPath],
            ['id' => 2, 'code' => 'EG', 'package_path' => $egChildPath],
        ],
        [],
        true
    );
    st_ok(!empty($batchProv['ok']), '4/7. manual all-countries KW parent+children write');
    $boundChildEg = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'country_recovery',
        'package_id' => $egChildId,
        'country_code' => 'EG',
        'package_fingerprint' => hash('sha256', 'eg-child'),
    ]);
    st_ok(
        ($boundChildEg['provenance']['backup_scope'] ?? '') === 'all_recoverable_countries'
        && ($boundChildEg['provenance']['initiated_context_country_code'] ?? '') === 'KW'
        && ($boundChildEg['provenance']['execution_id'] ?? '') === (string) $batchKw['execution_id'],
        '4/12/26. EG child shows KW origin context + parent execution'
    );
    $execRec = orange_backup_provenance_read_execution($root, (string) $batchKw['execution_id']);
    $childCodes = array_map(
        static fn (array $c): string => strtoupper((string) ($c['target_country_code'] ?? '')),
        is_array($execRec['children'] ?? null) ? $execRec['children'] : []
    );
    st_ok(in_array('KW', $childCodes, true) && in_array('EG', $childCodes, true), '7/14. parent children KW+EG');

    // Parent with one failure / no package
    $batchFail = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_admin_id' => 22,
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
    ]);
    orange_backup_provenance_publish_cli_context((string) $batchFail['execution_id'], [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Admin EG',
        'initiated_context_country_code' => 'EG',
        'started_at_utc' => gmdate('c'),
    ]);
    $okOnlyKw = st_fake_country_package($root, 'KW', '2026-07-17_121212', hash('sha256', 'only-kw'));
    orange_backup_provenance_after_country_batch(
        $root,
        [['id' => 1, 'code' => 'KW', 'package_path' => $okOnlyKw]],
        [['id' => 2, 'code' => 'EG', 'error' => 'export_failed_no_package']],
        false
    );
    $failExec = orange_backup_provenance_read_execution($root, (string) $batchFail['execution_id']);
    $egOutcome = null;
    foreach (($failExec['children'] ?? []) as $c) {
        if (strtoupper((string) ($c['target_country_code'] ?? '')) === 'EG') {
            $egOutcome = $c;
            break;
        }
    }
    st_ok(
        is_array($egOutcome)
        && ($egOutcome['status'] ?? '') === 'failed'
        && ($egOutcome['package_id'] ?? null) === null
        && str_contains((string) ($egOutcome['error_summary'] ?? ''), 'export_failed'),
        '5/8. EG failure outcome without package'
    );
    orange_backup_provenance_clear_cli_context();

    // Scheduled all-countries
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID);
    $schedBatch = orange_backup_provenance_ensure_cli_execution($root, 'all_recoverable_countries');
    $schedChild = st_fake_country_package($root, 'KW', '2026-07-17_131313', hash('sha256', 'sched-kw'));
    orange_backup_provenance_after_country_batch(
        $root,
        [['id' => 1, 'code' => 'KW', 'package_path' => $schedChild]],
        [],
        true
    );
    $boundSchedChild = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'country_recovery',
        'package_id' => '2026-07-17_131313',
        'country_code' => 'KW',
        'package_fingerprint' => hash('sha256', 'sched-kw'),
    ]);
    st_ok(
        ($boundSchedChild['provenance']['trigger_mode'] ?? '') === 'scheduled'
        && ($boundSchedChild['provenance']['initiated_by_kind'] ?? '') === 'system'
        && ($boundSchedChild['provenance']['initiated_context_country_code'] ?? null) === null,
        '6/10/30. scheduled country batch System'
    );
    orange_backup_provenance_clear_cli_context();

    // Fingerprint mismatch / package id mismatch / missing / malformed
    $mismatch = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => $pkgIdKw,
        'package_fingerprint' => str_repeat('0', 64),
    ]);
    st_ok(
        !empty($mismatch['provenance_unavailable'])
        && ($mismatch['provenance_unavailable_reason'] ?? '') === 'fingerprint_mismatch'
        && ($mismatch['provenance_message_ar'] ?? '') === ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR,
        '13. mismatched fingerprint ignored'
    );

    $wrongId = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => '2026-07-17_999999',
        'package_fingerprint' => $fpKw,
    ]);
    st_ok(!empty($wrongId['provenance_unavailable']), '12/14. missing sidecar / wrong package id');

    $histId = '2026-07-17_000001';
    st_fake_full_package($root, $histId, "historical\n");
    $hist = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => $histId,
        'package_fingerprint' => hash('sha256', "historical\n"),
    ]);
    st_ok(
        !empty($hist['provenance_unavailable'])
        && str_contains((string) ($hist['provenance_message_ar'] ?? ''), 'غير متاحة'),
        '16/31. historical unavailable origin (no guess)'
    );

    // Do not guess from filename/path/current country
    $guess = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'country_recovery',
        'package_id' => '2026-07-17_000002',
        'country_code' => 'KW',
        'package_fingerprint' => '',
        'package_path' => $root . '/country_packages/KW/2026-07-17_000002',
    ]);
    st_ok(
        !empty($guess['provenance_unavailable']) && ($guess['provenance'] ?? null) === null,
        'no provenance guess from path/country/filename'
    );

    // Malformed sidecar
    $malPathInfo = orange_backup_provenance_resolve_contained_path(
        $root,
        'packages/full/2026-07-17_141414.json'
    );
    st_ok(!empty($malPathInfo['ok']), 'malformed sidecar path create');
    file_put_contents((string) $malPathInfo['path'], "{not-json\n");
    $malBound = orange_backup_provenance_bind_for_list($root, [
        'package_type' => 'full_disaster',
        'package_id' => '2026-07-17_141414',
        'package_fingerprint' => 'x',
    ]);
    st_ok(!empty($malBound['provenance_unavailable']), '15. malformed sidecar unavailable');

    // Atomic write + idempotent update
    $atomicExec = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'full',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Idempotent',
    ]);
    $fin1 = orange_backup_provenance_finish_execution($root, (string) $atomicExec['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => '2026-07-17T10:00:00+00:00',
    ]);
    $fin2 = orange_backup_provenance_finish_execution($root, (string) $atomicExec['execution_id'], [
        'overall_status' => 'success',
        'completed_at_utc' => '2026-07-17T10:00:00+00:00',
    ]);
    st_ok(!empty($fin1['ok']) && !empty($fin2['ok']), '17/19. atomic + idempotent finish');

    // Concurrent child updates
    $conc = orange_backup_provenance_begin_execution($root, [
        'trigger_mode' => 'manual',
        'backup_scope' => 'all_recoverable_countries',
        'initiated_by_kind' => 'admin',
        'initiated_by_display_name_snapshot' => 'Concurrent',
        'initiated_context_country_code' => 'KW',
    ]);
    $eid = (string) $conc['execution_id'];
    $w1 = orange_backup_provenance_finish_execution($root, $eid, [
        'children' => [['target_country_code' => 'KW', 'package_id' => '2026-07-17_151515', 'status' => 'success']],
    ]);
    $w2 = orange_backup_provenance_finish_execution($root, $eid, [
        'children' => [['target_country_code' => 'EG', 'package_id' => '2026-07-17_151516', 'status' => 'success']],
    ]);
    $merged = orange_backup_provenance_read_execution($root, $eid);
    $codes = [];
    foreach (($merged['children'] ?? []) as $c) {
        $codes[] = strtoupper((string) ($c['target_country_code'] ?? ''));
    }
    sort($codes);
    st_ok(!empty($w1['ok']) && !empty($w2['ok']) && $codes === ['EG', 'KW'], '18. concurrent child merges');

    // .orange_meta excluded from package scans (finalized name pattern)
    $metaAsPkg = orange_backup_retention_list_finalized_dirs($root . DIRECTORY_SEPARATOR . '.orange_meta');
    st_ok($metaAsPkg === [], '21. .orange_meta not listed as finalized packages');

    // Sidecar must not alter verify/drv/recoverable derivation inputs
    $summaryBefore = [
        'package_type' => 'full_disaster',
        'package_id' => $pkgIdKw,
        'package_fingerprint' => $fpKw,
        'healthy' => true,
        'package_status' => 'healthy',
        'verify_state' => 'success',
        'drv_state' => 'success',
    ];
    $summaryAfter = orange_backup_provenance_bind_for_list($root, $summaryBefore);
    st_ok(
        ($summaryAfter['healthy'] ?? null) === true
        && ($summaryAfter['verify_state'] ?? '') === 'success'
        && ($summaryAfter['drv_state'] ?? '') === 'success'
        && ($summaryAfter['package_status'] ?? '') === 'healthy',
        '23. sidecar bind does not change qualification fields'
    );

    // Verify state derivation helpers
    st_ok(orange_backup_admin_derive_verify_state(null, null, true) === 'not_run', '33. verify not_run');
    st_ok(orange_backup_admin_derive_verify_state(['overall' => 'PASS', 'ok' => true], null, true) === 'success', '33. verify success');
    st_ok(orange_backup_admin_derive_verify_state(['overall' => 'FAIL', 'ok' => false], null, true) === 'failed', '33/37. verify failed');
    st_ok(orange_backup_admin_derive_drv_state(null, 'not_run') === 'blocked', '34. drv blocked before verify');
    st_ok(orange_backup_admin_derive_drv_state(['overall_result' => 'pass'], 'success') === 'success', '33. drv success');

    // UI static contract (Backup Center page)
    $ui = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'backup_center.php');
    st_ok(str_contains($ui, 'bc-primary-cluster') && str_contains($ui, 'qualBtnHtml'), '32. primary cluster Details+Verify+DRV');
    st_ok(str_contains($ui, 'bc_result_dialog') && str_contains($ui, 'bc_result_close') && !str_contains($ui, 'bc_result_x'), '39/40. result dialog Close-only');
    st_ok(str_contains($ui, 'تقرير تحقق استرداد الدولة'), '44. Country DRV renamed');
    st_ok(str_contains($ui, 'formatCountryDrvHuman'), '45. country DRV human renderer');
    st_ok(str_contains($ui, 'is-not-run') && str_contains($ui, 'لم يُنفذ بعد'), '33. verify not_run UI copy');
    st_ok(str_contains($ui, 'بانتظار نجاح Verify'), '34. drv blocked UI copy');
    st_ok(str_contains($ui, 'bc-report-control'), '43. report visual classes');
    st_ok(str_contains($ui, 'معلومات مصدر التشغيل غير متاحة لهذه الحزمة.'), '31. historical unavailable UI copy');
    st_ok(str_contains($ui, 'refreshPackageRow') && str_contains($ui, 'scrollY'), '42. accordion/scroll continuity helpers');

    // Visibility helpers: list scoping still uses onlyCountryCode (existing permission boundary)
    st_ok(
        str_contains(
            (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php'),
            'orange_backup_admin_filter_country_codes'
        ),
        '24/25/28. country list filter helper retained'
    );

    // Full verify sibling report persist (outside package payload)
    $vPkg = st_fake_full_package($root, '2026-07-17_161616', "verify-me\n");
    orange_backup_admin_persist_full_verify_report($vPkg, '2026-07-17_161616', [
        'ok' => true,
        'errors' => [],
        'warnings' => [],
    ]);
    $sibling = dirname($vPkg) . DIRECTORY_SEPARATOR . '2026-07-17_161616.full_verify_report.json';
    st_ok(is_file($sibling) && !is_file($vPkg . DIRECTORY_SEPARATOR . 'full_verify_report.json'), '22. verify report sibling not inside package payload');

    // Schema claim guard: provenance schema version is not app schema 125
    st_ok(ORANGE_BACKUP_PROVENANCE_SCHEMA_VERSION === 1, 'metadata_schema_version=1 only');
} catch (Throwable $e) {
    st_ok(false, 'uncaught: ' . $e->getMessage());
} finally {
    orange_backup_provenance_clear_cli_context();
    st_rm_tree($root);
    if (is_dir($root)) {
        echo "WARN: temp root cleanup incomplete: {$root}\n";
    } else {
        echo "CLEANUP: temp BackupRoot removed\n";
    }
}

echo "TOTALS passes={$passes} failures={$failures} skips={$skips}\n";
exit($failures > 0 ? 1 : 0);
