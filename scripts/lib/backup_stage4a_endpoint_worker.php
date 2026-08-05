<?php

declare(strict_types=1);

/**
 * Stage 4A test-only worker — runs the exact endpoint Verify/DRV sequence
 * (orange_backup_qualification_endpoint_*) in a separate PHP process.
 *
 * Usage:
 *   php scripts/lib/backup_stage4a_endpoint_worker.php <backupRoot> <packageType> <packageId> <action> [countryCode] [holdBeforeMs]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
putenv('ORANGE_QUAL_SILENCE_DRV_LOG=1');
$_ENV['ORANGE_QUAL_SILENCE_DRV_LOG'] = '1';
if (!function_exists('audit_log')) {
    /**
     * @param mixed $entityId
     */
    function audit_log(string $action, string $message = '', ?string $entityTable = null, $entityId = null): void
    {
    }
}
require_once $projectRoot . '/includes/backup/backup_qualification.php';

$backupRoot = (string) ($argv[1] ?? '');
$packageType = (string) ($argv[2] ?? '');
$packageId = (string) ($argv[3] ?? '');
$action = (string) ($argv[4] ?? '');
$countryCode = (string) ($argv[5] ?? '');
if ($countryCode === '-' || $countryCode === '_') {
    $countryCode = '';
}
$holdBeforeMs = max(0, (int) ($argv[6] ?? 0));

if ($backupRoot === '' || $packageId === '' || $action === '') {
    fwrite(STDERR, "usage: endpoint_worker backupRoot packageType packageId action [country] [holdBeforeMs]\n");
    exit(2);
}

if ($packageType === 'full_disaster') {
    $packagePath = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $packageId;
} else {
    $packagePath = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR
        . strtolower($countryCode) . DIRECTORY_SEPARATOR . $packageId;
}

if ($holdBeforeMs > 0) {
    usleep($holdBeforeMs * 1000);
}

if ($action === 'verify') {
    $run = orange_backup_qualification_endpoint_verify(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode,
        ['kind' => 'admin', 'admin_id' => 1]
    );
} elseif ($action === 'drv') {
    $run = orange_backup_qualification_endpoint_drv(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode
    );
} else {
    fwrite(STDERR, "unknown action\n");
    exit(2);
}

$out = [
    'pid' => getmypid(),
    'success' => (bool) ($run['success'] ?? false),
    'in_progress' => (bool) ($run['in_progress'] ?? false),
    'short_circuited' => (bool) ($run['short_circuited'] ?? false),
    'heavy_executed' => (bool) ($run['heavy_executed'] ?? false),
    'code' => (string) ($run['code'] ?? ''),
];
fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
fflush(STDOUT);
exit(!empty($run['in_progress']) ? 3 : (($run['success'] ?? false) ? 0 : 1));
