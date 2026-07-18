<?php

declare(strict_types=1);

/**
 * Phase 3B.3B1 / 3B.4B — Maintenance framework (metadata + policy).
 *
 * 3B.4B wires production activation of this metadata only.
 * Does not import/wipe production DB, restore files, cutover, or rollback.
 * Does not wire every storefront/admin route — central decision helper only.
 */

require_once __DIR__ . '/restore_job_framework.php';

const ORANGE_RESTORE_MAINT_FW_VERSION = '3B.4B-maintenance-activation';
const ORANGE_RESTORE_MAINT_STATE_FILE = 'maintenance_state.json';
const ORANGE_RESTORE_MAINT_LOCK_FILE = '.maintenance_state.lock';
const ORANGE_RESTORE_MAINT_STALE_SECONDS = 21600;

const ORANGE_RESTORE_MAINT_STATE_INACTIVE = 'inactive';
const ORANGE_RESTORE_MAINT_STATE_REQUESTED = 'requested';
const ORANGE_RESTORE_MAINT_STATE_VALIDATING = 'validating';
const ORANGE_RESTORE_MAINT_STATE_ACTIVE = 'active';
const ORANGE_RESTORE_MAINT_STATE_RELEASING = 'releasing';
const ORANGE_RESTORE_MAINT_STATE_FAILED = 'failed';

/**
 * @return list<string>
 */
function orange_restore_maint_fw_allowed_states(): array
{
    return [
        ORANGE_RESTORE_MAINT_STATE_INACTIVE,
        ORANGE_RESTORE_MAINT_STATE_REQUESTED,
        ORANGE_RESTORE_MAINT_STATE_VALIDATING,
        ORANGE_RESTORE_MAINT_STATE_ACTIVE,
        ORANGE_RESTORE_MAINT_STATE_RELEASING,
        ORANGE_RESTORE_MAINT_STATE_FAILED,
    ];
}

function orange_restore_maint_fw_state_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_MAINT_STATE_FILE;
}

function orange_restore_maint_fw_lock_path(string $workRoot): string
{
    return orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_MAINT_LOCK_FILE;
}

/**
 * @return array{ok:bool,handle:?resource,message:string}
 */
function orange_restore_maint_fw_acquire_lock(string $workRoot): array
{
    $path = orange_restore_maint_fw_lock_path($workRoot);
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return ['ok' => false, 'handle' => null, 'message' => 'Cannot open maintenance state lock.'];
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        return ['ok' => false, 'handle' => null, 'message' => 'Cannot acquire maintenance state lock.'];
    }

    return ['ok' => true, 'handle' => $handle, 'message' => 'ok'];
}

/**
 * @param resource|null $handle
 */
function orange_restore_maint_fw_release_lock(mixed $handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_default_state(): array
{
    return [
        'version' => ORANGE_RESTORE_MAINT_FW_VERSION,
        'state' => ORANGE_RESTORE_MAINT_STATE_INACTIVE,
        'requested_by' => '',
        'requested_at' => '',
        'activated_by' => '',
        'activated_at' => '',
        'reason_code' => '',
        'related_job_id' => '',
        'heartbeat_at' => '',
        'stale_threshold_seconds' => ORANGE_RESTORE_MAINT_STALE_SECONDS,
        'safe_read_policy' => 'allow_restore_status_and_static',
        'blocked_write_scopes' => orange_restore_maint_fw_default_blocked_scopes(),
        'stale' => false,
        'auto_release_forbidden' => true,
        'production_activation_wired' => false,
        'restore_started' => false,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_maint_fw_default_blocked_scopes(): array
{
    return [
        'order_create',
        'order_status_mutation',
        'stock_mutation',
        'gl_write',
        'catalog_write',
        'admin_unrelated_mutation',
        'scheduled_business_write',
        'conflicting_backup_job',
        'application_write_api',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_read(string $workRoot): array
{
    $path = orange_restore_maint_fw_state_path($workRoot);
    if (!is_file($path)) {
        return orange_restore_maint_fw_default_state();
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        $state = orange_restore_maint_fw_default_state();
        $state['state'] = ORANGE_RESTORE_MAINT_STATE_FAILED;
        $state['reason_code'] = 'maintenance_state_corrupt';

        return $state;
    }
    $state = array_merge(orange_restore_maint_fw_default_state(), $decoded);
    $state['stale'] = orange_restore_maint_fw_is_stale($state);
    // Never auto-release active maintenance.
    return $state;
}

/**
 * @param array<string, mixed> $state
 */
function orange_restore_maint_fw_is_stale(array $state): bool
{
    if ((string) ($state['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
        return false;
    }
    $hb = strtotime((string) ($state['heartbeat_at'] ?? $state['activated_at'] ?? ''));
    if ($hb === false) {
        return true;
    }
    $threshold = (int) ($state['stale_threshold_seconds'] ?? ORANGE_RESTORE_MAINT_STALE_SECONDS);

    return (time() - $hb) > $threshold;
}

/**
 * @param array<string, mixed> $state
 */
function orange_restore_maint_fw_write(string $workRoot, array $state): void
{
    $path = orange_restore_maint_fw_state_path($workRoot);
    $state['version'] = ORANGE_RESTORE_MAINT_FW_VERSION;
    $state['stale'] = orange_restore_maint_fw_is_stale($state);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Cannot encode maintenance state.');
    }
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write maintenance state.');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_request(
    string $workRoot,
    string $requestedBy,
    string $relatedJobId,
    string $reasonCode = 'restore_execution_pending'
): array {
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if (in_array((string) $state['state'], [
            ORANGE_RESTORE_MAINT_STATE_ACTIVE,
            ORANGE_RESTORE_MAINT_STATE_RELEASING,
            ORANGE_RESTORE_MAINT_STATE_VALIDATING,
        ], true)) {
            throw new RuntimeException('maintenance_already_active_or_releasing');
        }
        $state['state'] = ORANGE_RESTORE_MAINT_STATE_REQUESTED;
        $state['requested_by'] = $requestedBy;
        $state['requested_at'] = gmdate('c');
        $state['related_job_id'] = $relatedJobId;
        $state['reason_code'] = $reasonCode;
        $state['production_activation_wired'] = false;
        $state['restore_started'] = false;
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * Mark validating (activation prechecks in progress). Never starts restore.
 *
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_mark_validating(
    string $workRoot,
    string $by,
    string $relatedJobId = ''
): array {
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if (!in_array((string) $state['state'], [
            ORANGE_RESTORE_MAINT_STATE_REQUESTED,
            ORANGE_RESTORE_MAINT_STATE_VALIDATING,
        ], true)) {
            throw new RuntimeException('maintenance_validate_invalid_state');
        }
        $state['state'] = ORANGE_RESTORE_MAINT_STATE_VALIDATING;
        $state['activated_by'] = $by;
        if ($relatedJobId !== '') {
            $state['related_job_id'] = $relatedJobId;
        }
        $state['reason_code'] = 'maintenance_validating';
        $state['production_activation_wired'] = false;
        $state['restore_started'] = false;
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * Framework-level activate after validation. Sets production_activation_wired=true.
 * Does not invoke restore workers, cutover, wipe, or import.
 *
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_activate(
    string $workRoot,
    string $activatedBy,
    string $relatedJobId = ''
): array {
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if (!in_array((string) $state['state'], [
            ORANGE_RESTORE_MAINT_STATE_REQUESTED,
            ORANGE_RESTORE_MAINT_STATE_VALIDATING,
            ORANGE_RESTORE_MAINT_STATE_INACTIVE,
        ], true)) {
            throw new RuntimeException('maintenance_activate_invalid_state');
        }
        $now = gmdate('c');
        $state['state'] = ORANGE_RESTORE_MAINT_STATE_ACTIVE;
        $state['activated_by'] = $activatedBy;
        $state['activated_at'] = $now;
        $state['heartbeat_at'] = $now;
        if ($relatedJobId !== '') {
            $state['related_job_id'] = $relatedJobId;
        }
        $state['production_activation_wired'] = true;
        $state['restore_started'] = false;
        $state['reason_code'] = 'production_maintenance_active';
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_heartbeat(string $workRoot): array
{
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if ((string) $state['state'] !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            throw new RuntimeException('maintenance_not_active');
        }
        $state['heartbeat_at'] = gmdate('c');
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_begin_release(string $workRoot, string $by): array
{
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if ((string) $state['state'] !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            throw new RuntimeException('maintenance_release_invalid_state');
        }
        $state['state'] = ORANGE_RESTORE_MAINT_STATE_RELEASING;
        $state['requested_by'] = $by;
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_release(string $workRoot, string $by): array
{
    $lock = orange_restore_maint_fw_acquire_lock($workRoot);
    if (!$lock['ok']) {
        throw new RuntimeException((string) $lock['message']);
    }
    try {
        $state = orange_restore_maint_fw_read($workRoot);
        if (!in_array((string) $state['state'], [ORANGE_RESTORE_MAINT_STATE_ACTIVE, ORANGE_RESTORE_MAINT_STATE_RELEASING, ORANGE_RESTORE_MAINT_STATE_REQUESTED], true)) {
            throw new RuntimeException('maintenance_release_invalid_state');
        }
        $state = orange_restore_maint_fw_default_state();
        $state['reason_code'] = 'released_by_' . $by;
        orange_restore_maint_fw_write($workRoot, $state);

        return orange_restore_maint_fw_public($state);
    } finally {
        if (is_resource($lock['handle'])) {
            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
    }
}

/**
 * Central policy classifier. Does not mutate production. Does not wire routes.
 *
 * @param array{
 *   method?:string,
 *   path?:string,
 *   scope?:string,
 *   is_cli?:bool,
 *   is_admin?:bool,
 *   bypass_token?:string,
 *   bypass_job_id?:string,
 *   is_static_asset?:bool,
 *   is_maintenance_page?:bool,
 *   is_restore_center_read?:bool,
 *   is_health_probe?:bool,
 *   is_payment_callback?:bool,
 *   payment_callback_allowlisted?:bool
 * } $request
 * @return array{allow:bool,action:string,reason_code:string,scope:string,http_status:int}
 */
function orange_restore_maint_fw_classify_request(string $workRoot, array $request): array
{
    $state = orange_restore_maint_fw_read($workRoot);
    $status = (string) ($state['state'] ?? ORANGE_RESTORE_MAINT_STATE_INACTIVE);
    $scope = (string) ($request['scope'] ?? 'unknown');

    if ($status !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
        return [
            'allow' => true,
            'action' => 'passthrough',
            'reason_code' => 'maintenance_inactive',
            'scope' => $scope,
            'http_status' => 200,
        ];
    }

    // No administrator bypass. No query/header/IP bypass — only scoped CLI token.
    if (!empty($request['is_admin']) && empty($request['is_cli']) && empty($request['is_restore_center_read'])) {
        // Admins still blocked for mutating business scopes; restore-center reads allowed below.
    }
    if (!empty($request['bypass_token']) || !empty($request['bypass_header']) || !empty($request['bypass_ip'])) {
        if (empty($request['is_cli']) || empty($request['bypass_token']) || empty($request['bypass_job_id'])) {
            return [
                'allow' => false,
                'action' => 'block',
                'reason_code' => 'maintenance_bypass_forbidden',
                'scope' => $scope,
                'http_status' => 503,
            ];
        }
        $tokenOk = orange_restore_maint_fw_validate_cli_bypass(
            $workRoot,
            (string) $request['bypass_token'],
            (string) $request['bypass_job_id'],
            $scope
        );
        if (!$tokenOk) {
            return [
                'allow' => false,
                'action' => 'block',
                'reason_code' => 'maintenance_bypass_invalid',
                'scope' => $scope,
                'http_status' => 503,
            ];
        }

        return [
            'allow' => true,
            'action' => 'cli_bypass',
            'reason_code' => 'maintenance_cli_bypass',
            'scope' => $scope,
            'http_status' => 200,
        ];
    }

    if (!empty($request['is_static_asset']) || !empty($request['is_maintenance_page'])) {
        return [
            'allow' => true,
            'action' => 'allow_read',
            'reason_code' => 'maintenance_safe_read',
            'scope' => $scope,
            'http_status' => 200,
        ];
    }
    if (!empty($request['is_restore_center_read']) || !empty($request['is_health_probe'])) {
        return [
            'allow' => true,
            'action' => 'allow_read',
            'reason_code' => 'maintenance_safe_read',
            'scope' => $scope,
            'http_status' => 200,
        ];
    }
    if (!empty($request['is_payment_callback'])) {
        if (!empty($request['payment_callback_allowlisted'])) {
            return [
                'allow' => true,
                'action' => 'allow_payment_callback',
                'reason_code' => 'maintenance_payment_allowlisted',
                'scope' => $scope,
                'http_status' => 200,
            ];
        }

        return [
            'allow' => false,
            'action' => 'block',
            'reason_code' => 'maintenance_payment_blocked',
            'scope' => $scope,
            'http_status' => 503,
        ];
    }

    $blocked = is_array($state['blocked_write_scopes'] ?? null)
        ? array_values(array_map('strval', $state['blocked_write_scopes']))
        : orange_restore_maint_fw_default_blocked_scopes();
    if (in_array($scope, $blocked, true)) {
        return [
            'allow' => false,
            'action' => 'block',
            'reason_code' => 'maintenance_write_blocked',
            'scope' => $scope,
            'http_status' => 503,
        ];
    }

    $method = strtoupper((string) ($request['method'] ?? 'GET'));
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return [
            'allow' => false,
            'action' => 'block',
            'reason_code' => 'maintenance_write_blocked',
            'scope' => $scope !== '' ? $scope : 'application_write_api',
            'http_status' => 503,
        ];
    }

    return [
        'allow' => true,
        'action' => 'allow_read',
        'reason_code' => 'maintenance_safe_read',
        'scope' => $scope,
        'http_status' => 200,
    ];
}

/**
 * Production middleware decision helper (single policy source).
 * Callers must wire via restore_maintenance_enforcement.php (3B.4H / P0-1).
 *
 * @param array<string, mixed> $request
 * @return array{allow:bool,action:string,reason_code:string,scope:string,http_status:int,maintenance_active:bool,stale:bool}
 */
function orange_restore_production_maintenance_decide(string $workRoot, array $request): array
{
    $state = orange_restore_maint_fw_read($workRoot);
    $decision = orange_restore_maint_fw_classify_request($workRoot, $request);

    return $decision + [
        'maintenance_active' => (string) ($state['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE,
        'stale' => (bool) ($state['stale'] ?? false),
        'auto_release_forbidden' => true,
        'restore_started' => false,
    ];
}

/**
 * Scoped CLI bypass token stored under framework metadata (never query/header broad bypass).
 */
function orange_restore_maint_fw_issue_cli_bypass(
    string $workRoot,
    string $jobId,
    string $operation,
    int $ttlSeconds = 300
): string {
    $plaintext = bin2hex(random_bytes(24));
    $path = orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . '.maintenance_cli_bypass.json';
    $payload = [
        'token_hash' => hash('sha256', $plaintext),
        'job_id' => $jobId,
        'operation' => $operation,
        'expires_at' => gmdate('c', time() + max(30, $ttlSeconds)),
        'created_at' => gmdate('c'),
    ];
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    return $plaintext;
}

function orange_restore_maint_fw_validate_cli_bypass(
    string $workRoot,
    string $token,
    string $jobId,
    string $operation
): bool {
    $path = orange_restore_fw_root($workRoot) . DIRECTORY_SEPARATOR . '.maintenance_cli_bypass.json';
    if (!is_file($path) || $token === '' || $jobId === '') {
        return false;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }
    $expires = strtotime((string) ($decoded['expires_at'] ?? ''));
    if ($expires === false || time() > $expires) {
        return false;
    }
    if (!hash_equals((string) ($decoded['job_id'] ?? ''), $jobId)) {
        return false;
    }
    $allowedOp = (string) ($decoded['operation'] ?? '');
    if ($allowedOp !== '' && $operation !== '' && !hash_equals($allowedOp, $operation) && $operation !== 'unknown') {
        return false;
    }

    return hash_equals((string) ($decoded['token_hash'] ?? ''), hash('sha256', $token));
}

/**
 * Wired integration inventory (P0-1). Kept for operator visibility; not "future".
 *
 * @return list<string>
 */
function orange_restore_maint_fw_wired_integration_points(): array
{
    return [
        'config.php require_admin_api/page → orange_restore_maint_enforcement_http_guard',
        'api/orders/create-order.php',
        'api/orders/amend-order-items.php',
        'api/orders/cancel-by-customer.php',
        'api/payments/* mutation guards',
        'api/auth/* mutation guards',
        'includes/order_intake_queue.php',
        'scripts/process_order_intake_queue.php',
        'includes/backup/backup_runner.php',
        'approved restore CLI workers (scoped bypass)',
    ];
}

/**
 * @deprecated Use orange_restore_maint_fw_wired_integration_points()
 * @return list<string>
 */
function orange_restore_maint_fw_future_integration_points(): array
{
    return orange_restore_maint_fw_wired_integration_points();
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function orange_restore_maint_fw_public(array $state): array
{
    $fwState = (string) ($state['state'] ?? ORANGE_RESTORE_MAINT_STATE_INACTIVE);
    $active = $fwState === ORANGE_RESTORE_MAINT_STATE_ACTIVE;
    $ready = in_array($fwState, [
        ORANGE_RESTORE_MAINT_STATE_REQUESTED,
        ORANGE_RESTORE_MAINT_STATE_VALIDATING,
    ], true);

    return [
        'version' => (string) ($state['version'] ?? ORANGE_RESTORE_MAINT_FW_VERSION),
        'state' => $fwState,
        'label' => $active ? 'Maintenance Active' : ($ready ? 'Maintenance Ready' : $fwState),
        'maintenance_ready' => $ready,
        'maintenance_active' => $active,
        'requested_by' => (string) ($state['requested_by'] ?? ''),
        'requested_at' => (string) ($state['requested_at'] ?? ''),
        'activated_by' => (string) ($state['activated_by'] ?? ''),
        'activated_at' => (string) ($state['activated_at'] ?? ''),
        'reason_code' => (string) ($state['reason_code'] ?? ''),
        'related_job_id' => (string) ($state['related_job_id'] ?? ''),
        'heartbeat_at' => (string) ($state['heartbeat_at'] ?? ''),
        'stale' => (bool) ($state['stale'] ?? false),
        'stale_threshold_seconds' => (int) ($state['stale_threshold_seconds'] ?? ORANGE_RESTORE_MAINT_STALE_SECONDS),
        'safe_read_policy' => (string) ($state['safe_read_policy'] ?? ''),
        'blocked_write_scopes' => is_array($state['blocked_write_scopes'] ?? null)
            ? array_values($state['blocked_write_scopes'])
            : orange_restore_maint_fw_default_blocked_scopes(),
        'auto_release_forbidden' => true,
        'production_activation_wired' => (bool) ($state['production_activation_wired'] ?? false),
        'restore_started' => false,
        'execution_started' => false,
        'warning' => 'Production restore has NOT started.',
        'wired_integration_points' => orange_restore_maint_fw_wired_integration_points(),
        // Backward-compatible alias (no longer means unwired).
        'future_integration_points' => orange_restore_maint_fw_wired_integration_points(),
    ];
}
