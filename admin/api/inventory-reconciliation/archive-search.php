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

    $scope = (string) ($_GET['scope'] ?? '');
    $warehouseId = 0;
    $agentId = 0;
    if (preg_match('/^w:(\d+)$/', $scope, $m)) {
        $warehouseId = (int) $m[1];
    } elseif (preg_match('/^a:(\d+)$/', $scope, $m)) {
        $agentId = (int) $m[1];
    }

    $filters = [
        'from' => trim((string) ($_GET['from'] ?? '')),
        'to' => trim((string) ($_GET['to'] ?? '')),
        'notes' => trim((string) ($_GET['notes'] ?? '')),
        'warehouse_id' => $warehouseId,
        'delivery_agent_id' => $agentId,
    ];

    $rows = orange_inventory_reconciliation_archive_search($pdo, $filters, $ctxCountryId);

    json_response([
        'success' => true,
        'records' => $rows,
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
