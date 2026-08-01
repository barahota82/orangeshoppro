<?php

declare(strict_types=1);

/**
 * Backup Provenance Metadata Registry (sidecar under BackupRoot).
 *
 * Owner-approved layout:
 *   <BackupRoot>/.orange_meta/provenance/v1/executions/<execution_id>.json
 *   <BackupRoot>/.orange_meta/provenance/v1/packages/full/<package_id>.json
 *   <BackupRoot>/.orange_meta/provenance/v1/packages/country/<CC>/<package_id>.json
 *
 * Authoritative only for origin/initiator/context/parent-child presentation.
 * Never controls Health / Verify / DRV / Recoverable / Restore eligibility.
 *
 * @see docs/archive — Backup Provenance Metadata Registry Sidecar Contract
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup_paths.php';

const ORANGE_BACKUP_PROVENANCE_SCHEMA_VERSION = 1;
const ORANGE_BACKUP_PROVENANCE_META_DIR = '.orange_meta';
const ORANGE_BACKUP_PROVENANCE_REL_ROOT = '.orange_meta/provenance/v1';
const ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID = 'ORANGE_BACKUP_PROVENANCE_EXECUTION_ID';
const ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON = 'ORANGE_BACKUP_PROVENANCE_CONTEXT_JSON';
const ORANGE_BACKUP_PROVENANCE_PACKAGE_ID_RE = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
const ORANGE_BACKUP_PROVENANCE_COUNTRY_RE = '/^[A-Za-z]{2}$/';
const ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/';
const ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR = 'معلومات مصدر التشغيل غير متاحة لهذه الحزمة.';

function orange_backup_provenance_is_meta_entry(string $entryName): bool
{
    return $entryName === ORANGE_BACKUP_PROVENANCE_META_DIR
        || str_starts_with($entryName, ORANGE_BACKUP_PROVENANCE_META_DIR . DIRECTORY_SEPARATOR)
        || str_starts_with($entryName, ORANGE_BACKUP_PROVENANCE_META_DIR . '/');
}

function orange_backup_provenance_new_execution_id(): string
{
    return 'exec_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
}

function orange_backup_provenance_env_execution_id(): ?string
{
    $raw = getenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $id = trim($raw);
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE, $id)) {
        return null;
    }

    return $id;
}

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function orange_backup_provenance_root(string $backupRoot): array
{
    $root = realpath($backupRoot);
    if ($root === false || !is_dir($root)) {
        return ['ok' => false, 'error' => 'backup_root_unavailable'];
    }
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ORANGE_BACKUP_PROVENANCE_REL_ROOT);

    return ['ok' => true, 'path' => $path];
}

/**
 * @return array{ok:bool,path?:string,error?:string}
 */
function orange_backup_provenance_resolve_contained_path(
    string $backupRoot,
    string $relativeUnderV1,
    bool $createDirs = true
): array {
    $rootInfo = orange_backup_provenance_root($backupRoot);
    if (empty($rootInfo['ok'])) {
        return $rootInfo;
    }
    $v1 = (string) $rootInfo['path'];
    $rel = str_replace('\\', '/', $relativeUnderV1);
    if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '://')
        || str_starts_with($rel, '//') || preg_match('/^[A-Za-z]:/', $rel)
        || str_contains($rel, '..')) {
        return ['ok' => false, 'error' => 'path_rejected'];
    }
    $full = $v1 . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if ($createDirs) {
        if (!is_dir($v1) && !@mkdir($v1, 0770, true) && !is_dir($v1)) {
            return ['ok' => false, 'error' => 'cannot_create_provenance_root'];
        }
        $parent = dirname($full);
        if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
            return ['ok' => false, 'error' => 'cannot_create_provenance_dir'];
        }
    } elseif (!is_dir($v1)) {
        return ['ok' => false, 'error' => 'provenance_root_missing'];
    }
    $v1Real = realpath($v1) ?: $v1;
    $parent = dirname($full);
    $parentReal = is_dir($parent) ? (realpath($parent) ?: $parent) : $parent;
    if ($createDirs && is_dir($parent)) {
        $parentReal = realpath($parent) ?: $parent;
    }
    $normalizedParent = str_replace('\\', '/', (string) $parentReal);
    $normalizedV1 = str_replace('\\', '/', $v1Real);
    if (!str_starts_with($normalizedParent, $normalizedV1)) {
        return ['ok' => false, 'error' => 'path_escape'];
    }

    return ['ok' => true, 'path' => rtrim((string) $parentReal, '\\/') . DIRECTORY_SEPARATOR . basename($full)];
}

/**
 * @param array<string, mixed> $data
 * @return array{ok:bool,error?:string}
 */
function orange_backup_provenance_atomic_write_json(string $finalPath, array $data): array
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'json_encode_failed'];
    }
    $dir = dirname($finalPath);
    $base = basename($finalPath);
    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . $base . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    $lockPath = $dir . DIRECTORY_SEPARATOR . '.' . $base . '.lock';
    $lockFh = @fopen($lockPath, 'c+b');
    if ($lockFh === false) {
        return ['ok' => false, 'error' => 'lock_open_failed'];
    }
    if (!flock($lockFh, LOCK_EX)) {
        fclose($lockFh);

        return ['ok' => false, 'error' => 'lock_failed'];
    }
    try {
        if (file_put_contents($tmp, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'temp_write_failed'];
        }
        if (function_exists('chmod')) {
            @chmod($tmp, 0660);
        }
        if (!@rename($tmp, $finalPath)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'atomic_rename_failed'];
        }
        if (function_exists('chmod')) {
            @chmod($finalPath, 0660);
        }

        return ['ok' => true];
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_backup_provenance_read_json_file(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok:bool,execution_id?:string,error?:string,warning?:string}
 */
function orange_backup_provenance_begin_execution(string $backupRoot, array $fields): array
{
    $executionId = trim((string) ($fields['execution_id'] ?? ''));
    if ($executionId === '') {
        $executionId = orange_backup_provenance_new_execution_id();
    }
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE, $executionId)) {
        return ['ok' => false, 'error' => 'invalid_execution_id'];
    }
    $record = [
        'metadata_schema_version' => ORANGE_BACKUP_PROVENANCE_SCHEMA_VERSION,
        'execution_id' => $executionId,
        'trigger_mode' => (string) ($fields['trigger_mode'] ?? 'manual'),
        'backup_scope' => (string) ($fields['backup_scope'] ?? 'full'),
        'initiated_by_kind' => (string) ($fields['initiated_by_kind'] ?? 'system'),
        'initiated_by_admin_id' => isset($fields['initiated_by_admin_id']) ? (int) $fields['initiated_by_admin_id'] : null,
        'initiated_by_display_name_snapshot' => (string) ($fields['initiated_by_display_name_snapshot'] ?? 'System'),
        'initiated_context_country_id' => isset($fields['initiated_context_country_id'])
            ? (int) $fields['initiated_context_country_id'] : null,
        'initiated_context_country_code' => isset($fields['initiated_context_country_code'])
            ? strtoupper((string) $fields['initiated_context_country_code']) : null,
        'initiated_context_country_name' => isset($fields['initiated_context_country_name'])
            ? (string) $fields['initiated_context_country_name'] : null,
        'started_at_utc' => (string) ($fields['started_at_utc'] ?? gmdate('c')),
        'completed_at_utc' => null,
        'overall_status' => (string) ($fields['overall_status'] ?? 'running'),
        'children' => is_array($fields['children'] ?? null) ? $fields['children'] : [],
        'warning' => isset($fields['warning']) ? (string) $fields['warning'] : null,
    ];
    if (($record['trigger_mode'] ?? '') === 'scheduled' || ($record['initiated_by_kind'] ?? '') === 'system') {
        $record['initiated_by_admin_id'] = null;
        $record['initiated_context_country_id'] = null;
        $record['initiated_context_country_code'] = null;
        $record['initiated_context_country_name'] = null;
        if (($record['initiated_by_display_name_snapshot'] ?? '') === '') {
            $record['initiated_by_display_name_snapshot'] = 'System';
        }
    }
    $pathInfo = orange_backup_provenance_resolve_contained_path(
        $backupRoot,
        'executions/' . $executionId . '.json'
    );
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'error' => (string) ($pathInfo['error'] ?? 'path'), 'execution_id' => $executionId];
    }
    $write = orange_backup_provenance_atomic_write_json((string) $pathInfo['path'], $record);
    if (empty($write['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($write['error'] ?? 'write_failed'),
            'execution_id' => $executionId,
            'warning' => 'provenance_write_failed',
        ];
    }

    return ['ok' => true, 'execution_id' => $executionId];
}

/**
 * @param array<string, mixed> $patch
 * @return array{ok:bool,error?:string}
 */
function orange_backup_provenance_finish_execution(string $backupRoot, string $executionId, array $patch): array
{
    $existing = orange_backup_provenance_read_execution($backupRoot, $executionId);
    if ($existing === null) {
        return ['ok' => false, 'error' => 'execution_missing'];
    }
    foreach (['overall_status', 'completed_at_utc', 'warning', 'error_summary'] as $key) {
        if (array_key_exists($key, $patch)) {
            $existing[$key] = $patch[$key];
        }
    }
    if (isset($patch['children']) && is_array($patch['children'])) {
        $existing['children'] = orange_backup_provenance_merge_children(
            is_array($existing['children'] ?? null) ? $existing['children'] : [],
            $patch['children']
        );
    }
    if (empty($existing['completed_at_utc'])) {
        $existing['completed_at_utc'] = gmdate('c');
    }
    $pathInfo = orange_backup_provenance_resolve_contained_path(
        $backupRoot,
        'executions/' . $executionId . '.json'
    );
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'error' => (string) ($pathInfo['error'] ?? 'path')];
    }

    return orange_backup_provenance_atomic_write_json((string) $pathInfo['path'], $existing);
}

/**
 * @param list<array<string, mixed>> $current
 * @param list<array<string, mixed>> $incoming
 * @return list<array<string, mixed>>
 */
function orange_backup_provenance_merge_children(array $current, array $incoming): array
{
    $byKey = [];
    foreach ($current as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper((string) ($row['target_country_code'] ?? $row['code'] ?? ''));
        $key = $code !== '' ? $code : ('idx_' . count($byKey));
        $byKey[$key] = $row;
    }
    foreach ($incoming as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = strtoupper((string) ($row['target_country_code'] ?? $row['code'] ?? ''));
        $key = $code !== '' ? $code : ('idx_' . count($byKey));
        $byKey[$key] = array_merge($byKey[$key] ?? [], $row);
    }

    return array_values($byKey);
}

/**
 * @return array<string, mixed>|null
 */
function orange_backup_provenance_read_execution(string $backupRoot, string $executionId): ?array
{
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE, $executionId)) {
        return null;
    }
    $pathInfo = orange_backup_provenance_resolve_contained_path(
        $backupRoot,
        'executions/' . $executionId . '.json',
        false
    );
    if (empty($pathInfo['ok'])) {
        return null;
    }

    return orange_backup_provenance_read_json_file((string) $pathInfo['path']);
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok:bool,error?:string}
 */
function orange_backup_provenance_record_full_package(string $backupRoot, array $fields): array
{
    $packageId = (string) ($fields['package_id'] ?? '');
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_PACKAGE_ID_RE, $packageId)) {
        return ['ok' => false, 'error' => 'invalid_package_id'];
    }
    $executionId = (string) ($fields['execution_id'] ?? '');
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE, $executionId)) {
        return ['ok' => false, 'error' => 'invalid_execution_id'];
    }
    $record = orange_backup_provenance_normalize_package_record(array_merge($fields, [
        'package_type' => 'full',
        'package_id' => $packageId,
        'execution_id' => $executionId,
        'relative_package_path' => 'snapshots/' . $packageId,
        'target_country_id' => null,
        'target_country_code' => null,
    ]));
    $pathInfo = orange_backup_provenance_resolve_contained_path(
        $backupRoot,
        'packages/full/' . $packageId . '.json'
    );
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'error' => (string) ($pathInfo['error'] ?? 'path')];
    }

    return orange_backup_provenance_atomic_write_json((string) $pathInfo['path'], $record);
}

/**
 * @param array<string, mixed> $fields
 * @return array{ok:bool,error?:string}
 */
function orange_backup_provenance_record_country_package(string $backupRoot, array $fields): array
{
    $packageId = (string) ($fields['package_id'] ?? '');
    $countryCode = strtoupper((string) ($fields['target_country_code'] ?? $fields['country_code'] ?? ''));
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_PACKAGE_ID_RE, $packageId)) {
        return ['ok' => false, 'error' => 'invalid_package_id'];
    }
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_COUNTRY_RE, $countryCode)) {
        return ['ok' => false, 'error' => 'invalid_country_code'];
    }
    $executionId = (string) ($fields['execution_id'] ?? '');
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_EXECUTION_ID_RE, $executionId)) {
        return ['ok' => false, 'error' => 'invalid_execution_id'];
    }
    $record = orange_backup_provenance_normalize_package_record(array_merge($fields, [
        'package_type' => 'country',
        'package_id' => $packageId,
        'execution_id' => $executionId,
        'target_country_code' => $countryCode,
        'relative_package_path' => 'country_packages/' . $countryCode . '/' . $packageId,
    ]));
    $pathInfo = orange_backup_provenance_resolve_contained_path(
        $backupRoot,
        'packages/country/' . $countryCode . '/' . $packageId . '.json'
    );
    if (empty($pathInfo['ok'])) {
        return ['ok' => false, 'error' => (string) ($pathInfo['error'] ?? 'path')];
    }
    $write = orange_backup_provenance_atomic_write_json((string) $pathInfo['path'], $record);
    if (!empty($write['ok'])) {
        orange_backup_provenance_finish_execution($backupRoot, $executionId, [
            'children' => [[
                'target_country_id' => $record['target_country_id'] ?? null,
                'target_country_code' => $countryCode,
                'package_id' => $packageId,
                'status' => (string) ($record['package_result_status'] ?? 'success'),
            ]],
        ]);
    }

    return $write;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function orange_backup_provenance_normalize_package_record(array $fields): array
{
    return [
        'metadata_schema_version' => ORANGE_BACKUP_PROVENANCE_SCHEMA_VERSION,
        'execution_id' => (string) ($fields['execution_id'] ?? ''),
        'package_type' => (string) ($fields['package_type'] ?? ''),
        'package_id' => (string) ($fields['package_id'] ?? ''),
        'relative_package_path' => (string) ($fields['relative_package_path'] ?? ''),
        'target_country_id' => isset($fields['target_country_id']) ? (int) $fields['target_country_id'] : null,
        'target_country_code' => isset($fields['target_country_code'])
            ? strtoupper((string) $fields['target_country_code']) : null,
        'trigger_mode' => (string) ($fields['trigger_mode'] ?? ''),
        'backup_scope' => (string) ($fields['backup_scope'] ?? ''),
        'initiated_by_kind' => (string) ($fields['initiated_by_kind'] ?? ''),
        'initiated_by_admin_id' => isset($fields['initiated_by_admin_id']) ? (int) $fields['initiated_by_admin_id'] : null,
        'initiated_by_display_name_snapshot' => (string) ($fields['initiated_by_display_name_snapshot'] ?? ''),
        'initiated_context_country_id' => isset($fields['initiated_context_country_id'])
            ? (int) $fields['initiated_context_country_id'] : null,
        'initiated_context_country_code' => isset($fields['initiated_context_country_code'])
            ? strtoupper((string) $fields['initiated_context_country_code']) : null,
        'initiated_context_country_name' => isset($fields['initiated_context_country_name'])
            ? (string) $fields['initiated_context_country_name'] : null,
        'started_at_utc' => (string) ($fields['started_at_utc'] ?? gmdate('c')),
        'completed_at_utc' => (string) ($fields['completed_at_utc'] ?? gmdate('c')),
        'package_result_status' => (string) ($fields['package_result_status'] ?? 'success'),
        'schema_revision' => (int) ($fields['schema_revision'] ?? 0),
        'package_fingerprint' => (string) ($fields['package_fingerprint'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_backup_provenance_read_package(
    string $backupRoot,
    string $packageType,
    string $packageId,
    ?string $countryCode = null
): ?array {
    if (!preg_match(ORANGE_BACKUP_PROVENANCE_PACKAGE_ID_RE, $packageId)) {
        return null;
    }
    if ($packageType === 'full' || $packageType === 'full_disaster') {
        $rel = 'packages/full/' . $packageId . '.json';
    } elseif ($packageType === 'country' || $packageType === 'country_recovery') {
        $cc = strtoupper((string) $countryCode);
        if (!preg_match(ORANGE_BACKUP_PROVENANCE_COUNTRY_RE, $cc)) {
            return null;
        }
        $rel = 'packages/country/' . $cc . '/' . $packageId . '.json';
    } else {
        return null;
    }
    $pathInfo = orange_backup_provenance_resolve_contained_path($backupRoot, $rel, false);
    if (empty($pathInfo['ok'])) {
        return null;
    }

    return orange_backup_provenance_read_json_file((string) $pathInfo['path']);
}

/**
 * Attach presentation-safe provenance to a list/details package summary.
 *
 * @param array<string, mixed> $packageSummary
 * @return array<string, mixed>
 */
function orange_backup_provenance_bind_for_list(string $backupRoot, array $packageSummary): array
{
    $type = (string) ($packageSummary['package_type'] ?? '');
    $packageId = (string) ($packageSummary['package_id'] ?? '');
    $countryCode = (string) ($packageSummary['country_code'] ?? '');
    $sidecar = orange_backup_provenance_read_package($backupRoot, $type, $packageId, $countryCode !== '' ? $countryCode : null);
    if ($sidecar === null) {
        $packageSummary['provenance'] = null;
        $packageSummary['provenance_unavailable'] = true;
        $packageSummary['provenance_unavailable_reason'] = 'origin_unavailable_historical';
        $packageSummary['provenance_message_ar'] = ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR;

        return $packageSummary;
    }
    $expectedFp = trim((string) ($packageSummary['package_fingerprint'] ?? ''));
    if ($expectedFp === '' && is_string($packageSummary['manifest_package_fingerprint'] ?? null)) {
        $expectedFp = trim((string) $packageSummary['manifest_package_fingerprint']);
    }
    $sidecarFp = trim((string) ($sidecar['package_fingerprint'] ?? ''));
    if ($expectedFp !== '' && $sidecarFp !== '' && !hash_equals($expectedFp, $sidecarFp)) {
        $packageSummary['provenance'] = null;
        $packageSummary['provenance_unavailable'] = true;
        $packageSummary['provenance_unavailable_reason'] = 'fingerprint_mismatch';
        $packageSummary['provenance_message_ar'] = ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR;

        return $packageSummary;
    }
    if (($sidecar['package_id'] ?? '') !== $packageId) {
        $packageSummary['provenance'] = null;
        $packageSummary['provenance_unavailable'] = true;
        $packageSummary['provenance_unavailable_reason'] = 'package_id_mismatch';
        $packageSummary['provenance_message_ar'] = ORANGE_BACKUP_PROVENANCE_UNAVAILABLE_AR;

        return $packageSummary;
    }
    $packageSummary['provenance'] = [
        'trigger_mode' => (string) ($sidecar['trigger_mode'] ?? ''),
        'backup_scope' => (string) ($sidecar['backup_scope'] ?? ''),
        'initiated_by_kind' => (string) ($sidecar['initiated_by_kind'] ?? ''),
        'initiated_by_admin_id' => $sidecar['initiated_by_admin_id'] ?? null,
        'initiated_by_display_name_snapshot' => (string) ($sidecar['initiated_by_display_name_snapshot'] ?? ''),
        'initiated_context_country_id' => $sidecar['initiated_context_country_id'] ?? null,
        'initiated_context_country_code' => $sidecar['initiated_context_country_code'] ?? null,
        'initiated_context_country_name' => $sidecar['initiated_context_country_name'] ?? null,
        'execution_id' => (string) ($sidecar['execution_id'] ?? ''),
        'started_at_utc' => (string) ($sidecar['started_at_utc'] ?? ''),
        'completed_at_utc' => (string) ($sidecar['completed_at_utc'] ?? ''),
    ];
    $packageSummary['provenance_unavailable'] = false;

    return $packageSummary;
}

/**
 * @return array{
 *   initiated_by_kind:string,
 *   initiated_by_admin_id:?int,
 *   initiated_by_display_name_snapshot:string,
 *   initiated_context_country_id:?int,
 *   initiated_context_country_code:?string,
 *   initiated_context_country_name:?string
 * }
 */
function orange_backup_provenance_actor_from_admin(?array $admin, ?PDO $pdo = null): array
{
    $out = [
        'initiated_by_kind' => 'system',
        'initiated_by_admin_id' => null,
        'initiated_by_display_name_snapshot' => 'System',
        'initiated_context_country_id' => null,
        'initiated_context_country_code' => null,
        'initiated_context_country_name' => null,
    ];
    if (!is_array($admin) || (int) ($admin['id'] ?? 0) <= 0) {
        return $out;
    }
    $out['initiated_by_kind'] = 'admin';
    $out['initiated_by_admin_id'] = (int) $admin['id'];
    $display = trim((string) ($admin['display_name'] ?? ''));
    if ($display === '') {
        $display = trim((string) ($admin['username'] ?? ''));
    }
    $out['initiated_by_display_name_snapshot'] = $display !== '' ? $display : ('Admin#' . (int) $admin['id']);
    if ($pdo instanceof PDO) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'countries.php';
        if (function_exists('orange_admin_context_country_id')) {
            $cid = (int) orange_admin_context_country_id($pdo);
            if ($cid > 0) {
                $out['initiated_context_country_id'] = $cid;
            }
        }
        if (function_exists('orange_admin_context_country_code')) {
            $code = strtoupper(trim((string) orange_admin_context_country_code($pdo)));
            if (preg_match(ORANGE_BACKUP_PROVENANCE_COUNTRY_RE, $code)) {
                $out['initiated_context_country_code'] = $code;
                if (function_exists('orange_country_row_by_code')) {
                    $row = orange_country_row_by_code($pdo, $code, false);
                    if (is_array($row)) {
                        $name = trim((string) ($row['name_ar'] ?? $row['name_en'] ?? ''));
                        $out['initiated_context_country_name'] = $name !== '' ? $name : null;
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Best-effort: begin scheduled execution when CLI has no pre-assigned ID.
 *
 * @param array<string, mixed> $extra
 * @return array{ok:bool,execution_id:?string,warning?:string}
 */
function orange_backup_provenance_ensure_cli_execution(
    string $backupRoot,
    string $backupScope,
    array $extra = []
): array {
    $existing = orange_backup_provenance_env_execution_id();
    if ($existing !== null) {
        return ['ok' => true, 'execution_id' => $existing];
    }
    $begun = orange_backup_provenance_begin_execution($backupRoot, array_merge([
        'trigger_mode' => 'scheduled',
        'backup_scope' => $backupScope,
        'initiated_by_kind' => 'system',
        'initiated_by_display_name_snapshot' => 'System',
        'overall_status' => 'running',
    ], $extra));
    if (empty($begun['ok'])) {
        return [
            'ok' => false,
            'execution_id' => null,
            'warning' => (string) ($begun['error'] ?? 'provenance_begin_failed'),
        ];
    }
    $id = (string) ($begun['execution_id'] ?? '');
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID . '=' . $id);
    $_ENV[ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID] = $id;

    return ['ok' => true, 'execution_id' => $id];
}

/**
 * @return array<string, mixed>
 */
function orange_backup_provenance_context_from_env(): array
{
    $raw = getenv(ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Publish execution context for a child CLI process (inherits via putenv).
 *
 * @param array<string, mixed> $context
 */
function orange_backup_provenance_publish_cli_context(string $executionId, array $context): void
{
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID . '=' . $executionId);
    $_ENV[ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID] = $executionId;
    $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON . '=' . $json);
    $_ENV[ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON] = $json;
}

function orange_backup_provenance_clear_cli_context(): void
{
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID);
    putenv(ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON);
    unset($_ENV[ORANGE_BACKUP_PROVENANCE_ENV_EXECUTION_ID], $_ENV[ORANGE_BACKUP_PROVENANCE_ENV_CONTEXT_JSON]);
}

/**
 * Stable package-bound identity fingerprint (does not alter package payload).
 * Full: hash of checksums.sha256 (or manifest.json fallback).
 * Country: manifest package_fingerprint when present, else checksums/manifest hash.
 */
function orange_backup_provenance_package_identity_fingerprint(
    string $packagePath,
    string $packageType = 'full'
): string {
    if ($packageType === 'country' || $packageType === 'country_recovery') {
        $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = orange_backup_provenance_read_json_file($manifestPath);
        $fp = trim((string) ($manifest['package_fingerprint'] ?? ''));
        if ($fp !== '') {
            return $fp;
        }
    }
    $checksums = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksums) && is_readable($checksums)) {
        $raw = file_get_contents($checksums);
        if (is_string($raw) && $raw !== '') {
            return hash('sha256', $raw);
        }
    }
    $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (is_file($manifestPath) && is_readable($manifestPath)) {
        $raw = file_get_contents($manifestPath);
        if (is_string($raw) && $raw !== '') {
            return hash('sha256', $raw);
        }
    }

    return '';
}

/**
 * @return array<string, mixed>
 */
function orange_backup_provenance_fields_for_package_write(string $backupRoot, string $executionId): array
{
    $ctx = orange_backup_provenance_context_from_env();
    $exec = orange_backup_provenance_read_execution($backupRoot, $executionId);
    $source = $exec ?? $ctx;

    return [
        'execution_id' => $executionId,
        'trigger_mode' => (string) ($source['trigger_mode'] ?? ($ctx['trigger_mode'] ?? 'scheduled')),
        'backup_scope' => (string) ($source['backup_scope'] ?? ($ctx['backup_scope'] ?? 'full')),
        'initiated_by_kind' => (string) ($source['initiated_by_kind'] ?? ($ctx['initiated_by_kind'] ?? 'system')),
        'initiated_by_admin_id' => $source['initiated_by_admin_id'] ?? ($ctx['initiated_by_admin_id'] ?? null),
        'initiated_by_display_name_snapshot' => (string) (
            $source['initiated_by_display_name_snapshot']
            ?? ($ctx['initiated_by_display_name_snapshot'] ?? 'System')
        ),
        'initiated_context_country_id' => $source['initiated_context_country_id']
            ?? ($ctx['initiated_context_country_id'] ?? null),
        'initiated_context_country_code' => $source['initiated_context_country_code']
            ?? ($ctx['initiated_context_country_code'] ?? null),
        'initiated_context_country_name' => $source['initiated_context_country_name']
            ?? ($ctx['initiated_context_country_name'] ?? null),
        'started_at_utc' => (string) ($source['started_at_utc'] ?? gmdate('c')),
    ];
}

/**
 * Best-effort Full package sidecar after a successful snapshot (never throws).
 *
 * @return array{ok:bool,warning?:string}
 */
function orange_backup_provenance_after_full_success(
    string $backupRoot,
    string $packageId,
    string $packagePath,
    int $schemaRevision = 0
): array {
    try {
        $ensured = orange_backup_provenance_ensure_cli_execution($backupRoot, 'full');
        $executionId = (string) ($ensured['execution_id'] ?? '');
        if ($executionId === '') {
            return ['ok' => false, 'warning' => 'provenance_execution_unavailable'];
        }
        $fields = orange_backup_provenance_fields_for_package_write($backupRoot, $executionId);
        $write = orange_backup_provenance_record_full_package($backupRoot, array_merge($fields, [
            'package_id' => $packageId,
            'completed_at_utc' => gmdate('c'),
            'package_result_status' => 'success',
            'schema_revision' => $schemaRevision,
            'package_fingerprint' => orange_backup_provenance_package_identity_fingerprint($packagePath, 'full'),
        ]));
        orange_backup_provenance_finish_execution($backupRoot, $executionId, [
            'overall_status' => 'success',
            'completed_at_utc' => gmdate('c'),
            'warning' => empty($write['ok']) ? 'package_sidecar_write_failed' : null,
        ]);

        return empty($write['ok'])
            ? ['ok' => false, 'warning' => (string) ($write['error'] ?? 'package_sidecar_write_failed')]
            : ['ok' => true];
    } catch (Throwable $e) {
        error_log('[orange backup provenance] after_full_success: ' . $e->getMessage());

        return ['ok' => false, 'warning' => 'provenance_exception'];
    }
}

/**
 * Best-effort Country batch provenance (parent + children). Never throws.
 *
 * @param list<array<string, mixed>> $succeeded
 * @param list<array<string, mixed>> $failed
 * @return array{ok:bool,warning?:string}
 */
function orange_backup_provenance_after_country_batch(
    string $backupRoot,
    array $succeeded,
    array $failed,
    bool $batchOk
): array {
    try {
        $ensured = orange_backup_provenance_ensure_cli_execution($backupRoot, 'all_recoverable_countries');
        $executionId = (string) ($ensured['execution_id'] ?? '');
        if ($executionId === '') {
            return ['ok' => false, 'warning' => 'provenance_execution_unavailable'];
        }
        $base = orange_backup_provenance_fields_for_package_write($backupRoot, $executionId);
        $base['backup_scope'] = 'all_recoverable_countries';
        $children = [];
        foreach ($succeeded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper((string) ($row['code'] ?? ''));
            $packagePath = (string) ($row['package_path'] ?? '');
            $packageId = basename($packagePath);
            $manifest = is_dir($packagePath)
                ? orange_backup_provenance_read_json_file($packagePath . DIRECTORY_SEPARATOR . 'manifest.json')
                : null;
            if ($packageId !== '' && preg_match(ORANGE_BACKUP_PROVENANCE_PACKAGE_ID_RE, $packageId)) {
                orange_backup_provenance_record_country_package($backupRoot, array_merge($base, [
                    'package_id' => $packageId,
                    'target_country_id' => (int) ($row['id'] ?? 0),
                    'target_country_code' => $code,
                    'completed_at_utc' => gmdate('c'),
                    'package_result_status' => 'success',
                    'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
                    'package_fingerprint' => orange_backup_provenance_package_identity_fingerprint(
                        $packagePath,
                        'country'
                    ),
                ]));
            }
            $children[] = [
                'target_country_id' => (int) ($row['id'] ?? 0),
                'target_country_code' => $code,
                'package_id' => $packageId !== '' ? $packageId : null,
                'status' => 'success',
            ];
        }
        foreach ($failed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $err = trim((string) ($row['error'] ?? 'export_failed'));
            if (strlen($err) > 240) {
                $err = substr($err, 0, 240);
            }
            $children[] = [
                'target_country_id' => (int) ($row['id'] ?? 0),
                'target_country_code' => strtoupper((string) ($row['code'] ?? '')),
                'package_id' => null,
                'status' => 'failed',
                'error_summary' => $err,
            ];
        }
        orange_backup_provenance_finish_execution($backupRoot, $executionId, [
            'overall_status' => $batchOk ? 'success' : 'failed',
            'completed_at_utc' => gmdate('c'),
            'children' => $children,
        ]);

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[orange backup provenance] after_country_batch: ' . $e->getMessage());

        return ['ok' => false, 'warning' => 'provenance_exception'];
    }
}

/**
 * Begin manual Admin execution and publish CLI context. Never blocks the backup.
 *
 * @param array<string, mixed> $admin
 * @return array{execution_id:?string,warning:?string}
 */
function orange_backup_provenance_begin_manual_admin_execution(
    string $backupRoot,
    array $admin,
    PDO $pdo,
    string $backupScope
): array {
    $actor = orange_backup_provenance_actor_from_admin($admin, $pdo);
    $executionId = orange_backup_provenance_new_execution_id();
    $fields = array_merge($actor, [
        'execution_id' => $executionId,
        'trigger_mode' => 'manual',
        'backup_scope' => $backupScope,
        'overall_status' => 'running',
        'started_at_utc' => gmdate('c'),
    ]);
    $begun = orange_backup_provenance_begin_execution($backupRoot, $fields);
    $warning = empty($begun['ok']) ? (string) ($begun['error'] ?? 'provenance_begin_failed') : null;
    if (!empty($begun['execution_id'])) {
        $executionId = (string) $begun['execution_id'];
    }
    orange_backup_provenance_publish_cli_context($executionId, $fields);

    return ['execution_id' => $executionId, 'warning' => $warning];
}
