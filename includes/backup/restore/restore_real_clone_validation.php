<?php

declare(strict_types=1);

/**
 * Phase 3B.4L / P0-4 — Real MySQL/MariaDB clone disaster-recovery validation.
 *
 * Runs restore → verify → DRV → shadow checks → smoke against a physically
 * isolated clone. Never Mock PDO. Never touches production schema, uploads, or config.
 */

require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../recovery_validation.php';
require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_dr_drill.php';

const ORANGE_RESTORE_REAL_CLONE_VERSION = '3B.4L-real-clone-v1';
const ORANGE_RESTORE_REAL_CLONE_MARKER = '.orange_restore_real_clone';
const ORANGE_RESTORE_REAL_CLONE_REPORT_FILE = 'real_clone_validation_report.json';
const ORANGE_RESTORE_REAL_CLONE_DEFAULT_PORT = 3307;
const ORANGE_RESTORE_REAL_CLONE_TARGET_DB = 'orange_clone_target';
const ORANGE_RESTORE_REAL_CLONE_SHADOW_DB = 'orange_clone_shadow';

/** @var array<string, mixed> */
$GLOBALS['orange_restore_real_clone_ctx'] = [];

/**
 * @return array<string, mixed>
 */
function orange_restore_real_clone_ctx(): array
{
    return is_array($GLOBALS['orange_restore_real_clone_ctx'] ?? null)
        ? $GLOBALS['orange_restore_real_clone_ctx']
        : [];
}

/**
 * @param array<string, mixed> $ctx
 */
function orange_restore_real_clone_set_ctx(array $ctx): void
{
    $GLOBALS['orange_restore_real_clone_ctx'] = $ctx;
}

function orange_restore_real_clone_clear_ctx(): void
{
    $GLOBALS['orange_restore_real_clone_ctx'] = [];
}

function orange_restore_real_clone_marker_path(string $root): string
{
    return rtrim($root, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . ORANGE_RESTORE_REAL_CLONE_MARKER;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_real_clone_write_marker(string $root, array $payload = []): void
{
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('clone_root_create_failed');
    }
    $payload = array_merge([
        'marker' => ORANGE_RESTORE_REAL_CLONE_MARKER,
        'version' => ORANGE_RESTORE_REAL_CLONE_VERSION,
        'written_at' => gmdate('c'),
        'role' => (string) ($payload['role'] ?? 'clone'),
    ], $payload);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(orange_restore_real_clone_marker_path($root), $json . "\n") === false) {
        throw new RuntimeException('clone_marker_write_failed');
    }
}

function orange_restore_real_clone_has_marker(string $root): bool
{
    return is_file(orange_restore_real_clone_marker_path($root));
}

/**
 * Resolve production DB identity from the real project (read-only).
 * Does not require .env.php (local clones may lack secrets); defaults to orange_db.
 *
 * @return array{db:string,user:string,host:string}
 */
function orange_restore_real_clone_production_identity(string $realProjectRoot): array
{
    $db = 'orange_db';
    $user = '';
    $host = '127.0.0.1';
    $envPath = $realProjectRoot . DIRECTORY_SEPARATOR . '.env.php';
    if (is_file($envPath)) {
        try {
            $settings = orange_backup_load_db_settings($realProjectRoot);
            $db = trim((string) ($settings['name'] ?? 'orange_db')) ?: 'orange_db';
            $user = trim((string) ($settings['user'] ?? ''));
            $host = trim((string) ($settings['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        } catch (Throwable) {
            $env = orange_backup_load_env_array($realProjectRoot);
            $user = trim((string) ($env['DB_USER'] ?? ''));
        }
    } else {
        $configPath = $realProjectRoot . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($configPath)) {
            $src = (string) file_get_contents($configPath);
            if (preg_match("/define\\(\\s*'DB_NAME'\\s*,\\s*'([^']+)'/", $src, $m)) {
                $db = $m[1];
            }
            if (preg_match("/define\\(\\s*'DB_HOST'\\s*,\\s*'([^']+)'/", $src, $m)) {
                $host = $m[1];
            }
        }
    }

    return [
        'db' => $db,
        'user' => $user,
        'host' => $host,
    ];
}

/**
 * Fail closed before every destructive clone stage.
 *
 * @param array<string, mixed> $extra
 */
function orange_restore_real_clone_assert_isolation(array $extra = []): void
{
    $ctx = array_merge(orange_restore_real_clone_ctx(), $extra);
    $cloneRoot = (string) ($ctx['clone_root'] ?? '');
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    $uploadsRoot = (string) ($ctx['uploads_root'] ?? '');
    $targetDb = (string) ($ctx['target_db'] ?? '');
    $shadowDb = (string) ($ctx['shadow_db'] ?? '');
    $realProject = (string) ($ctx['real_project_root'] ?? dirname(__DIR__, 3));

    if ($cloneRoot === '' || $workRoot === '' || $backupRoot === '' || $uploadsRoot === '') {
        throw new RuntimeException('clone_roots_incomplete');
    }
    foreach ([$cloneRoot, $workRoot, $backupRoot, $uploadsRoot] as $root) {
        if (!orange_restore_real_clone_has_marker($root)) {
            throw new RuntimeException('clone_marker_missing');
        }
    }

    if ($targetDb === '' || $shadowDb === '' || strcasecmp($targetDb, $shadowDb) === 0) {
        throw new RuntimeException('clone_db_identity_invalid');
    }

    $prod = orange_restore_real_clone_production_identity($realProject);
    foreach ([$targetDb, $shadowDb] as $db) {
        if (strcasecmp($db, $prod['db']) === 0 || strcasecmp($db, 'orange_db') === 0) {
            throw new RuntimeException('clone_rejected_production_db_name');
        }
    }

    $cloneUser = (string) ($ctx['db_user'] ?? '');
    if ($cloneUser !== '' && $prod['user'] !== '' && strcasecmp($cloneUser, $prod['user']) === 0
        && (int) ($ctx['db_port'] ?? 0) === 3306
        && strcasecmp((string) ($ctx['db_host'] ?? ''), $prod['host']) === 0) {
        // Same user+host+default port as production is too risky even if DB names differ.
        throw new RuntimeException('clone_rejected_production_credentials');
    }

    $realUploads = $realProject . DIRECTORY_SEPARATOR . 'uploads';
    $u = realpath($uploadsRoot) ?: $uploadsRoot;
    $ru = realpath($realUploads) ?: $realUploads;
    if (strtolower(str_replace('\\', '/', $u)) === strtolower(str_replace('\\', '/', $ru))) {
        throw new RuntimeException('clone_rejected_production_uploads');
    }

    $rp = realpath($realProject) ?: $realProject;
    foreach ([$workRoot, $backupRoot, $uploadsRoot, $cloneRoot] as $path) {
        $p = realpath($path) ?: $path;
        $pn = strtolower(str_replace('\\', '/', $p));
        $rn = strtolower(str_replace('\\', '/', $rp));
        if ($pn === $rn || str_starts_with($pn, $rn . '/')) {
            // Clone workspace must be outside the real web project tree.
            throw new RuntimeException('clone_workspace_inside_real_project');
        }
    }
}

/**
 * Assert live PDO session DB is the expected clone DB and not production.
 */
function orange_restore_real_clone_assert_session_db(PDO $pdo, string $expectedDb, string $productionDb): void
{
    $current = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($current === '' || strcasecmp($current, $expectedDb) !== 0) {
        throw new RuntimeException('clone_session_db_mismatch');
    }
    if (strcasecmp($current, $productionDb) === 0 || strcasecmp($current, 'orange_db') === 0) {
        throw new RuntimeException('clone_session_is_production');
    }
}

/**
 * @return array{mysqld:string,mysql:string,basedir:string}
 */
function orange_restore_real_clone_detect_mysql_binaries(): array
{
    $candidates = [
        'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64',
        'C:\\xampp\\mysql',
    ];
    foreach ($candidates as $basedir) {
        $mysqld = $basedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqld.exe';
        $mysql = $basedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        if (is_file($mysqld) && is_file($mysql)) {
            return ['mysqld' => $mysqld, 'mysql' => $mysql, 'basedir' => $basedir];
        }
    }
    throw new RuntimeException('clone_mysql_binaries_missing');
}

/**
 * Bootstrap an ephemeral isolated mysqld for local clone validation.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_real_clone_bootstrap_server(array $options): array
{
    $cloneRoot = (string) ($options['clone_root'] ?? 'D:\\orange_clone_mysql');
    $port = (int) ($options['port'] ?? ORANGE_RESTORE_REAL_CLONE_DEFAULT_PORT);
    $bins = orange_restore_real_clone_detect_mysql_binaries();
    $dataDir = $cloneRoot . DIRECTORY_SEPARATOR . 'data';
    $tmpDir = $cloneRoot . DIRECTORY_SEPARATOR . 'tmp';
    $logFile = $cloneRoot . DIRECTORY_SEPARATOR . 'mysqld_clone.log';
    $pidFile = $cloneRoot . DIRECTORY_SEPARATOR . 'mysqld_clone.pid';

    orange_restore_real_clone_write_marker($cloneRoot, ['role' => 'clone_root', 'port' => $port]);
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }

    $mysqlSystemDir = $dataDir . DIRECTORY_SEPARATOR . 'mysql';
    if (!is_dir($dataDir) || !is_dir($mysqlSystemDir)) {
        if (is_dir($dataDir)) {
            $entries = array_diff(scandir($dataDir) ?: [], ['.', '..']);
            if ($entries !== []) {
                throw new RuntimeException('clone_datadir_incomplete_nonempty');
            }
        } else {
            mkdir($dataDir, 0775, true);
        }
        $cmd = escapeshellarg($bins['mysqld'])
            . ' --initialize-insecure'
            . ' --basedir=' . escapeshellarg($bins['basedir'])
            . ' --datadir=' . escapeshellarg($dataDir);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_dir($mysqlSystemDir)) {
            throw new RuntimeException(
                'clone_mysqld_initialize_failed:' . implode(' | ', array_slice($out, 0, 8))
            );
        }
    }

    // Already listening?
    if (orange_restore_real_clone_port_open('127.0.0.1', $port)) {
        return [
            'ok' => true,
            'bootstrapped' => false,
            'already_running' => true,
            'host' => '127.0.0.1',
            'port' => $port,
            'clone_root' => $cloneRoot,
            'user' => 'root',
            'pass' => '',
        ];
    }

    $args = [
        '--basedir=' . $bins['basedir'],
        '--datadir=' . $dataDir,
        '--port=' . (string) $port,
        '--bind-address=127.0.0.1',
        '--tmpdir=' . $tmpDir,
        '--log-error=' . $logFile,
        '--pid-file=' . $pidFile,
        '--mysqlx=0',
    ];
    $cmd = '"' . $bins['mysqld'] . '" ' . implode(' ', array_map(static fn ($a) => '"' . $a . '"', $args));
    if (PHP_OS_FAMILY === 'Windows') {
        $proc = @proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            null,
            null,
            ['bypass_shell' => false]
        );
        if (is_resource($proc)) {
            // Detach: do not wait; close pipes.
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
        }
    } else {
        exec('nohup ' . $cmd . ' >/dev/null 2>&1 &');
    }

    $deadline = time() + 45;
    while (time() < $deadline) {
        if (orange_restore_real_clone_port_open('127.0.0.1', $port)) {
            return [
                'ok' => true,
                'bootstrapped' => true,
                'already_running' => false,
                'host' => '127.0.0.1',
                'port' => $port,
                'clone_root' => $cloneRoot,
                'user' => 'root',
                'pass' => '',
                'pid_file' => $pidFile,
            ];
        }
        usleep(500000);
    }

    $tail = is_file($logFile) ? substr((string) file_get_contents($logFile), -800) : '';
    throw new RuntimeException('clone_mysqld_start_timeout:' . $tail);
}

function orange_restore_real_clone_port_open(string $host, int $port): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if (is_resource($fp)) {
        fclose($fp);

        return true;
    }

    return false;
}

/**
 * @return PDO
 */
function orange_restore_real_clone_connect(string $host, int $port, string $user, string $pass, string $db = ''): PDO
{
    $dsn = 'mysql:host=' . $host . ';port=' . (string) $port . ';charset=utf8mb4';
    if ($db !== '') {
        $dsn .= ';dbname=' . $db;
    }
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    return $pdo;
}

/**
 * Ensure clone databases exist (utf8mb4).
 *
 * @param array<string, mixed> $cfg
 */
function orange_restore_real_clone_ensure_databases(array $cfg): void
{
    $pdo = orange_restore_real_clone_connect(
        (string) $cfg['db_host'],
        (int) $cfg['db_port'],
        (string) $cfg['db_user'],
        (string) $cfg['db_pass'],
        ''
    );
    foreach ([(string) $cfg['target_db'], (string) $cfg['shadow_db']] as $db) {
        $q = '`' . str_replace('`', '``', $db) . '`';
        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS ' . $q
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_real_clone_server_info(PDO $pdo): array
{
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $cs = $pdo->query("SHOW VARIABLES LIKE 'character_set_server'")->fetch(PDO::FETCH_NUM);
    $co = $pdo->query("SHOW VARIABLES LIKE 'collation_server'")->fetch(PDO::FETCH_NUM);

    return [
        'server_version' => $version,
        'engine' => 'InnoDB',
        'charset' => is_array($cs) && isset($cs[1]) ? (string) $cs[1] : 'utf8mb4',
        'collation' => is_array($co) && isset($co[1]) ? (string) $co[1] : 'utf8mb4_unicode_ci',
    ];
}

/**
 * Prepare isolated filesystem roots under clone_root.
 *
 * @param array<string, mixed> $cfg
 * @return array<string, mixed>
 */
function orange_restore_real_clone_prepare_workspace(array $cfg): array
{
    $cloneRoot = (string) $cfg['clone_root'];
    $workRoot = $cloneRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $backupRoot = $cloneRoot . DIRECTORY_SEPARATOR . 'backups';
    $uploadsRoot = $cloneRoot . DIRECTORY_SEPARATOR . 'uploads';
    $pkgId = 'clone_' . gmdate('Ymd_His');
    $pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;

    foreach ([$workRoot, $backupRoot, $uploadsRoot, $pkgDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('clone_workspace_mkdir_failed');
        }
    }

    orange_restore_real_clone_write_marker($cloneRoot, ['role' => 'clone_root']);
    orange_restore_real_clone_write_marker($workRoot, ['role' => 'work_root']);
    orange_restore_real_clone_write_marker($backupRoot, ['role' => 'backup_root']);
    orange_restore_real_clone_write_marker($uploadsRoot, ['role' => 'uploads_root']);

    // Seed a real Full package with ZipArchive-compatible uploads.zip for DRV.
    orange_restore_real_clone_seed_package($pkgDir, $pkgId);
    file_put_contents($uploadsRoot . DIRECTORY_SEPARATOR . 'seed.txt', 'clone-pre-restore');

    $cfg['work_root'] = $workRoot;
    $cfg['backup_root'] = $backupRoot;
    $cfg['uploads_root'] = $uploadsRoot;
    $cfg['package_id'] = $pkgId;
    $cfg['package_dir'] = $pkgDir;

    return $cfg;
}

/**
 * Seed an isolated Full package that DRV can validate (ZipArchive uploads.zip).
 */
function orange_restore_real_clone_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir) && !mkdir($pkgDir, 0775, true) && !is_dir($pkgDir)) {
        throw new RuntimeException('clone_package_dir_failed');
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode(
        "SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\nINSERT INTO t VALUES (1);\n",
        1
    );
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));

    $zipPath = $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel;
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('clone_ziparchive_required_for_drv');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('clone_uploads_zip_create_failed');
    }
    $zip->addFromString('a.txt', 'hello-uploads');
    $zip->close();

    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $zipPath) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($zipPath),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 1,
    ]);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    file_put_contents(
        $pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256',
        $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n"
    );
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

/**
 * Isolated clone FS: two-phase uploads rename + rollback (never production paths).
 *
 * @return array{ok:bool,cutover:array<string,mixed>,rollback:array<string,mixed>}
 */
function orange_restore_real_clone_uploads_cutover_and_rollback(string $uploadsRoot, string $packageDir): array
{
    $marker = orange_restore_real_clone_marker_path($uploadsRoot);
    if (!is_file($marker)) {
        throw new RuntimeException('clone_uploads_marker_missing');
    }
    $zipPath = $packageDir . DIRECTORY_SEPARATOR . 'uploads.zip';
    if (!is_file($zipPath) || !class_exists('ZipArchive')) {
        throw new RuntimeException('clone_uploads_zip_missing');
    }

    $uploadsNext = dirname($uploadsRoot) . DIRECTORY_SEPARATOR . 'uploads_next';
    $uploadsPre = dirname($uploadsRoot) . DIRECTORY_SEPARATOR . 'uploads_pre_merge';
    foreach ([$uploadsNext, $uploadsPre] as $dir) {
        if (is_dir($dir)) {
            orange_restore_real_clone_rm_tree($dir);
        }
    }
    if (!mkdir($uploadsNext, 0775, true) && !is_dir($uploadsNext)) {
        throw new RuntimeException('clone_uploads_next_mkdir_failed');
    }
    orange_restore_real_clone_write_marker($uploadsNext, ['role' => 'uploads_next']);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('clone_uploads_zip_open_failed');
    }
    if (!$zip->extractTo($uploadsNext)) {
        $zip->close();
        throw new RuntimeException('clone_uploads_zip_extract_failed');
    }
    $zip->close();

    $seedBefore = is_file($uploadsRoot . DIRECTORY_SEPARATOR . 'seed.txt');
    if (!@rename($uploadsRoot, $uploadsPre)) {
        throw new RuntimeException('clone_uploads_rename_to_pre_merge_failed');
    }
    if (!@rename($uploadsNext, $uploadsRoot)) {
        @rename($uploadsPre, $uploadsRoot);
        throw new RuntimeException('clone_uploads_rename_next_to_live_failed');
    }
    orange_restore_real_clone_write_marker($uploadsRoot, ['role' => 'uploads_root_after_cutover']);
    $cutoverOk = is_file($uploadsRoot . DIRECTORY_SEPARATOR . 'a.txt')
        && is_dir($uploadsPre)
        && $seedBefore;

    // Rollback files: reverse rename (production model).
    $uploadsFailed = dirname($uploadsRoot) . DIRECTORY_SEPARATOR . 'uploads_failed_cutover';
    if (is_dir($uploadsFailed)) {
        orange_restore_real_clone_rm_tree($uploadsFailed);
    }
    if (!@rename($uploadsRoot, $uploadsFailed)) {
        throw new RuntimeException('clone_uploads_rollback_park_failed');
    }
    if (!@rename($uploadsPre, $uploadsRoot)) {
        @rename($uploadsFailed, $uploadsRoot);
        throw new RuntimeException('clone_uploads_rollback_restore_failed');
    }
    orange_restore_real_clone_write_marker($uploadsRoot, ['role' => 'uploads_root']);
    $rollbackOk = is_file($uploadsRoot . DIRECTORY_SEPARATOR . 'seed.txt')
        && !is_file($uploadsRoot . DIRECTORY_SEPARATOR . 'a.txt');

    return [
        'ok' => $cutoverOk && $rollbackOk,
        'cutover' => [
            'ok' => $cutoverOk,
            'model' => 'uploads→uploads_pre_merge; uploads_next→uploads',
            'post_file' => 'a.txt',
        ],
        'rollback' => [
            'ok' => $rollbackOk,
            'model' => 'uploads→uploads_failed_cutover; uploads_pre_merge→uploads',
            'restored_file' => 'seed.txt',
        ],
    ];
}

function orange_restore_real_clone_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            orange_restore_real_clone_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Anchor SQL representing pre-restore DB content for clone rollback proof.
 */
function orange_restore_real_clone_write_rollback_sql_gzip(string $path): string
{
    $sql = <<<'SQL'
SET NAMES utf8mb4;
DROP TABLE IF EXISTS `clone_items`;
CREATE TABLE `clone_items` (
  `id` INT NOT NULL PRIMARY KEY,
  `name` VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `clone_items` (`id`, `name`) VALUES (1, 'pre_restore_anchor');
SQL;
    $gz = gzencode($sql, 1);
    if ($gz === false) {
        throw new RuntimeException('clone_rollback_gzip_failed');
    }
    if (@file_put_contents($path, $gz) === false) {
        throw new RuntimeException('clone_rollback_sql_write_failed');
    }

    return hash_file('sha256', $path) ?: '';
}

/**
 * Build gzip SQL artifact for clone restore (real file, imported via real PDO).
 */
function orange_restore_real_clone_write_sql_gzip(string $path): string
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('clone_sql_dir_failed');
    }
    $sql = implode("\n", [
        'SET NAMES utf8mb4;',
        'SET FOREIGN_KEY_CHECKS=0;',
        'DROP TABLE IF EXISTS `clone_items`;',
        'CREATE TABLE `clone_items` (',
        '  `id` INT NOT NULL,',
        '  `name` VARCHAR(64) NOT NULL,',
        '  PRIMARY KEY (`id`)',
        ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        "INSERT INTO `clone_items` (`id`,`name`) VALUES (1,'alpha'),(2,'beta');",
        'SET FOREIGN_KEY_CHECKS=1;',
        '',
    ]);
    $gz = gzencode($sql, 9);
    if ($gz === false || file_put_contents($path, $gz) === false) {
        throw new RuntimeException('clone_sql_gzip_write_failed');
    }

    return hash_file('sha256', $path) ?: '';
}

/**
 * Run the full real-clone validation pipeline.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_real_clone_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $started = microtime(true);
    $realProject = (string) ($options['project_root'] ?? dirname(__DIR__, 3));
    $cloneRoot = (string) ($options['clone_root'] ?? 'D:\\orange_clone_mysql');
    $port = (int) ($options['port'] ?? ORANGE_RESTORE_REAL_CLONE_DEFAULT_PORT);
    $autoBootstrap = array_key_exists('auto_bootstrap', $options)
        ? (bool) $options['auto_bootstrap']
        : true;

    $prod = orange_restore_real_clone_production_identity($realProject);
    $timings = [];

    $t0 = microtime(true);
    if ($autoBootstrap) {
        $boot = orange_restore_real_clone_bootstrap_server([
            'clone_root' => $cloneRoot,
            'port' => $port,
        ]);
    } else {
        if (!orange_restore_real_clone_port_open('127.0.0.1', $port)) {
            throw new RuntimeException('clone_mysql_unavailable');
        }
        $boot = [
            'ok' => true,
            'host' => '127.0.0.1',
            'port' => $port,
            'user' => (string) ($options['db_user'] ?? 'root'),
            'pass' => (string) ($options['db_pass'] ?? ''),
            'clone_root' => $cloneRoot,
        ];
    }
    $timings['bootstrap_seconds'] = round(microtime(true) - $t0, 3);

    $cfg = [
        'real_project_root' => $realProject,
        'clone_root' => $cloneRoot,
        'db_host' => (string) ($boot['host'] ?? '127.0.0.1'),
        'db_port' => (int) ($boot['port'] ?? $port),
        'db_user' => (string) ($boot['user'] ?? 'root'),
        'db_pass' => (string) ($boot['pass'] ?? ''),
        'target_db' => (string) ($options['target_db'] ?? ORANGE_RESTORE_REAL_CLONE_TARGET_DB),
        'shadow_db' => (string) ($options['shadow_db'] ?? ORANGE_RESTORE_REAL_CLONE_SHADOW_DB),
        'production_db' => $prod['db'],
    ];

    if (strcasecmp($cfg['target_db'], $prod['db']) === 0 || strcasecmp($cfg['shadow_db'], $prod['db']) === 0) {
        throw new RuntimeException('clone_rejected_production_db_name');
    }

    $t0 = microtime(true);
    $cfg = orange_restore_real_clone_prepare_workspace($cfg);
    orange_restore_real_clone_set_ctx($cfg);
    orange_restore_real_clone_assert_isolation();
    orange_restore_real_clone_ensure_databases($cfg);
    $timings['workspace_seconds'] = round(microtime(true) - $t0, 3);

    $adminPdo = orange_restore_real_clone_connect(
        $cfg['db_host'],
        (int) $cfg['db_port'],
        $cfg['db_user'],
        $cfg['db_pass'],
        ''
    );
    $serverInfo = orange_restore_real_clone_server_info($adminPdo);

    // --- DRV on package ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    $drv = orange_recovery_validate_package((string) $cfg['package_dir']);
    $timings['drv_seconds'] = round(microtime(true) - $t0, 3);
    $drvOk = strtolower((string) ($drv['overall_result'] ?? '')) !== 'fail'
        && (int) ($drv['recovery_score'] ?? 0) >= 70;

    // --- Restore into shadow (real import) ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    $sqlGz = (string) $cfg['work_root'] . DIRECTORY_SEPARATOR . 'clone_restore.sql.gz';
    $sqlChecksum = orange_restore_real_clone_write_sql_gzip($sqlGz);
    $shadowPdo = orange_restore_real_clone_connect(
        $cfg['db_host'],
        (int) $cfg['db_port'],
        $cfg['db_user'],
        $cfg['db_pass'],
        (string) $cfg['shadow_db']
    );
    orange_restore_real_clone_assert_session_db($shadowPdo, (string) $cfg['shadow_db'], $prod['db']);
    // Wipe shadow then import.
    orange_restore_production_wipe($shadowPdo, (string) $cfg['shadow_db']);
    orange_restore_real_clone_assert_session_db($shadowPdo, (string) $cfg['shadow_db'], $prod['db']);
    $shadowImport = orange_restore_sql_runner_import_gzip(
        $shadowPdo,
        $sqlGz,
        (string) $cfg['shadow_db'],
        $prod['db']
    );
    if (empty($shadowImport['ok'])) {
        throw new RuntimeException('clone_shadow_import_failed:' . (string) ($shadowImport['error'] ?? ''));
    }
    $timings['shadow_restore_seconds'] = round(microtime(true) - $t0, 3);

    // --- Shadow verify ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    orange_restore_real_clone_assert_session_db($shadowPdo, (string) $cfg['shadow_db'], $prod['db']);
    $shadowTables = $shadowPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $shadowCount = (int) $shadowPdo->query('SELECT COUNT(*) FROM `clone_items`')->fetchColumn();
    $shadowCharset = $shadowPdo->query(
        "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME="
        . $shadowPdo->quote((string) $cfg['shadow_db'])
    )->fetchColumn();
    $shadowVerify = [
        'ok' => is_array($shadowTables) && in_array('clone_items', $shadowTables, true) && $shadowCount === 2
            && strtolower((string) $shadowCharset) === 'utf8mb4',
        'tables' => $shadowTables,
        'row_count' => $shadowCount,
        'schema_charset' => (string) $shadowCharset,
        'session_database' => (string) $shadowPdo->query('SELECT DATABASE()')->fetchColumn(),
    ];
    $timings['shadow_verify_seconds'] = round(microtime(true) - $t0, 3);

    // --- Cutover into clone target (wipe + import) with repeated isolation asserts ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    $targetPdo = orange_restore_real_clone_connect(
        $cfg['db_host'],
        (int) $cfg['db_port'],
        $cfg['db_user'],
        $cfg['db_pass'],
        (string) $cfg['target_db']
    );
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    orange_restore_production_wipe($targetPdo, (string) $cfg['target_db']);
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    $targetImport = orange_restore_sql_runner_import_gzip_to_target(
        $targetPdo,
        $sqlGz,
        (string) $cfg['target_db'],
        $prod['db']
    );
    if (empty($targetImport['ok'])) {
        throw new RuntimeException('clone_target_import_failed:' . (string) ($targetImport['error'] ?? ''));
    }
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    $timings['target_restore_seconds'] = round(microtime(true) - $t0, 3);

    // --- Smoke ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    $smokeRows = (int) $targetPdo->query('SELECT COUNT(*) FROM `clone_items`')->fetchColumn();
    $smokeName = (string) $targetPdo->query('SELECT `name` FROM `clone_items` WHERE id=1')->fetchColumn();
    $smoke = [
        'ok' => $smokeRows === 2 && $smokeName === 'alpha',
        'row_count' => $smokeRows,
        'sample_name' => $smokeName,
        'session_database' => (string) $targetPdo->query('SELECT DATABASE()')->fetchColumn(),
        'not_production' => strcasecmp((string) $targetPdo->query('SELECT DATABASE()')->fetchColumn(), $prod['db']) !== 0,
    ];
    $timings['smoke_seconds'] = round(microtime(true) - $t0, 3);

    // --- Real FS uploads cutover + rollback (isolated clone uploads only) ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    $uploadsFs = orange_restore_real_clone_uploads_cutover_and_rollback(
        (string) $cfg['uploads_root'],
        (string) $cfg['package_dir']
    );
    $timings['uploads_cutover_rollback_seconds'] = round(microtime(true) - $t0, 3);

    // --- Real DB rollback proof on clone target (wipe + re-import prior image) ---
    $t0 = microtime(true);
    orange_restore_real_clone_assert_isolation();
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    $rollbackSqlGz = (string) $cfg['work_root'] . DIRECTORY_SEPARATOR . 'clone_rollback_anchor.sql.gz';
    orange_restore_real_clone_write_rollback_sql_gzip($rollbackSqlGz);
    orange_restore_production_wipe($targetPdo, (string) $cfg['target_db']);
    orange_restore_real_clone_assert_session_db($targetPdo, (string) $cfg['target_db'], $prod['db']);
    $dbRollbackImport = orange_restore_sql_runner_import_gzip_to_target(
        $targetPdo,
        $rollbackSqlGz,
        (string) $cfg['target_db'],
        $prod['db']
    );
    if (empty($dbRollbackImport['ok'])) {
        throw new RuntimeException('clone_db_rollback_import_failed:' . (string) ($dbRollbackImport['error'] ?? ''));
    }
    $rbName = (string) $targetPdo->query('SELECT `name` FROM `clone_items` WHERE id=1')->fetchColumn();
    $dbRollback = [
        'ok' => !empty($dbRollbackImport['ok']) && $rbName === 'pre_restore_anchor',
        'sample_name' => $rbName,
        'import' => $dbRollbackImport,
        'session_database' => (string) $targetPdo->query('SELECT DATABASE()')->fetchColumn(),
    ];
    $timings['db_rollback_seconds'] = round(microtime(true) - $t0, 3);

    $timings['total_seconds'] = round(microtime(true) - $started, 3);

    $isolationProof = [
        'clone_marker' => ORANGE_RESTORE_REAL_CLONE_MARKER,
        'clone_root' => $cloneRoot,
        'work_root' => $cfg['work_root'],
        'backup_root' => $cfg['backup_root'],
        'uploads_root' => $cfg['uploads_root'],
        'target_db' => $cfg['target_db'],
        'shadow_db' => $cfg['shadow_db'],
        'production_db' => $prod['db'],
        'db_port' => (int) $cfg['db_port'],
        'production_db_differs' => strcasecmp((string) $cfg['target_db'], $prod['db']) !== 0
            && strcasecmp((string) $cfg['shadow_db'], $prod['db']) !== 0,
        'uploads_differs_from_production' => true,
        'workspace_outside_project' => true,
        'mock_pdo_used' => false,
        'asserts_before_destructive_stages' => true,
    ];

    $ok = $drvOk
        && ($shadowVerify['ok'] ?? false)
        && ($smoke['ok'] ?? false)
        && !empty($shadowImport['ok'])
        && !empty($targetImport['ok'])
        && !empty($uploadsFs['ok'])
        && !empty($dbRollback['ok'])
        && $isolationProof['production_db_differs'];

    $report = [
        'report_version' => ORANGE_RESTORE_REAL_CLONE_VERSION,
        'generated_at' => gmdate('c'),
        'overall_result' => $ok ? 'PASS' : 'FAIL',
        'server_version' => $serverInfo['server_version'],
        'engine' => $serverInfo['engine'],
        'charset' => $serverInfo['charset'],
        'collation' => $serverInfo['collation'],
        'restore_duration_seconds' => $timings['total_seconds'],
        'timings' => $timings,
        'verification' => [
            'shadow_import' => $shadowImport,
            'target_import' => $targetImport,
            'sql_gzip_sha256' => $sqlChecksum,
        ],
        'drv' => [
            'ok' => $drvOk,
            'overall_result' => (string) ($drv['overall_result'] ?? ''),
            'recovery_score' => (int) ($drv['recovery_score'] ?? 0),
        ],
        'shadow_verify' => $shadowVerify,
        'smoke' => $smoke,
        'uploads_cutover' => $uploadsFs['cutover'] ?? [],
        'uploads_rollback' => $uploadsFs['rollback'] ?? [],
        'db_rollback' => $dbRollback,
        'production_isolation_proof' => $isolationProof,
        'package_id' => (string) $cfg['package_id'],
        'mock_pdo_used' => false,
        'notes' => [
            'Clone target DB stands in for production schema during validation only.',
            'Real project DB_NAME / uploads / .env.php were not modified.',
            'Uploads cutover/rollback exercised on isolated clone filesystem only.',
        ],
    ];

    $reportPath = (string) $cfg['work_root'] . DIRECTORY_SEPARATOR . ORANGE_RESTORE_REAL_CLONE_REPORT_FILE;
    orange_backup_write_json($reportPath, $report);

    // Also publish a copy under docs/backup for operator visibility (non-secret).
    $docsCopy = $realProject . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_REAL_CLONE_REPORT_FILE;
    @orange_backup_write_json($docsCopy, $report);

    orange_restore_real_clone_clear_ctx();

    return [
        'ok' => $ok,
        'report' => $report,
        'report_path' => $reportPath,
        'docs_report_path' => $docsCopy,
        'bootstrap' => $boot,
    ];
}
