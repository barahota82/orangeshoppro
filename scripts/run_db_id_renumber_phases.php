<?php

declare(strict_types=1);

/**
 * تشغيل ترحيلات إعادة ترقيم id (v84–v87) من CLI بعد git pull.
 * الاستخدام على السيرفر: php scripts/run_db_id_renumber_phases.php
 * (يشمل v88 — channels بعد حذف المكرر يدوياً)
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
    orange_catalog_migrate_db_id_renumber_phases($pdo);
    $st = $pdo->query(
        "SELECT filename FROM orange_schema_migrations WHERE filename LIKE 'php_db_id_renumber_phase%' ORDER BY filename"
    );
    $rows = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] run_db_id_renumber_phases: ' . $e->getMessage());
    }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);

    exit(1);
}

echo 'OK php_revision=' . (string) ORANGE_CATALOG_SCHEMA_PHP_REVISION . PHP_EOL;
echo 'markers:' . PHP_EOL;
foreach ($rows as $fn) {
    echo '  - ' . (string) $fn . PHP_EOL;
}

exit(0);
