<?php

declare(strict_types=1);

/**
 * Frozen P2-04 / P2 Artifact Index EV-01…EV-14 evidence catalog (WP-P7-04).
 *
 * Packaging order is normative — do not invent, merge, reorder, or omit classes.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_04_EVIDENCE_PACK_SCHEMAS.md §7.1
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md §8
 */

const ORANGE_CPR_EVIDENCE_CATALOG_VERSION = 'P2-04-1.0';
const ORANGE_CPR_EVIDENCE_CATALOG_ARTIFACT = 'CPR-P2-WP04-EVIDENCE_PACK_SCHEMAS';
const ORANGE_CPR_EVIDENCE_CLASS_COUNT = 14;

/**
 * @return list<string>
 */
function orange_cpr_evidence_catalog_ids(): array
{
    return [
        'EV-01', 'EV-02', 'EV-03', 'EV-04', 'EV-05', 'EV-06', 'EV-07',
        'EV-08', 'EV-09', 'EV-10', 'EV-11', 'EV-12', 'EV-13', 'EV-14',
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function orange_cpr_evidence_catalog_definitions(): array
{
    static $defs = null;
    if (is_array($defs)) {
        return $defs;
    }

    $rows = [
        ['EV-01', 'Policy & baseline freeze proof', ['OD-CERT'], ['P1-13'], []],
        ['EV-02', 'Boundary / inventory certification', ['OD-INV'], ['P1-08'], []],
        ['EV-03', 'C8 SAFE package proof', ['OD-C8'], ['P1-08', 'P1-13'], []],
        ['EV-04', 'Pre-PONR gate suite results', ['OD-ENABLE'], ['P1-08'], ['DS-N01', 'DS-P01', 'DS-P04']],
        ['EV-05', 'Dual-control / authority path evidence', ['OD-DUAL', 'OD-PERM'], ['P1-06'], ['DS-R05', 'DS-P02', 'DS-G01', 'DS-G02', 'DS-G03']],
        ['EV-06', 'Maintenance / duration / timeout posture', ['OD-MAINT'], ['P1-07'], ['DS-M01', 'DS-M02', 'DS-M03', 'DS-M04', 'DS-P03']],
        ['EV-07', 'Lock / cross-feature exclusion proof', ['OD-LOCK-TTL'], ['P1-05'], ['DS-L01', 'DS-L02', 'DS-L03', 'DS-L04']],
        ['EV-08', 'Apply path proof (scoped DB + uploads)', ['OD-UPLOADS'], ['P1-10'], ['DS-F03', 'DS-R03', 'DS-B03', 'DS-U01', 'DS-U02']],
        ['EV-09', 'Post-apply verify pack', ['OD-VERIFY-WARN'], ['P1-11'], ['DS-F04', 'DS-R04', 'DS-B04', 'DS-V01', 'DS-V02']],
        ['EV-10', 'Fail / Resume / Rollback drill proof', ['OD-FAIL-DELETE', 'OD-ROLLBACK', 'OD-CERT'], ['P1-09', 'P1-03'], ['DS-F01', 'DS-F02', 'DS-R01', 'DS-R05', 'DS-B01', 'DS-B03', 'DS-M03', 'DS-P02', 'DS-P03']],
        ['EV-11', 'Audit / metrics / alert capture', ['OD-CERT'], ['P1-12'], ['DS-N01']],
        ['EV-12', 'Schema revision binding statement', ['OD-SCHEMA'], ['P1-13'], ['DS-S01', 'DS-S02']],
        ['EV-13', 'Enablement still disabled attestation', ['OD-ENABLE', 'OD-CERT'], ['P1-13'], ['DS-P05']],
        ['EV-14', 'Owner decision package', ['OD-CERT'], ['P1-13'], []],
    ];

    $defs = [];
    $order = 0;
    foreach ($rows as $row) {
        ++$order;
        $id = (string) $row[0];
        $defs[$id] = [
            'evidence_class' => $id,
            'catalog_order' => $order,
            'title' => (string) $row[1],
            'od_refs' => $row[2],
            'p1_artifact_refs' => $row[3],
            'scenario_refs' => $row[4],
            'drill_derived' => $order >= 4 && $order <= 11,
            'catalog_version' => ORANGE_CPR_EVIDENCE_CATALOG_VERSION,
            'catalog_artifact' => ORANGE_CPR_EVIDENCE_CATALOG_ARTIFACT,
        ];
    }

    return $defs;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_evidence_catalog_definition(string $evidenceClass): ?array
{
    $defs = orange_cpr_evidence_catalog_definitions();

    return $defs[$evidenceClass] ?? null;
}

/**
 * EV-10 rollback-minimum scenario set (P2-03 §6) — satisfaction helper.
 *
 * @param list<string> $passedScenarioIds
 * @return array<string, mixed>
 */
function orange_cpr_evidence_ev10_minimum_satisfied(array $passedScenarioIds): array
{
    $set = array_fill_keys($passedScenarioIds, true);
    $checks = [
        'fail_pause' => isset($set['DS-F01']) || isset($set['DS-F02']),
        'resume' => isset($set['DS-R01']) || isset($set['DS-R02']),
        'resume_deny' => isset($set['DS-R05']),
        'rollback_success' => isset($set['DS-B01']) || isset($set['DS-B02']),
        'rollback_verify_or_uploads' => isset($set['DS-B03']) || isset($set['DS-B04']),
        'no_auto_rollback' => isset($set['DS-M03']) && isset($set['DS-P03']),
        'ca_denied' => isset($set['DS-P02']),
    ];
    $ok = !in_array(false, $checks, true);

    return [
        'ok' => $ok,
        'ev10_minimum_set_satisfied' => $ok,
        'checks' => $checks,
    ];
}

/**
 * @param list<string> $classes
 * @return array<string, mixed>
 */
function orange_cpr_evidence_catalog_assert_order(array $classes): array
{
    $canonical = orange_cpr_evidence_catalog_ids();
    if ($classes === []) {
        return [
            'ok' => false,
            'code' => 'evidence_catalog_empty',
            'message' => 'Evidence class list empty.',
            'fail_closed' => true,
        ];
    }
    foreach ($classes as $id) {
        if (!is_string($id) || orange_cpr_evidence_catalog_definition($id) === null) {
            return [
                'ok' => false,
                'code' => 'evidence_item_missing',
                'message' => 'Evidence class not defined in frozen EV catalog: ' . (string) $id,
                'fail_closed' => true,
                'evidence_class' => $id,
            ];
        }
    }
    $unique = array_values(array_unique($classes));
    if ($unique !== array_values($classes)) {
        return [
            'ok' => false,
            'code' => 'evidence_order_invalid',
            'message' => 'Duplicate/merged evidence classes forbidden.',
            'fail_closed' => true,
        ];
    }
    if ($classes !== $canonical) {
        return [
            'ok' => false,
            'code' => 'evidence_order_invalid',
            'message' => 'Evidence classes must be exactly EV-01…EV-14 in frozen packaging order (no omit/reorder).',
            'fail_closed' => true,
            'expected' => $canonical,
            'got' => $classes,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'Evidence class order matches frozen catalog.',
        'evidence_classes' => $classes,
    ];
}
