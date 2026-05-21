<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * س15: حذف عميل من سجل العملاء.
 * شروط الحذف الصارمة (لحماية الذمم والتاريخ):
 *  - رصيد ذمته صفر (party_subledger مجموع debit-credit يساوي 0)
 *  - لا توجد طلبات (orders.customer_id) مربوطة به
 *  - لا حساب واجهة (storefront_accounts.customer_id) مربوط به
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }
    $data = get_json_input();
    $idIn = (int) ($data['id'] ?? 0);
    if ($idIn <= 0) {
        json_response(['success' => false, 'message' => 'معرف العميل مطلوب'], 422);
    }

    $exSt = $pdo->prepare('SELECT id, name_ar, phone FROM customers WHERE id = ? LIMIT 1');
    $exSt->execute([$idIn]);
    $row = $exSt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'customers', $idIn);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    // 1) فحص رصيد الذمة.
    $balance = orange_party_balance_customer($pdo, $idIn);
    if (abs((float) $balance) > 0.001) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن الحذف: العميل عليه رصيد ذمة (' . number_format((float) $balance, 3) . ' KD). سوّ الحساب أولاً.',
        ], 409);
    }

    // 2) فحص الطلبات المربوطة.
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'customer_id')) {
        $oSt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
        $oSt->execute([$idIn]);
        $oCnt = (int) $oSt->fetchColumn();
        if ($oCnt > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: العميل مربوط بـ ' . $oCnt . ' طلب. يمكنك تعطيل العميل بدل الحذف.',
            ], 409);
        }
    }

    // 3) فحص حساب الواجهة.
    if (orange_table_exists($pdo, 'storefront_accounts') && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')) {
        $sfSt = $pdo->prepare('SELECT COUNT(*) FROM storefront_accounts WHERE customer_id = ?');
        $sfSt->execute([$idIn]);
        $sfCnt = (int) $sfSt->fetchColumn();
        if ($sfCnt > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: العميل مربوط بحساب واجهة. فُكَّ الربط أولاً (يمكنك تعطيل العميل).',
            ], 409);
        }
    }

    // 4) فحص party_subledger (لو فيه أي سطر حتى لو الرصيد صفر — تاريخ مهم).
    if (orange_table_exists($pdo, 'party_subledger')) {
        $psSt = $pdo->prepare("SELECT COUNT(*) FROM party_subledger WHERE party_kind = 'customer' AND party_id = ?");
        $psSt->execute([$idIn]);
        $psCnt = (int) $psSt->fetchColumn();
        if ($psCnt > 0) {
            json_response([
                'success' => false,
                'message' => 'لا يمكن الحذف: للعميل ' . $psCnt . ' حركة في دفتر الذمم (حتى لو الرصيد صفر). التاريخ المحاسبي يمنع الحذف.',
            ], 409);
        }
    }

    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$idIn]);
    audit_log('customer_delete', 'حذف عميل #' . $idIn . ' — ' . ($row['phone'] ?? ''), 'customers', $idIn);
    json_response(['success' => true, 'message' => 'تم حذف العميل', 'id' => $idIn]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف العميل');
}
