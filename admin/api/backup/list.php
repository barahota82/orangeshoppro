<?php

declare(strict_types=1);

/**
 * Backup Center package list (read-only startup payload).
 *
 * B1 — endpoint-local fatal/timeout origin capture (PLAN 3 only).
 * Marker: BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1
 * Registers BEFORE bootstrap/config so this callback runs first on shutdown.
 * Auth-gated; inert until permission succeeds; no shared-helper / UI / Restore change.
 */

$GLOBALS['orange_backup_list_b1_authorized'] = false;
$GLOBALS['orange_backup_list_b1_completed'] = false;
$GLOBALS['orange_backup_list_b1_stage'] = 'route_entry';
$GLOBALS['orange_backup_list_b1_started_at'] = microtime(true);

if (!function_exists('orange_backup_list_b1_set_stage')) {
    function orange_backup_list_b1_set_stage(string $stage): void
    {
        $safe = (string) preg_replace('/[^a-z0-9_]/', '', strtolower(trim($stage)));
        $GLOBALS['orange_backup_list_b1_stage'] = $safe !== '' ? $safe : 'route_entry';
    }
}

if (!function_exists('orange_backup_list_b1_shutdown')) {
    /**
     * Runs first among shutdown callbacks registered from this request (before config.php global
     * and backup _bootstrap JSON guard). Intercepts only post-auth unfinished fatals for this
     * exact script; otherwise inert.
     */
    function orange_backup_list_b1_shutdown(): void
    {
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $isList = str_ends_with($scriptPath, '/admin/api/backup/list.php')
            || str_ends_with($scriptName, '/admin/api/backup/list.php')
            || (
                basename($scriptPath !== '' ? $scriptPath : $scriptName) === 'list.php'
                && (str_contains($scriptPath, '/admin/api/backup/') || str_contains($scriptName, '/admin/api/backup/'))
            );
        if (!$isList) {
            return;
        }
        if (empty($GLOBALS['orange_backup_list_b1_authorized'])
            || !empty($GLOBALS['orange_backup_list_b1_completed'])) {
            return;
        }

        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];
        $type = (int) ($err['type'] ?? 0);
        if (!in_array($type, $fatalTypes, true)) {
            return;
        }

        $rawMsg = (string) ($err['message'] ?? '');
        $category = 'fatal_error';
        if (stripos($rawMsg, 'Maximum execution time') !== false
            || stripos($rawMsg, 'max_execution_time') !== false) {
            $category = 'php_timeout';
        } elseif (stripos($rawMsg, 'Allowed memory size') !== false
            || stripos($rawMsg, 'Out of memory') !== false
            || stripos($rawMsg, 'memory exhausted') !== false) {
            $category = 'memory_exhausted';
        } elseif ($type === E_PARSE) {
            $category = 'parse_error';
        } elseif ($type === E_CORE_ERROR) {
            $category = 'core_error';
        } elseif ($type === E_COMPILE_ERROR) {
            $category = 'compile_error';
        } elseif ($type === E_USER_ERROR) {
            $category = 'user_fatal_error';
        } elseif ($type === E_RECOVERABLE_ERROR) {
            $category = 'recoverable_fatal_error';
        } elseif ($type === E_ERROR) {
            $category = 'fatal_error';
        } else {
            $category = 'unknown_fatal_error';
        }

        $typeMap = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        ];
        $typeName = $typeMap[$type] ?? 'E_ERROR';

        $originFile = basename(str_replace('\\', '/', (string) ($err['file'] ?? '')));
        if ($originFile === '' || $originFile === '.' || $originFile === '..') {
            $originFile = 'unknown';
        }
        $safeOriginFile = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $originFile);
        if ($safeOriginFile === '') {
            $safeOriginFile = 'unknown';
        }
        $originLine = (int) ($err['line'] ?? 0);
        if ($originLine < 0) {
            $originLine = 0;
        }

        $t0 = (float) ($GLOBALS['orange_backup_list_b1_started_at'] ?? microtime(true));
        $elapsedMs = (int) max(0, (int) round((microtime(true) - $t0) * 1000));

        $stage = (string) ($GLOBALS['orange_backup_list_b1_stage'] ?? 'route_entry');
        $stage = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($stage));
        if ($stage === '') {
            $stage = 'route_entry';
        }

        $probe = 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1';
        try {
            $traceId = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $traceId = substr(str_replace('.', '', uniqid('', true)), 0, 16);
        }

        $safeMsg = 'تعذر تحميل بيانات مركز النسخ بسبب توقف PHP داخلي آمن'
            . ' [probe: ' . $probe . ']'
            . ' [trace: ' . $traceId . ']'
            . ' [stage: ' . $stage . ']'
            . ' [category: ' . $category . ']'
            . ' [type: ' . $typeName . ']'
            . ' [origin: ' . $safeOriginFile . ':' . $originLine . ']'
            . ' [elapsed_ms: ' . $elapsedMs . ']';

        $payload = [
            'success' => false,
            'code' => 'backup_list_endpoint_fatal_origin',
            'message' => $safeMsg,
            'diagnostic_probe' => $probe,
            'diagnostic_trace_id' => $traceId,
            'diagnostic_failure_stage' => $stage,
            'diagnostic_shutdown_category' => $category,
            'diagnostic_php_error_type' => $typeName,
            'diagnostic_origin_file' => $safeOriginFile,
            'diagnostic_origin_line' => $originLine,
            'diagnostic_elapsed_ms' => $elapsedMs,
        ];

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!defined('ORANGE_ERROR_BOUNDARY_CLIENT_SENT')) {
            define('ORANGE_ERROR_BOUNDARY_CLIENT_SENT', true);
        }
        if (!defined('ORANGE_JSON_RESPONSE_EMITTED')) {
            define('ORANGE_JSON_RESPONSE_EMITTED', true);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($payload, $flags);
        // Suppress later global / backup JSON shutdown handlers.
        exit;
    }
}

register_shutdown_function('orange_backup_list_b1_shutdown');
if (ob_get_level() === 0) {
    ob_start();
}

orange_backup_list_b1_set_stage('bootstrap_complete');
require_once __DIR__ . '/_bootstrap.php';

orange_backup_list_b1_set_stage('authentication_complete');
backup_admin_api_require_get();

try {
    $admin = backup_admin_api_admin();
    orange_backup_list_b1_set_stage('permission_complete');
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);
    // Auth + Backup view permission succeeded — enable B1 fatal capture only after this point.
    $GLOBALS['orange_backup_list_b1_authorized'] = true;

    orange_backup_list_b1_set_stage('pdo_schema_context');
    $projectRoot = backup_admin_api_project_root();
    orange_backup_list_b1_set_stage('backup_view_context');
    $ctx = orange_backup_admin_context_for_view($projectRoot);
    $rootHealth = $ctx['root_health'];
    $backupRoot = $ctx['backup_root'];

    require_once dirname(__DIR__, 3) . '/includes/countries.php';
    // Country Backup views follow Admin Country Context; Full Backup stays global.
    $countryContextCode = orange_admin_context_country_code($pdo);

    // Single-pass package loads shared with overview (avoids duplicate JSON/FS inspections).
    // Full: shared snapshots/ — never filtered by Country Context; every recognized finalized package
    // (no silent row cap — Backup Center Last 5 remains a client-side slice of this payload).
    // Country: uncapped package ids for the selected context country only (no cross-country leakage).
    orange_backup_list_b1_set_stage('list_full_snapshots');
    $fullSnapshots = orange_backup_admin_list_full_snapshots($backupRoot);
    orange_backup_list_b1_set_stage('list_country_packages');
    $countryPackages = orange_backup_admin_list_country_packages($pdo, $backupRoot, null, $countryContextCode);
    orange_backup_list_b1_set_stage('package_inventory_counts_first');
    $inventoryScoped = orange_backup_admin_package_inventory_counts($backupRoot, $countryContextCode);
    // Storage KPIs remain shared BackupRoot totals (global disk), not context-scoped.
    orange_backup_list_b1_set_stage('package_inventory_counts_second');
    $inventoryGlobal = orange_backup_admin_package_inventory_counts($backupRoot, null);
    orange_backup_list_b1_set_stage('collect_storage_totals');
    $storage = orange_backup_admin_collect_storage_totals($backupRoot, $inventoryGlobal);

    orange_backup_list_b1_set_stage('collect_overview');
    $overview = orange_backup_admin_collect_overview($pdo, $projectRoot, $ctx, [
        'full_snapshots' => $fullSnapshots,
        'country_packages' => $countryPackages,
        'inventory' => $inventoryScoped,
        'storage' => $storage,
    ]);
    $overview['country_context_code'] = orange_countries_display_code($countryContextCode);
    $manualAvailable = !empty($rootHealth['manual_actions_available']);

    orange_backup_list_b1_set_stage('list_logs');
    $logs = orange_backup_admin_list_logs($backupRoot, 40);

    orange_backup_list_b1_set_stage('response_aggregation');
    $payload = [
        'success' => true,
        'backup_root_health' => $rootHealth,
        'permissions' => [
            'can_view' => orange_backup_admin_may_view($admin, $pdo),
            'can_run' => orange_backup_admin_may_run($admin, $pdo) && $manualAvailable,
            'can_verify' => orange_backup_admin_may_verify($admin, $pdo),
            'manual_actions_available' => $manualAvailable,
            'verify_is_read_only' => true,
            'recovery_check_requires_write' => true,
        ],
        'csrf_token' => orange_backup_admin_csrf_token(),
        'country_context_code' => orange_countries_display_code($countryContextCode),
        'overview' => $overview,
        'full_snapshots' => $fullSnapshots,
        'country_packages' => $countryPackages,
        'logs' => $logs,
    ];

    orange_backup_list_b1_set_stage('response_serialization');
    // Intentional response complete — keep B1 shutdown inert.
    $GLOBALS['orange_backup_list_b1_completed'] = true;
    orange_backup_list_b1_set_stage('response_complete');
    json_response($payload);
} catch (Throwable $e) {
    $GLOBALS['orange_backup_list_b1_completed'] = true;
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
