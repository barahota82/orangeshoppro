<?php

declare(strict_types=1);

/**
 * CLI only: يربط كل المنتجات بكل القنوات النشطة (نفس منطق backfill_product_channels.sql.TXT).
 *
 * من مجلد المشروع: php scripts/run_backfill_product_channels.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';

$pdo = db();
$sql = <<<'SQL'
INSERT IGNORE INTO product_channels (product_id, channel_id)
SELECT p.id, c.id
FROM products p
CROSS JOIN channels c
WHERE c.is_active = 1
SQL;

$affected = $pdo->exec($sql);
if ($affected === false) {
    fwrite(STDERR, "Failed to run backfill.\n");
    exit(1);
}

// PDO::exec returns row count for MySQL INSERT; may be 0 if all pairs already exist.
fwrite(STDOUT, "Backfill finished (rows reported by driver: {$affected}).\n");
exit(0);
