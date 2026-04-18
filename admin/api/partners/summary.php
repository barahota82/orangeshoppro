<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_allocations.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $includeAging = !empty($data['include_aging']);
    $rep = orange_partner_summary_report($pdo, $includeAging);
    json_response(array_merge(['success' => true], $rep));
} catch (Throwable $e) {
    api_error($e, 'تعذر بناء ملخص الذمم');
}
