<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job_framework.php';

$passes = 0;
$failures = 0;
function tm_test(bool $c, string $l): void
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

echo "=== self_test_restore_fw_transition_matrix ===\n";
tm_test(function_exists('orange_restore_fw_transition_is_allowed'), 'matrix helpers loaded');
tm_test(orange_restore_fw_transition_is_allowed('queued', 'preparing'), 'queued→preparing allowed');
tm_test(orange_restore_fw_transition_is_allowed('queued', 'queued'), 'same-status allowed');
tm_test(!orange_restore_fw_transition_is_allowed('queued', 'restore_completed'), 'queued→restore_completed rejected');
tm_test(orange_restore_fw_transition_is_allowed('uploads_cutover_ready', 'rollback_pending'), 'cutover→rollback allowed');
tm_test(orange_restore_fw_transition_is_allowed('production_import_running', 'failed'), 'running→failed global escape');
tm_test(!orange_restore_fw_transition_is_allowed('restore_completed', 'production_import_running'), 'terminal→import rejected');

$illegal = false;
try {
    orange_restore_fw_assert_transition('queued', 'restore_completed');
} catch (Throwable $e) {
    $illegal = str_contains($e->getMessage(), 'illegal_framework_status_transition');
}
tm_test($illegal, 'assert throws on illegal jump');

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
