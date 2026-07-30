<?php

declare(strict_types=1);

/**
 * FSR Batch D5 — disposable Backup/Restore runtime (test-only).
 *
 * Detached worktree: D:\orange_d5_runtime
 * Data root:         D:\orange_d5_data\<pid>_<hex>\
 * Databases:         orange_db, orange_d5_stg_*, orange_d5_shd_*, orange_d5_fsh_*
 *
 * Never touches Production BackupRoot, Production .env.php, or pre-existing DBs.
 */

require_once __DIR__ . '/final_review_d1_fixture.php';

const ORANGE_D5_RUNTIME = 'D:\\orange_d5_runtime';
const ORANGE_D5_DATA_PARENT = 'D:\\orange_d5_data';

/**
 * @return array{
 *   ok:bool,
 *   error?:string,
 *   runtime_root?:string,
 *   data_root?:string,
 *   backup_root?:string,
 *   restore_root?:string,
 *   media_root?:string,
 *   src_db?:string,
 *   stg_db?:string,
 *   shd_db?:string,
 *   app_user?:string,
 *   stg_user?:string,
 *   app_pass?:string,
 *   stg_pass?:string,
 *   pdo?:PDO,
 *   ids?:array<string,mixed>,
 *   created_dbs?:list<string>,
 *   created_users?:list<string>,
 *   lock_path?:string,
 *   cleanup?:callable
 * }
 */
function orange_d5_bootstrap(string $mainRoot): array
{
    $mainRoot = realpath($mainRoot) ?: $mainRoot;
    $probe = orange_d1_mysql_probe();
    if (empty($probe['ok'])) {
        return ['ok' => false, 'error' => 'MySQL unavailable: ' . (string) ($probe['error'] ?? '')];
    }

    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Refuse to proceed if fixed orange_db already exists (must not drop foreign DBs).
    $hasOrange = $admin->query("SHOW DATABASES LIKE 'orange_db'")->fetchColumn();
    if ($hasOrange !== false && $hasOrange !== '') {
        return [
            'ok' => false,
            'error' => 'Pre-existing orange_db found — D5 refuses to use or drop it (not created by this run).',
        ];
    }

    $token = getmypid() . '_' . bin2hex(random_bytes(4));
    $dataRoot = ORANGE_D5_DATA_PARENT . DIRECTORY_SEPARATOR . $token;
    $backupRoot = $dataRoot . DIRECTORY_SEPARATOR . 'backups';
    $restoreRoot = $dataRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $mediaRoot = $dataRoot . DIRECTORY_SEPARATOR . 'uploads';
    $lockPath = $dataRoot . DIRECTORY_SEPARATOR . 'd5_runtime.lock';

    foreach ([$dataRoot, $backupRoot, $restoreRoot, $mediaRoot, $mediaRoot . DIRECTORY_SEPARATOR . 'products'] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Cannot create D5 data dir: ' . $dir];
        }
    }

    $lockFh = @fopen($lockPath, 'c+b');
    if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'error' => 'Cannot acquire exclusive D5 runtime lock'];
    }
    fwrite($lockFh, json_encode(['pid' => getmypid(), 'started_at' => gmdate('c')], JSON_UNESCAPED_SLASHES) ?: '{}');
    fflush($lockFh);

    $runtimeRoot = ORANGE_D5_RUNTIME;
    if (!is_dir($runtimeRoot)) {
        return ['ok' => false, 'error' => 'Detached runtime missing: ' . $runtimeRoot . ' (create via git worktree)'];
    }
    // Sync runtime to current main HEAD files for test helpers (worktree may lag if created earlier).
    // Prefer reading helpers from $mainRoot; runtime holds .env.php + uploads only.

    // Production config.php hardcodes const DB_NAME = 'orange_db' — use that fixed
    // disposable name only after proving it does not already exist (checked above).
    $srcDb = 'orange_db';
    $stgDb = 'orange_d5_stg_' . $token;
    $shdDb = 'orange_d5_shd_' . $token;
    // Full-disaster shadow placeholder — must differ from Country Shadow (C7 identity fence).
    $fullShdDb = 'orange_d5_fsh_' . $token;
    $appUser = 'orange_d5_app_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $stgUser = 'orange_d5_stg_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $appPass = 'd5_app_' . bin2hex(random_bytes(8));
    $stgPass = 'd5_stg_' . bin2hex(random_bytes(8));

    if ($srcDb !== 'orange_db'
        || !preg_match('/^orange_d5_(stg|shd|fsh)_[a-zA-Z0-9_]+$/', $stgDb)
        || !preg_match('/^orange_d5_(stg|shd|fsh)_[a-zA-Z0-9_]+$/', $shdDb)
        || !preg_match('/^orange_d5_(stg|shd|fsh)_[a-zA-Z0-9_]+$/', $fullShdDb)
    ) {
        return ['ok' => false, 'error' => 'Invalid D5 database name'];
    }

    $createdDbs = [];
    $createdUsers = [];

    try {
        foreach ([$srcDb, $stgDb, $shdDb, $fullShdDb] as $db) {
            $exists = $admin->query("SHOW DATABASES LIKE " . $admin->quote($db))->fetchColumn();
            if ($exists !== false && $exists !== '') {
                throw new RuntimeException('Refusing to reuse pre-existing DB: ' . $db);
            }
            $admin->exec(
                'CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $createdDbs[] = $db;
        }

        foreach ([$appUser, $stgUser] as $user) {
            foreach (['localhost', '127.0.0.1'] as $host) {
                try {
                    $admin->exec("CREATE USER '{$user}'@'{$host}' IDENTIFIED BY " . $admin->quote(
                        $user === $appUser ? $appPass : $stgPass
                    ));
                    $createdUsers[] = $user . '@' . $host;
                } catch (Throwable $e) {
                    if (!str_contains($e->getMessage(), 'exists')) {
                        throw $e;
                    }
                }
            }
        }
        $admin->exec("GRANT ALL PRIVILEGES ON `{$srcDb}`.* TO '{$appUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$srcDb}`.* TO '{$appUser}'@'127.0.0.1'");
        $mergeUser = 'orange_d5_mrg_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $mergePass = 'd5_mrg_' . bin2hex(random_bytes(8));
        foreach (['localhost', '127.0.0.1'] as $host) {
            try {
                $admin->exec("CREATE USER '{$mergeUser}'@'{$host}' IDENTIFIED BY " . $admin->quote($mergePass));
                $createdUsers[] = $mergeUser . '@' . $host;
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'exists')) {
                    throw $e;
                }
            }
        }

        $admin->exec("GRANT ALL PRIVILEGES ON `{$stgDb}`.* TO '{$stgUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$stgDb}`.* TO '{$stgUser}'@'127.0.0.1'");
        // Country Shadow connect uses staging credentials against ORANGE_RESTORE_COUNTRY_SHADOW_DB.
        $admin->exec("GRANT ALL PRIVILEGES ON `{$shdDb}`.* TO '{$stgUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$shdDb}`.* TO '{$stgUser}'@'127.0.0.1'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$fullShdDb}`.* TO '{$stgUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$fullShdDb}`.* TO '{$stgUser}'@'127.0.0.1'");
        // App user also needs CREATE on staging/shadow for some cutover helpers in tests.
        $admin->exec("GRANT ALL PRIVILEGES ON `{$stgDb}`.* TO '{$appUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$stgDb}`.* TO '{$appUser}'@'127.0.0.1'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$shdDb}`.* TO '{$appUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$shdDb}`.* TO '{$appUser}'@'127.0.0.1'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$fullShdDb}`.* TO '{$appUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$fullShdDb}`.* TO '{$appUser}'@'127.0.0.1'");
        // Merge user: production (disposable orange_db) only — never staging.
        $admin->exec("GRANT ALL PRIVILEGES ON `{$srcDb}`.* TO '{$mergeUser}'@'localhost'");
        $admin->exec("GRANT ALL PRIVILEGES ON `{$srcDb}`.* TO '{$mergeUser}'@'127.0.0.1'");
        $admin->exec('FLUSH PRIVILEGES');

        // Import schema from orange_db.sql into src DB (same path as D1).
        $boot = orange_d5_import_schema_and_seed($mainRoot, $srcDb, $mediaRoot);
        if (empty($boot['ok'])) {
            throw new RuntimeException((string) ($boot['error'] ?? 'seed failed'));
        }
        /** @var PDO $pdo */
        $pdo = $boot['pdo'];
        /** @var array<string,mixed> $ids */
        $ids = $boot['ids'];

        // Seed disposable Super Admin for approval/cutover re-auth (test-only).
        // Prefer a non-NULL country_id so Country Shadow EA-01 null-ownership checks
        // on country-scoped admins are not tripped by a global NULL survivor row.
        $adminPassPlain = 'd5_admin_' . bin2hex(random_bytes(6));
        $adminId = 0;
        try {
            $hash = password_hash($adminPassPlain, PASSWORD_DEFAULT);
            if (orange_table_exists($pdo, 'admins')) {
                $kwSeed = 0;
                try {
                    $kwSeed = (int) $pdo->query(
                        "SELECT id FROM countries WHERE code IN ('KW','kw') ORDER BY id ASC LIMIT 1"
                    )->fetchColumn();
                } catch (Throwable) {
                    $kwSeed = 0;
                }
                if (orange_table_has_column($pdo, 'admins', 'country_id') && $kwSeed > 0) {
                    $pdo->prepare(
                        'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser, country_id)
                         VALUES (?, ?, ?, 1, 1, ?)'
                    )->execute(['d5_restore_admin', $hash, 'D5 Restore Admin', $kwSeed]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser)
                         VALUES (?, ?, ?, 1, 1)'
                    )->execute(['d5_restore_admin', $hash, 'D5 Restore Admin']);
                }
                $adminId = (int) $pdo->lastInsertId();
            }
        } catch (Throwable) {
            $adminId = 0;
        }

        orange_d5_write_runtime_env($runtimeRoot, [
            'DB_HOST' => '127.0.0.1',
            'DB_NAME' => $srcDb,
            'DB_USER' => $appUser,
            'DB_PASS' => $appPass,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_RESTORE_WORK_DIR' => $restoreRoot,
            'ORANGE_RESTORE_STAGING_DB' => $stgDb,
            'ORANGE_RESTORE_STAGING_DB_USER' => $stgUser,
            'ORANGE_RESTORE_STAGING_DB_PASS' => $stgPass,
            // Full-disaster shadow name must differ from Country Shadow (C7 fence).
            'ORANGE_RESTORE_SHADOW_DB' => $fullShdDb,
            'ORANGE_RESTORE_COUNTRY_SHADOW_DB' => $shdDb,
            'ORANGE_RESTORE_MERGE_DB_USER' => $mergeUser,
            'ORANGE_RESTORE_MERGE_DB_PASS' => $mergePass,
            // Disposable Super Admin for approval/cutover re-auth (never Production).
            'ORANGE_D5_ADMIN_ID' => $adminId,
            'ORANGE_D5_ADMIN_PASS' => $adminPassPlain,
            // Force php_pdo for fresh-backup gate + restore-compatible packages:
            // invalid configured paths disable PowerShell/mysqldump selection (no auto-discovery).
            'ORANGE_BACKUP_POWERSHELL_PATH' => 'Z:\\d5_missing\\powershell.exe',
            'ORANGE_MYSQLDUMP_PATH' => 'Z:\\d5_missing\\mysqldump.exe',
            'ORANGE_ENVIRONMENT_NAME' => 'd5_disposable_' . $token,
        ]);

        // Point runtime uploads to our media root via junction/symlink or copy placeholder.
        $runtimeUploads = $runtimeRoot . DIRECTORY_SEPARATOR . 'uploads';
        if (is_dir($runtimeUploads) && !is_link($runtimeUploads)) {
            // Keep worktree uploads; also mirror sentinel media into runtime uploads.
            orange_d5_mirror_dir($mediaRoot, $runtimeUploads);
        } else {
            if (!is_dir($runtimeUploads)) {
                @mkdir($runtimeUploads, 0775, true);
            }
            orange_d5_mirror_dir($mediaRoot, $runtimeUploads);
        }

        $cleanup = static function () use (
            $admin,
            $createdDbs,
            $createdUsers,
            $dataRoot,
            $runtimeRoot,
            $lockFh,
            $lockPath
        ): void {
            try {
                foreach ($createdDbs as $db) {
                    // Drop only DBs created by this run (orange_db source + orange_d5_*).
                    if ($db === 'orange_db' || preg_match('/^orange_d5_(stg|shd|fsh)_/', $db)) {
                        $admin->exec('DROP DATABASE IF EXISTS `' . $db . '`');
                    }
                }
                foreach ($createdUsers as $spec) {
                    [$u, $h] = explode('@', $spec, 2);
                    if (str_starts_with($u, 'orange_d5_')) {
                        try {
                            $admin->exec("DROP USER IF EXISTS '{$u}'@'{$h}'");
                        } catch (Throwable) {
                        }
                    }
                }
                $admin->exec('FLUSH PRIVILEGES');
            } catch (Throwable) {
            }
            $envFile = $runtimeRoot . DIRECTORY_SEPARATOR . '.env.php';
            if (is_file($envFile)) {
                @unlink($envFile);
            }
            if (is_resource($lockFh)) {
                @flock($lockFh, LOCK_UN);
                @fclose($lockFh);
            }
            @unlink($lockPath);
            orange_d5_rmdir_safe($dataRoot);
        };

        return [
            'ok' => true,
            'runtime_root' => $runtimeRoot,
            'main_root' => $mainRoot,
            'data_root' => $dataRoot,
            'backup_root' => $backupRoot,
            'restore_root' => $restoreRoot,
            'media_root' => $mediaRoot,
            'src_db' => $srcDb,
            'stg_db' => $stgDb,
            'shd_db' => $shdDb,
            'full_shd_db' => $fullShdDb,
            'app_user' => $appUser,
            'stg_user' => $stgUser,
            'merge_user' => $mergeUser,
            'app_pass' => $appPass,
            'stg_pass' => $stgPass,
            'merge_pass' => $mergePass,
            'admin_id' => $adminId,
            'admin_username' => 'd5_restore_admin',
            'admin_pass' => $adminPassPlain,
            'pdo' => $pdo,
            'ids' => $ids,
            'created_dbs' => $createdDbs,
            'created_users' => $createdUsers,
            'lock_path' => $lockPath,
            'cleanup' => $cleanup,
        ];
    } catch (Throwable $e) {
        foreach ($createdDbs as $db) {
            if ($db === 'orange_db' || preg_match('/^orange_d5_(stg|shd)_/', $db)) {
                try {
                    $admin->exec('DROP DATABASE IF EXISTS `' . $db . '`');
                } catch (Throwable) {
                }
            }
        }
        foreach ($createdUsers as $spec) {
            [$u, $h] = explode('@', $spec, 2);
            if (str_starts_with($u, 'orange_d5_')) {
                try {
                    $admin->exec("DROP USER IF EXISTS '{$u}'@'{$h}'");
                } catch (Throwable) {
                }
            }
        }
        if (is_resource($lockFh)) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
        orange_d5_rmdir_safe($dataRoot);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @param array<string,string|int|bool> $env
 */
function orange_d5_write_runtime_env(string $runtimeRoot, array $env): void
{
    $lines = ["<?php\n", "declare(strict_types=1);\n", "\nreturn [\n"];
    foreach ($env as $k => $v) {
        if (is_bool($v)) {
            $lines[] = '    ' . var_export((string) $k, true) . ' => ' . ($v ? 'true' : 'false') . ",\n";
        } elseif (is_int($v)) {
            $lines[] = '    ' . var_export((string) $k, true) . ' => ' . $v . ",\n";
        } else {
            $lines[] = '    ' . var_export((string) $k, true) . ' => ' . var_export((string) $v, true) . ",\n";
        }
    }
    $lines[] = "];\n";
    $path = $runtimeRoot . DIRECTORY_SEPARATOR . '.env.php';
    if (file_put_contents($path, implode('', $lines)) === false) {
        throw new RuntimeException('Cannot write runtime .env.php');
    }
}

/**
 * @return array{ok:bool,pdo?:PDO,ids?:array<string,mixed>,error?:string}
 */
function orange_d5_import_schema_and_seed(string $projectRoot, string $dbName, string $mediaRoot): array
{
    $dumpPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'orange_db.sql';
    if (!is_file($dumpPath)) {
        return ['ok' => false, 'error' => 'scripts/orange_db.sql missing'];
    }

    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $raw = (string) file_get_contents($dumpPath);
    $raw = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $raw) ?? $raw;
    $raw = preg_replace('/^USE\s+`?orange_db`?\s*;/mi', 'USE `' . $dbName . '`;', $raw) ?? $raw;
    $raw = "SET NAMES utf8mb4;\nUSE `{$dbName}`;\nSET FOREIGN_KEY_CHECKS=0;\n" . $raw . "\nSET FOREIGN_KEY_CHECKS=1;\n";
    $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $dbName . '.sql';
    file_put_contents($tmpSql, $raw);

    $mysql = orange_d1_mysql_bin();
    $descriptors = [0 => ['file', $tmpSql, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = [$mysql, '--default-character-set=utf8mb4', '-h127.0.0.1', '-P3306', '-uroot', $dbName];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        @unlink($tmpSql);

        return ['ok' => false, 'error' => 'Cannot start mysql import'];
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
        return ['ok' => false, 'error' => 'mysql import failed: ' . trim((string) $stderr . "\n" . (string) $stdout)];
    }

    $pdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . $dbName . ';charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('SET NAMES utf8mb4');
    orange_d1_load_production_helpers($projectRoot);
    orange_d1_truncate_business_tables($pdo);
    $ids = orange_d1_seed_core_fixture($pdo);
    orange_d5_seed_sentinels($pdo, $ids, $mediaRoot);
    // Bring dump-based fixture to Schema 124 Country-export columns that mysqldump
    // snapshot may lack (Production applies these via catalog_schema migrations).
    require_once $projectRoot . '/includes/catalog_schema.php';
    try {
        if (function_exists('orange_catalog_migrate_admin_time_country_authority_v123')) {
            orange_catalog_migrate_admin_time_country_authority_v123($pdo);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'schema patch v123 failed: ' . $e->getMessage()];
    }
    // Dump may contain global admins with NULL country_id; Country Shadow EA-01
    // treats NULL on country-scoped import tables as leakage. Reassign or drop them.
    try {
        if (orange_table_exists($pdo, 'admins') && orange_table_has_column($pdo, 'admins', 'country_id')) {
            $kwFix = (int) $pdo->query(
                "SELECT id FROM countries WHERE code IN ('KW','kw') ORDER BY id ASC LIMIT 1"
            )->fetchColumn();
            if ($kwFix > 0) {
                $pdo->prepare('UPDATE admins SET country_id = ? WHERE country_id IS NULL')->execute([$kwFix]);
            }
        }
    } catch (Throwable) {
    }
    orange_d5_seal_schema_gate($pdo, $projectRoot);

    return ['ok' => true, 'pdo' => $pdo, 'ids' => $ids];
}

/**
 * Seal Schema 124 for disposable local DB (same dump limitation as D2/D4:
 * id-renumber phase fails on tables without `id`). Test-only; not a Production seam.
 */
function orange_d5_seal_schema_gate(PDO $pdo, string $projectRoot): void
{
    $rev = 124;
    if (defined('ORANGE_SCHEMA_CODE_VERSION')) {
        $rev = (int) ORANGE_SCHEMA_CODE_VERSION;
    } elseif (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
        $rev = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    }

    $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_' . getmypid() . '.flag';
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
    }

    try {
        require_once $projectRoot . '/includes/schema_migrations.php';
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
            }
        }
    } catch (Throwable) {
    }
}

/**
 * @param array<string,mixed> $ids
 */
function orange_d5_seed_sentinels(PDO $pdo, array $ids, string $mediaRoot): void
{
    $kw = (int) ($ids['kw_country_id'] ?? 1);
    $eg = (int) ($ids['eg_country_id'] ?? 2);

    // Sentinel media files (Country-tagged names).
    $kwMedia = $mediaRoot . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'D5_KW_SENTINEL.txt';
    $egMedia = $mediaRoot . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'D5_EG_SENTINEL.txt';
    @mkdir(dirname($kwMedia), 0775, true);
    file_put_contents($kwMedia, 'D5_KW_MEDIA_' . $kw . "\n");
    file_put_contents($egMedia, 'D5_EG_MEDIA_' . $eg . "\n");

    // Soft-reference friendly channel slugs if columns exist.
    if (orange_table_exists($pdo, 'channels') && orange_table_has_column($pdo, 'channels', 'slug')) {
        $pdo->prepare('UPDATE channels SET slug = ? WHERE country_id = ? LIMIT 1')
            ->execute(['d5-kw-channel', $kw]);
        $pdo->prepare('UPDATE channels SET slug = ? WHERE country_id = ? LIMIT 1')
            ->execute(['d5-eg-channel', $eg]);
    }

    // Tag a customer note / name with sentinel if possible.
    if (orange_table_exists($pdo, 'customers')) {
        if (orange_table_has_column($pdo, 'customers', 'name_ar')) {
            $pdo->prepare("UPDATE customers SET name_ar = CONCAT('D5_KW_', name_ar) WHERE country_id = ? LIMIT 1")
                ->execute([$kw]);
            $pdo->prepare("UPDATE customers SET name_ar = CONCAT('D5_EG_', name_ar) WHERE country_id = ? LIMIT 1")
                ->execute([$eg]);
        }
    }
}

function orange_d5_mirror_dir(string $from, string $to): void
{
    if (!is_dir($from)) {
        return;
    }
    if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $rel = substr($item->getPathname(), strlen($from) + 1);
        $dest = $to . DIRECTORY_SEPARATOR . $rel;
        if ($item->isDir()) {
            if (!is_dir($dest)) {
                @mkdir($dest, 0775, true);
            }
        } else {
            @copy($item->getPathname(), $dest);
        }
    }
}

function orange_d5_rmdir_safe(string $dir): void
{
    $parent = realpath(ORANGE_D5_DATA_PARENT);
    $resolved = realpath($dir);
    if ($parent === false || $resolved === false) {
        // unfinished create — try best-effort delete by path prefix check
        $norm = strtolower(str_replace('\\', '/', $dir));
        if (!str_starts_with($norm, strtolower(str_replace('\\', '/', ORANGE_D5_DATA_PARENT)) . '/')) {
            return;
        }
        $resolved = $dir;
        $parent = ORANGE_D5_DATA_PARENT;
    }
    $normParent = strtolower(str_replace('\\', '/', rtrim((string) $parent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim((string) $resolved, '\\/')));
    if ($normDir === $normParent || !str_starts_with($normDir, $normParent . '/')) {
        return;
    }
    if (!is_dir($resolved)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($resolved);
}

function orange_d5_mysqldump_bin(): string
{
    $mysql = orange_d1_mysql_bin();
    $candidate = preg_replace('/mysql\\.exe$/i', 'mysqldump.exe', $mysql) ?? '';
    if ($candidate !== '' && is_file($candidate)) {
        return $candidate;
    }
    $fallbacks = [
        'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe',
    ];
    foreach ($fallbacks as $c) {
        if (is_file($c)) {
            return $c;
        }
    }

    return 'mysqldump';
}

/**
 * Clone schema+data from one disposable MySQL DB into another (root only).
 */
function orange_d5_clone_database(string $fromDb, string $toDb): void
{
    if ($fromDb === '' || $toDb === '' || $fromDb === $toDb) {
        throw new InvalidArgumentException('Invalid clone database names');
    }
    if (!preg_match('/^(orange_db|orange_d5_(stg|shd)_[a-zA-Z0-9_]+)$/', $fromDb)
        || !preg_match('/^orange_d5_(stg|shd)_[a-zA-Z0-9_]+$/', $toDb)
    ) {
        throw new RuntimeException('Refusing clone outside D5 disposable names');
    }
    $mysqldump = orange_d5_mysqldump_bin();
    $mysql = orange_d1_mysql_bin();
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_clone_' . bin2hex(random_bytes(4)) . '.sql';
    $dumpCmd = [
        $mysqldump,
        '--default-character-set=utf8mb4',
        '-h127.0.0.1',
        '-P3306',
        '-uroot',
        '--single-transaction',
        '--routines',
        '--triggers',
        $fromDb,
    ];
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $tmp, 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($dumpCmd, $desc, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot start mysqldump for clone');
    }
    fclose($pipes[0]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 32) {
        @unlink($tmp);
        throw new RuntimeException('mysqldump clone failed: ' . trim((string) $err));
    }
    $admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $admin->exec('DROP DATABASE IF EXISTS `' . $toDb . '`');
    $admin->exec('CREATE DATABASE `' . $toDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $raw = (string) file_get_contents($tmp);
    $raw = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $raw) ?? $raw;
    file_put_contents($tmp, "SET NAMES utf8mb4;\nUSE `{$toDb}`;\nSET FOREIGN_KEY_CHECKS=0;\n" . $raw . "\nSET FOREIGN_KEY_CHECKS=1;\n");
    $imp = [$mysql, '--default-character-set=utf8mb4', '-h127.0.0.1', '-P3306', '-uroot', $toDb];
    $desc2 = [0 => ['file', $tmp, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc2 = proc_open($imp, $desc2, $pipes2, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc2)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot start mysql import for clone');
    }
    $stdout = stream_get_contents($pipes2[1]);
    $stderr = stream_get_contents($pipes2[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $code2 = proc_close($proc2);
    @unlink($tmp);
    if ($code2 !== 0) {
        throw new RuntimeException('mysql clone import failed: ' . trim((string) $stderr . "\n" . (string) $stdout));
    }
}

/**
 * Run Production php_pdo full backup in an isolated subprocess (avoids include collisions).
 *
 * @param array<string,mixed> $ctx from orange_d5_bootstrap
 * @return array{ok:bool,snapshot?:string,package_path?:string,backend?:string,message?:string,error?:string}
 */
function orange_d5_run_full_backup_pdo(array $ctx): array
{
    $runtimeRoot = (string) ($ctx['runtime_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    $mainRoot = (string) ($ctx['main_root'] ?? dirname(__DIR__, 2));
    if ($runtimeRoot === '' || $backupRoot === '') {
        return ['ok' => false, 'error' => 'runtime/backup root missing'];
    }

    // Sync worker + current backup includes into worktree so subprocess uses same code as main.
    $workerSrc = $mainRoot . '/scripts/lib/final_review_d5_backup_worker.php';
    $workerDst = $runtimeRoot . '/scripts/lib/final_review_d5_backup_worker.php';
    if (!is_dir(dirname($workerDst))) {
        @mkdir(dirname($workerDst), 0775, true);
    }
    if (!@copy($workerSrc, $workerDst)) {
        return ['ok' => false, 'error' => 'Cannot copy backup worker into runtime'];
    }
    // Refresh critical includes from main into worktree for this run.
    foreach (['includes/backup', 'includes/catalog_schema.php', 'config.php'] as $rel) {
        $from = $mainRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $to = $runtimeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_dir($from)) {
            orange_d5_mirror_dir($from, $to);
        } elseif (is_file($from)) {
            @copy($from, $to);
        }
    }

    $resultPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_bak_' . bin2hex(random_bytes(4)) . '.json';
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = [$php, $workerDst, $runtimeRoot, $backupRoot, $resultPath];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes, $runtimeRoot, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Cannot start backup worker'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $raw = is_file($resultPath) ? (string) file_get_contents($resultPath) : '';
    @unlink($resultPath);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'backup worker bad result exit=' . $code
                . ' stderr=' . trim((string) $stderr)
                . ' stdout=' . trim((string) $stdout),
        ];
    }

    return $decoded;
}
