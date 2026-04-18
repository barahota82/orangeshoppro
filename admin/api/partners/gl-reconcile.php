<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/party_allocations.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $fyId = (int) ($data['fiscal_year_id'] ?? 0);
    if ($fyId <= 0) {
        $years = orange_fiscal_years_list($pdo);
        if ($years !== []) {
            $fyId = (int) $years[0]['id'];
        }
    }
    $rep = orange_partner_gl_reconcile($pdo, $fyId);
    if ($rep === null) {
        json_response([
            'success' => false,
            'message' => 'حدد سنة مالية أو فعّل سندات اليومية لعرض المطابقة.',
        ], 422);
    }
    json_response(array_merge(['success' => true], $rep));
} catch (Throwable $e) {
    api_error($e, 'تعذر حساب مطابقة الدليل');
}
