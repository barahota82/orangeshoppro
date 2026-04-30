<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'pattern_dictionary')) {
        json_response(['success' => true, 'patterns' => []]);

        return;
    }
    $rows = $pdo->query(
        'SELECT * FROM pattern_dictionary ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    json_response(['success' => true, 'patterns' => $rows]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذّر تحميل أنماط الألوان');
}
