<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/../backup_environment.php';

/**
 * Load and validate dedicated production-merge MySQL credentials (fail closed).
 *
 * @param array<string, mixed> $env
 * @return array{user:string,pass:string,db:string,host:string}
 */
function orange_restore_merge_credentials(array $env, string $projectRoot): array
{
    $mergeUser = trim((string) ($env[ORANGE_RESTORE_ENV_MERGE_DB_USER] ?? ''));
    $mergePass = (string) ($env[ORANGE_RESTORE_ENV_MERGE_DB_PASS] ?? '');

    if ($mergeUser === '') {
        throw new RuntimeException('ORANGE_RESTORE_MERGE_DB_USER is not configured in .env.php.');
    }
    if ($mergePass === '') {
        throw new RuntimeException('ORANGE_RESTORE_MERGE_DB_PASS is not configured in .env.php.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $mergeUser)) {
        throw new RuntimeException('ORANGE_RESTORE_MERGE_DB_USER contains invalid characters.');
    }

    $productionDb = orange_restore_production_db_name($projectRoot);
    $productionCreds = orange_restore_production_db_credentials($projectRoot);
    $stagingUser = trim((string) ($env[ORANGE_RESTORE_ENV_STAGING_DB_USER] ?? ''));

    if (strcasecmp($mergeUser, $productionCreds['user']) === 0) {
        throw new RuntimeException(
            'ORANGE_RESTORE_MERGE_DB_USER must not equal production DB_USER ('
            . $productionCreds['user']
            . ').'
        );
    }
    if ($stagingUser !== '' && strcasecmp($mergeUser, $stagingUser) === 0) {
        throw new RuntimeException(
            'ORANGE_RESTORE_MERGE_DB_USER must not equal ORANGE_RESTORE_STAGING_DB_USER.'
        );
    }

    $settings = orange_backup_load_db_settings($projectRoot);

    return [
        'user' => $mergeUser,
        'pass' => $mergePass,
        'db' => $productionDb,
        'host' => (string) $settings['host'],
    ];
}

/**
 * @param array<string, mixed> $env
 */
function orange_restore_connect_merge_pdo(string $projectRoot, array $env): PDO
{
    $creds = orange_restore_merge_credentials($env, $projectRoot);
    $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $creds['db'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');

    return $pdo;
}

function orange_restore_production_assert_identity(PDO $pdo, string $expectedDb): void
{
    $current = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($current === '' || strcasecmp($current, $expectedDb) !== 0) {
        throw new RuntimeException(
            'Production merge PDO session database mismatch (expected '
            . $expectedDb
            . ', got '
            . ($current === '' ? '(empty)' : $current)
            . ').'
        );
    }

    $pdo->query('SELECT 1')->fetchColumn();
}

/**
 * Read-only production target verification for merge foundation (no SQL mutation).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_production_verify_target(string $projectRoot, array $env, ?PDO $pdoOverride = null): array
{
    $creds = orange_restore_merge_credentials($env, $projectRoot);
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);

    if (strcasecmp($creds['db'], $stagingDb) === 0) {
        throw new RuntimeException('Production database name must differ from ORANGE_RESTORE_STAGING_DB.');
    }

    $pdo = $pdoOverride ?? orange_restore_connect_merge_pdo($projectRoot, $env);
    orange_restore_production_assert_identity($pdo, $creds['db']);

    return [
        'production_db' => $creds['db'],
        'merge_user' => $creds['user'],
        'host' => $creds['host'],
        'session_database' => (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''),
        'staging_db' => $stagingDb,
        'verified_at' => gmdate('c'),
        'production_writes' => false,
    ];
}

/**
 * Wipe all tables in the configured production schema only (merge credentials required).
 */
function orange_restore_production_wipe(PDO $pdo, string $productionDb): void
{
    orange_restore_production_assert_identity($pdo, $productionDb);
    orange_restore_log('Production wipe... START');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $tables = [];
    $st = $pdo->query('SHOW TABLES');
    if ($st !== false) {
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            if (is_array($row) && isset($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
    }
    foreach ($tables as $table) {
        orange_restore_production_assert_identity($pdo, $productionDb);
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    orange_restore_production_assert_identity($pdo, $productionDb);
    orange_restore_log('Production wipe... OK (tables=' . (string) count($tables) . ')');
}
