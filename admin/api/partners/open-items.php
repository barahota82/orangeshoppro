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
    $partyKind = (string) ($data['party_kind'] ?? '');
    $partyId = (int) ($data['party_id'] ?? 0);
    if (!in_array($partyKind, ['customer', 'supplier'], true) || $partyId <= 0) {
        json_response(['success' => false, 'message' => 'نوع الطرف والمعرّف مطلوبان'], 422);
    }
    if ($partyKind === 'customer') {
        $chk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
    } else {
        $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
    }
    $items = orange_party_open_documents($pdo, $partyKind, $partyId);
    json_response(['success' => true, 'items' => $items]);
} catch (Throwable $e) {
    api_error($e, 'تعذر جلب المستندات المفتوحة');
}
