<?php

declare(strict_types=1);

/**
 * HTTP-equivalent maintenance enforcement smoke (no live web server).
 * Proves decide()+enforcement behavior for storefront/admin write scopes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_maintenance_enforcement.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_maintenance_framework.php';

$passes = 0;
$failures = 0;
function mh_test(bool $c, string $l): void
{
    global $passes, $failures;
    if ($c) {
        echo "PASS: {$l}\n";
        $passes++;
    } else {
        echo "FAIL: {$l}\n";
        $failures++;
    }
}

echo "=== self_test_maintenance_http_smoke ===\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_maint_http_smoke_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
@mkdir($workRoot, 0775, true);

// Inactive: writes allowed.
$inactive = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
    'is_admin' => false,
    'is_cli' => false,
]);
mh_test(!empty($inactive['allow']), 'inactive: order_create allowed');

// Activate framework maintenance for a synthetic job.
$jobId = 'smoke_job_1';
orange_restore_maint_fw_request($workRoot, 'smoke_operator', $jobId, 'certification_http_smoke');
$act = orange_restore_maint_fw_activate($workRoot, 'smoke_operator', $jobId);
mh_test(($act['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'maintenance activated');

$blockedOrder = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
    'is_admin' => false,
    'is_cli' => false,
    'script_name' => '/api/orders/create-order.php',
    'request_uri' => '/api/orders/create-order.php',
]);
mh_test(empty($blockedOrder['allow']), 'active: order_create blocked');

$blockedAdmin = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'admin_write',
    'method' => 'POST',
    'is_admin' => true,
    'is_cli' => false,
    'script_name' => '/admin/api/products/update.php',
    'request_uri' => '/admin/api/products/update.php',
]);
mh_test(empty($blockedAdmin['allow']), 'active: admin write blocked');

$readOk = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'storefront_read',
    'method' => 'GET',
    'is_admin' => false,
    'is_cli' => false,
    'is_restore_center_read' => false,
    'script_name' => '/api/products/get-product.php',
    'request_uri' => '/api/products/get-product.php',
]);
mh_test(!empty($readOk['allow']), 'active: safe read still allowed (or policy-documented)');

// Restore control-plane reads should remain available for operators.
$restoreRead = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'restore_control_plane',
    'method' => 'GET',
    'is_admin' => true,
    'is_cli' => false,
    'is_restore_control_plane' => true,
    'is_restore_center_read' => true,
    'script_name' => '/admin/api/restore/job/list.php',
    'request_uri' => '/admin/api/restore/job/list.php',
]);
mh_test(!empty($restoreRead['allow']), 'active: restore center read allowed');

// Wiring evidence in config + key mutation APIs.
$cfg = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'config.php');
mh_test(str_contains($cfg, 'orange_restore_maint_enforcement_http_guard'), 'config.php wires admin HTTP guard');
$createOrder = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'orders'
    . DIRECTORY_SEPARATOR . 'create-order.php'
);
mh_test(str_contains($createOrder, 'orange_restore_maint_enforcement_api_mutation_guard'), 'create-order wired');

// Cleanup best-effort.
try {
    orange_restore_maint_fw_release($workRoot, 'smoke_operator');
} catch (Throwable $e) {
    // ignore
}

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
