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
 * @return list<string>
 */
function orange_restore_production_merge_grant_lines(PDO $pdo): array
{
    try {
        $grantSt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Cannot inspect production merge user privileges (SHOW GRANTS unavailable): ' . $e->getMessage()
        );
    }

    if ($grantSt === false) {
        throw new RuntimeException('Cannot inspect production merge user privileges (SHOW GRANTS returned false).');
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

    return $grantLines;
}

/**
 * @param list<string> $grantLines
 */
function orange_restore_production_validate_merge_grant_lines(
    array $grantLines,
    string $productionDb,
    string $stagingDb
): void {
    if ($grantLines === []) {
        throw new RuntimeException(
            'Cannot inspect production merge user privileges (SHOW GRANTS returned no rows).'
        );
    }

    $hasProductionGrant = false;
    foreach ($grantLines as $grant) {
        $grant = trim($grant);
        if ($grant === '') {
            continue;
        }

        if (preg_match('/^GRANT\s+USAGE\s+ON\s+\*\.\*/i', $grant) === 1) {
            continue;
        }
        if (preg_match('/\sON\s+\*\.\*/i', $grant) === 1) {
            throw new RuntimeException('Production merge user must not have global database privileges.');
        }
        if (preg_match('/\sON\s+(?:`([^`]+)`|([A-Za-z0-9_]+))\s*\.\s*(?:\*|`[^`]+`|[A-Za-z0-9_]+)/i', $grant, $m) === 1) {
            $db = (string) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
            if (strcasecmp($db, $productionDb) === 0) {
                $hasProductionGrant = true;
                continue;
            }
            if (strcasecmp($db, $stagingDb) === 0) {
                throw new RuntimeException('Production merge user must not have privileges on staging database.');
            }

            throw new RuntimeException('Production merge user has privileges outside production schema: ' . $db);
        }
    }

    if (!$hasProductionGrant) {
        throw new RuntimeException('Production merge user has no detectable production-schema grant.');
    }
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
    orange_restore_production_validate_merge_grant_lines(
        orange_restore_production_merge_grant_lines($pdo),
        $creds['db'],
        $stagingDb
    );

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
