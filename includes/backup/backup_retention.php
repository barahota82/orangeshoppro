<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_full.php';

const ORANGE_BACKUP_RETENTION_ENV_DAYS = 'ORANGE_BACKUP_RETENTION_DAYS';
const ORANGE_BACKUP_RETENTION_DEFAULT_DAYS = 30;
const ORANGE_BACKUP_RETENTION_FAMILY_FULL = 'full_disaster';
const ORANGE_BACKUP_RETENTION_FAMILY_CRP = 'country_recovery';
const ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE = 'pre_restore_rollback_anchor';
/** Test-round baseline pin (OR-CURSOR-022) — protects package from retention without restore-job coupling. */
const ORANGE_BACKUP_RETENTION_PIN_REASON_TEST_BASELINE = 'test_round_baseline';
const ORANGE_BACKUP_RETENTION_PIN_STORE_RELATIVE = 'locks' . DIRECTORY_SEPARATOR . 'retention_pins.json';
const ORANGE_BACKUP_RETENTION_PIN_LOCK_RELATIVE = 'locks' . DIRECTORY_SEPARATOR . 'retention_pins.lock';

function orange_backup_retention_days(array $env): int
{
    return max(1, (int) ($env[ORANGE_BACKUP_RETENTION_ENV_DAYS] ?? ORANGE_BACKUP_RETENTION_DEFAULT_DAYS));
}

function orange_backup_retention_pin_store_path(string $backupRoot): string
{
    return orange_backup_path_inside_root($backupRoot, ORANGE_BACKUP_RETENTION_PIN_STORE_RELATIVE);
}

function orange_backup_retention_pin_lock_path(string $backupRoot): string
{
    return orange_backup_path_inside_root($backupRoot, ORANGE_BACKUP_RETENTION_PIN_LOCK_RELATIVE);
}

/**
 * Validate decoded pin-store document. Throws on any undecidable/corrupt shape (OR-CURSOR-022-R2 BL-05).
 *
 * @param mixed $decoded
 * @return array{version:string,pins:array<string,array<string,mixed>>}
 */
function orange_backup_retention_pin_store_validate_document(mixed $decoded): array
{
    if (!is_array($decoded) || !array_key_exists('pins', $decoded) || !is_array($decoded['pins'])) {
        throw new RuntimeException('Retention pin store corrupt; refusing destructive retention.');
    }
    $pins = [];
    foreach ($decoded['pins'] as $packageId => $pin) {
        if (!is_string($packageId) || $packageId === '' || !is_array($pin)) {
            throw new RuntimeException('Retention pin store corrupt pin entry; refusing destructive retention.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $packageId)) {
            throw new RuntimeException('Retention pin store corrupt package id; refusing destructive retention.');
        }
        $pins[$packageId] = $pin;
    }

    return [
        'version' => (string) ($decoded['version'] ?? '1'),
        'pins' => $pins,
    ];
}

/**
 * Acquire exclusive lock for pin-store read/modify/write (shared with PowerShell retention).
 *
 * @return resource
 */
function orange_backup_retention_pin_store_lock(string $backupRoot)
{
    $lockPath = orange_backup_retention_pin_lock_path($backupRoot);
    $dir = dirname($lockPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create retention pin lock directory.');
    }
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Cannot open retention pin store lock.');
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Cannot lock retention pin store.');
    }

    return $fh;
}

/**
 * @param resource $fh
 */
function orange_backup_retention_pin_store_unlock($fh): void
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * True when bak/tmp siblings indicate an incomplete pin-store replace (OR-CURSOR-022-R3 A1).
 * Must not be treated as a fresh missing store.
 *
 * Enumeration failure is distinct from an empty listing (OR-CURSOR-022-R4): scandir false must not
 * collapse to "no artifacts" and must not allow a missing primary to look like a fresh store.
 *
 * @param array{simulate_enumerate_fail?:bool} $inject declared test inject only
 * @throws RuntimeException when the pin-store directory cannot be listed
 */
function orange_backup_retention_pin_store_has_incomplete_swap_artifacts(string $backupRoot, array $inject = []): bool
{
    $path = orange_backup_retention_pin_store_path($backupRoot);
    $dir = dirname($path);
    $base = basename($path);
    if (!is_dir($dir)) {
        return false;
    }
    if (!empty($inject['simulate_enumerate_fail'])) {
        throw new RuntimeException(
            'Cannot enumerate retention pin store directory for swap artifacts; refusing destructive retention.'
        );
    }
    $entries = @scandir($dir);
    if ($entries === false) {
        throw new RuntimeException(
            'Cannot enumerate retention pin store directory for swap artifacts; refusing destructive retention.'
        );
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (str_starts_with($entry, $base . '.bak.') || str_starts_with($entry, $base . '.tmp.')) {
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($full) || is_link($full)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Missing store file with no swap artifacts → empty pins.
 * Path invalid/unreadable/directory OR incomplete bak/tmp swap → throws (OR-CURSOR-022-R3 A1).
 * Swap-artifact enumeration failure → throws (OR-CURSOR-022-R4); never treat as fresh empty store.
 *
 * @param array{simulate_enumerate_fail?:bool} $inject declared test inject only
 * @return array{version:string,pins:array<string,array<string,mixed>>}
 */
function orange_backup_retention_pin_store_read(string $backupRoot, array $inject = []): array
{
    $path = orange_backup_retention_pin_store_path($backupRoot);
    if (file_exists($path) && !is_file($path)) {
        throw new RuntimeException('Retention pin store path is not a readable file; refusing destructive retention.');
    }
    if (!is_file($path)) {
        if (orange_backup_retention_pin_store_has_incomplete_swap_artifacts($backupRoot, $inject)) {
            throw new RuntimeException(
                'Retention pin store replace incomplete (bak/tmp present without primary); refusing destructive retention.'
            );
        }

        return ['version' => '1', 'pins' => []];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Retention pin store unreadable; refusing destructive retention.');
    }
    $decoded = json_decode($raw, true);

    return orange_backup_retention_pin_store_validate_document($decoded);
}

/**
 * Safe replace: write temp → verify bytes/content → swap without a missing-file window.
 * Prior store is restored if the final rename fails. Optional $inject is for declared simulations only.
 *
 * @param array{version?:string,pins:array<string,array<string,mixed>>} $store
 * @param array{
 *   simulate_short_write?:bool,
 *   simulate_rename_fail?:bool,
 *   simulate_fail_after_stage?:bool,
 *   simulate_abort_after_stage?:bool,
 *   sleep_ms_before_rename?:int
 * } $inject
 */
function orange_backup_retention_pin_store_write(string $backupRoot, array $store, array $inject = []): void
{
    $path = orange_backup_retention_pin_store_path($backupRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create retention pin store directory.');
    }
    if (file_exists($path) && !is_file($path)) {
        throw new RuntimeException('Retention pin store path is not a writable file.');
    }

    $payload = [
        'version' => (string) ($store['version'] ?? '1'),
        'pins' => is_array($store['pins'] ?? null) ? $store['pins'] : [],
        'updated_at' => gmdate('c'),
    ];
    orange_backup_retention_pin_store_validate_document($payload);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Cannot encode retention pin store.');
    }
    $bytes = $json . "\n";
    $expectedLen = strlen($bytes);
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $writeBytes = $bytes;
    if (!empty($inject['simulate_short_write'])) {
        $writeBytes = substr($bytes, 0, max(1, (int) floor($expectedLen / 3)));
    }
    $written = @file_put_contents($tmp, $writeBytes, LOCK_EX);
    if ($written === false) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write retention pin store temp file.');
    }
    if ((int) $written !== strlen($writeBytes) || (int) $written !== $expectedLen) {
        @unlink($tmp);
        throw new RuntimeException('Retention pin store short write; prior store kept.');
    }
    $verifyRaw = @file_get_contents($tmp);
    if (!is_string($verifyRaw) || $verifyRaw !== $bytes) {
        @unlink($tmp);
        throw new RuntimeException('Retention pin store temp content mismatch; prior store kept.');
    }
    orange_backup_retention_pin_store_validate_document(json_decode($verifyRaw, true));

    if (!empty($inject['sleep_ms_before_rename'])) {
        usleep(max(0, (int) $inject['sleep_ms_before_rename']) * 1000);
    }

    $renameFailMode = '';
    if (!empty($inject['simulate_rename_fail'])) {
        $renameFailMode = 'first';
    } elseif (!empty($inject['simulate_fail_after_stage'])) {
        $renameFailMode = 'after_stage';
    }
    $renameStep = 0;
    $rename = static function (string $from, string $to) use (&$renameStep, $renameFailMode): bool {
        $renameStep++;
        if ($renameFailMode === 'first' && $renameStep === 1) {
            return false;
        }
        if ($renameFailMode === 'after_stage' && $renameStep >= 2) {
            return false; // fail tmp→path and bak→path restore
        }

        return @rename($from, $to);
    };

    // Stage prior file aside, then install temp. On failure leave bak/tmp for readers (A1) — do not wipe them.
    if (is_file($path)) {
        $bak = $path . '.bak.' . getmypid() . '.' . bin2hex(random_bytes(3));
        if (!$rename($path, $bak)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot stage prior retention pin store; prior store kept.');
        }
        if (!empty($inject['simulate_abort_after_stage'])) {
            // Declared process-abort after successful stage: primary missing, bak retained, tmp retained.
            throw new RuntimeException('Retention pin store write aborted after staging; incomplete replace left in place.');
        }
        if (!$rename($tmp, $path)) {
            // Keep tmp (may still exist) and bak; do not delete bak to “look clean”.
            if (!$rename($bak, $path)) {
                throw new RuntimeException(
                    'Cannot finalize retention pin store write; prior store retained as backup file (incomplete replace).'
                );
            }
            throw new RuntimeException('Cannot finalize retention pin store write; prior store restored.');
        }
        @unlink($bak);

        return;
    }

    if (!$rename($tmp, $path)) {
        // First-time store: tmp may remain; readers treat missing primary without bak as empty only if no artifacts.
        throw new RuntimeException('Cannot finalize retention pin store write.');
    }
}

function orange_backup_retention_is_pinned(string $backupRoot, string $packageId): bool
{
    if ($packageId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $packageId)) {
        return false;
    }
    $lock = orange_backup_retention_pin_store_lock($backupRoot);
    try {
        $store = orange_backup_retention_pin_store_read($backupRoot);

        return isset($store['pins'][$packageId]) && is_array($store['pins'][$packageId]);
    } finally {
        orange_backup_retention_pin_store_unlock($lock);
    }
}

/**
 * Idempotent pin. Does not unpin. Private metadata only (no absolute paths).
 * Entire read/modify/write holds the shared pin-store lock (OR-CURSOR-022-R2 BL-05).
 *
 * @param array{
 *   framework_job_id:string,
 *   reason?:string,
 *   source_package_id?:string,
 *   created_by?:string,
 *   purpose?:string,
 *   identity_tag?:string
 * } $meta
 * @param array{
 *   sleep_ms_after_read?:int,
 *   simulate_short_write?:bool,
 *   simulate_rename_fail?:bool,
 *   simulate_fail_after_stage?:bool,
 *   simulate_abort_after_stage?:bool,
 *   sleep_ms_before_rename?:int
 * } $inject
 * @return array{pin_id:string,package_id:string,created:bool,pin:array<string,mixed>}
 */
function orange_backup_retention_pin_package(string $backupRoot, string $packageId, array $meta, array $inject = []): array
{
    if ($packageId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $packageId)) {
        throw new RuntimeException('Invalid package id for retention pin.');
    }
    $jobId = trim((string) ($meta['framework_job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('Invalid framework_job_id for retention pin.');
    }
    $reason = trim((string) ($meta['reason'] ?? ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE));
    $allowedReasons = [
        ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
        ORANGE_BACKUP_RETENTION_PIN_REASON_TEST_BASELINE,
    ];
    if (!in_array($reason, $allowedReasons, true)) {
        throw new RuntimeException('Unsupported retention pin reason.');
    }

    $lock = orange_backup_retention_pin_store_lock($backupRoot);
    try {
        $store = orange_backup_retention_pin_store_read($backupRoot);
        if (!empty($inject['sleep_ms_after_read'])) {
            usleep(max(0, (int) $inject['sleep_ms_after_read']) * 1000);
        }
        if (isset($store['pins'][$packageId]) && is_array($store['pins'][$packageId])) {
            $existing = $store['pins'][$packageId];
            if ((string) ($existing['framework_job_id'] ?? '') === $jobId
                && (string) ($existing['reason'] ?? '') === $reason) {
                return [
                    'pin_id' => (string) ($existing['pin_id'] ?? ''),
                    'package_id' => $packageId,
                    'created' => false,
                    'pin' => $existing,
                ];
            }
            throw new RuntimeException('package_already_pinned_for_other_job');
        }

        $pinId = 'pin_' . substr(hash('sha256', $packageId . '|' . $jobId . '|' . $reason), 0, 24);
        $pin = [
            'pin_id' => $pinId,
            'package_id' => $packageId,
            'package_type' => 'full_disaster',
            'framework_job_id' => $jobId,
            'reason' => $reason,
            'purpose' => (string) ($meta['purpose'] ?? ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE),
            'source_package_id' => (string) ($meta['source_package_id'] ?? ''),
            'created_by' => (string) ($meta['created_by'] ?? ''),
            'identity_tag' => (string) ($meta['identity_tag'] ?? ''),
            'created_at' => gmdate('c'),
        ];
        $store['pins'][$packageId] = $pin;
        orange_backup_retention_pin_store_write($backupRoot, $store, $inject);

        return [
            'pin_id' => $pinId,
            'package_id' => $packageId,
            'created' => true,
            'pin' => $pin,
        ];
    } finally {
        orange_backup_retention_pin_store_unlock($lock);
    }
}

/**
 * Public/safe pin summary (no paths).
 *
 * @return array<string, mixed>|null
 */
function orange_backup_retention_pin_public(string $backupRoot, string $packageId): ?array
{
    $lock = orange_backup_retention_pin_store_lock($backupRoot);
    try {
        $store = orange_backup_retention_pin_store_read($backupRoot);
        $pin = $store['pins'][$packageId] ?? null;
        if (!is_array($pin)) {
            return null;
        }

        return [
            'pin_id' => (string) ($pin['pin_id'] ?? ''),
            'package_id' => (string) ($pin['package_id'] ?? $packageId),
            'framework_job_id' => (string) ($pin['framework_job_id'] ?? ''),
            'reason' => (string) ($pin['reason'] ?? ''),
            'purpose' => (string) ($pin['purpose'] ?? ''),
            'retention_pinned' => true,
            'created_at' => (string) ($pin['created_at'] ?? ''),
        ];
    } finally {
        orange_backup_retention_pin_store_unlock($lock);
    }
}

function orange_backup_retention_is_temp_dir_name(string $name): bool
{
    return str_starts_with($name, '._work_')
        || str_starts_with($name, '.tmp_')
        || str_starts_with($name, '.tmp');
}

function orange_backup_retention_is_finalized_dir_name(string $name): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name);
}

function orange_backup_retention_dir_age_seconds(string $name, int $mtime): int
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{6})$/', $name, $matches)) {
        $datePart = $matches[1];
        $timePart = $matches[2];
        $formatted = $datePart . ' '
            . substr($timePart, 0, 2) . ':'
            . substr($timePart, 2, 2) . ':'
            . substr($timePart, 4, 2);
        $parsed = strtotime($formatted);
        if ($parsed !== false) {
            return max(0, time() - $parsed);
        }
    }

    return max(0, time() - $mtime);
}

function orange_backup_retention_age_exceeds(int $ageSeconds, int $retentionDays): bool
{
    return $ageSeconds > ($retentionDays * 86400);
}

function orange_backup_retention_path_within_root(string $backupRoot, string $targetPath): bool
{
    $rootReal = realpath($backupRoot);
    if ($rootReal === false) {
        return false;
    }
    $rootNorm = strtolower(rtrim(str_replace('\\', '/', $rootReal), '/'));

    if (is_link($targetPath)) {
        return false;
    }

    $targetReal = realpath($targetPath);
    if ($targetReal === false) {
        $candidate = strtolower(rtrim(str_replace('\\', '/', $targetPath), '/'));

        return str_starts_with($candidate, $rootNorm . '/');
    }

    $targetNorm = strtolower(rtrim(str_replace('\\', '/', $targetReal), '/'));

    return str_starts_with($targetNorm, $rootNorm . '/');
}

/**
 * @return list<array{name:string,path:string,mtime:int,age_seconds:int}>
 */
function orange_backup_retention_list_finalized_dirs(string $containerDir): array
{
    if (!is_dir($containerDir)) {
        return [];
    }

    $dirs = [];
    foreach (scandir($containerDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || orange_backup_retention_is_temp_dir_name($entry)) {
            continue;
        }
        // Private operational metadata root must never be treated as a package.
        if ($entry === '.orange_meta') {
            continue;
        }
        if (!orange_backup_retention_is_finalized_dir_name($entry)) {
            continue;
        }
        $full = $containerDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($full) || is_link($full)) {
            continue;
        }
        $mtime = filemtime($full) ?: time();
        $dirs[] = [
            'name' => $entry,
            'path' => $full,
            'mtime' => $mtime,
            'age_seconds' => orange_backup_retention_dir_age_seconds($entry, $mtime),
        ];
    }

    usort($dirs, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

    return $dirs;
}

function orange_backup_retention_full_is_healthy(string $packageDir): bool
{
    if (!is_dir($packageDir)) {
        return false;
    }
    $healthPath = $packageDir . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE;
    if (!is_file($healthPath)) {
        return false;
    }
    $health = json_decode((string) file_get_contents($healthPath), true);
    if (!is_array($health) || ($health['package_status'] ?? '') !== 'healthy') {
        return false;
    }

    $verify = orange_backup_verify_full_package($packageDir);

    return $verify['ok'];
}

function orange_backup_retention_crp_is_healthy(string $packageDir): bool
{
    if (!is_dir($packageDir)) {
        return false;
    }
    require_once __DIR__ . '/backup_validate.php';
    $healthPath = $packageDir . DIRECTORY_SEPARATOR . 'health.json';
    if (!is_file($healthPath)) {
        return false;
    }
    $health = json_decode((string) file_get_contents($healthPath), true);
    if (!is_array($health) || ($health['package_status'] ?? '') !== 'healthy') {
        return false;
    }

    $verify = orange_country_export_verify_package($packageDir);

    return $verify['ok'];
}

function orange_backup_retention_find_newest_healthy_name(string $containerDir, string $family): ?string
{
    foreach (orange_backup_retention_list_finalized_dirs($containerDir) as $dir) {
        $healthy = $family === ORANGE_BACKUP_RETENTION_FAMILY_CRP
            ? orange_backup_retention_crp_is_healthy($dir['path'])
            : orange_backup_retention_full_is_healthy($dir['path']);
        if ($healthy) {
            return $dir['name'];
        }
    }

    return null;
}

/**
 * @param callable(string,string=):void|null $logger
 * @param array{simulate_enumerate_fail?:bool} $inject declared test inject only (OR-CURSOR-022-R4)
 * @return array{
 *   kept:list<array{name:string,path:string,reason:string}>,
 *   deleted:list<array{name:string,path:string,reason:string}>,
 *   errors:list<string>
 * }
 */
function orange_backup_retention_apply(
    string $backupRoot,
    string $containerDir,
    string $family,
    ?string $currentPackageName,
    int $retentionDays,
    ?callable $logger = null,
    array $inject = []
): array {
    $logger ??= static function (string $message, string $level = 'INFO'): void {
    };

    $kept = [];
    $deleted = [];
    $errors = [];
    $allDirs = orange_backup_retention_list_finalized_dirs($containerDir);
    if ($allDirs === []) {
        return ['kept' => $kept, 'deleted' => $deleted, 'errors' => $errors];
    }

    $newestHealthy = orange_backup_retention_find_newest_healthy_name($containerDir, $family);
    $protected = [];

    if ($currentPackageName !== null && $currentPackageName !== '') {
        $protected[$currentPackageName] = 'current_run';
    }
    if ($newestHealthy !== null) {
        $protected[$newestHealthy] = 'newest_verified_healthy';
    }

    // BL-05: hold shared pin lock for evaluate+delete so concurrent pin writers cannot race retention.
    $pinLock = orange_backup_retention_pin_store_lock($backupRoot);
    try {
        try {
            $pinStore = orange_backup_retention_pin_store_read($backupRoot, $inject);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $logger($e->getMessage(), 'ERROR');
            foreach ($allDirs as $dir) {
                $kept[] = ['name' => $dir['name'], 'path' => $dir['path'], 'reason' => 'pin_store_unreadable_keep_all'];
            }

            return ['kept' => $kept, 'deleted' => $deleted, 'errors' => $errors];
        }

        foreach ($allDirs as $dir) {
            $name = $dir['name'];
            $path = $dir['path'];

            // Pins win over age / newest-healthy protection and never expose paths in the kept row.
            if (isset($pinStore['pins'][$name]) && is_array($pinStore['pins'][$name])) {
                $kept[] = ['name' => $name, 'reason' => 'skipped_due_to_pin'];
                $logger('Retention keep name=' . $name . ' reason=skipped_due_to_pin');
                continue;
            }

            if (isset($protected[$name])) {
                $kept[] = ['name' => $name, 'path' => $path, 'reason' => $protected[$name]];
                $logger('Retention keep name=' . $name . ' reason=' . $protected[$name]);
                continue;
            }

            if (!orange_backup_retention_age_exceeds($dir['age_seconds'], $retentionDays)) {
                $kept[] = ['name' => $name, 'path' => $path, 'reason' => 'within_retention_window'];
                $logger('Retention keep name=' . $name . ' reason=within_retention_window age_days=' . round($dir['age_seconds'] / 86400, 2));
                continue;
            }

            $isHealthy = $family === ORANGE_BACKUP_RETENTION_FAMILY_CRP
                ? orange_backup_retention_crp_is_healthy($path)
                : orange_backup_retention_full_is_healthy($path);
            if ($isHealthy && $newestHealthy === null) {
                $kept[] = ['name' => $name, 'path' => $path, 'reason' => 'verified_healthy_no_replacement'];
                $logger('Retention keep name=' . $name . ' reason=verified_healthy_no_replacement');
                continue;
            }

            if (!orange_backup_retention_path_within_root($backupRoot, $path)) {
                $errors[] = 'Retention blocked path outside BackupRoot: ' . $path;
                $logger('Retention blocked path outside BackupRoot: ' . $path, 'ERROR');
                continue;
            }

            orange_backup_remove_dir($path);
            if (is_dir($path)) {
                $errors[] = 'Retention failed to delete: ' . $path;
                $logger('Retention failed to delete: ' . $path, 'ERROR');
                continue;
            }

            $deleted[] = ['name' => $name, 'path' => $path, 'reason' => 'expired_older_than_' . $retentionDays . '_days'];
            $logger('Retention deleted name=' . $name . ' reason=expired_older_than_' . $retentionDays . '_days');
        }
    } finally {
        orange_backup_retention_pin_store_unlock($pinLock);
    }

    return ['kept' => $kept, 'deleted' => $deleted, 'errors' => $errors];
}

/**
 * @param callable(string,string=):void|null $logger
 * @param array{simulate_enumerate_fail?:bool} $inject declared test inject only (OR-CURSOR-022-R4)
 * @return array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}
 */
function orange_backup_retention_apply_full_snapshots(
    string $backupRoot,
    string $snapshotsDir,
    ?string $currentSnapshotName,
    int $retentionDays,
    ?callable $logger = null,
    array $inject = []
): array {
    return orange_backup_retention_apply(
        $backupRoot,
        $snapshotsDir,
        ORANGE_BACKUP_RETENTION_FAMILY_FULL,
        $currentSnapshotName,
        $retentionDays,
        $logger,
        $inject
    );
}

/**
 * @param callable(string,string=):void|null $logger
 * @return array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}
 */
function orange_backup_retention_apply_country_packages(
    string $backupRoot,
    string $countryCode,
    ?string $currentPackageName,
    int $retentionDays,
    ?callable $logger = null
): array {
    $countryPackagesDir = orange_backup_path_inside_root(
        $backupRoot,
        'country_packages' . DIRECTORY_SEPARATOR . $countryCode
    );

    return orange_backup_retention_apply(
        $backupRoot,
        $countryPackagesDir,
        ORANGE_BACKUP_RETENTION_FAMILY_CRP,
        $currentPackageName,
        $retentionDays,
        $logger
    );
}

/**
 * @return list<string>
 */
function orange_backup_retention_list_country_codes(string $backupRoot): array
{
    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    if (!is_dir($countryRoot)) {
        return [];
    }

    $codes = [];
    foreach (scandir($countryRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || orange_backup_retention_is_temp_dir_name($entry)) {
            continue;
        }
        if ($entry === '.orange_meta') {
            continue;
        }
        $full = $countryRoot . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full) && !is_link($full)) {
            $codes[] = $entry;
        }
    }

    sort($codes);

    return $codes;
}

/**
 * @param array<string, ?string> $currentPackagesByCountry country_code => package_name
 * @param callable(string,string=):void|null $logger
 * @return array<string, array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}>
 */
function orange_backup_retention_apply_all_country_packages(
    string $backupRoot,
    array $currentPackagesByCountry,
    int $retentionDays,
    ?callable $logger = null
): array {
    $results = [];
    $countryCodes = array_unique(array_merge(
        orange_backup_retention_list_country_codes($backupRoot),
        array_keys($currentPackagesByCountry)
    ));
    sort($countryCodes);

    foreach ($countryCodes as $countryCode) {
        $current = $currentPackagesByCountry[$countryCode] ?? null;
        $results[$countryCode] = orange_backup_retention_apply_country_packages(
            $backupRoot,
            $countryCode,
            $current,
            $retentionDays,
            $logger
        );
    }

    return $results;
}
