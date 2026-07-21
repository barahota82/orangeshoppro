<?php

declare(strict_types=1);

/**
 * CPR Mutation Engine stage catalog (WP-P3-08 skeleton).
 *
 * Architecture §6 pipeline stages relevant to mutation orchestration.
 * Mutation stages are stubs only — no production DELETE/IMPORT/PONR.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_08_MUTATION_SKELETON.md
 */

const ORANGE_CPR_MUT_ENGINE_VERSION = 'P3-08-1.0';
const ORANGE_CPR_MUT_PIPELINE_SCHEMA = 'cpr_mutation_pipeline/1';
const ORANGE_CPR_MUT_MSG_NIY = 'Not Implemented Yet';

/** Fail-closed codes */
const ORANGE_CPR_MUT_ERR_NIY = 'mutation_stage_not_implemented';
const ORANGE_CPR_MUT_ERR_CANCELLED = 'mutation_pipeline_cancelled';
const ORANGE_CPR_MUT_ERR_FAIL_CLOSED = 'mutation_fail_closed';
const ORANGE_CPR_MUT_ERR_DISPATCH = 'mutation_stage_dispatch_failed';
const ORANGE_CPR_MUT_ERR_INTEGRATION = 'mutation_integration_failed';
const ORANGE_CPR_MUT_ERR_ENABLEMENT = 'mutation_enablement_forbidden';
const ORANGE_CPR_MUT_ERR_CONTEXT = 'mutation_context_invalid';

/** Stage kinds */
const ORANGE_CPR_MUT_KIND_INTEGRATION = 'integration';
const ORANGE_CPR_MUT_KIND_MUTATION = 'mutation_stub';
const ORANGE_CPR_MUT_KIND_CONTROL = 'control';

/** Ordered skeleton pipeline stage ids (Architecture §6 mutation path). */
const ORANGE_CPR_MUT_STAGE_PREFLIGHT = 'preflight_integrations';
const ORANGE_CPR_MUT_STAGE_GATE_BIND = 'gate_engine_bind';
const ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND = 'authority_engine_bind';
const ORANGE_CPR_MUT_STAGE_LOCK_BIND = 'lock_engine_bind';
const ORANGE_CPR_MUT_STAGE_STATE_BIND = 'state_engine_bind';
const ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK = 'checkpoint_hook';
const ORANGE_CPR_MUT_STAGE_AUDIT_HOOK = 'audit_hook';
const ORANGE_CPR_MUT_STAGE_CP_A = 'checkpoint_cpa';
const ORANGE_CPR_MUT_STAGE_PONR_DELETE = 'ponr_target_slice_delete';
const ORANGE_CPR_MUT_STAGE_IMPORT = 'target_slice_import';
const ORANGE_CPR_MUT_STAGE_SPECIAL = 'special_handlers';
const ORANGE_CPR_MUT_STAGE_UPLOADS = 'country_uploads_apply';
const ORANGE_CPR_MUT_STAGE_POST_VERIFY = 'post_apply_verification';
const ORANGE_CPR_MUT_STAGE_FINALIZE = 'success_finalize_or_rollback';
const ORANGE_CPR_MUT_STAGE_MAINT_OFF = 'global_maintenance_off';
const ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE = 'audit_close';

/**
 * Default ordered stages for the mutation skeleton pipeline.
 *
 * @return list<string>
 */
function orange_cpr_mutation_default_stage_order(): array
{
    return [
        ORANGE_CPR_MUT_STAGE_PREFLIGHT,
        ORANGE_CPR_MUT_STAGE_GATE_BIND,
        ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND,
        ORANGE_CPR_MUT_STAGE_LOCK_BIND,
        ORANGE_CPR_MUT_STAGE_STATE_BIND,
        ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK,
        ORANGE_CPR_MUT_STAGE_AUDIT_HOOK,
        ORANGE_CPR_MUT_STAGE_CP_A,
        ORANGE_CPR_MUT_STAGE_PONR_DELETE,
        ORANGE_CPR_MUT_STAGE_IMPORT,
        ORANGE_CPR_MUT_STAGE_SPECIAL,
        ORANGE_CPR_MUT_STAGE_UPLOADS,
        ORANGE_CPR_MUT_STAGE_POST_VERIFY,
        ORANGE_CPR_MUT_STAGE_FINALIZE,
        ORANGE_CPR_MUT_STAGE_MAINT_OFF,
        ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE,
    ];
}

/**
 * @return array<string, array{kind:string,worker:string,description:string,is_mutation:bool}>
 */
function orange_cpr_mutation_stage_definitions(): array
{
    $mk = static function (string $kind, string $worker, string $description, bool $isMutation): array {
        return [
            'kind' => $kind,
            'worker' => $worker,
            'description' => $description,
            'is_mutation' => $isMutation,
        ];
    };

    return [
        ORANGE_CPR_MUT_STAGE_PREFLIGHT => $mk(
            ORANGE_CPR_MUT_KIND_INTEGRATION,
            'preflight',
            'Enablement false + job/context preflight (no production writes)',
            false
        ),
        ORANGE_CPR_MUT_STAGE_GATE_BIND => $mk(
            ORANGE_CPR_MUT_KIND_INTEGRATION,
            'gate',
            'Bind/verify sealed gate evaluation (WP-P3-06); no bypass',
            false
        ),
        ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND => $mk(
            ORANGE_CPR_MUT_KIND_INTEGRATION,
            'authority',
            'Bind/verify PONR authorization record (WP-P3-07); no PONR execution',
            false
        ),
        ORANGE_CPR_MUT_STAGE_LOCK_BIND => $mk(
            ORANGE_CPR_MUT_KIND_INTEGRATION,
            'lock',
            'Bind/verify CPR lock ownership (WP-P3-05)',
            false
        ),
        ORANGE_CPR_MUT_STAGE_STATE_BIND => $mk(
            ORANGE_CPR_MUT_KIND_INTEGRATION,
            'state',
            'Bind/verify job state engine view (WP-P3-03)',
            false
        ),
        ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK => $mk(
            ORANGE_CPR_MUT_KIND_CONTROL,
            'checkpoint_hook',
            'Invoke checkpoint hook callback (no CP-A write in P3-08)',
            false
        ),
        ORANGE_CPR_MUT_STAGE_AUDIT_HOOK => $mk(
            ORANGE_CPR_MUT_KIND_CONTROL,
            'audit_hook',
            'Invoke audit hook callback',
            false
        ),
        ORANGE_CPR_MUT_STAGE_CP_A => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'cpa',
            'CP-A last reversible checkpoint before PONR — stub',
            true
        ),
        ORANGE_CPR_MUT_STAGE_PONR_DELETE => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'delete',
            'PONR target-slice DELETE — stub (no production mutation)',
            true
        ),
        ORANGE_CPR_MUT_STAGE_IMPORT => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'import',
            'Target-slice IMPORT batches — stub (no production mutation)',
            true
        ),
        ORANGE_CPR_MUT_STAGE_SPECIAL => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'special',
            'Special handlers — stub',
            true
        ),
        ORANGE_CPR_MUT_STAGE_UPLOADS => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'uploads',
            'Country-scoped uploads apply — stub',
            true
        ),
        ORANGE_CPR_MUT_STAGE_POST_VERIFY => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'post_verify',
            'Post-apply verification suite — stub',
            true
        ),
        ORANGE_CPR_MUT_STAGE_FINALIZE => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'finalize',
            'Success finalize / Rollback workers — stub',
            true
        ),
        ORANGE_CPR_MUT_STAGE_MAINT_OFF => $mk(
            ORANGE_CPR_MUT_KIND_MUTATION,
            'maint_off',
            'GLOBAL Maintenance OFF — stub (no maint release execution)',
            true
        ),
        ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE => $mk(
            ORANGE_CPR_MUT_KIND_CONTROL,
            'audit_close',
            'Audit close control stage (hook only in skeleton)',
            false
        ),
    ];
}

function orange_cpr_mutation_stage_definition(string $stageId): ?array
{
    $defs = orange_cpr_mutation_stage_definitions();

    return $defs[$stageId] ?? null;
}
