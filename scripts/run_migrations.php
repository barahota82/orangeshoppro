<?php

declare(strict_types=1);

/**
 * تشغيل ترحيل المخطط من سطر الأوامر بعد النشر (IBRAHIM §4).
 * لا يستبدل فتح الواجهة — يكمّلها عندما يُشغَّل صراحةً على السيرفر.
 *
 * الاستخدام: php scripts/run_migrations.php
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'CLI only';

    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] run_migrations: ' . $e->getMessage());
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);

    exit(1);
}

echo 'orange_catalog_ensure_schema OK (revision ' . (string) ORANGE_CATALOG_SCHEMA_PHP_REVISION . ')' . PHP_EOL;

exit(0);
