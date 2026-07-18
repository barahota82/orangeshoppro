<?php

declare(strict_types=1);

/**
 * Phase 3B.4H / P0-1 — Production maintenance enforcement self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_maintenance_enforcement.php
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
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job_framework.php';

$failures = 0;
$passes = 0;

function me_self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function me_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            me_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

echo "=== self_test_maintenance_enforcement (3B.4H / P0-1) ===\n";

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_maint_enf_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($workRoot, 0775, true);
orange_restore_fw_root($workRoot); // creates framework dir
$jobId = '2026-07-18_enf_' . bin2hex(random_bytes(3));

// Manually activate maintenance state for decide() tests.
orange_restore_maint_fw_write($workRoot, array_merge(orange_restore_maint_fw_default_state(), [
    'state' => ORANGE_RESTORE_MAINT_STATE_ACTIVE,
    'related_job_id' => $jobId,
    'activated_at' => gmdate('c'),
    'heartbeat_at' => gmdate('c'),
    'production_activation_wired' => true,
]));

me_self_test(orange_restore_maint_enforcement_is_active_fast($workRoot) === true, 'fast_path_detects_active');

$blockOrders = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
]);
me_self_test(($blockOrders['allow'] ?? true) === false, 'orders blocked');
me_self_test(($blockOrders['http_status'] ?? 0) === 503, 'orders blocked 503');

$blockStock = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'stock_mutation',
    'method' => 'POST',
]);
me_self_test(($blockStock['allow'] ?? true) === false, 'stock blocked');

$blockFifo = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'scheduled_business_write',
    'method' => 'POST',
    'is_cli' => true,
]);
me_self_test(($blockFifo['allow'] ?? true) === false, 'FIFO/cron writes blocked');

$blockGl = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'gl_write',
    'method' => 'POST',
    'is_admin' => true,
]);
me_self_test(($blockGl['allow'] ?? true) === false, 'GL blocked');

$blockSettings = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'admin_unrelated_mutation',
    'method' => 'POST',
    'is_admin' => true,
]);
me_self_test(($blockSettings['allow'] ?? true) === false, 'settings blocked');

$blockUploads = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'application_write_api',
    'method' => 'POST',
]);
me_self_test(($blockUploads['allow'] ?? true) === false, 'uploads/api writes blocked');

$payPolicy = orange_restore_maint_enforcement_payment_callback_policy();
me_self_test($payPolicy['allowlisted'] === false, 'payment callback policy default block');
$blockPay = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'application_write_api',
    'method' => 'POST',
    'is_payment_callback' => true,
    'payment_callback_allowlisted' => false,
]);
me_self_test(($blockPay['allow'] ?? true) === false, 'payment callback blocked');
me_self_test(($blockPay['reason_code'] ?? '') === 'maintenance_payment_blocked', 'payment callback reason code');

$allowHealth = orange_restore_production_maintenance_decide($workRoot, [
    'method' => 'GET',
    'is_health_probe' => true,
]);
me_self_test(($allowHealth['allow'] ?? false) === true, 'health allowed');

$allowRc = orange_restore_production_maintenance_decide($workRoot, [
    'method' => 'GET',
    'is_restore_center_read' => true,
]);
me_self_test(($allowRc['allow'] ?? false) === true, 'restore center allowed');

$allowMaintPage = orange_restore_production_maintenance_decide($workRoot, [
    'method' => 'GET',
    'is_maintenance_page' => true,
]);
me_self_test(($allowMaintPage['allow'] ?? false) === true, 'maintenance page allowed');

$allowRead = orange_restore_production_maintenance_decide($workRoot, [
    'method' => 'GET',
    'scope' => 'admin_page_read',
    'is_admin' => true,
]);
me_self_test(($allowRead['allow'] ?? false) === true, 'read-only pages allowed');

$allowStatic = orange_restore_production_maintenance_decide($workRoot, [
    'method' => 'GET',
    'is_static_asset' => true,
]);
me_self_test(($allowStatic['allow'] ?? false) === true, 'static assets allowed');

// CLI bypass
$tok = orange_restore_maint_fw_issue_cli_bypass($workRoot, $jobId, 'production_db_import', 120);
$cliOk = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'production_db_import',
    'method' => 'POST',
    'is_cli' => true,
    'bypass_token' => $tok,
    'bypass_job_id' => $jobId,
]);
me_self_test(($cliOk['allow'] ?? false) === true && ($cliOk['action'] ?? '') === 'cli_bypass', 'CLI bypass works');

$cliBad = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
    'is_cli' => true,
]);
me_self_test(($cliBad['allow'] ?? true) === false, 'generic CLI rejected');

$cliFakeBypass = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
    'is_cli' => false,
    'bypass_token' => 'x',
]);
me_self_test(($cliFakeBypass['reason_code'] ?? '') === 'maintenance_bypass_forbidden', 'HTTP bypass token rejected');

// Wiring presence
$cfg = (string) file_get_contents($projectRoot . '/config.php');
me_self_test(str_contains($cfg, 'orange_restore_maint_enforcement_http_guard'), 'config wires admin guard');

$createOrder = (string) file_get_contents($projectRoot . '/api/orders/create-order.php');
me_self_test(str_contains($createOrder, 'orange_restore_maint_enforcement_api_mutation_guard'), 'create-order wired');

$intake = (string) file_get_contents($projectRoot . '/includes/order_intake_queue.php');
me_self_test(str_contains($intake, 'orange_restore_maint_enforcement_library_mutation_guard'), 'intake queue wired');

$backupRunner = (string) file_get_contents($projectRoot . '/includes/backup/backup_runner.php');
me_self_test(str_contains($backupRunner, 'conflicting_backup_job'), 'backup runner wired');

$cron = (string) file_get_contents($projectRoot . '/scripts/process_order_intake_queue.php');
me_self_test(str_contains($cron, 'orange_restore_maint_enforcement_cron_guard'), 'cron script wired');

$importCli = (string) file_get_contents($projectRoot . '/scripts/backup/restore_import_production.php');
me_self_test(str_contains($importCli, 'orange_restore_maint_enforcement_cli_restore_worker'), 'import CLI bypass wired');

// Single policy source — enforcement must call decide, not reimplement blocked scopes list.
$enf = (string) file_get_contents(
    $projectRoot . '/includes/backup/restore/restore_maintenance_enforcement.php'
);
me_self_test(
    str_contains($enf, 'orange_restore_production_maintenance_decide')
    && !str_contains($enf, 'function orange_restore_production_maintenance_decide'),
    'no duplicate middleware policy function'
);
me_self_test(
    substr_count($enf, "'order_create'") <= 2,
    'enforcement does not own full blocked-scope policy list'
);

// Audit: storefront mutation endpoints must call guard (or payment webhook flag).
$mustWire = [
    'api/orders/create-order.php',
    'api/orders/amend-order-items.php',
    'api/orders/cancel-by-customer.php',
    'api/orders/email-track-order-summary.php',
    'api/auth/apply-phone-merge.php',
    'api/auth/request-checkout-email-otp.php',
    'api/auth/request-email-verify.php',
    'api/auth/verify-checkout-email-otp.php',
    'api/payments/bank-proof.php',
    'api/payments/gateway-create.php',
    'api/payments/gateway-webhook.php',
];
$unprotected = [];
foreach ($mustWire as $rel) {
    $src = (string) @file_get_contents($projectRoot . '/' . $rel);
    if ($src === '' || !str_contains($src, 'orange_restore_maint_enforcement_')) {
        $unprotected[] = $rel;
    }
}
me_self_test($unprotected === [], 'all listed storefront mutation endpoints wired'
    . ($unprotected ? (' missing=' . implode(',', $unprotected)) : ''));

// Inactive → allow
orange_restore_maint_fw_write($workRoot, orange_restore_maint_fw_default_state());
me_self_test(orange_restore_maint_enforcement_is_active_fast($workRoot) === false, 'inactive fast path');
$inactive = orange_restore_production_maintenance_decide($workRoot, [
    'scope' => 'order_create',
    'method' => 'POST',
]);
me_self_test(($inactive['allow'] ?? false) === true, 'inactive allows writes');

me_rmtree($tmp);

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
if ($unprotected !== []) {
    echo "UNPROTECTED_IN_AUDIT_LIST:\n";
    foreach ($unprotected as $u) {
        echo "  - {$u}\n";
    }
}
exit($failures > 0 ? 1 : 0);
