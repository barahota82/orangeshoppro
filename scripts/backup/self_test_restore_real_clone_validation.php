<?php

declare(strict_types=1);

/**
 * P0-4 — Real clone validation self-test (actual MySQL only; never Mock).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_real_clone_validation.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: PHP ZipArchive extension required for real-clone DRV (enable extension=zip).\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_real_clone_validation.php';

$passes = 0;
$failures = 0;

function rcv_self_test(bool $cond, string $label): void
{
    global $passes, $failures;
    if ($cond) {
        echo 'PASS: ' . $label . PHP_EOL;
        $passes++;
    } else {
        echo 'FAIL: ' . $label . PHP_EOL;
        $failures++;
    }
}

echo "=== self_test_restore_real_clone_validation (P0-4) ===\n";

$mod = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_real_clone_validation.php'
);
$cli = (string) file_get_contents(
    $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'run_restore_real_clone_validation.php'
);

rcv_self_test(
    str_contains($mod, 'ORANGE_RESTORE_REAL_CLONE_MARKER')
    && str_contains($mod, 'real_clone_validation_report.json')
    && !str_contains($mod, 'OrangeRestoreDrDrillMockPdo')
    && !str_contains($mod, 'merge_pdo_override'),
    'module: marker + report; no Mock PDO wiring'
);
rcv_self_test(
    str_contains($cli, "PHP_SAPI !== 'cli'")
    && str_contains($cli, 'arbitrary path/database/credential')
    && !str_contains($cli, 'MockPdo'),
    'CLI: isolated args; no Mock'
);

// Isolation rejects production DB names.
$rejected = false;
try {
    orange_restore_real_clone_set_ctx([
        'clone_root' => 'D:\\orange_clone_mysql',
        'work_root' => 'D:\\orange_clone_mysql\\restore_work',
        'backup_root' => 'D:\\orange_clone_mysql\\backups',
        'uploads_root' => 'D:\\orange_clone_mysql\\uploads',
        'target_db' => 'orange_db',
        'shadow_db' => 'orange_clone_shadow',
        'real_project_root' => $projectRoot,
        'db_user' => 'root',
        'db_host' => '127.0.0.1',
        'db_port' => 3307,
    ]);
    // Markers missing first.
    orange_restore_real_clone_assert_isolation();
} catch (Throwable $e) {
    $rejected = trim($e->getMessage()) === 'clone_marker_missing'
        || trim($e->getMessage()) === 'clone_rejected_production_db_name';
}
rcv_self_test($rejected, 'isolation: rejects incomplete/production identity');

try {
    $result = orange_restore_real_clone_run([
        'project_root' => $projectRoot,
        'clone_root' => 'D:\\orange_clone_mysql',
        'port' => ORANGE_RESTORE_REAL_CLONE_DEFAULT_PORT,
        'auto_bootstrap' => true,
    ]);
    $report = is_array($result['report'] ?? null) ? $result['report'] : [];

    rcv_self_test(!empty($result['ok']), 'pipeline: overall PASS on real MySQL clone');
    rcv_self_test(($report['mock_pdo_used'] ?? true) === false, 'report: mock_pdo_used=false');
    rcv_self_test((string) ($report['server_version'] ?? '') !== '', 'report: server_version present');
    rcv_self_test((string) ($report['engine'] ?? '') !== '', 'report: engine present');
    rcv_self_test((string) ($report['charset'] ?? '') !== '', 'report: charset present');
    rcv_self_test((string) ($report['collation'] ?? '') !== '', 'report: collation present');
    rcv_self_test(isset($report['restore_duration_seconds']), 'report: restore duration');
    rcv_self_test(!empty($report['drv']), 'report: DRV block');
    rcv_self_test(!empty($report['shadow_verify']['ok']), 'report: shadow verify ok');
    rcv_self_test(!empty($report['smoke']['ok']), 'report: smoke ok');
    rcv_self_test(!empty($report['uploads_cutover']['ok']), 'report: uploads cutover ok');
    rcv_self_test(!empty($report['uploads_rollback']['ok']), 'report: uploads rollback ok');
    rcv_self_test(!empty($report['db_rollback']['ok']), 'report: db rollback ok');
    rcv_self_test(
        !empty($report['production_isolation_proof']['production_db_differs'])
        && !empty($report['production_isolation_proof']['uploads_differs_from_production']),
        'report: production isolation proof'
    );
    rcv_self_test(is_file((string) ($result['report_path'] ?? '')), 'report file written in clone work root');
    rcv_self_test(
        is_file($projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . 'real_clone_validation_report.json'),
        'docs copy of report present'
    );

    // Live assert: target session is not production.
    $boot = is_array($result['bootstrap'] ?? null) ? $result['bootstrap'] : [];
    $pdo = orange_restore_real_clone_connect(
        (string) ($boot['host'] ?? '127.0.0.1'),
        (int) ($boot['port'] ?? 3307),
        (string) ($boot['user'] ?? 'root'),
        (string) ($boot['pass'] ?? ''),
        ORANGE_RESTORE_REAL_CLONE_TARGET_DB
    );
    $sessionDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $prod = orange_restore_real_clone_production_identity($projectRoot);
    rcv_self_test(
        strcasecmp($sessionDb, ORANGE_RESTORE_REAL_CLONE_TARGET_DB) === 0
        && strcasecmp($sessionDb, $prod['db']) !== 0,
        'live: clone target session != production DB'
    );
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM clone_items')->fetchColumn();
    $anchor = (string) $pdo->query('SELECT name FROM clone_items WHERE id=1')->fetchColumn();
    rcv_self_test(
        $rows === 1 && $anchor === 'pre_restore_anchor',
        'live: clone target reflects DB rollback anchor'
    );
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . PHP_EOL;
    rcv_self_test(false, 'pipeline: completed without exception (' . $e->getMessage() . ')');
}

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
