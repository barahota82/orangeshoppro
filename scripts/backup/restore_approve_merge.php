<?php

declare(strict_types=1);

/**
 * Phase 2C — Owner approval / rejection / cancellation gate (CLI).
 *
 * Approves a staging restore job for future merge only — never writes production.
 *
 * Usage:
 *   php scripts/backup/restore_approve_merge.php --job=JOB_ID --admin-id=N --password=SECRET --confirm=RESTORE --action=approve
 *   php scripts/backup/restore_approve_merge.php --job=JOB_ID --admin-id=N --password=SECRET --action=reject --reason="..."
 *   php scripts/backup/restore_approve_merge.php --job=JOB_ID --admin-id=N --password=SECRET --action=cancel --reason="..."
 *
 * Country confirm phrase: RESTORE {COUNTRY_CODE}
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$jobId = '';
$adminId = 0;
$password = '';
$confirmation = '';
$action = 'approve';
$reason = '';

foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobId = trim(substr($arg, strlen('--job=')));
    } elseif (str_starts_with($arg, '--admin-id=')) {
        $adminId = (int) substr($arg, strlen('--admin-id='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } elseif (str_starts_with($arg, '--confirm=')) {
        $confirmation = substr($arg, strlen('--confirm='));
    } elseif (str_starts_with($arg, '--action=')) {
        $action = strtolower(trim(substr($arg, strlen('--action='))));
    } elseif (str_starts_with($arg, '--reason=')) {
        $reason = substr($arg, strlen('--reason='));
    }
}

if ($jobId === '' || $adminId <= 0 || $password === '') {
    fwrite(STDERR, "Usage: php restore_approve_merge.php --job=JOB_ID --admin-id=N --password=SECRET [--confirm=PHRASE] --action=approve|reject|cancel [--reason=TEXT]\n");
    exit(2);
}

if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
    fwrite(STDERR, "ERROR: --action must be approve, reject, or cancel.\n");
    exit(2);
}

if ($action === 'approve' && trim($confirmation) === '') {
    fwrite(STDERR, "ERROR: --confirm is required for approve (RESTORE or RESTORE {COUNTRY_CODE}).\n");
    exit(2);
}

if (($action === 'reject' || $action === 'cancel') && trim($reason) === '') {
    fwrite(STDERR, "ERROR: --reason is required for reject/cancel.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

try {
    $pdo = db();
    $options = [
        'project_root' => $projectRoot,
        'job_id' => $jobId,
        'admin_id' => $adminId,
        'password' => $password,
    ];

    if ($action === 'approve') {
        $options['confirmation_phrase'] = $confirmation;
        $result = orange_restore_orchestrator_approve_for_merge($pdo, $options);
    } elseif ($action === 'reject') {
        $options['reason'] = $reason;
        $result = orange_restore_orchestrator_reject($pdo, $options);
    } else {
        $options['reason'] = $reason;
        $result = orange_restore_orchestrator_cancel($pdo, $options);
    }

    echo 'ok=1' . PHP_EOL;
    echo 'action=' . $action . PHP_EOL;
    echo 'job_id=' . (string) ($result['job_id'] ?? '') . PHP_EOL;
    echo 'status=' . (string) ($result['status'] ?? '') . PHP_EOL;
    echo 'production_writes=0' . PHP_EOL;
    echo 'merge_executed=0' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
