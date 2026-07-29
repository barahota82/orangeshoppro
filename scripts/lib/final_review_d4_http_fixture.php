<?php

declare(strict_types=1);

/**
 * FSR D4 Full Operational-Path — temporary local HTTP runtime (test-only).
 *
 * Main repo: develop/run suites from here.
 * Runtime worktree: D:\orange_d4_http_runtime (exact HEAD code + local .env.php only).
 * Disposable DB name must be orange_db (Production const DB_NAME) on 127.0.0.1 only.
 *
 * Never commit .env.php. Never modify config.php / DB_NAME.
 */

require_once __DIR__ . '/final_review_d4_fixture.php';

// CLI suites often run with stdout piped; disable block buffering so RESULT= is visible before cleanup.
if (PHP_SAPI === 'cli') {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    ob_implicit_flush(true);
}

const ORANGE_D4_HTTP_RUNTIME = 'D:\\orange_d4_http_runtime';
const ORANGE_D4_HTTP_DB = 'orange_db';
const ORANGE_D4_HTTP_APP_USER = 'orange_d4_http_app';

/**
 * Seal Production schema gate for disposable local orange_db only.
 * Same dump limitation as D2: id-renumber phase3 fails on tables without `id`
 * (e.g. orange_gl_settings). Flag + meta skip catch-up; not a Production seam.
 */
function orange_d4_http_seal_schema_gate(PDO $pdo, string $flagPath): void
{
    $rev = 124;
    if (defined('ORANGE_SCHEMA_CODE_VERSION')) {
        $rev = (int) ORANGE_SCHEMA_CODE_VERSION;
    } elseif (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
        $rev = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    }
    file_put_contents($flagPath, (string) $rev . "\n");
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS orange_schema_meta (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                version INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->prepare(
            'INSERT INTO orange_schema_meta (id, version) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version)'
        )->execute([$rev]);
    } catch (Throwable) {
        // best-effort
    }

    // Short-circuit pre-APCu integrity orchestrator (runs even when ok-flag matches).
    try {
        require_once dirname(__DIR__, 2) . '/includes/schema_migrations.php';
        if (function_exists('orange_schema_migrations_ensure_table')) {
            orange_schema_migrations_ensure_table($pdo);
        }
        $markers = [
            'php_stock_ledger_referential_integrity_v116_complete',
            'php_inventory_cost_referential_integrity_v117_complete',
            'php_variant_deletion_referential_integrity_v118_complete',
            'php_filter_column_indexes_go_live_v119_complete',
        ];
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_schema_migrations (filename, applied_at) VALUES (?, UTC_TIMESTAMP())'
        );
        foreach ($markers as $fn) {
            try {
                $ins->execute([$fn]);
            } catch (Throwable) {
                // column shape may differ — ignore
            }
        }
    } catch (Throwable) {
        // best-effort
    }
}

/**
 * @return array{
 *   ok:bool,
 *   runtime?:string,
 *   port?:int,
 *   base_url?:string,
 *   session_dir?:string,
 *   cookie_jar?:string,
 *   lock_path?:string,
 *   app_pass?:string,
 *   pdo?:PDO,
 *   ids?:array<string,int|string>,
 *   cleanup?:callable,
 *   error?:string,
 *   env?:string
 * }
 */
function orange_d4_http_bootstrap(string $mainRoot): array
{
    $runtime = ORANGE_D4_HTTP_RUNTIME;
    $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d4_http_exclusive.lock';
    $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d4_http_state_' . getmypid() . '.json';
    $schemaFlagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d4_http_schema_ok_orange_db.flag';

    $probe = orange_d1_mysql_probe();
    if (empty($probe['ok'])) {
        return ['ok' => false, 'error' => 'MySQL unavailable: ' . (string) ($probe['error'] ?? ''), 'env' => 'ENVIRONMENT_BLOCKED'];
    }

    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $existing = [];
    foreach ($admin->query('SHOW DATABASES') as $r) {
        $existing[] = (string) $r['Database'];
    }
    if (in_array(ORANGE_D4_HTTP_DB, $existing, true)) {
        return [
            'ok' => false,
            'error' => 'LOCAL_ORANGE_DB_ALREADY_EXISTS_OWNER_REVIEW_REQUIRED',
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }

    $lockFp = fopen($lockPath, 'c+');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockFp)) {
            fclose($lockFp);
        }

        return ['ok' => false, 'error' => 'exclusive D4 HTTP lock busy', 'env' => 'ENVIRONMENT_BLOCKED'];
    }
    ftruncate($lockFp, 0);
    fwrite($lockFp, (string) getmypid() . "\n" . gmdate('c') . "\n");
    fflush($lockFp);

    $serverProc = null;
    $sessionDir = '';
    $cookieJar = '';
    $appPass = bin2hex(random_bytes(12));
    $port = 0;
    $createdDb = false;
    $createdUser = false;

    $cleanup = static function () use (
        &$serverProc,
        &$sessionDir,
        &$cookieJar,
        &$createdDb,
        &$createdUser,
        $lockFp,
        $lockPath,
        $statePath,
        $schemaFlagPath,
        $runtime,
        $appPass
    ): void {
        if (is_resource($serverProc)) {
            $status = proc_get_status($serverProc);
            if (!empty($status['running']) && !empty($status['pid'])) {
                if (stripos(PHP_OS, 'WIN') === 0) {
                    exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
                } else {
                    proc_terminate($serverProc);
                }
            }
            // Avoid proc_close hang on Windows after taskkill (pipes already redirected to files).
            $serverProc = null;
        }
        try {
            $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            if ($createdDb) {
                // Callers must release their suite PDO before cleanup; still KILL leftovers.
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $kill = $admin->query(
                            "SELECT id FROM information_schema.processlist WHERE db = '" . ORANGE_D4_HTTP_DB . "'"
                        );
                        if ($kill) {
                            foreach ($kill->fetchAll(PDO::FETCH_COLUMN) as $pidKill) {
                                try {
                                    $admin->exec('KILL ' . (int) $pidKill);
                                } catch (Throwable) {
                                }
                            }
                        }
                    } catch (Throwable) {
                    }
                    try {
                        $admin->exec('DROP DATABASE IF EXISTS `' . ORANGE_D4_HTTP_DB . '`');
                        break;
                    } catch (Throwable) {
                        usleep(200000);
                    }
                }
            }
            if ($createdUser) {
                try {
                    $admin->exec("DROP USER IF EXISTS '" . ORANGE_D4_HTTP_APP_USER . "'@'localhost'");
                } catch (Throwable) {
                }
                try {
                    $admin->exec("DROP USER IF EXISTS '" . ORANGE_D4_HTTP_APP_USER . "'@'127.0.0.1'");
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }
        $envFile = $runtime . DIRECTORY_SEPARATOR . '.env.php';
        if (is_file($envFile)) {
            @unlink($envFile);
        }
        $sessHint = $runtime . DIRECTORY_SEPARATOR . '.d4_http_session_path';
        if (is_file($sessHint)) {
            @unlink($sessHint);
        }
        if ($sessionDir !== '' && is_dir($sessionDir)) {
            orange_d4_http_rrmdir($sessionDir);
        }
        if ($cookieJar !== '' && is_file($cookieJar)) {
            @unlink($cookieJar);
        }
        if (is_file($statePath)) {
            @unlink($statePath);
        }
        if (is_file($schemaFlagPath)) {
            @unlink($schemaFlagPath);
        }
        putenv('ORANGE_SCHEMA_OK_FLAG_PATH');
        unset($_ENV['ORANGE_SCHEMA_OK_FLAG_PATH']);
        if (is_resource($lockFp)) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
        @unlink($lockPath);
        // Wipe secrets from memory helper (no-op logging).
        unset($appPass);
    };

    try {
        if (!is_dir($runtime) || !is_file($runtime . DIRECTORY_SEPARATOR . 'config.php')) {
            throw new RuntimeException('runtime worktree missing at ' . $runtime);
        }

        // Sync Production files under test from the main working tree into the detached runtime
        // (worktree starts at committed HEAD; uncommitted/local repairs must be served by HTTP).
        $syncRels = [
            'api/cart/checkout-preview.php',
            'includes/phone_validation.php',
            'includes/order_intake_queue.php',
            'includes/catalog_bootstrap_store.php',
            'includes/catalog_schema.php',
            'includes/document_sequences.php',
        ];
        foreach ($syncRels as $rel) {
            $src = $mainRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dst = $runtime . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($src)) {
                throw new RuntimeException('missing sync source: ' . $rel);
            }
            if (!@copy($src, $dst)) {
                throw new RuntimeException('cannot sync into runtime: ' . $rel);
            }
        }
        $admin->exec(
            'CREATE DATABASE `' . ORANGE_D4_HTTP_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        $createdDb = true;

        $dumpPath = $mainRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'orange_db.sql';
        if (!is_file($dumpPath)) {
            throw new RuntimeException('scripts/orange_db.sql missing');
        }
        $raw = (string) file_get_contents($dumpPath);
        $raw = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/^USE\s+`?orange_db`?\s*;/mi', 'USE `' . ORANGE_D4_HTTP_DB . '`;', $raw) ?? $raw;
        $raw = "SET NAMES utf8mb4;\nUSE `" . ORANGE_D4_HTTP_DB . "`;\nSET FOREIGN_KEY_CHECKS=0;\n"
            . $raw . "\nSET FOREIGN_KEY_CHECKS=1;\n";
        $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d4_http_import_' . getmypid() . '.sql';
        file_put_contents($tmpSql, $raw);
        $mysql = orange_d1_mysql_bin();
        $descriptors = [0 => ['file', $tmpSql, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [$mysql, '--default-character-set=utf8mb4', '-h127.0.0.1', '-P3306', '-uroot', ORANGE_D4_HTTP_DB],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            @unlink($tmpSql);
            throw new RuntimeException('mysql import proc_open failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($tmpSql);
        if ($code !== 0) {
            throw new RuntimeException('mysql import failed: ' . trim((string) $stderr . "\n" . (string) $stdout));
        }

        // Restricted app user (HTTP path). Root remains for suite-side fixture control.
        try {
            $admin->exec("DROP USER IF EXISTS '" . ORANGE_D4_HTTP_APP_USER . "'@'localhost'");
        } catch (Throwable) {
        }
        try {
            $admin->exec("DROP USER IF EXISTS '" . ORANGE_D4_HTTP_APP_USER . "'@'127.0.0.1'");
        } catch (Throwable) {
        }
        $admin->exec("CREATE USER '" . ORANGE_D4_HTTP_APP_USER . "'@'localhost' IDENTIFIED BY " . $admin->quote($appPass));
        $admin->exec("CREATE USER '" . ORANGE_D4_HTTP_APP_USER . "'@'127.0.0.1' IDENTIFIED BY " . $admin->quote($appPass));
        $admin->exec('GRANT ALL PRIVILEGES ON `' . ORANGE_D4_HTTP_DB . '`.* TO \'' . ORANGE_D4_HTTP_APP_USER . '\'@\'localhost\'');
        $admin->exec('GRANT ALL PRIVILEGES ON `' . ORANGE_D4_HTTP_DB . '`.* TO \'' . ORANGE_D4_HTTP_APP_USER . '\'@\'127.0.0.1\'');
        $admin->exec('FLUSH PRIVILEGES');
        $createdUser = true;

        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=' . ORANGE_D4_HTTP_DB . ';charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET time_zone = '+00:00'");

        // Match D1/D4 disposable pattern: schema from scripts/orange_db.sql.
        // Seal ok-flag so HTTP ensure_schema skips dump-unsafe id-renumber catch-up (D2 note).
        orange_d1_load_production_helpers($mainRoot);
        require_once $mainRoot . '/includes/catalog_schema.php';
        if ((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION !== 124) {
            throw new RuntimeException('Schema revision constant not 124');
        }
        orange_d4_http_seal_schema_gate($pdo, $schemaFlagPath);

        $envPhp = "<?php\ndeclare(strict_types=1);\nreturn [\n"
            . "    'DB_USER' => " . var_export(ORANGE_D4_HTTP_APP_USER, true) . ",\n"
            . "    'DB_PASS' => " . var_export($appPass, true) . ",\n"
            . "    'STOREFRONT_FORCE_LONG_URLS' => true,\n"
            . "    'ORANGE_STOREFRONT_GEO_OVERRIDE' => 'kw',\n"
            . "    'DISABLE_HTML_CACHE' => true,\n"
            . "    'MAIL_FROM' => '',\n"
            . "    'HEALTH_CHECK_KEY' => 'd4_http_local_health_only',\n"
            . "    'ORANGE_SCHEMA_OK_FLAG_PATH' => " . var_export($schemaFlagPath, true) . ",\n"
            . "    'ORANGE_PRODUCTION' => false,\n"
            . "    'ORANGE_API_DEBUG' => '1',\n"
            . "];\n";
        if (file_put_contents($runtime . DIRECTORY_SEPARATOR . '.env.php', $envPhp) === false) {
            throw new RuntimeException('cannot write runtime .env.php');
        }

        try {
            orange_d1_truncate_business_tables($pdo);
            $ids = orange_d1_seed_core_fixture($pdo);
        } catch (Throwable $e) {
            throw new RuntimeException('seed_core failed: ' . $e->getMessage(), 0, $e);
        }
        try {
            orange_d4_load_production_helpers($mainRoot);
            $extra = orange_d4_seed_promo_loyalty_spine($pdo, $ids);
            $ids = array_merge($ids, $extra);
            orange_d4_http_seed_channel_products($pdo, $ids);
            orange_d4_verify_promo_loyalty_schema($pdo);
        } catch (Throwable $e) {
            throw new RuntimeException('seed_promo failed: ' . $e->getMessage(), 0, $e);
        }

        // Admin user for manual-order path
        if (orange_table_exists($pdo, 'admins')) {
            try {
                $hash = password_hash('d4http_admin_local', PASSWORD_DEFAULT);
                $cols = ['username', 'password_hash', 'display_name', 'is_active', 'is_superuser'];
                $vals = ['d4http_admin', $hash, 'D4 HTTP Admin', 1, 1];
                if (orange_table_has_column($pdo, 'admins', 'country_id')) {
                    $cols[] = 'country_id';
                    $vals[] = (int) ($ids['kw_country_id'] ?? 1);
                }
                if (orange_table_has_column($pdo, 'admins', 'created_at')) {
                    $cols[] = 'created_at';
                    $vals[] = gmdate('Y-m-d H:i:s');
                }
                $ph = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare('INSERT INTO admins (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
                $ids['admin_username'] = 'd4http_admin';
                $ids['admin_password'] = 'd4http_admin_local';
            } catch (Throwable $e) {
                throw new RuntimeException('seed_admin failed: ' . $e->getMessage(), 0, $e);
            }
        }

        $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d4_session_' . getmypid() . '_' . bin2hex(random_bytes(4));
        if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            throw new RuntimeException('cannot create session dir');
        }
        $cookieJar = $sessionDir . DIRECTORY_SEPARATOR . 'cookies.txt';
        file_put_contents($cookieJar, '');
        file_put_contents($runtime . DIRECTORY_SEPARATOR . '.d4_http_session_path', $sessionDir);

        $port = orange_d4_http_pick_free_port();
        $php = orange_d4_php_bin();
        $router = $mainRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib'
            . DIRECTORY_SEPARATOR . 'final_review_d4_http_router.php';
        // Router lives in main repo but document root is runtime worktree.
        $phpErrorLog = $sessionDir . DIRECTORY_SEPARATOR . 'php_error.log';
        file_put_contents($phpErrorLog, '');
        $cmd = [
            $php,
            '-d',
            'log_errors=1',
            '-d',
            'display_errors=0',
            '-d',
            'error_log=' . $phpErrorLog,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $runtime,
            $router,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $sessionDir . DIRECTORY_SEPARATOR . 'server.out.log', 'w'], 2 => ['file', $sessionDir . DIRECTORY_SEPARATOR . 'server.err.log', 'w']];
        // Inherit full process env (Windows needs SystemRoot/PATH). Flag is also in .env.php.
        putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $schemaFlagPath);
        $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $schemaFlagPath;
        putenv('ORANGE_API_DEBUG=1');
        $_ENV['ORANGE_API_DEBUG'] = '1';
        $serverProc = proc_open($cmd, $descriptors, $pipes, $runtime, null, ['bypass_shell' => true]);
        if (!is_resource($serverProc)) {
            throw new RuntimeException('cannot start PHP built-in server');
        }
        fclose($pipes[0]);
        usleep(350000);
        $status = proc_get_status($serverProc);
        if (empty($status['running'])) {
            throw new RuntimeException('PHP server exited early — see ' . $sessionDir . '/server.err.log');
        }

        $baseUrl = 'http://127.0.0.1:' . $port;
        $health = orange_d4_http_request($baseUrl . '/health.php?key=d4_http_local_health_only', 'GET', null, $cookieJar, []);
        if (($health['status'] ?? 0) < 200 || ($health['status'] ?? 0) >= 500) {
            throw new RuntimeException('health check failed status=' . (int) ($health['status'] ?? 0) . ' body=' . substr((string) ($health['body'] ?? ''), 0, 300));
        }
        if (!str_contains((string) ($health['body'] ?? ''), 'DB OK')) {
            throw new RuntimeException('health missing DB OK: ' . substr((string) ($health['body'] ?? ''), 0, 300));
        }

        file_put_contents($statePath, json_encode([
            'port' => $port,
            'runtime' => $runtime,
            'session_dir' => $sessionDir,
            'pid' => (int) ($status['pid'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));

        return [
            'ok' => true,
            'runtime' => $runtime,
            'port' => $port,
            'base_url' => $baseUrl,
            'session_dir' => $sessionDir,
            'cookie_jar' => $cookieJar,
            'lock_path' => $lockPath,
            'pdo' => $pdo,
            'ids' => $ids,
            'cleanup' => $cleanup,
            'env' => 'MYSQL_DISPOSABLE_ORANGE_DB_HTTP',
        ];
    } catch (Throwable $e) {
        $cleanup();

        return ['ok' => false, 'error' => $e->getMessage(), 'env' => 'ENVIRONMENT_BLOCKED'];
    }
}

/**
 * @param array<string,int|string> $ids
 */
function orange_d4_http_seed_channel_products(PDO $pdo, array $ids): void
{
    $kwCh = (int) ($ids['kw_channel_id'] ?? 1);
    $egCh = (int) ($ids['eg_channel_id'] ?? 2);
    $kw = (int) ($ids['kw_country_id'] ?? 1);
    $eg = (int) ($ids['eg_country_id'] ?? 2);

    // Unified-catalog chain requires department_countries for storefront product visibility.
    if (orange_table_exists($pdo, 'department_countries')) {
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO department_countries (department_id, country_id, is_active) VALUES (1, ?, 1), (1, ?, 1)'
            )->execute([$kw, $eg]);
        } catch (Throwable) {
            // optional column set may differ
        }
    }

    if (orange_table_exists($pdo, 'product_channels')) {
        $productIds = [500, 510, 511, 512, 501, 502];
        $st = $pdo->prepare('INSERT IGNORE INTO product_channels (product_id, channel_id) VALUES (?, ?)');
        foreach ($productIds as $pid) {
            $st->execute([$pid, $kwCh]);
            $st->execute([$pid, $egCh]);
        }
    }

    // Ensure countries have storefront codes (lowercase geo)
    if (orange_table_has_column($pdo, 'countries', 'code')) {
        $pdo->exec("UPDATE countries SET code = 'kw', is_active = 1 WHERE id = 1");
        $pdo->exec("UPDATE countries SET code = 'eg', is_active = 1 WHERE id = 2");
    }

    // Prefer explicit variant selection in cart when flags exist.
    if (orange_table_has_column($pdo, 'products', 'has_colors')) {
        try {
            $pdo->exec('UPDATE products SET has_colors = 1 WHERE id IN (500,510,511,512,501,502)');
        } catch (Throwable) {
        }
    }
}

function orange_d4_http_pick_free_port(): int
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException('cannot allocate free port: ' . $errstr);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        throw new RuntimeException('cannot parse free port');
    }

    return (int) $m[1];
}

function orange_d4_http_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            orange_d4_http_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @param array<string,string> $headers
 * @param array<string,mixed>|null $jsonBody
 * @return array{status:int, headers:string, body:string, json:?array, content_type:string}
 */
function orange_d4_http_request(
    string $url,
    string $method,
    ?array $jsonBody,
    string $cookieJar,
    array $extraHeaders = [],
    int $timeoutSec = 60
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders);
    $opts = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('curl error: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $json = json_decode($body, true);

    return [
        'status' => $status,
        'headers' => $headerStr,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
        'content_type' => $contentType,
    ];
}

/**
 * Visit a storefront page to establish channel/country cookies for subsequent API calls.
 */
function orange_d4_http_prime_channel(string $baseUrl, string $cookieJar, string $channelSlug, string $lang = 'en'): array
{
    $url = rtrim($baseUrl, '/') . '/pages/home.php?channel=' . rawurlencode($channelSlug) . '&lang=' . rawurlencode($lang);

    return orange_d4_http_request($url, 'GET', null, $cookieJar, ['Accept: text/html'], 30);
}

/**
 * @param list<array{product_id:int,variant_id:int,qty:int}> $lines
 * @return list<array<string,mixed>>
 */
function orange_d4_http_cart_items(array $lines): array
{
    $out = [];
    foreach ($lines as $ln) {
        // Production cart contract: product key is `id` (see storefront_cart_items.php).
        $out[] = [
            'id' => (int) ($ln['id'] ?? $ln['product_id'] ?? 0),
            'variant_id' => (int) ($ln['variant_id'] ?? 0),
            'qty' => (int) ($ln['qty'] ?? 1),
        ];
    }

    return $out;
}

/**
 * Standard guest checkout payload for KW.
 *
 * @param list<array<string,mixed>> $items
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function orange_d4_http_checkout_payload(
    array $items,
    int $channelId,
    string $phone,
    string $phoneCountryDial,
    int $deliveryAreaId = 1,
    array $extra = []
): array {
    // phone = national digits only; dial in phone_country (storefront policy «صفر»).
    $base = [
        'name' => 'D4 HTTP Guest',
        'phone' => $phone,
        'phone_country' => $phoneCountryDial,
        'area' => 'Kuwait City',
        'address' => 'D4 HTTP test address',
        'email' => '',
        'channel_id' => $channelId,
        'delivery_area_id' => $deliveryAreaId,
        'lang' => 'en',
        'payment_method' => 'cod',
        'items' => $items,
    ];

    return array_merge($base, $extra);
}
