<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $partyKind = (string) ($data['party_kind'] ?? '');
    $partyId = (int) ($data['party_id'] ?? 0);
    $asOf = trim((string) ($data['as_of'] ?? ''));
    if (!in_array($partyKind, ['customer', 'supplier'], true) || $partyId <= 0) {
        json_response(['success' => false, 'message' => 'نوع الطرف والمعرّف مطلوبان'], 422);
    }
    if ($partyKind === 'customer') {
        $chk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'customers', $partyId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
    } else {
        $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'suppliers', $partyId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }
    if ($asOf !== '') {
        $asOfNorm = orange_parse_admin_date_to_ymd($asOf);
        if ($asOfNorm === '') {
            json_response(['success' => false, 'message' => 'تاريخ غير صالح'], 422);
        }
    } else {
        $asOfNorm = null;
    }
    $report = orange_party_aging_buckets($pdo, $partyKind, $partyId, $asOfNorm);
    json_response(['success' => true, 'aging' => $report]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حساب أعمار الذمم');
}
