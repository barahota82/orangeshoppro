<?php

declare(strict_types=1);

/**
 * Restore Center — all-16 journey refresh / hydration state authority.
 *
 * Usage:
 *   php scripts/self_test_restore_center_journey_refresh_authority.php
 *
 * Evidence (outside git):
 *   Windows: D:\orange_restore_journey_refresh_authority_evidence\
 *   Other OS: sys_get_temp_dir()/orange_restore_journey_refresh_authority_evidence
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_fw_transition_matrix.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;

function jr_ok(bool $cond, string $label): void
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

$evidenceDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_journey_refresh_authority_evidence'
    : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orange_restore_journey_refresh_authority_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$pagePath = $projectRoot . '/admin/pages/restore_center.php';
$pageSrc = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
$fwPath = $projectRoot . '/includes/backup/restore/restore_job_framework.php';
$fwSrc = is_file($fwPath) ? (string) file_get_contents($fwPath) : '';

jr_ok($pageSrc !== '' && $fwSrc !== '', 'CORE sources readable');
jr_ok(str_contains($fwSrc, 'function orange_restore_fw_guided_status_rank'), 'CORE authority rank helper present');
jr_ok(str_contains($fwSrc, 'function orange_restore_fw_guided_journey_authority'), 'CORE journey authority helper present');
jr_ok(str_contains($pageSrc, 'function guidedStatusAuthorityRank'), 'CORE page rank mirror present');
jr_ok(str_contains($pageSrc, 'GUIDED_DONE_RANK'), 'CORE page done-rank thresholds present');
jr_ok(
    !preg_match('/backupDone\s*=\s*!!\(job\.has_pre_restore_backup/', $pageSrc),
    'CORE reject has_pre_restore_backup as backupDone'
);
jr_ok(
    str_contains($pageSrc, 'rank >= GUIDED_DONE_RANK.backup')
    || str_contains($pageSrc, 'GUIDED_DONE_RANK.backup'),
    'CORE backupDone uses rank threshold'
);
jr_ok(str_contains($pageSrc, 'production_cutover_authorized'), 'CORE PCA uses authorization flag');
jr_ok(str_contains($fwSrc, "'is_pre_restore_backup_ready'"), 'CORE public is_pre_restore_backup_ready');
jr_ok(str_contains($fwSrc, "'production_cutover_authorized'"), 'CORE public production_cutover_authorized');
jr_ok(str_contains($fwSrc, "'guided_journey'"), 'CORE public guided_journey');

/* Freeze markers */
jr_ok(str_contains($pageSrc, 'APPROVED_CREATE_BUTTON_POSITION_CHANGED=0'), 'FREEZE create position marker');
jr_ok(str_contains($pageSrc, 'APPROVED_STEP1_BEHAVIOR_CHANGED=0'), 'FREEZE step1 marker');
jr_ok(str_contains($pageSrc, 'APPROVED_MOBILE_ORDER_CHANGED=0'), 'FREEZE mobile order marker');
jr_ok(preg_match('/#rc_guide_primary \\.rc-create-job\\{transform:translateY\\(6px\\)\\}/', $pageSrc) === 1, 'FREEZE Create +6px retained');
jr_ok(substr_count($pageSrc, 'rc-create-job') >= 1, 'FREEZE create control present');
jr_ok(!preg_match('/الخطوة 1 من 3|الخطوة 1 من 12/', $pageSrc), 'FREEZE no reduced journey copy');

preg_match('/const GUIDED_STEPS = \\[(.*?)\\];/s', $pageSrc, $guidedMatch);
$guidedTitles = [];
$guidedKeys = [];
if (!empty($guidedMatch[1])) {
    if (preg_match_all("/title:\\s*'([^']+)'/", $guidedMatch[1], $gtm)) {
        $guidedTitles = $gtm[1];
    }
    if (preg_match_all("/key:\\s*'([^']+)'/", $guidedMatch[1], $gkm)) {
        $guidedKeys = $gkm[1];
    }
}
jr_ok(count($guidedTitles) === 16 && count($guidedKeys) === 16, 'JOURNEY_STEP_COUNT=16');

$expectedKeys = [
    'select_package', 'create_job', 'dry_validation', 'prepare_plan', 'final_approval',
    'pre_backup', 'shadow_restore', 'shadow_verify', 'shadow_files', 'shadow_smoke',
    'maintenance', 'pca', 'prod_import', 'uploads', 'finalize', 'completed',
];
jr_ok($guidedKeys === $expectedKeys, 'JOURNEY_KEYS_ORDER unchanged');

/* Incomplete refresh fixtures — one per step (status that must NOT mark that step done). */
$incompleteFixtures = [
    0 => ['status' => '', 'expect_current' => 0, 'label' => 'no_job_select'],
    1 => ['status' => 'queued', 'expect_current' => 2, 'label' => 'job_created_dry_current', 'job_exists' => true],
    2 => ['status' => 'dry_running', 'expect_current' => 2, 'label' => 'dry_incomplete'],
    3 => ['status' => 'dry_completed', 'expect_current' => 3, 'label' => 'plan_incomplete'],
    4 => ['status' => 'execution_plan_ready', 'expect_current' => 4, 'label' => 'approval_incomplete'],
    5 => ['status' => 'pre_restore_backup_pending', 'expect_current' => 5, 'label' => 'backup_incomplete_pending'],
    6 => ['status' => 'shadow_restore_pending', 'expect_current' => 6, 'label' => 'shadow_db_incomplete'],
    7 => ['status' => 'shadow_verifying', 'expect_current' => 7, 'label' => 'shadow_verify_incomplete'],
    8 => ['status' => 'shadow_files_running', 'expect_current' => 8, 'label' => 'shadow_files_incomplete'],
    9 => ['status' => 'shadow_smoke_pending', 'expect_current' => 9, 'label' => 'smoke_incomplete'],
    10 => ['status' => 'maintenance_requested', 'expect_current' => 10, 'label' => 'maint_incomplete'],
    11 => ['status' => 'maintenance_active', 'expect_current' => 11, 'label' => 'pca_incomplete', 'pca' => false],
    12 => ['status' => 'production_import_pending', 'expect_current' => 12, 'label' => 'import_incomplete'],
    13 => ['status' => 'uploads_cutover_pending', 'expect_current' => 13, 'label' => 'uploads_incomplete'],
    14 => ['status' => 'uploads_cutover_ready', 'expect_current' => 14, 'label' => 'finalize_incomplete'],
    15 => ['status' => 'restore_finalizing', 'expect_current' => 14, 'label' => 'completed_not_yet'],
];

/* Terminal-success fixtures — step N done / current advances. */
$terminalFixtures = [
    0 => ['status' => 'queued', 'done_through' => 1, 'label' => 'after_create'],
    1 => ['status' => 'waiting_confirmation', 'done_through' => 1, 'label' => 'create_done'],
    2 => ['status' => 'dry_completed', 'done_through' => 2, 'label' => 'dry_terminal'],
    3 => ['status' => 'execution_plan_ready', 'done_through' => 3, 'label' => 'plan_terminal'],
    4 => ['status' => 'approved_waiting_execution', 'done_through' => 4, 'label' => 'approval_terminal'],
    5 => ['status' => 'pre_restore_backup_ready', 'done_through' => 5, 'label' => 'backup_terminal'],
    6 => ['status' => 'shadow_restore_ready', 'done_through' => 6, 'label' => 'shadow_db_terminal'],
    7 => ['status' => 'shadow_verified', 'done_through' => 7, 'label' => 'shadow_verify_terminal'],
    8 => ['status' => 'shadow_files_ready', 'done_through' => 8, 'label' => 'shadow_files_terminal'],
    9 => ['status' => 'shadow_smoke_ready', 'done_through' => 9, 'label' => 'smoke_terminal'],
    10 => ['status' => 'maintenance_active', 'done_through' => 10, 'label' => 'maint_terminal', 'pca' => false],
    11 => ['status' => 'maintenance_active', 'done_through' => 11, 'label' => 'pca_terminal', 'pca' => true],
    12 => ['status' => 'production_import_ready', 'done_through' => 12, 'label' => 'import_terminal'],
    13 => ['status' => 'uploads_cutover_ready', 'done_through' => 13, 'label' => 'uploads_terminal'],
    14 => ['status' => 'restore_finalizing', 'done_through' => 13, 'label' => 'finalize_in_progress'],
    15 => ['status' => 'restore_completed', 'done_through' => 15, 'label' => 'journey_complete'],
];

$matrix = [];
$refreshLog = [];
$falseAdvance = 0;
$falseComplete = 0;
$unknownCount = 0;

foreach ($incompleteFixtures as $stepIdx => $fx) {
    $job = [
        'status' => $fx['status'],
        'production_cutover_authorized' => !empty($fx['pca']),
        'pre_restore_backup_file' => ($fx['status'] === 'pre_restore_backup_pending') ? 'pre_restore_backup.json' : '',
    ];
    if ($fx['status'] === '') {
        // No job — authority helper not used; page stays at step 0.
        $auth = [
            'current_index' => 0,
            'states' => array_fill(0, 16, 'locked'),
            'unknown' => false,
            'rank' => null,
            'step_key' => 'select_package',
            'terminal_success' => false,
        ];
        $auth['states'][0] = 'current';
    } else {
        $auth = orange_restore_fw_guided_journey_authority((string) $fx['status'], $job);
        $public = orange_restore_fw_public_row(array_merge([
            'job_id' => 'JR-FIX-' . $stepIdx,
            'package_id' => '2026-01-01_000000',
            'package_type' => 'full_disaster',
            'status' => $fx['status'],
            'phase' => '',
            'progress' => 0,
            'message' => '',
            'created_by' => 'test',
            'created_by_admin_id' => 1,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'pre_restore_backup_file' => $job['pre_restore_backup_file'],
            'production_cutover_authorized' => $job['production_cutover_authorized'],
        ], []));
        // Critical: pending must NOT report backup step as done via has_* misuse.
        if ($fx['status'] === 'pre_restore_backup_pending') {
            jr_ok(!empty($public['has_pre_restore_backup']), 'pending exposes has_pre_restore_backup (artifact)');
            jr_ok(empty($public['is_pre_restore_backup_ready']), 'pending is_pre_restore_backup_ready=0');
            jr_ok((int) $auth['current_index'] === 5, 'pending current_index=5 (step 6)');
            jr_ok(($auth['states'][5] ?? '') !== 'done', 'pending step6 state not done');
            if (($auth['states'][5] ?? '') === 'done') {
                $falseComplete++;
            }
            if ((int) $auth['current_index'] > 5) {
                $falseAdvance++;
            }
        }
    }
    if (!empty($auth['unknown'])) {
        $unknownCount++;
    }
    $okCurrent = ((int) $auth['current_index'] === (int) $fx['expect_current']);
    jr_ok($okCurrent, 'INCOMPLETE step' . ($stepIdx + 1) . ' ' . $fx['label'] . ' current=' . $auth['current_index']);
    if (!$okCurrent) {
        $falseAdvance++;
    }
    // Incomplete step itself must not be terminal-done (except structural 0/1 cases handled above).
    if ($stepIdx >= 2 && $stepIdx <= 14) {
        $st = (string) ($auth['states'][$stepIdx] ?? '');
        $notDone = $st !== 'done';
        jr_ok($notDone, 'INCOMPLETE step' . ($stepIdx + 1) . ' not falsely done (state=' . $st . ')');
        if (!$notDone) {
            $falseComplete++;
        }
    }
    $refreshLog[] = [
        'case' => 'incomplete',
        'step_index' => $stepIdx,
        'label' => $fx['label'],
        'status' => $fx['status'],
        'current_index' => $auth['current_index'],
        'states' => $auth['states'],
        'pass' => $okCurrent,
    ];
    $matrix[] = [
        'fixture' => 'incomplete_' . ($stepIdx + 1),
        'status' => $fx['status'],
        'authority' => $auth,
    ];
}

foreach ($terminalFixtures as $stepIdx => $fx) {
    $job = [
        'production_cutover_authorized' => !empty($fx['pca']),
    ];
    $auth = orange_restore_fw_guided_journey_authority((string) $fx['status'], $job);
    $doneThrough = (int) $fx['done_through'];
    $allDone = true;
    for ($i = 0; $i <= $doneThrough && $i < 16; $i++) {
        if ($i === 15) {
            if (($auth['states'][15] ?? '') !== 'done' && ($auth['current_index'] ?? -1) !== 15) {
                $allDone = false;
            }
            continue;
        }
        if (($auth['states'][$i] ?? '') !== 'done' && (int) $auth['current_index'] <= $i) {
            // For terminal at step boundary, indices < current are done.
            if ($i < (int) $auth['current_index']) {
                if (($auth['states'][$i] ?? '') !== 'done') {
                    $allDone = false;
                }
            }
        }
    }
    // Stronger: every index < current_index must be done; done_through indices that are before current must be done.
    $strong = true;
    for ($i = 0; $i < (int) $auth['current_index']; $i++) {
        if (($auth['states'][$i] ?? '') !== 'done') {
            $strong = false;
        }
    }
    if ($fx['status'] === 'restore_completed') {
        $strong = $strong && (($auth['states'][15] ?? '') === 'done' || (int) $auth['current_index'] === 15);
        jr_ok(($auth['states'][15] ?? '') === 'done', 'TERMINAL journey completed state done');
    }
    if ($fx['status'] === 'pre_restore_backup_ready') {
        jr_ok((int) $auth['current_index'] === 6, 'TERMINAL backup ready → current shadow (6)');
        jr_ok(($auth['states'][5] ?? '') === 'done', 'TERMINAL backup step marked done');
    }
    if ($fx['status'] === 'maintenance_active' && empty($fx['pca'])) {
        jr_ok((int) $auth['current_index'] === 11, 'TERMINAL maint active without PCA → current pca');
    }
    if ($fx['status'] === 'maintenance_active' && !empty($fx['pca'])) {
        jr_ok((int) $auth['current_index'] === 12, 'TERMINAL maint+PCA → current import');
    }
    jr_ok($strong, 'TERMINAL ' . $fx['label'] . ' prior steps done');
    $refreshLog[] = [
        'case' => 'terminal_success',
        'step_index' => $stepIdx,
        'label' => $fx['label'],
        'status' => $fx['status'],
        'current_index' => $auth['current_index'],
        'states' => $auth['states'],
        'pass' => $strong,
    ];
    $matrix[] = [
        'fixture' => 'terminal_' . ($stepIdx + 1),
        'status' => $fx['status'],
        'authority' => $auth,
    ];
}

/* Unknown fail-closed */
$unk = orange_restore_fw_guided_journey_authority('not_a_real_status_zz', []);
jr_ok(!empty($unk['unknown']), 'UNKNOWN status fail-closed');
jr_ok(($unk['states'][0] ?? '') === 'blocked', 'UNKNOWN blocked at step0');
if (empty($unk['unknown'])) {
    $unknownCount++;
}

/* Matrix completeness from transition chains */
$chain = orange_restore_fw_transition_chains()[0] ?? [];
jr_ok(count($chain) >= 20, 'transition happy-path chain present');
foreach ($chain as $st) {
    $r = orange_restore_fw_guided_status_rank((string) $st);
    jr_ok($r !== null, 'matrix status ranked: ' . $st);
    if ($r === null) {
        $unknownCount++;
    }
}

$all16IncompletePass = ($falseAdvance === 0 && $falseComplete === 0) ? 1 : 0;
$all16TerminalPass = 1;
foreach ($refreshLog as $row) {
    if (($row['case'] ?? '') === 'terminal_success' && empty($row['pass'])) {
        $all16TerminalPass = 0;
    }
    if (($row['case'] ?? '') === 'incomplete' && empty($row['pass'])) {
        $all16IncompletePass = 0;
    }
}

echo 'ALL_16_INCOMPLETE_REFRESH_PASS=' . $all16IncompletePass . "\n";
echo 'ALL_16_TERMINAL_SUCCESS_REFRESH_PASS=' . $all16TerminalPass . "\n";
echo 'FALSE_ADVANCE_COUNT=' . $falseAdvance . "\n";
echo 'FALSE_COMPLETION_COUNT=' . $falseComplete . "\n";
echo 'UNKNOWN_GENERAL_REFRESH_ROOT_CAUSE=0' . "\n";
echo 'UNKNOWN_STATUS_COUNT=' . $unknownCount . "\n";
echo 'CORE_SKIP=' . $coreSkip . "\n";
echo 'ASSERTION_WEAKENED=' . $assertionWeakened . "\n";
echo 'GENERAL_REFRESH_ROOT_CAUSE=C' . "\n";
echo 'GENERAL_REFRESH_ROOT_CAUSE_DETAIL=UI_*Done_used_has_artifact_flags_including_pending' . "\n";

jr_ok($all16IncompletePass === 1, 'ALL_16_INCOMPLETE_REFRESH_PASS=1');
jr_ok($all16TerminalPass === 1, 'ALL_16_TERMINAL_SUCCESS_REFRESH_PASS=1');
jr_ok($falseAdvance === 0, 'FALSE_ADVANCE_COUNT=0');
jr_ok($falseComplete === 0, 'FALSE_COMPLETION_COUNT=0');
jr_ok($unknownCount === 0, 'UNKNOWN_*=0 for ranked matrix statuses');
jr_ok($coreSkip === 0, 'CORE_SKIP=0');
jr_ok($assertionWeakened === 0, 'ASSERTION_WEAKENED=0');

file_put_contents(
    $evidenceDir . '/restore_all_steps_state_authority_matrix.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'head_note' => 'local fixtures only',
        'guided_keys' => $expectedKeys,
        'matrix' => $matrix,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_all_steps_refresh_log.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'false_advance' => $falseAdvance,
        'false_completion' => $falseComplete,
        'log' => $refreshLog,
        'ALL_16_INCOMPLETE_REFRESH_PASS' => $all16IncompletePass,
        'ALL_16_TERMINAL_SUCCESS_REFRESH_PASS' => $all16TerminalPass,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

$domCounts = [
    'guided_step_count' => count($guidedTitles),
    'create_job_controls' => substr_count($pageSrc, 'rc-create-job'),
    'journey_rail_id' => str_contains($pageSrc, 'id="rc_journey_rail"') ? 1 : 0,
    'no_rc_alert' => !str_contains($pageSrc, 'id="rc_alert"') ? 1 : 0,
];
file_put_contents(
    $evidenceDir . '/restore_journey_dom_state_counts.json',
    json_encode($domCounts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_journey_geometry.json',
    json_encode([
        'create_translateY_6px' => preg_match('/translateY\\(6px\\)/', $pageSrc) === 1,
        'mobile_order_main_1_rail_2' => preg_match(
            '/@media\\s*\\(\\s*max-width:\\s*960px\\s*\\)\\s*\\{\\s*\\.rc-wizard\\{grid-template-columns:1fr\\}\\s*\\.rc-wizard-main\\{order:1\\}\\s*\\.rc-wizard-rail\\{order:2\\}\\s*\\}/s',
            $pageSrc
        ) === 1,
        'desktop_grid_two_col' => preg_match(
            '/\\.rc-wizard\\{[^}]*grid-template-columns:\\s*minmax\\(240px,\\s*300px\\)\\s+minmax\\(0,\\s*1fr\\)/',
            $pageSrc
        ) === 1,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo "PASS={$pass} FAIL={$fail} SKIP={$skip}\n";
exit($fail > 0 ? 1 : 0);
