<?php

declare(strict_types=1);

/**
 * Stage 4A — Qualification evidence, package binding, locks, idempotency, resolver.
 *
 * Backend-only. Not a Stage 1 provenance authority. Not Restore eligibility redesign.
 * Full Verify sibling reports live beside snapshots (same convention as Full DRV).
 * Locks live under BackupRoot/.orange_meta/qualification_locks/v1/.
 */

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_validate.php';
require_once __DIR__ . '/recovery_validation.php';
require_once __DIR__ . '/restore_admin.php';
require_once __DIR__ . '/backup_admin.php';

const ORANGE_BACKUP_QUAL_REPORT_SCHEMA_VERSION = 1;
const ORANGE_BACKUP_QUAL_FULL_VERIFY_SUFFIX = 'full_verify_report.json';
const ORANGE_BACKUP_QUAL_LOCK_REL_ROOT = '.orange_meta/qualification_locks/v1';
const ORANGE_BACKUP_QUAL_PACKAGE_ID_RE = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
const ORANGE_BACKUP_QUAL_COUNTRY_RE = '/^[A-Za-z]{2}$/';
const ORANGE_BACKUP_QUAL_ACTION_VERIFY = 'verify';
const ORANGE_BACKUP_QUAL_ACTION_DRV = 'drv';
const ORANGE_BACKUP_QUAL_LOCK_STALE_SECONDS = 7200;

/** @var array<string, resource> */
$orangeBackupQualLockHandles = [];

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function orange_backup_qualification_assert_safe_id(string $packageId): array
{
    $id = trim($packageId);
    if ($id === '' || !preg_match(ORANGE_BACKUP_QUAL_PACKAGE_ID_RE, $id)) {
        return ['ok' => false, 'error' => 'unsafe_package_id'];
    }

    return ['ok' => true];
}

/**
 * @return array{ok:bool,code?:string,error?:string}
 */
function orange_backup_qualification_assert_safe_country(string $countryCode): array
{
    $cc = strtoupper(trim($countryCode));
    if ($cc === '' || !preg_match(ORANGE_BACKUP_QUAL_COUNTRY_RE, $cc)) {
        return ['ok' => false, 'error' => 'unsafe_country_code'];
    }

    return ['ok' => true, 'code' => $cc];
}

/**
 * Reject traversal, absolute, UNC, URL-like relative segments.
 *
 * @return array{ok:bool,rel?:string,error?:string}
 */
function orange_backup_qualification_assert_safe_relative(string $relative): array
{
    $raw = trim($relative);
    if ($raw === '' || str_contains($raw, "\0") || str_contains($raw, '..')
        || str_contains($raw, '://') || preg_match('/^[A-Za-z]:/', $raw)
        || str_starts_with($raw, '\\\\') || str_starts_with($raw, '//')) {
        return ['ok' => false, 'error' => 'unsafe_relative_path'];
    }
    $rel = str_replace('\\', '/', $raw);
    $rel = ltrim($rel, '/');
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '//')
        || preg_match('/^[A-Za-z]:/', $rel)) {
        return ['ok' => false, 'error' => 'unsafe_relative_path'];
    }

    return ['ok' => true, 'rel' => $rel];
}

function orange_backup_qualification_safe_relative_for_package(
    string $packageType,
    string $packageId,
    string $countryCode = ''
): string {
    if ($packageType === 'full_disaster' || $packageType === 'full') {
        return 'snapshots/' . $packageId;
    }
    $cc = strtolower(trim($countryCode));

    return 'country_packages/' . $cc . '/' . $packageId;
}

/**
 * Current package fingerprint for binding (not provenance authority).
 * Country: orange_crp_export_package_fingerprint (hashes live payload files).
 * Full: sha256 over live hashes of every path listed in checksums.sha256
 *        (NOT a digest of the checksums file text alone — payload mutation must invalidate).
 */
function orange_backup_qualification_current_fingerprint(string $packagePath, string $packageType): string
{
    $resolved = realpath($packagePath);
    if ($resolved === false || !is_dir($resolved)) {
        return '';
    }
    if ($packageType === 'country_recovery' || $packageType === 'country') {
        require_once __DIR__ . '/country_export.php';
        $manifestPath = $resolved . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath)) {
            return '';
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return '';
        }

        return orange_crp_export_package_fingerprint($resolved, $manifest);
    }

    return orange_backup_qualification_full_payload_fingerprint($resolved);
}

/**
 * Content-aware Full fingerprint: live sha256 of each checksum-covered payload path.
 *
 * @return string empty when checksums.sha256 missing/unreadable
 */
function orange_backup_qualification_full_payload_fingerprint(string $packagePath): string
{
    $checksums = $packagePath . DIRECTORY_SEPARATOR . ORANGE_BACKUP_CHECKSUMS_FILE;
    if (!is_file($checksums) || !is_readable($checksums)) {
        return '';
    }
    $lines = file($checksums, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }
    $parts = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || !preg_match('/^[a-f0-9]{64}\s{2}(.+)$/', $line, $m)) {
            continue;
        }
        $rel = str_replace('\\', '/', $m[1]);
        if ($rel === '' || str_contains($rel, '..')) {
            continue;
        }
        $abs = $packagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $parts[] = $rel . '=' . (is_file($abs) ? (hash_file('sha256', $abs) ?: 'unreadable') : 'missing');
    }
    if ($parts === []) {
        return '';
    }
    sort($parts, SORT_STRING);

    return hash('sha256', implode('|', $parts));
}

/** Digest of checksums.sha256 file bytes (manifest identity only — not sufficient alone for success). */
function orange_backup_qualification_checksums_digest(string $packagePath): string
{
    $checksums = $packagePath . DIRECTORY_SEPARATOR . ORANGE_BACKUP_CHECKSUMS_FILE;
    if (!is_file($checksums)) {
        return '';
    }
    $raw = file_get_contents($checksums);

    return (is_string($raw) && $raw !== '') ? hash('sha256', $raw) : '';
}

function orange_backup_qualification_bump_counter_file(string $envKey, string $label): void
{
    $path = getenv($envKey);
    if (!is_string($path) || $path === '') {
        return;
    }
    $fh = @fopen($path, 'c+b');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $data[$label] = (int) ($data[$label] ?? 0) + 1;
    $data['total'] = (int) ($data['total'] ?? 0) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Atomic JSON write: same-directory temp + rename. Interrupted temp leaves last valid.
 *
 * @param array<string, mixed> $data
 */
function orange_backup_qualification_write_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create report directory.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed for qualification report.');
    }
    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write temporary qualification report.');
    }
    $decoded = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($decoded)) {
        @unlink($tmp);
        throw new RuntimeException('Temporary qualification report is not valid JSON.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically replace qualification report.');
        }
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($path, 0660);
    }
    orange_backup_qualification_bump_counter_file('ORANGE_QUAL_REPORT_WRITE_COUNTER_FILE', 'report_write');
}

function orange_backup_qualification_full_verify_sibling_path(string $packagePath, string $packageId): string
{
    return dirname($packagePath) . DIRECTORY_SEPARATOR . $packageId . '.' . ORANGE_BACKUP_QUAL_FULL_VERIFY_SUFFIX;
}

/**
 * @param array<string, mixed> $result from orange_backup_verify_full_package
 * @param array{admin_id?:int|null,kind?:string}|null $operator
 * @return array<string, mixed>
 */
function orange_backup_qualification_build_full_verify_report(
    string $packagePath,
    string $packageId,
    array $result,
    ?array $operator = null
): array {
    $ok = (bool) ($result['ok'] ?? false);
    $manifest = is_array($result['manifest'] ?? null) ? $result['manifest'] : null;
    $fp = orange_backup_qualification_current_fingerprint($packagePath, 'full_disaster');
    $errors = is_array($result['errors'] ?? null) ? array_values($result['errors']) : [];
    $warnings = is_array($result['warnings'] ?? null) ? array_values($result['warnings']) : [];

    return [
        'report_schema_version' => ORANGE_BACKUP_QUAL_REPORT_SCHEMA_VERSION,
        'action' => ORANGE_BACKUP_QUAL_ACTION_VERIFY,
        'package_type' => 'full_disaster',
        'package_id' => $packageId,
        'safe_relative_package_path' => orange_backup_qualification_safe_relative_for_package('full_disaster', $packageId),
        'schema_revision' => is_array($manifest) ? (int) ($manifest['schema_revision'] ?? 0) : 0,
        'package_fingerprint' => $fp,
        'checksums_digest' => orange_backup_qualification_checksums_digest($packagePath),
        'status' => $ok ? 'success' : 'failed',
        'completed_at_utc' => gmdate('c'),
        'operator_kind' => (string) ($operator['kind'] ?? 'admin'),
        'operator_admin_id' => isset($operator['admin_id']) ? (int) $operator['admin_id'] : null,
        'stable_result_code' => $ok ? 'verify_ok' : 'verify_failed',
        'summary' => $ok ? 'Full package verification succeeded.' : 'Full package verification failed.',
        'error_count' => count($errors),
        'warning_count' => count($warnings),
        'errors' => array_slice(array_map('strval', $errors), 0, 20),
        'warnings' => array_slice(array_map('strval', $warnings), 0, 20),
    ];
}

/**
 * @return array{ok:bool,bound:bool,status?:string,report?:array<string,mixed>,reason?:string}
 */
function orange_backup_qualification_read_full_verify_bound(
    string $packagePath,
    string $packageId
): array {
    $idCheck = orange_backup_qualification_assert_safe_id($packageId);
    if (!$idCheck['ok']) {
        return ['ok' => false, 'bound' => false, 'reason' => 'unsafe_package_id'];
    }
    $path = orange_backup_qualification_full_verify_sibling_path($packagePath, $packageId);
    if (!is_file($path)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_missing'];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_unreadable'];
    }
    $report = json_decode($raw, true);
    if (!is_array($report)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_malformed'];
    }

    return orange_backup_qualification_validate_bound_report(
        $report,
        'full_disaster',
        $packageId,
        '',
        $packagePath,
        ORANGE_BACKUP_QUAL_ACTION_VERIFY
    );
}

/**
 * Validate report binding. Historical reports without fingerprint may pass only with
 * exact ID/type/country + matching checksums digest and no contradiction.
 *
 * @param array<string, mixed> $report
 * @return array{ok:bool,bound:bool,status?:string,report?:array<string,mixed>,reason?:string}
 */
function orange_backup_qualification_validate_bound_report(
    array $report,
    string $packageType,
    string $packageId,
    string $countryCode,
    string $packagePath,
    string $action
): array {
    $reportType = (string) ($report['package_type'] ?? '');
    $normalizedReportType = $reportType;
    if ($packageType === 'full_disaster') {
        if ($reportType !== '' && $reportType !== 'full_disaster' && $reportType !== 'full') {
            return ['ok' => false, 'bound' => false, 'reason' => 'package_type_mismatch'];
        }
        $normalizedReportType = 'full_disaster';
    } elseif ($packageType === 'country_recovery') {
        if ($reportType !== '' && !in_array($reportType, ['country_recovery', 'country'], true)) {
            return ['ok' => false, 'bound' => false, 'reason' => 'package_type_mismatch'];
        }
        $normalizedReportType = 'country_recovery';
    }

    $reportId = (string) ($report['package_id'] ?? '');
    if ($reportId !== '' && $reportId !== $packageId) {
        return ['ok' => false, 'bound' => false, 'reason' => 'package_id_mismatch'];
    }
    // Historical Country Verify often omitted package_id — require country + fingerprint or checksums.
    if ($reportId === '' && $action === ORANGE_BACKUP_QUAL_ACTION_VERIFY && $packageType === 'country_recovery') {
        // allow if fingerprint/country bind strongly below
    } elseif ($reportId === '' && $action === ORANGE_BACKUP_QUAL_ACTION_DRV && $packageType === 'full_disaster') {
        // historical Full DRV may omit package_id; basename binding via sibling path only — require checksums
    } elseif ($reportId === '' && !($action === ORANGE_BACKUP_QUAL_ACTION_VERIFY && $packageType === 'country_recovery')) {
        if ($action === ORANGE_BACKUP_QUAL_ACTION_DRV && $packageType === 'country_recovery') {
            // country DRV usually has package_id
        }
    }

    if ($packageType === 'country_recovery' && $countryCode !== '') {
        $reportCc = strtoupper((string) ($report['country_code'] ?? ''));
        $manifest = json_decode((string) @file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'manifest.json'), true);
        $manifestCc = '';
        if (is_array($manifest) && isset($manifest['country_code'])) {
            $manifestCc = strtoupper((string) $manifest['country_code']);
        }
        if ($reportCc !== '' && $reportCc !== strtoupper($countryCode)) {
            return ['ok' => false, 'bound' => false, 'reason' => 'country_mismatch'];
        }
        // country_id numeric cross-check when both present
        $reportCid = (int) ($report['country_id'] ?? 0);
        $pkgCid = is_array($manifest) ? (int) ($manifest['country_id'] ?? 0) : 0;
        if ($reportCid > 0 && $pkgCid > 0 && $reportCid !== $pkgCid) {
            return ['ok' => false, 'bound' => false, 'reason' => 'country_id_mismatch'];
        }
        unset($manifestCc);
    }

    $rel = (string) ($report['safe_relative_package_path'] ?? '');
    if ($rel !== '') {
        $relCheck = orange_backup_qualification_assert_safe_relative($rel);
        if (!$relCheck['ok']) {
            return ['ok' => false, 'bound' => false, 'reason' => 'unsafe_relative_path'];
        }
        $expected = orange_backup_qualification_safe_relative_for_package($packageType, $packageId, $countryCode);
        if (strtolower($rel) !== strtolower($expected)) {
            return ['ok' => false, 'bound' => false, 'reason' => 'path_mismatch'];
        }
    }

    $currentFp = orange_backup_qualification_current_fingerprint($packagePath, $packageType);
    $reportFp = trim((string) ($report['package_fingerprint'] ?? ''));
    $currentDigest = orange_backup_qualification_checksums_digest($packagePath);
    $reportDigest = trim((string) ($report['checksums_digest'] ?? ''));

    if ($reportFp !== '') {
        if ($currentFp !== '' && hash_equals($reportFp, $currentFp)) {
            // Content-aware fingerprint matches current live payload — OK.
        } elseif ($currentDigest !== '' && hash_equals($reportFp, $currentDigest)) {
            // Legacy Stage 4A reports stored checksums-file digest as fingerprint.
            // Accept only when live checksum verification of actual payload still passes.
            if (!function_exists('orange_backup_verify_checksums')) {
                require_once __DIR__ . '/backup_manifest.php';
            }
            $cv = orange_backup_verify_checksums($packagePath);
            if (empty($cv['ok'])) {
                return ['ok' => false, 'bound' => false, 'reason' => 'payload_checksum_mismatch'];
            }
        } else {
            return ['ok' => false, 'bound' => false, 'reason' => 'fingerprint_mismatch'];
        }
    } elseif ($reportDigest !== '') {
        if ($currentDigest === '' || !hash_equals($reportDigest, $currentDigest)) {
            return ['ok' => false, 'bound' => false, 'reason' => 'checksum_digest_mismatch'];
        }
        // Digest of checksums.sha256 text alone is insufficient — require live payload verify.
        if (!function_exists('orange_backup_verify_checksums')) {
            require_once __DIR__ . '/backup_manifest.php';
        }
        $cv = orange_backup_verify_checksums($packagePath);
        if (empty($cv['ok'])) {
            return ['ok' => false, 'bound' => false, 'reason' => 'payload_checksum_mismatch'];
        }
    } else {
        // Historical without fingerprint/digest: accept only if package_id exact + type + country dims
        // and checksums file still verifies (full file verify) for Full; for Country require recomputed fp match stored manifest fingerprint.
        if ($reportId === '' && $packageType === 'full_disaster') {
            return ['ok' => false, 'bound' => false, 'reason' => 'historical_binding_insufficient'];
        }
        if ($packageType === 'country_recovery') {
            $manifest = json_decode((string) @file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'manifest.json'), true);
            $manifestFp = is_array($manifest) ? trim((string) ($manifest['package_fingerprint'] ?? '')) : '';
            if ($manifestFp === '' || $currentFp === '' || !hash_equals($manifestFp, $currentFp)) {
                return ['ok' => false, 'bound' => false, 'reason' => 'historical_binding_insufficient'];
            }
            // Also require stored overall/status fields present
        } else {
            // Full historical DRV without fingerprint: require checksums.sha256 verifies + package_id match via sibling name
            if (!function_exists('orange_backup_verify_checksums')) {
                require_once __DIR__ . '/backup_manifest.php';
            }
            $cv = orange_backup_verify_checksums($packagePath);
            if (empty($cv['ok'])) {
                return ['ok' => false, 'bound' => false, 'reason' => 'historical_checksums_invalid'];
            }
            if ($reportId !== '' && $reportId !== $packageId) {
                return ['ok' => false, 'bound' => false, 'reason' => 'package_id_mismatch'];
            }
            if ($reportId === '') {
                // sibling path identity only — still insufficient without digest/fingerprint for success terminal
                return ['ok' => false, 'bound' => false, 'reason' => 'historical_binding_insufficient'];
            }
        }
    }

    $status = orange_backup_qualification_report_status($report, $action);
    if ($status === '') {
        return ['ok' => false, 'bound' => false, 'reason' => 'status_unknown'];
    }

    return [
        'ok' => true,
        'bound' => true,
        'status' => $status,
        'report' => $report,
        'package_type' => $normalizedReportType,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function orange_backup_qualification_report_status(array $report, string $action): string
{
    if ($action === ORANGE_BACKUP_QUAL_ACTION_VERIFY) {
        $status = strtolower((string) ($report['status'] ?? ''));
        if (in_array($status, ['success', 'failed'], true)) {
            return $status;
        }
        $overall = strtoupper((string) ($report['overall'] ?? ''));
        if ($overall === 'PASS' || $overall === 'WARNING') {
            return 'success';
        }
        if ($overall === 'FAIL') {
            return 'failed';
        }
        if (array_key_exists('ok', $report)) {
            return !empty($report['ok']) ? 'success' : 'failed';
        }

        return '';
    }
    $overall = strtolower((string) ($report['overall_result'] ?? ''));
    if ($overall === 'pass' || $overall === 'warning') {
        return 'success';
    }
    if ($overall === 'fail' || $overall === 'failed') {
        return 'failed';
    }

    return '';
}

/**
 * @return array{ok:bool,bound:bool,status?:string,report?:array<string,mixed>,reason?:string}
 */
function orange_backup_qualification_read_country_verify_bound(
    string $packagePath,
    string $packageId,
    string $countryCode
): array {
    $path = $packagePath . DIRECTORY_SEPARATOR . 'country_verify_report.json';
    if (!is_file($path)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_missing'];
    }
    $report = json_decode((string) file_get_contents($path), true);
    if (!is_array($report)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_malformed'];
    }

    return orange_backup_qualification_validate_bound_report(
        $report,
        'country_recovery',
        $packageId,
        $countryCode,
        $packagePath,
        ORANGE_BACKUP_QUAL_ACTION_VERIFY
    );
}

/**
 * @return array{ok:bool,bound:bool,status?:string,report?:array<string,mixed>,reason?:string}
 */
function orange_backup_qualification_read_full_drv_bound(string $packagePath, string $packageId): array
{
    require_once __DIR__ . '/backup_admin.php';
    $report = orange_backup_admin_read_recovery_validation_report($packagePath, $packageId);
    if (!is_array($report)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_missing'];
    }

    return orange_backup_qualification_validate_bound_report(
        $report,
        'full_disaster',
        $packageId,
        '',
        $packagePath,
        ORANGE_BACKUP_QUAL_ACTION_DRV
    );
}

/**
 * @return array{ok:bool,bound:bool,status?:string,report?:array<string,mixed>,reason?:string}
 */
function orange_backup_qualification_read_country_drv_bound(
    string $packagePath,
    string $packageId,
    string $countryCode
): array {
    require_once __DIR__ . '/country_crp_drv.php';
    $report = orange_country_drv_read_report($packagePath, $packageId);
    if (!is_array($report)) {
        return ['ok' => false, 'bound' => false, 'reason' => 'report_missing'];
    }

    return orange_backup_qualification_validate_bound_report(
        $report,
        'country_recovery',
        $packageId,
        $countryCode,
        $packagePath,
        ORANGE_BACKUP_QUAL_ACTION_DRV
    );
}

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function orange_backup_qualification_lock_path(
    string $backupRoot,
    string $packageType,
    string $packageId,
    string $action,
    string $countryCode = ''
): array {
    $idCheck = orange_backup_qualification_assert_safe_id($packageId);
    if (!$idCheck['ok']) {
        return ['ok' => false, 'error' => 'unsafe_package_id'];
    }
    if (!in_array($action, [ORANGE_BACKUP_QUAL_ACTION_VERIFY, ORANGE_BACKUP_QUAL_ACTION_DRV], true)) {
        return ['ok' => false, 'error' => 'unsafe_action'];
    }
    $root = realpath($backupRoot);
    if ($root === false || !is_dir($root)) {
        return ['ok' => false, 'error' => 'backup_root_unavailable'];
    }
    if ($packageType === 'full_disaster' || $packageType === 'full') {
        $rel = 'full/' . $packageId . '/' . $action . '.lock';
    } elseif ($packageType === 'country_recovery' || $packageType === 'country') {
        $cc = orange_backup_qualification_assert_safe_country($countryCode);
        if (!$cc['ok']) {
            return ['ok' => false, 'error' => 'unsafe_country_code'];
        }
        $rel = 'country/' . $cc['code'] . '/' . $packageId . '/' . $action . '.lock';
    } else {
        return ['ok' => false, 'error' => 'unsafe_package_type'];
    }
    $relCheck = orange_backup_qualification_assert_safe_relative($rel);
    if (!$relCheck['ok']) {
        return ['ok' => false, 'error' => 'unsafe_relative_path'];
    }
    $base = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ORANGE_BACKUP_QUAL_LOCK_REL_ROOT);
    $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $parent = dirname($full);
    if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
        return ['ok' => false, 'error' => 'cannot_create_lock_dir'];
    }
    $normFull = str_replace('\\', '/', $full);
    $normBase = str_replace('\\', '/', $base);
    if (!str_starts_with(strtolower($normFull), strtolower($normBase . '/'))) {
        return ['ok' => false, 'error' => 'path_escape'];
    }

    return ['ok' => true, 'path' => $full];
}

/**
 * @return array{acquired:bool,reason:string,path:string,in_progress:bool}
 */
function orange_backup_qualification_acquire_lock(
    string $backupRoot,
    string $packageType,
    string $packageId,
    string $action,
    string $countryCode = ''
): array {
    global $orangeBackupQualLockHandles;

    $pathInfo = orange_backup_qualification_lock_path($backupRoot, $packageType, $packageId, $action, $countryCode);
    if (!$pathInfo['ok']) {
        return [
            'acquired' => false,
            'reason' => (string) ($pathInfo['error'] ?? 'lock_path_error'),
            'path' => '',
            'in_progress' => false,
        ];
    }
    $path = (string) $pathInfo['path'];
    $key = strtolower(str_replace('\\', '/', $path));

    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Locked exclusively by another process (common on Windows) — do not unlink.
            return [
                'acquired' => false,
                'reason' => 'qualification_locked',
                'path' => $path,
                'in_progress' => true,
            ];
        }
        $meta = $raw !== '' ? json_decode($raw, true) : null;
        $pid = is_array($meta) ? (int) ($meta['pid'] ?? 0) : 0;
        $startedAt = is_array($meta) ? (string) ($meta['started_at'] ?? '') : '';
        $age = 0;
        if ($startedAt !== '') {
            $ts = strtotime($startedAt);
            if ($ts !== false) {
                $age = time() - $ts;
            }
        } else {
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $age = time() - $mtime;
            }
        }
        // Empty/malformed lock artifact alone does not imply running.
        $pidAlive = $pid > 0 && function_exists('orange_backup_process_alive')
            ? orange_backup_process_alive($pid)
            : ($pid > 0);
        if ($pidAlive) {
            return [
                'acquired' => false,
                'reason' => 'qualification_locked',
                'path' => $path,
                'in_progress' => true,
            ];
        }
        // Empty / dead / stale lock artifacts may be cleared; never steal from a live holder.
        if (!is_array($meta) || $pid <= 0 || !$pidAlive || $age >= ORANGE_BACKUP_QUAL_LOCK_STALE_SECONDS) {
            @unlink($path);
        }
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        // Fail closed: inability to open an existing/contended lock path means in-progress/locked.
        return [
            'acquired' => false,
            'reason' => 'qualification_locked',
            'path' => $path,
            'in_progress' => true,
        ];
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return [
            'acquired' => false,
            'reason' => 'qualification_locked',
            'path' => $path,
            'in_progress' => true,
        ];
    }
    ftruncate($handle, 0);
    rewind($handle);
    $payload = json_encode([
        'pid' => getmypid(),
        'started_at' => gmdate('c'),
        'action' => $action,
        'package_id' => $packageId,
        'package_type' => $packageType,
        'country_code' => strtoupper($countryCode),
        'hostname' => php_uname('n'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite($handle, $payload !== false ? $payload : '{}');
    fflush($handle);
    $orangeBackupQualLockHandles[$key] = $handle;

    return ['acquired' => true, 'reason' => '', 'path' => $path, 'in_progress' => false];
}

function orange_backup_qualification_release_lock(string $lockPath): void
{
    global $orangeBackupQualLockHandles;
    $key = strtolower(str_replace('\\', '/', $lockPath));
    if (!isset($orangeBackupQualLockHandles[$key]) || !is_resource($orangeBackupQualLockHandles[$key])) {
        if ($lockPath !== '' && is_file($lockPath)) {
            // Do not unlink foreign locks; only clear if we held them.
        }

        return;
    }
    $handle = $orangeBackupQualLockHandles[$key];
    flock($handle, LOCK_UN);
    fclose($handle);
    unset($orangeBackupQualLockHandles[$key]);
    if (is_file($lockPath)) {
        @unlink($lockPath);
    }
}

function orange_backup_qualification_lock_is_active(string $lockPath): bool
{
    if ($lockPath === '' || !is_file($lockPath)) {
        return false;
    }
    $raw = file_get_contents($lockPath);
    $meta = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($meta)) {
        return false;
    }
    $pid = (int) ($meta['pid'] ?? 0);
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('orange_backup_process_alive')) {
        return orange_backup_process_alive($pid);
    }

    return true;
}

/**
 * @param array{admin_id?:int|null,kind?:string}|null $operator
 * @return array<string, mixed>
 */
function orange_backup_qualification_run_verify(
    string $backupRoot,
    string $packageType,
    string $packagePath,
    string $packageId,
    string $countryCode = '',
    ?array $operator = null
): array {
    $lock = orange_backup_qualification_acquire_lock(
        $backupRoot,
        $packageType,
        $packageId,
        ORANGE_BACKUP_QUAL_ACTION_VERIFY,
        $countryCode
    );
    if (!$lock['acquired']) {
        if (!empty($lock['in_progress'])) {
            return [
                'success' => false,
                'in_progress' => true,
                'short_circuited' => false,
                'heavy_executed' => false,
                'message' => 'عملية التحقق قيد التنفيذ حالياً.',
                'code' => 'qualification_in_progress',
            ];
        }
        // Read-only BackupRoot: allow bound success short-circuit without lock; heavy write needs writable root.
        $boundReadonly = $packageType === 'full_disaster'
            ? orange_backup_qualification_read_full_verify_bound($packagePath, $packageId)
            : orange_backup_qualification_read_country_verify_bound($packagePath, $packageId, $countryCode);
        if ($boundReadonly['ok'] && ($boundReadonly['status'] ?? '') === 'success') {
            return [
                'success' => true,
                'in_progress' => false,
                'short_circuited' => true,
                'heavy_executed' => false,
                'message' => 'تم التحقق من الحزمة بنجاح (نتيجة محفوظة).',
                'result' => [
                    'ok' => true,
                    'package_type' => $packageType,
                    'from_saved_report' => true,
                    'report' => $boundReadonly['report'],
                    'errors' => [],
                    'warnings' => [],
                ],
            ];
        }
        throw new RuntimeException('Cannot acquire Verify qualification lock: ' . $lock['reason']);
    }
    $lockPath = $lock['path'];
    try {
        orange_backup_qualification_test_hold_after_lock();
        if ($packageType === 'full_disaster') {
            $bound = orange_backup_qualification_read_full_verify_bound($packagePath, $packageId);
            if ($bound['ok'] && ($bound['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'in_progress' => false,
                    'short_circuited' => true,
                    'heavy_executed' => false,
                    'message' => 'تم التحقق من الحزمة بنجاح (نتيجة محفوظة).',
                    'result' => [
                        'ok' => true,
                        'package_type' => 'full_disaster',
                        'from_saved_report' => true,
                        'report' => $bound['report'],
                        'errors' => [],
                        'warnings' => [],
                    ],
                ];
            }
            orange_backup_qualification_bump_heavy_counter('verify_full');
            $heavy = orange_backup_verify_full_package($packagePath);
            $report = orange_backup_qualification_build_full_verify_report($packagePath, $packageId, $heavy, $operator);
            orange_backup_qualification_write_json_atomic(
                orange_backup_qualification_full_verify_sibling_path($packagePath, $packageId),
                $report
            );
            $ok = (bool) ($heavy['ok'] ?? false);

            return [
                'success' => $ok,
                'in_progress' => false,
                'short_circuited' => false,
                'heavy_executed' => true,
                'message' => $ok ? 'تم التحقق من الحزمة بنجاح.' : 'فشل التحقق من الحزمة.',
                'result' => orange_backup_admin_redact_secrets([
                    'ok' => $ok,
                    'package_type' => 'full_disaster',
                    'from_saved_report' => false,
                    'report' => $report,
                    'errors' => $heavy['errors'] ?? [],
                    'warnings' => $heavy['warnings'] ?? [],
                    'manifest' => $heavy['manifest'] ?? null,
                    'health' => $heavy['health'] ?? null,
                ]),
            ];
        }

        $bound = orange_backup_qualification_read_country_verify_bound($packagePath, $packageId, $countryCode);
        if ($bound['ok'] && ($bound['status'] ?? '') === 'success') {
            return [
                'success' => true,
                'in_progress' => false,
                'short_circuited' => true,
                'heavy_executed' => false,
                'message' => 'تم التحقق من الحزمة بنجاح (نتيجة محفوظة).',
                'result' => [
                    'ok' => true,
                    'package_type' => 'country_recovery',
                    'from_saved_report' => true,
                    'report' => $bound['report'],
                    'errors' => [],
                    'warnings' => [],
                ],
            ];
        }
        orange_backup_qualification_bump_heavy_counter('verify_country');
        require_once __DIR__ . '/backup_admin.php';
        $heavy = orange_backup_admin_verify_package('country_recovery', $packagePath);
        // Writer already strengthened in country_crp_verify finalize; re-read and enrich if needed.
        orange_backup_qualification_enrich_country_verify_report_file($packagePath, $packageId, $countryCode);
        $ok = (bool) ($heavy['ok'] ?? false);

        return [
            'success' => $ok,
            'in_progress' => false,
            'short_circuited' => false,
            'heavy_executed' => true,
            'message' => $ok ? 'تم التحقق من الحزمة بنجاح.' : 'فشل التحقق من الحزمة.',
            'result' => $heavy,
        ];
    } finally {
        orange_backup_qualification_release_lock($lockPath);
    }
}

/**
 * @return array<string, mixed>
 */
function orange_backup_qualification_run_drv(
    string $backupRoot,
    string $packageType,
    string $packagePath,
    string $packageId,
    string $countryCode = ''
): array {
    $lock = orange_backup_qualification_acquire_lock(
        $backupRoot,
        $packageType,
        $packageId,
        ORANGE_BACKUP_QUAL_ACTION_DRV,
        $countryCode
    );
    if (!$lock['acquired']) {
        if (!empty($lock['in_progress'])) {
            return [
                'success' => false,
                'in_progress' => true,
                'short_circuited' => false,
                'heavy_executed' => false,
                'message' => 'فحص قابلية الاسترداد قيد التنفيذ حالياً.',
                'code' => 'qualification_in_progress',
            ];
        }
        throw new RuntimeException('Cannot acquire DRV qualification lock: ' . $lock['reason']);
    }
    $lockPath = $lock['path'];
    try {
        orange_backup_qualification_test_hold_after_lock();
        if ($packageType === 'full_disaster') {
            $bound = orange_backup_qualification_read_full_drv_bound($packagePath, $packageId);
            if ($bound['ok'] && ($bound['status'] ?? '') === 'success') {
                $rep = $bound['report'] ?? [];

                return [
                    'success' => true,
                    'in_progress' => false,
                    'short_circuited' => true,
                    'heavy_executed' => false,
                    'message' => 'اجتازت الحزمة فحص قابلية الاسترداد (نتيجة محفوظة).',
                    'result' => [
                        'ok' => true,
                        'overall_result' => (string) ($rep['overall_result'] ?? 'pass'),
                        'recovery_score' => (int) ($rep['recovery_score'] ?? 0),
                        'from_saved_report' => true,
                        'errors' => [],
                        'warnings' => $rep['warnings'] ?? [],
                        'report' => $rep,
                    ],
                ];
            }
            orange_backup_qualification_bump_heavy_counter('drv_full');
            require_once __DIR__ . '/backup_admin.php';
            $heavy = orange_backup_admin_recovery_validate($packagePath);
            orange_backup_qualification_enrich_full_drv_report_file($packagePath, $packageId);
            $ok = (bool) ($heavy['ok'] ?? false);

            return [
                'success' => $ok,
                'in_progress' => false,
                'short_circuited' => false,
                'heavy_executed' => true,
                'message' => $ok ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'فشل فحص قابلية الاسترداد.',
                'result' => $heavy,
            ];
        }

        $bound = orange_backup_qualification_read_country_drv_bound($packagePath, $packageId, $countryCode);
        if ($bound['ok'] && ($bound['status'] ?? '') === 'success') {
            $rep = $bound['report'] ?? [];

            return [
                'success' => true,
                'in_progress' => false,
                'short_circuited' => true,
                'heavy_executed' => false,
                'message' => 'اجتازت الحزمة فحص قابلية الاسترداد (نتيجة محفوظة).',
                'result' => [
                    'ok' => true,
                    'overall_result' => (string) ($rep['overall_result'] ?? 'pass'),
                    'recovery_score' => (int) ($rep['recovery_score'] ?? 0),
                    'from_saved_report' => true,
                    'errors' => [],
                    'warnings' => $rep['warnings'] ?? [],
                    'report' => $rep,
                    'execution_performed' => false,
                ],
            ];
        }
        orange_backup_qualification_bump_heavy_counter('drv_country');
        require_once __DIR__ . '/backup_admin.php';
        $heavy = orange_backup_admin_recovery_validate($packagePath);
        orange_backup_qualification_enrich_country_drv_report_file($packagePath, $packageId, $countryCode);
        $ok = (bool) ($heavy['ok'] ?? false);

        return [
            'success' => $ok,
            'in_progress' => false,
            'short_circuited' => false,
            'heavy_executed' => true,
            'message' => $ok ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'فشل فحص قابلية الاسترداد.',
            'result' => $heavy,
        ];
    } finally {
        orange_backup_qualification_release_lock($lockPath);
    }
}

function orange_backup_qualification_enrich_country_verify_report_file(
    string $packagePath,
    string $packageId,
    string $countryCode
): void {
    $path = $packagePath . DIRECTORY_SEPARATOR . 'country_verify_report.json';
    if (!is_file($path)) {
        return;
    }
    $report = json_decode((string) file_get_contents($path), true);
    if (!is_array($report)) {
        return;
    }
    $fp = orange_backup_qualification_current_fingerprint($packagePath, 'country_recovery');
    $report['report_schema_version'] = ORANGE_BACKUP_QUAL_REPORT_SCHEMA_VERSION;
    $report['action'] = ORANGE_BACKUP_QUAL_ACTION_VERIFY;
    $report['package_type'] = 'country_recovery';
    $report['package_id'] = $packageId;
    $report['country_code'] = strtoupper($countryCode);
    $report['safe_relative_package_path'] = orange_backup_qualification_safe_relative_for_package(
        'country_recovery',
        $packageId,
        $countryCode
    );
    $report['package_fingerprint'] = $fp !== '' ? $fp : (string) ($report['package_fingerprint'] ?? '');
    $report['checksums_digest'] = orange_backup_qualification_checksums_digest($packagePath);
    if (empty($report['completed_at_utc']) && !empty($report['generated_at'])) {
        $report['completed_at_utc'] = (string) $report['generated_at'];
    } elseif (empty($report['completed_at_utc'])) {
        $report['completed_at_utc'] = gmdate('c');
    }
    unset($report['package_path'], $report['project_root']);
    orange_backup_qualification_write_json_atomic($path, $report);
}

function orange_backup_qualification_enrich_full_drv_report_file(string $packagePath, string $packageId): void
{
    require_once __DIR__ . '/backup_admin.php';
    $sibling = orange_backup_admin_recovery_report_sibling_path($packagePath, $packageId);
    if (!is_file($sibling)) {
        return;
    }
    $report = json_decode((string) file_get_contents($sibling), true);
    if (!is_array($report)) {
        return;
    }
    $fp = orange_backup_qualification_current_fingerprint($packagePath, 'full_disaster');
    $report['report_schema_version'] = ORANGE_BACKUP_QUAL_REPORT_SCHEMA_VERSION;
    $report['action'] = ORANGE_BACKUP_QUAL_ACTION_DRV;
    $report['package_type'] = 'full_disaster';
    $report['package_id'] = $packageId;
    $report['safe_relative_package_path'] = orange_backup_qualification_safe_relative_for_package('full_disaster', $packageId);
    $report['package_fingerprint'] = $fp;
    $report['checksums_digest'] = orange_backup_qualification_checksums_digest($packagePath);
    $report['completed_at_utc'] = (string) ($report['validated_at'] ?? gmdate('c'));
    unset($report['package_path']);
    orange_backup_qualification_write_json_atomic($sibling, $report);
}

function orange_backup_qualification_enrich_country_drv_report_file(
    string $packagePath,
    string $packageId,
    string $countryCode
): void {
    require_once __DIR__ . '/country_crp_drv.php';
    $sibling = orange_country_drv_report_sibling_path($packagePath, $packageId);
    if (!is_file($sibling)) {
        return;
    }
    $report = json_decode((string) file_get_contents($sibling), true);
    if (!is_array($report)) {
        return;
    }
    $fp = orange_backup_qualification_current_fingerprint($packagePath, 'country_recovery');
    $report['report_schema_version'] = ORANGE_BACKUP_QUAL_REPORT_SCHEMA_VERSION;
    $report['action'] = ORANGE_BACKUP_QUAL_ACTION_DRV;
    $report['package_type'] = 'country_recovery';
    $report['package_id'] = $packageId;
    $report['country_code'] = strtoupper($countryCode);
    $report['safe_relative_package_path'] = orange_backup_qualification_safe_relative_for_package(
        'country_recovery',
        $packageId,
        $countryCode
    );
    $report['package_fingerprint'] = $fp;
    $report['checksums_digest'] = orange_backup_qualification_checksums_digest($packagePath);
    $report['completed_at_utc'] = (string) ($report['validated_at'] ?? gmdate('c'));
    orange_backup_qualification_write_json_atomic($sibling, $report);
}

/**
 * Test-only: hold the qualification lock briefly so a concurrent endpoint worker can observe in_progress.
 * Production never sets ORANGE_QUAL_TEST_HOLD_MS.
 */
function orange_backup_qualification_test_hold_after_lock(): void
{
    $ms = (int) (getenv('ORANGE_QUAL_TEST_HOLD_MS') ?: 0);
    if ($ms > 0) {
        usleep($ms * 1000);
    }
}

function orange_backup_qualification_bump_heavy_counter(string $label): void
{
    orange_backup_qualification_bump_counter_file('ORANGE_QUAL_HEAVY_COUNTER_FILE', $label);
}

function orange_backup_qualification_bump_audit_counter(string $label): void
{
    orange_backup_qualification_bump_counter_file('ORANGE_QUAL_AUDIT_COUNTER_FILE', $label);
}

/**
 * Exact Admin API Verify body (same sequence as admin/api/backup/verify.php) for tests/workers.
 *
 * @param array{admin_id?:int|null,kind?:string}|null $operator
 * @return array<string, mixed>
 */
function orange_backup_qualification_endpoint_verify(
    string $backupRoot,
    string $packageType,
    string $packagePath,
    string $packageId,
    string $countryCode = '',
    ?array $operator = null
): array {
    $startedAt = gmdate('c');
    $run = orange_backup_qualification_run_verify(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode,
        $operator
    );
    $finishedAt = gmdate('c');
    if (!empty($run['in_progress'])) {
        return $run;
    }
    $ok = (bool) ($run['success'] ?? false);
    if (!empty($run['heavy_executed'])) {
        orange_backup_admin_audit(
            'verify',
            $packageType,
            $packageType === 'country_recovery' ? strtoupper($countryCode) . '/' . $packageId : $packageId,
            $startedAt,
            $finishedAt,
            $ok,
            $ok ? '' : implode('; ', $run['result']['errors'] ?? [])
        );
        orange_backup_qualification_bump_audit_counter('verify_audit');
    }

    return $run;
}

/**
 * Exact Admin API DRV body (same sequence as admin/api/backup/recovery-check.php) for tests/workers.
 *
 * @return array<string, mixed>
 */
function orange_backup_qualification_endpoint_drv(
    string $backupRoot,
    string $packageType,
    string $packagePath,
    string $packageId,
    string $countryCode = ''
): array {
    $startedAt = gmdate('c');
    $run = orange_backup_qualification_run_drv(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode
    );
    $finishedAt = gmdate('c');
    if (!empty($run['in_progress'])) {
        return $run;
    }
    $ok = (bool) ($run['success'] ?? false);
    $result = is_array($run['result'] ?? null) ? $run['result'] : [];
    if (!empty($run['heavy_executed'])) {
        orange_backup_admin_audit(
            'recovery_validation',
            $packageType,
            $packageType === 'country_recovery' ? strtoupper($countryCode) . '/' . $packageId : $packageId,
            $startedAt,
            $finishedAt,
            $ok,
            $ok ? '' : implode('; ', array_slice($result['errors'] ?? [], 0, 5))
        );
        orange_backup_qualification_bump_audit_counter('drv_audit');
    }

    return $run;
}

/**
 * Read-only normalized qualification state (Stage 4B contract foundation).
 *
 * @param array<string, mixed>|null $admin
 * @return array<string, mixed>
 */
function orange_backup_qualification_resolve(
    string $backupRoot,
    string $packageType,
    string $packageId,
    string $countryCode = '',
    ?array $admin = null,
    ?PDO $pdo = null
): array {
    require_once __DIR__ . '/backup_admin.php';

    $idCheck = orange_backup_qualification_assert_safe_id($packageId);
    if (!$idCheck['ok']) {
        return ['ok' => false, 'code' => 'unsafe_package_id', 'message' => 'Invalid package id.'];
    }
    if ($packageType === 'country_recovery') {
        $cc = orange_backup_qualification_assert_safe_country($countryCode);
        if (!$cc['ok']) {
            return ['ok' => false, 'code' => 'unsafe_country_code', 'message' => 'Invalid country code.'];
        }
        $countryCode = $cc['code'];
        if ($admin !== null && $pdo instanceof PDO) {
            try {
                orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
            } catch (Throwable $e) {
                return ['ok' => false, 'code' => 'country_scope_denied', 'message' => 'Country scope denied.'];
            }
        }
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
    } elseif ($packageType === 'full_disaster') {
        if ($admin !== null && $pdo instanceof PDO && !orange_backup_admin_may_verify($admin, $pdo)) {
            // may_verify gates execution; for read of Full state, require backup_view via existing helpers
        }
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
    } else {
        return ['ok' => false, 'code' => 'unsupported_package_type', 'message' => 'Unsupported package type.'];
    }

    if ($admin !== null && $pdo instanceof PDO) {
        // Unauthorized cannot read qualification details (same gate as verify for details).
        if (!orange_backup_admin_may_verify($admin, $pdo) && empty($admin['is_superuser'])) {
            $canView = function_exists('orange_backup_admin_may_view')
                ? orange_backup_admin_may_view($admin, $pdo)
                : false;
            if (!$canView) {
                return ['ok' => false, 'code' => 'permission_denied', 'message' => 'Not authorized.'];
            }
        }
    }

    $manifest = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'manifest.json');
    $health = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'health.json');
    $healthy = ($health['package_status'] ?? '') === 'healthy';
    $schemaRevision = (int) ($manifest['schema_revision'] ?? 0);
    $fp = orange_backup_qualification_current_fingerprint($packagePath, $packageType);

    $verifyBound = $packageType === 'full_disaster'
        ? orange_backup_qualification_read_full_verify_bound($packagePath, $packageId)
        : orange_backup_qualification_read_country_verify_bound($packagePath, $packageId, $countryCode);
    $drvBound = $packageType === 'full_disaster'
        ? orange_backup_qualification_read_full_drv_bound($packagePath, $packageId)
        : orange_backup_qualification_read_country_drv_bound($packagePath, $packageId, $countryCode);

    $verifyLock = orange_backup_qualification_lock_path(
        $backupRoot,
        $packageType,
        $packageId,
        ORANGE_BACKUP_QUAL_ACTION_VERIFY,
        $countryCode
    );
    $drvLock = orange_backup_qualification_lock_path(
        $backupRoot,
        $packageType,
        $packageId,
        ORANGE_BACKUP_QUAL_ACTION_DRV,
        $countryCode
    );
    $verifyRunning = !empty($verifyLock['ok']) && orange_backup_qualification_lock_is_active((string) $verifyLock['path']);
    $drvRunning = !empty($drvLock['ok']) && orange_backup_qualification_lock_is_active((string) $drvLock['path']);

    $verifyState = 'not_run';
    if ($verifyRunning) {
        $verifyState = 'running';
    } elseif ($verifyBound['ok'] && ($verifyBound['status'] ?? '') === 'success') {
        $verifyState = 'success';
    } elseif ($verifyBound['ok'] && ($verifyBound['status'] ?? '') === 'failed') {
        $verifyState = 'failed';
    }

    $verifySuccess = $verifyState === 'success';
    $drvState = 'blocked';
    if (!$verifySuccess && !$drvRunning) {
        $drvState = 'blocked';
    } elseif ($drvRunning) {
        $drvState = 'running';
    } elseif ($drvBound['ok'] && ($drvBound['status'] ?? '') === 'success') {
        $drvState = 'success';
    } elseif ($drvBound['ok'] && ($drvBound['status'] ?? '') === 'failed') {
        $drvState = 'failed';
    } elseif ($verifySuccess) {
        $drvState = 'not_run';
    }

    // Package row for eligibility — only attach verification when DRV bound success/fail is trusted.
    $verification = null;
    $recoveryScore = null;
    if ($drvBound['ok'] && is_array($drvBound['report'] ?? null)) {
        $verification = [
            'overall_result' => (string) ($drvBound['report']['overall_result'] ?? ''),
            'validated_at' => (string) ($drvBound['report']['completed_at_utc']
                ?? $drvBound['report']['validated_at'] ?? ''),
            'recovery_score' => (int) ($drvBound['report']['recovery_score'] ?? 0),
        ];
        $recoveryScore = (int) ($drvBound['report']['recovery_score'] ?? 0);
    }

    $packageRow = [
        'package_id' => $packageId,
        'package_type' => $packageType,
        'healthy' => $healthy,
        'schema_revision' => $schemaRevision,
        'backend' => (string) ($manifest['export_backend'] ?? ''),
        'registry_version' => (string) ($manifest['registry_version'] ?? ''),
        'verification' => $verification,
        'recovery_score' => $recoveryScore,
    ];
    if ($packageType === 'country_recovery') {
        $packageRow['country_code'] = strtoupper($countryCode);
        $packageRow['country_id'] = (int) ($manifest['country_id'] ?? 0);
    }

    $eligibility = orange_restore_admin_package_eligibility($packageRow, $packageType);
    $recoverable = (($eligibility['eligibility_status'] ?? '') === 'eligible');

    $verifyReport = ($verifyBound['ok'] ?? false) ? ($verifyBound['report'] ?? null) : null;
    $drvReport = ($drvBound['ok'] ?? false) ? ($drvBound['report'] ?? null) : null;

    return [
        'ok' => true,
        'package' => [
            'package_type' => $packageType,
            'package_id' => $packageId,
            'safe_relative_path' => orange_backup_qualification_safe_relative_for_package(
                $packageType,
                $packageId,
                $countryCode
            ),
            'country_code' => $packageType === 'country_recovery' ? strtoupper($countryCode) : '',
            'schema_revision' => $schemaRevision,
            'current_package_fingerprint' => $fp,
            'health' => $healthy ? 'healthy' : (string) ($health['package_status'] ?? 'unknown'),
            'recoverable' => $recoverable,
            'eligibility_status' => (string) ($eligibility['eligibility_status'] ?? ''),
            'eligibility_reason_code' => (string) ($eligibility['eligibility_reason_code'] ?? ''),
        ],
        'verify' => [
            'state' => $verifyState,
            'report_reference' => $verifyReport !== null
                ? ($packageType === 'full_disaster'
                    ? basename(orange_backup_qualification_full_verify_sibling_path($packagePath, $packageId))
                    : 'country_verify_report.json')
                : null,
            'completed_at_utc' => is_array($verifyReport)
                ? (string) ($verifyReport['completed_at_utc'] ?? $verifyReport['generated_at'] ?? '')
                : '',
            'operator_admin_id' => is_array($verifyReport) ? ($verifyReport['operator_admin_id'] ?? null) : null,
            'stable_result_code' => is_array($verifyReport)
                ? (string) ($verifyReport['stable_result_code'] ?? $verifyReport['overall'] ?? '')
                : (string) ($verifyBound['reason'] ?? ''),
            'fingerprint_match' => (bool) ($verifyBound['ok'] ?? false),
        ],
        'drv' => [
            'state' => $drvState,
            'report_reference' => $drvReport !== null
                ? ($packageType === 'full_disaster'
                    ? $packageId . '.' . ORANGE_RECOVERY_VALIDATION_REPORT_FILE
                    : $packageId . '.country_recovery_validation.json')
                : null,
            'completed_at_utc' => is_array($drvReport)
                ? (string) ($drvReport['completed_at_utc'] ?? $drvReport['validated_at'] ?? '')
                : '',
            'recovery_score' => is_array($drvReport) ? (int) ($drvReport['recovery_score'] ?? 0) : null,
            'stable_result_code' => is_array($drvReport)
                ? (string) ($drvReport['overall_result'] ?? '')
                : (string) ($drvBound['reason'] ?? ''),
            'fingerprint_match' => (bool) ($drvBound['ok'] ?? false),
        ],
        // Explicit freezes
        'authorities' => [
            'provenance_used' => false,
            'local_storage_used' => false,
            'health_alone_not_success' => true,
            'eligibility_function' => 'orange_restore_admin_package_eligibility',
        ],
    ];
}

/**
 * Stage 4B — public, safe package qualification status (no absolute paths / raw reports / fingerprints).
 *
 * @param array{admin_id?:int|null,kind?:string}|null $admin
 * @return array<string, mixed>
 */
function orange_backup_qualification_public_status(
    string $backupRoot,
    string $packageType,
    string $packageId,
    string $countryCode = '',
    ?array $admin = null,
    ?PDO $pdo = null
): array {
    $resolved = orange_backup_qualification_resolve(
        $backupRoot,
        $packageType,
        $packageId,
        $countryCode,
        $admin,
        $pdo
    );
    if (empty($resolved['ok'])) {
        return [
            'ok' => false,
            'code' => (string) ($resolved['code'] ?? 'resolve_failed'),
            'message' => (string) ($resolved['message'] ?? 'تعذر قراءة حالة التأهيل.'),
        ];
    }

    $pkg = is_array($resolved['package'] ?? null) ? $resolved['package'] : [];
    $verify = is_array($resolved['verify'] ?? null) ? $resolved['verify'] : [];
    $drv = is_array($resolved['drv'] ?? null) ? $resolved['drv'] : [];

    $vState = (string) ($verify['state'] ?? 'not_run');
    $dState = (string) ($drv['state'] ?? 'blocked');

    $vSummary = match ($vState) {
        'success' => 'تم التحقق من الحزمة بنجاح (نتيجة محفوظة).',
        'failed' => 'فشل التحقق من الحزمة.',
        'running' => 'عملية التحقق قيد التنفيذ حالياً.',
        default => 'لم يُنفَّذ التحقق بعد.',
    };
    $dSummary = match ($dState) {
        'success' => 'اجتازت الحزمة فحص قابلية الاسترداد (نتيجة محفوظة).',
        'failed' => 'فشل فحص قابلية الاسترداد.',
        'running' => 'فحص قابلية الاسترداد قيد التنفيذ حالياً.',
        'blocked' => 'فحص قابلية الاسترداد غير متاح قبل نجاح التحقق.',
        default => 'لم يُنفَّذ فحص قابلية الاسترداد بعد.',
    };

    $vCode = (string) ($verify['stable_result_code'] ?? '');
    if ($vCode === '' || strlen($vCode) > 64 || preg_match('/[\\\\\\/]|\\.\\./', $vCode)) {
        $vCode = $vState;
    }
    $dCode = (string) ($drv['stable_result_code'] ?? '');
    if ($dCode === '' || strlen($dCode) > 64 || preg_match('/[\\\\\\/]|\\.\\./', $dCode)) {
        $dCode = $dState;
    }

    return [
        'ok' => true,
        'package' => [
            'package_type' => (string) ($pkg['package_type'] ?? $packageType),
            'package_id' => (string) ($pkg['package_id'] ?? $packageId),
            'country_code' => (string) ($pkg['country_code'] ?? ''),
            'health' => (string) ($pkg['health'] ?? 'unknown'),
            'recoverable' => (bool) ($pkg['recoverable'] ?? false),
        ],
        'verify' => [
            'state' => $vState,
            'completed_at' => (string) ($verify['completed_at_utc'] ?? ''),
            'safe_result_code' => $vCode,
            'safe_summary' => $vSummary,
            'report_available' => ($verify['report_reference'] ?? null) !== null && $vState !== 'not_run' && $vState !== 'running',
            'retry_allowed' => ($vState === 'failed' || $vState === 'not_run'),
        ],
        'drv' => [
            'state' => $dState,
            'completed_at' => (string) ($drv['completed_at_utc'] ?? ''),
            'recovery_score' => isset($drv['recovery_score']) ? (int) $drv['recovery_score'] : null,
            'safe_result_code' => $dCode,
            'safe_summary' => $dSummary,
            'report_available' => ($drv['report_reference'] ?? null) !== null && $dState !== 'not_run' && $dState !== 'running' && $dState !== 'blocked',
            'retry_allowed' => ($dState === 'failed' || $dState === 'not_run'),
        ],
    ];
}

/** Max package identities accepted by the read-only batch status transport. */
const ORANGE_BACKUP_QUAL_STATUS_BATCH_MAX_ITEMS = 5;

/**
 * Stage 4B — transport-only batch wrapper around orange_backup_qualification_public_status.
 * Does not introduce a new state authority; each item is resolved independently.
 *
 * @param list<array{package_type?:string,package_id?:string,country_code?:string}> $items
 * @param array{admin_id?:int|null,kind?:string}|null $admin
 * @return array{
 *   ok:bool,
 *   code?:string,
 *   message?:string,
 *   results?:list<array<string,mixed>>
 * }
 */
function orange_backup_qualification_public_status_batch(
    string $backupRoot,
    array $items,
    ?array $admin = null,
    ?PDO $pdo = null,
    int $maxItems = ORANGE_BACKUP_QUAL_STATUS_BATCH_MAX_ITEMS
): array {
    if ($maxItems < 1) {
        $maxItems = ORANGE_BACKUP_QUAL_STATUS_BATCH_MAX_ITEMS;
    }
    if ($items === []) {
        return [
            'ok' => false,
            'code' => 'empty_batch',
            'message' => 'دفعة الحالات فارغة.',
        ];
    }
    if (count($items) > $maxItems) {
        return [
            'ok' => false,
            'code' => 'batch_too_large',
            'message' => 'تجاوزت الدفعة الحد الأقصى المسموح (' . $maxItems . ').',
        ];
    }

    $seen = [];
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            return [
                'ok' => false,
                'code' => 'invalid_batch_item',
                'message' => 'عنصر دفعة غير صالح.',
            ];
        }
        $packageType = trim((string) ($item['package_type'] ?? ''));
        $packageId = trim((string) ($item['package_id'] ?? ''));
        $countryCode = trim((string) ($item['country_code'] ?? ''));
        if ($packageId === '' || str_contains($packageId, '/') || str_contains($packageId, '\\')
            || str_contains($packageId, '..') || preg_match('#^[a-zA-Z]:#', $packageId)) {
            return [
                'ok' => false,
                'code' => 'unsafe_package_id',
                'message' => 'معرّف الحزمة غير صالح.',
            ];
        }
        if ($countryCode !== '' && (str_contains($countryCode, '/') || str_contains($countryCode, '\\')
            || str_contains($countryCode, '..'))) {
            return [
                'ok' => false,
                'code' => 'unsafe_country_code',
                'message' => 'رمز الدولة غير صالح.',
            ];
        }
        if (isset($item['package_path']) || isset($item['path']) || isset($item['report_path'])) {
            return [
                'ok' => false,
                'code' => 'path_not_allowed',
                'message' => 'مسارات الملفات غير مقبولة في طلب الحالة.',
            ];
        }
        $key = $packageType . '|' . strtoupper($countryCode) . '|' . $packageId;
        if (isset($seen[$key])) {
            continue; // dedupe — first wins
        }
        $seen[$key] = true;
        $normalized[] = [
            'package_type' => $packageType,
            'package_id' => $packageId,
            'country_code' => $countryCode,
            'exact_key' => $key,
        ];
    }

    if ($normalized === []) {
        return [
            'ok' => false,
            'code' => 'empty_batch',
            'message' => 'دفعة الحالات فارغة بعد إزالة التكرار.',
        ];
    }

    $results = [];
    foreach ($normalized as $row) {
        $packageType = $row['package_type'];
        $packageId = $row['package_id'];
        $countryCode = $row['country_code'];

        if ($packageType === 'country_recovery') {
            if ($pdo instanceof PDO) {
                try {
                    orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
                } catch (Throwable $e) {
                    $results[] = [
                        'ok' => false,
                        'exact_key' => $row['exact_key'],
                        'package_type' => $packageType,
                        'package_id' => $packageId,
                        'country_code' => strtoupper($countryCode),
                        'code' => 'country_scope_denied',
                        'message' => 'حزمة الدولة خارج سياق الدولة المحدد في الأدمن.',
                    ];
                    continue;
                }
            }
        } elseif ($packageType !== 'full_disaster') {
            $results[] = [
                'ok' => false,
                'exact_key' => $row['exact_key'],
                'package_type' => $packageType,
                'package_id' => $packageId,
                'country_code' => $countryCode,
                'code' => 'unsupported_package_type',
                'message' => 'نوع الحزمة غير مدعوم.',
            ];
            continue;
        }

        $status = orange_backup_qualification_public_status(
            $backupRoot,
            $packageType,
            $packageId,
            $countryCode,
            $admin,
            $pdo
        );
        if (empty($status['ok'])) {
            $results[] = [
                'ok' => false,
                'exact_key' => $row['exact_key'],
                'package_type' => $packageType,
                'package_id' => $packageId,
                'country_code' => $packageType === 'country_recovery' ? strtoupper($countryCode) : '',
                'code' => (string) ($status['code'] ?? 'resolve_failed'),
                'message' => (string) ($status['message'] ?? 'تعذر قراءة حالة التأهيل.'),
            ];
            continue;
        }

        $results[] = [
            'ok' => true,
            'exact_key' => $row['exact_key'],
            'qualification' => [
                'package' => $status['package'],
                'verify' => $status['verify'],
                'drv' => $status['drv'],
            ],
        ];
    }

    return [
        'ok' => true,
        'results' => $results,
    ];
}
