<?php

declare(strict_types=1);

/**
 * Contained B1 feasibility: shutdown order / single JSON / auth gate.
 * PHP built-ins only. CLI only. Not a production route.
 *
 * Modes: authorized_fatal | pre_auth_fatal | completed_ok | authorized_fatal_with_ob
 *        | classify_timeout | classify_memory
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '0');

$GLOBALS['__order'] = [];
$GLOBALS['orange_backup_list_b1_authorized'] = false;
$GLOBALS['orange_backup_list_b1_completed'] = false;
$GLOBALS['orange_backup_list_b1_stage'] = 'route_entry';
$GLOBALS['orange_backup_list_b1_started_at'] = microtime(true);

// Simulate endpoint-local callback registered FIRST (before bootstrap/config).
register_shutdown_function(static function (): void {
    $GLOBALS['__order'][] = 'local_first';
    $err = error_get_last();
    $type = (int) ($err['type'] ?? 0);
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    $isFatal = $err !== null && in_array($type, $fatalTypes, true);
    $auth = !empty($GLOBALS['orange_backup_list_b1_authorized']);
    $done = !empty($GLOBALS['orange_backup_list_b1_completed']);
    $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)));
    $identityOk = str_ends_with($script, 'self_test_backup_center_list_endpoint_fatal_origin_b1_shutdown_fixture.php')
        || $script === basename(__FILE__);

    if ($identityOk && $auth && !$done && $isFatal) {
        $rawMsg = (string) ($err['message'] ?? '');
        $category = 'fatal_error';
        if (stripos($rawMsg, 'Maximum execution time') !== false
            || stripos($rawMsg, 'max_execution_time') !== false) {
            $category = 'php_timeout';
        } elseif (stripos($rawMsg, 'Allowed memory size') !== false
            || stripos($rawMsg, 'Out of memory') !== false
            || stripos($rawMsg, 'memory exhausted') !== false) {
            $category = 'memory_exhausted';
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        $stage = (string) ($GLOBALS['orange_backup_list_b1_stage'] ?? 'route_entry');
        $stage = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($stage));
        if ($stage === '') {
            $stage = 'route_entry';
        }
        $payload = [
            'success' => false,
            'code' => 'backup_list_endpoint_fatal_origin',
            'message' => 'تعذر تحميل بيانات مركز النسخ بسبب توقف PHP داخلي آمن',
            'diagnostic_probe' => 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1',
            'diagnostic_trace_id' => 'fixturetrace01',
            'diagnostic_failure_stage' => $stage,
            'diagnostic_shutdown_category' => $category,
            'diagnostic_php_error_type' => 'E_USER_ERROR',
            'diagnostic_origin_file' => basename((string) ($err['file'] ?? 'unknown')),
            'diagnostic_origin_line' => (int) ($err['line'] ?? 0),
            'diagnostic_elapsed_ms' => 1,
        ];
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        file_put_contents(
            sys_get_temp_dir() . '/orange_backup_list_b1_shutdown_order.txt',
            implode(',', $GLOBALS['__order'])
        );
        exit(0);
    }
    file_put_contents(
        sys_get_temp_dir() . '/orange_backup_list_b1_shutdown_order.txt',
        implode(',', $GLOBALS['__order']) . '|inert'
    );
});

// Simulate bootstrap + global boundary registered SECOND.
register_shutdown_function(static function (): void {
    $GLOBALS['__order'][] = 'global_second';
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    echo json_encode(['success' => false, 'code' => 'server_error', 'message' => 'generic_global'], JSON_UNESCAPED_UNICODE);
    file_put_contents(
        sys_get_temp_dir() . '/orange_backup_list_b1_shutdown_order.txt',
        implode(',', $GLOBALS['__order']) . '|GLOBAL_RAN'
    );
});

$mode = $argv[1] ?? 'authorized_fatal';

if ($mode === 'authorized_fatal') {
    $GLOBALS['orange_backup_list_b1_authorized'] = true;
    $GLOBALS['orange_backup_list_b1_completed'] = false;
    $GLOBALS['orange_backup_list_b1_stage'] = 'collect_storage_totals';
    trigger_error('Simulated authorized fatal for B1 proof', E_USER_ERROR);
} elseif ($mode === 'pre_auth_fatal') {
    $GLOBALS['orange_backup_list_b1_authorized'] = false;
    $GLOBALS['orange_backup_list_b1_completed'] = false;
    trigger_error('Simulated pre-auth fatal', E_USER_ERROR);
} elseif ($mode === 'completed_ok') {
    $GLOBALS['orange_backup_list_b1_authorized'] = true;
    $GLOBALS['orange_backup_list_b1_completed'] = true;
    echo json_encode(['success' => true, 'ok' => 1], JSON_UNESCAPED_UNICODE);
} elseif ($mode === 'authorized_fatal_with_ob') {
    $GLOBALS['orange_backup_list_b1_authorized'] = true;
    $GLOBALS['orange_backup_list_b1_completed'] = false;
    $GLOBALS['orange_backup_list_b1_stage'] = 'collect_storage_totals';
    ob_start();
    echo 'PARTIAL_LEAK';
    trigger_error('Simulated fatal with OB', E_USER_ERROR);
} elseif ($mode === 'classify_timeout') {
    $GLOBALS['orange_backup_list_b1_authorized'] = true;
    $GLOBALS['orange_backup_list_b1_completed'] = false;
    $GLOBALS['orange_backup_list_b1_stage'] = 'collect_storage_totals';
    trigger_error('Maximum execution time of 30 seconds exceeded', E_USER_ERROR);
} elseif ($mode === 'classify_memory') {
    $GLOBALS['orange_backup_list_b1_authorized'] = true;
    $GLOBALS['orange_backup_list_b1_completed'] = false;
    trigger_error('Allowed memory size of 134217728 bytes exhausted', E_USER_ERROR);
} else {
    fwrite(STDERR, "unknown mode\n");
    exit(2);
}
