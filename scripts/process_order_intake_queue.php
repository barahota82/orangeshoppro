<?php

declare(strict_types=1);

/**
 * Drain storefront order intake queue (FIFO). Run from cron e.g. every minute:
 *   php scripts/process_order_intake_queue.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/order_intake_queue.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!orange_table_exists($pdo, 'order_intake_queue')) {
    fwrite(STDERR, "order_intake_queue missing; run app once to migrate.\n");
    exit(1);
}

$n = 0;
while (orange_order_intake_process_next($pdo)) {
    ++$n;
}

echo 'Processed ' . $n . " intake job(s).\n";
