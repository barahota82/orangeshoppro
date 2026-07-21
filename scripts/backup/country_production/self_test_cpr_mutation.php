<?php

declare(strict_types=1);

/**
 * Self-test: CPR Mutation Engine Skeleton (WP-P3-08).
 * Run: php scripts/backup/country_production/self_test_cpr_mutation.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_mutation_engine.php';

$pass = 0;
$fail = 0;

function cpr_mt(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p308_' . bin2hex(random_bytes(4));
$restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
$cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
$backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
@mkdir($cpr, 0775, true);
@mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

$env = [
    'ORANGE_CPR_WORK_DIR' => $cpr,
    'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-mut',
        'package_fingerprint' => str_repeat('m', 32),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 1);
    $jid = (string) $job['job_id'];

    // --- pipeline creation ---
    $created = orange_cpr_mutation_pipeline_create($env, $jid, null, ['persist' => true]);
    cpr_mt('pipeline_creation', !empty($created['ok'])
        && isset($created['pipeline']['stages'])
        && count($created['pipeline']['stages']) >= 8
        && ($created['pipeline']['production_mutation_allowed'] ?? true) === false,
        (string) ($created['message'] ?? $created['code'] ?? ''));

    // --- orchestration flow (stops at first mutation stub) ---
    $auditEvents = [];
    $checkpointHooks = [];
    $orch = orange_cpr_mutation_orchestrate($env, $jid, [
        'on_audit' => static function (array $ctx, array $event) use (&$auditEvents): void {
            $auditEvents[] = $event;
        },
        'on_checkpoint' => static function (array $ctx, array $payload) use (&$checkpointHooks): void {
            $checkpointHooks[] = $payload;
        },
    ]);
    cpr_mt(
        'orchestration_flow',
        empty($orch['ok'])
        && ($orch['code'] ?? '') === ORANGE_CPR_MUT_ERR_NIY
        && ($orch['failed_stage_id'] ?? '') === ORANGE_CPR_MUT_STAGE_CP_A
        && in_array(ORANGE_CPR_MUT_STAGE_PREFLIGHT, $orch['dispatched_stages'] ?? [], true)
        && in_array(ORANGE_CPR_MUT_STAGE_GATE_BIND, $orch['dispatched_stages'] ?? [], true)
        && in_array(ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND, $orch['dispatched_stages'] ?? [], true)
        && in_array(ORANGE_CPR_MUT_STAGE_LOCK_BIND, $orch['dispatched_stages'] ?? [], true)
        && in_array(ORANGE_CPR_MUT_STAGE_STATE_BIND, $orch['dispatched_stages'] ?? [], true)
        && ($orch['message'] ?? '') === ORANGE_CPR_MUT_MSG_NIY,
        (string) ($orch['failed_stage_id'] ?? '') . ' ' . (string) ($orch['code'] ?? '')
    );

    // Fail-closed: import stage must NOT have been dispatched after CP-A stub
    cpr_mt(
        'fail_closed_propagation',
        !in_array(ORANGE_CPR_MUT_STAGE_PONR_DELETE, $orch['dispatched_stages'] ?? [], true)
        && !in_array(ORANGE_CPR_MUT_STAGE_IMPORT, $orch['dispatched_stages'] ?? [], true)
        && !empty($orch['fail_closed'])
        && empty($orch['context']['production_mutation'] ?? null)
        && empty($orch['context']['ponr_executed'] ?? null)
    );

    cpr_mt('audit_callbacks', count($auditEvents) >= 3);
    cpr_mt('checkpoint_callbacks', count($checkpointHooks) >= 1
        && ($checkpointHooks[0]['write_checkpoint'] ?? true) === false);

    // --- stage dispatch (direct) ---
    $ctx = orange_cpr_mutation_context_create($env, $jid);
    $disp = orange_cpr_mutation_stage_dispatch($ctx, ORANGE_CPR_MUT_STAGE_PREFLIGHT);
    cpr_mt('stage_dispatch', !empty($disp['ok'])
        && in_array(ORANGE_CPR_MUT_STAGE_PREFLIGHT, $ctx['dispatched_stages'], true));

    $stub = orange_cpr_mutation_stage_dispatch($ctx, ORANGE_CPR_MUT_STAGE_PONR_DELETE);
    cpr_mt('mutation_stub_niy', empty($stub['ok'])
        && ($stub['message'] ?? '') === ORANGE_CPR_MUT_MSG_NIY
        && ($stub['code'] ?? '') === ORANGE_CPR_MUT_ERR_NIY);

    // --- dependency injection ---
    $diCalls = 0;
    $ctxDi = orange_cpr_mutation_context_create($env, $jid, [
        'dependencies' => [
            'enablement_read' => static function () use (&$diCalls): bool {
                ++$diCalls;

                return false;
            },
            'workers' => [
                'preflight' => static function (array &$c) use (&$diCalls): array {
                    ++$diCalls;

                    return orange_cpr_mut_ok(['stage_id' => ORANGE_CPR_MUT_STAGE_PREFLIGHT, 'di' => true]);
                },
            ],
        ],
    ]);
    $diResult = orange_cpr_mutation_stage_dispatch($ctxDi, ORANGE_CPR_MUT_STAGE_PREFLIGHT);
    cpr_mt('dependency_injection', !empty($diResult['ok']) && !empty($diResult['di']) && $diCalls >= 1);

    // --- cancellation ---
    $ctxCancel = orange_cpr_mutation_context_create($env, $jid, [
        'on_cancel_check' => static function (array $c, string $at): array {
            if ($at === 'before:' . ORANGE_CPR_MUT_STAGE_GATE_BIND) {
                return ['cancel' => true, 'reason' => 'test_cancel'];
            }

            return ['cancel' => false];
        },
    ]);
    orange_cpr_mutation_stage_dispatch($ctxCancel, ORANGE_CPR_MUT_STAGE_PREFLIGHT);
    $cancelled = orange_cpr_mutation_stage_dispatch($ctxCancel, ORANGE_CPR_MUT_STAGE_GATE_BIND);
    cpr_mt('cancellation', empty($cancelled['ok'])
        && ($cancelled['code'] ?? '') === ORANGE_CPR_MUT_ERR_CANCELLED
        && !empty($ctxCancel['cancelled']));

    // Refuse helpers
    $refDel = orange_cpr_mutation_refuse_delete();
    $refImp = orange_cpr_mutation_refuse_import();
    $refPonr = orange_cpr_mutation_refuse_ponr_execution();
    cpr_mt('refuse_helpers', empty($refDel['ok']) && empty($refImp['ok']) && empty($refPonr['ok'])
        && ($refDel['message'] ?? '') === ORANGE_CPR_MUT_MSG_NIY);

    // Enablement remains false on orchestrate context
    cpr_mt('enablement_false', ($orch['context']['enablement_flag_observed'] ?? null) === false);
} catch (Throwable $e) {
    cpr_mt('suite_exception', false, $e->getMessage());
}

if (is_dir($base)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($base);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
