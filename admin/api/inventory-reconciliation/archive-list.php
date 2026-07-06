<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_inventory_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول الجرد غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $from = trim((string) ($_GET['from'] ?? '')) ?: null;
    $to = trim((string) ($_GET['to'] ?? '')) ?: null;

    $rows = orange_inventory_reconciliation_archive_list($pdo, $ctxCountryId, 300, $from, $to);

    json_response([
        'success' => true,
        'records' => $rows,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل أرشيف الجرد');
}
