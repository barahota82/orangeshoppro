<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/party_allocations.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $voucherId = (int) ($data['voucher_id'] ?? 0);

    if ($voucherId <= 0) {
        json_response(['success' => false, 'message' => 'رقم السند مطلوب'], 422);
    }

    $st = $pdo->prepare(
        "SELECT * FROM journal_vouchers WHERE id = ? AND entry_type = 'supplier_payment' LIMIT 1"
    );
    $st->execute([$voucherId]);
    $vRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$vRow) {
        json_response(['success' => false, 'message' => 'السند غير موجود أو ليس سند سداد مورد'], 404);
    }
    $admin = current_admin();
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $vCountryId = (int) ($vRow['country_id'] ?? 0);
    try {
        orange_edit_lock_assert_may_mutate(
            $pdo,
            $admin,
            'supplier_payment',
            $voucherId,
            'delete',
            $vCountryId > 0 ? $vCountryId : null
        );
        orange_admin_assert_entity_country($pdo, 'journal_vouchers', $voucherId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM journal_lines WHERE voucher_id = ?')->execute([$voucherId]);
        $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([$voucherId]);
        orange_edit_lock_unregister(
            $pdo,
            'supplier_payment',
            $voucherId,
            $vCountryId > 0 ? $vCountryId : null
        );
        orange_edit_lock_log_mutation($pdo, 'supplier_payment', $voucherId, 'delete');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit_log('supplier_payment_delete', 'حذف سند سداد مورد رقم ' . $voucherId, 'journal_vouchers', $voucherId);
    json_response(['success' => true, 'message' => 'تم حذف السند']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حذف السند');
}
