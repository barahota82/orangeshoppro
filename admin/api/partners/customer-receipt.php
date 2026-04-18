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
    $customerId = (int) ($data['customer_id'] ?? 0);
    $amount = (float) ($data['amount'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $dateRaw = trim((string) ($data['date'] ?? ''));
    $date = $dateRaw !== '' ? $dateRaw : date('Y-m-d H:i:s');
    if (strlen($date) === 10) {
        $date .= ' 12:00:00';
    }
    if ($customerId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'العميل والمبلغ مطلوبان'], 422);
    }
    if ($description === '') {
        $description = 'سند قبض عميل';
    }

    $chk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
    $chk->execute([$customerId]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
    }

    $allowExcess = !empty($data['allow_excess']);
    $arBal = orange_party_balance_customer($pdo, $customerId);
    if (!$allowExcess && $arBal > 0.0001 && $amount > $arBal + 0.02) {
        json_response([
            'success' => false,
            'message' => 'المبلغ يتجاوز ذمة العميل الحالية (' . number_format($arBal, 3) . '). صحّح المبلغ أو فعّل السماح بالزيادة (سلفة / دفعة مقدمة).',
            'max_amount' => $arBal,
        ], 422);
    }

    $allocLines = orange_party_normalize_allocations_payload($data['allocations'] ?? []);

    $arId = orange_gl_account_id($pdo, 'ar_credit');
    $cashId = orange_gl_account_id($pdo, 'cash');

    $seq = orange_sequence_next($pdo, 'crec_' . date('Ymd'));
    $ref = 'CREC-' . $customerId . '-' . date('Ymd') . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        $vid = orange_voucher_post($pdo, [
            'voucher_date' => $date,
            'reference' => $ref,
            'description' => $description,
            'entry_type' => 'customer_receipt',
        ], [
            ['account_id' => $cashId, 'debit' => $amount, 'credit' => 0, 'memo' => 'تحصيل نقدي'],
            ['account_id' => $arId, 'debit' => 0, 'credit' => $amount, 'memo' => 'تخفيض ذمة عميل'],
        ]);

        orange_party_subledger_record($pdo, 'customer', $customerId, $vid, 0, $amount, 'receipt_ar', null, $description);

        orange_party_insert_payment_allocations($pdo, 'customer', $customerId, $vid, $amount, $allocLines);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit_log('customer_receipt', 'قبض عميل #' . $customerId . ' مبلغ ' . $amount, 'party_subledger', $customerId);
    json_response(['success' => true, 'message' => 'تم تسجيل القبض', 'voucher_id' => $vid]);
} catch (Throwable $e) {
    api_error($e, 'تعذر تسجيل القبض');
}
