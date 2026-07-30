<?php

declare(strict_types=1);

/**
 * D5 isolated worker: Full Restore → staging.
 * Args: runtime_root package_path result_json_path
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$runtimeRoot = (string) ($argv[1] ?? '');
$packagePath = (string) ($argv[2] ?? '');
$resultPath = (string) ($argv[3] ?? '');
if ($runtimeRoot === '' || $packagePath === '' || $resultPath === '') {
    fwrite(STDERR, "Usage: staging_worker runtime package result.json\n");
    exit(2);
}

require_once $runtimeRoot . '/config.php';
require_once $runtimeRoot . '/includes/catalog_schema.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_full_staging.php';

$out = ['ok' => false, 'error' => 'unknown'];
try {
    // Schema already sealed at 124 in D5 fixture — avoid migration catch-up during fresh gate.
    $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_stg_' . getmypid() . '.flag';
    file_put_contents($flagPath, '124');
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    $staging = orange_restore_full_staging_run([
        'project_root' => $runtimeRoot,
        'package_path' => $packagePath,
    ]);
    $out = [
        'ok' => !empty($staging['ok']),
        'job_id' => (string) ($staging['job_id'] ?? ''),
        'message' => (string) ($staging['message'] ?? ''),
        'error' => (string) ($staging['error'] ?? ''),
        'staging' => $staging,
    ];
    if (empty($out['ok']) && $out['error'] === '' && isset($staging['errors'])) {
        $out['error'] = is_array($staging['errors']) ? implode('; ', $staging['errors']) : (string) $staging['errors'];
    }
} catch (Throwable $e) {
    $out = ['ok' => false, 'error' => $e->getMessage()];
}

file_put_contents($resultPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(!empty($out['ok']) ? 0 : 1);
