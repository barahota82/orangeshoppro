<?php

declare(strict_types=1);

/**
 * Phase 3B.4H / P0-1 — Production maintenance enforcement wiring.
 *
 * Single policy source: orange_restore_production_maintenance_decide().
 * This file only builds request context, emits stable blocks, and provides
 * CLI restore-worker bypass helpers. It does not duplicate classification rules.
 */

require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_paths.php';

const ORANGE_RESTORE_MAINT_ENFORCEMENT_VERSION = '3B.4H-v1';
const ORANGE_RESTORE_MAINT_ENFORCEMENT_CODE = 'maintenance_write_blocked';

/**
 * Resolve project root (web or CLI).
 */
function orange_restore_maint_enforcement_project_root(): string
{
    if (defined('ORANGE_PROJECT_ROOT') && is_string(ORANGE_PROJECT_ROOT) && ORANGE_PROJECT_ROOT !== '') {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ORANGE_PROJECT_ROOT), DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 3);
}

/**
 * Best-effort work root. Empty string means enforcement cannot run (fail open only when inactive file missing).
 */
function orange_restore_maint_enforcement_work_root(?string $projectRoot = null): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $projectRoot = $projectRoot ?? orange_restore_maint_enforcement_project_root();
    try {
        $env = orange_backup_load_env_array($projectRoot);
        if (!orange_backup_root_configured($env) && trim((string) ($env['ORANGE_RESTORE_WORK_DIR'] ?? '')) === '') {
            // No backup/restore roots configured → treat as inactive (dev hosts without restore).
            $cached = '';

            return $cached;
        }
        $cached = orange_restore_resolve_work_root($env);
    } catch (Throwable) {
        $cached = '';
    }

    return $cached;
}

/**
 * Fast path: true only when framework state file says active (no heavy requires).
 */
function orange_restore_maint_enforcement_is_active_fast(?string $workRoot = null): bool
{
    $workRoot = $workRoot ?? orange_restore_maint_enforcement_work_root();
    if ($workRoot === '') {
        return false;
    }
    $path = rtrim($workRoot, DIRECTORY_SEPARATOR . '/\\')
        . DIRECTORY_SEPARATOR . 'framework'
        . DIRECTORY_SEPARATOR . 'maintenance_state.json';
    if (!is_file($path)) {
        return false;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }

    return strtolower(trim((string) ($decoded['state'] ?? ''))) === 'active';
}

/**
 * @return array{script:string,uri:string,method:string}
 */
function orange_restore_maint_enforcement_http_identity(): array
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
    $uri = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? $script));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return ['script' => $script, 'uri' => $uri, 'method' => $method];
}

/**
 * Map HTTP path to a blocked scope name for decide().
 */
function orange_restore_maint_enforcement_scope_from_path(string $script, string $uri): string
{
    $hay = strtolower($script . ' ' . $uri);
    if (str_contains($hay, '/api/orders/') || str_contains($hay, '/admin/api/orders/')) {
        if (str_contains($hay, 'create') || str_contains($hay, 'amend') || str_contains($hay, 'cancel')) {
            return 'order_create';
        }

        return 'order_status_mutation';
    }
    if (str_contains($hay, '/stock/') || str_contains($hay, 'inventory') || str_contains($hay, 'fifo')
        || str_contains($hay, 'opening-stock') || str_contains($hay, 'warehouse')) {
        return 'stock_mutation';
    }
    if (str_contains($hay, '/journal') || str_contains($hay, '/accounts') || str_contains($hay, '/gl')
        || str_contains($hay, 'fiscal') || str_contains($hay, 'opening_balances')
        || str_contains($hay, 'bank-reconciliation') || str_contains($hay, 'sales_returns')
        || str_contains($hay, 'partners/')) {
        return 'gl_write';
    }
    if (str_contains($hay, '/products') || str_contains($hay, '/catalog') || str_contains($hay, '/departments')
        || str_contains($hay, '/colors') || str_contains($hay, '/offers') || str_contains($hay, 'promotions')
        || str_contains($hay, 'channels') || str_contains($hay, 'advisory')) {
        return 'catalog_write';
    }
    if (str_contains($hay, '/admin/api/admins') || str_contains($hay, '/admin/api/countries')
        || str_contains($hay, 'settings') || str_contains($hay, 'country-copy')
        || str_contains($hay, 'country-screen-copy')) {
        return 'admin_unrelated_mutation';
    }
    if (str_contains($hay, '/api/payments/') || str_contains($hay, '/admin/api/payments/')) {
        return 'application_write_api';
    }
    if (str_contains($hay, 'upload') || str_contains($hay, 'import')) {
        return 'application_write_api';
    }

    return 'application_write_api';
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_restore_maint_enforcement_build_http_request(array $extra = []): array
{
    $id = orange_restore_maint_enforcement_http_identity();
    $script = $id['script'];
    $uri = $id['uri'];
    $method = $id['method'];
    $hay = strtolower($script . ' ' . $uri);

    $isRestoreApi = str_contains($hay, '/admin/api/restore/');
    $isRestorePage = str_contains($hay, 'restore_center') || str_contains($hay, 'page=restore_center');
    $isBackupPage = str_contains($hay, 'backup_center') || str_contains($hay, 'page=backup_center');
    $isHealth = str_contains($hay, '/health.php') || str_contains($hay, 'health.php');
    $isLogin = str_contains($hay, '/admin/login.php');
    $isStatic = (bool) preg_match('/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map)(\?|$)/i', $uri);
    $isMaintPage = str_contains($hay, 'maintenance') && (str_contains($hay, '/pages/') || str_contains($hay, 'page='));
    $isPaymentCb = str_contains($hay, 'gateway-webhook') || str_contains($hay, 'payment') && str_contains($hay, 'webhook');
    $isCert = str_contains($hay, '/admin/api/restore/certification.php');

    $req = [
        'scope' => orange_restore_maint_enforcement_scope_from_path($script, $uri),
        'method' => $method,
        'is_admin' => str_contains($hay, '/admin/'),
        'is_cli' => false,
        'is_static_asset' => $isStatic,
        'is_maintenance_page' => $isMaintPage || $isLogin,
        'is_restore_center_read' => ($isRestoreApi || $isRestorePage || $isCert) && $method === 'GET',
        'is_restore_control_plane' => $isRestoreApi,
        'is_health_probe' => $isHealth,
        'is_payment_callback' => $isPaymentCb,
        // Owner has not allowlisted payment callbacks during restore (TBD). Default: block.
        'payment_callback_allowlisted' => false,
        'is_backup_center_read' => $isBackupPage && $method === 'GET',
    ];

    return array_merge($req, $extra);
}

/**
 * Emit stable maintenance block and exit.
 *
 * @param array<string, mixed> $decision
 */
function orange_restore_maint_enforcement_emit_block(array $decision): void
{
    $code = (string) ($decision['reason_code'] ?? ORANGE_RESTORE_MAINT_ENFORCEMENT_CODE);
    $status = (int) ($decision['http_status'] ?? 503);
    if ($status < 400) {
        $status = 503;
    }
    $messageAr = 'المتجر والإدارة في وضع صيانة الاسترداد. العمليات الكتابية متوقفة مؤقتاً.';
    $payload = [
        'success' => false,
        'code' => $code !== '' ? $code : ORANGE_RESTORE_MAINT_ENFORCEMENT_CODE,
        'message' => $messageAr,
        'maintenance' => true,
        'maintenance_active' => true,
        'scope' => (string) ($decision['scope'] ?? ''),
        'action' => (string) ($decision['action'] ?? 'block'),
    ];

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ERROR: ' . $payload['code'] . ' — ' . $messageAr . PHP_EOL);
        exit(75); // EX_TEMPFAIL-style
    }

    if (!headers_sent()) {
        http_response_code($status);
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $wantsJson = str_contains($accept, 'application/json')
        || str_contains($script, '/api/')
        || str_contains($script, '/admin/api/')
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if ($wantsJson || function_exists('json_response')) {
        if (function_exists('json_response')) {
            json_response($payload, $status);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>صيانة</title></head><body>';
    echo '<h1>صيانة مؤقتة</h1><p>' . htmlspecialchars($messageAr, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><code>' . htmlspecialchars($payload['code'], ENT_QUOTES, 'UTF-8') . '</code></p>';
    echo '</body></html>';
    exit;
}

/**
 * Core guard: no-op when maintenance inactive; otherwise decide + maybe block.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed> decision (allow=true when passthrough)
 */
function orange_restore_maint_enforcement_guard(array $request): array
{
    $workRoot = orange_restore_maint_enforcement_work_root();
    if ($workRoot === '' || !orange_restore_maint_enforcement_is_active_fast($workRoot)) {
        return [
            'allow' => true,
            'action' => 'passthrough',
            'reason_code' => 'maintenance_inactive',
            'scope' => (string) ($request['scope'] ?? ''),
            'http_status' => 200,
            'maintenance_active' => false,
        ];
    }

    require_once __DIR__ . '/restore_maintenance_framework.php';

    // Extend classify allowlist for restore control-plane without duplicating write rules.
    if (!empty($request['is_restore_control_plane'])) {
        $request['is_restore_center_read'] = true;
    }
    if (!empty($request['is_backup_center_read'])) {
        $request['is_restore_center_read'] = true;
    }

    $decision = orange_restore_production_maintenance_decide($workRoot, $request);
    if (empty($decision['allow'])) {
        orange_restore_maint_enforcement_emit_block($decision);
    }

    return $decision;
}

/**
 * HTTP auto-guard for admin/storefront entrypoints.
 *
 * @param array<string, mixed> $extra
 */
function orange_restore_maint_enforcement_http_guard(array $extra = []): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    orange_restore_maint_enforcement_guard(
        orange_restore_maint_enforcement_build_http_request($extra)
    );
}

/**
 * Storefront / public API mutation guard (explicit scope optional).
 *
 * @param array<string, mixed> $extra
 */
function orange_restore_maint_enforcement_api_mutation_guard(string $scope = 'application_write_api', array $extra = []): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $req = orange_restore_maint_enforcement_build_http_request(array_merge([
        'scope' => $scope,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')),
    ], $extra));
    orange_restore_maint_enforcement_guard($req);
}

/**
 * Cron / worker guard for business mutations (generic CLI — no bypass).
 *
 * @param array<string, mixed> $extra
 */
function orange_restore_maint_enforcement_cron_guard(string $scope = 'scheduled_business_write', array $extra = []): void
{
    orange_restore_maint_enforcement_library_mutation_guard($scope, array_merge([
        'is_cli' => true,
        'is_admin' => false,
    ], $extra));
}

/**
 * Library / shared mutation guard (queue drain, backup runner, etc.).
 * Generic callers without scoped restore bypass are blocked when active.
 *
 * @param array<string, mixed> $extra
 */
function orange_restore_maint_enforcement_library_mutation_guard(string $scope, array $extra = []): void
{
    $workRoot = orange_restore_maint_enforcement_work_root();
    if ($workRoot === '' || !orange_restore_maint_enforcement_is_active_fast($workRoot)) {
        return;
    }
    $req = array_merge([
        'scope' => $scope,
        'method' => 'POST',
        'is_cli' => PHP_SAPI === 'cli',
        'is_admin' => false,
    ], $extra);
    orange_restore_maint_enforcement_guard($req);
}

/**
 * Approved restore CLI worker: issue scoped bypass for this job+operation, then decide.
 *
 * @return array<string, mixed>
 */
function orange_restore_maint_enforcement_cli_restore_worker(
    string $workRoot,
    string $jobId,
    string $operation
): array {
    if ($workRoot === '' || $jobId === '') {
        throw new RuntimeException('maintenance_cli_context_invalid');
    }
    if (!orange_restore_maint_enforcement_is_active_fast($workRoot)) {
        return [
            'allow' => true,
            'action' => 'passthrough',
            'reason_code' => 'maintenance_inactive',
            'maintenance_active' => false,
        ];
    }
    require_once __DIR__ . '/restore_maintenance_framework.php';
    $token = orange_restore_maint_fw_issue_cli_bypass($workRoot, $jobId, $operation, 900);

    return orange_restore_maint_enforcement_guard([
        'scope' => $operation,
        'method' => 'POST',
        'is_cli' => true,
        'bypass_token' => $token,
        'bypass_job_id' => $jobId,
    ]);
}

/**
 * Payment callback policy note for auditors/tests (owner has not allowlisted).
 *
 * @return array{allowlisted:bool,policy:string}
 */
function orange_restore_maint_enforcement_payment_callback_policy(): array
{
    return [
        'allowlisted' => false,
        'policy' => 'Payment webhooks/callbacks are blocked while maintenance_active=true until the owner explicitly allowlists them in archive policy. Default: block (payment_callback_allowlisted=false).',
    ];
}
