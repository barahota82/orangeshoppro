<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
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
        'SELECT id, voucher_date, reference, description, entry_type FROM journal_vouchers WHERE id = ? AND entry_type = ? LIMIT 1'
    );
    $st->execute([$voucherId, 'supplier_payment']);
    $voucher = $st->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        json_response(['success' => false, 'message' => 'السند غير موجود'], 404);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'journal_vouchers', $voucherId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $lines = $pdo->prepare('SELECT account_id, debit, credit, memo FROM journal_lines WHERE voucher_id = ? ORDER BY id ASC');
    $lines->execute([$voucherId]);
    $lineRows = $lines->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total = 0.0;
    foreach ($lineRows as $l) {
        $total += (float) ($l['debit'] ?? 0);
    }

    $supplierId = 0;
    if (orange_table_exists($pdo, 'party_subledger')) {
        $ps = $pdo->prepare("SELECT party_id FROM party_subledger WHERE voucher_id = ? AND party_kind = 'supplier' LIMIT 1");
        $ps->execute([$voucherId]);
        $supplierId = (int) $ps->fetchColumn();
    }

    $voucherDateDmy = '';
    if (!empty($voucher['voucher_date'])) {
        $voucherDateDmy = orange_format_date_dmY((string) $voucher['voucher_date']);
    }

    json_response([
        'success' => true,
        'voucher_id' => (int) $voucher['id'],
        'voucher_date' => (string) ($voucher['voucher_date'] ?? ''),
        'voucher_date_dmy' => $voucherDateDmy,
        'reference' => (string) ($voucher['reference'] ?? ''),
        'description' => (string) ($voucher['description'] ?? ''),
        'total' => round($total, 3),
        'supplier_id' => $supplierId,
        'lines' => $lineRows,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل السند');
}
