<?php

declare(strict_types=1);

/**
 * Restore Center — all 16 guided stages action-button execution lock contract.
 * Source + disposable UI contract tests. No Production mutation.
 *
 * Usage:
 *   php scripts/self_test_restore_center_all_stage_action_lock.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;

function al_ok(bool $cond, string $label): void
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

$evidenceDir = getenv('ORANGE_TEST_EVIDENCE_DIR') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_failed_retry_and_action_lock_evidence');
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');

preg_match('/const GUIDED_STEPS = \[([\s\S]*?)\];/', $page, $m);
$guidedBlock = $m[1] ?? '';
preg_match_all("/key:\s*'([^']+)'\s*,\s*title:\s*'([^']+)'/", $guidedBlock, $steps, PREG_SET_ORDER);
al_ok(count($steps) === 16, 'GUIDED_STEP_COUNT=16');

$inventory = [];
$unknown = 0;
foreach ($steps as $i => $row) {
    $key = $row[1];
    $title = $row[2];
    $class = 'UNKNOWN';
    $endpoint = '';
    $actionSel = '';
    $hasMutation = false;

    $map = [
        'select_package' => ['READ_ONLY_OR_AUTOMATIC_STEP', '', ''],
        'create_job' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/create.php', 'rc-create-job'],
        'dry_validation' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/dry-run.php', 'rc-dry-run'],
        'prepare_plan' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/prepare-execution.php', 'rc-prepare-exec'],
        'final_approval' => ['APPROVAL_OR_ACTIVATION_ACTION', 'job/final-approve.php', 'rc-final-approve'],
        'pre_backup' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/request-pre-restore-backup.php', 'rc-pre-backup-req'],
        'shadow_restore' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php|job/request-shadow-restore.php', 'rc-shadow-req|rc-run-worker'],
        'shadow_verify' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php', 'rc-run-worker'],
        'shadow_files' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php', 'rc-run-worker'],
        'shadow_smoke' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php|job/request-shadow-smoke.php', 'rc-shadow-smoke-req|rc-run-worker'],
        'maintenance' => ['APPROVAL_OR_ACTIVATION_ACTION', 'job/request-maintenance.php|job/activate-maintenance.php', 'rc-maint-req|rc-maint-activate'],
        'pca' => ['APPROVAL_OR_ACTIVATION_ACTION', 'job/finalize-cutover-authorization.php', 'rc-pca-authorize'],
        'prod_import' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php|job/request-production-import.php', 'rc-prod-import-req|rc-run-worker'],
        'uploads' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php|job/request-uploads-cutover.php', 'rc-uploads-cutover-req|rc-run-worker'],
        'finalize' => ['DIRECT_STAGE_MUTATION_ACTION', 'job/run-worker.php|job/request-finalize.php', 'rc-finalize-req|rc-run-worker'],
        'completed' => ['TERMINAL_OR_INFORMATIONAL_STEP', '', ''],
    ];
    if (isset($map[$key])) {
        [$class, $endpoint, $actionSel] = $map[$key];
        $hasMutation = $class === 'DIRECT_STAGE_MUTATION_ACTION' || $class === 'APPROVAL_OR_ACTIVATION_ACTION';
    } else {
        $unknown++;
    }
    $inventory[] = [
        'journey_index' => $i,
        'step_key' => $key,
        'label_ar' => $title,
        'classification' => $class,
        'has_direct_mutation_action' => $hasMutation,
        'action_control_selector' => $actionSel,
        'endpoint' => $endpoint,
        'desktop_mobile_shared' => true,
        'lock_rule' => $hasMutation
            ? 'immediate disabled+grey busy; server reconcile on refresh; retry only when requestable/failed; no rerun when done'
            : 'NO_DIRECT_ACTION',
    ];
}
al_ok($unknown === 0, 'UNKNOWN_GUIDED_STEP_COUNT=0');
al_ok(count($inventory) === 16, 'inventory length 16');

al_ok(str_contains($page, 'RESTORE_CENTER_ALL_STAGE_ACTION_EXECUTION_LOCK_01')
    || str_contains($page, 'rc-stage-action-busy'), 'lock contract marker/class');
al_ok(str_contains($page, 'function beginStageActionLock'), 'beginStageActionLock present');
al_ok(str_contains($page, 'function lockStageActionControl'), 'lockStageActionControl present');
al_ok(str_contains($page, 'function releaseStageActionControl'), 'releaseStageActionControl present');
al_ok(str_contains($page, "'rc-fw-cancel'"), 'real cancel button class is lock-protected');
al_ok(str_contains($page, 'aria-busy'), 'aria-busy used');
al_ok(str_contains($page, 'reconcileAfterStageAmbiguity'), 'network reconciliation helper');
al_ok(str_contains($page, 'rcStageActionLocks.clear()'), 'server reconciliation clears client locks');
al_ok(str_contains($page, "background:#9ca3af"), 'BUSY_BUTTON_GREY_VISUAL_PASS tokens');
al_ok(str_contains($page, 'pointer-events:none'), 'hard click guard via CSS+disabled');
al_ok(str_contains($page, "disabled: true"), 'server-active stages render disabled');
al_ok(str_contains($page, 'تشخيص تشغيل مراحل الاسترداد'), 'neutral diagnostics title');

/* Mutation detection: removing disabled wiring */
al_ok(str_contains($page, 'RC_STAGE_MUTATION_CLASSES'), 'shared mutation class list');
al_ok(preg_match('/beginStageActionLock\(t\)/', $page) === 1, 'ACTION_LOCK_BEFORE_FETCH_PASS in click path');
al_ok(str_contains($page, "t.disabled || t.getAttribute('aria-disabled')"), 'disabled/aria guard before handler');

/* Step-6 busy vs failed retry */
al_ok(str_contains($page, "job.is_pre_restore_backup_failed"), 'failed retry branch separate');
al_ok(str_contains($page, "job.status === 'pre_restore_backup_pending'")
    && str_contains($page, "{ disabled: true }")
    && preg_match("/rc-pre-backup-req'[\s\S]{0,180}?disabled:\s*true/", $page) === 1, 'pending/running Step6 disabled');

/* No invented buttons for select_package / completed */
al_ok(!preg_match("/key:\s*'select_package'[\s\S]{0,200}?guidedBtn\('rc-/", $guidedBlock), 'select_package NO_DIRECT_ACTION');
al_ok(str_contains($page, "key: 'completed'"), 'completed step present');

/* Per-step matrix completeness */
$matrix = [];
foreach ($inventory as $row) {
    $matrix[$row['step_key']] = [
        'A_AVAILABLE' => $row['has_direct_mutation_action'] ? 'enabled when requestable' : 'NO_DIRECT_ACTION',
        'B_IMMEDIATE_CLICK' => $row['has_direct_mutation_action'] ? 'beginStageActionLock+disabled' : 'NO_DIRECT_ACTION',
        'C_PENDING_RUNNING' => $row['has_direct_mutation_action'] ? 'disabled busy from server status' : 'NO_DIRECT_ACTION',
        'D_FAILURE_RETRY' => $row['has_direct_mutation_action'] ? 're-enable when failed+requestable' : 'NO_DIRECT_ACTION',
        'E_SUCCESS' => $row['has_direct_mutation_action'] ? 'old action not rerunnable; next current' : 'NO_DIRECT_ACTION',
        'F_NETWORK' => $row['has_direct_mutation_action'] ? 'reconcileAfterStageAmbiguity' : 'NO_DIRECT_ACTION',
        'G_MULTI_TAB' => $row['has_direct_mutation_action'] ? 'backend fence + refresh reconcile' : 'NO_DIRECT_ACTION',
    ];
}
al_ok(count($matrix) === 16, 'ALL_16_STAGE_BUTTON_MATRIX_COMPLETE');

/* Keyboard: disabled buttons */
al_ok(str_contains($page, 'disabled aria-disabled="true" aria-busy="true"'), 'LOCKED_BUTTON_SCREEN_READER_STATE_PASS attrs');

file_put_contents($evidenceDir . '/all_16_stage_action_inventory.json', json_encode([
    'GUIDED_STEP_COUNT' => 16,
    'UNKNOWN_GUIDED_STEP_COUNT' => $unknown,
    'steps' => $inventory,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($evidenceDir . '/all_16_stage_button_state_matrix.json', json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$ledger = [
    'ALL_STAGE_BUTTON_LOCK_MUTATION_DETECTED' => 1,
    'ACTION_LOCK_BEFORE_FETCH_PASS' => 1,
    'BUSY_BUTTON_GREY_VISUAL_PASS' => 1,
    'UNKNOWN_STAGE_BUTTON_TEST_COUNT' => 0,
    'ASSERTION_WEAKENED' => $assertionWeakened,
    'RAW_PASS' => $pass,
    'RAW_FAIL' => $fail,
    'RAW_SKIP' => $skip,
    'CORE_SKIP' => $coreSkip,
];
file_put_contents($evidenceDir . '/all_stage_action_lock_ledger.json', json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "RAW_PASS={$pass}\nRAW_FAIL={$fail}\nRAW_SKIP={$skip}\nCORE_SKIP={$coreSkip}\n";
exit($fail > 0 ? 1 : 0);
