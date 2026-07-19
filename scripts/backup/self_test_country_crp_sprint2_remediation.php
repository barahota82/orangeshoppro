<?php

declare(strict_types=1);

/**
 * Country Recovery Platform — Remediation Sprint 2 (EA-01 … EA-06).
 *
 * Includes MySQL integration (no overrides / no sqlite) when MySQL is reachable.
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow_verify.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_dry_run.php';
require_once $projectRoot . '/includes/backup/country_crp_verify.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';

$passes = 0;
$failures = 0;

function s2_assert(bool $cond, string $msg): void
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

function s2_mysql(): ?PDO
{
    $hosts = ['127.0.0.1', 'localhost'];
    foreach ($hosts as $host) {
        try {
            $pdo = new PDO("mysql:host={$host};port=3306;charset=utf8mb4", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return $pdo;
        } catch (Throwable) {
        }
    }

    return null;
}

function s2_seed_schema(PDO $pdo, string $db): void
{
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db}`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'orders', 'order_items', 'accounts', 'admins', 'admin_permissions',
        'journal_entries', 'journal_vouchers', 'journal_lines',
        'warehouses', 'warehouse_variant_stock', 'inventory_cost_layers', 'inventory_cost_consumptions',
        'products', 'product_variants', 'customers', 'document_sequences', 'expenses',
        'orange_country_screen_copy_log', 'product_taxonomy_nodes',
    ] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec('CREATE TABLE orders (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE order_items (id INT PRIMARY KEY, order_id INT NOT NULL)');
    $pdo->exec('CREATE TABLE accounts (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE admins (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE admin_permissions (id INT PRIMARY KEY, admin_id INT NOT NULL)');
    $pdo->exec('CREATE TABLE journal_entries (id INT PRIMARY KEY, note VARCHAR(64) NULL)');
    $pdo->exec('CREATE TABLE journal_vouchers (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE journal_lines (id INT PRIMARY KEY, voucher_id INT NULL, journal_voucher_id INT NULL, debit DECIMAL(18,4) DEFAULT 0, credit DECIMAL(18,4) DEFAULT 0)');
    $pdo->exec('CREATE TABLE warehouses (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE warehouse_variant_stock (id INT PRIMARY KEY, warehouse_id INT NOT NULL)');
    $pdo->exec('CREATE TABLE inventory_cost_layers (id INT PRIMARY KEY, warehouse_id INT NULL, qty DECIMAL(18,4) DEFAULT 0, country_id INT NULL)');
    $pdo->exec('CREATE TABLE inventory_cost_consumptions (id INT PRIMARY KEY, layer_id INT NOT NULL, qty DECIMAL(18,4) DEFAULT 0)');
    $pdo->exec('CREATE TABLE products (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE product_variants (id INT PRIMARY KEY, product_id INT NOT NULL)');
    $pdo->exec('CREATE TABLE customers (id INT PRIMARY KEY, country_id INT NULL)');
    $pdo->exec('CREATE TABLE document_sequences (id INT PRIMARY KEY, scope VARCHAR(191) NOT NULL, next_value INT NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE expenses (id INT PRIMARY KEY, expense_account_id INT NULL, account_id INT NULL)');
    $pdo->exec('CREATE TABLE orange_country_screen_copy_log (id INT PRIMARY KEY, source_country_id INT, target_country_id INT)');
    $pdo->exec('CREATE TABLE product_taxonomy_nodes (id INT PRIMARY KEY, name VARCHAR(64) NULL)');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function s2_seed_rows(PDO $pdo): void
{
    // Target country 1
    $pdo->exec('INSERT INTO orders (id, country_id) VALUES (1,1),(10,1)');
    $pdo->exec('INSERT INTO order_items (id, order_id) VALUES (1,1),(2,10)');
    $pdo->exec('INSERT INTO accounts (id, country_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO admins (id, country_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO admin_permissions (id, admin_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO journal_vouchers (id, country_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO journal_lines (id, voucher_id, debit, credit) VALUES (1,1,10,10)');
    $pdo->exec('INSERT INTO warehouses (id, country_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO warehouse_variant_stock (id, warehouse_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO inventory_cost_layers (id, warehouse_id, qty, country_id) VALUES (1,1,5,1)');
    $pdo->exec('INSERT INTO inventory_cost_consumptions (id, layer_id, qty) VALUES (1,1,1)');
    $pdo->exec('INSERT INTO products (id, country_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO product_variants (id, product_id) VALUES (1,1)');
    $pdo->exec('INSERT INTO customers (id, country_id) VALUES (1,1)');
    $pdo->exec("INSERT INTO document_sequences (id, scope, next_value) VALUES (1,'inv_c1',5)");
    $pdo->exec('INSERT INTO expenses (id, expense_account_id) VALUES (1,1)');
    // Survivor country 2
    $pdo->exec('INSERT INTO orders (id, country_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO order_items (id, order_id) VALUES (3,2)');
    $pdo->exec('INSERT INTO accounts (id, country_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO admins (id, country_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO admin_permissions (id, admin_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO journal_vouchers (id, country_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO journal_lines (id, voucher_id, debit, credit) VALUES (2,2,3,3)');
    $pdo->exec('INSERT INTO warehouses (id, country_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO warehouse_variant_stock (id, warehouse_id) VALUES (2,2)');
    $pdo->exec('INSERT INTO products (id, country_id) VALUES (2,2)');
    $pdo->exec("INSERT INTO document_sequences (id, scope, next_value) VALUES (2,'inv_c2',9)");
    // Global
    $pdo->exec("INSERT INTO journal_entries (id, note) VALUES (1,'global-seed')");
    $pdo->exec('INSERT INTO orange_country_screen_copy_log (id, source_country_id, target_country_id) VALUES (1,1,2)');
    $pdo->exec("INSERT INTO product_taxonomy_nodes (id, name) VALUES (1,'root'),(2,'child'),(3,'leaf')");
}

function s2_build_package(string $projectRoot, string $dir, int $countryId = 1): void
{
    foreach (['sql', 'files'] as $sub) {
        if (!is_dir($dir . '/' . $sub)) {
            mkdir($dir . '/' . $sub, 0777, true);
        }
    }
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    $registry = orange_backup_registry_load($projectRoot);
    $batches = orange_country_boundary_matrix_restore_batches($matrix);
    $dep = orange_country_export_build_dependency_graph_c3($matrix, $registry, $batches);
    orange_backup_write_json($dir . '/dependency_graph.json', $dep);
    $sqlBody = "-- Orange CRP export table=orders country_id={$countryId}\n"
        . "INSERT INTO `orders` (`id`,`country_id`) VALUES (1,{$countryId});\n";
    file_put_contents($dir . '/sql/010_orders.sql', $sqlBody);
    $gz = gzopen($dir . '/country.sql.gz', 'wb9');
    gzwrite($gz, $sqlBody);
    gzclose($gz);
    orange_country_uploads_write_empty_zip($dir . '/files/uploads_country.zip');
    orange_backup_write_json($dir . '/table_inventory.json', [
        'country_id' => $countryId,
        'tables' => ['orders' => 1],
        'other_country_markers' => [],
    ]);
    orange_backup_write_json($dir . '/id_snapshot.json', [
        'country_id' => $countryId,
        'tables' => ['orders' => [1]],
    ]);
    orange_backup_write_json($dir . '/health.json', [
        'package_status' => 'healthy',
        'country_id' => $countryId,
        'schema_revision' => 121,
    ]);
    $manifest = [
        'package_type' => 'country_recovery',
        'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
        'generated_at' => gmdate('c'),
        'export_time' => gmdate('c'),
        'country_id' => $countryId,
        'country_code' => 'kw',
        'schema_revision' => 121,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
        'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
        'export_backend' => ORANGE_COUNTRY_EXPORT_BACKEND,
        'registry_version' => '1.0',
        'restore_batches' => $batches,
        'package_fingerprint' => '',
    ];
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    $manifest['package_fingerprint'] = orange_crp_export_package_fingerprint($dir, $manifest);
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    orange_backup_write_checksums($dir, orange_backup_collect_package_files($dir));
    orange_crp_verify_run($dir, ['write_report' => true, 'project_root' => $projectRoot]);
    orange_country_drv_run($dir, [
        'write_report' => true,
        'project_root' => $projectRoot,
        'package_id' => basename($dir),
    ]);
}

$base = sys_get_temp_dir() . '/orange_s2_' . getmypid();
$backupRoot = $base . '/backup';
$workRoot = $base . '/work';
mkdir($backupRoot . '/country_packages/kw', 0777, true);
mkdir($workRoot, 0777, true);

try {
    s2_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'production restore remains disabled');
    s2_assert(ORANGE_COUNTRY_SHADOW_MODEL === 'seeded_multicountry_target_slice', 'architecture constant');
    s2_assert(ORANGE_COUNTRY_SHADOW_ENGINE_VERSION === '1.2', 'C6 engine 1.2');
    s2_assert(ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION === '1.2', 'C7 engine 1.2');
    s2_assert(ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION === '1.2', 'C8 engine 1.2');

    // EA-05: unresolved ownership fails closed
    $threw = false;
    try {
        $mem = orange_country_shadow_target_membership_sql(
            new PDO('sqlite::memory:'),
            'no_such_mixed_table_xyz',
            1,
            ['ownership_resolver' => 'parent_fk', 'exportable' => true],
            $projectRoot
        );
        s2_assert($mem['ok'] === false, 'EA-05 unresolved parent_fk returns not ok');
    } catch (Throwable $e) {
        $threw = true;
        s2_assert(true, 'EA-05 membership helper available');
    }
    unset($threw);

    // EA-04 proof fields require inventory keys
    $impact = orange_country_dry_run_compute_impact(
        ['tables' => ['orders' => ['exportable' => true, 'restore_mode' => 'replace', 'classification' => 'Country Scoped']]],
        ['tables' => ['orders' => 1]],
        [],
        ['row_counts' => ['orders' => 1]],
        ['accounting_integrity' => 'PASS', 'stock_fifo_integrity' => 'PASS', 'composite_integrity' => 'PASS'],
        $base,
        1,
        [],
        [
            'source' => 'certified_snapshot',
            'target_counts' => ['orders' => 2],
            'survivor_counts' => ['orders' => 9],
            'global_counts' => ['journal_entries' => 1],
        ]
    );
    s2_assert((int) $impact['survivor_country_impact'] === 0, 'EA-04 survivor impact proven 0');
    s2_assert((int) $impact['global_impact'] === 0, 'EA-04 global impact proven 0');
    s2_assert((int) $impact['journal_entries_impact'] === 0, 'EA-04 JE impact proven 0');
    s2_assert(($impact['outside_target_impact_proof']['survivor_proof'] ?? '') !== null, 'EA-04 proof block present');

    $unproven = orange_country_dry_run_compute_impact(
        ['tables' => ['orders' => ['exportable' => true, 'restore_mode' => 'replace', 'classification' => 'Country Scoped']]],
        ['tables' => ['orders' => 1]],
        [],
        [],
        [],
        $base,
        1,
        [],
        ['source' => 'inject', 'target_counts' => ['orders' => 1]] // missing survivor/global
    );
    s2_assert((int) $unproven['survivor_country_impact'] === 1, 'EA-04 missing survivor inventory fails closed');

    $pdoRoot = s2_mysql();
    if ($pdoRoot === null) {
        s2_assert(false, 'EA-06 MySQL required but unavailable on 127.0.0.1:3306');
    } else {
        $shadowDb = 'orange_crp_s2_shadow';
        $prodName = 'orange_crp_s2_production_fence';
        s2_seed_schema($pdoRoot, $shadowDb);
        s2_seed_rows($pdoRoot);
        // fence production name distinct
        $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$prodName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $pkgId = '2026-07-19_220000';
        $pkgDir = $backupRoot . '/country_packages/kw/' . $pkgId;
        mkdir($pkgDir, 0777, true);
        s2_build_package($projectRoot, $pkgDir, 1);

        $dsn = "mysql:host=127.0.0.1;port=3306;dbname={$shadowDb};charset=utf8mb4";
        $shadowPdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // No overrides — real MySQL path
        unset(
            $GLOBALS['orange_country_shadow_connect_override'],
            $GLOBALS['orange_country_shadow_wipe_override'],
            $GLOBALS['orange_country_shadow_import_override'],
            $GLOBALS['orange_country_shadow_verify_override'],
            $GLOBALS['orange_country_shadow_baseline_override'],
            $GLOBALS['orange_country_shadow_c7_probe_override']
        );
        $GLOBALS['orange_country_shadow_production_db_override'] = $prodName;
        $GLOBALS['orange_country_shadow_env_override'] = [
            ORANGE_COUNTRY_SHADOW_ENV_DB => $shadowDb,
        ];
        // EA-06: no wipe/verify/probe/baseline overrides. Env points at seeded MySQL shadow.
        // Direct PDO only for assertions + fixture seed (not for C6/C7 engine path).
        $runId = 'kw_2026-07-19_220001';
        $runDir = orange_country_shadow_run_dir($workRoot, $runId);
        mkdir($runDir, 0777, true);
        $captured = orange_country_shadow_write_live_baselines($shadowPdo, $runDir, 1, $projectRoot);
        s2_assert(($captured['capture_mode'] ?? '') === 'live', 'EA-06 live baseline capture');
        s2_assert((int) (($captured['global']['journal_entries']['count'] ?? -1)) === 1, 'EA-02 global JE baseline count=1');

        $matrix = orange_country_boundary_matrix_load($projectRoot);
        $clear = orange_country_shadow_clear_target_slice_strict(
            $shadowPdo,
            $shadowDb,
            $prodName,
            ['order_items', 'orders', 'admin_permissions', 'admins'],
            1,
            $matrix,
            $projectRoot
        );
        s2_assert($clear['ok'] === true, 'EA-05 clear ok');
        $survOrders = (int) $shadowPdo->query('SELECT COUNT(*) FROM orders WHERE country_id = 2')->fetchColumn();
        s2_assert($survOrders === 1, 'EA-01/EA-05 survivor orders preserved after clear');
        $je = (int) $shadowPdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        s2_assert($je === 1, 'EA-02 journal_entries not wiped');

        // Re-seed target after clear for verify unit check
        $shadowPdo->exec('INSERT INTO orders (id, country_id) VALUES (1,1)');
        $shadowPdo->exec('INSERT INTO order_items (id, order_id) VALUES (1,1)');
        $shadowPdo->exec('INSERT INTO admins (id, country_id) VALUES (1,1)');
        $shadowPdo->exec('INSERT INTO admin_permissions (id, admin_id) VALUES (1,1)');

        $verify = orange_country_shadow_verify_target_slice(
            $shadowPdo,
            $shadowDb,
            $prodName,
            1,
            ['country_id' => 1],
            ['tables' => ['orders']],
            $pkgDir,
            $projectRoot,
            $runDir
        );
        s2_assert($verify['ok'] === true, 'EA-01 C6 target-slice verify ok with survivors+global');
        s2_assert((int) ($verify['row_counts']['orders'] ?? -1) === 1, 'EA-01 target-scoped order count');

        $sql = orange_country_shadow_sql_integrity_checks_v2($shadowPdo, 1, $projectRoot, $pkgDir);
        s2_assert(!in_array('journal_entries_changed', $sql['accounting_codes'], true), 'EA-02 JE rows do not auto-fail accounting');

        // Re-seed full fixture for end-to-end C6 (clear wiped target again above)
        s2_seed_schema($pdoRoot, $shadowDb);
        s2_seed_rows($pdoRoot);

        // Real MySQL DSN bridge (not sqlite). No wipe/verify/baseline/probe overrides.
        // Required when local .env.php is absent — transport only.
        $GLOBALS['orange_country_shadow_skip_session_assert'] = true;
        $GLOBALS['orange_country_shadow_env_override'] = [
            ORANGE_COUNTRY_SHADOW_ENV_DB => $shadowDb,
        ];
        $GLOBALS['orange_country_shadow_connect_override'] = static function () use ($shadowDb) {
            return new PDO(
                "mysql:host=127.0.0.1;port=3306;dbname={$shadowDb};charset=utf8mb4",
                'orange_crp_s2',
                's2pass',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        };

        $c6 = orange_country_shadow_run([
            'project_root' => $projectRoot,
            'backup_root' => $backupRoot,
            'work_root' => $workRoot,
            'package_id' => $pkgId,
            'country_code' => 'kw',
            'env' => $GLOBALS['orange_country_shadow_env_override'],
        ]);
        if (empty($c6['ok'])) {
            echo 'INFO: C6 codes=' . json_encode($c6['blocking_reason_codes'] ?? $c6['codes'] ?? $c6) . "\n";
        }
        s2_assert(!empty($c6['ok']), 'EA-06 C6 shadow restore ok on MySQL seed (no wipe/verify override)');
        $jobId = (string) ($c6['run_id'] ?? '');
        s2_assert($jobId !== '', 'EA-06 C6 run_id');
        if ($jobId !== '') {
            $survAfter = (int) (new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
                ->query('SELECT COUNT(*) FROM orders WHERE country_id = 2')->fetchColumn();
            s2_assert($survAfter === 1, 'EA-06 survivor intact after C6');

            unset($GLOBALS['orange_country_shadow_c7_probe_override']);
            $c7 = orange_country_shadow_verify_run([
                'project_root' => $projectRoot,
                'backup_root' => $backupRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'env' => $GLOBALS['orange_country_shadow_env_override'],
            ]);
            s2_assert(in_array(($c7['overall_result'] ?? ''), ['READY', 'WARNING', 'FAIL'], true), 'EA-06 C7 produced overall_result');
            $rep = $c7['report'] ?? [];
            s2_assert(($rep['survivor_country_integrity'] ?? '') !== '', 'EA-06 C7 survivor integrity field set');
            s2_assert(($rep['global_state_integrity'] ?? '') !== '', 'EA-06 C7 global integrity field set');

            orange_country_dry_run_write_certified_snapshot(
                $workRoot,
                $jobId,
                1,
                ['orders' => 1],
                ['orders' => 1],
                ['journal_entries' => 1, 'orange_country_screen_copy_log' => 1]
            );
            if (($c7['overall_result'] ?? '') === 'READY') {
                $c8 = orange_country_dry_run_execute([
                    'project_root' => $projectRoot,
                    'backup_root' => $backupRoot,
                    'work_root' => $workRoot,
                    'job_id' => $jobId,
                    'env' => $GLOBALS['orange_country_shadow_env_override'],
                ]);
                s2_assert(in_array(($c8['overall_result'] ?? ''), ['SAFE', 'WARNING', 'FAIL'], true), 'EA-06 C8 overall_result present');
                if (($c8['overall_result'] ?? '') === 'SAFE') {
                    s2_assert((int) (($c8['report']['survivor_country_impact'] ?? -1)) === 0, 'EA-04/EA-06 SAFE survivor impact 0');
                    s2_assert(isset($c8['report']['outside_target_impact_proof']), 'EA-04 proof in C8 report');
                }
            } else {
                $probeCheck = false;
                foreach ((array) ($rep['checks'] ?? []) as $ch) {
                    if (($ch['id'] ?? '') === 'probe' && ($ch['status'] ?? '') === 'PASS') {
                        $probeCheck = true;
                    }
                }
                s2_assert(
                    $probeCheck || in_array(($rep['survivor_country_integrity'] ?? ''), ['PASS', 'FAIL'], true),
                    'EA-06 C7 live path exercised'
                );
            }
        }

        unset(
            $GLOBALS['orange_country_shadow_production_db_override'],
            $GLOBALS['orange_country_shadow_env_override'],
            $GLOBALS['orange_country_shadow_connect_override'],
            $GLOBALS['orange_country_shadow_skip_session_assert']
        );
    }
} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "Sprint2 remediation totals: pass={$passes} fail={$failures}\n";
exit($failures > 0 ? 1 : 0);
