<?php

declare(strict_types=1);

/**
 * Frozen P2-03 Drill Scenario Catalog (WP-P7-03).
 *
 * Inventory order is normative — do not invent, reorder, merge, or skip.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_03_DRILL_SCENARIOS.md §5
 */

const ORANGE_CPR_DRILL_CATALOG_VERSION = 'P2-03-1.0';
const ORANGE_CPR_DRILL_CATALOG_ARTIFACT = 'CPR-P2-WP03-DRILL_SCENARIOS';
/** Inventory §5 row count (authoritative over the erroneous “40” roll-up note in P2-03 §5). */
const ORANGE_CPR_DRILL_CATALOG_COUNT = 42;

/**
 * Mandatory DS-* IDs in frozen catalog inventory order (§5).
 *
 * @return list<string>
 */
function orange_cpr_drill_catalog_ids(): array
{
    return [
        'DS-N01',
        'DS-F01', 'DS-F02', 'DS-F03', 'DS-F04', 'DS-F05', 'DS-F06',
        'DS-R01', 'DS-R02', 'DS-R03', 'DS-R04', 'DS-R05',
        'DS-B01', 'DS-B02', 'DS-B03', 'DS-B04', 'DS-B05', 'DS-B06',
        'DS-L01', 'DS-L02', 'DS-L03', 'DS-L04',
        'DS-M01', 'DS-M02', 'DS-M03', 'DS-M04',
        'DS-S01', 'DS-S02',
        'DS-I01', 'DS-I02',
        'DS-U01', 'DS-U02',
        'DS-V01', 'DS-V02',
        'DS-G01', 'DS-G02', 'DS-G03',
        'DS-P01', 'DS-P02', 'DS-P03', 'DS-P04', 'DS-P05',
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function orange_cpr_drill_catalog_definitions(): array
{
    static $defs = null;
    if (is_array($defs)) {
        return $defs;
    }

    $rows = [
        ['DS-N01', 'Happy-path restore', 'Normal successful restore', 'cpr_succeeded', ['EV-04', 'EV-05', 'EV-06', 'EV-07', 'EV-08', 'EV-09', 'EV-11', 'EV-13'], ['OD-DUAL', 'OD-PHRASE', 'OD-PERM', 'OD-PIN', 'OD-ENABLE'], ['P1-03', 'P1-04', 'P1-05', 'P1-06', 'P1-07', 'P1-08', 'P1-10', 'P1-11'], null],
        ['DS-F01', 'Delete fail-pause', 'Fail-pause', 'cpr_paused_delete_failed', ['EV-10', 'EV-06', 'EV-11'], ['OD-FAIL-DELETE', 'OD-MAINT', 'OD-PIN'], ['P1-09', 'P1-03', 'P1-07'], 'cpr_paused_delete_failed'],
        ['DS-F02', 'Import fail-pause', 'Fail-pause', 'cpr_paused_import_failed', ['EV-10', 'EV-11'], ['OD-FAIL-IMPORT', 'OD-PIN'], ['P1-09'], 'cpr_paused_import_failed'],
        ['DS-F03', 'Uploads fail-pause', 'Fail-pause / Upload integrity', 'cpr_paused_uploads_failed', ['EV-08', 'EV-10', 'EV-11'], ['OD-UPLOADS', 'OD-PIN'], ['P1-09', 'P1-10'], 'cpr_paused_uploads_failed'],
        ['DS-F04', 'Verify fail-pause', 'Fail-pause / Verification', 'cpr_paused_verify_failed', ['EV-09', 'EV-10', 'EV-11'], ['OD-VERIFY-WARN'], ['P1-09', 'P1-11'], 'cpr_paused_verify_failed'],
        ['DS-F05', 'Emergency stop pause', 'Fail-pause', 'cpr_paused_*', ['EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-MAINT', 'OD-PERM'], ['P1-09'], null],
        ['DS-F06', 'Rollback worker fail-pause', 'Fail-pause', 'cpr_paused_rollback_failed', ['EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_rollback_failed'],
        ['DS-R01', 'Resume delete', 'Resume', 'resume_delete_ok', ['EV-05', 'EV-10', 'EV-11'], ['OD-PERM', 'OD-PIN'], ['P1-09'], 'cpr_paused_delete_failed'],
        ['DS-R02', 'Resume import re-clear', 'Resume', 'resume_import_reclear_ok', ['EV-10', 'EV-11'], ['OD-FAIL-IMPORT', 'OD-PERM'], ['P1-09'], 'cpr_paused_import_failed'],
        ['DS-R03', 'Resume uploads', 'Resume', 'resume_uploads_ok', ['EV-08', 'EV-10', 'EV-11'], ['OD-UPLOADS', 'OD-PERM'], ['P1-09', 'P1-10'], 'cpr_paused_uploads_failed'],
        ['DS-R04', 'Resume verify', 'Resume', 'resume_verify_ok', ['EV-09', 'EV-10', 'EV-11'], ['OD-VERIFY-WARN', 'OD-PERM'], ['P1-09', 'P1-11'], 'cpr_paused_verify_failed'],
        ['DS-R05', 'Resume DENIED', 'Resume', 'resume_denied', ['EV-05', 'EV-10', 'EV-11'], ['OD-PERM'], ['P1-09'], null],
        ['DS-B01', 'Rollback delete pause', 'Rollback', 'rollback_from_delete_ok', ['EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_delete_failed'],
        ['DS-B02', 'Rollback import pause', 'Rollback', 'rollback_from_import_ok', ['EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_import_failed'],
        ['DS-B03', 'Rollback uploads pause', 'Rollback', 'rollback_from_uploads_ok', ['EV-08', 'EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_uploads_failed'],
        ['DS-B04', 'Rollback verify pause', 'Rollback', 'rollback_from_verify_ok', ['EV-09', 'EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_verify_failed'],
        ['DS-B05', 'Retry Rollback', 'Rollback', 'retry_rollback_ok', ['EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-PIN'], ['P1-09'], 'cpr_paused_rollback_failed'],
        ['DS-B06', 'Missing pin incident', 'Rollback', 'missing_pin_incident', ['EV-06', 'EV-10', 'EV-11'], ['OD-PIN', 'OD-ROLLBACK'], ['P1-09', 'P1-04'], null],
        ['DS-L01', 'Full DR lock conflict', 'Lock conflict', 'full_dr_lock_blocks', ['EV-04', 'EV-07', 'EV-11'], ['OD-LOCK-TTL'], ['P1-05'], null],
        ['DS-L02', 'C6 lock conflict', 'Lock conflict', 'c6_lock_blocks', ['EV-04', 'EV-07', 'EV-11'], ['OD-LOCK-TTL'], ['P1-05'], null],
        ['DS-L03', 'Backup runner conflict', 'Lock conflict', 'backup_runner_conflict', ['EV-04', 'EV-07', 'EV-11'], ['OD-LOCK-TTL'], ['P1-05'], null],
        ['DS-L04', 'Post-PONR no auto-unlock', 'Lock conflict', 'no_auto_unlock_post_ponr', ['EV-04', 'EV-07', 'EV-11'], ['OD-LOCK-TTL'], ['P1-05'], null],
        ['DS-M01', 'Maint required', 'Maintenance', 'ponr_refused_without_maint', ['EV-04', 'EV-06', 'EV-11'], ['OD-MAINT'], ['P1-04'], null],
        ['DS-M02', 'Maint ON during pause', 'Maintenance', 'maint_on_during_pause', ['EV-06', 'EV-10', 'EV-11'], ['OD-MAINT'], ['P1-09'], null],
        ['DS-M03', 'Timeout ≠ auto-rollback', 'Maintenance', 'timeout_no_auto_rollback', ['EV-06', 'EV-10', 'EV-11'], ['OD-ROLLBACK', 'OD-MAINT'], ['P1-09'], null],
        ['DS-M04', 'Maint release discipline', 'Maintenance', 'maint_release_sa_runbook_only', ['EV-06', 'EV-11'], ['OD-MAINT', 'OD-RUNBOOK'], ['P1-04'], null],
        ['DS-S01', 'Schema mismatch gate', 'Schema mismatch', 'schema_mismatch_blocks', ['EV-03', 'EV-04', 'EV-12'], ['OD-SCHEMA'], ['P1-08'], null],
        ['DS-S02', 'Schema invalidates cert', 'Schema mismatch', 'schema_invalidates_cert', ['EV-03', 'EV-12', 'EV-13'], ['OD-SCHEMA', 'OD-ENABLE'], ['P1-13'], null],
        ['DS-I01', 'Inventory missing', 'Inventory mismatch', 'inventory_missing_blocks', ['EV-02', 'EV-11'], ['OD-INV'], ['P1-08'], null],
        ['DS-I02', 'Live replace inventory forbidden', 'Inventory mismatch', 'live_replace_inventory_forbidden', ['EV-02', 'EV-11'], ['OD-INV'], ['P1-08'], null],
        ['DS-U01', 'Upload integrity failure', 'Upload integrity', 'upload_integrity_failure', ['EV-08', 'EV-10', 'EV-11'], ['OD-UPLOADS'], ['P1-10'], null],
        ['DS-U02', 'Best-effort uploads forbidden', 'Upload integrity', 'best_effort_uploads_forbidden', ['EV-08', 'EV-10', 'EV-11'], ['OD-UPLOADS'], ['P1-10'], null],
        ['DS-V01', 'Pillar verify FAIL', 'Verification failure', 'pillar_verify_fail', ['EV-09', 'EV-10', 'EV-11'], ['OD-VERIFY-WARN'], ['P1-11'], null],
        ['DS-V02', 'Success-with-warnings forbidden', 'Verification failure', 'success_with_warnings_forbidden', ['EV-09', 'EV-11'], ['OD-VERIFY-WARN'], ['P1-11'], null],
        ['DS-G01', 'Break Glass SA allowed chassis', 'Break Glass', 'break_glass_sa_allowed', ['EV-05', 'EV-11'], ['OD-BREAK'], ['P1-06'], null],
        ['DS-G02', 'Break Glass no bypass', 'Break Glass', 'break_glass_no_bypass', ['EV-05', 'EV-11'], ['OD-BREAK'], ['P1-06', 'P1-09'], null],
        ['DS-G03', 'CA Break Glass DENIED', 'Break Glass', 'ca_break_glass_denied', ['EV-05', 'EV-11'], ['OD-BREAK', 'OD-PERM'], ['P1-06', 'P1-09'], null],
        ['DS-P01', 'Pre-PONR fail (no Rollback UI)', 'Authority / pre-PONR', 'pre_ponr_fail_no_rollback_ui', ['EV-04', 'EV-05'], ['OD-DUAL', 'OD-PHRASE', 'OD-ENABLE'], ['P1-08', 'P1-09'], null],
        ['DS-P02', 'CA Resume/Rollback DENIED', 'Authority', 'ca_resume_rollback_denied', ['EV-05', 'EV-10', 'EV-11'], ['OD-PERM', 'OD-ROLLBACK'], ['P1-09'], null],
        ['DS-P03', 'Crash ≠ auto-rollback', 'Integrity', 'crash_no_auto_rollback', ['EV-07', 'EV-10', 'EV-06'], ['OD-ROLLBACK', 'OD-LOCK-TTL'], ['P1-09', 'P1-05'], null],
        ['DS-P04', 'PIN order', 'Anchor', 'pin_order_maint_backup_verify_pin', ['EV-06', 'EV-04', 'EV-10'], ['OD-PIN'], ['P1-04', 'P1-08'], null],
        ['DS-P05', 'Enablement FALSE attestation', 'Enablement', 'enablement_false_attested', ['EV-13'], ['OD-ENABLE', 'OD-CERT'], ['P1-13'], null],
    ];

    $defs = [];
    $order = 0;
    foreach ($rows as $row) {
        ++$order;
        $id = (string) $row[0];
        $defs[$id] = [
            'scenario_id' => $id,
            'catalog_order' => $order,
            'title' => (string) $row[1],
            'class' => (string) $row[2],
            'expected_outcome' => (string) $row[3],
            'evidence_refs' => $row[4],
            'od_refs' => $row[5],
            'p1_refs' => $row[6],
            'related_state' => $row[7],
            'catalog_version' => ORANGE_CPR_DRILL_CATALOG_VERSION,
            'catalog_artifact' => ORANGE_CPR_DRILL_CATALOG_ARTIFACT,
            'auto_rollback_executed' => false,
            'enablement_flag' => false,
        ];
    }

    return $defs;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_drill_catalog_definition(string $scenarioId): ?array
{
    $defs = orange_cpr_drill_catalog_definitions();

    return $defs[$scenarioId] ?? null;
}

/**
 * Validate requested scenario IDs against frozen catalog order (no invent/reorder/skip/merge).
 *
 * @param list<string> $scenarioIds
 * @return array<string, mixed>
 */
function orange_cpr_drill_catalog_assert_execution_order(array $scenarioIds): array
{
    $canonical = orange_cpr_drill_catalog_ids();
    if ($scenarioIds === []) {
        return [
            'ok' => false,
            'code' => 'drill_catalog_empty',
            'message' => 'Scenario list empty.',
            'fail_closed' => true,
        ];
    }

    foreach ($scenarioIds as $id) {
        if (!is_string($id) || orange_cpr_drill_catalog_definition($id) === null) {
            return [
                'ok' => false,
                'code' => 'drill_scenario_missing',
                'message' => 'Scenario not defined in frozen P2-03 catalog: ' . (string) $id,
                'fail_closed' => true,
                'scenario_id' => $id,
            ];
        }
    }

    $unique = array_values(array_unique($scenarioIds));
    if ($unique !== array_values($scenarioIds)) {
        return [
            'ok' => false,
            'code' => 'drill_scenario_order_invalid',
            'message' => 'Duplicate/merged scenario IDs forbidden.',
            'fail_closed' => true,
        ];
    }

    $n = count($scenarioIds);
    $prefix = array_slice($canonical, 0, $n);
    if ($scenarioIds !== $prefix) {
        return [
            'ok' => false,
            'code' => 'drill_scenario_order_invalid',
            'message' => 'Scenario IDs must follow frozen catalog order without reorder/skip.',
            'fail_closed' => true,
            'expected_prefix' => $prefix,
            'got' => $scenarioIds,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'Scenario order matches frozen catalog.',
        'scenario_ids' => $scenarioIds,
        'full_catalog' => $scenarioIds === $canonical,
        'count' => $n,
    ];
}
