<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_has_column($pdo, 'orders', 'amount_paid')) {
        json_response(['success' => false, 'message' => 'عمود المدفوع غير مُهيأ — شغّل ترحيل قاعدة البيانات'], 422);
    }
    $data = get_json_input();
    $orderId = (int) ($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        json_response(['success' => false, 'message' => 'معرف الطلب مطلوب'], 422);
    }
    $orderSelectCols = ['id', 'total'];
    if (orange_table_has_column($pdo, 'orders', 'country_id')) {
        $orderSelectCols[] = 'country_id';
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $orderSelectCols) . ' FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'الطلب غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'orders', $orderId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
    $moneyCountryId = (int) ($row['country_id'] ?? 0);
    if ($moneyCountryId <= 0) {
        $moneyCountryId = orange_admin_context_country_id($pdo);
    }
    $moneyDecimals = orange_currency_decimals_for_code(
        orange_country_functional_currency_code($pdo, $moneyCountryId)
    );
    $moneyEpsilon = pow(10, -$moneyDecimals);
    $total = round(max(0.0, (float) ($row['total'] ?? 0)), $moneyDecimals);
    $paid = round(max(0.0, (float) ($data['amount_paid'] ?? 0)), $moneyDecimals);
    if ($paid > $total + ($moneyEpsilon / 2)) {
        $paid = $total;
    }
    $pdo->prepare('UPDATE orders SET amount_paid = ? WHERE id = ?')->execute([$paid, $orderId]);
    json_response(['success' => true, 'message' => 'تم حفظ المدفوع']);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث الدفع');
}
