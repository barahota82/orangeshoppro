<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_deployment_preflight.php';

$passes = 0;
$failures = 0;
function pf_test(bool $c, string $l): void
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

echo "=== self_test_restore_deployment_preflight ===\n";
$r = orange_restore_deployment_preflight_run(['project_root' => $projectRoot]);
pf_test(isset($r['ok'], $r['checks'], $r['blockers']), 'result shape');
pf_test(!empty($r['fail_closed']), 'fail_closed flag');
$ids = [];
foreach ($r['checks'] as $c) {
    $ids[] = (string) ($c['id'] ?? '');
}
foreach (['ziparchive', 'pdo_mysql', 'db_identity_configured', 'backup_root_configured', 'restore_work_root_configured'] as $need) {
    pf_test(in_array($need, $ids, true), 'check present: ' . $need);
}
pf_test(is_bool($r['ok']), 'ok is bool');

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
