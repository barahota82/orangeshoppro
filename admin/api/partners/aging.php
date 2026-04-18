<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
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
    } else {
        $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $chk->execute([$partyId]);
        if (!$chk->fetch()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
    }
    $asOfNorm = $asOf !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : null;
    $report = orange_party_aging_buckets($pdo, $partyKind, $partyId, $asOfNorm);
    json_response(['success' => true, 'aging' => $report]);
} catch (Throwable $e) {
    api_error($e, 'تعذر حساب أعمار الذمم');
}
