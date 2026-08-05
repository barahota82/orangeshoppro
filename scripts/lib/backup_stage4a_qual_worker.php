<?php

declare(strict_types=1);

/**
 * Stage 4A test-only worker — separate PHP process for lock/concurrency proofs.
 *
 * Usage:
 *   php scripts/lib/backup_stage4a_qual_worker.php <backupRoot> <packageType> <packageId> <action> [countryCode] [holdSeconds]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_qualification.php';

$backupRoot = (string) ($argv[1] ?? '');
$packageType = (string) ($argv[2] ?? '');
$packageId = (string) ($argv[3] ?? '');
$action = (string) ($argv[4] ?? '');
$countryCode = (string) ($argv[5] ?? '');
if ($countryCode === '-' || $countryCode === '_') {
    $countryCode = '';
}
$holdSeconds = max(0, (int) ($argv[6] ?? 2));

if ($backupRoot === '' || $packageId === '' || $action === '') {
    fwrite(STDERR, "usage: worker backupRoot packageType packageId action [country] [holdSeconds]\n");
    exit(2);
}

$lock = orange_backup_qualification_acquire_lock($backupRoot, $packageType, $packageId, $action, $countryCode);
$out = [
    'acquired' => (bool) $lock['acquired'],
    'in_progress' => (bool) ($lock['in_progress'] ?? false),
    'reason' => (string) ($lock['reason'] ?? ''),
    'path' => (string) ($lock['path'] ?? ''),
    'pid' => getmypid(),
];
fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
fflush(STDOUT);
if (!$lock['acquired']) {
    $locked = !empty($lock['in_progress']) || ($lock['reason'] ?? '') === 'qualification_locked';
    exit($locked ? 3 : 1);
}
if ($holdSeconds > 0) {
    sleep($holdSeconds);
}
orange_backup_qualification_release_lock((string) $lock['path']);
exit(0);
