<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/party_allocations.php';
require_once __DIR__ . '/../../../includes/document_sequences.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $amount = (float) ($data['amount'] ?? 0);
    $description = trim((string) ($data['description'] ?? ''));
    $dateRaw = trim((string) ($data['date'] ?? ''));
    $date = orange_normalize_admin_posted_datetime($dateRaw);
    if ($date === null) {
        json_response(['success' => false, 'message' => 'التاريخ غير صالح'], 422);
    }
    if ($supplierId <= 0 || $amount <= 0) {
        json_response(['success' => false, 'message' => 'المورد والمبلغ مطلوبان'], 422);
    }
    if ($description === '') {
        $description = 'سداد فواتير مشتريات آجلة';
    }

    $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
    $chk->execute([$supplierId]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $supCountryId = 0;
    if (orange_table_has_country_id($pdo, 'suppliers')) {
        $stSc = $pdo->prepare('SELECT country_id FROM suppliers WHERE id = ? LIMIT 1');
        $stSc->execute([$supplierId]);
        $supCountryId = (int) ($stSc->fetchColumn() ?: 0);
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

    try {
        $apId = orange_supplier_required_payable_account_id($pdo, $supplierId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }
    $cashId = orange_gl_account_id($pdo, 'cash');

    $seq = orange_sequence_next($pdo, 'spay_' . date('Ymd'), $supCountryId);
    $pendingKey = 'src:supplier_payment:' . $supplierId . ':' . date('Ymd') . ':' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();
    try {
        if (orange_gl_use_pending_queue($pdo)) {
            $after = [
                'party_subledger' => [
                    'party_kind' => 'supplier',
                    'party_id' => $supplierId,
                    'debit' => $amount,
                    'credit' => 0.0,
                    'ref_type' => 'payment_ap',
                    'memo' => $description,
                ],
            ];
            if ($allocLines !== []) {
                $after['party_payment_allocations'] = [
                    'party_kind' => 'supplier',
                    'party_id' => $supplierId,
                    'amount' => $amount,
                    'lines' => $allocLines,
                ];
            }
            $pendingId = orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingKey,
                'source_label' => 'SPAY-' . $supplierId,
                'movement_at' => $date,
                'voucher_date' => $date,
                'account_debit' => $apId,
                'account_credit' => $cashId,
                'amount' => $amount,
                'description' => $description,
                'entry_type' => 'supplier_payment',
                'after_post_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
            ]);
            $pdo->commit();
            audit_log('supplier_payment', 'سداد فواتير مشتريات آجلة (معلّق) #' . $supplierId . ' مبلغ ' . $amount, 'party_subledger', $supplierId);
            json_response([
                'success' => true,
                'message' => 'تم تسجيل الدفع في طابور الترحيل — أكمل من «إقفال الحركات»',
                'voucher_id' => null,
                'pending_movement_id' => $pendingId,
            ]);
        }

        $vid = orange_voucher_post($pdo, [
            'voucher_date' => $date,
            'description' => $description,
            'entry_type' => 'supplier_payment',
            'country_id' => $supCountryId > 0 ? $supCountryId : null,
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

    audit_log('supplier_payment', 'سداد فواتير مشتريات آجلة #' . $supplierId . ' مبلغ ' . $amount, 'party_subledger', $supplierId);
    json_response(['success' => true, 'message' => 'تم تسجيل الدفع', 'voucher_id' => $vid]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تسجيل الدفع');
}
