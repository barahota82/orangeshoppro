<?php

declare(strict_types=1);

/**
 * Country Recovery Platform — Final Quality Hardening (N3-01 … N3-07).
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_final_hardening.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('ORANGE_CRP_ALLOW_TEST_OVERRIDES', true);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/catalog_schema.php';
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow_verify.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_dry_run.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';

$passes = 0;
$failures = 0;

function fh_assert(bool $cond, string $msg): void
{
    global $passes, $failures;
    if ($cond) {
        echo "PASS: {$msg}\n";
        $passes++;
    } else {
        echo "FAIL: {$msg}\n";
        $failures++;
    }
}

fh_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'production restore remains disabled');
fh_assert(ORANGE_COUNTRY_SHADOW_ENGINE_VERSION === '1.3', 'C6 engine 1.3');
fh_assert(ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION === '1.3', 'C7 engine 1.3');
fh_assert(ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION === '1.3', 'C8 engine 1.3');
fh_assert(ORANGE_COUNTRY_SHADOW_MODEL === 'seeded_multicountry_target_slice', 'architecture unchanged');

// N3-06: overrides permitted in this self-test
fh_assert(orange_country_shadow_test_overrides_permitted() === true, 'N3-06 self-test permits overrides');
$GLOBALS['orange_country_shadow_production_db_override'] = 'fixture_prod_db';
fh_assert(orange_country_shadow_production_db_name($projectRoot) === 'fixture_prod_db', 'N3-06 override honored under permit');
unset($GLOBALS['orange_country_shadow_production_db_override']);

// N3-05 batch integrity
$matrix = orange_country_boundary_matrix_load($projectRoot);
$batchOk = orange_country_shadow_verify_batch_integrity(
    [
        'tables' => ['accounts', 'admins', 'admin_permissions'],
        'restore_batches' => orange_country_boundary_matrix_restore_batches($matrix),
    ],
    $matrix,
    []
);
fh_assert($batchOk['ok'] === true, 'N3-05 ordered batches PASS');

$batchBad = orange_country_shadow_verify_batch_integrity(
    [
        'tables' => ['admin_permissions', 'admins'], // child before parent / wrong order
        'restore_batches' => orange_country_boundary_matrix_restore_batches($matrix),
    ],
    $matrix,
    []
);
fh_assert($batchBad['ok'] === false, 'N3-05 wrong batch order FAIL');
fh_assert(in_array('batch_order_violation', $batchBad['codes'], true), 'N3-05 batch_order_violation code');

// N3-02 schema expectations load + revision
$expectations = orange_country_shadow_schema_expectations_load($projectRoot);
fh_assert(($expectations['schema_revision'] ?? 0) === (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION, 'N3-02 expectations schema_revision matches code');
fh_assert(isset($expectations['tables']['orders']['required_columns']), 'N3-02 orders required_columns present');

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, country_id INT)');
$pdo->exec('CREATE TABLE accounts (id INT PRIMARY KEY, country_id INT)');
$pdo->exec('CREATE TABLE warehouses (id INT PRIMARY KEY, country_id INT)');
$pdo->exec('CREATE TABLE products (id INT PRIMARY KEY, country_id INT)');
$schema = orange_country_shadow_verify_schema_drift(
    $pdo,
    $projectRoot,
    ['schema_revision' => (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION],
    $matrix,
    ['orders']
);
fh_assert($schema['ok'] === true, 'N3-02 schema drift PASS on matching revision+columns');

$schemaBad = orange_country_shadow_verify_schema_drift(
    $pdo,
    $projectRoot,
    ['schema_revision' => 1],
    array_merge($matrix, ['schema_revision' => 1]),
    ['orders']
);
fh_assert($schemaBad['ok'] === false, 'N3-02 schema revision mismatch FAIL');
fh_assert(in_array('schema_revision_mismatch', $schemaBad['codes'], true), 'N3-02 schema_revision_mismatch code');

// N3-07 empty uploads by inventory (no zip)
$emptyPkg = sys_get_temp_dir() . '/orange_fh_up_' . getmypid();
@mkdir($emptyPkg . '/files', 0777, true);
orange_backup_write_json($emptyPkg . '/manifest.json', [
    'uploads_file_count' => 0,
    'schema_revision' => 121,
]);
$upEmpty = orange_country_shadow_verify_uploads_integrity($emptyPkg);
fh_assert($upEmpty['ok'] === true, 'N3-07 empty uploads without zip PASS');
fh_assert(str_contains((string) $upEmpty['detail'], 'uploads_empty'), 'N3-07 empty detail marker');

// Missing zip without inventory proof → fail
$missingPkg = sys_get_temp_dir() . '/orange_fh_upm_' . getmypid();
@mkdir($missingPkg . '/files', 0777, true);
orange_backup_write_json($missingPkg . '/manifest.json', ['uploads_file_count' => 3]);
$upMissing = orange_country_shadow_verify_uploads_integrity($missingPkg);
fh_assert($upMissing['ok'] === false, 'N3-07 missing zip with expected files FAIL');
fh_assert(in_array('uploads_archive_missing', $upMissing['codes'], true), 'N3-07 uploads_archive_missing');

// Empty zip + count 0
orange_country_uploads_write_empty_zip($emptyPkg . '/files/uploads_country.zip');
$upZip = orange_country_shadow_verify_uploads_integrity($emptyPkg);
fh_assert($upZip['ok'] === true, 'N3-07 empty zip + inventory 0 PASS');

// N3-04 outside-target proof structure
$impact = orange_country_dry_run_build_outside_target_proof(
    [
        'survivor_counts' => ['orders' => 5, 'accounts' => 2],
        'global_counts' => ['journal_entries' => 1],
    ],
    ['orders'],
    ['orders' => ['classification' => 'Country Scoped', 'exportable' => true, 'restore_mode' => 'replace']],
    ['orders' => 1],
    []
);
fh_assert((int) $impact['survivor_country_impact'] === 0, 'N3-04 survivor impact 0');
fh_assert((int) $impact['global_impact'] === 0, 'N3-04 global impact 0');
fh_assert((int) $impact['journal_entries_impact'] === 0, 'N3-04 JE impact 0');
$proof = $impact['outside_target_impact_proof'];
fh_assert(($proof['method'] ?? '') === 'restore_plan_plus_certified_inventory', 'N3-04 proof method');
fh_assert(($proof['simulation_execution'] ?? true) === false, 'N3-04 no simulation execution');
fh_assert((int) ($proof['survivor_row_total'] ?? 0) === 7, 'N3-04 survivor_row_total enumerated');
fh_assert(str_contains((string) ($proof['survivor_proof'] ?? ''), 'certified_survivor'), 'N3-04 survivor proof text');

$impactFail = orange_country_dry_run_build_outside_target_proof(
    ['target_counts' => ['orders' => 1]],
    ['orders'],
    ['orders' => ['classification' => 'Country Scoped']],
    ['orders' => 1],
    []
);
fh_assert((int) $impactFail['survivor_country_impact'] === 1, 'N3-04 missing survivor inventory fails closed');

// N3-01 / N3-03 MySQL when available
function fh_mysql(): ?PDO
{
    foreach (['127.0.0.1', 'localhost'] as $host) {
        try {
            return new PDO("mysql:host={$host};port=3306;charset=utf8mb4", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable) {
        }
    }

    return null;
}

$mysql = fh_mysql();
if ($mysql === null) {
    echo "INFO: MySQL unavailable — skipping live N3-01/N3-03 DB checks\n";
    fh_assert(true, 'N3-01/N3-03 MySQL optional skip recorded');
} else {
    $db = 'orange_crp_fh_shadow';
    $mysql->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql->exec("USE `{$db}`");
    $mysql->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'orders', 'order_items', 'accounts', 'admins', 'admin_permissions',
        'warehouses', 'warehouse_variant_stock', 'inventory_cost_layers', 'inventory_cost_consumptions',
        'journal_entries', 'products', 'customers', 'journal_vouchers', 'journal_lines', 'document_sequences',
    ] as $t) {
        $mysql->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $mysql->exec('CREATE TABLE orders (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE order_items (id INT PRIMARY KEY, order_id INT NOT NULL)');
    $mysql->exec('CREATE TABLE accounts (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE admins (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE admin_permissions (id INT PRIMARY KEY, admin_id INT NOT NULL)');
    $mysql->exec('CREATE TABLE warehouses (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE warehouse_variant_stock (id INT PRIMARY KEY, warehouse_id INT NOT NULL)');
    $mysql->exec('CREATE TABLE inventory_cost_layers (id INT PRIMARY KEY, warehouse_id INT NULL, qty DECIMAL(18,4) DEFAULT 0, country_id INT NULL)');
    $mysql->exec('CREATE TABLE inventory_cost_consumptions (id INT PRIMARY KEY, layer_id INT NOT NULL, qty DECIMAL(18,4) DEFAULT 0)');
    $mysql->exec('CREATE TABLE journal_entries (id INT PRIMARY KEY, note VARCHAR(64) NULL)');
    $mysql->exec('CREATE TABLE products (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE customers (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE journal_vouchers (id INT PRIMARY KEY, country_id INT NULL)');
    $mysql->exec('CREATE TABLE journal_lines (id INT PRIMARY KEY, voucher_id INT NULL, debit DECIMAL(18,4) DEFAULT 0, credit DECIMAL(18,4) DEFAULT 0)');
    $mysql->exec('CREATE TABLE document_sequences (id INT PRIMARY KEY, scope VARCHAR(191) NOT NULL, next_value INT NOT NULL DEFAULT 1)');
    $mysql->exec('SET FOREIGN_KEY_CHECKS=1');

    $mysql->exec('INSERT INTO orders (id, country_id) VALUES (1,1),(2,2)');
    $mysql->exec('INSERT INTO warehouses (id, country_id) VALUES (1,1),(2,2)');
    $mysql->exec('INSERT INTO warehouse_variant_stock (id, warehouse_id) VALUES (1,1),(2,2)');
    $mysql->exec('INSERT INTO inventory_cost_layers (id, warehouse_id, qty, country_id) VALUES (1,1,5,1),(2,2,3,2)');
    $mysql->exec('INSERT INTO inventory_cost_consumptions (id, layer_id, qty) VALUES (1,1,1)');
    $mysql->exec("INSERT INTO journal_entries (id, note) VALUES (1,'g')");
    $mysql->exec('INSERT INTO accounts (id, country_id) VALUES (1,1),(2,2)');
    $mysql->exec('INSERT INTO products (id, country_id) VALUES (1,1)');
    $mysql->exec('INSERT INTO customers (id, country_id) VALUES (1,1)');

    $runDir = sys_get_temp_dir() . '/orange_fh_run_' . getmypid();
    @mkdir($runDir, 0777, true);
    $captured = orange_country_shadow_write_live_baselines($mysql, $runDir, 1, $projectRoot);
    fh_assert(($captured['capture_mode'] ?? '') === 'live', 'N3-01 baseline capture live');

    $pkg = sys_get_temp_dir() . '/orange_fh_pkg_' . getmypid();
    @mkdir($pkg . '/files', 0777, true);
    orange_backup_write_json($pkg . '/table_inventory.json', ['tables' => ['orders' => 1], 'uploads_file_count' => 0]);
    orange_backup_write_json($pkg . '/manifest.json', ['schema_revision' => 121, 'uploads_file_count' => 0]);
    orange_country_uploads_write_empty_zip($pkg . '/files/uploads_country.zip');

    $verify = orange_country_shadow_verify_target_slice(
        $mysql,
        $db,
        'orange_crp_fh_production_fence',
        1,
        ['schema_revision' => 121, 'country_id' => 1],
        [
            'tables' => ['orders'],
            'restore_batches' => orange_country_boundary_matrix_restore_batches($matrix),
        ],
        $pkg,
        $projectRoot,
        $runDir
    );
    fh_assert($verify['ok'] === true, 'N3-01 C6 verify with survivor revalidation PASS');
    $survCheck = false;
    foreach ($verify['checks'] as $chk) {
        if (($chk['id'] ?? '') === 'survivor_baseline_revalidate' && ($chk['status'] ?? '') === 'PASS') {
            $survCheck = true;
        }
    }
    fh_assert($survCheck, 'N3-01 survivor_baseline_revalidate check present');

    // Mutate survivor → fail N3-01
    $mysql->exec('DELETE FROM orders WHERE country_id = 2');
    $verifyFail = orange_country_shadow_verify_target_slice(
        $mysql,
        $db,
        'orange_crp_fh_production_fence',
        1,
        ['schema_revision' => 121, 'country_id' => 1],
        [
            'tables' => ['orders'],
            'restore_batches' => orange_country_boundary_matrix_restore_batches($matrix),
        ],
        $pkg,
        $projectRoot,
        $runDir
    );
    fh_assert($verifyFail['ok'] === false, 'N3-01 survivor deletion detected');
    fh_assert(in_array('survivor_country_row_deleted', $verifyFail['codes'], true), 'N3-01 survivor_country_row_deleted');

    // Restore survivor; inject cross-country FIFO
    $mysql->exec('INSERT INTO orders (id, country_id) VALUES (2,2)');
    $mysql->exec('INSERT INTO inventory_cost_layers (id, warehouse_id, qty, country_id) VALUES (3,2,1,1)');
    $stock = orange_country_shadow_verify_stock_fifo_ownership($mysql, 1);
    fh_assert($stock['ok'] === false, 'N3-03 cross-country FIFO FAIL');
    fh_assert(
        in_array('fifo_cross_country_reference', $stock['codes'], true)
        || in_array('stock_movement_leakage', $stock['codes'], true),
        'N3-03 cross-country ownership code'
    );

    // Clean FIFO path
    $mysql->exec('DELETE FROM inventory_cost_layers WHERE id = 3');
    $stockOk = orange_country_shadow_verify_stock_fifo_ownership($mysql, 1);
    fh_assert($stockOk['ok'] === true, 'N3-03 clean stock/FIFO PASS');
}

echo "Final hardening totals: pass={$passes} fail={$failures}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: Country CRP final hardening self-tests passed.\n";
exit(0);
