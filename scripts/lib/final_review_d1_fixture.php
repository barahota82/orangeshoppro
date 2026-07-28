<?php

declare(strict_types=1);

/**
 * FSR Batch D1 — disposable MySQL fixture bootstrap (test-only).
 * Never points at Production BackupRoot / Production credentials from .env.php.
 */

/**
 * Load production helpers without requiring repo-root .env.php / config.php.
 */
function orange_d1_load_production_helpers(string $projectRoot): void
{
    require_once $projectRoot . '/includes/catalog_schema.php';
    require_once $projectRoot . '/includes/variant_pricing.php';
    require_once $projectRoot . '/includes/order_helpers.php';
    require_once $projectRoot . '/includes/order_fulfillment.php';
    require_once $projectRoot . '/includes/admin_time.php';
    require_once $projectRoot . '/includes/countries.php';
    require_once $projectRoot . '/includes/sales_doc_channel.php';
    require_once $projectRoot . '/includes/purchase_helpers.php';
    require_once $projectRoot . '/includes/purchase_return_helpers.php';
    require_once $projectRoot . '/includes/sales_return_helpers.php';
    require_once $projectRoot . '/includes/payments/payment_schema.php';
    require_once $projectRoot . '/includes/payments/payment_core.php';
    require_once $projectRoot . '/includes/payments/payment_gateway.php';
}

function orange_d1_mysql_bin(): string
{
    $candidates = [
        'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysql.exe',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }

    return 'mysql';
}

/**
 * @return array{ok:bool,dsn?:string,user?:string,pass?:string,error?:string}
 */
function orange_d1_mysql_probe(): array
{
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->query('SELECT 1');

        return ['ok' => true, 'dsn' => 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'user' => 'root', 'pass' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{
 *   ok:bool,
 *   pdo?:PDO,
 *   db_name?:string,
 *   cleanup?:callable,
 *   error?:string,
 *   env?:string
 * }
 */
function orange_d1_bootstrap_isolated_db(string $projectRoot): array
{
    $probe = orange_d1_mysql_probe();
    if (empty($probe['ok'])) {
        return [
            'ok' => false,
            'error' => 'MySQL unavailable: ' . (string) ($probe['error'] ?? 'unknown'),
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }

    $dumpPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'orange_db.sql';
    if (!is_file($dumpPath)) {
        return [
            'ok' => false,
            'error' => 'scripts/orange_db.sql missing — cannot build schema fixture',
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }

    $dbName = 'orange_d1_' . getmypid() . '_' . bin2hex(random_bytes(4));
    if (!preg_match('/^orange_d1_[a-zA-Z0-9_]+$/', $dbName)) {
        return ['ok' => false, 'error' => 'invalid disposable db name', 'env' => 'ENVIRONMENT_BLOCKED'];
    }

    try {
        $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $admin->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $raw = (string) file_get_contents($dumpPath);
        $raw = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/^USE\s+`?orange_db`?\s*;/mi', 'USE `' . $dbName . '`;', $raw) ?? $raw;
        // phpMyAdmin dump opens a transaction; keep statements sequential.
        $raw = "SET NAMES utf8mb4;\nUSE `{$dbName}`;\nSET FOREIGN_KEY_CHECKS=0;\n" . $raw . "\nSET FOREIGN_KEY_CHECKS=1;\n";

        $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $dbName . '.sql';
        if (file_put_contents($tmpSql, $raw) === false) {
            throw new RuntimeException('Cannot write sanitized dump');
        }

        $mysql = orange_d1_mysql_bin();
        $descriptors = [
            0 => ['file', $tmpSql, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [
            $mysql,
            '--default-character-set=utf8mb4',
            '-h127.0.0.1',
            '-P3306',
            '-uroot',
            $dbName,
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($tmpSql);
            $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
            throw new RuntimeException('Cannot start mysql import process');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ([1, 2] as $i) {
            if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                fclose($pipes[$i]);
            }
        }
        $code = proc_close($proc);
        @unlink($tmpSql);
        if ($code !== 0) {
            $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
            throw new RuntimeException(
                'mysql import failed (' . $code . '): ' . trim((string) $stderr . "\n" . (string) $stdout)
            );
        }

        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=' . $dbName . ';charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec('SET time_zone = \'+00:00\'');

        orange_d1_truncate_business_tables($pdo);
        $ids = orange_d1_seed_core_fixture($pdo);

        $cleanup = static function () use ($dbName): void {
            try {
                $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
            } catch (Throwable) {
                // best-effort
            }
        };

        return [
            'ok' => true,
            'pdo' => $pdo,
            'db_name' => $dbName,
            'cleanup' => $cleanup,
            'ids' => $ids,
            'env' => 'MYSQL_DISPOSABLE',
        ];
    } catch (Throwable $e) {
        try {
            $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        } catch (Throwable) {
        }

        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }
}

function orange_d1_truncate_business_tables(PDO $pdo): void
{
    // Wipe dump rows from ALL base tables (no Production data in D1 fixtures).
    // FK checks off so catalog trees can be rebuilt by the seed helper.
    $st = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    $tables = $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $t = (string) $table;
        if ($t === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $t)) {
            continue;
        }
        $pdo->exec('TRUNCATE TABLE `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

/**
 * Minimal KW/EG fixture. Same numeric IDs across countries where useful.
 *
 * @return array<string,int|string>
 */
function orange_d1_seed_core_fixture(PDO $pdo): array
{
    $now = gmdate('Y-m-d H:i:s');

    orange_d1_insert_if_table($pdo, 'countries', [
        [
            'id' => 1,
            'code' => 'KW',
            'name_ar' => 'الكويت',
            'name_en' => 'Kuwait',
            'currency_code' => 'KWD',
            'is_active' => 1,
            'sort_order' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 2,
            'code' => 'EG',
            'name_ar' => 'مصر',
            'name_en' => 'Egypt',
            'currency_code' => 'EGP',
            'is_active' => 1,
            'sort_order' => 2,
            'created_at' => $now,
        ],
    ]);

    orange_d1_insert_if_table($pdo, 'channels', [
        ['id' => 1, 'country_id' => 1, 'name' => 'KW Channel', 'slug' => 'kw-channel', 'is_active' => 1],
        ['id' => 2, 'country_id' => 2, 'name' => 'EG Channel', 'slug' => 'eg-channel', 'is_active' => 1],
        ['id' => 11, 'country_id' => 1, 'name' => 'KW-11', 'slug' => 'kw-11', 'is_active' => 1],
        ['id' => 12, 'country_id' => 2, 'name' => 'EG-12', 'slug' => 'eg-12', 'is_active' => 1],
    ]);

    orange_d1_insert_if_table($pdo, 'customers', [
        [
            'id' => 100,
            'country_id' => 1,
            'name_ar' => 'KW Customer',
            'phone' => '96550000001',
            'area' => 'Kuwait City',
            'address' => 'D1 KW address',
            'created_at' => $now,
        ],
        [
            'id' => 101,
            'country_id' => 2,
            'name_ar' => 'EG Customer',
            'phone' => '201000000001',
            'area' => 'Cairo',
            'address' => 'D1 EG address',
            'created_at' => $now,
        ],
    ]);

    orange_d1_insert_if_table($pdo, 'suppliers', [
        ['id' => 200, 'country_id' => 1, 'name' => 'KW Supplier', 'phone' => '96550000999', 'status' => 'active'],
        ['id' => 201, 'country_id' => 2, 'name' => 'EG Supplier', 'phone' => '20100000999', 'status' => 'active'],
    ]);

    orange_d1_insert_if_table($pdo, 'warehouses', [
        [
            'id' => 10,
            'country_id' => 1,
            'name_ar' => 'مخزن كويت',
            'name_en' => 'KW WH',
            'is_default' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 20,
            'country_id' => 2,
            'name_ar' => 'مخزن مصر',
            'name_en' => 'EG WH',
            'is_default' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);

    // Minimal catalog spine so products.product_type_id is satisfiable without Production rows.
    orange_d1_insert_if_table($pdo, 'departments', [
        ['id' => 1, 'slug' => 'd1-dept', 'name_ar' => 'قسم', 'name_en' => 'Dept', 'sort_order' => 1, 'is_active' => 1],
    ]);
    orange_d1_insert_if_table($pdo, 'catalog_sections', [
        [
            'id' => 1,
            'department_id' => 1,
            'slug' => 'd1-sec',
            'name_ar' => 'قسم',
            'name_en' => 'Sec',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);
    orange_d1_insert_if_table($pdo, 'catalog_categories', [
        [
            'id' => 1,
            'catalog_section_id' => 1,
            'slug' => 'd1-cat',
            'name_ar' => 'فئة',
            'name_en' => 'Cat',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);
    orange_d1_insert_if_table($pdo, 'catalog_subcategories', [
        [
            'id' => 1,
            'catalog_category_id' => 1,
            'slug' => 'd1-sub',
            'name_ar' => 'فرعي',
            'name_en' => 'Sub',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);
    orange_d1_insert_if_table($pdo, 'product_types', [
        [
            'id' => 1,
            'catalog_subcategory_id' => 1,
            'slug' => 'd1-type',
            'name_ar' => 'نوع',
            'name_en' => 'Type',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);

    orange_d1_insert_if_table($pdo, 'products', [
        [
            'id' => 500,
            'country_id' => 1,
            'name' => 'KW Product',
            'name_en' => 'KW Product',
            'product_type_id' => 1,
            'price' => 10.0000,
            'cost' => 4.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 501,
            'country_id' => 2,
            'name' => 'EG Product',
            'name_en' => 'EG Product',
            'product_type_id' => 1,
            'price' => 20.0000,
            'cost' => 8.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 502,
            'country_id' => 2,
            'name' => 'EG Product 502',
            'name_en' => 'EG Product 502',
            'product_type_id' => 1,
            'price' => 15.5000,
            'cost' => 6.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ]);

    orange_d1_insert_if_table($pdo, 'product_variants', [
        [
            'id' => 600,
            'product_id' => 500,
            'item_code' => 'KW-500-A',
            'price' => 10.0000,
            'cost' => 4.0000,
            'stock_quantity' => 100,
        ],
        [
            'id' => 601,
            'product_id' => 501,
            'item_code' => 'EG-501-A',
            'price' => 20.0000,
            'cost' => 8.0000,
            'stock_quantity' => 50,
        ],
        [
            'id' => 602,
            'product_id' => 502,
            'item_code' => 'EG-502-A',
            'price' => 15.5000,
            'cost' => 6.0000,
            'stock_quantity' => 40,
        ],
    ]);

    return [
        'kw_country_id' => 1,
        'eg_country_id' => 2,
        'kw_channel_id' => 1,
        'eg_channel_id' => 2,
        'kw_customer_id' => 100,
        'eg_customer_id' => 101,
        'kw_supplier_id' => 200,
        'eg_supplier_id' => 201,
        'kw_warehouse_id' => 10,
        'eg_warehouse_id' => 20,
        'kw_product_id' => 500,
        'eg_product_id' => 501,
        'kw_variant_id' => 600,
        'eg_variant_id' => 601,
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function orange_d1_insert_if_table(PDO $pdo, string $table, array $rows, bool $ignoreErrors = false): void
{
    if ($rows === []) {
        return;
    }
    $chk = $pdo->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "
        . $pdo->quote($table) . " LIMIT 1"
    );
    if (!$chk || !$chk->fetchColumn()) {
        return;
    }

    $colSt = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
    );
    $existing = [];
    foreach ($colSt ? ($colSt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [] as $c) {
        $existing[(string) $c] = true;
    }

    foreach ($rows as $row) {
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($row as $k => $v) {
            if (!isset($existing[$k])) {
                continue;
            }
            $cols[] = '`' . $k . '`';
            $vals[] = '?';
            $params[] = $v;
        }
        if ($cols === []) {
            continue;
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
        } catch (Throwable $e) {
            if (!$ignoreErrors) {
                throw $e;
            }
        }
    }
}

/**
 * Insert a minimal order row for payment/return tests.
 *
 * @return int order id
 */
function orange_d1_insert_order(
    PDO $pdo,
    int $countryId,
    int $channelId,
    string $orderNumber,
    float $total,
    string $status = 'pending',
    string $paymentStatus = 'unpaid'
): int {
    $cols = ['order_number', 'customer_name', 'phone', 'area', 'address', 'channel_id', 'status', 'total'];
    $vals = [$orderNumber, 'D1 Customer', '96550000001', 'area', 'addr', $channelId, $status, $total];
    if (orange_d1_has_column($pdo, 'orders', 'country_id')) {
        $cols[] = 'country_id';
        $vals[] = $countryId;
    }
    if (orange_d1_has_column($pdo, 'orders', 'payment_status')) {
        $cols[] = 'payment_status';
        $vals[] = $paymentStatus;
    }
    if (orange_d1_has_column($pdo, 'orders', 'created_at')) {
        $cols[] = 'created_at';
        $vals[] = gmdate('Y-m-d H:i:s');
    }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute($vals);

    return (int) $pdo->lastInsertId();
}

/**
 * @return int order_item id
 */
function orange_d1_insert_order_item(
    PDO $pdo,
    int $orderId,
    int $productId,
    int $variantId,
    int $qty,
    float $price
): int {
    $cols = ['order_id', 'product_id', 'qty', 'price'];
    $vals = [$orderId, $productId, $qty, $price];
    if (orange_d1_has_column($pdo, 'order_items', 'gl_slot')) {
        $slot = (int) $pdo->query(
            'SELECT COALESCE(MAX(gl_slot), 0) + 1 FROM order_items WHERE order_id = ' . (int) $orderId
        )->fetchColumn();
        $cols[] = 'gl_slot';
        $vals[] = max(1, $slot);
    }
    if (orange_d1_has_column($pdo, 'order_items', 'cost')) {
        $cols[] = 'cost';
        $vals[] = 0.0;
    }
    if (orange_d1_has_column($pdo, 'order_items', 'variant_id')) {
        $cols[] = 'variant_id';
        $vals[] = $variantId;
    }
    if (orange_d1_has_column($pdo, 'order_items', 'product_name')) {
        $cols[] = 'product_name';
        $vals[] = 'D1 item';
    }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare('INSERT INTO order_items (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);

    return (int) $pdo->lastInsertId();
}

function orange_d1_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $st->execute([$table, $column]);

    return (bool) $st->fetchColumn();
}

/**
 * @return int purchase id
 */
function orange_d1_insert_purchase(
    PDO $pdo,
    int $countryId,
    int $supplierId,
    float $total,
    string $type = 'cash'
): int {
    $cols = ['supplier_id', 'total', 'type'];
    $vals = [$supplierId, $total, $type];
    if (orange_d1_has_column($pdo, 'purchases', 'country_id')) {
        $cols[] = 'country_id';
        $vals[] = $countryId;
    }
    if (orange_d1_has_column($pdo, 'purchases', 'created_at')) {
        $cols[] = 'created_at';
        $vals[] = gmdate('Y-m-d H:i:s');
    }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare('INSERT INTO purchases (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);

    return (int) $pdo->lastInsertId();
}

function orange_d1_insert_purchase_item(
    PDO $pdo,
    int $purchaseId,
    int $productId,
    int $variantId,
    int $qty,
    float $cost
): int {
    $cols = ['purchase_id', 'product_id', 'qty', 'cost'];
    $vals = [$purchaseId, $productId, $qty, $cost];
    if (orange_d1_has_column($pdo, 'purchase_items', 'variant_id')) {
        $cols[] = 'variant_id';
        $vals[] = $variantId;
    }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare('INSERT INTO purchase_items (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);

    return (int) $pdo->lastInsertId();
}
