<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/party_allocations.php';
require_once __DIR__ . '/../../../includes/document_sequences.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $amount = (float) ($data['amount'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $dateRaw = trim((string) ($data['date'] ?? ''));
    $date = $dateRaw !== '' ? $dateRaw : date('Y-m-d H:i:s');
    if (strlen($date) === 10) {
        $date .= ' 12:00:00';
    }
    if ($supplierId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'المورد والمبلغ مطلوبان'], 422);
    }
    if ($description === '') {
        $description = 'سند دفع مورد';
    }

    $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
    $chk->execute([$supplierId]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
    }

    $allowExcess = !empty($data['allow_excess']);
    $apBal = orange_party_balance_supplier($pdo, $supplierId);
    if ($apBal <= 0.0001) {
        json_response(['success' => false, 'message' => 'لا توجد ذمة مستحقة لهذا المورد (الرصيد صفر أو سالب).'], 422);
    }
    if (!$allowExcess && $amount > $apBal + 0.02) {
        json_response([
            'success' => false,
            'message' => 'المبلغ يتجاوز الذمة المستحقة (' . number_format($apBal, 3) . '). أزل الزيادة أو فعّل خيار السماح بالزيادة إن كان مقصوداً (دفعة مقدمة).',
            'max_amount' => $apBal,
        ], 422);
    }

    $allocLines = orange_party_normalize_allocations_payload($data['allocations'] ?? []);

    $apId = orange_gl_account_id($pdo, 'accounts_payable');
    $cashId = orange_gl_account_id($pdo, 'cash');

    $seq = orange_sequence_next($pdo, 'spay_' . date('Ymd'));
    $ref = 'SPAY-' . $supplierId . '-' . date('Ymd') . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        $vid = orange_voucher_post($pdo, [
            'voucher_date' => $date,
            'reference' => $ref,
            'description' => $description,
            'entry_type' => 'supplier_payment',
        ], [
            ['account_id' => $apId, 'debit' => $amount, 'credit' => 0, 'memo' => 'تخفيض ذمة مورد'],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => $amount, 'memo' => 'صرف نقدي'],
        ]);

        orange_party_subledger_record($pdo, 'supplier', $supplierId, $vid, $amount, 0, 'payment_ap', null, $description);

        orange_party_insert_payment_allocations($pdo, 'supplier', $supplierId, $vid, $amount, $allocLines);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit_log('supplier_payment', 'دفع مورد #' . $supplierId . ' مبلغ ' . $amount, 'party_subledger', $supplierId);
    json_response(['success' => true, 'message' => 'تم تسجيل الدفع', 'voucher_id' => $vid]);
} catch (Throwable $e) {
    api_error($e, 'تعذر تسجيل الدفع');
}
