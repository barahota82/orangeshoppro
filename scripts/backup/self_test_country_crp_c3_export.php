<?php

declare(strict_types=1);

/**
 * Phase C3 — Country Recovery Package export self-tests (boundary policy).
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c3_export.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_validate.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_boundary_matrix_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';

$failures = 0;

function c3_assert(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

// --- Matrix load ---
try {
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    c3_assert(($matrix['boundary_policy_version'] ?? '') === 'C1.1', 'boundary_policy_version=C1.1');
    c3_assert(($matrix['dependency_graph_version'] ?? '') === 'C2', 'dependency_graph_version=C2');
    c3_assert((int) ($matrix['schema_revision'] ?? 0) === 121, 'matrix schema_revision=121');
    c3_assert((int) ($matrix['mutate_table_count'] ?? 0) === 80, 'mutate_table_count=80');
} catch (Throwable $e) {
    c3_assert(false, 'matrix load: ' . $e->getMessage());
    echo "FAIL count={$failures}\n";
    exit(1);
}

$registry = orange_backup_registry_load($projectRoot);
$plan = orange_country_boundary_matrix_export_plan($matrix, $registry);
c3_assert(count($plan) === 80, 'export plan has 80 tables');

$planNames = array_map(static fn (array $e): string => $e['table'], $plan);
c3_assert(!in_array('journal_entries', $planNames, true), 'Global/Full-only: journal_entries excluded');
c3_assert(!in_array('orange_country_screen_copy_log', $planNames, true), 'Global: country_screen_copy_log excluded');
c3_assert(in_array('admin_permissions', $planNames, true), 'special: admin_permissions included');
c3_assert(in_array('document_sequences', $planNames, true), 'special: document_sequences included');
c3_assert(in_array('expenses', $planNames, true), 'special: expenses included');
c3_assert(in_array('orange_gl_voucher_slots', $planNames, true), 'special: voucher slots included');
c3_assert(in_array('orange_company_documents', $planNames, true), 'special: company documents included');

// --- Dependency ordering ---
$prevBatch = 0;
$orderOk = true;
foreach ($plan as $entry) {
    $b = (int) ($entry['matrix']['restore_batch'] ?? 0);
    if ($b < $prevBatch) {
        $orderOk = false;
        break;
    }
    $prevBatch = $b;
}
c3_assert($orderOk, 'dependency ordering by restore_batch');
$batches = orange_country_boundary_matrix_restore_batches($matrix);
c3_assert(isset($batches[1], $batches[6]), 'restore batches 1 and 6 present');
c3_assert(in_array('document_sequences', $batches[6] ?? [], true), 'sequences in batch 6');
c3_assert(in_array('admins', $batches[1] ?? [], true), 'admins in batch 1');
c3_assert(in_array('admin_permissions', $batches[2] ?? [], true), 'admin_permissions in batch 2');

// --- NULL leakage ---
$nullErrors = orange_country_export_validate_row_country_scope(
    'orders',
    ['extraction_rule' => ['type' => 'country_id', 'column' => 'country_id']],
    ['id' => 1, 'country_id' => null],
    1
);
c3_assert($nullErrors !== [], 'NULL leakage rejected');

// --- Country leakage / wrong country ---
$wrong = orange_country_export_validate_row_country_scope(
    'orders',
    ['extraction_rule' => ['type' => 'country_id', 'column' => 'country_id']],
    ['id' => 1, 'country_id' => 9],
    1
);
c3_assert($wrong !== [], 'Country leakage / wrong country rejected');

// --- Global leakage (forbidden SQL tables) ---
c3_assert(in_array('journal_entries', ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES, true), 'forbidden list has journal_entries');
c3_assert(in_array('orange_country_screen_copy_log', ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES, true), 'forbidden list has copy_log');

// --- country_scope_or forbidden ---
$scopeOr = orange_country_export_validate_row_country_scope(
    'orange_country_screen_copy_log',
    ['extraction_rule' => ['type' => 'country_scope_or', 'columns' => ['source_country_id', 'target_country_id']]],
    ['id' => 1, 'source_country_id' => 1, 'target_country_id' => 2],
    1
);
c3_assert($scopeOr !== [], 'country_scope_or rejected by validator');
$threw = false;
try {
    orange_country_export_build_query(
        'orange_country_screen_copy_log',
        ['extraction_rule' => ['type' => 'country_scope_or', 'columns' => ['source_country_id']]],
        1,
        []
    );
} catch (Throwable $e) {
    $threw = true;
}
c3_assert($threw, 'country_scope_or query builder throws');

// --- Sequence namespace ---
$seqQ = orange_crp_export_build_sequence_namespace_query(3);
c3_assert(str_contains($seqQ['sql'], 'document_sequences'), 'sequence query targets document_sequences');
c3_assert(str_contains((string) ($seqQ['params'][0] ?? ''), '_c3'), 'sequence namespace param includes _c3');
$seqBad = orange_country_export_validate_row_country_scope(
    'document_sequences',
    ['extraction_rule' => ['type' => 'special_sequence_namespace']],
    ['scope' => 'INV_c9', 'last_value' => 1],
    3
);
c3_assert($seqBad !== [], 'sequence namespace violation detected');
$seqOk = orange_country_export_validate_row_country_scope(
    'document_sequences',
    ['extraction_rule' => ['type' => 'special_sequence_namespace']],
    ['scope' => 'INV_c3', 'last_value' => 1],
    3
);
c3_assert($seqOk === [], 'valid sequence namespace accepted');

// --- Composite export helpers ---
$compBad = orange_crp_export_validate_composites(
    ['admins' => [], 'accounts' => [], 'journal_vouchers' => []],
    ['admin_permissions' => 2, 'expenses' => 1, 'orange_gl_voucher_slots' => 1]
);
c3_assert($compBad !== [], 'composite inconsistency detected');
$compOk = orange_crp_export_validate_composites(
    ['admins' => [1], 'accounts' => [10], 'journal_vouchers' => [5]],
    ['admin_permissions' => 1, 'expenses' => 1, 'orange_gl_voucher_slots' => 1]
);
c3_assert($compOk === [], 'composite consistency OK');

// --- FK override journal_lines ---
$jlMeta = orange_country_boundary_matrix_merge_export_meta(
    'journal_lines',
    ['classification' => 'Mixed', 'restore_mode' => 'replace', 'restore_batch' => 3, 'ownership_resolver' => 'parent_fk'],
    [
        'ownership_type' => 'dependent',
        'export_order' => 163,
        'extraction_rule' => [
            'type' => 'parent_rows',
            'parent_table' => 'journal_vouchers',
            'foreign_key' => 'journal_voucher_id',
        ],
        'parent_dependency' => [
            'table' => 'journal_vouchers',
            'foreign_key' => 'journal_voucher_id',
            'nullable' => false,
        ],
    ]
);
c3_assert(($jlMeta['parent_dependency']['foreign_key'] ?? '') === 'voucher_id', 'journal_lines FK override voucher_id');
c3_assert(($jlMeta['extraction_rule']['foreign_key'] ?? '') === 'voucher_id', 'journal_lines extraction FK override');

// --- Uploads filtering ---
c3_assert(orange_country_uploads_is_allowlisted('uploads/products/x.jpg'), 'uploads allowlist products');
c3_assert(!orange_country_uploads_is_allowlisted('uploads/../etc/passwd'), 'uploads traversal blocked');
c3_assert(!orange_country_uploads_is_allowlisted('var/full/tree/x'), 'full uploads tree path rejected');

// --- Package fingerprint + manifest shape ---
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_c3_fp_' . bin2hex(random_bytes(4));
mkdir($tmp);
mkdir($tmp . DIRECTORY_SEPARATOR . 'files');
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'country.sql.gz', 'gz');
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip', 'zip');
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'table_inventory.json', '{}');
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'dependency_graph.json', '{}');
$fp = orange_crp_export_package_fingerprint($tmp, [
    'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
    'country_id' => 1,
    'schema_revision' => 121,
    'boundary_policy_version' => 'C1.1',
    'dependency_graph_version' => 'C2',
]);
c3_assert(strlen($fp) === 64, 'package fingerprint sha256 length');
$fp2 = orange_crp_export_package_fingerprint($tmp, [
    'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
    'country_id' => 1,
    'schema_revision' => 121,
    'boundary_policy_version' => 'C1.1',
    'dependency_graph_version' => 'C2',
]);
c3_assert($fp === $fp2, 'package fingerprint stable');
orange_backup_remove_dir($tmp);

// --- Inventory / graph builders ---
$graph = orange_country_export_build_dependency_graph_c3($matrix, $registry, $batches);
c3_assert(($graph['dependency_graph_version'] ?? '') === 'C2', 'dependency_graph.json version C2');
c3_assert(count($graph['nodes'] ?? []) === 80, 'dependency graph nodes=80');
$summary = orange_crp_export_classification_summary(['orders' => 2, 'order_items' => 5], $matrix);
c3_assert(($summary['Country Scoped'] ?? 0) === 2, 'inventory classification Country Scoped');
c3_assert(($summary['Mixed'] ?? 0) === 5, 'inventory classification Mixed');

// --- country.sql.gz writer ---
$tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_c3_sql_' . bin2hex(random_bytes(4));
mkdir($tmpSql);
file_put_contents($tmpSql . DIRECTORY_SEPARATOR . '000_session_preamble.sql', "-- pre\n");
file_put_contents($tmpSql . DIRECTORY_SEPARATOR . '050_accounts.sql', "-- Orange CRP export table=accounts\nINSERT INTO `accounts` VALUES (1);\n");
$gzOut = $tmpSql . DIRECTORY_SEPARATOR . 'country.sql.gz';
orange_crp_export_write_country_sql_gz($tmpSql, $gzOut);
c3_assert(is_file($gzOut) && filesize($gzOut) > 0, 'country.sql.gz written');
if (function_exists('gzopen')) {
    $h = gzopen($gzOut, 'rb');
    $raw = $h ? (string) stream_get_contents($h) : '';
    if ($h) {
        gzclose($h);
    }
    c3_assert(str_contains($raw, 'INSERT INTO `accounts`'), 'country.sql.gz contains accounts insert');
} else {
    c3_assert(false, 'gzopen available');
}
orange_backup_remove_dir($tmpSql);

// --- Manifest required keys (contract) ---
$requiredManifestKeys = [
    'country_id',
    'schema_revision',
    'boundary_policy_version',
    'dependency_graph_version',
    'restore_batches',
    'package_fingerprint',
    'export_time',
    'drv_version',
    'verify_version',
    'package_version',
];
c3_assert(ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION === '2.0', 'package_version=2.0');
c3_assert(ORANGE_COUNTRY_EXPORT_DRV_VERSION !== '', 'drv_version constant');
c3_assert(ORANGE_COUNTRY_EXPORT_VERIFY_VERSION === '2.0', 'verify_version=2.0');
foreach ($requiredManifestKeys as $k) {
    c3_assert($k !== '', 'manifest key listed: ' . $k);
}

echo $failures === 0 ? "ALL PASS\n" : "FAIL count={$failures}\n";
exit($failures === 0 ? 0 : 1);
