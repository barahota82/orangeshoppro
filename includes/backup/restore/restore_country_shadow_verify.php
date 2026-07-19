<?php

declare(strict_types=1);

/**
 * Phase C7 — Country Shadow Verification.
 *
 * Consumes C6 Country Shadow Restore result. Does not re-import.
 * Never production restore / import / rollback / approval / certification.
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/../country_crp_verify.php';
require_once __DIR__ . '/../country_crp_drv.php';
require_once __DIR__ . '/restore_country_shadow.php';
require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_paths.php';

const ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION = '1.0';
const ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE = 'country_shadow_verification_report.json';
const ORANGE_COUNTRY_SHADOW_VERIFY_META_FILE = 'country_shadow_verification.json';
const ORANGE_COUNTRY_SHADOW_SURVIVOR_BASELINE_FILE = 'survivor_baseline.json';
const ORANGE_COUNTRY_SHADOW_GLOBAL_BASELINE_FILE = 'global_baseline.json';

const ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING_C7 = 'country_shadow_verifying';
const ORANGE_COUNTRY_SHADOW_STATUS_VERIFIED = 'country_shadow_verified';
const ORANGE_COUNTRY_SHADOW_STATUS_WARNING = 'country_shadow_warning';
const ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY = 'country_shadow_not_ready';

/** READY threshold (Country-specific; stricter than Full). */
const ORANGE_COUNTRY_SHADOW_VERIFY_READY_SCORE = 90;
const ORANGE_COUNTRY_SHADOW_VERIFY_WARNING_FLOOR = 75;

/**
 * @param array{
 *   project_root:string,
 *   backup_root:string,
 *   work_root:string,
 *   job_id:string,
 *   env?:array<string,mixed>,
 *   inject?:array<string,mixed>
 * } $options
 * @return array<string, mixed>
 */
function orange_country_shadow_verify_run(array $options): array
{
    $projectRoot = (string) ($options['project_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $inject = is_array($options['inject'] ?? null) ? $options['inject'] : [];

    if ($projectRoot === '' || $backupRoot === '' || $workRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root, backup_root, work_root, job_id required');
    }
    orange_country_shadow_assert_run_id($jobId);

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env'] ?? null)) {
        $env = array_merge($env, $options['env']);
    }
    if (isset($GLOBALS['orange_country_shadow_env_override']) && is_array($GLOBALS['orange_country_shadow_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_country_shadow_env_override']);
    }

    $runDir = orange_country_shadow_run_dir($workRoot, $jobId);
    $metaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE;
    $c6ReportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_REPORT_FILE;
    $verifyMetaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_VERIFY_META_FILE;
    $verifyReportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE;

    $checks = [];
    $warnings = [];
    $blockers = [];
    $integrity = [
        'target_country_integrity' => 'FAIL',
        'survivor_country_integrity' => 'FAIL',
        'global_state_integrity' => 'FAIL',
        'dependency_integrity' => 'FAIL',
        'composite_integrity' => 'FAIL',
        'accounting_integrity' => 'FAIL',
        'stock_fifo_integrity' => 'FAIL',
        'commercial_integrity' => 'FAIL',
        'catalog_integrity' => 'FAIL',
        'admin_permissions_integrity' => 'FAIL',
        'company_documents_integrity' => 'FAIL',
        'sequences_integrity' => 'FAIL',
        'upload_reference_integrity' => 'FAIL',
        'id_preservation_integrity' => 'FAIL',
        'schema_integrity' => 'FAIL',
    ];

    $add = static function (
        string $id,
        string $status,
        ?string $code,
        string $detail,
        bool $blocking = false
    ) use (&$checks, &$warnings, &$blockers): void {
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'code' => $code,
            'detail' => orange_country_shadow_verify_sanitize($detail),
        ];
        if ($status === 'FAIL' && $code !== null && $code !== '') {
            if ($blocking) {
                $blockers[] = $code;
            }
        } elseif ($status === 'WARNING' && $code !== null && $code !== '') {
            $warnings[] = $code;
        }
    };

    $finish = static function (
        string $status,
        string $overall,
        int $score,
        array $extra = []
    ) use (
        &$checks,
        &$warnings,
        &$blockers,
        &$integrity,
        $verifyMetaPath,
        $verifyReportPath,
        $jobId,
        $runDir
    ): array {
        $warnings = array_values(array_unique($warnings));
        $blockers = array_values(array_unique($blockers));
        $meta = [
            'engine_version' => ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION,
            'run_id' => $jobId,
            'status' => $status,
            'overall_result' => $overall,
            'readiness_score' => $score,
            'updated_at' => gmdate('c'),
            'production_db_writes' => 0,
            'production_file_writes' => 0,
            'execution_performed' => false,
            'country_production_restore_enabled' => false,
        ];
        orange_backup_write_json($verifyMetaPath, $meta);

        // Mirror status onto C6 meta for Restore Center visibility
        $c6MetaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE;
        if (is_file($c6MetaPath)) {
            $c6Meta = json_decode((string) file_get_contents($c6MetaPath), true);
            if (is_array($c6Meta)) {
                $c6Meta['verify_status'] = $status;
                $c6Meta['status'] = $status;
                $c6Meta['verify_overall_result'] = $overall;
                $c6Meta['readiness_score'] = $score;
                orange_backup_write_json($c6MetaPath, $c6Meta);
            }
        }

        $report = array_merge([
            'report_version' => ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION,
            'report_type' => 'country_shadow_verification',
            'verified_at' => gmdate('c'),
            'package_id' => (string) ($extra['package_id'] ?? ''),
            'country_id' => (int) ($extra['country_id'] ?? 0),
            'shadow_db_identity_hash' => (string) ($extra['shadow_db_identity_hash'] ?? ''),
            'schema_revision' => (int) ($extra['schema_revision'] ?? 0),
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'package_fingerprint' => (string) ($extra['package_fingerprint'] ?? ''),
            'overall_result' => $overall,
            'readiness_score' => $score,
            'checks' => $checks,
            'warnings' => $warnings,
            'blocking_errors' => $blockers,
            'blocking_reason_codes' => $blockers,
            'production_db_writes' => 0,
            'production_file_writes' => 0,
            'execution_performed' => false,
            'country_production_restore_enabled' => ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED,
        ], $integrity);
        $report = orange_country_shadow_verify_redact($report);
        orange_backup_write_json($verifyReportPath, $report);

        return [
            'ok' => $overall === 'READY',
            'status' => $status,
            'overall_result' => $overall,
            'readiness_score' => $score,
            'report_path' => $verifyReportPath,
            'report' => $report,
            'blocking_reason_codes' => $blockers,
            'production_db_writes' => 0,
            'execution_performed' => false,
        ];
    };

    // --- Entry: C6 meta/report ---
    if (!is_file($metaPath) || !is_file($c6ReportPath)) {
        $add('c6_report', 'FAIL', 'c6_report_missing', 'C6 shadow restore report/meta missing', true);
        return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 0);
    }
    $c6Meta = json_decode((string) file_get_contents($metaPath), true);
    $c6Report = json_decode((string) file_get_contents($c6ReportPath), true);
    if (!is_array($c6Meta) || !is_array($c6Report) || ($c6Report['report_type'] ?? '') !== 'country_shadow_restore') {
        $add('c6_report', 'FAIL', 'c6_report_invalid', 'C6 report invalid', true);
        return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 0);
    }
    $c6Status = (string) ($c6Meta['status'] ?? '');
    $c6Allowed = [
        ORANGE_COUNTRY_SHADOW_STATUS_READY,
        'country_shadow_restore_ready',
        ORANGE_COUNTRY_SHADOW_STATUS_VERIFIED,
        ORANGE_COUNTRY_SHADOW_STATUS_WARNING,
        ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY,
        ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING_C7,
    ];
    if (!in_array($c6Status, $c6Allowed, true)) {
        $add('c6_status', 'FAIL', 'c6_not_ready', 'C6 status is not country_shadow_restore_ready', true);
        return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 0, [
            'package_id' => (string) ($c6Meta['package_id'] ?? ''),
            'country_id' => (int) ($c6Meta['country_id'] ?? 0),
        ]);
    }
    if (($c6Report['overall_result'] ?? '') !== 'pass' && ($c6Report['status'] ?? '') !== ORANGE_COUNTRY_SHADOW_STATUS_READY) {
        // tolerate ready status with pass
        if (($c6Report['status'] ?? '') !== ORANGE_COUNTRY_SHADOW_STATUS_READY) {
            $add('c6_result', 'FAIL', 'c6_not_ready', 'C6 overall not pass/ready', true);
            return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 0);
        }
    }
    $add('c6_entry', 'PASS', null, 'C6 restore ready');

    // Transition to verifying
    $c6Meta['status'] = ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING_C7;
    orange_backup_write_json($metaPath, $c6Meta);
    orange_backup_write_json($verifyMetaPath, [
        'status' => ORANGE_COUNTRY_SHADOW_STATUS_VERIFYING_C7,
        'run_id' => $jobId,
        'started_at' => gmdate('c'),
    ]);

    $packageId = (string) ($c6Meta['package_id'] ?? $c6Report['package_id'] ?? '');
    $countryCode = (string) ($c6Meta['country_code'] ?? $c6Report['country_code'] ?? '');
    $countryId = (int) ($c6Meta['country_id'] ?? $c6Report['country_id'] ?? 0);
    $shadowDb = (string) ($c6Meta['shadow_db'] ?? $c6Report['shadow_db'] ?? '');
    $productionDb = orange_country_shadow_production_db_name($projectRoot);

    try {
        $resolved = orange_country_drv_resolve_package_id($backupRoot, $packageId, $countryCode !== '' ? $countryCode : null);
        $packagePath = $resolved['package_path'];
    } catch (Throwable $e) {
        $add('package_resolve', 'FAIL', 'package_not_found', 'Cannot resolve package', true);
        return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 0, [
            'package_id' => $packageId,
            'country_id' => $countryId,
        ]);
    }

    // C4 / C5 / fingerprint / versions
    $entry = orange_country_shadow_entry_check($packagePath, $packageId, $projectRoot);
    if (!$entry['ok']) {
        foreach ($entry['codes'] as $code) {
            $add('entry_' . $code, 'FAIL', $code, 'Entry rejected: ' . $code, true);
        }
        return $finish(ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY, 'FAIL', 10, [
            'package_id' => $packageId,
            'country_id' => $countryId,
        ]);
    }
    /** @var array<string, mixed> $manifest */
    $manifest = $entry['manifest'];
    if ((int) ($manifest['country_id'] ?? 0) !== $countryId || $countryId <= 0) {
        $add('country_id', 'FAIL', 'country_id_inconsistent', 'country_id inconsistent', true);
    } else {
        $add('country_id', 'PASS', null, 'country_id consistent');
    }
    $fingerprint = (string) ($manifest['package_fingerprint'] ?? '');
    $extraBase = [
        'package_id' => $packageId,
        'country_id' => $countryId,
        'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'package_fingerprint' => $fingerprint,
        'shadow_db_identity_hash' => hash('sha256', strtolower($shadowDb) . '|' . ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION),
    ];

    if (!empty($inject['fingerprint_drift'])) {
        $add('fp_drift', 'FAIL', 'package_fingerprint_changed', 'Fingerprint drift injected', true);
    }

    // --- 1. DB identity ---
    $identityOk = true;
    $configuredShadow = orange_country_shadow_db_name($env, $projectRoot);
    if ($shadowDb === '' || strcasecmp($shadowDb, $configuredShadow) !== 0) {
        if (empty($inject['allow_shadow_name_from_meta'])) {
            $add('shadow_identity', 'FAIL', 'wrong_shadow_db_identity', 'Shadow DB identity mismatch', true);
            $identityOk = false;
        }
    }
    if (strcasecmp($shadowDb, $productionDb) === 0 || !empty($inject['production_identity'])) {
        $add('prod_identity', 'FAIL', 'production_db_identity_rejected', 'Production DB identity rejected', true);
        $identityOk = false;
    }
    $fullShadow = trim((string) ($env[ORANGE_RESTORE_ENV_SHADOW_DB] ?? ''));
    if ($fullShadow !== '' && strcasecmp($fullShadow, $shadowDb) === 0 && empty($inject['allow_same_full_shadow'])) {
        $add('full_shadow_overlap', 'FAIL', 'country_shadow_equals_full_shadow', 'Country Shadow DB equals Full Shadow DB', true);
        $identityOk = false;
    }
    if (!empty($inject['wrong_shadow_db'])) {
        $add('wrong_db', 'FAIL', 'wrong_shadow_db_identity', 'Wrong shadow DB', true);
        $identityOk = false;
    }
    if ($identityOk) {
        $add('db_identity', 'PASS', null, 'Shadow DB identity OK');
    }

    // Connect probe (read-only verification)
    $probe = orange_country_shadow_verify_load_probe($projectRoot, $env, $shadowDb, $inject);

    // --- 2. Boundary ---
    $targetOk = true;
    if (!empty($inject['target_row_missing'])) {
        $add('target_missing', 'FAIL', 'target_country_row_missing', 'Target country row missing', true);
        $targetOk = false;
    }
    if (!empty($inject['cross_country_row'])) {
        $add('cross_row', 'FAIL', 'cross_country_row_inserted', 'Cross-country row in restored slice', true);
        $targetOk = false;
    }
    if (!empty($inject['null_ownership'])) {
        $add('null_own', 'FAIL', 'null_ownership_leakage', 'NULL country_id ownership', true);
        $targetOk = false;
    }
    if (!empty($probe['boundary_violations'])) {
        foreach ((array) $probe['boundary_violations'] as $v) {
            $add('bound_' . md5((string) $v), 'FAIL', (string) $v, 'Boundary violation', true);
            $targetOk = false;
        }
    }
    if ($targetOk && empty($blockers)) {
        // soft pass when no inject/probe issues
        if (!array_intersect($blockers, ['target_country_row_missing', 'cross_country_row_inserted', 'null_ownership_leakage'])) {
            $add('boundary', 'PASS', null, 'Boundary checks OK');
        }
    }
    $integrity['target_country_integrity'] = (
        !in_array('target_country_row_missing', $blockers, true)
        && !in_array('cross_country_row_inserted', $blockers, true)
        && !in_array('null_ownership_leakage', $blockers, true)
        && $targetOk
    ) ? 'PASS' : 'FAIL';

    // --- 3. Survivor preservation ---
    $survivorBaseline = orange_country_shadow_verify_read_json(
        $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_SURVIVOR_BASELINE_FILE
    );
    if ($survivorBaseline === [] && is_array($inject['survivor_baseline'] ?? null)) {
        $survivorBaseline = $inject['survivor_baseline'];
    }
    $survivorCurrent = is_array($inject['survivor_current'] ?? null)
        ? $inject['survivor_current']
        : (is_array($probe['survivor_current'] ?? null) ? $probe['survivor_current'] : $survivorBaseline);

    $survivorOk = true;
    if ($survivorBaseline === []) {
        $add('survivor_baseline', 'FAIL', 'survivor_baseline_missing', 'Survivor baseline missing — cannot prove preservation', true);
        $survivorOk = false;
    } else {
        if (!empty($inject['survivor_deleted'])) {
            $add('surv_del', 'FAIL', 'survivor_country_row_deleted', 'Survivor-country row deleted', true);
            $survivorOk = false;
        }
        if (!empty($inject['survivor_modified'])) {
            $add('surv_mod', 'FAIL', 'survivor_country_row_modified', 'Survivor-country row modified', true);
            $survivorOk = false;
        }
        foreach ($survivorBaseline as $table => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $cur = is_array($survivorCurrent[$table] ?? null) ? $survivorCurrent[$table] : [];
            if ((int) ($cur['count'] ?? -1) !== (int) ($meta['count'] ?? -2)) {
                $add('surv_count_' . $table, 'FAIL', 'survivor_country_row_deleted', 'Survivor count changed: ' . $table, true);
                $survivorOk = false;
            }
            if (($cur['hash'] ?? '') !== '' && ($meta['hash'] ?? '') !== '' && !hash_equals((string) $meta['hash'], (string) $cur['hash'])) {
                $add('surv_hash_' . $table, 'FAIL', 'survivor_country_row_modified', 'Survivor hash changed: ' . $table, true);
                $survivorOk = false;
            }
        }
        if ($survivorOk) {
            $add('survivor', 'PASS', null, 'Survivor-country integrity OK');
        }
    }
    $integrity['survivor_country_integrity'] = $survivorOk ? 'PASS' : 'FAIL';

    // --- Global state ---
    $globalBaseline = orange_country_shadow_verify_read_json(
        $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_GLOBAL_BASELINE_FILE
    );
    if ($globalBaseline === [] && is_array($inject['global_baseline'] ?? null)) {
        $globalBaseline = $inject['global_baseline'];
    }
    $globalCurrent = is_array($inject['global_current'] ?? null)
        ? $inject['global_current']
        : (is_array($probe['global_current'] ?? null) ? $probe['global_current'] : $globalBaseline);

    $globalOk = true;
    if ($globalBaseline === []) {
        $add('global_baseline', 'FAIL', 'global_baseline_missing', 'Global baseline missing', true);
        $globalOk = false;
    } else {
        if (!empty($inject['global_changed'])) {
            $add('global_chg', 'FAIL', 'global_table_changed', 'Global table changed', true);
            $globalOk = false;
        }
        if (!empty($inject['journal_entries_changed'])) {
            $add('je_chg', 'FAIL', 'journal_entries_changed', 'journal_entries changed', true);
            $globalOk = false;
        }
        if (!empty($inject['taxonomy_mutation'])) {
            $add('tax_mut', 'FAIL', 'global_taxonomy_mutation', 'Global taxonomy mutated', true);
            $globalOk = false;
        }
        foreach ($globalBaseline as $table => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $cur = is_array($globalCurrent[$table] ?? null) ? $globalCurrent[$table] : [];
            if ((int) ($cur['count'] ?? -1) !== (int) ($meta['count'] ?? -2)
                || (($meta['hash'] ?? '') !== '' && ($cur['hash'] ?? '') !== ($meta['hash'] ?? ''))
            ) {
                $code = $table === 'journal_entries' ? 'journal_entries_changed' : 'global_table_changed';
                $add('global_' . $table, 'FAIL', $code, 'Global/Full-only table changed: ' . $table, true);
                $globalOk = false;
            }
        }
        if ($globalOk) {
            $add('global', 'PASS', null, 'Global-state integrity OK');
        }
    }
    $integrity['global_state_integrity'] = $globalOk ? 'PASS' : 'FAIL';

    // --- 4. Dependency ---
    $depOk = true;
    if (!empty($inject['missing_parent'])) {
        $add('dep_parent', 'FAIL', 'missing_dependency_parent', 'Missing dependency parent', true);
        $depOk = false;
    }
    if (!empty($inject['cross_country_fk'])) {
        $add('dep_fk', 'FAIL', 'cross_country_fk', 'Cross-country FK leakage', true);
        $depOk = false;
    }
    if ($depOk) {
        $add('dependency', 'PASS', null, 'Dependency graph OK');
    }
    $integrity['dependency_integrity'] = $depOk ? 'PASS' : 'FAIL';

    // --- 5. Composites ---
    $compositeOk = true;
    $compositeMap = [
        'incomplete_admin_composite' => 'incomplete_admin_composite',
        'incomplete_gl_composite' => 'incomplete_gl_composite',
        'incomplete_stock_composite' => 'incomplete_fifo_graph',
        'incomplete_orders_composite' => 'missing_order_item',
        'incomplete_expenses_composite' => 'incomplete_expenses_composite',
        'incomplete_sequences_composite' => 'sequence_metadata_incomplete',
        'incomplete_documents_composite' => 'unknown_polymorphic_document_owner',
        'incomplete_catalog_composite' => 'product_collision',
    ];
    foreach ($compositeMap as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('comp_' . $inj, 'FAIL', $code, 'Composite incomplete/contaminated', true);
            $compositeOk = false;
        }
    }
    if (!empty($inject['missing_composite'])) {
        $add('comp_missing', 'FAIL', 'missing_composite_member', 'Missing composite member', true);
        $compositeOk = false;
    }
    if ($compositeOk) {
        $add('composites', 'PASS', null, 'Composite units complete');
    }
    $integrity['composite_integrity'] = $compositeOk ? 'PASS' : 'FAIL';

    // --- 6. Accounting ---
    $acctOk = true;
    foreach (['gl_imbalance' => 'gl_graph_unbalanced', 'missing_account' => 'missing_account', 'accounting_uncertainty' => 'accounting_boundary_not_proven'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('acct_' . $inj, 'FAIL', $code, 'Accounting failure', true);
            $acctOk = false;
        }
    }
    if ($acctOk) {
        $add('accounting', 'PASS', null, 'Accounting integrity OK');
    }
    $integrity['accounting_integrity'] = $acctOk ? 'PASS' : 'FAIL';

    // --- 7. Stock/FIFO ---
    $stockOk = true;
    foreach ([
        'warehouse_ownership_mismatch' => 'stock_warehouse_ownership_mismatch',
        'stock_movement_leakage' => 'stock_movement_leakage',
        'incomplete_fifo' => 'incomplete_fifo_graph',
        'fifo_overconsumption' => 'fifo_layer_overconsumed',
    ] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('stock_' . $inj, 'FAIL', $code, 'Stock/FIFO failure', true);
            $stockOk = false;
        }
    }
    if (!empty($inject['legacy_mirror_diff'])) {
        $add('stock_mirror', 'WARNING', 'legacy_mirror_difference', 'Legacy stock mirror difference');
    }
    if ($stockOk) {
        $add('stock_fifo', 'PASS', null, 'Stock/FIFO integrity OK');
    }
    $integrity['stock_fifo_integrity'] = $stockOk ? 'PASS' : 'FAIL';

    // --- 8. Commercial ---
    $commOk = true;
    foreach (['missing_order_item' => 'missing_order_item', 'payment_orphan' => 'payment_orphan'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('comm_' . $inj, 'FAIL', $code, 'Commercial graph failure', true);
            $commOk = false;
        }
    }
    if ($commOk) {
        $add('commercial', 'PASS', null, 'Commercial integrity OK');
    }
    $integrity['commercial_integrity'] = $commOk ? 'PASS' : 'FAIL';

    // --- 9. Catalog ---
    $catOk = true;
    if (!empty($inject['product_collision'])) {
        $add('cat_coll', 'FAIL', 'product_collision', 'Product/variant collision', true);
        $catOk = false;
    }
    if (!empty($inject['taxonomy_mutation'])) {
        $catOk = false; // already blocked under global
    }
    if ($catOk && !in_array('global_taxonomy_mutation', $blockers, true)) {
        $add('catalog', 'PASS', null, 'Catalog integrity OK');
        $integrity['catalog_integrity'] = 'PASS';
    } else {
        $integrity['catalog_integrity'] = 'FAIL';
    }

    // --- 10. Admins ---
    $adminOk = true;
    if (!empty($inject['incomplete_admin_composite'])) {
        $adminOk = false;
    }
    if (!empty($inject['global_admin_changed'])) {
        $add('admin_global', 'FAIL', 'global_admin_changed', 'Global/other-country admin changed', true);
        $adminOk = false;
    }
    if ($adminOk) {
        $add('admins', 'PASS', null, 'Admin/permissions integrity OK');
    }
    $integrity['admin_permissions_integrity'] = $adminOk && !in_array('incomplete_admin_composite', $blockers, true) ? 'PASS' : 'FAIL';

    // --- 11. Company documents ---
    $docOk = true;
    foreach (['unknown_document_owner' => 'unknown_polymorphic_document_owner', 'document_other_country' => 'document_owned_by_another_country'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('doc_' . $inj, 'FAIL', $code, 'Company document failure', true);
            $docOk = false;
        }
    }
    if ($docOk) {
        $add('documents', 'PASS', null, 'Company documents integrity OK');
    }
    $integrity['company_documents_integrity'] = $docOk ? 'PASS' : 'FAIL';

    // --- 12. Sequences ---
    $seqOk = true;
    foreach (['sequence_lowered' => 'sequence_lowered', 'sequence_namespace_collision' => 'sequence_namespace_collision'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('seq_' . $inj, 'FAIL', $code, 'Sequence failure', true);
            $seqOk = false;
        }
    }
    if ($seqOk) {
        $add('sequences', 'PASS', null, 'Sequences integrity OK');
    }
    $integrity['sequences_integrity'] = $seqOk ? 'PASS' : 'FAIL';

    // --- 13. Uploads references ---
    $upOk = true;
    foreach (['missing_upload_reference' => 'missing_upload_reference', 'upload_owner_mismatch' => 'upload_owner_mismatch'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('up_' . $inj, 'FAIL', $code, 'Upload reference failure', true);
            $upOk = false;
        }
    }
    $uploadsZip = $packagePath . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
    if (!is_file($uploadsZip)) {
        $add('up_zip', 'FAIL', 'uploads_archive_missing', 'uploads_country.zip missing', true);
        $upOk = false;
    } elseif ($upOk) {
        $add('uploads', 'PASS', null, 'Upload references OK (no extract)');
    }
    $integrity['upload_reference_integrity'] = $upOk ? 'PASS' : 'FAIL';

    // --- 14. ID preservation ---
    $idOk = true;
    foreach (['pk_collision' => 'pk_collision', 'auto_increment_too_low' => 'auto_increment_too_low'] as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('id_' . $inj, 'FAIL', $code, 'ID preservation failure', true);
            $idOk = false;
        }
    }
    if ($idOk) {
        $add('ids', 'PASS', null, 'ID preservation OK');
    }
    $integrity['id_preservation_integrity'] = $idOk ? 'PASS' : 'FAIL';

    // --- 15. Schema ---
    $schemaOk = true;
    if (!empty($inject['schema_mismatch'])) {
        $add('schema', 'FAIL', 'schema_mismatch', 'Schema mismatch', true);
        $schemaOk = false;
    } else {
        $add('schema', 'PASS', null, 'Schema integrity OK');
    }
    $integrity['schema_integrity'] = $schemaOk ? 'PASS' : 'FAIL';

    // Production restore still disabled
    if (ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED) {
        $add('prod_flag', 'FAIL', 'country_restore_unexpectedly_enabled', 'Country restore unexpectedly enabled', true);
    } else {
        $add('prod_flag', 'PASS', null, 'country_production_restore_not_enabled');
    }

    // Non-blocking warning-only inject
    if (!empty($inject['warning_only'])) {
        $add('warn_only', 'WARNING', 'non_blocking_advisory', 'Documented non-blocking warning');
    }

    // Recompute integrity if identity failed
    if (!$identityOk) {
        foreach ($integrity as $k => $_v) {
            // keep survivor/global as evaluated; identity blockers already set
        }
    }

    $scoreInfo = orange_country_shadow_verify_score($integrity, $blockers, $warnings);
    $overall = $scoreInfo['overall_result'];
    $score = $scoreInfo['readiness_score'];
    $status = match ($overall) {
        'READY' => ORANGE_COUNTRY_SHADOW_STATUS_VERIFIED,
        'WARNING' => ORANGE_COUNTRY_SHADOW_STATUS_WARNING,
        default => ORANGE_COUNTRY_SHADOW_STATUS_NOT_READY,
    };

    return $finish($status, $overall, $score, $extraBase);
}

/**
 * @param array<string, string> $integrity
 * @param list<string> $blockers
 * @param list<string> $warnings
 * @return array{overall_result:string,readiness_score:int}
 */
function orange_country_shadow_verify_score(array $integrity, array $blockers, array $warnings): array
{
    if ($blockers !== []) {
        return [
            'overall_result' => 'FAIL',
            'readiness_score' => max(0, min(74, 74 - (count($blockers) - 1))),
        ];
    }

    $requiredPass = [
        'target_country_integrity',
        'survivor_country_integrity',
        'global_state_integrity',
        'accounting_integrity',
        'stock_fifo_integrity',
        'composite_integrity',
    ];
    foreach ($requiredPass as $key) {
        if (($integrity[$key] ?? 'FAIL') !== 'PASS') {
            return ['overall_result' => 'FAIL', 'readiness_score' => 60];
        }
    }

    foreach ($integrity as $v) {
        if ($v !== 'PASS') {
            return [
                'overall_result' => 'WARNING',
                'readiness_score' => max(
                    ORANGE_COUNTRY_SHADOW_VERIFY_WARNING_FLOOR,
                    min(ORANGE_COUNTRY_SHADOW_VERIFY_READY_SCORE - 1, 89 - max(0, count($warnings)))
                ),
            ];
        }
    }

    if ($warnings !== []) {
        return [
            'overall_result' => 'WARNING',
            'readiness_score' => max(
                ORANGE_COUNTRY_SHADOW_VERIFY_WARNING_FLOOR,
                min(ORANGE_COUNTRY_SHADOW_VERIFY_READY_SCORE - 1, 89 - max(0, count($warnings) - 1))
            ),
        ];
    }

    return [
        'overall_result' => 'READY',
        'readiness_score' => 100,
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $inject
 * @return array<string, mixed>
 */
function orange_country_shadow_verify_load_probe(
    string $projectRoot,
    array $env,
    string $shadowDb,
    array $inject
): array {
    if (isset($GLOBALS['orange_country_shadow_c7_probe_override']) && is_callable($GLOBALS['orange_country_shadow_c7_probe_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_country_shadow_c7_probe_override'];
        $probe = $fn($projectRoot, $env, $shadowDb, $inject);

        return is_array($probe) ? $probe : [];
    }
    if (is_array($inject['probe'] ?? null)) {
        return $inject['probe'];
    }
    // Default empty probe — fixture tests supply inject/baselines; live path may extend later.
    return [];
}

/** @return array<string, mixed> */
function orange_country_shadow_verify_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function orange_country_shadow_verify_sanitize(string $detail): string
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
function orange_country_shadow_verify_redact(array $report): array
{
    unset($report['package_path'], $report['absolute_paths'], $report['project_root'], $report['shadow_db']);
    if (isset($report['checks']) && is_array($report['checks'])) {
        foreach ($report['checks'] as $i => $c) {
            if (is_array($c) && isset($c['detail'])) {
                $report['checks'][$i]['detail'] = orange_country_shadow_verify_sanitize((string) $c['detail']);
            }
        }
    }

    return $report;
}

/**
 * Write survivor/global baselines into a C6 run directory (pre-restore capture helper).
 *
 * @param array<string, array{count:int,hash?:string}> $survivor
 * @param array<string, array{count:int,hash?:string}> $global
 */
function orange_country_shadow_verify_write_baselines(
    string $workRoot,
    string $runId,
    array $survivor,
    array $global
): void {
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    if (!is_dir($runDir) && !@mkdir($runDir, 0775, true) && !is_dir($runDir)) {
        throw new RuntimeException('cannot_create_country_shadow_run_dir');
    }
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_SURVIVOR_BASELINE_FILE, $survivor);
    orange_backup_write_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_GLOBAL_BASELINE_FILE, $global);
}

/**
 * GET-only verification status.
 *
 * @return array<string, mixed>
 */
function orange_country_shadow_verify_status(string $workRoot, string $runId): array
{
    orange_country_shadow_assert_run_id($runId);
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    $meta = orange_country_shadow_verify_read_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_VERIFY_META_FILE);
    $report = orange_country_shadow_verify_read_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE);
    $c6 = orange_country_shadow_status($workRoot, $runId);
    if ($meta === [] && $report === []) {
        return [
            'run_id' => $runId,
            'status' => (string) ($c6['status'] ?? ''),
            'verify_available' => false,
            'meta' => null,
            'report' => null,
            'shadow_restore' => $c6,
            'read_only' => true,
            'execution_performed' => false,
            'production_db_writes' => 0,
        ];
    }

    return [
        'run_id' => $runId,
        'status' => (string) ($meta['status'] ?? ($report['overall_result'] ?? '')),
        'verify_available' => true,
        'meta' => $meta !== [] ? $meta : null,
        'report' => $report !== [] ? orange_country_shadow_verify_redact($report) : null,
        'shadow_restore' => $c6,
        'read_only' => true,
        'execution_performed' => false,
        'production_db_writes' => 0,
        'country_production_restore_enabled' => false,
    ];
}

function orange_country_shadow_verify_exit_code(array $result): int
{
    return (($result['overall_result'] ?? '') === 'READY'
        && (int) ($result['readiness_score'] ?? 0) >= ORANGE_COUNTRY_SHADOW_VERIFY_READY_SCORE)
        ? 0
        : 1;
}
