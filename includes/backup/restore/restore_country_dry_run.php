<?php

declare(strict_types=1);

/**
 * Phase C8 — Country Dry Run & Impact Simulation.
 *
 * Simulates a complete Country Restore without modifying production or shadow data.
 * No imports, deletes, or execution. Consumes C7 Shadow Verification READY result.
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../country_boundary_matrix_lib.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/../country_crp_verify.php';
require_once __DIR__ . '/../country_crp_drv.php';
require_once __DIR__ . '/restore_country_shadow.php';
require_once __DIR__ . '/restore_country_shadow_verify.php';
require_once __DIR__ . '/restore_paths.php';

const ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION = '1.2';
const ORANGE_COUNTRY_DRY_RUN_REPORT_FILE = 'country_dry_run_report.json';
const ORANGE_COUNTRY_DRY_RUN_META_FILE = 'country_dry_run.json';

const ORANGE_COUNTRY_DRY_RUN_STATUS_RUNNING = 'country_dry_run_running';
const ORANGE_COUNTRY_DRY_RUN_STATUS_SAFE = 'country_dry_run_safe';
const ORANGE_COUNTRY_DRY_RUN_STATUS_WARNING = 'country_dry_run_warning';
const ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED = 'country_dry_run_failed';

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
function orange_country_dry_run_execute(array $options): array
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
    $metaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_DRY_RUN_META_FILE;
    $reportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_DRY_RUN_REPORT_FILE;
    $c7ReportPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE;
    $c6MetaPath = $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_META_FILE;

    $checks = [];
    $warnings = [];
    $blockers = [];

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
            'detail' => orange_country_dry_run_sanitize($detail),
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
        array $impact,
        array $extra = []
    ) use (
        &$checks,
        &$warnings,
        &$blockers,
        $metaPath,
        $reportPath,
        $jobId,
        $c6MetaPath
    ): array {
        $warnings = array_values(array_unique($warnings));
        $blockers = array_values(array_unique($blockers));

        $meta = [
            'engine_version' => ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION,
            'run_id' => $jobId,
            'status' => $status,
            'overall_result' => $overall,
            'updated_at' => gmdate('c'),
            'production_db_writes' => 0,
            'production_file_writes' => 0,
            'shadow_db_writes' => 0,
            'execution_performed' => false,
            'country_production_restore_enabled' => false,
        ];
        orange_backup_write_json($metaPath, $meta);

        if (is_file($c6MetaPath)) {
            $c6Meta = json_decode((string) file_get_contents($c6MetaPath), true);
            if (is_array($c6Meta)) {
                $c6Meta['dry_run_status'] = $status;
                $c6Meta['dry_run_overall_result'] = $overall;
                orange_backup_write_json($c6MetaPath, $c6Meta);
            }
        }

        $report = array_merge([
            'report_version' => ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION,
            'report_type' => 'country_dry_run',
            'simulated_at' => gmdate('c'),
            'package_id' => (string) ($extra['package_id'] ?? ''),
            'country_id' => (int) ($extra['country_id'] ?? 0),
            'schema_revision' => (int) ($extra['schema_revision'] ?? 0),
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'package_fingerprint' => (string) ($extra['package_fingerprint'] ?? ''),
            'c7_readiness_score' => (int) ($extra['c7_readiness_score'] ?? 0),
            'overall_result' => $overall,
            'checks' => $checks,
            'warnings' => $warnings,
            'blocking_errors' => $blockers,
            'blocking_reason_codes' => $blockers,
            'production_db_writes' => 0,
            'production_file_writes' => 0,
            'shadow_db_writes' => 0,
            'execution_performed' => false,
            'country_production_restore_enabled' => ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED,
            'simulation_only' => true,
        ], $impact);
        $report = orange_country_dry_run_redact($report);
        orange_backup_write_json($reportPath, $report);

        return [
            'ok' => $overall === 'SAFE',
            'status' => $status,
            'overall_result' => $overall,
            'report_path' => $reportPath,
            'report' => $report,
            'blocking_reason_codes' => $blockers,
            'production_db_writes' => 0,
            'shadow_db_writes' => 0,
            'execution_performed' => false,
        ];
    };

    $emptyImpact = [
        'tables_affected' => [],
        'tables_affected_count' => 0,
        'rows_to_replace' => 0,
        'rows_to_delete' => 0,
        'rows_to_insert' => 0,
        'uploads_to_replace' => 0,
        'uploads_to_add' => 0,
        'composite_units' => [],
        'special_handlers' => [],
        'estimated_duration_seconds' => 0,
        'estimated_duration' => '0s',
        'survivor_country_impact' => 0,
        'global_impact' => 0,
        'journal_entries_impact' => 0,
        'full_only_impact' => 0,
    ];

    // --- Entry: C7 READY ---
    if (!is_dir($runDir) || !is_file($c7ReportPath)) {
        $add('c7_report', 'FAIL', 'c7_report_missing', 'C7 shadow verification report missing', true);
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact);
    }
    $c7 = json_decode((string) file_get_contents($c7ReportPath), true);
    if (!is_array($c7) || ($c7['report_type'] ?? '') !== 'country_shadow_verification') {
        $add('c7_report', 'FAIL', 'c7_report_invalid', 'C7 report invalid', true);
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact);
    }

    orange_backup_write_json($metaPath, [
        'status' => ORANGE_COUNTRY_DRY_RUN_STATUS_RUNNING,
        'run_id' => $jobId,
        'started_at' => gmdate('c'),
        'execution_performed' => false,
        'production_db_writes' => 0,
        'shadow_db_writes' => 0,
    ]);

    if (($c7['overall_result'] ?? '') !== 'READY') {
        $add('c7_ready', 'FAIL', 'c7_not_ready', 'C7 overall is not READY', true);
    }
    if ((int) ($c7['readiness_score'] ?? 0) < ORANGE_COUNTRY_SHADOW_VERIFY_READY_SCORE) {
        $add('c7_score', 'FAIL', 'c7_score_below_threshold', 'C7 readiness_score < 90', true);
    }
    if (($c7['survivor_country_integrity'] ?? '') !== 'PASS') {
        $add('c7_survivor', 'FAIL', 'c7_survivor_not_pass', 'survivor_country_integrity not PASS', true);
    }
    if (($c7['global_state_integrity'] ?? '') !== 'PASS') {
        $add('c7_global', 'FAIL', 'c7_global_not_pass', 'global_state_integrity not PASS', true);
    }
    if (($c7['execution_performed'] ?? true) !== false) {
        $add('c7_exec', 'FAIL', 'execution_already_performed', 'execution_performed must remain false', true);
    }
    if ($blockers !== []) {
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact, [
            'package_id' => (string) ($c7['package_id'] ?? ''),
            'country_id' => (int) ($c7['country_id'] ?? 0),
            'c7_readiness_score' => (int) ($c7['readiness_score'] ?? 0),
            'package_fingerprint' => (string) ($c7['package_fingerprint'] ?? ''),
            'schema_revision' => (int) ($c7['schema_revision'] ?? 0),
        ]);
    }
    $add('c7_entry', 'PASS', null, 'C7 READY entry satisfied');

    $packageId = (string) ($c7['package_id'] ?? '');
    $countryId = (int) ($c7['country_id'] ?? 0);
    $c6Meta = is_file($c6MetaPath)
        ? (json_decode((string) file_get_contents($c6MetaPath), true) ?: [])
        : [];
    $countryCode = (string) ($c6Meta['country_code'] ?? '');

    try {
        $resolved = orange_country_drv_resolve_package_id(
            $backupRoot,
            $packageId,
            $countryCode !== '' ? $countryCode : null
        );
        $packagePath = $resolved['package_path'];
    } catch (Throwable $e) {
        $add('package', 'FAIL', 'package_not_found', 'Cannot resolve package', true);
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact, [
            'package_id' => $packageId,
            'country_id' => $countryId,
        ]);
    }

    $entry = orange_country_shadow_entry_check($packagePath, $packageId, $projectRoot);
    if (!$entry['ok']) {
        foreach ($entry['codes'] as $code) {
            $mapped = $code === 'fingerprint_changed' ? 'package_fingerprint_changed' : $code;
            $add('entry_' . $mapped, 'FAIL', $mapped, 'Entry rejected: ' . $mapped, true);
        }
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact, [
            'package_id' => $packageId,
            'country_id' => $countryId,
        ]);
    }
    /** @var array<string, mixed> $manifest */
    $manifest = $entry['manifest'];
    $fingerprint = (string) ($manifest['package_fingerprint'] ?? '');
    if ($fingerprint !== '' && ($c7['package_fingerprint'] ?? '') !== ''
        && !hash_equals((string) $c7['package_fingerprint'], $fingerprint)
    ) {
        $add('fp', 'FAIL', 'package_fingerprint_changed', 'Package fingerprint drifted vs C7', true);
    }
    if (($manifest['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION
        || ($c7['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION
    ) {
        $add('boundary_ver', 'FAIL', 'boundary_policy_version_changed', 'Boundary policy version mismatch', true);
    }
    if (($manifest['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION
        || ($c7['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION
    ) {
        $add('dep_ver', 'FAIL', 'dependency_graph_version_changed', 'Dependency graph version mismatch', true);
    }
    if (!empty($inject['fingerprint_drift'])) {
        $add('fp_inj', 'FAIL', 'package_fingerprint_changed', 'Fingerprint drift injected', true);
    }
    if ($blockers !== []) {
        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact, [
            'package_id' => $packageId,
            'country_id' => $countryId,
            'package_fingerprint' => $fingerprint,
            'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
            'c7_readiness_score' => (int) ($c7['readiness_score'] ?? 0),
        ]);
    }
    $add('versions', 'PASS', null, 'Fingerprint and policy versions unchanged');

    // --- Impact simulation (F-04: certified production inventory; no writes) ---
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    $inventory = orange_country_dry_run_read_json($packagePath . DIRECTORY_SEPARATOR . 'table_inventory.json');
    $idSnapshot = orange_country_dry_run_read_json($packagePath . DIRECTORY_SEPARATOR . 'id_snapshot.json');
    $c6Report = orange_country_dry_run_read_json(
        $runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_SHADOW_REPORT_FILE
    );

    $prodInv = orange_country_dry_run_load_production_inventory(
        $projectRoot,
        $workRoot,
        $jobId,
        $countryId,
        $env,
        $inject
    );
    if (!$prodInv['ok']) {
        $add(
            'prod_inv',
            'FAIL',
            (string) ($prodInv['code'] ?? 'production_inventory_snapshot_missing'),
            'Certified read-only production inventory required for dry-run impact',
            true
        );

        return $finish(ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED, 'FAIL', $emptyImpact, [
            'package_id' => $packageId,
            'country_id' => $countryId,
            'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
            'package_fingerprint' => $fingerprint,
            'c7_readiness_score' => (int) ($c7['readiness_score'] ?? 0),
        ]);
    }
    $add(
        'prod_inv',
        'PASS',
        null,
        'Production inventory source: ' . (string) ($prodInv['source'] ?? 'unknown')
    );

    $impact = orange_country_dry_run_compute_impact(
        $matrix,
        $inventory,
        $idSnapshot,
        $c6Report,
        $c7,
        $packagePath,
        $countryId,
        $inject,
        $prodInv
    );

    // Predicted contamination / safety failures
    $failMap = [
        'global_mutation' => 'global_mutation_predicted',
        'survivor_mutation' => 'survivor_country_mutation_predicted',
        'journal_entries_mutation' => 'journal_entries_mutation_predicted',
        'full_only_mutation' => 'full_only_table_mutation_predicted',
        'unresolved_ownership' => 'unresolved_ownership',
        'sequence_collision' => 'sequence_collision',
        'accounting_violation' => 'accounting_boundary_violation',
        'fifo_violation' => 'stock_fifo_corruption_predicted',
        'composite_failure' => 'unresolved_composite',
        'leakage' => 'cross_country_leakage_predicted',
    ];
    foreach ($failMap as $inj => $code) {
        if (!empty($inject[$inj])) {
            $add('pred_' . $inj, 'FAIL', $code, 'Simulation predicts unsafe impact', true);
            if ($inj === 'global_mutation') {
                $impact['global_impact'] = max(1, (int) $impact['global_impact']);
            }
            if ($inj === 'survivor_mutation') {
                $impact['survivor_country_impact'] = max(1, (int) $impact['survivor_country_impact']);
            }
            if ($inj === 'journal_entries_mutation') {
                $impact['journal_entries_impact'] = max(1, (int) $impact['journal_entries_impact']);
            }
            if ($inj === 'full_only_mutation') {
                $impact['full_only_impact'] = max(1, (int) $impact['full_only_impact']);
            }
        }
    }

    if ((int) $impact['survivor_country_impact'] !== 0) {
        $add('surv_impact', 'FAIL', 'survivor_country_mutation_predicted', 'Survivor-country impact must be zero', true);
    } else {
        $add('surv_impact', 'PASS', null, 'Survivor-country impact = 0');
    }
    if ((int) $impact['global_impact'] !== 0) {
        $add('global_impact', 'FAIL', 'global_mutation_predicted', 'Global impact must be zero', true);
    } else {
        $add('global_impact', 'PASS', null, 'Global impact = 0');
    }
    if ((int) $impact['journal_entries_impact'] !== 0) {
        $add('je_impact', 'FAIL', 'journal_entries_mutation_predicted', 'journal_entries must remain untouched', true);
    }
    if ((int) $impact['full_only_impact'] !== 0) {
        $add('full_impact', 'FAIL', 'full_only_table_mutation_predicted', 'Full-only tables must remain untouched', true);
    }

    if (($c7['accounting_integrity'] ?? '') !== 'PASS' || !empty($inject['accounting_violation'])) {
        if (($c7['accounting_integrity'] ?? '') !== 'PASS') {
            $add('acct', 'FAIL', 'accounting_boundary_violation', 'Accounting integrity not PASS in C7', true);
        }
    } else {
        $add('acct', 'PASS', null, 'Accounting boundary OK for simulation');
    }
    if (($c7['stock_fifo_integrity'] ?? '') !== 'PASS' || !empty($inject['fifo_violation'])) {
        if (($c7['stock_fifo_integrity'] ?? '') !== 'PASS') {
            $add('fifo', 'FAIL', 'stock_fifo_corruption_predicted', 'Stock/FIFO integrity not PASS in C7', true);
        }
    } else {
        $add('fifo', 'PASS', null, 'Stock/FIFO OK for simulation');
    }
    if (($c7['composite_integrity'] ?? '') !== 'PASS' || !empty($inject['composite_failure'])) {
        if (($c7['composite_integrity'] ?? '') !== 'PASS') {
            $add('comp', 'FAIL', 'unresolved_composite', 'Composite integrity not PASS in C7', true);
        }
    } else {
        $add('comp', 'PASS', null, 'Composite units resolved for simulation');
    }

    if (ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED) {
        $add('prod_flag', 'FAIL', 'country_restore_unexpectedly_enabled', 'Country restore unexpectedly enabled', true);
    } else {
        $add('prod_flag', 'PASS', null, 'country_production_restore_not_enabled');
    }

    if (!empty($inject['warning_only'])) {
        $add('warn', 'WARNING', 'non_blocking_advisory', 'Documented non-blocking dry-run warning');
    }

    $add('sim_mode', 'PASS', null, 'Simulation only — no production/shadow writes');

    $scoreInfo = orange_country_dry_run_score($blockers, $warnings, $impact);
    $overall = $scoreInfo['overall_result'];
    $status = match ($overall) {
        'SAFE' => ORANGE_COUNTRY_DRY_RUN_STATUS_SAFE,
        'WARNING' => ORANGE_COUNTRY_DRY_RUN_STATUS_WARNING,
        default => ORANGE_COUNTRY_DRY_RUN_STATUS_FAILED,
    };

    return $finish($status, $overall, $impact, [
        'package_id' => $packageId,
        'country_id' => $countryId,
        'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'package_fingerprint' => $fingerprint,
        'c7_readiness_score' => (int) ($c7['readiness_score'] ?? 0),
    ]);
}

/**
 * @param array<string, mixed> $matrix
 * @param array<string, mixed> $inventory
 * @param array<string, mixed> $idSnapshot
 * @param array<string, mixed> $c6Report
 * @param array<string, mixed> $c7
 * @param array<string, mixed> $inject
 * @param array<string, mixed> $prodInv certified / inject production inventory
 * @return array<string, mixed>
 */
function orange_country_dry_run_compute_impact(
    array $matrix,
    array $inventory,
    array $idSnapshot,
    array $c6Report,
    array $c7,
    string $packagePath,
    int $countryId,
    array $inject,
    array $prodInv = []
): array {
    $tablesMeta = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    $invTables = is_array($inventory['tables'] ?? null) ? $inventory['tables'] : [];
    // F-04: production target counts drive delete/replace impact (not C6 shadow counts alone).
    $prodTarget = is_array($prodInv['target_counts'] ?? null) ? $prodInv['target_counts'] : [];
    if (is_array($inject['target_row_counts'] ?? null)) {
        $prodTarget = $inject['target_row_counts'];
    }
    $c6Counts = is_array($c6Report['row_counts'] ?? null) ? $c6Report['row_counts'] : [];

    $tablesAffected = [];
    $rowsInsert = 0;
    $rowsDelete = 0;
    $rowsReplace = 0;
    $specialHandlers = [];

    foreach ($tablesMeta as $tableName => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        if (!(bool) ($meta['exportable'] ?? false)) {
            continue;
        }
        $mode = (string) ($meta['restore_mode'] ?? '');
        $handler = trim((string) ($meta['special_handler'] ?? ''));
        if (!in_array($mode, ['replace', 'special'], true)) {
            continue;
        }
        // Only count mutate/exportable tables with package presence or known production target rows
        $pkgCount = (int) ($invTables[$tableName] ?? 0);
        $curCount = (int) ($prodTarget[$tableName] ?? ($c6Counts[$tableName] ?? 0));
        $inSnapshot = !empty($idSnapshot['tables'][$tableName]);
        if ($pkgCount <= 0 && $curCount <= 0 && !$inSnapshot) {
            continue;
        }
        $tablesAffected[] = (string) $tableName;
        if ($handler !== '') {
            $specialHandlers[] = [
                'table' => (string) $tableName,
                'handler' => $handler,
                'country_id' => $countryId,
            ];
        }
        $ins = max(0, $pkgCount);
        $del = max(0, $curCount);
        $rep = min($ins, $del);
        $rowsInsert += $ins;
        $rowsDelete += $del;
        $rowsReplace += $rep;
    }

    // Fallback when inventory only has a few tables (fixture packages)
    if ($tablesAffected === [] && $invTables !== []) {
        foreach ($invTables as $t => $cnt) {
            $tablesAffected[] = (string) $t;
            $n = (int) $cnt;
            $cur = (int) ($prodTarget[$t] ?? ($c6Counts[$t] ?? $n));
            $rowsInsert += $n;
            $rowsDelete += $cur;
            $rowsReplace += min($n, $cur);
        }
    }

    // EA-04: prove outside-target impact from inventory + restore plan (never silent defaults).
    $survivorCounts = is_array($prodInv['survivor_counts'] ?? null) ? $prodInv['survivor_counts'] : null;
    $globalCounts = is_array($prodInv['global_counts'] ?? null) ? $prodInv['global_counts'] : null;
    $impactProof = [
        'survivor_proof' => null,
        'global_proof' => null,
        'journal_entries_proof' => null,
        'full_only_proof' => null,
    ];

    if (isset($inject['survivor_country_impact'])) {
        $survivorImpact = (int) $inject['survivor_country_impact'];
        $impactProof['survivor_proof'] = 'inject';
    } elseif ($survivorCounts === null) {
        $survivorImpact = 1; // fail closed — unproven
        $impactProof['survivor_proof'] = 'unproven_missing_survivor_inventory';
    } else {
        // Target-slice restore plan never deletes survivor rows by ownership model.
        $survivorImpact = 0;
        foreach ($tablesAffected as $tName) {
            $class = (string) (($tablesMeta[$tName]['classification'] ?? ''));
            if ($class === 'Global') {
                $survivorImpact = max($survivorImpact, 1);
            }
        }
        $impactProof['survivor_proof'] = 'target_slice_ownership_excludes_survivors';
    }

    if (isset($inject['global_impact'])) {
        $globalImpact = (int) $inject['global_impact'];
        $impactProof['global_proof'] = 'inject';
    } elseif ($globalCounts === null) {
        $globalImpact = 1;
        $impactProof['global_proof'] = 'unproven_missing_global_inventory';
    } else {
        $globalImpact = 0;
        foreach ($tablesAffected as $tName) {
            if (in_array((string) $tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)
                || (($tablesMeta[$tName]['classification'] ?? '') === 'Global')
            ) {
                $globalImpact = max($globalImpact, 1);
            }
        }
        foreach (array_keys($invTables) as $tName) {
            if (in_array((string) $tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
                $globalImpact = max($globalImpact, 1);
            }
        }
        $impactProof['global_proof'] = 'restore_plan_excludes_global_tables';
    }

    if (isset($inject['journal_entries_impact'])) {
        $jeImpact = (int) $inject['journal_entries_impact'];
        $impactProof['journal_entries_proof'] = 'inject';
    } elseif ($globalCounts === null || !array_key_exists('journal_entries', $globalCounts)) {
        $jeImpact = 1;
        $impactProof['journal_entries_proof'] = 'unproven_missing_journal_entries_inventory';
    } else {
        $jeImpact = 0;
        if (isset($invTables['journal_entries']) || in_array('journal_entries', $tablesAffected, true)) {
            $jeImpact = 1;
        }
        $impactProof['journal_entries_proof'] = 'journal_entries_absent_from_restore_plan';
    }

    if (isset($inject['full_only_impact'])) {
        $fullOnlyImpact = (int) $inject['full_only_impact'];
        $impactProof['full_only_proof'] = 'inject';
    } elseif ($globalCounts === null) {
        $fullOnlyImpact = 1;
        $impactProof['full_only_proof'] = 'unproven_missing_global_inventory';
    } else {
        $fullOnlyImpact = 0;
        foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
            if (isset($invTables[$never]) || in_array($never, $tablesAffected, true)) {
                $fullOnlyImpact = 1;
            }
        }
        $impactProof['full_only_proof'] = 'full_only_absent_from_restore_plan';
    }

    $uploadsReplace = 0;
    $uploadsAdd = 0;
    $uploadsZip = $packagePath . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
    if (is_file($uploadsZip) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($uploadsZip) === true) {
            $n = (int) $zip->numFiles;
            $zip->close();
            // Simulation: treat listed entries as adds/replaces without filesystem cutover
            $uploadsAdd = max(0, $n);
            $uploadsReplace = (int) ($inject['uploads_to_replace'] ?? 0);
        }
    }
    if (isset($inject['uploads_to_add'])) {
        $uploadsAdd = (int) $inject['uploads_to_add'];
    }

    $compositeUnits = [
        ['id' => 'admins_permissions', 'status' => ($c7['admin_permissions_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'gl_voucher_graph', 'status' => ($c7['accounting_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'stock_fifo', 'status' => ($c7['stock_fifo_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'company_documents', 'status' => ($c7['company_documents_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'commercial', 'status' => ($c7['commercial_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'catalog', 'status' => ($c7['catalog_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'expenses_accounts', 'status' => ($c7['accounting_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
        ['id' => 'document_sequences', 'status' => ($c7['sequences_integrity'] ?? '') === 'PASS' ? 'affected_ok' : 'blocked'],
    ];

    $totalOps = $rowsInsert + $rowsDelete + $uploadsAdd + $uploadsReplace;
    $seconds = max(1, (int) ceil($totalOps / 500) + count($tablesAffected));
    if ($totalOps === 0) {
        $seconds = 1;
    }

    return [
        'tables_affected' => array_values(array_unique($tablesAffected)),
        'tables_affected_count' => count(array_unique($tablesAffected)),
        'rows_to_replace' => $rowsReplace,
        'rows_to_delete' => $rowsDelete,
        'rows_to_insert' => $rowsInsert,
        'uploads_to_replace' => $uploadsReplace,
        'uploads_to_add' => $uploadsAdd,
        'composite_units' => $compositeUnits,
        'special_handlers' => $specialHandlers,
        'estimated_duration_seconds' => $seconds,
        'estimated_duration' => orange_country_dry_run_duration_human($seconds),
        'survivor_country_impact' => $survivorImpact,
        'global_impact' => $globalImpact,
        'journal_entries_impact' => $jeImpact,
        'full_only_impact' => $fullOnlyImpact,
        'production_inventory_source' => (string) ($prodInv['source'] ?? 'unknown'),
        'production_target_row_total' => array_sum(array_map('intval', $prodTarget)),
        'production_survivor_row_total' => array_sum(array_map(
            'intval',
            is_array($prodInv['survivor_counts'] ?? null) ? $prodInv['survivor_counts'] : []
        )),
        'production_global_row_total' => array_sum(array_map(
            'intval',
            is_array($prodInv['global_counts'] ?? null) ? $prodInv['global_counts'] : []
        )),
        'shadow_model' => ORANGE_COUNTRY_SHADOW_MODEL,
        'target_country_change_summary' => [
            'tables' => count(array_unique($tablesAffected)),
            'rows_insert' => $rowsInsert,
            'rows_delete' => $rowsDelete,
            'rows_replace' => $rowsReplace,
        ],
        'outside_target_must_remain_unchanged' => [
            'survivor_country_impact' => 0,
            'global_impact' => 0,
            'journal_entries_impact' => 0,
            'full_only_impact' => 0,
        ],
        'outside_target_impact_proof' => $impactProof,
    ];
}

/**
 * @param list<string> $blockers
 * @param list<string> $warnings
 * @param array<string, mixed> $impact
 * @return array{overall_result:string}
 */
function orange_country_dry_run_score(array $blockers, array $warnings, array $impact): array
{
    if ($blockers !== []) {
        return ['overall_result' => 'FAIL'];
    }
    if ((int) ($impact['survivor_country_impact'] ?? 0) !== 0
        || (int) ($impact['global_impact'] ?? 0) !== 0
        || (int) ($impact['journal_entries_impact'] ?? 0) !== 0
        || (int) ($impact['full_only_impact'] ?? 0) !== 0
    ) {
        return ['overall_result' => 'FAIL'];
    }
    if ($warnings !== []) {
        return ['overall_result' => 'WARNING'];
    }

    return ['overall_result' => 'SAFE'];
}

function orange_country_dry_run_duration_human(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;

    return $m . 'm' . ($s > 0 ? $s . 's' : '');
}

/** @return array<string, mixed> */
function orange_country_dry_run_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

function orange_country_dry_run_sanitize(string $detail): string
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
function orange_country_dry_run_redact(array $report): array
{
    unset($report['package_path'], $report['absolute_paths'], $report['project_root'], $report['shadow_db']);
    if (isset($report['checks']) && is_array($report['checks'])) {
        foreach ($report['checks'] as $i => $c) {
            if (is_array($c) && isset($c['detail'])) {
                $report['checks'][$i]['detail'] = orange_country_dry_run_sanitize((string) $c['detail']);
            }
        }
    }

    return $report;
}

/**
 * GET-only dry-run status/report.
 *
 * @return array<string, mixed>
 */
function orange_country_dry_run_status(string $workRoot, string $runId): array
{
    orange_country_shadow_assert_run_id($runId);
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    $meta = orange_country_dry_run_read_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_DRY_RUN_META_FILE);
    $report = orange_country_dry_run_read_json($runDir . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_DRY_RUN_REPORT_FILE);
    $verify = orange_country_shadow_verify_status($workRoot, $runId);

    if ($meta === [] && $report === []) {
        return [
            'run_id' => $runId,
            'status' => '',
            'dry_run_available' => false,
            'meta' => null,
            'report' => null,
            'verify' => $verify,
            'read_only' => true,
            'execution_performed' => false,
            'production_db_writes' => 0,
            'shadow_db_writes' => 0,
            'country_production_restore_enabled' => false,
        ];
    }

    return [
        'run_id' => $runId,
        'status' => (string) ($meta['status'] ?? ($report['overall_result'] ?? '')),
        'dry_run_available' => true,
        'meta' => $meta !== [] ? $meta : null,
        'report' => $report !== [] ? orange_country_dry_run_redact($report) : null,
        'verify' => $verify,
        'read_only' => true,
        'execution_performed' => false,
        'production_db_writes' => 0,
        'shadow_db_writes' => 0,
        'country_production_restore_enabled' => false,
    ];
}

function orange_country_dry_run_exit_code(array $result): int
{
    return (($result['overall_result'] ?? '') === 'SAFE') ? 0 : 1;
}
