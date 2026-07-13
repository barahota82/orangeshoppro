<?php

declare(strict_types=1);

require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_validation_adapter.php';

/**
 * Create and verify a fresh full disaster backup; return rollback anchor metadata.
 *
 * @return array{ok:bool,snapshot_path:string,checksum:string,verify:array<string,mixed>,drv:array<string,mixed>,backup:array<string,mixed>,errors:list<string>}
 */
function orange_restore_fresh_backup_gate(string $projectRoot, ?string $backupRootOverride = null): array
{
    orange_restore_log('Fresh backup... START');
    $backup = orange_backup_run_full($projectRoot, $backupRootOverride);
    if (!$backup['ok'] || !is_string($backup['snapshot'] ?? null) || $backup['snapshot'] === '') {
        orange_restore_log('Fresh backup... FAIL');

        return [
            'ok' => false,
            'snapshot_path' => '',
            'checksum' => '',
            'verify' => [],
            'drv' => [],
            'backup' => $backup,
            'errors' => ['Fresh full disaster backup failed: ' . (string) ($backup['message'] ?? 'unknown')],
        ];
    }

    $snapshotPath = (string) $backup['snapshot'];
    orange_restore_log('Fresh backup verify...');
    $verify = orange_backup_verify_full_package($snapshotPath);
    if (!$verify['ok']) {
        orange_restore_log('Fresh backup verify... FAIL');

        return [
            'ok' => false,
            'snapshot_path' => $snapshotPath,
            'checksum' => '',
            'verify' => $verify,
            'drv' => [],
            'backup' => $backup,
            'errors' => $verify['errors'],
        ];
    }

    orange_restore_log('Fresh backup DRV...');
    $drv = orange_recovery_validate_package($snapshotPath);
    if ((int) ($drv['recovery_score'] ?? 0) < 70) {
        orange_restore_log('Fresh backup DRV... FAIL');

        return [
            'ok' => false,
            'snapshot_path' => $snapshotPath,
            'checksum' => '',
            'verify' => $verify,
            'drv' => $drv,
            'backup' => $backup,
            'errors' => ['Fresh backup recovery validation score below threshold.'],
        ];
    }

    $checksum = orange_restore_fresh_backup_anchor_checksum($snapshotPath, is_array($verify['manifest'] ?? null) ? $verify['manifest'] : []);
    orange_restore_log('Fresh backup... OK (rollback anchor recorded)');

    return [
        'ok' => true,
        'snapshot_path' => $snapshotPath,
        'checksum' => $checksum,
        'verify' => $verify,
        'drv' => $drv,
        'backup' => $backup,
        'errors' => [],
    ];
}

/**
 * @param array<string, mixed> $manifest
 */
function orange_restore_fresh_backup_anchor_checksum(string $snapshotPath, array $manifest): string
{
    $checksumFile = $snapshotPath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksumFile)) {
        return orange_backup_sha256_file($checksumFile);
    }
    $dumpSha = trim((string) ($manifest['dump_sha256'] ?? ''));
    if ($dumpSha !== '') {
        return $dumpSha;
    }

    $manifestPath = $snapshotPath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (is_file($manifestPath)) {
        return orange_backup_sha256_file($manifestPath);
    }

    throw new RuntimeException('Cannot compute fresh backup rollback anchor checksum.');
}
