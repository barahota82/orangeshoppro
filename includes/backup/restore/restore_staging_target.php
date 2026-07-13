<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/../backup_environment.php';

const ORANGE_RESTORE_ENV_STAGING_DB_USER = 'ORANGE_RESTORE_STAGING_DB_USER';
const ORANGE_RESTORE_ENV_STAGING_DB_PASS = 'ORANGE_RESTORE_STAGING_DB_PASS';

function orange_restore_log(string $message): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }
    fwrite(STDOUT, $message . PHP_EOL);
    if (function_exists('fflush')) {
        fflush(STDOUT);
    }
}

function orange_restore_production_db_name(string $projectRoot): string
{
    $settings = orange_backup_load_db_settings($projectRoot);

    return (string) $settings['name'];
}

/**
 * @return array{user:string,pass:string}
 */
function orange_restore_production_db_credentials(string $projectRoot): array
{
    $settings = orange_backup_load_db_settings($projectRoot);

    return [
        'user' => (string) $settings['user'],
        'pass' => (string) $settings['pass'],
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array{db:string,user:string,pass:string,host:string}
 */
function orange_restore_staging_credentials(array $env, string $projectRoot): array
{
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $stagingUser = trim((string) ($env[ORANGE_RESTORE_ENV_STAGING_DB_USER] ?? ''));
    $stagingPass = (string) ($env[ORANGE_RESTORE_ENV_STAGING_DB_PASS] ?? '');

    if ($stagingUser === '') {
        throw new RuntimeException('ORANGE_RESTORE_STAGING_DB_USER is not configured in .env.php.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $stagingUser)) {
        throw new RuntimeException('ORANGE_RESTORE_STAGING_DB_USER contains invalid characters.');
    }

    $productionCreds = orange_restore_production_db_credentials($projectRoot);
    if (strcasecmp($stagingUser, $productionCreds['user']) === 0) {
        throw new RuntimeException(
            'ORANGE_RESTORE_STAGING_DB_USER must not equal production DB_USER ('
            . $productionCreds['user']
            . '). Use a dedicated staging-only MySQL account.'
        );
    }

    $settings = orange_backup_load_db_settings($projectRoot);

    return [
        'db' => $stagingDb,
        'user' => $stagingUser,
        'pass' => $stagingPass,
        'host' => (string) $settings['host'],
    ];
}

/**
 * @param array<string, mixed> $env
 */
function orange_restore_staging_db_name(array $env, string $projectRoot): string
{
    $stagingDb = trim((string) ($env[ORANGE_RESTORE_ENV_STAGING_DB] ?? ''));
    if ($stagingDb === '') {
        throw new RuntimeException('ORANGE_RESTORE_STAGING_DB is not configured in .env.php.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $stagingDb)) {
        throw new RuntimeException('ORANGE_RESTORE_STAGING_DB contains invalid characters.');
    }

    $productionDb = orange_restore_production_db_name($projectRoot);
    if (strcasecmp($stagingDb, $productionDb) === 0) {
        throw new RuntimeException(
            'ORANGE_RESTORE_STAGING_DB must not equal production database (' . $productionDb . ').'
        );
    }

    return $stagingDb;
}

/**
 * @param array<string, mixed> $env
 */
function orange_restore_connect_staging_pdo(string $projectRoot, array $env): PDO
{
    $creds = orange_restore_staging_credentials($env, $projectRoot);
    $dsn = 'mysql:host=' . $creds['host'] . ';dbname=' . $creds['db'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $creds['user'], $creds['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
    orange_restore_staging_assert_safe_target($pdo, $creds['db']);

    return $pdo;
}

function orange_restore_staging_assert_safe_target(PDO $pdo, string $expectedDb): void
{
    $current = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($current === '' || strcasecmp($current, $expectedDb) !== 0) {
        throw new RuntimeException(
            'Staging PDO session database mismatch (expected '
            . $expectedDb
            . ', got '
            . ($current === '' ? '(empty)' : $current)
            . ').'
        );
    }
}

/**
 * Evaluate SHOW GRANTS lines for production/global privilege violations.
 *
 * @param list<string> $grantLines
 */
function orange_restore_staging_validate_grant_lines(array $grantLines, string $productionDb): void
{
    if ($grantLines === []) {
        throw new RuntimeException(
            'Cannot inspect staging user privileges (SHOW GRANTS returned no rows).'
        );
    }

    $productionNeedle = '`' . str_replace('`', '``', $productionDb) . '`';
    $productionPattern = '/\sON\s+(?:`'
        . preg_quote(str_replace('`', '``', $productionDb), '/')
        . '`|'
        . preg_quote($productionDb, '/')
        . ')\s*\./i';

    foreach ($grantLines as $grant) {
        $grant = trim($grant);
        if ($grant === '') {
            continue;
        }
        if (
            stripos($grant, ' ON ' . $productionNeedle . '.') !== false
            || stripos($grant, ' ON ' . $productionNeedle . '.*') !== false
            || preg_match($productionPattern, $grant) === 1
            || stripos($grant, ' ON *.*') !== false
        ) {
            throw new RuntimeException(
                'Staging DB user has detectable privilege on production schema ('
                . $productionDb
                . '). Grant staging-only access per runbook.'
            );
        }
    }
}

/**
 * Detectable privilege fence: staging user must not hold schema privileges on production DB.
 */
function orange_restore_staging_assert_no_production_privileges(
    PDO $pdo,
    string $stagingDb,
    string $productionDb
): void {
    unset($stagingDb);

    try {
        $grantSt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Cannot inspect staging user privileges (SHOW GRANTS unavailable): ' . $e->getMessage()
        );
    }

    if ($grantSt === false) {
        throw new RuntimeException(
            'Cannot inspect staging user privileges (SHOW GRANTS returned false).'
        );
    }

    $grantLines = [];
    while ($row = $grantSt->fetch(PDO::FETCH_NUM)) {
        if (is_array($row) && isset($row[0])) {
            $line = trim((string) $row[0]);
            if ($line !== '') {
                $grantLines[] = $line;
            }
        }
    }

    orange_restore_staging_validate_grant_lines($grantLines, $productionDb);
}

/**
 * Pre-wipe staging target confirmation (credentials, database name, privilege fence).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_restore_staging_confirm_target(string $projectRoot, array $env): array
{
    $productionDb = orange_restore_production_db_name($projectRoot);
    $creds = orange_restore_staging_credentials($env, $projectRoot);
    $pdo = orange_restore_connect_staging_pdo($projectRoot, $env);
    orange_restore_staging_assert_no_production_privileges($pdo, $creds['db'], $productionDb);

    return [
        'staging_db' => $creds['db'],
        'staging_user' => $creds['user'],
        'production_db' => $productionDb,
        'host' => $creds['host'],
        'session_database' => (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''),
        'confirmed_at' => gmdate('c'),
    ];
}

function orange_restore_staging_wipe(PDO $pdo, string $stagingDb): void
{
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
    orange_restore_log('Staging wipe... START');
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
        orange_restore_staging_assert_safe_target($pdo, $stagingDb);
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
    orange_restore_log('Staging wipe... OK (tables_dropped=' . (string) count($tables) . ')');
}

function orange_restore_staging_uploads_directory(string $workRoot, string $jobId): string
{
    $dir = orange_restore_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . 'staging_uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create staging uploads directory.');
    }
    orange_restore_assert_inside_work_root($workRoot, $dir);

    return realpath($dir) ?: $dir;
}
