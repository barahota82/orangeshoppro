<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/../backup_environment.php';

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
    $settings = orange_backup_load_db_settings($projectRoot);
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $dsn = 'mysql:host=' . $settings['host'] . ';dbname=' . $stagingDb . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET NAMES utf8mb4');
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);

    return $pdo;
}

function orange_restore_staging_assert_safe_target(PDO $pdo, string $expectedDb): void
{
    $current = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($current === '' || strcasecmp($current, $expectedDb) !== 0) {
        throw new RuntimeException('Staging PDO is not connected to expected database.');
    }
}

function orange_restore_staging_wipe(PDO $pdo): void
{
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
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $pdo->exec('DROP TABLE IF EXISTS ' . $quoted);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
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
