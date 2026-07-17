<?php

declare(strict_types=1);

/**
 * Phase 3B.3B6 — Shadow File Restore.
 *
 * Extracts Full package uploads.zip into an isolated restore_shadow_workspace/
 * under the framework job directory. Never overwrites production, never renames,
 * never cutover.
 *
 * Reuses:
 *   - orange_restore_uploads_applicator_extract()
 *   - orange_restore_uploads_tree_inventory() / symlink+reparse fences
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_shadow_verify.php';
require_once __DIR__ . '/restore_uploads_applicator.php';
require_once __DIR__ . '/restore_uploads_fs.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';

const ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION = '3B.3B6-v1';
const ORANGE_RESTORE_SHADOW_FILES_REPORT_FILE = 'shadow_files_report.json';
const ORANGE_RESTORE_SHADOW_FILES_META_FILE = 'shadow_files.json';
const ORANGE_RESTORE_SHADOW_FILES_LOCK_FILE = '.shadow_files.lock';
const ORANGE_RESTORE_SHADOW_FILES_LOCK_STALE_SECONDS = 21600;
const ORANGE_RESTORE_SHADOW_FILES_WORKSPACE = 'restore_shadow_workspace';

function orange_restore_shadow_files_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_FILES_REPORT_FILE;
}

function orange_restore_shadow_files_meta_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_FILES_META_FILE;
}

function orange_restore_shadow_files_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_FILES_LOCK_FILE;
}

/**
 * Isolated workspace under the framework job directory only.
 */
function orange_restore_shadow_files_workspace_path(string $workRoot, string $jobId): string
{
    $jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
    $workspace = $jobDir . DIRECTORY_SEPARATOR . ORANGE_RESTORE_SHADOW_FILES_WORKSPACE;
    orange_restore_assert_inside_work_root($workRoot, $workspace);

    return $workspace;
}

/**
 * @return array{held:bool,payload:?array<string,mixed>,stale:bool}
 */
function orange_restore_shadow_files_lock_status(string $workRoot): array
{
    $path = orange_restore_shadow_files_lock_path($workRoot);
    if (!is_file($path)) {
        return ['held' => false, 'payload' => null, 'stale' => false];
    }
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        return ['held' => true, 'payload' => null, 'stale' => true];
    }
    $acquiredAt = strtotime((string) ($payload['acquired_at'] ?? ''));
    $age = $acquiredAt !== false ? (time() - $acquiredAt) : PHP_INT_MAX;
    $pid = (int) ($payload['pid'] ?? 0);
    $pidAlive = null;
    if ($pid > 0 && function_exists('posix_kill')) {
        $pidAlive = @posix_kill($pid, 0);
    }
    $stale = $age > ORANGE_RESTORE_SHADOW_FILES_LOCK_STALE_SECONDS && $pidAlive !== true;

    return ['held' => true, 'payload' => $payload, 'stale' => $stale];
}

/**
 * @return array{ok:bool,message:string}
 */
function orange_restore_shadow_files_acquire_lock(string $workRoot, string $jobId, string $owner): array
{
    $path = orange_restore_shadow_files_lock_path($workRoot);
    $status = orange_restore_shadow_files_lock_status($workRoot);
    if ($status['held'] && $status['stale']) {
        @unlink($path);
        $status = orange_restore_shadow_files_lock_status($workRoot);
    }
    if ($status['held'] && !$status['stale']) {
        $held = (string) (($status['payload'] ?? [])['job_id'] ?? '');
        if ($held === $jobId) {
            return ['ok' => true, 'message' => 'lock_already_held'];
        }

        return ['ok' => false, 'message' => 'shadow_files_lock_active'];
    }
    $payload = json_encode([
        'job_id' => $jobId,
        'owner' => $owner,
        'pid' => getmypid(),
        'acquired_at' => gmdate('c'),
        'heartbeat_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $handle = @fopen($path, 'xb');
    if ($handle === false || $payload === false) {
        return ['ok' => false, 'message' => 'shadow_files_lock_active'];
    }
    fwrite($handle, $payload . "\n");
    fclose($handle);

    return ['ok' => true, 'message' => 'ok'];
}

function orange_restore_shadow_files_release_lock(string $workRoot, ?string $expectedJobId = null): void
{
    $path = orange_restore_shadow_files_lock_path($workRoot);
    if (!is_file($path)) {
        return;
    }
    if ($expectedJobId !== null) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $held = is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
        if ($held !== '' && $held !== $expectedJobId) {
            return;
        }
    }
    @unlink($path);
}

/**
 * @param array<string, mixed> $record
 */
function orange_restore_shadow_files_write_json(string $path, array $record): void
{
    unset(
        $record['absolute_paths'],
        $record['package_path'],
        $record['zip_path'],
        $record['workspace_path'],
        $record['password'],
        $record['secrets']
    );
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write shadow files metadata.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_files_load_meta(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_files_meta_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_shadow_files_load_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_shadow_files_report_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function orange_restore_shadow_files_public_meta(array $meta): array
{
    unset($meta['absolute_paths'], $meta['package_path'], $meta['zip_path'], $meta['workspace_path'], $meta['password']);

    return [
        'record_version' => (string) ($meta['record_version'] ?? ''),
        'framework_job_id' => (string) ($meta['framework_job_id'] ?? ''),
        'source_package_id' => (string) ($meta['source_package_id'] ?? ''),
        'workspace_relative' => ORANGE_RESTORE_SHADOW_FILES_WORKSPACE,
        'status' => (string) ($meta['status'] ?? ''),
        'created_at' => (string) ($meta['created_at'] ?? ''),
        'created_by' => (string) ($meta['created_by'] ?? ''),
        'files_extracted' => (int) ($meta['files_extracted'] ?? 0),
        'bytes_extracted' => (int) ($meta['bytes_extracted'] ?? 0),
        'ready' => (bool) ($meta['ready'] ?? false),
        'cli_needed' => (bool) ($meta['cli_needed'] ?? false),
        'cli_command' => (string) ($meta['cli_command'] ?? ''),
        'failure_code' => (string) ($meta['failure_code'] ?? ''),
        'production_touched' => false,
        'cutover_performed' => false,
        'directories_renamed' => false,
        'execution_started' => false,
        'warning' => (string) ($meta['warning'] ?? 'Shadow file restore only — production filesystem was not modified.'),
    ];
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_shadow_files_public_report(array $report): array
{
    unset($report['absolute_paths'], $report['package_path'], $report['zip_path'], $report['workspace_path']);

    return $report + [
        'production_touched' => false,
        'cutover_performed' => false,
        'directories_renamed' => false,
        'execution_started' => false,
    ];
}

/**
 * @return array{ok:bool,code:string,job:array<string,mixed>}
 */
function orange_restore_shadow_files_revalidate(string $workRoot, string $jobId, string $backupRoot): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['package_type'] ?? '') === 'country_recovery') {
        return ['ok' => false, 'code' => 'country_production_restore_not_enabled', 'job' => $job];
    }
    if ((string) ($job['package_type'] ?? '') !== 'full_disaster') {
        return ['ok' => false, 'code' => 'package_type_mismatch', 'job' => $job];
    }

    $status = (string) ($job['status'] ?? '');
    $allowed = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
    ];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'code' => 'invalid_status', 'job' => $job];
    }

    $verifyMeta = orange_restore_shadow_verify_load_meta($workRoot, $jobId);
    if ($verifyMeta === null || empty($verifyMeta['verified'])) {
        return ['ok' => false, 'code' => 'shadow_not_verified', 'job' => $job];
    }

    $anchor = orange_restore_pre_backup_load_record($workRoot, $jobId);
    if ($anchor === null || empty($anchor['ready_for_rollback']) || empty($anchor['retention_pinned'])) {
        return ['ok' => false, 'code' => 'pre_restore_backup_not_ready', 'job' => $job];
    }

    try {
        $contract = orange_restore_load_execution_contract($workRoot, $jobId);
        $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);
        if (!($validation['ok'] ?? false)) {
            return ['ok' => false, 'code' => (string) ($validation['code'] ?? 'version_mismatch'), 'job' => $job];
        }
    } catch (Throwable) {
        return ['ok' => false, 'code' => 'contract_missing', 'job' => $job];
    }

    return ['ok' => true, 'code' => 'ok', 'job' => $job];
}

/**
 * Recursively delete contents of a directory that must remain under work root.
 */
function orange_restore_shadow_files_wipe_workspace(string $workRoot, string $workspace): void
{
    orange_restore_assert_inside_work_root($workRoot, $workspace);
    if (!is_dir($workspace)) {
        return;
    }
    $real = realpath($workspace);
    if ($real === false) {
        return;
    }
    orange_restore_assert_inside_work_root($workRoot, $real);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        orange_restore_assert_inside_work_root($workRoot, $path);
        if ($file->isLink()) {
            @unlink($path);
            continue;
        }
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
}

/**
 * List zip central-directory entries (ZipArchive preferred; stored/deflate names via pure PHP fallback).
 *
 * @return list<array{name:string,method:int,comp_size:int,uncomp_size:int,local_offset:int,external_attributes:int,opsys:int}>
 */
function orange_restore_shadow_files_zip_list_entries(string $zipPath): array
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new RuntimeException('cannot_open_uploads_zip');
        }
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '') {
                continue;
            }
            $stat = $zip->statIndex($i);
            $entries[] = [
                'name' => $name,
                'method' => is_array($stat) ? (int) ($stat['comp_method'] ?? 0) : 0,
                'comp_size' => is_array($stat) ? (int) ($stat['comp_size'] ?? 0) : 0,
                'uncomp_size' => is_array($stat) ? (int) ($stat['size'] ?? 0) : 0,
                'local_offset' => is_array($stat) ? (int) ($stat['offset'] ?? 0) : 0,
                'external_attributes' => is_array($stat) ? (int) ($stat['external_attributes'] ?? 0) : 0,
                'opsys' => is_array($stat) ? (int) ($stat['opsys'] ?? -1) : -1,
            ];
        }
        $zip->close();

        return $entries;
    }

    $raw = @file_get_contents($zipPath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('cannot_open_uploads_zip');
    }
    $len = strlen($raw);
    $eocd = strrpos($raw, "\x50\x4b\x05\x06");
    if ($eocd === false || $eocd + 22 > $len) {
        throw new RuntimeException('cannot_open_uploads_zip');
    }
    $cdOffset = unpack('V', substr($raw, $eocd + 16, 4))[1] ?? 0;
    $cdSize = unpack('V', substr($raw, $eocd + 12, 4))[1] ?? 0;
    $cdOffset = (int) $cdOffset;
    $cdSize = (int) $cdSize;
    if ($cdOffset < 0 || $cdSize < 0 || $cdOffset + $cdSize > $len) {
        throw new RuntimeException('cannot_open_uploads_zip');
    }

    $entries = [];
    $pos = $cdOffset;
    $end = $cdOffset + $cdSize;
    while ($pos + 46 <= $end) {
        $sig = unpack('V', substr($raw, $pos, 4))[1] ?? 0;
        if ((int) $sig !== 0x02014b50) {
            break;
        }
        $madeVer = unpack('v', substr($raw, $pos + 4, 2))[1] ?? 0;
        $method = unpack('v', substr($raw, $pos + 10, 2))[1] ?? 0;
        $comp = unpack('V', substr($raw, $pos + 20, 4))[1] ?? 0;
        $uncomp = unpack('V', substr($raw, $pos + 24, 4))[1] ?? 0;
        $nameLen = unpack('v', substr($raw, $pos + 28, 2))[1] ?? 0;
        $extraLen = unpack('v', substr($raw, $pos + 30, 2))[1] ?? 0;
        $commentLen = unpack('v', substr($raw, $pos + 32, 2))[1] ?? 0;
        $external = unpack('V', substr($raw, $pos + 38, 4))[1] ?? 0;
        $localOff = unpack('V', substr($raw, $pos + 42, 4))[1] ?? 0;
        $nameLen = (int) $nameLen;
        $extraLen = (int) $extraLen;
        $commentLen = (int) $commentLen;
        $name = substr($raw, $pos + 46, $nameLen);
        $entries[] = [
            'name' => (string) $name,
            'method' => (int) $method,
            'comp_size' => (int) $comp,
            'uncomp_size' => (int) $uncomp,
            'local_offset' => (int) $localOff,
            'external_attributes' => (int) $external,
            'opsys' => ((int) $madeVer >> 8) & 0xFF,
        ];
        $pos += 46 + $nameLen + $extraLen + $commentLen;
    }

    return $entries;
}

/**
 * Extract stored (method 0) zip entries without ZipArchive — used when extension missing.
 *
 * @return array{ok:bool,files_extracted:int,bytes_extracted:int,error:?string}
 */
function orange_restore_shadow_files_extract_stored(string $zipPath, string $targetDir): array
{
    try {
        $entries = orange_restore_shadow_files_zip_list_entries($zipPath);
    } catch (Throwable $e) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => $e->getMessage()];
    }
    $raw = @file_get_contents($zipPath);
    if (!is_string($raw)) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'cannot_read_uploads_zip'];
    }
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => 'Cannot create uploads target directory'];
    }
    $targetReal = realpath($targetDir) ?: $targetDir;
    $targetNorm = strtolower(rtrim(str_replace('\\', '/', $targetReal), '/'));
    $filesExtracted = 0;
    $bytesExtracted = 0;

    try {
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $name);
            if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
                throw new RuntimeException('Blocked zip traversal entry: ' . $name);
            }
            $dest = $targetReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
            $destNorm = strtolower(rtrim(str_replace('\\', '/', $dest), '/'));
            if ($destNorm !== $targetNorm && !str_starts_with($destNorm, $targetNorm . '/')) {
                throw new RuntimeException('Zip extract path escapes target directory: ' . $name);
            }
            if (str_ends_with($normalized, '/')) {
                if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
                    throw new RuntimeException('Cannot create directory from zip: ' . $name);
                }
                continue;
            }
            if ((int) ($entry['method'] ?? 0) !== 0) {
                throw new RuntimeException('Compressed zip entries require ZipArchive extension');
            }
            $localOff = (int) ($entry['local_offset'] ?? 0);
            if ($localOff < 0 || $localOff + 30 > strlen($raw)) {
                throw new RuntimeException('Invalid local header offset: ' . $name);
            }
            $localSig = unpack('V', substr($raw, $localOff, 4))[1] ?? 0;
            if ((int) $localSig !== 0x04034b50) {
                throw new RuntimeException('Invalid local header: ' . $name);
            }
            $nameLen = (int) (unpack('v', substr($raw, $localOff + 26, 2))[1] ?? 0);
            $extraLen = (int) (unpack('v', substr($raw, $localOff + 28, 2))[1] ?? 0);
            $dataOff = $localOff + 30 + $nameLen + $extraLen;
            $compSize = (int) ($entry['comp_size'] ?? 0);
            if ($dataOff < 0 || $dataOff + $compSize > strlen($raw)) {
                throw new RuntimeException('Invalid entry payload: ' . $name);
            }
            $contents = substr($raw, $dataOff, $compSize);
            $parent = dirname($dest);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create parent directory for zip entry: ' . $name);
            }
            if (file_put_contents($dest, $contents) === false) {
                throw new RuntimeException('Cannot write zip entry: ' . $name);
            }
            $filesExtracted++;
            $bytesExtracted += strlen($contents);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'files_extracted' => $filesExtracted, 'bytes_extracted' => $bytesExtracted, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'files_extracted' => $filesExtracted, 'bytes_extracted' => $bytesExtracted, 'error' => null];
}

/**
 * Pre-scan zip for zip-slip / absolute / drive / symlink entries.
 *
 * @return array{ok:bool,files:list<string>,dirs:list<string>,errors:list<string>,file_count:int}
 */
function orange_restore_shadow_files_scan_zip(string $zipPath): array
{
    if (isset($GLOBALS['orange_shadow_files_scan_override']) && is_callable($GLOBALS['orange_shadow_files_scan_override'])) {
        /** @var callable $fn */
        $fn = $GLOBALS['orange_shadow_files_scan_override'];
        $result = $fn($zipPath);

        return is_array($result) ? $result : ['ok' => false, 'files' => [], 'dirs' => [], 'errors' => ['scan_override_invalid'], 'file_count' => 0];
    }

    $errors = [];
    $files = [];
    $dirs = [];
    if (!is_file($zipPath)) {
        return ['ok' => false, 'files' => [], 'dirs' => [], 'errors' => ['uploads_zip_missing'], 'file_count' => 0];
    }

    try {
        $entries = orange_restore_shadow_files_zip_list_entries($zipPath);
    } catch (Throwable) {
        return ['ok' => false, 'files' => [], 'dirs' => [], 'errors' => ['cannot_open_uploads_zip'], 'file_count' => 0];
    }

    foreach ($entries as $entry) {
        $name = (string) ($entry['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/') || str_starts_with($normalized, '\\')) {
            $errors[] = 'absolute_path:' . $name;
            continue;
        }
        if (preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            $errors[] = 'drive_path:' . $name;
            continue;
        }
        if (preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            $errors[] = 'zip_slip:' . $name;
            continue;
        }
        $opsys = (int) ($entry['opsys'] ?? -1);
        $external = (int) ($entry['external_attributes'] ?? 0);
        // Unix symlink: opsys=3 (UNIX), high mode bits include 0120000 (symlink).
        if ($opsys === 3) {
            $mode = ($external >> 16) & 0xFFFF;
            if (($mode & 0xF000) === 0xA000) {
                $errors[] = 'symlink_entry:' . $name;
                continue;
            }
        }
        if (str_ends_with($normalized, '/')) {
            $dirs[] = rtrim($normalized, '/');
            continue;
        }
        $files[] = $normalized;
    }

    sort($files, SORT_STRING);
    sort($dirs, SORT_STRING);

    return [
        'ok' => $errors === [],
        'files' => $files,
        'dirs' => $dirs,
        'errors' => $errors,
        'file_count' => count($files),
    ];
}

/**
 * @param list<string> $expectedFiles
 * @param array<string, mixed> $inventory
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,missing:list<string>,extra:list<string>}
 */
function orange_restore_shadow_files_verify_tree(
    string $workspace,
    array $expectedFiles,
    array $inventory,
    array $manifest
): array {
    $errors = [];
    $warnings = [];

    $actualFiles = [];
    foreach (($inventory['checksum_lines'] ?? []) as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s{2,}/', $line, 2);
        if (is_array($parts) && count($parts) === 2) {
            $actualFiles[] = (string) $parts[1];
        }
    }
    sort($actualFiles, SORT_STRING);
    $expected = array_values(array_unique($expectedFiles));
    sort($expected, SORT_STRING);

    $missing = array_values(array_diff($expected, $actualFiles));
    $extra = array_values(array_diff($actualFiles, $expected));
    if ($missing !== []) {
        $errors[] = 'Missing extracted files (' . count($missing) . ').';
    }
    if ($extra !== []) {
        $errors[] = 'Unexpected extracted files (' . count($extra) . ').';
    }

    $fileCount = (int) ($inventory['file_count'] ?? 0);
    if ($fileCount <= 0) {
        $errors[] = 'Shadow workspace has no files after extract.';
    }
    if ($expected !== [] && $fileCount !== count($expected)) {
        $errors[] = 'Extracted file_count mismatch vs zip entry count.';
    }

    // Readable / permission sanity (no production ACL changes).
    $unreadable = [];
    $execChecked = 0;
    $execOk = 0;
    foreach (array_slice($actualFiles, 0, 200) as $rel) {
        $full = $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($full)) {
            continue;
        }
        if (!is_readable($full)) {
            $unreadable[] = $rel;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, ['sh', 'bash', 'cgi'], true)) {
            $execChecked++;
            if (is_executable($full) || PHP_OS_FAMILY === 'Windows') {
                $execOk++;
            }
        }
    }
    if ($unreadable !== []) {
        $errors[] = 'Unreadable files in shadow workspace: ' . implode(', ', array_slice($unreadable, 0, 10));
    }
    if ($execChecked > 0 && $execOk < $execChecked && PHP_OS_FAMILY !== 'Windows') {
        $warnings[] = 'Some script files are not executable in shadow workspace (reported only).';
    }

    $manifestUploadsSha = strtolower(trim((string) ($manifest['uploads_sha256'] ?? '')));
    if ($manifestUploadsSha === '') {
        $warnings[] = 'manifest.uploads_sha256 missing; zip integrity relied on scan/extract only.';
    }

    if (!is_dir($workspace)) {
        $errors[] = 'Shadow workspace directory missing after extract.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'missing' => array_slice($missing, 0, 50),
        'extra' => array_slice($extra, 0, 50),
        'expected_file_count' => count($expected),
        'actual_file_count' => $fileCount,
        'tree_checksum_sha256' => (string) ($inventory['tree_checksum_sha256'] ?? ''),
        'total_size' => (int) ($inventory['total_size'] ?? 0),
    ];
}

/**
 * CLI worker — shadow files only. Stops at shadow_files_ready / shadow_files_failed.
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_files_run_cli(
    string $projectRoot,
    string $workRoot,
    string $backupRoot,
    string $jobId,
    string $owner = 'cli'
): array {
    unset($projectRoot);
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $check = orange_restore_shadow_files_revalidate($workRoot, $jobId, $backupRoot);
    if (!$check['ok']) {
        throw new RuntimeException((string) $check['code']);
    }
    $job = $check['job'];
    $status = (string) ($job['status'] ?? '');

    $meta = orange_restore_shadow_files_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_files_load_report($workRoot, $jobId);
    if ($status === ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY
        && is_array($meta)
        && !empty($meta['ready'])
        && is_array($report)) {
        return [
            'ok' => true,
            'idempotent' => true,
            'result' => 'PASS',
            'job_id' => $jobId,
            'files_extracted' => (int) ($meta['files_extracted'] ?? 0),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_files_public_meta($meta),
            'report' => orange_restore_shadow_files_public_report($report),
        ];
    }

    if (!in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
    ], true)) {
        throw new RuntimeException('invalid_status');
    }

    $lock = orange_restore_shadow_files_acquire_lock($workRoot, $jobId, $owner);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }

    $workspace = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    $meta = [
        'record_version' => ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => (string) ($job['package_id'] ?? ''),
        'workspace_relative' => ORANGE_RESTORE_SHADOW_FILES_WORKSPACE,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
        'created_at' => gmdate('c'),
        'created_by' => $owner,
        'files_extracted' => 0,
        'bytes_extracted' => 0,
        'ready' => false,
        'cli_needed' => false,
        'cli_command' => 'php scripts/backup/restore_shadow_files.php --job=' . $jobId,
        'production_touched' => false,
        'cutover_performed' => false,
        'directories_renamed' => false,
        'execution_started' => false,
        'warning' => 'Shadow file restore only — production filesystem will not be modified.',
    ];

    try {
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_files_started',
            'result' => 'ok',
            'owner' => $owner,
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_FILES_RUNNING,
            35,
            'Extracting package files into shadow workspace',
            'shadow_files_started'
        );
        orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), $meta);

        $packageId = (string) ($job['package_id'] ?? '');
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        $manifestRaw = file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (!is_array($manifest) || ($manifest['package_type'] ?? '') !== 'full_disaster') {
            throw new RuntimeException('package_type_mismatch');
        }
        $uploadsFile = trim((string) ($manifest['uploads_file'] ?? ''));
        if ($uploadsFile === '') {
            throw new RuntimeException('uploads_file_missing');
        }
        $zipPath = $packagePath . DIRECTORY_SEPARATOR . $uploadsFile;
        if (!is_file($zipPath)) {
            throw new RuntimeException('uploads_zip_missing');
        }

        $expectedSha = strtolower(trim((string) ($manifest['uploads_sha256'] ?? '')));
        if ($expectedSha !== '') {
            $actualSha = strtolower(orange_backup_sha256_file($zipPath));
            if (!hash_equals($expectedSha, $actualSha)) {
                throw new RuntimeException('uploads_checksum_mismatch');
            }
        }

        $scan = orange_restore_shadow_files_scan_zip($zipPath);
        if (!($scan['ok'] ?? false)) {
            throw new RuntimeException('zip_safety_failed:' . implode(',', array_slice($scan['errors'] ?? [], 0, 5)));
        }

        if (!is_dir($workspace) && !@mkdir($workspace, 0775, true) && !is_dir($workspace)) {
            throw new RuntimeException('shadow_workspace_create_failed');
        }
        orange_restore_assert_inside_work_root($workRoot, $workspace);
        orange_restore_shadow_files_wipe_workspace($workRoot, $workspace);

        if (isset($GLOBALS['orange_shadow_files_extract_override'])
            && is_callable($GLOBALS['orange_shadow_files_extract_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_files_extract_override'];
            $extract = $fn($zipPath, $workspace);
            if (!is_array($extract)) {
                throw new RuntimeException('extract_override_invalid');
            }
        } elseif (class_exists('ZipArchive')) {
            $extract = orange_restore_uploads_applicator_extract($zipPath, $workspace);
        } else {
            $extract = orange_restore_shadow_files_extract_stored($zipPath, $workspace);
        }
        if (!($extract['ok'] ?? false)) {
            throw new RuntimeException('extract_failed:' . (string) ($extract['error'] ?? 'unknown'));
        }

        $meta['files_extracted'] = (int) ($extract['files_extracted'] ?? 0);
        $meta['bytes_extracted'] = (int) ($extract['bytes_extracted'] ?? 0);
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING;
        orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_FILES_VERIFYING,
            70,
            'Verifying shadow file workspace',
            'shadow_files_verification_started'
        );

        if (isset($GLOBALS['orange_shadow_files_inventory_override'])
            && is_callable($GLOBALS['orange_shadow_files_inventory_override'])) {
            /** @var callable $fn */
            $fn = $GLOBALS['orange_shadow_files_inventory_override'];
            $inventory = $fn($workspace);
            if (!is_array($inventory)) {
                throw new RuntimeException('inventory_override_invalid');
            }
        } else {
            $inventory = orange_restore_uploads_tree_inventory($workspace);
        }

        $verify = orange_restore_shadow_files_verify_tree(
            $workspace,
            $scan['files'] ?? [],
            $inventory,
            $manifest
        );
        if (!($verify['ok'] ?? false)) {
            throw new RuntimeException('shadow_files_verify_failed');
        }

        $report = [
            'report_version' => ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION,
            'generated_at' => gmdate('c'),
            'framework_job_id' => $jobId,
            'source_package_id' => $packageId,
            'workspace_relative' => ORANGE_RESTORE_SHADOW_FILES_WORKSPACE,
            'overall_result' => 'PASS',
            'uploads_file' => $uploadsFile,
            'uploads_sha256_matched' => $expectedSha !== '',
            'zip_scan' => [
                'ok' => true,
                'file_count' => (int) ($scan['file_count'] ?? 0),
                'dir_count' => count($scan['dirs'] ?? []),
                'errors' => [],
            ],
            'extract' => [
                'ok' => true,
                'files_extracted' => (int) ($extract['files_extracted'] ?? 0),
                'bytes_extracted' => (int) ($extract['bytes_extracted'] ?? 0),
            ],
            'verification' => $verify,
            'warnings' => $verify['warnings'],
            'errors' => [],
            'production_touched' => false,
            'cutover_performed' => false,
            'directories_renamed' => false,
            'files_restored_to_production' => false,
            'execution_started' => false,
            'warning' => 'Shadow file restore only — production filesystem was not modified.',
        ];
        orange_restore_shadow_files_write_json(orange_restore_shadow_files_report_path($workRoot, $jobId), $report);

        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY;
        $meta['ready'] = true;
        $meta['failure_code'] = '';
        $meta['execution_started'] = false;
        orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), $meta);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'shadow_files_ready',
            'result' => 'ok',
            'files_extracted' => (int) ($extract['files_extracted'] ?? 0),
        ]);

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
            ORANGE_RESTORE_FW_PHASE_SHADOW_FILES_READY,
            100,
            'Shadow files ready in restore_shadow_workspace (production untouched)',
            'shadow_files_ready'
        );
        $job['shadow_files_file'] = ORANGE_RESTORE_SHADOW_FILES_META_FILE;
        $job['shadow_files_report_file'] = ORANGE_RESTORE_SHADOW_FILES_REPORT_FILE;
        $job['shadow_files_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY;
        $job['execution_started'] = false;
        orange_restore_fw_write($workRoot, $job);

        orange_restore_shadow_files_release_lock($workRoot, $jobId);

        return [
            'ok' => true,
            'idempotent' => false,
            'result' => 'PASS',
            'job_id' => $jobId,
            'files_extracted' => (int) ($extract['files_extracted'] ?? 0),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_files_public_meta($meta),
            'report' => orange_restore_shadow_files_public_report($report),
        ];
    } catch (Throwable $e) {
        $code = trim($e->getMessage()) ?: 'shadow_files_failed';
        $meta['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED;
        $meta['ready'] = false;
        $meta['failure_code'] = $code;
        $meta['cli_needed'] = true;
        $meta['execution_started'] = false;
        $meta['production_touched'] = false;
        try {
            orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), $meta);
            $failReport = [
                'report_version' => ORANGE_RESTORE_SHADOW_FILES_RECORD_VERSION,
                'generated_at' => gmdate('c'),
                'framework_job_id' => $jobId,
                'overall_result' => 'FAIL',
                'failure_code' => $code,
                'errors' => [$code],
                'production_touched' => false,
                'cutover_performed' => false,
                'directories_renamed' => false,
                'execution_started' => false,
            ];
            orange_restore_shadow_files_write_json(
                orange_restore_shadow_files_report_path($workRoot, $jobId),
                $failReport
            );
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
                ORANGE_RESTORE_FW_PHASE_SHADOW_FILES_FAILED,
                100,
                'Shadow file restore failed: ' . $code,
                'shadow_files_failed'
            );
            $failed = orange_restore_fw_read($workRoot, $jobId);
            $failed['shadow_files_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED;
            $failed['execution_started'] = false;
            orange_restore_fw_write($workRoot, $failed);
            orange_restore_fw_audit_append($workRoot, $jobId, [
                'event' => 'shadow_files_failed',
                'result' => 'fail',
                'code' => $code,
            ]);
        } catch (Throwable) {
            // best-effort forensic preserve
        }
        orange_restore_shadow_files_release_lock($workRoot, $jobId);

        return [
            'ok' => false,
            'idempotent' => false,
            'result' => 'FAIL',
            'job_id' => $jobId,
            'code' => $code,
            'files_extracted' => (int) ($meta['files_extracted'] ?? 0),
            'execution_started' => false,
            'production_touched' => false,
            'meta' => orange_restore_shadow_files_public_meta($meta),
        ];
    }
}
