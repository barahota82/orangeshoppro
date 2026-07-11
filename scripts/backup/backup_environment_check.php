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
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';

$report = orange_backup_collect_environment_report($projectRoot);

$lines = [
    'php_version=' . ($report['php_version'] ?? ''),
    'php_sapi=' . ($report['php_sapi'] ?? ''),
    'project_root=' . ($report['project_root'] ?? ''),
    'backup_root=' . ($report['backup_root'] ?? ''),
    'backup_root_writable=' . (!empty($report['backup_root_writable']) ? 'yes' : 'no'),
    'powershell_available=' . (!empty($report['powershell_available']) ? 'yes' : 'no'),
    'powershell_path=' . ($report['powershell_path'] ?? ''),
    'powershell_ready=' . (!empty($report['powershell_ready']) ? 'yes' : 'no'),
    'mysqldump_available=' . (!empty($report['mysqldump_available']) ? 'yes' : 'no'),
    'mysqldump_path=' . ($report['mysqldump_path'] ?? ''),
    'gzip_supported=' . (!empty($report['gzip_supported']) ? 'yes' : 'no'),
    'ziparchive_supported=' . (!empty($report['ziparchive_supported']) ? 'yes' : 'no'),
    'proc_open_available=' . (!empty($report['proc_open_available']) ? 'yes' : 'no'),
    'shell_exec_available=' . (!empty($report['shell_exec_available']) ? 'yes' : 'no'),
    'uploads_path=' . ($report['uploads_path'] ?? ''),
    'uploads_readable=' . (!empty($report['uploads_readable']) ? 'yes' : 'no'),
    'database_connected=' . (!empty($report['database_connected']) ? 'yes' : 'no'),
    'schema_revision=' . ($report['schema_revision'] ?? ''),
    'selected_backend=' . ($report['selected_backend'] ?? ''),
    'can_run_full_backup=' . (!empty($report['can_run_full_backup']) ? 'yes' : 'no'),
];

foreach ($lines as $line) {
    echo $line . PHP_EOL;
}

$blockers = $report['blockers'] ?? [];
if (is_array($blockers) && $blockers !== []) {
    echo 'blockers=' . implode(' | ', $blockers) . PHP_EOL;
}

exit(!empty($report['can_run_full_backup']) ? 0 : 1);
