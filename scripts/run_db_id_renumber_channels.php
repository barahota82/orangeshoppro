<?php

declare(strict_types=1);

/**
 * إعادة ترقيم channels فقط (بعد حذف المكرر يدوياً).
 * php scripts/run_db_id_renumber_channels.php
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'CLI only';

    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/schema_migrations.php';

try {
    $pdo = db();
    orange_schema_migrations_ensure_table($pdo);
    orange_catalog_migrate_db_id_renumber_channels_v88($pdo);
    $row = $pdo->query('SELECT MIN(id) AS mn, MAX(id) AS mx, COUNT(*) AS c FROM channels')->fetch(PDO::FETCH_ASSOC);
    echo 'OK channels id: min=' . (string) ($row['mn'] ?? '') . ' max=' . (string) ($row['mx'] ?? '')
        . ' count=' . (string) ($row['c'] ?? '') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);

    exit(1);
}

exit(0);
