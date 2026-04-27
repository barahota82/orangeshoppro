<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_return_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $supplierId = (int) ($data['supplier_id'] ?? 0);
    $type = trim((string) ($data['type'] ?? ''));
    $purchaseIdOpt = (int) ($data['purchase_id'] ?? 0);
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $notes = trim((string) ($data['notes'] ?? ''));

    if (!in_array($type, ['cash', 'credit'], true) || $items === []) {
        json_response(['success' => false, 'message' => 'بيانات مردود المشتريات غير صحيحة'], 422);
    }

    if ($type === 'credit' && $supplierId <= 0) {
        json_response(['success' => false, 'message' => 'مردود آجل يتطلّب مورداً مربوطاً بحساب ذمة في الدليل.'], 422);
    }
    if ($type === 'credit') {
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
    if ($type === 'cash' && $supplierId > 0) {
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    $pdo->beginTransaction();

    if ($purchaseIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE id = ? LIMIT 1');
        $chk->execute([$purchaseIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'فاتورة الشراء المرجعية غير موجودة'], 422);
        }
    }

    $computedTotal = 0.0;
    foreach ($items as $item) {
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر مردود غير صالح');
        }
        $computedTotal += ($qty * $cost);
    }
    $computedTotal = round($computedTotal, 4);

    $tmpNum = 'PR-TMP-' . bin2hex(random_bytes(6));
    $pdo->prepare(
        'INSERT INTO purchase_returns (return_number, purchase_id, supplier_id, type, total, notes)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $tmpNum,
        $purchaseIdOpt > 0 ? $purchaseIdOpt : null,
        $supplierId > 0 ? $supplierId : null,
        $type,
        $computedTotal,
        $notes !== '' ? $notes : null,
    ]);
    $returnId = (int) $pdo->lastInsertId();
    $retRef = 'PR-' . $returnId;
    $pdo->prepare('UPDATE purchase_returns SET return_number = ? WHERE id = ?')->execute([$retRef, $returnId]);

    $hasVariant = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير مكتمل');
        }
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        orange_purchase_return_apply_line_stock($pdo, $productId, $variantId, $qty);

        if ($hasVariant) {
            $pdo->prepare(
                'INSERT INTO purchase_return_items (purchase_return_id, product_id, variant_id, qty, cost)
                 VALUES (?,?,?,?,?)'
            )->execute([$returnId, $productId, $variantId, $qty, $cost]);
        } else {
            $pdo->prepare(
                'INSERT INTO purchase_return_items (purchase_return_id, product_id, qty, cost)
                 VALUES (?,?,?,?)'
            )->execute([$returnId, $productId, $qty, $cost]);
        }
    }

    $glB = orange_gl_purchase_return_posting_bundle($pdo, $type, $supplierId, $returnId, $computedTotal);
    $now = date('Y-m-d H:i:s');
    $afterJson = $glB['after_post'] !== null
        ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE)
        : null;

    if (orange_gl_use_pending_queue($pdo)) {
        if ($glB['is_multi']) {
            orange_gl_pending_enqueue_multi(
                $pdo,
                $glB['lines'],
                $retRef,
                $retRef,
                $now,
                $now,
                $glB['voucher_description'],
                'purchase_return',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $retRef,
                'source_label' => $retRef,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $computedTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
                'after_post_json' => $afterJson,
            ]);
        }
    } else {
        if ($glB['is_multi']) {
            $vid = orange_voucher_post($pdo, [
                'voucher_date' => $now,
                'document_entered_at' => $now,
                'reference' => $retRef,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
            ], $glB['lines']);
            orange_gl_apply_voucher_after_post_hooks($pdo, $vid, $afterJson);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $computedTotal,
                'reference' => $retRef,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
            ]);
            if ($glB['legacy_ap_subledger']) {
                orange_purchase_return_record_ap_subledger($pdo, $returnId, $supplierId, $type, $computedTotal);
            }
        }
    }

    $pdo->commit();
    audit_log('purchase_return_create', 'تم إنشاء مردود مشتريات رقم: ' . $returnId, 'purchase_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم حفظ مردود المشتريات', 'purchase_return_id' => $returnId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ مردود المشتريات');
}
