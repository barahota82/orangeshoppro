<?php

declare(strict_types=1);

/**
 * Production deployment preflight for Full Restore certification (fail closed).
 *
 * Validates host capability + path/identity fences. Never mutates production data.
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/restore_paths.php';

const ORANGE_RESTORE_DEPLOYMENT_PREFLIGHT_VERSION = '3B.4M-preflight-v1';

/**
 * @param array<string, mixed> $options
 * @return array{ok:bool,version:string,checks:list<array<string,mixed>>,blockers:list<string>}
 */
function orange_restore_deployment_preflight_run(array $options = []): array
{
    $projectRoot = (string) ($options['project_root'] ?? dirname(__DIR__, 3));
    $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR . '/\\');
    $checks = [];
    $blockers = [];

    $add = static function (string $id, bool $ok, string $message, bool $blocking = true) use (&$checks, &$blockers): void {
        $checks[] = ['id' => $id, 'ok' => $ok, 'message' => $message, 'blocking' => $blocking];
        if (!$ok && $blocking) {
            $blockers[] = $id . ':' . $message;
        }
    };

    $add('php_sapi_info', true, 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY, false);
    $add('ziparchive', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'ZipArchive available' : 'ZipArchive missing');
    $add('zlib', function_exists('gzopen') || function_exists('gzencode'), function_exists('gzencode') ? 'zlib/gzip available' : 'zlib missing');
    $add('pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'pdo_mysql loaded' : 'pdo_mysql missing');
    $add('json', function_exists('json_encode'), 'json extension');
    $add('hash_sha256', in_array('sha256', hash_algos(), true), 'sha256 available');

    $envName = '';
    if (defined('ORANGE_ENVIRONMENT')) {
        $envName = strtolower((string) ORANGE_ENVIRONMENT);
    } elseif (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
        $raw = (string) @file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.env.php');
        if (preg_match("/['\"]ORANGE_ENVIRONMENT['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $raw, $m) === 1) {
            $envName = strtolower(trim($m[1]));
        }
    }
    $envOk = $envName === '' || in_array($envName, ['production', 'clone', 'staging', 'development'], true);
    $add(
        'environment_identity',
        $envOk,
        $envName !== '' ? 'ORANGE_ENVIRONMENT=' . $envName : 'ORANGE_ENVIRONMENT unset (operator must confirm host identity)',
        $envName !== '' && !$envOk
    );

    $dbName = '';
    if (defined('DB_NAME')) {
        $dbName = (string) DB_NAME;
    } elseif (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
        $raw = (string) @file_get_contents($projectRoot . DIRECTORY_SEPARATOR . '.env.php');
        if (preg_match("/['\"]DB_NAME['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $raw, $m) === 1) {
            $dbName = trim($m[1]);
        } elseif (preg_match("/define\\s*\\(\\s*['\"]DB_NAME['\"]\\s*,\\s*['\"]([^'\"]+)['\"]/", $raw, $m) === 1) {
            $dbName = trim($m[1]);
        }
    }
    if ($dbName === '') {
        $dbName = 'orange_db';
    }
    $add('db_identity_configured', $dbName !== '', 'DB_NAME=' . $dbName);
    $add(
        'db_identity_not_fixture',
        !str_starts_with(strtolower($dbName), 'orange_dr_fixture')
        && !str_starts_with(strtolower($dbName), 'orange_clone_'),
        'DB_NAME is not a drill/clone fixture name'
    );

    $backupRoot = '';
    $workRoot = '';
    try {
        $view = orange_backup_admin_resolve_root_for_view($projectRoot);
        $backupRoot = (string) ($view['backup_root'] ?? '');
        $env = is_array($view['env'] ?? null) ? $view['env'] : [];
        if (function_exists('orange_restore_resolve_work_root')) {
            $workRoot = (string) orange_restore_resolve_work_root($env);
        }
    } catch (Throwable $e) {
        $backupRoot = '';
        $workRoot = '';
    }

    $add('backup_root_configured', $backupRoot !== '' && is_dir($backupRoot), $backupRoot !== '' ? $backupRoot : 'backup root missing');
    $add('restore_work_root_configured', $workRoot !== '' && is_dir($workRoot), $workRoot !== '' ? $workRoot : 'restore work root missing');

    if ($backupRoot !== '' && is_dir($backupRoot)) {
        $add('backup_root_writable', is_writable($backupRoot), 'backup root writable');
    }
    if ($workRoot !== '' && is_dir($workRoot)) {
        $add('work_root_writable', is_writable($workRoot), 'work root writable');
    }

    $uploads = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
    $add('uploads_dir_present', is_dir($uploads), is_dir($uploads) ? 'uploads/ present' : 'uploads/ missing', false);

    $ok = $blockers === [];

    return [
        'ok' => $ok,
        'version' => ORANGE_RESTORE_DEPLOYMENT_PREFLIGHT_VERSION,
        'generated_at' => gmdate('c'),
        'project_root' => $projectRoot,
        'db_name' => $dbName,
        'environment' => $envName,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'checks' => $checks,
        'blockers' => $blockers,
        'fail_closed' => true,
    ];
}
