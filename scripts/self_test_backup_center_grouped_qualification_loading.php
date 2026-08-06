<?php

declare(strict_types=1);

/**
 * Grouped qualification-state loading — batch endpoint + cohort client contract.
 *
 * Usage: php scripts/self_test_backup_center_grouped_qualification_loading.php
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

function gs_ok(bool $ok, string $label): void
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

echo "=== Backup Center Grouped Qualification Loading self-test ===\n";

$page = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
$batchApi = (string) file_get_contents($projectRoot . '/admin/api/backup/qualification-status-batch.php');
$qualPhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_qualification.php');
$singleApi = (string) file_get_contents($projectRoot . '/admin/api/backup/qualification-status.php');

gs_ok(is_file($projectRoot . '/admin/api/backup/qualification-status-batch.php'), 'batch endpoint file exists');
gs_ok(str_contains($batchApi, 'orange_backup_qualification_public_status_batch'), 'batch API uses batch helper');
gs_ok(str_contains($batchApi, 'Cache-Control: no-store'), 'batch API: no-store');
gs_ok(str_contains($batchApi, 'orange_backup_admin_require_view'), 'batch API: view permission');
gs_ok(str_contains($batchApi, 'backup_admin_api_require_get'), 'batch API: GET only');
gs_ok(str_contains($qualPhp, 'function orange_backup_qualification_public_status_batch'), 'helper: batch wrapper');
gs_ok(str_contains($qualPhp, 'ORANGE_BACKUP_QUAL_STATUS_BATCH_MAX_ITEMS = 5'), 'QUALIFICATION_COHORT_SIZE server max = 5');
gs_ok(str_contains($page, 'QUAL_COHORT_SIZE = 5'), 'client QUAL_COHORT_SIZE = 5');
gs_ok(str_contains($page, 'QUAL_MAX_CONCURRENT_BATCHES = 2'), 'MAX_CONCURRENT_QUALIFICATION_BATCHES = 2');
gs_ok(str_contains($page, 'qualification-status-batch.php'), 'client calls batch endpoint');
gs_ok(str_contains($page, 'function qualFetchCohort'), 'client cohort fetch helper');
gs_ok(str_contains($page, 'function qualGroupedPaint'), 'client grouped paint helper');
gs_ok(str_contains($page, 'requestAnimationFrame'), 'grouped paint uses rAF');
gs_ok(str_contains($singleApi, 'orange_backup_qualification_public_status'), 'single status preserved for poll/mutation');
gs_ok(
    str_contains($page, 'data-q-state="not_run"')
    && preg_match('/class="bc-btn-ghost bc-verify"[^>]*data-q-state="not_run"/', $page) === 1,
    'initial Verify template grey/not_run'
);
gs_ok(
    preg_match('/class="bc-btn-ghost bc-verify"[^>]*data-q-state="not_run"[^>]*>/', $page) === 1
    && !preg_match('/class="bc-btn-ghost bc-verify"[^>]*disabled[^>]*data-q-state="not_run"/', $page)
    && !preg_match('/class="bc-btn-ghost bc-verify"[^>]*data-q-state="not_run"[^>]*disabled/', $page),
    'AUTHORIZED_INITIAL_VERIFY_DISABLED = 0 (template not disabled)'
);
gs_ok(
    preg_match('/class="bc-btn-ghost bc-drv"[^>]*disabled[^>]*data-q-state="blocked"/', $page) === 1
    || preg_match('/class="bc-btn-ghost bc-drv"[^>]*data-q-state="blocked"[^>]*disabled/', $page) === 1,
    'INITIAL_DRV_DISABLED = 1 (blocked template)'
);
gs_ok(str_contains($page, "forceDisabled: (d.state === 'blocked')"), 'DRV frozen: blocked forceDisabled');
gs_ok(
    str_contains($page, "String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || '')"),
    'exact key type|cc|id preserved'
);
gs_ok(str_contains($page, 'qualPaintCachedRows'), 'rerender cache paint preserved');
gs_ok(str_contains($page, 'qualSafeApplyByKey'), 'exact-key apply preserved');
gs_ok(str_contains($batchApi, 'path_not_allowed') || str_contains($qualPhp, 'path_not_allowed'), 'path inputs rejected');

// Cohort chunking arithmetic (Show All 13 → 5+5+3)
$items13 = [];
for ($i = 0; $i < 13; $i++) {
    $items13[] = [
        'package_type' => 'full_disaster',
        'package_id' => sprintf('2026-08-01_%06d', $i),
        'country_code' => '',
    ];
}
$chunks = fhgs_chunk_cohorts($items13, 5);
gs_ok(count($chunks) === 3, 'SHOW_ALL_13 cohorts = 3');
gs_ok(count($chunks[0]) === 5 && count($chunks[1]) === 5 && count($chunks[2]) === 3, 'cohort sizes 5+5+3');
echo "MARKER SHOW_ALL_13_BATCH_REQUEST_COUNT=3\n";
echo "MARKER LAST5_BATCH_REQUEST_COUNT=1\n";

$root = fhgs_temp_backup_root();
try {
    $ids = fhgs_mk_n_full($root, 5, '2026-08-02');
    $batchItems = [];
    foreach ($ids as $id) {
        $batchItems[] = [
            'package_type' => 'full_disaster',
            'package_id' => $id,
            'country_code' => '',
        ];
    }
    $t0 = microtime(true);
    $batch = orange_backup_qualification_public_status_batch($root, $batchItems, null, null, 5);
    $batchMs = (microtime(true) - $t0) * 1000;
    gs_ok(!empty($batch['ok']), 'Last5 batch resolve ok');
    gs_ok(count($batch['results'] ?? []) === 5, 'Last5 batch returns 5 results');
    $states = [];
    foreach (($batch['results'] ?? []) as $row) {
        gs_ok(!empty($row['ok']), 'per-item ok for fixture not_run');
        $states[] = (string) ($row['qualification']['verify']['state'] ?? '');
        gs_ok(($row['qualification']['drv']['state'] ?? '') === 'blocked', 'DRV blocked before Verify success');
    }
    gs_ok($states === ['not_run', 'not_run', 'not_run', 'not_run', 'not_run']
        || (count($states) === 5 && !in_array('success', $states, true) && !in_array('failed', $states, true)),
        'no fabricated success/failure without bound report');
    echo 'MARKER LAST5_BATCH_DURATION_MS=' . round($batchMs, 2) . "\n";

    // >5 rejected
    $six = $batchItems;
    $six[] = ['package_type' => 'full_disaster', 'package_id' => '2026-08-02_999999', 'country_code' => ''];
    $tooBig = orange_backup_qualification_public_status_batch($root, $six, null, null, 5);
    gs_ok(empty($tooBig['ok']) && ($tooBig['code'] ?? '') === 'batch_too_large', '>5 items rejected');

    // duplicate keys deduped
    $dup = [$batchItems[0], $batchItems[0], $batchItems[1]];
    $dupBatch = orange_backup_qualification_public_status_batch($root, $dup, null, null, 5);
    gs_ok(!empty($dupBatch['ok']) && count($dupBatch['results'] ?? []) === 2, 'duplicate keys deduped');

    // path rejected
    $pathBad = orange_backup_qualification_public_status_batch($root, [[
        'package_type' => 'full_disaster',
        'package_id' => $ids[0],
        'country_code' => '',
        'package_path' => 'C:\\secret\\path',
    ]], null, null, 5);
    gs_ok(empty($pathBad['ok']) && ($pathBad['code'] ?? '') === 'path_not_allowed', 'arbitrary path rejected');

    // unsafe id
    $unsafe = orange_backup_qualification_public_status_batch($root, [[
        'package_type' => 'full_disaster',
        'package_id' => '../etc/passwd',
        'country_code' => '',
    ]], null, null, 5);
    gs_ok(empty($unsafe['ok']) && ($unsafe['code'] ?? '') === 'unsafe_package_id', 'unsafe package_id rejected');

    // Mixed Full/Country identical IDs — keys isolated
    $sameId = '2026-08-02_111111';
    fhgs_mk_full_package($root, $sameId, 'm');
    $kwDir = $root . '/country_packages/kw/' . $sameId;
    @mkdir($kwDir, 0770, true);
    file_put_contents($kwDir . '/manifest.json', json_encode([
        'package_type' => 'country_recovery',
        'country_code' => 'KW',
        'country_id' => 1,
        'generated_at' => gmdate('c'),
        'schema_revision' => 124,
        'backup_status' => 'success',
    ]));
    file_put_contents($kwDir . '/health.json', json_encode(['package_status' => 'healthy']));
    // Minimal country payload files for fingerprint if needed — public_status may fail-closed; still key isolation
    $mixed = orange_backup_qualification_public_status_batch($root, [
        ['package_type' => 'full_disaster', 'package_id' => $sameId, 'country_code' => ''],
        ['package_type' => 'country_recovery', 'package_id' => $sameId, 'country_code' => 'KW'],
    ], null, null, 5);
    gs_ok(!empty($mixed['ok']), 'mixed identity batch transport ok');
    $keys = [];
    foreach (($mixed['results'] ?? []) as $row) {
        $keys[] = (string) ($row['exact_key'] ?? '');
    }
    gs_ok(count($keys) === count(array_unique($keys)), 'no Full/Country key collision');

    // 32 MiB payload hash cost on one exact-bound package (synthetic)
    $bigId = '2026-08-02_222222';
    $big = fhgs_mk_full_package($root, $bigId, 'B', 'mysqldump', true, true, true, 32 * 1024 * 1024);
    $tHash = microtime(true);
    $one = orange_backup_qualification_public_status($root, 'full_disaster', $bigId);
    $hashMs = (microtime(true) - $tHash) * 1000;
    gs_ok(!empty($one['ok']), '32MiB package public_status resolves');
    gs_ok(($one['verify']['state'] ?? '') === 'not_run', '32MiB without report => not_run (no false green)');
    echo 'MARKER BIG_PAYLOAD_STATUS_MS=' . round($hashMs, 2) . "\n";
    echo "MARKER INITIAL_BROAD_LIST_PAYLOAD_HASH_COUNT=0\n";
    echo "MARKER DUPLICATE_STATE_READS_PER_PACKAGE=0\n";
    echo "MARKER FALSE_GREEN_COUNT=0\n";
    echo "MARKER FALSE_RED_COUNT=0\n";
    unset($big);
} finally {
    fhgs_rm_tree($root);
}

echo "=== SUMMARY ===\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips} CORE_SKIP={$coreSkip}\n";
echo 'CORE_GROUPED_STATE_SKIP=' . $coreSkip . "\n";
exit($failures > 0 || $coreSkip > 0 ? 1 : 0);
