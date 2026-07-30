<?php

declare(strict_types=1);

require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_validation_adapter.php';

/**
 * Approved Full snapshot directory id returned by orange_backup_run_full() backends.
 * Exact basename only — never a relative/absolute filesystem path.
 */
const ORANGE_RESTORE_FRESH_BACKUP_SNAPSHOT_ID_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';

/**
 * Resolve the runner `snapshot` basename to a contained package directory under BackupRoot/snapshots.
 *
 * Contract (all Full backends): `snapshot` is the UTC folder id `Y-m-d_His` only.
 * Malformed values (separators, absolute/UNC/drive paths, traversal) fail closed.
 * Never selects “latest” or any other package.
 *
 * @return array{ok:bool,path:string,snapshot_id:string,errors:list<string>}
 */
function orange_restore_fresh_backup_resolve_package_dir(
    string $projectRoot,
    string $snapshotResult,
    ?string $backupRootOverride = null
): array {
    $raw = trim($snapshotResult);
    if ($raw === '') {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => '',
            'errors' => ['Fresh backup snapshot id is empty.'],
        ];
    }

    // Fail closed: do not basename()-sanitize malicious paths into a valid id.
    if (
        str_contains($raw, '/')
        || str_contains($raw, '\\')
        || str_contains($raw, '..')
        || str_contains($raw, "\0")
        || preg_match('/[[:cntrl:]]/', $raw) === 1
        || preg_match('#^[A-Za-z]:#', $raw) === 1
        || str_starts_with($raw, '\\\\')
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw) === 1
    ) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => '',
            'errors' => ['Fresh backup snapshot id is malformed or not a basename.'],
        ];
    }

    if (preg_match(ORANGE_RESTORE_FRESH_BACKUP_SNAPSHOT_ID_PATTERN, $raw) !== 1) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => '',
            'errors' => ['Fresh backup snapshot id is malformed or not a basename.'],
        ];
    }

    try {
        $env = orange_backup_load_env_array($projectRoot);
        $backupRoot = orange_backup_resolve_root($env, $backupRootOverride);
        $packagePath = orange_backup_path_inside_root($backupRoot, 'snapshots/' . $raw);
        $snapshotsRoot = orange_backup_path_inside_root($backupRoot, 'snapshots');
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => $raw,
            'errors' => ['Fresh backup package path rejected: ' . $e->getMessage()],
        ];
    }

    $resolved = realpath($packagePath);
    if ($resolved === false || !is_dir($resolved)) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => $raw,
            'errors' => ['Fresh backup package directory does not exist.'],
        ];
    }
    if (is_link($resolved) || (PHP_OS_FAMILY === 'Windows' && is_link($packagePath))) {
        // realpath already expanded; still refuse if the leaf name drifted.
    }

    $resolvedNorm = strtolower(rtrim(str_replace('\\', '/', $resolved), '/'));
    $snapshotsNorm = strtolower(rtrim(str_replace('\\', '/', realpath($snapshotsRoot) ?: $snapshotsRoot), '/'));
    if ($resolvedNorm === $snapshotsNorm || !str_starts_with($resolvedNorm, $snapshotsNorm . '/')) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => $raw,
            'errors' => ['Fresh backup package escaped snapshots root.'],
        ];
    }

    $leaf = basename(str_replace('\\', '/', $resolvedNorm));
    if ($leaf !== $raw) {
        return [
            'ok' => false,
            'path' => '',
            'snapshot_id' => $raw,
            'errors' => ['Fresh backup package identity mismatch.'],
        ];
    }

    return [
        'ok' => true,
        'path' => $resolved,
        'snapshot_id' => $raw,
        'errors' => [],
    ];
}

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

    $resolved = orange_restore_fresh_backup_resolve_package_dir(
        $projectRoot,
        (string) $backup['snapshot'],
        $backupRootOverride
    );
    if (!$resolved['ok']) {
        orange_restore_log('Fresh backup path resolve... FAIL');

        return [
            'ok' => false,
            'snapshot_path' => '',
            'checksum' => '',
            'verify' => [],
            'drv' => [],
            'backup' => $backup,
            'errors' => $resolved['errors'],
        ];
    }

    $snapshotPath = $resolved['path'];
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

    /** @var array<string, mixed> $manifest */
    $manifest = is_array($verify['manifest'] ?? null) ? $verify['manifest'] : [];
    if (($manifest['package_type'] ?? '') !== ORANGE_BACKUP_FULL_PACKAGE_TYPE) {
        return [
            'ok' => false,
            'snapshot_path' => $snapshotPath,
            'checksum' => '',
            'verify' => $verify,
            'drv' => [],
            'backup' => $backup,
            'errors' => ['Fresh backup package_type is not full_disaster.'],
        ];
    }
    $expectedSchema = defined('ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION')
        ? (int) ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION
        : 124;
    if ((int) ($manifest['schema_revision'] ?? 0) !== $expectedSchema) {
        return [
            'ok' => false,
            'snapshot_path' => $snapshotPath,
            'checksum' => '',
            'verify' => $verify,
            'drv' => [],
            'backup' => $backup,
            'errors' => ['Fresh backup schema_revision mismatch.'],
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

    $checksum = orange_restore_fresh_backup_anchor_checksum($snapshotPath, $manifest);
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
