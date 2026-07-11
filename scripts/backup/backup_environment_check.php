<?php

declare(strict_types=1);

/**
 * Read-only backup environment diagnostics (no secrets).
 *
 * Usage:
 *   php scripts/backup/backup_environment_check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
$env = [];
$envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
if (is_file($envPath)) {
    $loaded = require $envPath;
    if (is_array($loaded)) {
        $env = $loaded;
    }
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';

if (in_array('--self-test-extensions', $_SERVER['argv'] ?? [], true)) {
    exit(orange_backup_extension_checks_self_test() ? 0 : 1);
}

try {
    $report = orange_backup_collect_environment_report($projectRoot);

    $bool = static fn (bool $value): string => $value ? 'yes' : 'no';

    $lines = [
        'php_version=' . ($report['php_version'] ?? ''),
        'php_sapi=' . ($report['php_sapi'] ?? ''),
        'project_root=' . ($report['project_root'] ?? ''),
        'orange_backup_root_configured=' . $bool(!empty($report['orange_backup_root_configured'])),
        'backup_root_candidate=' . ($report['backup_root_candidate'] ?? ''),
        'backup_root=' . ($report['backup_root'] ?? ''),
        'backup_root_writable=' . $bool(!empty($report['backup_root_writable'])),
        'backup_root_error=' . ($report['backup_root_error'] ?? ''),
        'open_basedir=' . ($report['open_basedir'] ?? ''),
        'proc_open_available=' . $bool(!empty($report['proc_open_available'])),
        'shell_exec_available=' . $bool(!empty($report['shell_exec_available'])),
        'configured_mysqldump_path_present=' . $bool(!empty($report['configured_mysqldump_path_present'])),
        'configured_powershell_path_present=' . $bool(!empty($report['configured_powershell_path_present'])),
        'mysqldump_detection_source=' . ($report['mysqldump_detection_source'] ?? ''),
        'powershell_detection_source=' . ($report['powershell_detection_source'] ?? ''),
        'mysqldump_available=' . $bool(!empty($report['mysqldump_available'])),
        'mysqldump_path=' . ($report['mysqldump_path'] ?? ''),
        'powershell_available=' . $bool(!empty($report['powershell_available'])),
        'powershell_path=' . ($report['powershell_path'] ?? ''),
        'gzip_supported=' . $bool(!empty($report['gzip_supported'])),
        'ziparchive_supported=' . $bool(!empty($report['ziparchive_supported'])),
        'uploads_readable=' . $bool(!empty($report['uploads_readable'])),
        'database_connected=' . $bool(!empty($report['database_connected'])),
        'schema_revision=' . ($report['schema_revision'] ?? ''),
        'selected_backend=' . ($report['selected_backend'] ?? ''),
        'can_run_full_backup=' . $bool(!empty($report['can_run_full_backup'])),
    ];

    foreach ($lines as $line) {
        echo $line . PHP_EOL;
    }

    $warnings = $report['warnings'] ?? [];
    if (is_array($warnings) && $warnings !== []) {
        echo 'warnings=' . implode(' | ', $warnings) . PHP_EOL;
    }

    $blockers = $report['blockers'] ?? [];
    if (is_array($blockers) && $blockers !== []) {
        echo 'blockers=' . implode(' | ', $blockers) . PHP_EOL;
    }

    exit(!empty($report['can_run_full_backup']) ? 0 : 1);
} catch (Throwable $e) {
    orange_backup_cli_render_error($e, $projectRoot, $env, 'backup_environment_check.php');
    exit(1);
}
