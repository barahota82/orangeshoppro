<?php

declare(strict_types=1);

/**
 * Phase 2B.1 — Full disaster restore → staging self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_full_staging.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_safety.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_package_compat.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_uploads_applicator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_fresh_backup_gate.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_full_staging.php';

$failures = 0;

function restore_staging_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function restore_staging_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2b1_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function restore_staging_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            restore_staging_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @param array<string, mixed> $overrides
 */
function restore_staging_write_full_package(string $dir, array $overrides = []): void
{
    $sql = "-- Orange restore staging self-test\n"
        . "CREATE TABLE restore_demo (id INT PRIMARY KEY, label VARCHAR(64));\n"
        . "INSERT INTO restore_demo VALUES (1, 'staging');\n"
        . "# hash comment line\n"
        . "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    $dumpPath = $dir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
    $out = gzopen($dumpPath, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);

    $uploadsZip = $dir . DIRECTORY_SEPARATOR . 'uploads.zip';
    orange_country_uploads_write_empty_zip($uploadsZip);

    $manifest = array_merge([
        'package_type' => 'full_disaster',
        'package_version' => '1.2',
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'dump_file' => 'orange_db.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => orange_backup_sha256_file($dumpPath),
        'uploads_sha256' => orange_backup_sha256_file($uploadsZip),
        'dump_size_bytes' => filesize($dumpPath),
        'uploads_size_bytes' => filesize($uploadsZip),
        'table_count' => 1,
        'backup_status' => 'success',
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
    ], $overrides['manifest'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $health = array_merge([
        'package_type' => 'full_disaster',
        'package_status' => 'healthy',
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'failure_reasons' => [],
        'warnings' => [],
        'maintenance_notes' => [],
    ], $overrides['health'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);
    orange_backup_write_checksums($dir, ['orange_db.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
}

function restore_staging_write_gzip_sql(string $path, string $sql): void
{
    $out = gzopen($path, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);
}

function restore_staging_validate_rejects(string $sql, string $stagingDb, string $productionDb): bool
{
    try {
        orange_restore_sql_validate_statement_for_staging($sql, $stagingDb, $productionDb);

        return false;
    } catch (Throwable) {
        return true;
    }
}

function restore_staging_validate_accepts(string $sql, string $stagingDb, string $productionDb): bool
{
    try {
        orange_restore_sql_validate_statement_for_staging($sql, $stagingDb, $productionDb);

        return true;
    } catch (Throwable) {
        return false;
    }
}

$fixture = restore_staging_fixture_layout();
$backupRoot = $fixture['backup_root'];
$workRoot = $fixture['work_root'];
$packageDir = $fixture['package_dir'];

$stagingDbName = 'orange_restore_staging_test';
$productionDbName = orange_restore_production_db_name($projectRoot);

$envOverride = [
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_RESTORE_WORK_DIR' => $workRoot,
    ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName,
    ORANGE_RESTORE_ENV_STAGING_DB_USER => 'restore_staging_user',
    ORANGE_RESTORE_ENV_STAGING_DB_PASS => 'restore_staging_pass',
];

// SQL safety validator
restore_staging_self_test(
    restore_staging_validate_rejects('USE `' . $productionDbName . '`;', $stagingDbName, $productionDbName),
    'sql safety: USE production_db rejected before execution'
);
restore_staging_self_test(
    restore_staging_validate_rejects('CREATE DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DROP DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: fully qualified production table rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DELIMITER ;;', $stagingDbName, $productionDbName),
    'sql safety: DELIMITER rejected'
);
restore_staging_self_test(
    orange_restore_sql_is_comment_only("# only hash comment\n-- and dash\n"),
    'sql safety: # comment-only statement recognized'
);

// Whitespace variants — all rejected before execution
restore_staging_self_test(
    restore_staging_validate_rejects("USE\t`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with tab rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("USE\n`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with newline rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("USE\r\n`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with CRLF rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('USE    `' . $productionDbName . '`;', $stagingDbName, $productionDbName),
    'sql safety: USE with multiple spaces rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('CREATE  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("CREATE\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DROP  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("DROP\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('ALTER  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: ALTER DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("ALTER\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: ALTER DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO ' . $productionDbName . '.accounts VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: unquoted db.table rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: quoted db.table rejected'
);

// Context-aware cross-schema — must pass (no false positives)
restore_staging_self_test(
    restore_staging_validate_accepts(
        'CREATE TABLE restore_pricing (id INT PRIMARY KEY, amount DECIMAL(10,2) NOT NULL);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: DECIMAL(10,2) in CREATE TABLE accepted'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        "INSERT INTO restore_pricing VALUES (1, 19.99);",
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: decimal literal VALUES (19.99) accepted'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        'SELECT t.column_name FROM restore_pricing t WHERE t.id = 1;',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: alias.column accepted'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        "INSERT INTO restore_pricing VALUES (2, 'note: {$productionDbName}.accounts is safe in string');",
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: prod.table inside string literal accepted'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        "-- cross-schema mention {$productionDbName}.accounts in comment\nINSERT INTO restore_pricing VALUES (3, 1.25);",
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: prod.table in comment accepted'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        "SET NAMES utf8mb4;\n"
        . "CREATE TABLE `restore_demo` (\n"
        . "  `id` INT NOT NULL,\n"
        . "  `price` DECIMAL(10,2) NOT NULL,\n"
        . "  PRIMARY KEY (`id`)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        . "INSERT INTO `restore_demo` (`id`, `price`) VALUES\n"
        . "(1, 19.99),\n"
        . "(2, 0xDEADBEEF);\n",
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: php_pdo-style CREATE TABLE + multiline INSERT accepted'
);

// Context-aware cross-schema — must fail (object-name positions)
$crossSchemaRejectCases = [
    'INSERT INTO prod.table VALUES (1);' => 'INSERT INTO prod.table',
    'INSERT prod.table VALUES (1);' => 'INSERT prod.table without INTO',
    'UPDATE prod.table SET id = 1;' => 'UPDATE prod.table',
    'DELETE FROM prod.table WHERE id = 1;' => 'DELETE FROM prod.table',
    'SELECT * FROM prod.table;' => 'FROM prod.table',
    'SELECT a.id FROM orders a JOIN prod.table b ON a.id = b.id;' => 'JOIN prod.table',
    'CREATE TABLE prod.table (id INT);' => 'CREATE TABLE prod.table',
    'CREATE VIEW prod.view_name AS SELECT 1;' => 'CREATE VIEW prod.view_name',
    'ALTER TABLE prod.table ADD COLUMN x INT;' => 'ALTER TABLE prod.table',
    'ALTER VIEW prod.view_name AS SELECT 1;' => 'ALTER VIEW prod.view_name',
    'DROP TABLE prod.table;' => 'DROP TABLE prod.table',
    'DROP TABLE IF EXISTS prod.table;' => 'DROP TABLE IF EXISTS prod.table',
    'DROP VIEW prod.view_name;' => 'DROP VIEW prod.view_name',
    'DROP VIEW IF EXISTS prod.view_name;' => 'DROP VIEW IF EXISTS prod.view_name',
    'TRUNCATE prod.table;' => 'TRUNCATE prod.table',
    'RENAME TABLE prod.a TO prod.b;' => 'RENAME TABLE prod.a TO prod.b',
    'CREATE TABLE child (id INT, FOREIGN KEY (id) REFERENCES prod.parent (id));' => 'REFERENCES prod.table',
    'LOCK TABLES prod.table READ;' => 'LOCK TABLES prod.table',
    'REPLACE INTO prod.table VALUES (1);' => 'REPLACE INTO prod.table',
    'REPLACE prod.table VALUES (1);' => 'REPLACE prod.table without INTO',
    'CREATE INDEX idx_restore_test ON prod.table (id);' => 'CREATE INDEX ON prod.table',
    'DROP INDEX idx_restore_test ON prod.table;' => 'DROP INDEX ON prod.table',
    'LOAD DATA INFILE "restore.csv" INTO TABLE prod.table;' => 'LOAD DATA INTO TABLE prod.table',
    'ANALYZE TABLE prod.table;' => 'ANALYZE TABLE prod.table',
    'OPTIMIZE TABLE prod.table;' => 'OPTIMIZE TABLE prod.table',
    'CHECK TABLE prod.table;' => 'CHECK TABLE prod.table',
    'REPAIR TABLE prod.table;' => 'REPAIR TABLE prod.table',
    'DESCRIBE prod.table;' => 'DESCRIBE prod.table',
    'CALL prod.proc_name();' => 'CALL prod.proc_name',
    'DROP TRIGGER prod.trigger_name;' => 'DROP TRIGGER prod.trigger_name',
    'DROP TRIGGER IF EXISTS prod.trigger_name;' => 'DROP TRIGGER IF EXISTS prod.trigger_name',
    'DROP PROCEDURE prod.proc_name;' => 'DROP PROCEDURE prod.proc_name',
    'DROP PROCEDURE IF EXISTS prod.proc_name;' => 'DROP PROCEDURE IF EXISTS prod.proc_name',
    'DROP FUNCTION prod.func_name;' => 'DROP FUNCTION prod.func_name',
    'DROP FUNCTION IF EXISTS prod.func_name;' => 'DROP FUNCTION IF EXISTS prod.func_name',
    'DROP EVENT prod.event_name;' => 'DROP EVENT prod.event_name',
    'DROP EVENT IF EXISTS prod.event_name;' => 'DROP EVENT IF EXISTS prod.event_name',
];
foreach ($crossSchemaRejectCases as $sqlTemplate => $label) {
    $sql = str_replace('prod', $productionDbName, $sqlTemplate);
    restore_staging_self_test(
        restore_staging_validate_rejects($sql, $stagingDbName, $productionDbName),
        'sql safety: rejects ' . $label
    );
}

// Four quoting combinations for INSERT INTO
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO ' . $productionDbName . '.accounts VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: unquoted db.table (INSERT) rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: quoted db.table (INSERT) rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO ' . $productionDbName . '.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: db.`table` (INSERT) rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.accounts VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: `db`.table (INSERT) rejected'
);

// Privilege fence — fail closed
try {
    orange_restore_staging_assert_no_production_privileges(
        new PDO('sqlite::memory:'),
        $stagingDbName,
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: SHOW GRANTS unavailable fails closed');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'SHOW GRANTS'),
        'privilege fence: SHOW GRANTS unavailable fails closed'
    );
}

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT ALL PRIVILEGES ON *.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: global *.* grant rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'production schema'),
        'privilege fence: global *.* grant rejected'
    );
}

$usageGrantOk = true;
try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT USAGE ON *.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
} catch (Throwable) {
    $usageGrantOk = false;
}
restore_staging_self_test($usageGrantOk, 'privilege fence: no-op global USAGE grant accepted');

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT SELECT ON `{$productionDbName}`.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: production schema grant rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'production schema'),
        'privilege fence: production schema grant rejected'
    );
}

$stagingGrantOk = true;
try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT ALL PRIVILEGES ON `{$stagingDbName}`.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
} catch (Throwable) {
    $stagingGrantOk = false;
}
restore_staging_self_test($stagingGrantOk, 'privilege fence: staging-only grant accepted');

$stagingUnquotedGrantOk = true;
try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT ALL PRIVILEGES ON {$stagingDbName}.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
} catch (Throwable) {
    $stagingUnquotedGrantOk = false;
}
restore_staging_self_test($stagingUnquotedGrantOk, 'privilege fence: unquoted staging-only grant accepted');

try {
    orange_restore_staging_validate_grant_lines([], $productionDbName);
    restore_staging_self_test(false, 'privilege fence: empty SHOW GRANTS rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'no rows'),
        'privilege fence: empty SHOW GRANTS rejected'
    );
}

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT SELECT ON {$productionDbName}.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: unquoted ON production.* rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'production schema'),
        'privilege fence: unquoted ON production.* rejected'
    );
}

// Package backend compatibility
$pdoCompat = orange_restore_package_staging_import_compat(
    $packageDir,
    json_decode((string) file_get_contents($packageDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($pdoCompat['ok'] === true, 'package compat: php_pdo package accepted');

$mysqldumpDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_mysqldump';
mkdir($mysqldumpDir, 0775, true);
restore_staging_write_full_package($mysqldumpDir, ['manifest' => ['export_backend' => 'php_mysqldump']]);
$mysqldumpCompat = orange_restore_package_staging_import_compat(
    $mysqldumpDir,
    json_decode((string) file_get_contents($mysqldumpDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($mysqldumpCompat['ok'] === false, 'package compat: php_mysqldump rejected before mutation');

$delimiterDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_delimiter';
mkdir($delimiterDir, 0775, true);
restore_staging_write_full_package($delimiterDir);
$delimiterDump = $delimiterDir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
restore_staging_write_gzip_sql($delimiterDump, "DELIMITER ;;\nCREATE TABLE x (id INT);;\nDELIMITER ;\n");
$delimiterCompat = orange_restore_package_staging_import_compat(
    $delimiterDir,
    json_decode((string) file_get_contents($delimiterDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($delimiterCompat['ok'] === false, 'package compat: DELIMITER dump rejected before mutation');

// Staging credentials fail closed
try {
    orange_restore_staging_credentials([ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName], $projectRoot);
    restore_staging_self_test(false, 'staging creds: missing ORANGE_RESTORE_STAGING_DB_USER rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'ORANGE_RESTORE_STAGING_DB_USER'), 'staging creds: missing user rejected');
}

$productionCreds = orange_restore_production_db_credentials($projectRoot);
try {
    orange_restore_staging_credentials([
        ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName,
        ORANGE_RESTORE_ENV_STAGING_DB_USER => $productionCreds['user'],
        ORANGE_RESTORE_ENV_STAGING_DB_PASS => 'x',
    ], $projectRoot);
    restore_staging_self_test(false, 'staging creds: production DB_USER reuse rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'must not equal production DB_USER'), 'staging creds: production DB_USER reuse rejected');
}

// Lock: active preserved, stale cleared
$lockWork = $backupRoot . DIRECTORY_SEPARATOR . 'lock_work';
mkdir($lockWork, 0775, true);
$activeLockPath = orange_restore_global_lock_path($lockWork);
$activePayload = json_encode([
    'pid' => getmypid(),
    'hostname' => php_uname('n'),
    'job_id' => 'active_job',
    'started_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($activeLockPath, $activePayload . "\n");
$activeAgain = orange_restore_acquire_lock($lockWork, 'blocked');
restore_staging_self_test($activeAgain['ok'] === false, 'lock: active lock preserved');
@unlink($activeLockPath);

$stalePayload = json_encode([
    'pid' => 999999,
    'hostname' => 'stale-host',
    'job_id' => 'stale_job',
    'started_at' => gmdate('c', time() - ORANGE_RESTORE_LOCK_STALE_SECONDS - 60),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($activeLockPath, $stalePayload . "\n");
restore_staging_self_test(orange_restore_lock_is_stale(json_decode($stalePayload, true)), 'lock: stale lock detected');
$staleAcquire = orange_restore_acquire_lock($lockWork, 'after_stale');
restore_staging_self_test($staleAcquire['ok'] === true, 'lock: stale lock cleared and re-acquired');
orange_restore_release_lock($lockWork);

// Job lifecycle enforcement
$lifeJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
    'source_package_path' => $packageDir,
    'source_package_checksum' => str_repeat('e', 64),
    'package_version' => '1.2',
    'schema_revision' => 121,
    'approval_phrase_expected' => 'RESTORE',
]);
$lifeJobId = (string) ($lifeJob['job_id'] ?? '');
try {
    orange_restore_job_transition($workRoot, $lifeJobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    restore_staging_self_test(false, 'job lifecycle: invalid jump created->staging rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'Invalid full-disaster restore job transition'), 'job lifecycle: invalid transition rejected');
}

orange_restore_job_transition($workRoot, $lifeJobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED);
$failedStageJob = orange_restore_job_mark_failed($workRoot, $lifeJobId, 'package_compat', 'simulated', false);
restore_staging_self_test(($failedStageJob['stage_failed'] ?? '') === 'package_compat', 'job lifecycle: exact failed stage recorded');

// Orchestrator abort paths (no staging mutation)
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'missing_pkg',
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: missing package aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: missing package aborts');
}

$failPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_fail';
mkdir($failPkgDir, 0775, true);
restore_staging_write_full_package($failPkgDir, [
    'health' => ['package_status' => 'failed', 'failure_reasons' => ['simulated']],
]);
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $failPkgDir,
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: failed validation aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: failed validation aborts');
}

// Upload restore
$uploadsTarget = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_target';
mkdir($uploadsTarget, 0775, true);
$uploadsZipWithFile = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_with_file.zip';
$zip = new ZipArchive();
$zip->open($uploadsZipWithFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('products/demo.txt', 'restore-upload-test');
$zip->close();
restore_staging_self_test(orange_restore_uploads_applicator_extract($uploadsZipWithFile, $uploadsTarget)['ok'] === true, 'uploads applicator: extract success');

// CLI: --skip-fresh-backup unavailable
$cliScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_full_to_staging.php';
$cliPhp = (PHP_BINARY !== '' ? PHP_BINARY : 'php');
$cliOutput = [];
$cliExit = 0;
exec(escapeshellarg($cliPhp) . ' ' . escapeshellarg($cliScript) . ' --package=x --skip-fresh-backup 2>&1', $cliOutput, $cliExit);
restore_staging_self_test($cliExit === 2, 'cli: --skip-fresh-backup rejected');
restore_staging_self_test(
    str_contains(implode("\n", $cliOutput), 'not supported'),
    'cli: skip-fresh-backup error message'
);

restore_staging_rmdir($backupRoot);

echo PHP_EOL . ($failures === 0 ? 'ALL RESTORE STAGING SELF-TESTS PASSED' : "FAILURES: {$failures}") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

function restore_staging_fixture_layout(): array
{
    $backupRoot = restore_staging_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_ok';
    mkdir($workRoot, 0775, true);
    mkdir($packageDir, 0775, true);
    restore_staging_write_full_package($packageDir);

    return [
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_dir' => $packageDir,
    ];
}
