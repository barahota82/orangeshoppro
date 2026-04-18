<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orders') || !orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جداول الطلبات أو العملاء غير متوفرة'], 500);
    }
    if (!orange_table_has_column($pdo, 'orders', 'customer_id')) {
        json_response(['success' => false, 'message' => 'عمود customer_id غير موجود في الطلبات'], 422);
    }

    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');
    $sql = 'SELECT id, customer_name, phone FROM orders WHERE TRIM(phone) <> \'\' AND (customer_id IS NULL OR customer_id = 0)';
    if ($hasPay) {
        $sql .= " AND LOWER(TRIM(COALESCE(payment_terms, 'cash'))) = 'credit'";
    }
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    foreach ($rows as $r) {
        $oid = (int) $r['id'];
        $cid = orange_ensure_customer($pdo, (string) ($r['customer_name'] ?? ''), (string) ($r['phone'] ?? ''));
        if ($cid > 0) {
            $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$cid, $oid]);
            ++$updated;
        }
    }
    audit_log('customer_backfill_orders', 'ربط طلبات آجل بعملاء: ' . $updated . ' صف', 'orders', 0);
    json_response([
        'success' => true,
        'message' => 'تم فحص الطلبات وربط ' . $updated . ' طلباً بعملاء (حسب الهاتف).',
        'updated' => $updated,
        'candidates' => count($rows),
    ]);
} catch (Throwable $e) {
    api_error($e, 'تعذر تنفيذ الربط');
}
