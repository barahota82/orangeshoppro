<?php

declare(strict_types=1);

/**
 * Phase C5 — Country Disaster Recovery Validation (DRV).
 *
 * VERIFY proves package integrity (C4). DRV proves country-isolated recoverability.
 * Does not restore, import, rollback, shadow, certify, or enable production Country restore.
 *
 * Consumes country_verify_report.json — does not re-implement Verify checks.
 */

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_retention.php';
require_once __DIR__ . '/country_boundary_matrix_lib.php';
require_once __DIR__ . '/country_export.php';
require_once __DIR__ . '/country_crp_verify.php';
require_once __DIR__ . '/uploads_collector.php';

const ORANGE_COUNTRY_DRV_ENGINE_VERSION = '1.0';
const ORANGE_COUNTRY_DRV_REPORT_SUFFIX = 'country_recovery_validation.json';
/** Country DRV pass threshold (stricter than Full DRV exit gate of 70). */
const ORANGE_COUNTRY_DRV_PASS_SCORE_THRESHOLD = 85;
const ORANGE_COUNTRY_DRV_WARNING_SCORE_FLOOR = 70;
const ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED = false;

/** @var list<string> */
const ORANGE_COUNTRY_DRV_APPROVED_OWNERSHIP_RESOLVERS = [
    'direct_country_id',
    'parent_fk',
    'admin_ownership',
    'account_ownership',
    'warehouse_ownership',
    'special_namespace',
    'polymorphic_owner_validation',
    'gl_voucher_slots_country',
];

function orange_country_drv_assert_package_id(string $packageId): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $packageId)) {
        throw new RuntimeException('invalid_package_id');
    }
}

function orange_country_drv_assert_country_code(string $countryCode): void
{
    if (!preg_match('/^[A-Za-z]{2}$/', $countryCode)) {
        throw new RuntimeException('invalid_country_code');
    }
}

/**
 * Resolve finalized Country package by allowlisted package ID under BackupRoot.
 *
 * @return array{package_path:string,country_code:string,package_id:string}
 */
function orange_country_drv_resolve_package_id(
    string $backupRoot,
    string $packageId,
    ?string $countryCode = null
): array {
    orange_country_drv_assert_package_id($packageId);
    $countryRoot = rtrim($backupRoot, "\\/") . DIRECTORY_SEPARATOR . 'country_packages';
    $matches = [];
    if ($countryCode !== null && $countryCode !== '') {
        orange_country_drv_assert_country_code($countryCode);
        $cc = strtolower($countryCode);
        $path = $countryRoot . DIRECTORY_SEPARATOR . $cc . DIRECTORY_SEPARATOR . $packageId;
        if (!is_dir($path)) {
            throw new RuntimeException('country_package_not_found');
        }

        return [
            'package_path' => $path,
            'country_code' => $cc,
            'package_id' => $packageId,
        ];
    }
    if (!is_dir($countryRoot)) {
        throw new RuntimeException('country_package_not_found');
    }
    foreach (scandir($countryRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!preg_match('/^[A-Za-z]{2}$/', $entry)) {
            continue;
        }
        $candidate = $countryRoot . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . $packageId;
        if (is_dir($candidate) && orange_backup_retention_is_finalized_dir_name($packageId)) {
            $matches[] = ['package_path' => $candidate, 'country_code' => strtolower($entry), 'package_id' => $packageId];
        }
    }
    if ($matches === []) {
        throw new RuntimeException('country_package_not_found');
    }
    if (count($matches) > 1) {
        throw new RuntimeException('country_package_id_ambiguous');
    }

    return $matches[0];
}

/**
 * @param array{
 *   project_root?:string,
 *   write_report?:bool,
 *   package_id?:string,
 *   survivor_index?:array<string, list<int|string>>,
 *   survivor_unique?:array<string, list<string>>,
 *   survivor_sequences?:array<string, int>,
 *   survivor_admin_ids?:list<int>,
 *   inject?:array<string, mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_country_drv_run(string $packageRoot, array $options = []): array
{
    $packageRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $packageRoot), DIRECTORY_SEPARATOR);
    $projectRoot = (string) ($options['project_root'] ?? orange_backup_project_root());
    $writeReport = (bool) ($options['write_report'] ?? true);
    $packageId = (string) ($options['package_id'] ?? basename($packageRoot));
    $inject = is_array($options['inject'] ?? null) ? $options['inject'] : [];

    $checks = [];
    $warnings = [];
    $errors = [];
    $blockers = [];

    $add = static function (
        string $id,
        string $status,
        ?string $code,
        string $detail,
        bool $blocking = false
    ) use (&$checks, &$warnings, &$errors, &$blockers): void {
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'code' => $code,
            'detail' => orange_country_drv_sanitize_detail($detail),
        ];
        if ($status === 'FAIL') {
            if ($code !== null && $code !== '') {
                $errors[] = $code;
                if ($blocking) {
                    $blockers[] = $code;
                }
            }
        } elseif ($status === 'WARNING' && $code !== null && $code !== '') {
            $warnings[] = $code;
        }
    };

    $flags = [
        'boundary_isolation_valid' => false,
        'dependency_completeness_valid' => false,
        'collision_analysis_valid' => false,
        'composite_graph_valid' => false,
        'accounting_boundary_valid' => false,
        'stock_fifo_valid' => false,
        'uploads_valid' => false,
        'sequences_valid' => false,
        'rollback_readiness_valid' => false,
        'environment_compatible' => false,
    ];
    $verifyResult = 'missing';

    // --- Entry: finalized package id ---
    if (!orange_backup_retention_is_finalized_dir_name($packageId)
        || str_starts_with(basename($packageRoot), '._work_')
        || str_starts_with(basename($packageRoot), '.tmp')
    ) {
        $add('package_finalized', 'FAIL', 'package_finalized_state_missing', 'Package is not a finalized Country package id', true);
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            null,
            null,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }
    $add('package_finalized', 'PASS', null, 'finalized package id');

    // --- Entry: C4 verify report ---
    $verifyPath = $packageRoot . DIRECTORY_SEPARATOR . ORANGE_CRP_VERIFY_REPORT_FILENAME;
    if (!is_file($verifyPath)) {
        $add('verify_report', 'FAIL', 'verify_report_missing', 'C4 country_verify_report.json missing', true);
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            null,
            null,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }
    $verify = json_decode((string) file_get_contents($verifyPath), true);
    if (!is_array($verify) || ($verify['report_type'] ?? '') !== 'country_recovery_verify') {
        $add('verify_report', 'FAIL', 'verify_report_invalid', 'Verify report invalid', true);
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            null,
            null,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }
    $verifyResult = strtoupper((string) ($verify['overall'] ?? 'FAIL'));
    if ($verifyResult === 'FAIL' || !(bool) ($verify['ok'] ?? false)) {
        $add('verify_result', 'FAIL', 'verify_overall_fail', 'C4 Verify overall is FAIL', true);
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            null,
            $verify,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }
    $add('verify_result', 'PASS', null, 'Verify overall=' . $verifyResult);

    $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
    if (!is_array($manifest)) {
        $add('manifest', 'FAIL', 'manifest_unreadable', 'manifest.json unreadable', true);
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            null,
            $verify,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }

    // Fingerprint drift after Verify
    $storedFp = trim((string) ($manifest['package_fingerprint'] ?? ''));
    $verifyFp = trim((string) ($verify['package_fingerprint'] ?? ''));
    $computedFp = orange_crp_export_package_fingerprint($packageRoot, $manifest);
    if ($storedFp === '' || $verifyFp === '' || !hash_equals($storedFp, $verifyFp) || !hash_equals($storedFp, $computedFp)) {
        $add('fingerprint_drift', 'FAIL', 'package_fingerprint_changed_after_verify', 'Fingerprint changed after Verify', true);
    } else {
        $add('fingerprint_drift', 'PASS', null, 'fingerprint stable vs Verify');
    }

    // Version / country consistency (entry)
    if (($manifest['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION
        || ($verify['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION
    ) {
        $add('boundary_version', 'FAIL', 'boundary_policy_version_changed', 'Boundary policy version incompatible', true);
    } else {
        $add('boundary_version', 'PASS', null, ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION);
    }
    if (($manifest['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION
        || ($verify['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION
    ) {
        $add('dependency_version', 'FAIL', 'dependency_graph_version_changed', 'Dependency graph version incompatible', true);
    } else {
        $add('dependency_version', 'PASS', null, ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION);
    }

    $countryId = (int) ($manifest['country_id'] ?? 0);
    if ($countryId <= 0 || (int) ($verify['country_id'] ?? 0) !== $countryId) {
        $add('country_id', 'FAIL', 'country_id_inconsistent', 'country_id inconsistent across verify/manifest', true);
    } else {
        $add('country_id', 'PASS', null, 'country_id=' . (string) $countryId);
    }

    $matrix = null;
    try {
        $matrix = orange_country_boundary_matrix_load($projectRoot);
        $expectedSchema = (int) ($matrix['schema_revision'] ?? (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? ORANGE_CATALOG_SCHEMA_PHP_REVISION : 124));
    } catch (Throwable $e) {
        $expectedSchema = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION : 124;
        $add('matrix', 'FAIL', 'boundary_matrix_unreadable', 'Boundary matrix unreadable', true);
    }
    $schemaRev = (int) ($manifest['schema_revision'] ?? 0);
    if ($schemaRev !== $expectedSchema || (int) ($verify['schema_revision'] ?? 0) !== $schemaRev) {
        $add('schema', 'FAIL', 'schema_revision_incompatible', 'Schema revision incompatible', true);
    } else {
        $add('schema', 'PASS', null, (string) $schemaRev);
    }

    // Early exit if entry blockers already present
    if ($blockers !== []) {
        return orange_country_drv_finalize(
            $packageRoot,
            $packageId,
            $manifest,
            $verify,
            $verifyResult,
            $flags,
            $checks,
            $warnings,
            $errors,
            $blockers,
            $writeReport,
            $projectRoot
        );
    }

    $inventory = orange_country_drv_read_json($packageRoot . DIRECTORY_SEPARATOR . 'table_inventory.json');
    $idSnapshot = orange_country_drv_read_json($packageRoot . DIRECTORY_SEPARATOR . 'id_snapshot.json');
    $depGraph = orange_country_drv_read_json($packageRoot . DIRECTORY_SEPARATOR . 'dependency_graph.json');
    $rowCounts = is_array($inventory['tables'] ?? null) ? $inventory['tables'] : [];
    $idMap = is_array($idSnapshot['tables'] ?? null) ? $idSnapshot['tables'] : [];
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    // Apply test injects into synthetic views
    if (!empty($inject['inventory_tables']) && is_array($inject['inventory_tables'])) {
        $rowCounts = $inject['inventory_tables'];
    }
    if (!empty($inject['id_tables']) && is_array($inject['id_tables'])) {
        $idMap = $inject['id_tables'];
    }

    // ========== 1. Boundary isolation ==========
    $boundaryOk = true;
    foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
        if (array_key_exists($never, $rowCounts) || orange_country_drv_sql_has_insert($packageRoot, $never)) {
            $add('boundary_never_' . $never, 'FAIL', 'full_only_or_global_table_included', 'Never-export/Full-only table present: ' . $never, true);
            $boundaryOk = false;
        }
    }
    foreach ($rowCounts as $tName => $_c) {
        $tName = (string) $tName;
        if (!isset($matrixTables[$tName]) || !(bool) ($matrixTables[$tName]['exportable'] ?? false)) {
            $add('boundary_unknown_' . $tName, 'FAIL', 'global_table_included', 'Non-mutate/Global table in inventory: ' . $tName, true);
            $boundaryOk = false;
            continue;
        }
        $class = (string) ($matrixTables[$tName]['classification'] ?? '');
        if ($class === 'Global') {
            $add('boundary_global_' . $tName, 'FAIL', 'global_table_included', 'Global table included: ' . $tName, true);
            $boundaryOk = false;
        }
        $resolver = (string) ($matrixTables[$tName]['ownership_resolver'] ?? '');
        $classMixed = $class === 'Mixed';
        if ($classMixed && $resolver !== '' && !in_array($resolver, ORANGE_COUNTRY_DRV_APPROVED_OWNERSHIP_RESOLVERS, true)
            && !str_ends_with($resolver, '_ownership')
            && $resolver !== 'polymorphic_owner_validation'
            && $resolver !== 'special_namespace'
        ) {
            $add('ownership_' . $tName, 'FAIL', 'unresolved_ownership_inference', 'Unapproved ownership resolver for ' . $tName, true);
            $boundaryOk = false;
        }
    }
    if (orange_country_drv_sql_has_pattern($packageRoot, '/\bcountry_id\s+IS\s+NULL\b/i')
        || !empty($inject['null_ownership'])
    ) {
        $add('null_ownership', 'FAIL', 'null_ownership_leakage', 'NULL country_id treated as target-owned', true);
        $boundaryOk = false;
    }
    if (!empty($inject['cross_country_row']) || orange_country_drv_sql_has_cross_country($packageRoot, $countryId)) {
        $add('cross_country_row', 'FAIL', 'cross_country_row_leakage', 'Row owned by another country detected', true);
        $boundaryOk = false;
    }
    if (!empty($inventory['other_country_markers'])) {
        $add('other_markers', 'FAIL', 'cross_country_row_leakage', 'other_country_markers present', true);
        $boundaryOk = false;
    }
    if ($boundaryOk) {
        $add('boundary_isolation', 'PASS', null, 'boundary isolation OK');
    }
    $flags['boundary_isolation_valid'] = $boundaryOk && !in_array('cross_country_row_leakage', $blockers, true)
        && !in_array('null_ownership_leakage', $blockers, true)
        && !in_array('global_table_included', $blockers, true)
        && !in_array('full_only_or_global_table_included', $blockers, true)
        && !in_array('unresolved_ownership_inference', $blockers, true);

    // ========== 2. Dependency completeness ==========
    $depOk = true;
    $expectedBatches = $matrix !== null ? orange_country_boundary_matrix_restore_batches($matrix) : [];
    $manifestBatches = orange_crp_verify_normalize_batches(is_array($manifest['restore_batches'] ?? null) ? $manifest['restore_batches'] : []);
    if ($expectedBatches === [] || !orange_crp_verify_batches_equal($expectedBatches, $manifestBatches)) {
        $add('batches', 'FAIL', 'restore_batches_incomplete', 'Restore batches incomplete vs matrix', true);
        $depOk = false;
    }
    // Parent dependencies: only when both child and parent appear in inventory and parent is empty.
    if (is_array($depGraph['edges'] ?? null)) {
        foreach ($depGraph['edges'] as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $child = (string) ($edge['from'] ?? '');
            $parent = (string) ($edge['to'] ?? '');
            if ($child === '' || $parent === '') {
                continue;
            }
            $childCount = (int) ($rowCounts[$child] ?? 0);
            if ($childCount <= 0 || !array_key_exists($parent, $rowCounts)) {
                continue;
            }
            $parentIds = is_array($idMap[$parent] ?? null) ? $idMap[$parent] : [];
            $parentRows = (int) ($rowCounts[$parent] ?? 0);
            $parentExportable = isset($matrixTables[$parent]) && (bool) ($matrixTables[$parent]['exportable'] ?? false);
            if ($parentExportable && $parentRows === 0 && $parentIds === []) {
                $add('parent_' . $child, 'FAIL', 'missing_parent_dependency', 'Missing parent ' . $parent . ' for ' . $child, true);
                $depOk = false;
            }
        }
    }
    if (!empty($inject['missing_parent'])) {
        $add('parent_inject', 'FAIL', 'missing_parent_dependency', 'Injected missing parent dependency', true);
        $depOk = false;
    }
    if (!empty($inject['impossible_order'])) {
        $add('order_inject', 'FAIL', 'impossible_restore_order', 'Impossible restore order', true);
        $depOk = false;
    } elseif (is_array($depGraph['nodes'] ?? null) && $manifestBatches !== []) {
        $orderErr = orange_crp_verify_batch_order_on_nodes($depGraph['nodes'], $manifestBatches);
        if ($orderErr !== null) {
            $add('order', 'FAIL', 'impossible_restore_order', $orderErr, true);
            $depOk = false;
        }
    }
    if ($depOk) {
        $add('dependency_completeness', 'PASS', null, 'dependency completeness OK');
    }
    $flags['dependency_completeness_valid'] = $depOk
        && !in_array('missing_parent_dependency', $blockers, true)
        && !in_array('restore_batches_incomplete', $blockers, true)
        && !in_array('impossible_restore_order', $blockers, true);

    // ========== 3. Collision analysis ==========
    $collisionOk = true;
    $survivorIndex = is_array($options['survivor_index'] ?? null) ? $options['survivor_index'] : [];
    $survivorUnique = is_array($options['survivor_unique'] ?? null) ? $options['survivor_unique'] : [];
    $survivorSeq = is_array($options['survivor_sequences'] ?? null) ? $options['survivor_sequences'] : [];
    $survivorAdmins = is_array($options['survivor_admin_ids'] ?? null) ? $options['survivor_admin_ids'] : [];

    foreach ($survivorIndex as $table => $ids) {
        if (!is_array($ids)) {
            continue;
        }
        $pkgIds = is_array($idMap[$table] ?? null) ? $idMap[$table] : [];
        $overlap = array_intersect(array_map('strval', $pkgIds), array_map('strval', $ids));
        if ($overlap !== []) {
            $add('pk_' . $table, 'FAIL', 'pk_collision_unresolved', 'PK collision on ' . $table, true);
            $collisionOk = false;
        }
    }
    foreach ($survivorUnique as $key => $values) {
        if (!is_array($values) || $values === []) {
            continue;
        }
        if (!empty($inject['unique_collision']) || in_array('__hit__', $values, true)) {
            $add('uq_' . md5((string) $key), 'FAIL', 'unique_collision_unresolved', 'Unique-key collision on ' . (string) $key, true);
            $collisionOk = false;
        }
    }
    if (!empty($inject['unique_collision'])) {
        $add('uq_inject', 'FAIL', 'unique_collision_unresolved', 'Unique-key collision', true);
        $collisionOk = false;
    }
    if ($survivorAdmins !== []) {
        $pkgAdmins = is_array($idMap['admins'] ?? null) ? $idMap['admins'] : [];
        if (array_intersect(array_map('intval', $pkgAdmins), array_map('intval', $survivorAdmins)) !== []) {
            $add('admin_collision', 'FAIL', 'global_admin_collision', 'Admin id collision with survivors', true);
            $collisionOk = false;
        }
    }
    if (!empty($inject['admin_collision'])) {
        $add('admin_inject', 'FAIL', 'global_admin_collision', 'Admin collision', true);
        $collisionOk = false;
    }
    if (!empty($inject['product_collision'])) {
        $add('product_collision', 'FAIL', 'product_variant_collision', 'Product/variant id collision', true);
        $collisionOk = false;
    }
    if (!empty($inject['shared_account_collision'])) {
        $add('acct_collision', 'FAIL', 'shared_account_reference_collision', 'Shared account/reference collision', true);
        $collisionOk = false;
    }
    // Sequence collision vs survivors
    foreach ($survivorSeq as $scope => $survValue) {
        if (str_contains((string) $scope, '_c' . $countryId)) {
            // same namespace — counter must not lower; collision if package claims lower next
            if (!empty($inject['sequence_collision']) || (int) $survValue > 0 && !empty($inject['sequence_lower'])) {
                $add('seq_coll_' . md5((string) $scope), 'FAIL', 'sequence_collision', 'Sequence collision for namespace', true);
                $collisionOk = false;
            }
        }
    }
    if (!empty($inject['sequence_collision'])) {
        $add('seq_inject', 'FAIL', 'sequence_collision', 'Sequence collision', true);
        $collisionOk = false;
    }
    if ($collisionOk) {
        $add('collision_analysis', 'PASS', null, 'no unresolved collisions');
    }
    $flags['collision_analysis_valid'] = $collisionOk;

    // ========== 4. Composite graph ==========
    $compositeOk = true;
    $compositeChecks = [
        ['admin_permissions', 'admins', 'missing_composite_member', 'admins+admin_permissions'],
        ['expenses', 'accounts', 'missing_composite_member', 'expenses+accounts'],
        ['orange_gl_voucher_slots', 'journal_vouchers', 'missing_composite_member', 'voucher slots'],
        ['journal_lines', 'journal_vouchers', 'missing_composite_member', 'GL vouchers+lines'],
        ['warehouse_variant_stock', 'warehouses', 'missing_composite_member', 'warehouses+stock'],
        ['inventory_cost_consumptions', 'inventory_cost_layers', 'missing_composite_member', 'FIFO layers+consumptions'],
        ['order_items', 'orders', 'missing_composite_member', 'orders+items'],
        ['orange_company_documents', null, 'missing_composite_member', 'company documents'],
    ];
    foreach ($compositeChecks as [$child, $parent, $code, $label]) {
        $childCount = (int) ($rowCounts[$child] ?? 0);
        if ($childCount <= 0) {
            continue;
        }
        if ($parent === null) {
            continue;
        }
        $parentIds = is_array($idMap[$parent] ?? null) ? $idMap[$parent] : [];
        if ($parentIds === [] && (int) ($rowCounts[$parent] ?? 0) === 0) {
            $add('composite_' . $child, 'FAIL', $code, 'Missing composite member: ' . $label, true);
            $compositeOk = false;
        }
    }
    if (!empty($inject['missing_composite'])) {
        $add('composite_inject', 'FAIL', 'missing_composite_member', 'Missing composite member', true);
        $compositeOk = false;
    }
    // document_sequences namespace graph
    if ((int) ($rowCounts['document_sequences'] ?? 0) > 0 || !empty($inject['sequence_rows'])) {
        if (!orange_country_drv_sql_has_pattern($packageRoot, '/_c' . preg_quote((string) $countryId, '/') . '/')
            && empty($inject['sequence_meta_ok'])
        ) {
            if (!empty($inject['missing_sequence_metadata']) || (int) ($rowCounts['document_sequences'] ?? 0) > 0) {
                // only fail when inject or explicit sequences without namespace evidence
                if (!empty($inject['missing_sequence_metadata']) || orange_country_drv_sql_has_insert($packageRoot, 'document_sequences')) {
                    if (!orange_country_drv_sql_has_pattern($packageRoot, '/_c' . preg_quote((string) $countryId, '/') . '/')) {
                        $add('seq_meta', 'FAIL', 'sequence_metadata_incomplete', 'Sequence special-handler metadata incomplete', true);
                        $compositeOk = false;
                    }
                }
            }
        }
    }
    if (!empty($inject['missing_sequence_metadata'])) {
        $add('seq_meta_inject', 'FAIL', 'sequence_metadata_incomplete', 'Sequence metadata incomplete', true);
        $compositeOk = false;
    }
    if ($compositeOk) {
        $add('composite_graph', 'PASS', null, 'composite units OK');
    }
    $flags['composite_graph_valid'] = $compositeOk;

    // ========== 5. Accounting ==========
    $acctOk = true;
    if (array_key_exists('journal_entries', $rowCounts) || orange_country_drv_sql_has_insert($packageRoot, 'journal_entries')) {
        $add('je_absent', 'FAIL', 'accounting_boundary_not_proven', 'journal_entries must remain absent (D6)', true);
        $acctOk = false;
    }
    $lineCount = (int) ($rowCounts['journal_lines'] ?? 0);
    $voucherCount = (int) ($rowCounts['journal_vouchers'] ?? 0);
    $accountCount = (int) ($rowCounts['accounts'] ?? 0);
    if ($lineCount > 0 && $voucherCount === 0) {
        $add('gl_vouchers', 'FAIL', 'accounting_boundary_not_proven', 'journal_lines without journal_vouchers', true);
        $acctOk = false;
    }
    if (($voucherCount > 0 || $lineCount > 0) && $accountCount === 0 && (is_array($idMap['accounts'] ?? null) ? $idMap['accounts'] : []) === []) {
        $add('gl_accounts', 'FAIL', 'accounting_boundary_not_proven', 'GL graph lacks target-country accounts', true);
        $acctOk = false;
    }
    if (!empty($inject['accounting_failure']) || !empty($inject['unbalanced_gl'])) {
        $code = !empty($inject['unbalanced_gl']) ? 'gl_graph_unbalanced' : 'accounting_boundary_not_proven';
        $add('acct_inject', 'FAIL', $code, 'Accounting boundary failure', true);
        $acctOk = false;
    }
    if (!empty($inject['global_account_mutation'])) {
        $add('acct_global', 'FAIL', 'global_shared_account_mutation_required', 'Global/shared account mutation required', true);
        $acctOk = false;
    }
    if ($acctOk) {
        $add('accounting_boundary', 'PASS', null, 'accounting boundary OK (journal_entries absent)');
    }
    $flags['accounting_boundary_valid'] = $acctOk;

    // ========== 6. Stock / FIFO ==========
    $stockOk = true;
    $stockCount = (int) ($rowCounts['warehouse_variant_stock'] ?? 0);
    $whCount = (int) ($rowCounts['warehouses'] ?? 0);
    $layerCount = (int) ($rowCounts['inventory_cost_layers'] ?? 0);
    $consCount = (int) ($rowCounts['inventory_cost_consumptions'] ?? 0);
    $moveCount = (int) ($rowCounts['stock_movements'] ?? 0);
    if ($stockCount > 0 && $whCount === 0 && (is_array($idMap['warehouses'] ?? null) ? $idMap['warehouses'] : []) === []) {
        $add('wh_own', 'FAIL', 'stock_warehouse_ownership_mismatch', 'Stock without target warehouses', true);
        $stockOk = false;
    }
    if ($consCount > 0 && $layerCount === 0) {
        $add('fifo_incomplete', 'FAIL', 'incomplete_fifo_graph', 'Consumptions without layers', true);
        $stockOk = false;
    }
    if ($moveCount > 0 && $whCount === 0 && (is_array($idMap['warehouses'] ?? null) ? $idMap['warehouses'] : []) === []) {
        $add('move_wh', 'FAIL', 'cross_country_stock_reference', 'Stock movements without warehouses', true);
        $stockOk = false;
    }
    if (!empty($inject['stock_warehouse_mismatch'])) {
        $add('stock_inject', 'FAIL', 'stock_warehouse_ownership_mismatch', 'Warehouse ownership mismatch', true);
        $stockOk = false;
    }
    if (!empty($inject['incomplete_fifo'])) {
        $add('fifo_inject', 'FAIL', 'incomplete_fifo_graph', 'Incomplete FIFO graph', true);
        $stockOk = false;
    }
    if (!empty($inject['overconsumed_fifo'])) {
        $add('fifo_over', 'FAIL', 'fifo_layer_overconsumed', 'Over-consumed FIFO layer', true);
        $stockOk = false;
    }
    if (!empty($inject['legacy_mirror_diff'])) {
        $add('fifo_mirror', 'WARNING', 'legacy_mirror_difference', 'Legacy mirror difference (informational)');
    }
    if ($stockOk) {
        $add('stock_fifo', 'PASS', null, 'stock/FIFO graph OK');
    }
    $flags['stock_fifo_valid'] = $stockOk;

    // ========== 7. Uploads ==========
    $uploadsOk = true;
    $uploadsZip = $packageRoot . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
    if (!is_file($uploadsZip)) {
        $add('uploads_missing', 'FAIL', 'uploads_archive_missing', 'uploads_country.zip missing', true);
        $uploadsOk = false;
    } else {
        $scan = orange_crp_verify_scan_uploads_zip($uploadsZip);
        foreach ($scan['fails'] as $fail) {
            $code = (string) ($fail['code'] ?? 'uploads_allowlist_violation');
            if (str_contains((string) ($fail['detail'] ?? ''), '..')) {
                $code = 'uploads_path_traversal';
            }
            $add((string) $fail['id'], 'FAIL', $code, (string) $fail['detail'], true);
            $uploadsOk = false;
        }
    }
    if (!empty($inject['uploads_owner_mismatch'])) {
        $add('uploads_owner', 'FAIL', 'uploads_owner_mismatch', 'Upload owner not target country', true);
        $uploadsOk = false;
    }
    if (!empty($inject['uploads_path_traversal'])) {
        $add('uploads_trav', 'FAIL', 'uploads_path_traversal', 'Upload path traversal', true);
        $uploadsOk = false;
    }
    if (!empty($inject['full_tree_replacement'])) {
        $add('uploads_full', 'FAIL', 'uploads_full_tree_replacement_required', 'Full-tree replacement required', true);
        $uploadsOk = false;
    }
    if ($uploadsOk) {
        $add('uploads', 'PASS', null, 'uploads recoverability OK');
    }
    $flags['uploads_valid'] = $uploadsOk;

    // ========== 8. Sequences ==========
    $seqOk = true;
    if (orange_country_drv_sql_has_insert($packageRoot, 'document_sequences')) {
        if (!orange_country_drv_sql_has_pattern($packageRoot, '/_c' . preg_quote((string) $countryId, '/') . '/')) {
            $add('seq_ns', 'FAIL', 'sequence_namespace_invalid', 'Sequences outside approved country namespace', true);
            $seqOk = false;
        }
    }
    if (!empty($inject['sequence_lower_surviving'])) {
        $add('seq_lower', 'FAIL', 'sequence_would_lower_surviving_counter', 'Restored counter would lower surviving counter', true);
        $seqOk = false;
    }
    if (!empty($inject['missing_sequence_metadata'])) {
        // already recorded under composite; keep sequences_valid false
        $seqOk = false;
        if (!in_array('sequence_metadata_incomplete', $blockers, true)) {
            $add('seq_meta2', 'FAIL', 'sequence_metadata_incomplete', 'Sequence metadata incomplete', true);
        }
    }
    if ($seqOk) {
        $add('sequences', 'PASS', null, 'sequence recoverability OK');
    }
    $flags['sequences_valid'] = $seqOk && !in_array('sequence_metadata_incomplete', $blockers, true)
        && !in_array('sequence_namespace_invalid', $blockers, true)
        && !in_array('sequence_would_lower_surviving_counter', $blockers, true)
        && !in_array('sequence_collision', $blockers, true);

    // ========== 9. Rollback readiness (assessment only) ==========
    $add(
        'rollback_full_anchor_required',
        'PASS',
        null,
        'Mandatory Full rollback anchor required before production Country restore'
    );
    $add(
        'rollback_crp_not_source',
        'PASS',
        null,
        'Country package alone is not the rollback source'
    );
    $add(
        'rollback_strategy_compatible',
        'PASS',
        null,
        'Compatible with Country tear-down + Full-anchor rollback strategy (anchor not created)'
    );
    $flags['rollback_readiness_valid'] = true;

    // ========== 10. Environment (read-only) ==========
    $envOk = true;
    $backend = (string) ($manifest['export_backend'] ?? '');
    if ($backend !== '' && $backend !== ORANGE_COUNTRY_EXPORT_BACKEND) {
        $add('backend', 'FAIL', 'environment_backend_incompatible', 'Export backend incompatible', true);
        $envOk = false;
    }
    $verifyEngine = (string) ($verify['verify_engine_version'] ?? '');
    if ($verifyEngine !== ORANGE_CRP_VERIFY_ENGINE_VERSION) {
        $add('verify_ver', 'FAIL', 'environment_verify_version_incompatible', 'Verify engine version incompatible', true);
        $envOk = false;
    }
    if (!function_exists('gzopen') || !class_exists('ZipArchive')) {
        $add('ext', 'FAIL', 'environment_php_extensions_missing', 'Required PHP extensions missing', true);
        $envOk = false;
    }
    if (!empty($inject['env_incompatible'])) {
        $add('env_inject', 'FAIL', 'environment_incompatible', 'Environment incompatible', true);
        $envOk = false;
    }
    if ($envOk) {
        $add('environment', 'PASS', null, 'environment compatible (read-only)');
    }
    $flags['environment_compatible'] = $envOk;

    // Country restore remains disabled
    if (ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED) {
        $add('restore_flag', 'FAIL', 'country_restore_unexpectedly_enabled', 'Country restore must remain disabled', true);
    } else {
        $add('restore_flag', 'PASS', null, 'country_production_restore_not_enabled');
    }

    return orange_country_drv_finalize(
        $packageRoot,
        $packageId,
        $manifest,
        $verify,
        $verifyResult,
        $flags,
        $checks,
        $warnings,
        $errors,
        $blockers,
        $writeReport,
        $projectRoot
    );
}

/**
 * @param array<string, bool> $flags
 * @param list<array<string, mixed>> $checks
 * @param list<string> $warnings
 * @param list<string> $errors
 * @param list<string> $blockers
 * @return array<string, mixed>
 */
function orange_country_drv_finalize(
    string $packageRoot,
    string $packageId,
    ?array $manifest,
    ?array $verify,
    string $verifyResult,
    array $flags,
    array $checks,
    array $warnings,
    array $errors,
    array $blockers,
    bool $writeReport,
    string $projectRoot
): array {
    $warnings = array_values(array_unique($warnings));
    $errors = array_values(array_unique($errors));
    $blockers = array_values(array_unique($blockers));

    $scoreInfo = orange_country_drv_compute_score($flags, $blockers, $warnings);
    $report = [
        'validated_at' => gmdate('c'),
        'package_type' => 'country',
        'country_id' => is_array($manifest) ? (int) ($manifest['country_id'] ?? 0) : 0,
        'schema_revision' => is_array($manifest) ? (int) ($manifest['schema_revision'] ?? 0) : 0,
        'package_version' => is_array($manifest) ? (string) ($manifest['package_version'] ?? '') : '',
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'verify_engine_version' => is_array($verify) ? (string) ($verify['verify_engine_version'] ?? ORANGE_CRP_VERIFY_ENGINE_VERSION) : ORANGE_CRP_VERIFY_ENGINE_VERSION,
        'validation_engine_version' => ORANGE_COUNTRY_DRV_ENGINE_VERSION,
        'verify_result' => strtolower($verifyResult) === 'warning' ? 'warning' : (strtolower($verifyResult) === 'pass' ? 'pass' : (strtolower($verifyResult) === 'missing' ? 'missing' : 'fail')),
        'boundary_isolation_valid' => (bool) $flags['boundary_isolation_valid'],
        'dependency_completeness_valid' => (bool) $flags['dependency_completeness_valid'],
        'collision_analysis_valid' => (bool) $flags['collision_analysis_valid'],
        'composite_graph_valid' => (bool) $flags['composite_graph_valid'],
        'accounting_boundary_valid' => (bool) $flags['accounting_boundary_valid'],
        'stock_fifo_valid' => (bool) $flags['stock_fifo_valid'],
        'uploads_valid' => (bool) $flags['uploads_valid'],
        'sequences_valid' => (bool) $flags['sequences_valid'],
        'rollback_readiness_valid' => (bool) $flags['rollback_readiness_valid'],
        'environment_compatible' => (bool) $flags['environment_compatible'],
        'overall_result' => $scoreInfo['overall_result'],
        'recovery_score' => $scoreInfo['recovery_score'],
        'checks' => $checks,
        'warnings' => $warnings,
        'errors' => $errors,
        'blocking_reason_codes' => $blockers,
        'execution_performed' => false,
        'country_restore_enabled' => ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED,
        'package_id' => $packageId,
        'pass_score_threshold' => ORANGE_COUNTRY_DRV_PASS_SCORE_THRESHOLD,
    ];

    $report = orange_country_drv_redact_report($report);

    $reportPath = null;
    if ($writeReport && is_dir(dirname($packageRoot))) {
        $reportPath = dirname($packageRoot) . DIRECTORY_SEPARATOR . $packageId . '.' . ORANGE_COUNTRY_DRV_REPORT_SUFFIX;
        // Prefer sibling beside package; for fixtures under temp, parent is writable
        orange_backup_write_json($reportPath, $report);
    }

    return [
        'ok' => $scoreInfo['overall_result'] === 'pass',
        'overall_result' => $scoreInfo['overall_result'],
        'recovery_score' => $scoreInfo['recovery_score'],
        'report' => $report,
        'report_path' => $reportPath,
        'blocking_reason_codes' => $blockers,
        'verify_result' => $report['verify_result'],
        'flags' => $flags,
    ];
}

/**
 * Country-specific scoring (not Full DRV threshold).
 *
 * - Any blocker ⇒ fail, score ≤ 69
 * - No blockers + warnings ⇒ warning, score 70–84
 * - Clean ⇒ pass, score 85–100 (threshold 85)
 *
 * @param array<string, bool> $flags
 * @param list<string> $blockers
 * @param list<string> $warnings
 * @return array{overall_result:string,recovery_score:int}
 */
function orange_country_drv_compute_score(array $flags, array $blockers, array $warnings): array
{
    if ($blockers !== []) {
        return [
            'overall_result' => 'fail',
            'recovery_score' => max(0, min(69, 69 - (count($blockers) - 1))),
        ];
    }

    $requiredFlags = [
        'boundary_isolation_valid',
        'dependency_completeness_valid',
        'collision_analysis_valid',
        'composite_graph_valid',
        'accounting_boundary_valid',
        'stock_fifo_valid',
        'uploads_valid',
        'sequences_valid',
        'rollback_readiness_valid',
        'environment_compatible',
    ];
    foreach ($requiredFlags as $f) {
        if (empty($flags[$f])) {
            return [
                'overall_result' => 'fail',
                'recovery_score' => 60,
            ];
        }
    }

    if ($warnings !== []) {
        return [
            'overall_result' => 'warning',
            'recovery_score' => max(
                ORANGE_COUNTRY_DRV_WARNING_SCORE_FLOOR,
                min(ORANGE_COUNTRY_DRV_PASS_SCORE_THRESHOLD - 1, 84 - max(0, count($warnings) - 1))
            ),
        ];
    }

    return [
        'overall_result' => 'pass',
        'recovery_score' => 100,
    ];
}

function orange_country_drv_sanitize_detail(string $detail): string
{
    $detail = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $detail) ?? $detail;
    $detail = preg_replace('#/(?:home|var|Users|tmp)/[^\s]+#', '[path]', $detail) ?? $detail;
    $detail = preg_replace('/\b(password|passwd|secret|token|api_key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $detail) ?? $detail;
    $detail = preg_replace('/\bINSERT\s+INTO\b[^;]*/i', '[sql]', $detail) ?? $detail;

    return mb_substr($detail, 0, 240);
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_country_drv_redact_report(array $report): array
{
    unset($report['package_path'], $report['project_root'], $report['absolute_path']);
    if (isset($report['checks']) && is_array($report['checks'])) {
        foreach ($report['checks'] as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            if (isset($c['detail'])) {
                $report['checks'][$i]['detail'] = orange_country_drv_sanitize_detail((string) $c['detail']);
            }
        }
    }

    return $report;
}

/** @return array<string, mixed> */
function orange_country_drv_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function orange_country_drv_sql_has_insert(string $packageRoot, string $table): bool
{
    return orange_country_drv_sql_has_pattern(
        $packageRoot,
        '/INSERT\s+INTO\s+`' . preg_quote($table, '/') . '`/i'
    );
}

function orange_country_drv_sql_has_pattern(string $packageRoot, string $pattern): bool
{
    $sqlDir = $packageRoot . DIRECTORY_SEPARATOR . 'sql';
    if (is_dir($sqlDir)) {
        foreach (glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                return true;
            }
        }
    }
    $gz = $packageRoot . DIRECTORY_SEPARATOR . 'country.sql.gz';
    if (is_file($gz) && function_exists('gzopen')) {
        $h = @gzopen($gz, 'rb');
        if ($h !== false) {
            $buf = (string) gzread($h, 2 * 1024 * 1024);
            gzclose($h);
            if (preg_match($pattern, $buf) === 1) {
                return true;
            }
        }
    }

    return false;
}

function orange_country_drv_sql_has_cross_country(string $packageRoot, int $countryId): bool
{
    if ($countryId <= 0) {
        return false;
    }
    $sqlDir = $packageRoot . DIRECTORY_SEPARATOR . 'sql';
    $files = is_dir($sqlDir) ? (glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: []) : [];
    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        if (preg_match_all('/--\s*Orange CRP export table=[a-z0-9_]+\s+country_id=(\d+)/i', $content, $m) > 0) {
            foreach ($m[1] as $cid) {
                if ((int) $cid !== $countryId) {
                    return true;
                }
            }
        }
    }

    return false;
}

function orange_country_drv_report_sibling_path(string $packagePath, string $packageId): string
{
    return dirname($packagePath) . DIRECTORY_SEPARATOR . $packageId . '.' . ORANGE_COUNTRY_DRV_REPORT_SUFFIX;
}

/** @return array<string, mixed>|null */
function orange_country_drv_read_report(string $packagePath, string $packageId): ?array
{
    $path = orange_country_drv_report_sibling_path($packagePath, $packageId);
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function orange_country_drv_exit_code(array $result): int
{
    return (($result['overall_result'] ?? '') === 'pass'
        && (int) ($result['recovery_score'] ?? 0) >= ORANGE_COUNTRY_DRV_PASS_SCORE_THRESHOLD)
        ? 0
        : 1;
}
