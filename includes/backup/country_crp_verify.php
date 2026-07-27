<?php

declare(strict_types=1);

/**
 * Phase C4 — Country Recovery Package Verify Engine (VERIFY ONLY).
 *
 * Does not restore, import, rollback, shadow, or certify.
 *
 * Sources of truth:
 * - config/country_restore_boundary_matrix.json
 * - package dependency_graph.json / manifest restore_batches
 * - package manifest.json
 */

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_validate.php';
require_once __DIR__ . '/country_boundary_matrix_lib.php';
require_once __DIR__ . '/country_export.php';
require_once __DIR__ . '/uploads_collector.php';

const ORANGE_CRP_VERIFY_ENGINE_VERSION = '1.0';
const ORANGE_CRP_VERIFY_REPORT_FILENAME = 'country_verify_report.json';

/**
 * @param array{write_report?:bool,project_root?:string} $options
 * @return array{
 *   overall:string,
 *   ok:bool,
 *   codes:list<string>,
 *   warnings:list<string>,
 *   checks:list<array{id:string,status:string,code:?string,detail:string}>,
 *   report_path:?string,
 *   report:array<string,mixed>,
 *   manifest:?array<string,mixed>
 * }
 */
function orange_crp_verify_run(string $packageRoot, array $options = []): array
{
    $packageRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $packageRoot), DIRECTORY_SEPARATOR);
    $projectRoot = (string) ($options['project_root'] ?? orange_backup_project_root());
    $writeReport = (bool) ($options['write_report'] ?? true);

    $codes = [];
    $warnings = [];
    $checks = [];
    $manifest = null;
    $matrix = null;
    /** @var array<string, true> $exportable */
    $exportable = [];

    $add = static function (
        string $id,
        string $status,
        ?string $code,
        string $detail
    ) use (&$checks, &$codes, &$warnings): void {
        $checks[] = [
            'id' => $id,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
        ];
        if ($status === 'FAIL' && $code !== null && $code !== '') {
            $codes[] = $code;
        }
        if ($status === 'WARNING' && $code !== null && $code !== '') {
            $warnings[] = $code;
        }
    };

    // --- Manifest ---
    $manifestPath = $packageRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        $add('manifest', 'FAIL', 'manifest_missing', 'manifest.json not found');
        return orange_crp_verify_finalize($packageRoot, null, $checks, $codes, $warnings, $writeReport, $projectRoot);
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        $add('manifest', 'FAIL', 'manifest_invalid_json', 'manifest.json is not valid JSON');
        return orange_crp_verify_finalize($packageRoot, null, $checks, $codes, $warnings, $writeReport, $projectRoot);
    }
    $add('manifest', 'PASS', null, 'manifest.json loaded');

    if (($manifest['package_type'] ?? '') !== 'country_recovery') {
        $add('manifest_package_type', 'FAIL', 'manifest_package_type_invalid', 'package_type must be country_recovery');
    } else {
        $add('manifest_package_type', 'PASS', null, 'package_type=country_recovery');
    }

    foreach ([
        'country_id',
        'schema_revision',
        'boundary_policy_version',
        'dependency_graph_version',
        'package_version',
        'package_fingerprint',
        'restore_batches',
        'export_time',
        'drv_version',
        'verify_version',
    ] as $field) {
        $present = array_key_exists($field, $manifest)
            && !(is_string($manifest[$field]) && trim($manifest[$field]) === '')
            && !($manifest[$field] === null);
        if (!$present) {
            $add(
                'manifest_field_' . $field,
                'FAIL',
                'manifest_field_missing_' . $field,
                'Required manifest field missing: ' . $field
            );
        }
    }

    // --- Boundary matrix ---
    $expectedSchema = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION : 124;
    try {
        if (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
            $expectedSchema = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
        }
        $matrix = orange_country_boundary_matrix_load($projectRoot);
        if ((int) ($matrix['schema_revision'] ?? 0) > 0) {
            $expectedSchema = (int) $matrix['schema_revision'];
        }
        foreach (is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [] as $tName => $tMeta) {
            if (is_array($tMeta) && (bool) ($tMeta['exportable'] ?? false)) {
                $exportable[(string) $tName] = true;
            }
        }
        $add('boundary_matrix', 'PASS', null, 'boundary matrix loaded');
    } catch (Throwable $e) {
        $add('boundary_matrix', 'FAIL', 'boundary_matrix_unreadable', $e->getMessage());
    }

    if (($manifest['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION) {
        $add('boundary_policy_version', 'FAIL', 'boundary_policy_version_mismatch', 'Expected ' . ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION);
    } else {
        $add('boundary_policy_version', 'PASS', null, ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION);
    }
    if (($manifest['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION) {
        $add('dependency_graph_version', 'FAIL', 'dependency_graph_version_mismatch', 'Expected ' . ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION);
    } else {
        $add('dependency_graph_version', 'PASS', null, ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION);
    }
    if ((int) ($manifest['schema_revision'] ?? 0) !== $expectedSchema) {
        $add('schema_revision', 'FAIL', 'schema_revision_mismatch', 'manifest schema_revision does not match expected ' . (string) $expectedSchema);
    } else {
        $add('schema_revision', 'PASS', null, (string) $expectedSchema);
    }

    $countryId = (int) ($manifest['country_id'] ?? 0);
    if ($countryId <= 0) {
        $add('country_id', 'FAIL', 'country_id_invalid', 'country_id must be positive');
    } else {
        $add('country_id', 'PASS', null, 'country_id=' . (string) $countryId);
    }

    // --- Required files ---
    foreach ([
        'checksums.sha256',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'country.sql.gz',
        'files/uploads_country.zip',
        'health.json',
    ] as $required) {
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required);
        if (!is_file($abs)) {
            $code = match ($required) {
                'country.sql.gz' => 'country_sql_gz_missing',
                'files/uploads_country.zip' => 'uploads_archive_missing',
                'dependency_graph.json' => 'dependency_graph_missing',
                'table_inventory.json' => 'inventory_missing',
                'checksums.sha256' => 'checksums_missing',
                default => 'required_file_missing',
            };
            $add('file_' . str_replace(['/', '.'], '_', $required), 'FAIL', $code, 'Missing ' . $required);
        }
    }
    $sqlDir = $packageRoot . DIRECTORY_SEPARATOR . 'sql';
    $sqlFiles = is_dir($sqlDir) ? (glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: []) : [];
    if ($sqlFiles === []) {
        $add('sql_chunks', 'FAIL', 'sql_chunks_missing', 'sql/ has no .sql chunks');
    } else {
        $add('sql_chunks', 'PASS', null, 'sql chunks=' . (string) count($sqlFiles));
    }

    // --- Checksums ---
    $checksum = orange_backup_verify_checksums($packageRoot);
    if (!$checksum['ok']) {
        foreach ($checksum['errors'] as $err) {
            $code = 'checksums_invalid';
            if (str_contains($err, 'mismatch')) {
                $code = 'checksum_mismatch';
            } elseif (str_contains($err, 'Missing checksums')) {
                $code = 'checksums_missing';
            }
            $add('checksums', 'FAIL', $code, $err);
        }
    } else {
        $add('checksums', 'PASS', null, 'checksums.sha256 OK');
    }

    // --- Fingerprint ---
    $storedFp = trim((string) ($manifest['package_fingerprint'] ?? ''));
    if ($storedFp === '') {
        $add('package_fingerprint', 'FAIL', 'package_fingerprint_missing', 'package_fingerprint empty');
    } else {
        $computedFp = orange_crp_export_package_fingerprint($packageRoot, $manifest);
        if (!hash_equals($storedFp, $computedFp)) {
            $add('package_fingerprint', 'FAIL', 'package_fingerprint_mismatch', 'recomputed fingerprint differs');
        } else {
            $add('package_fingerprint', 'PASS', null, 'fingerprint matches');
        }
    }

    // --- Health / inventory country id ---
    $healthPath = $packageRoot . DIRECTORY_SEPARATOR . 'health.json';
    if (is_file($healthPath)) {
        $health = json_decode((string) file_get_contents($healthPath), true);
        if (!is_array($health)) {
            $add('health', 'FAIL', 'health_invalid_json', 'health.json invalid');
        } else {
            if (($health['package_status'] ?? '') === 'failed') {
                $add('health_status', 'FAIL', 'health_package_status_failed', 'health package_status=failed');
            }
            if ($countryId > 0 && (int) ($health['country_id'] ?? 0) !== $countryId) {
                $add('health_country_id', 'FAIL', 'country_id_mismatch_health', 'health.country_id != manifest.country_id');
            } else {
                $add('health_country_id', 'PASS', null, 'health country_id OK');
            }
        }
    }

    $inventory = null;
    $inventoryPath = $packageRoot . DIRECTORY_SEPARATOR . 'table_inventory.json';
    if (is_file($inventoryPath)) {
        $inventory = json_decode((string) file_get_contents($inventoryPath), true);
        if (!is_array($inventory)) {
            $add('inventory', 'FAIL', 'inventory_invalid', 'table_inventory.json invalid');
        } else {
            $add('inventory', 'PASS', null, 'table_inventory.json loaded');
            if ($countryId > 0 && (int) ($inventory['country_id'] ?? 0) !== $countryId) {
                $add('inventory_country_id', 'FAIL', 'country_id_mismatch_inventory', 'inventory.country_id != manifest.country_id');
            }
            if (!empty($inventory['other_country_markers'])) {
                $add('inventory_markers', 'FAIL', 'inventory_other_country_markers', 'other_country_markers present');
            } else {
                $add('inventory_markers', 'PASS', null, 'no other_country_markers');
            }
        }
    }

    // --- Dependency graph + batches ---
    $depPath = $packageRoot . DIRECTORY_SEPARATOR . 'dependency_graph.json';
    $depGraph = null;
    if (is_file($depPath)) {
        $depGraph = json_decode((string) file_get_contents($depPath), true);
        if (!is_array($depGraph)) {
            $add('dependency_graph', 'FAIL', 'dependency_graph_invalid', 'dependency_graph.json invalid');
        } else {
            if (($depGraph['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION) {
                $add('dependency_graph_version_file', 'FAIL', 'dependency_graph_version_mismatch', 'dependency_graph.json version mismatch');
            }
            if (($depGraph['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION) {
                $add('dependency_graph_policy', 'FAIL', 'boundary_policy_version_mismatch', 'dependency_graph.json policy version mismatch');
            }
            $add('dependency_graph', 'PASS', null, 'dependency_graph.json loaded');
        }
    }

    $normalizedManifestBatches = [];
    $manifestBatches = is_array($manifest['restore_batches'] ?? null) ? $manifest['restore_batches'] : null;
    if ($manifestBatches === null) {
        $add('restore_batches', 'FAIL', 'dependency_batch_missing', 'manifest.restore_batches missing');
    } else {
        $normalizedManifestBatches = orange_crp_verify_normalize_batches($manifestBatches);
        if ($normalizedManifestBatches === []) {
            $add('restore_batches', 'FAIL', 'dependency_batch_missing', 'manifest.restore_batches empty');
        } else {
            $add('restore_batches', 'PASS', null, 'restore_batches present');
        }
        if ($matrix !== null && $normalizedManifestBatches !== []) {
            $expectedBatches = orange_country_boundary_matrix_restore_batches($matrix);
            if (!orange_crp_verify_batches_equal($expectedBatches, $normalizedManifestBatches)) {
                $add('restore_batches_matrix', 'FAIL', 'dependency_batch_mismatch', 'manifest batches differ from boundary matrix');
            } else {
                $add('restore_batches_matrix', 'PASS', null, 'batches match boundary matrix');
            }
        }
        if (is_array($depGraph) && is_array($depGraph['restore_batches'] ?? null)) {
            $graphBatches = orange_crp_verify_normalize_batches($depGraph['restore_batches']);
            if ($normalizedManifestBatches !== [] && !orange_crp_verify_batches_equal($normalizedManifestBatches, $graphBatches)) {
                $add('restore_batches_graph', 'FAIL', 'dependency_batch_mismatch', 'manifest batches differ from dependency_graph.json');
            }
        }
        if (is_array($depGraph) && is_array($depGraph['nodes'] ?? null) && $normalizedManifestBatches !== []) {
            $orderErr = orange_crp_verify_batch_order_on_nodes($depGraph['nodes'], $normalizedManifestBatches);
            if ($orderErr !== null) {
                $add('dependency_order', 'FAIL', 'dependency_order_violation', $orderErr);
            } else {
                $add('dependency_order', 'PASS', null, 'node restore_batch ordering OK');
            }
        }
    }

    // --- Inventory vs matrix (forbidden / unknown tables) ---
    if ($matrix !== null && is_array($inventory) && is_array($inventory['tables'] ?? null)) {
        foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
            if (array_key_exists($never, $inventory['tables'])) {
                $add('inventory_forbidden_' . $never, 'FAIL', 'inventory_forbidden_table_present', 'Inventory lists never-export table: ' . $never);
            }
        }
        foreach ($inventory['tables'] as $tName => $_count) {
            $tName = (string) $tName;
            if (in_array($tName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
                continue;
            }
            if ($exportable !== [] && !isset($exportable[$tName])) {
                $add('inventory_unknown_' . $tName, 'FAIL', 'inventory_unknown_table', 'Inventory table not in boundary mutate set: ' . $tName);
            }
        }
        if (!in_array('inventory_forbidden_table_present', $codes, true) && !in_array('inventory_unknown_table', $codes, true)) {
            $add('inventory_matrix', 'PASS', null, 'inventory tables within boundary mutate set');
        }
    }

    // --- Composite units (from inventory + id_snapshot) ---
    $idSnapshot = null;
    $idPath = $packageRoot . DIRECTORY_SEPARATOR . 'id_snapshot.json';
    if (is_file($idPath)) {
        $idSnapshot = json_decode((string) file_get_contents($idPath), true);
    }
    $rowCounts = is_array($inventory['tables'] ?? null) ? $inventory['tables'] : [];
    $idMap = is_array($idSnapshot['tables'] ?? null) ? $idSnapshot['tables'] : [];
    $adminIds = is_array($idMap['admins'] ?? null) ? $idMap['admins'] : [];
    $permCount = (int) ($rowCounts['admin_permissions'] ?? 0);
    if ($permCount > 0 && $adminIds === []) {
        $add('composite_admins', 'FAIL', 'composite_admins_permissions_mismatch', 'admin_permissions without admins snapshot');
    } else {
        $add('composite_admins', 'PASS', null, 'admins/permissions composite OK');
    }
    $accountIds = is_array($idMap['accounts'] ?? null) ? $idMap['accounts'] : [];
    if ((int) ($rowCounts['expenses'] ?? 0) > 0 && $accountIds === []) {
        $add('composite_expenses', 'FAIL', 'composite_expenses_accounts_mismatch', 'expenses without accounts snapshot');
    } else {
        $add('composite_expenses', 'PASS', null, 'expenses/accounts composite OK');
    }
    $voucherIds = is_array($idMap['journal_vouchers'] ?? null) ? $idMap['journal_vouchers'] : [];
    if ((int) ($rowCounts['orange_gl_voucher_slots'] ?? 0) > 0 && $voucherIds === []) {
        $add('composite_slots', 'FAIL', 'composite_voucher_slots_mismatch', 'voucher slots without journal_vouchers snapshot');
    } else {
        $add('composite_slots', 'PASS', null, 'voucher slots composite OK');
    }

    // --- Special handlers present in graph ---
    if (is_array($depGraph['nodes'] ?? null)) {
        // Schema 123+: orange_company_documents is direct country_id authority (no polymorphic special handler).
        $handlersExpected = [
            'document_sequences' => 'seq_country_namespace',
            'admin_permissions' => 'admins_permissions_composite',
            'expenses' => 'expenses_via_accounts',
            'orange_gl_voucher_slots' => 'gl_voucher_slots_country',
        ];
        $nodesByTable = [];
        foreach ($depGraph['nodes'] as $node) {
            if (is_array($node) && isset($node['table'])) {
                $nodesByTable[(string) $node['table']] = $node;
            }
        }
        foreach ($handlersExpected as $table => $handler) {
            if (!isset($nodesByTable[$table])) {
                if (isset($exportable[$table]) || array_key_exists($table, $rowCounts)) {
                    $add('special_' . $table, 'FAIL', 'special_handler_missing', 'Missing dependency node for special table ' . $table);
                }
                continue;
            }
            $got = $nodesByTable[$table]['special_handler'] ?? null;
            if ($got !== $handler) {
                $add('special_' . $table, 'FAIL', 'special_handler_missing', 'Handler mismatch for ' . $table);
            } else {
                $add('special_' . $table, 'PASS', null, $table . ' handler OK');
            }
        }
        if (isset($nodesByTable['orange_company_documents'])) {
            $docsHandler = $nodesByTable['orange_company_documents']['special_handler'] ?? null;
            $docsType = '';
            try {
                require_once __DIR__ . '/backup_table_registry_lib.php';
                $registryForDocs = orange_backup_registry_load($projectRoot);
                $docsRule = is_array($registryForDocs['tables']['orange_company_documents']['extraction_rule'] ?? null)
                    ? $registryForDocs['tables']['orange_company_documents']['extraction_rule']
                    : [];
                $docsType = (string) ($docsRule['type'] ?? '');
            } catch (Throwable) {
                $docsType = '';
            }
            if ($docsHandler !== null && $docsHandler !== '') {
                $add(
                    'special_orange_company_documents',
                    'FAIL',
                    'special_handler_missing',
                    'orange_company_documents must use direct country_id (no special_handler) under schema 123+'
                );
            } elseif ($docsType !== '' && $docsType !== 'country_id') {
                $add(
                    'special_orange_company_documents',
                    'FAIL',
                    'special_handler_missing',
                    'orange_company_documents extraction_rule must be country_id'
                );
            } else {
                $add('special_orange_company_documents', 'PASS', null, 'orange_company_documents direct country_id OK');
            }
        }
    }

    // --- SQL forbidden / leakage scans ---
    $sqlScan = orange_crp_verify_scan_sql_artifacts($packageRoot, $countryId, $exportable);
    foreach ($sqlScan['fails'] as $fail) {
        $add($fail['id'], 'FAIL', $fail['code'], $fail['detail']);
    }
    foreach ($sqlScan['warns'] as $warn) {
        $add($warn['id'], 'WARNING', $warn['code'], $warn['detail']);
    }
    if ($sqlScan['fails'] === []) {
        $add('sql_policy', 'PASS', null, 'SQL artifacts pass forbidden/leakage scans');
    }

    // --- Uploads allowlist ---
    $uploadsZip = $packageRoot . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
    if (is_file($uploadsZip)) {
        $uploadScan = orange_crp_verify_scan_uploads_zip($uploadsZip);
        foreach ($uploadScan['fails'] as $fail) {
            $add($fail['id'], 'FAIL', $fail['code'], $fail['detail']);
        }
        if ($uploadScan['fails'] === []) {
            $add('uploads_allowlist', 'PASS', null, 'uploads zip allowlist OK entries=' . (string) $uploadScan['entry_count']);
        }
    }

    return orange_crp_verify_finalize($packageRoot, $manifest, $checks, $codes, $warnings, $writeReport, $projectRoot);
}

/**
 * @param list<array{id:string,status:string,code:?string,detail:string}> $checks
 * @param list<string> $codes
 * @param list<string> $warnings
 * @return array<string, mixed>
 */
function orange_crp_verify_finalize(
    string $packageRoot,
    ?array $manifest,
    array $checks,
    array $codes,
    array $warnings,
    bool $writeReport,
    string $projectRoot
): array {
    $codes = array_values(array_unique($codes));
    $warnings = array_values(array_unique($warnings));
    $hasFail = false;
    foreach ($checks as $c) {
        if (($c['status'] ?? '') === 'FAIL') {
            $hasFail = true;
            break;
        }
    }
    $hasWarn = $warnings !== [];
    if ($hasFail) {
        $overall = 'FAIL';
    } elseif ($hasWarn) {
        $overall = 'WARNING';
    } else {
        $overall = 'PASS';
    }

    $report = [
        'report_type' => 'country_recovery_verify',
        'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
        'generated_at' => gmdate('c'),
        'package_path' => $packageRoot,
        'overall' => $overall,
        'ok' => $overall === 'PASS' || $overall === 'WARNING',
        'codes' => $codes,
        'warnings' => $warnings,
        'checks' => $checks,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'country_id' => is_array($manifest) ? (int) ($manifest['country_id'] ?? 0) : 0,
        'schema_revision' => is_array($manifest) ? (int) ($manifest['schema_revision'] ?? 0) : 0,
        'package_fingerprint' => is_array($manifest) ? (string) ($manifest['package_fingerprint'] ?? '') : '',
        'project_root' => $projectRoot,
    ];

    $reportPath = null;
    if ($writeReport && is_dir($packageRoot)) {
        $reportPath = $packageRoot . DIRECTORY_SEPARATOR . ORANGE_CRP_VERIFY_REPORT_FILENAME;
        orange_backup_write_json($reportPath, $report);
    }

    return [
        'overall' => $overall,
        'ok' => (bool) $report['ok'],
        'codes' => $codes,
        'warnings' => $warnings,
        'checks' => $checks,
        'report_path' => $reportPath,
        'report' => $report,
        'manifest' => $manifest,
    ];
}

/**
 * @param array<string|int, mixed> $batches
 * @return array<int, list<string>>
 */
function orange_crp_verify_normalize_batches(array $batches): array
{
    $out = [];
    foreach ($batches as $k => $list) {
        $b = (int) $k;
        if ($b < 1 || !is_array($list)) {
            continue;
        }
        $tables = [];
        foreach ($list as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
        sort($tables);
        $out[$b] = $tables;
    }
    ksort($out);

    return $out;
}

/**
 * @param array<int, list<string>> $a
 * @param array<int, list<string>> $b
 */
function orange_crp_verify_batches_equal(array $a, array $b): bool
{
    if (array_keys($a) !== array_keys($b)) {
        return false;
    }
    foreach ($a as $k => $list) {
        if ($list !== ($b[$k] ?? null)) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array<string,mixed>> $nodes
 * @param array<int, list<string>> $batches
 */
function orange_crp_verify_batch_order_on_nodes(array $nodes, array $batches): ?string
{
    $tableBatch = [];
    foreach ($batches as $b => $tables) {
        foreach ($tables as $t) {
            $tableBatch[$t] = (int) $b;
        }
    }
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $table = (string) ($node['table'] ?? '');
        if ($table === '' || !isset($tableBatch[$table])) {
            continue;
        }
        $nodeBatch = (int) ($node['restore_batch'] ?? 0);
        if ($nodeBatch > 0 && $nodeBatch !== $tableBatch[$table]) {
            return 'Node restore_batch mismatch for ' . $table;
        }
    }

    return null;
}

/**
 * @param array<string, true> $exportable
 * @return array{fails:list<array{id:string,code:string,detail:string}>,warns:list<array{id:string,code:string,detail:string}>}
 */
function orange_crp_verify_scan_sql_artifacts(string $packageRoot, int $countryId, array $exportable): array
{
    $fails = [];
    $warns = [];

    $scanText = static function (string $content, string $source) use (&$fails, $countryId, $exportable): void {
        foreach (ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES as $forbidden) {
            if (preg_match('/INSERT\s+INTO\s+`' . preg_quote($forbidden, '/') . '`/i', $content) === 1) {
                $fails[] = [
                    'id' => 'forbidden_sql_' . $forbidden . '_' . md5($source),
                    'code' => 'forbidden_table_in_sql',
                    'detail' => 'Forbidden INSERT into `' . $forbidden . '` in ' . $source,
                ];
            }
        }
        foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $never) {
            if (preg_match('/INSERT\s+INTO\s+`' . preg_quote($never, '/') . '`/i', $content) === 1) {
                $fails[] = [
                    'id' => 'never_sql_' . $never . '_' . md5($source),
                    'code' => 'forbidden_table_in_sql',
                    'detail' => 'Never-export INSERT into `' . $never . '` in ' . $source,
                ];
            }
        }
        if (preg_match('/\bcountry_id\s*=\s*NULL\b/i', $content) === 1
            || preg_match('/\bcountry_id\s+IS\s+NULL\b/i', $content) === 1) {
            $fails[] = [
                'id' => 'null_leak_' . md5($source),
                'code' => 'null_leakage_in_sql',
                'detail' => 'NULL country_id predicate/value pattern in ' . $source,
            ];
        }
        if ($countryId > 0
            && preg_match_all('/--\s*Orange CRP export table=([a-z0-9_]+)\s+country_id=(\d+)/i', $content, $matches, PREG_SET_ORDER) > 0
        ) {
            foreach ($matches as $m) {
                $hdrCountry = (int) $m[2];
                $hdrTable = (string) $m[1];
                if ($hdrCountry !== $countryId) {
                    $fails[] = [
                        'id' => 'cross_country_' . md5($source . '|' . $hdrTable . '|' . (string) $hdrCountry),
                        'code' => 'cross_country_leakage_in_sql',
                        'detail' => 'SQL header country_id=' . (string) $hdrCountry . ' != manifest for table ' . $hdrTable,
                    ];
                }
                if ($exportable !== [] && !isset($exportable[$hdrTable])) {
                    $fails[] = [
                        'id' => 'unknown_export_' . md5($source . '|' . $hdrTable),
                        'code' => 'forbidden_sql_present',
                        'detail' => 'SQL chunk for non-mutate table ' . $hdrTable,
                    ];
                }
            }
        }
        if (preg_match('/\bOR\s+country_id\s+IS\s+NULL\b/i', $content) === 1
            || preg_match('/COALESCE\s*\(\s*country_id/i', $content) === 1) {
            $fails[] = [
                'id' => 'forbidden_sql_null_coalesce_' . md5($source),
                'code' => 'forbidden_sql_present',
                'detail' => 'Forbidden NULL-coalesce/OR IS NULL country predicate in ' . $source,
            ];
        }
        if (preg_match('/INSERT\s+INTO\s+`document_sequences`/i', $content) === 1 && $countryId > 0) {
            if (!str_contains($content, '_c' . $countryId)) {
                $fails[] = [
                    'id' => 'seq_ns_' . md5($source),
                    'code' => 'sequence_namespace_violation',
                    'detail' => 'document_sequences insert without _c' . (string) $countryId . ' namespace marker in ' . $source,
                ];
            }
        }
    };

    $sqlDir = $packageRoot . DIRECTORY_SEPARATOR . 'sql';
    if (is_dir($sqlDir)) {
        foreach (glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $sqlFile) {
            $scanText((string) file_get_contents($sqlFile), 'sql/' . basename($sqlFile));
        }
    }

    $gzPath = $packageRoot . DIRECTORY_SEPARATOR . 'country.sql.gz';
    if (is_file($gzPath)) {
        if (!function_exists('gzopen')) {
            $warns[] = [
                'id' => 'gz_unavailable',
                'code' => 'country_sql_gz_unreadable',
                'detail' => 'gzopen unavailable; skipped country.sql.gz body scan',
            ];
        } else {
            $gz = @gzopen($gzPath, 'rb');
            if ($gz === false) {
                $fails[] = [
                    'id' => 'gz_open',
                    'code' => 'country_sql_gz_unreadable',
                    'detail' => 'Cannot open country.sql.gz',
                ];
            } else {
                $buf = '';
                while (!gzeof($gz) && strlen($buf) < 8 * 1024 * 1024) {
                    $buf .= (string) gzread($gz, 256 * 1024);
                }
                gzclose($gz);
                $scanText($buf, 'country.sql.gz');
            }
        }
    }

    return ['fails' => $fails, 'warns' => $warns];
}

/**
 * @return array{fails:list<array{id:string,code:string,detail:string}>,entry_count:int}
 */
function orange_crp_verify_scan_uploads_zip(string $zipPath): array
{
    $fails = [];
    if (!class_exists('ZipArchive')) {
        return [
            'fails' => [[
                'id' => 'zip_ext',
                'code' => 'uploads_zip_unreadable',
                'detail' => 'ZipArchive extension unavailable',
            ]],
            'entry_count' => 0,
        ];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [
            'fails' => [[
                'id' => 'zip_open',
                'code' => 'uploads_zip_unreadable',
                'detail' => 'Cannot open uploads_country.zip',
            ]],
            'entry_count' => 0,
        ];
    }
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        $count++;
        if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            $fails[] = [
                'id' => 'upload_path_' . $i,
                'code' => 'uploads_allowlist_violation',
                'detail' => 'Unsafe upload path: ' . $name,
            ];
            continue;
        }
        // C3 empty-archive marker (orange_country_uploads_write_empty_zip)
        if ($name === 'README.txt') {
            continue;
        }
        $rel = $name;
        if (!str_starts_with($rel, 'uploads/')) {
            $rel = 'uploads/' . ltrim($rel, '/');
        }
        if (!orange_country_uploads_is_allowlisted($rel) && !orange_country_uploads_is_allowlisted($name)) {
            $fails[] = [
                'id' => 'upload_allow_' . $i,
                'code' => 'uploads_allowlist_violation',
                'detail' => 'Upload path not allowlisted: ' . $name,
            ];
        }
    }
    $zip->close();

    return ['fails' => $fails, 'entry_count' => $count];
}
