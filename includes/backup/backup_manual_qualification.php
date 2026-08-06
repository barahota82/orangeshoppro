<?php

declare(strict_types=1);

/**
 * Manual Admin qualification UI state (Country Verify button authority).
 *
 * Separate from Backend package-integrity evidence (country_verify_report.json).
 * Not Stage 1 provenance. Not Restore eligibility by itself.
 *
 * Sidecar:
 * {BackupRoot}/.orange_meta/manual_qualification/v1/country/{CC}/{package_id}/verify.json
 */

require_once __DIR__ . '/backup_paths.php';
// Caller must load backup_qualification.php first (shared safe-id / atomic JSON helpers).

const ORANGE_BACKUP_MANUAL_QUAL_SCHEMA_VERSION = 1;
const ORANGE_BACKUP_MANUAL_QUAL_REL_ROOT = '.orange_meta' . DIRECTORY_SEPARATOR . 'manual_qualification' . DIRECTORY_SEPARATOR . 'v1';
const ORANGE_BACKUP_MANUAL_QUAL_TRIGGER = 'manual_admin_verify';
const ORANGE_BACKUP_MANUAL_QUAL_ACTION_VERIFY = 'verify';

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function orange_backup_manual_qualification_country_verify_path(
    string $backupRoot,
    string $countryCode,
    string $packageId
): array {
    $idCheck = orange_backup_qualification_assert_safe_id($packageId);
    if (empty($idCheck['ok'])) {
        return ['ok' => false, 'error' => (string) ($idCheck['error'] ?? 'unsafe_package_id')];
    }
    $cc = strtoupper(trim($countryCode));
    if ($cc === '' || !preg_match(ORANGE_BACKUP_QUAL_COUNTRY_RE, $cc)) {
        return ['ok' => false, 'error' => 'unsafe_country_code'];
    }
    $root = realpath($backupRoot);
    if ($root === false || !is_dir($root)) {
        return ['ok' => false, 'error' => 'backup_root_unreadable'];
    }
    $rel = ORANGE_BACKUP_MANUAL_QUAL_REL_ROOT
        . DIRECTORY_SEPARATOR . 'country'
        . DIRECTORY_SEPARATOR . $cc
        . DIRECTORY_SEPARATOR . $packageId
        . DIRECTORY_SEPARATOR . 'verify.json';
    $path = $root . DIRECTORY_SEPARATOR . $rel;
    $normRoot = strtolower(str_replace('\\', '/', rtrim($root, '\\/')));
    $normPath = strtolower(str_replace('\\', '/', $path));
    if ($normPath === $normRoot || !str_starts_with($normPath, $normRoot . '/')) {
        return ['ok' => false, 'error' => 'path_escape'];
    }

    return ['ok' => true, 'path' => $path];
}

/**
 * Read and validate manual Country Verify UI evidence bound to current fingerprint.
 *
 * @return array{
 *   ok:bool,
 *   state?:string,
 *   reason?:string,
 *   record?:array<string,mixed>,
 *   completed_at_utc?:string,
 *   safe_result_code?:string,
 *   safe_summary?:string
 * }
 */
function orange_backup_manual_qualification_read_country_verify(
    string $backupRoot,
    string $packageId,
    string $countryCode,
    string $currentFingerprint
): array {
    $pathInfo = orange_backup_manual_qualification_country_verify_path($backupRoot, $countryCode, $packageId);
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'reason' => (string) ($pathInfo['error'] ?? 'path_invalid')];
    }
    $path = (string) $pathInfo['path'];
    if (!is_file($path) || !is_readable($path)) {
        return ['ok' => false, 'reason' => 'manual_not_present'];
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['ok' => false, 'reason' => 'manual_invalid_json'];
    }
    if ((int) ($decoded['metadata_schema_version'] ?? 0) !== ORANGE_BACKUP_MANUAL_QUAL_SCHEMA_VERSION) {
        return ['ok' => false, 'reason' => 'manual_schema_mismatch'];
    }
    if (($decoded['package_type'] ?? '') !== 'country'
        && ($decoded['package_type'] ?? '') !== 'country_recovery') {
        return ['ok' => false, 'reason' => 'manual_package_type_mismatch'];
    }
    if (strtoupper((string) ($decoded['country_code'] ?? '')) !== strtoupper(trim($countryCode))) {
        return ['ok' => false, 'reason' => 'manual_country_mismatch'];
    }
    if ((string) ($decoded['package_id'] ?? '') !== $packageId) {
        return ['ok' => false, 'reason' => 'manual_package_id_mismatch'];
    }
    if (($decoded['action'] ?? '') !== ORANGE_BACKUP_MANUAL_QUAL_ACTION_VERIFY) {
        return ['ok' => false, 'reason' => 'manual_action_mismatch'];
    }
    if (($decoded['trigger'] ?? '') !== ORANGE_BACKUP_MANUAL_QUAL_TRIGGER) {
        return ['ok' => false, 'reason' => 'manual_trigger_mismatch'];
    }
    $state = (string) ($decoded['state'] ?? '');
    if ($state !== 'success' && $state !== 'failed') {
        return ['ok' => false, 'reason' => 'manual_state_invalid'];
    }
    $fp = trim((string) ($decoded['package_fingerprint'] ?? ''));
    if ($fp === '' || $currentFingerprint === '' || !hash_equals($fp, $currentFingerprint)) {
        return ['ok' => false, 'reason' => 'manual_fingerprint_mismatch'];
    }

    return [
        'ok' => true,
        'state' => $state,
        'record' => $decoded,
        'completed_at_utc' => (string) ($decoded['completed_at_utc'] ?? ''),
        'safe_result_code' => (string) ($decoded['safe_result_code'] ?? $state),
        'safe_summary' => (string) ($decoded['safe_summary'] ?? ''),
    ];
}

/**
 * Persist manual Admin Country Verify confirmation (atomic). Idempotent for same bound success.
 *
 * @param array{admin_id?:int|null,kind?:string}|null $operator
 * @return array{ok:bool,written:bool,path?:string,error?:string}
 */
function orange_backup_manual_qualification_write_country_verify(
    string $backupRoot,
    string $packageId,
    string $countryCode,
    string $packageFingerprint,
    string $state,
    string $safeResultCode = '',
    string $safeSummary = '',
    ?array $operator = null
): array {
    if ($state !== 'success' && $state !== 'failed') {
        return ['ok' => false, 'written' => false, 'error' => 'invalid_state'];
    }
    if ($packageFingerprint === '' || !preg_match('/^[a-f0-9]{64}$/i', $packageFingerprint)) {
        // Allow non-sha hex digests used by CRP fingerprint if length is bounded.
        if ($packageFingerprint === '' || strlen($packageFingerprint) < 16 || strlen($packageFingerprint) > 128
            || preg_match('/[\\\\\\/]|\\.\\./', $packageFingerprint)) {
            return ['ok' => false, 'written' => false, 'error' => 'invalid_fingerprint'];
        }
    }
    $pathInfo = orange_backup_manual_qualification_country_verify_path($backupRoot, $countryCode, $packageId);
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'written' => false, 'error' => (string) ($pathInfo['error'] ?? 'path_invalid')];
    }
    $path = (string) $pathInfo['path'];

    $existing = orange_backup_manual_qualification_read_country_verify(
        $backupRoot,
        $packageId,
        $countryCode,
        $packageFingerprint
    );
    if (!empty($existing['ok']) && ($existing['state'] ?? '') === 'success' && $state === 'success') {
        // Green re-click: no duplicate write.
        return ['ok' => true, 'written' => false, 'path' => $path];
    }

    $cc = strtoupper(trim($countryCode));
    $code = trim($safeResultCode);
    if ($code === '' || strlen($code) > 64 || preg_match('/[\\\\\\/]|\\.\\./', $code)) {
        $code = $state;
    }
    $summary = trim($safeSummary);
    if ($summary === '') {
        $summary = $state === 'success'
            ? 'تم تأكيد التحقق اليدوي من الحزمة بنجاح.'
            : 'فشل التحقق اليدوي من الحزمة.';
    }
    if (strlen($summary) > 400) {
        $summary = substr($summary, 0, 400);
    }

    $payload = [
        'metadata_schema_version' => ORANGE_BACKUP_MANUAL_QUAL_SCHEMA_VERSION,
        'package_type' => 'country',
        'country_code' => $cc,
        'package_id' => $packageId,
        'package_fingerprint' => $packageFingerprint,
        'action' => ORANGE_BACKUP_MANUAL_QUAL_ACTION_VERIFY,
        'trigger' => ORANGE_BACKUP_MANUAL_QUAL_TRIGGER,
        'state' => $state,
        'completed_at_utc' => gmdate('c'),
        'safe_result_code' => $code,
        'safe_summary' => $summary,
    ];
    $adminId = (int) ($operator['admin_id'] ?? 0);
    if ($adminId > 0) {
        $payload['operator_admin_id'] = $adminId;
    }

    orange_backup_qualification_write_json_atomic($path, $payload);
    if (function_exists('chmod')) {
        @chmod($path, 0660);
        @chmod(dirname($path), 0770);
    }

    return ['ok' => true, 'written' => true, 'path' => $path];
}
