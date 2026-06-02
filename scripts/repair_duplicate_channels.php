<?php

declare(strict_types=1);

/**
 * دمج قنوات مكررة (نفس الدولة + نفس path_segment أو slug).
 * تشخيص: php scripts/repair_duplicate_channels.php
 * تنفيذ: php scripts/repair_duplicate_channels.php --apply
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'CLI only';

    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/channels_maintenance.php';

$apply = in_array('--apply', $argv ?? [], true);

try {
    $pdo = db();
    $report = orange_channels_repair_duplicates($pdo, !$apply);
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    if (!$apply && (($report['path_segment_groups'] ?? 0) > 0 || ($report['slug_groups'] ?? 0) > 0)) {
        echo PHP_EOL . 'Dry run only. Re-run with --apply after backup.' . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);

    exit(1);
}

exit(0);
