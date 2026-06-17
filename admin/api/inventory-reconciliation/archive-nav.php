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
    $dir = (string) ($_GET['dir'] ?? '');
    $current = (int) ($_GET['current'] ?? 0);
    if (! in_array($dir, ['first', 'prev', 'next', 'last'], true)) {
        json_response(['success' => false, 'message' => 'اتجاه غير صالح'], 422);
    }

    $targetId = orange_inventory_reconciliation_archive_nav($pdo, $dir, $current, $ctxCountryId);

    json_response([
        'success' => true,
        'id' => $targetId,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
