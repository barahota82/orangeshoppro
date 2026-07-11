<?php

declare(strict_types=1);

/**
 * Read-only backup environment diagnostics (no secrets).
 *
 * Usage:
 *   php scripts/backup/backup_environment_check.php
 *   php scripts/backup/backup_environment_check.php --output=C:\temp\backup_environment.txt
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/**
 * @param list<string> $argv
 */
function backup_environment_check_parse_output_path(array $argv): ?string
{
    foreach ($argv as $arg) {
        if (!is_string($arg) || !str_starts_with($arg, '--output=')) {
            continue;
        }
        $path = trim(substr($arg, strlen('--output=')));

        return $path !== '' ? $path : null;
    }

    return null;
}

/**
 * @param list<string> $lines
 */
function backup_environment_check_write_report_file(string $path, array $lines): void
{
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create output directory: ' . $dir);
        }
    }

    $content = implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write output file: ' . $path);
    }
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
    ];

    $probe = orange_backup_probe_directory_path((string) ($report['backup_root_candidate'] ?? ''));
    if ($probe !== []) {
        $probeLines = [
            'backup_root_probe_configured_path=' . ($probe['configured_path'] ?? ''),
            'backup_root_probe_normalized_path=' . ($probe['normalized_path'] ?? ''),
            'backup_root_probe_realpath=' . ($probe['realpath'] ?? ''),
            'backup_root_probe_realpath_ok=' . (!empty($probe['realpath_ok']) ? 'yes' : 'no'),
            'backup_root_probe_is_dir_configured=' . (!empty($probe['is_dir_configured']) ? 'yes' : 'no'),
            'backup_root_probe_is_dir_normalized=' . (!empty($probe['is_dir_normalized']) ? 'yes' : 'no'),
            'backup_root_probe_file_exists_configured=' . (!empty($probe['file_exists_configured']) ? 'yes' : 'no'),
            'backup_root_probe_dirname_configured=' . ($probe['dirname_configured'] ?? ''),
            'backup_root_probe_getcwd=' . ($probe['getcwd'] ?? ''),
        ];
        $lines = array_merge($lines, $probeLines);
    }

    $lines = array_merge($lines, [
        'backup_root=' . ($report['backup_root'] ?? ''),
        'backup_root_writable=' . $bool(!empty($report['backup_root_writable'])),
        'backup_root_error=' . ($report['backup_root_error'] ?? ''),
        'open_basedir=' . ($report['open_basedir'] ?? ''),
        'proc_open_available=' . $bool(!empty($report['proc_open_available'])),
        'shell_exec_available=' . $bool(!empty($report['shell_exec_available'])),
        'configured_mysqldump_path_present=' . $bool(!empty($report['configured_mysqldump_path_present'])),
        'configured_powershell_path_present=' . $bool(!empty($report['configured_powershell_path_present'])),
        'mysqldump_detection_source=' . ($report['mysqldump_detection_source'] ?? ''),
        'mysqldump_attempted_paths=' . ($report['mysqldump_attempted_paths'] ?? ''),
        'mysqldump_suggested_env_path=' . ($report['mysqldump_suggested_env_path'] ?? ''),
        'mysqldump_available=' . $bool(!empty($report['mysqldump_available'])),
        'mysqldump_path=' . ($report['mysqldump_path'] ?? ''),
        'powershell_detection_source=' . ($report['powershell_detection_source'] ?? ''),
        'powershell_available=' . $bool(!empty($report['powershell_available'])),
        'powershell_path=' . ($report['powershell_path'] ?? ''),
        'gzip_supported=' . $bool(!empty($report['gzip_supported'])),
        'ziparchive_supported=' . $bool(!empty($report['ziparchive_supported'])),
        'uploads_readable=' . $bool(!empty($report['uploads_readable'])),
        'database_connected=' . $bool(!empty($report['database_connected'])),
        'schema_revision=' . ($report['schema_revision'] ?? ''),
        'selected_backend=' . ($report['selected_backend'] ?? ''),
        'can_run_full_backup=' . $bool(!empty($report['can_run_full_backup'])),
    ]);

    $warnings = $report['warnings'] ?? [];
    if (is_array($warnings) && $warnings !== []) {
        $lines[] = 'warnings=' . implode(' | ', $warnings);
    }

    $blockers = $report['blockers'] ?? [];
    if (is_array($blockers) && $blockers !== []) {
        $lines[] = 'blockers=' . implode(' | ', $blockers);
    }

    foreach ($lines as $line) {
        echo $line . PHP_EOL;
    }

    $outputPath = backup_environment_check_parse_output_path($_SERVER['argv'] ?? []);
    if ($outputPath !== null) {
        backup_environment_check_write_report_file($outputPath, $lines);
    }

    exit(!empty($report['can_run_full_backup']) ? 0 : 1);
} catch (Throwable $e) {
    orange_backup_cli_render_error($e, $projectRoot, $env, 'backup_environment_check.php');
    exit(1);
}
