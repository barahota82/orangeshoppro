<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT * FROM color_dictionary ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    json_response(['success' => true, 'colors' => $rows]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
