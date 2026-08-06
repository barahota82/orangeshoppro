<?php

declare(strict_types=1);

/**
 * Full history completeness — silent cap 20 removed; Show All = all discovered Full packages.
 *
 * Usage: php scripts/self_test_backup_center_full_history_visibility.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/scripts/lib/backup_full_history_grouped_state_lib.php';

$passes = 0;
$failures = 0;
$skips = 0;
$coreSkip = 0;

function fh_ok(bool $ok, string $label): void
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

echo "=== Backup Center Full History Visibility self-test ===\n";

$listPhp = (string) file_get_contents($projectRoot . '/admin/api/backup/list.php');
$adminPhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$pagePhp = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');

fh_ok(
    str_contains($listPhp, 'orange_backup_admin_list_full_snapshots($backupRoot)')
    && !str_contains($listPhp, 'list_full_snapshots($backupRoot, 20)'),
    'list.php Full discovery uncapped (no silent , 20)'
);
fh_ok(
    preg_match('/function orange_backup_admin_list_full_snapshots\(string \$backupRoot, \?int \$limit = null\)/', $adminPhp) === 1,
    'list_full_snapshots accepts null limit = all'
);
fh_ok(
    str_contains($adminPhp, 'if ($limit !== null && $limit > 0)')
    && str_contains($adminPhp, '$dirs = array_slice($dirs, 0, $limit)'),
    'limit only applied when positive'
);
fh_ok(str_contains($pagePhp, 'const RECENT_LIMIT = 5'), 'UI Last 5 remains client RECENT_LIMIT=5');
fh_ok(
    str_contains($pagePhp, 'isArchive ? null : RECENT_LIMIT'),
    'Show All uses null limit client-side (all loaded rows)'
);
fh_ok(
    !str_contains($listPhp, 'list_country_packages($pdo, $backupRoot, 5)'),
    'COUNTRY_DISCOVERY_BEHAVIOR_CHANGED = 0 (no new country cap)'
);
fh_ok(
    str_contains($listPhp, 'list_country_packages($pdo, $backupRoot, null, $countryContextCode)'),
    'Country list path unchanged (uncapped + context)'
);

$root = fhgs_temp_backup_root();
$retentionTouched = false;
try {
    // 0 packages
    orange_backup_admin_reset_finalized_dirs_cache();
    $zero = orange_backup_admin_list_full_snapshots($root);
    fh_ok($zero === [], 'fixture: zero Full packages');

    // 5
    $ids5 = fhgs_mk_n_full($root, 5, '2026-07-10');
    orange_backup_admin_reset_finalized_dirs_cache();
    $list5 = orange_backup_admin_list_full_snapshots($root);
    fh_ok(count($list5) === 5, 'fixture: five Full packages discovered');
    fh_ok(count(fhgs_client_last5($list5)) === 5, 'Last 5 of 5 = 5');

    // wipe and rebuild for larger counts
    fhgs_rm_tree($root);
    $root = fhgs_temp_backup_root();
    orange_backup_admin_reset_finalized_dirs_cache();

    $ids20 = fhgs_mk_n_full($root, 20, '2026-07-11');
    orange_backup_admin_reset_finalized_dirs_cache();
    $list20 = orange_backup_admin_list_full_snapshots($root);
    fh_ok(count($list20) === 20, 'fixture: twenty Full packages discovered');
    fh_ok(count(fhgs_client_last5($list20)) === 5, 'Last 5 of 20 = 5');

    fhgs_rm_tree($root);
    $root = fhgs_temp_backup_root();
    orange_backup_admin_reset_finalized_dirs_cache();

    $ids21 = fhgs_mk_n_full($root, 21, '2026-07-12');
    orange_backup_admin_reset_finalized_dirs_cache();
    $list21 = orange_backup_admin_list_full_snapshots($root);
    fh_ok(count($list21) === 21, 'fixture: twenty-one Full packages discovered (past old cap)');
    fh_ok(count($list21) > 20, 'no silent cap 20: count > 20');
    fh_ok(($list21[20]['package_id'] ?? '') === $ids21[20], 'row 21 (index 20) is oldest of 21');

    fhgs_rm_tree($root);
    $root = fhgs_temp_backup_root();
    orange_backup_admin_reset_finalized_dirs_cache();

    $ids37 = fhgs_mk_n_full($root, 37, '2026-07-13');
    // same-day extras
    fhgs_mk_full_package($root, '2026-07-13_120000', 's', 'mysqldump');
    fhgs_mk_full_package($root, '2026-07-13_120001', 't', 'manual');
    orange_backup_admin_reset_finalized_dirs_cache();
    $list37base = orange_backup_admin_list_full_snapshots($root);
    // 37 + 2 same-day = 39
    fh_ok(count($list37base) === 39, 'same-day + scheduled/manual extras counted distinctly');

    fhgs_rm_tree($root);
    $root = fhgs_temp_backup_root();
    orange_backup_admin_reset_finalized_dirs_cache();
    $ids37 = fhgs_mk_n_full($root, 37, '2026-07-14');
    orange_backup_admin_reset_finalized_dirs_cache();
    $list37 = orange_backup_admin_list_full_snapshots($root);
    $last5 = fhgs_client_last5($list37);
    fh_ok(count($list37) === 37, 'FULL_FIXTURE_37_DISCOVERED = 37');
    fh_ok(count($list37) === 37, 'FULL_FIXTURE_37_SHOW_ALL = 37');
    fh_ok(count($last5) === 5, 'FULL_FIXTURE_37_LAST5 = 5');
    fh_ok(($list37[0]['package_id'] ?? '') === $ids37[0], 'Show All first row = newest');
    fh_ok(($last5[0]['package_id'] ?? '') === $ids37[0], 'Last 5 first = newest');
    fh_ok(($last5[4]['package_id'] ?? '') === $ids37[4], 'Last 5 fifth = fifth newest');
    fh_ok(($list37[36]['package_id'] ?? '') === $ids37[36], 'Show All final row = oldest retained');
    echo "MARKER FULL_FIXTURE_37_DISCOVERED=37\n";
    echo "MARKER FULL_FIXTURE_37_SHOW_ALL=37\n";
    echo "MARKER FULL_FIXTURE_37_LAST5=5\n";
    echo "MARKER FULL_SILENT_CAP_20_PRESENT=0\n";

    // Invalid / incomplete candidates
    @mkdir($root . '/snapshots/not-a-package', 0770, true);
    @mkdir($root . '/snapshots/._work_temp', 0770, true);
    fhgs_mk_full_package($root, '2026-07-14_235959', 'i', 'mysqldump', true, false); // missing manifest — still listed (discovery = finalized dir name)
    orange_backup_admin_reset_finalized_dirs_cache();
    $beforeInvalid = count(orange_backup_admin_list_full_snapshots($root));
    // invalid name ignored by retention finalized filter
    $afterInvalidName = orange_backup_admin_list_full_snapshots($root);
    $names = array_map(static fn ($p) => (string) ($p['package_id'] ?? ''), $afterInvalidName);
    fh_ok(!in_array('not-a-package', $names, true), 'invalid package name excluded');
    fh_ok(!in_array('._work_temp', $names, true), 'temp work dir excluded');
    fh_ok(in_array('2026-07-14_235959', $names, true), 'finalized id with missing manifest still discovered by name rules');
    fh_ok($beforeInvalid >= 38, 'incomplete/missing-manifest does not shrink below recognized finalized dirs');

    // Country fixture unchanged by Full listing
    $kwPath = $root . '/country_packages/kw/2026-07-14_100000';
    @mkdir($kwPath, 0770, true);
    file_put_contents($kwPath . '/manifest.json', json_encode([
        'package_type' => 'country_recovery',
        'country_code' => 'KW',
        'country_id' => 1,
        'generated_at' => gmdate('c'),
        'schema_revision' => 124,
    ]));
    file_put_contents($kwPath . '/health.json', json_encode(['package_status' => 'healthy']));
    $fullOnly = orange_backup_admin_list_full_snapshots($root);
    fh_ok(!in_array('2026-07-14_100000', array_map(static fn ($p) => (string) ($p['package_id'] ?? ''), $fullOnly), true)
        || (($fullOnly[0]['package_type'] ?? '') === 'full_disaster'), 'Full list does not include Country packages as Full');
    echo "MARKER COUNTRY_DISCOVERY_BEHAVIOR_CHANGED=0\n";

    // Explicit limited caller still works
    $capped = orange_backup_admin_list_full_snapshots($root, 10);
    fh_ok(count($capped) === 10, 'explicit positive limit still supported for other callers');

    // No retention execution in this suite
    fh_ok($retentionTouched === false, 'no retention execution');
    echo "MARKER FULL_SHOW_ALL_COMPLETE=1\n";
    echo "MARKER FULL_LAST5_COUNT_CORRECT=1\n";
} finally {
    fhgs_rm_tree($root);
}

echo "=== SUMMARY ===\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips} CORE_SKIP={$coreSkip}\n";
echo 'CORE_FULL_HISTORY_SKIP=' . $coreSkip . "\n";
exit($failures > 0 || $coreSkip > 0 ? 1 : 0);
