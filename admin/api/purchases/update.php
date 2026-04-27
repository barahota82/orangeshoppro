<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_admin_api();

function reverse_purchase_stock(PDO $pdo, int $purchaseId): void
{
    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $hasRecv = orange_table_has_column($pdo, 'purchase_items', 'qty_received');
    $cols = 'product_id, qty';
    if ($hasRecv) {
        $cols .= ', qty_received';
    }
    if ($hasV) {
        $cols .= ', variant_id';
    }
    $sql = 'SELECT ' . $cols . ' FROM purchase_items WHERE purchase_id = ?';
    $itemsStmt = $pdo->prepare($sql);
    $itemsStmt->execute([$purchaseId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $qty = $hasRecv
            ? (int) ($item['qty_received'] ?? 0)
            : (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $vid = $hasV ? (int) ($item['variant_id'] ?? 0) : 0;
        if ($vid <= 0) {
            $vid = orange_purchase_resolve_variant_id($pdo, $pid, 0);
        }
        $pdo->prepare(
            'UPDATE product_variants SET stock_quantity = GREATEST(stock_quantity - ?, 0) WHERE id = ? AND product_id = ?'
        )->execute([$qty, $vid, $pid]);
    }
}

function apply_purchase_items(PDO $pdo, int $purchaseId, array $items): float
{
    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $hasRecv = orange_table_has_column($pdo, 'purchase_items', 'qty_received');
    $total = 0.0;
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صحيح');
        }
        $total += $qty * $cost;
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int)($item['variant_id'] ?? 0)
        );
        if ($hasV && $hasRecv) {
            $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, variant_id, qty, qty_received, cost) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$purchaseId, $productId, $variantId, $qty, $qty, $cost]);
            $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?')
                ->execute([$qty, $variantId, $productId]);
        } elseif ($hasV) {
            $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, variant_id, qty, cost) VALUES (?, ?, ?, ?, ?)'
            )->execute([$purchaseId, $productId, $variantId, $qty, $cost]);
            $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')
                ->execute([$qty, $variantId]);
        } elseif ($hasRecv) {
            $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, qty, qty_received, cost) VALUES (?, ?, ?, ?, ?)'
            )->execute([$purchaseId, $productId, $qty, $qty, $cost]);
            $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ? AND product_id = ?')
                ->execute([$qty, $variantId, $productId]);
        } else {
            $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, qty, cost) VALUES (?, ?, ?, ?)")
                ->execute([$purchaseId, $productId, $qty, $cost]);
            $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity + ? WHERE id = ?')
                ->execute([$qty, $variantId]);
        }
    }

    return $total;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $purchaseId = (int)($data['id'] ?? 0);
    $action = trim((string)($data['action'] ?? 'update'));
    if ($purchaseId <= 0) {
        json_response(['success' => false, 'message' => 'معرف عملية الشراء مطلوب'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ? LIMIT 1");
    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch();
    if (!$purchase) {
        json_response(['success' => false, 'message' => 'عملية الشراء غير موجودة'], 404);
    }

    $purRef = 'PUR-' . $purchaseId;
    $accRow = orange_accounting_row_by_reference($pdo, $purRef);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن تعديل أو حذف شراء مرتبط بسنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $pdo->beginTransaction();
    reverse_purchase_stock($pdo, $purchaseId);
    $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchaseId]);

    if ($action === 'delete') {
        orange_purchase_remove_receive_accounting($pdo, $purchaseId);
        orange_purchase_remove_accounting($pdo, $purRef);
        orange_gl_pending_remove_by_reference($pdo, $purRef);
        $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$purchaseId]);
        $pdo->commit();
        audit_log('purchase_delete', 'تم حذف فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
        json_response(['success' => true, 'message' => 'تم حذف عملية الشراء']);
    }

    $type = trim((string)($data['type'] ?? $purchase['type']));
    $supplierId = (int)($data['supplier_id'] ?? (int)$purchase['supplier_id']);
    $notes = trim((string)($data['notes'] ?? (string)$purchase['notes']));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    if (!in_array($type, ['cash', 'credit'], true) || count($items) === 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }

    if ($type === 'credit') {
        if ($supplierId <= 0) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => 'شراء آجل يتطلّب مورداً مربوطاً بحساب ذمة خاص في الدليل.'], 422);
        }
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
    if ($type === 'cash' && $supplierId > 0) {
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    $newTotal = apply_purchase_items($pdo, $purchaseId, $items);
    $pdo->prepare("UPDATE purchases SET supplier_id = ?, total = ?, type = ?, notes = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$supplierId > 0 ? $supplierId : null, $newTotal, $type, $notes, $purchaseId]);

    orange_purchase_remove_receive_accounting($pdo, $purchaseId);
    orange_purchase_remove_accounting($pdo, $purRef);
    orange_gl_pending_remove_by_reference($pdo, $purRef);

    $glB = orange_gl_purchase_invoice_posting_bundle(
        $pdo,
        $type,
        $supplierId,
        $purchaseId,
        $newTotal,
        false
    );
    $now = date('Y-m-d H:i:s');
    $afterJson = $glB['after_post'] !== null
        ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE)
        : null;

    if (orange_gl_use_pending_queue($pdo)) {
        if ($glB['is_multi']) {
            orange_gl_pending_enqueue_multi(
                $pdo,
                $glB['lines'],
                $purRef,
                $purRef,
                $now,
                $now,
                $glB['voucher_description'],
                'purchase',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $purRef,
                'source_label' => $purRef,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $newTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
                'after_post_json' => $afterJson,
            ]);
        }
    } else {
        if ($glB['is_multi']) {
            $vid = orange_voucher_post($pdo, [
                'voucher_date' => $now,
                'document_entered_at' => $now,
                'reference' => $purRef,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
            ], $glB['lines']);
            orange_gl_apply_voucher_after_post_hooks($pdo, $vid, $afterJson);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $newTotal,
                'reference' => $purRef,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
            ]);

            if ($glB['legacy_ap_subledger']) {
                orange_purchase_record_ap_subledger($pdo, $purchaseId, $supplierId, $type, $newTotal);
            }
        }
    }

    $pdo->commit();
    audit_log('purchase_update', 'تم تعديل فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم تعديل عملية الشراء']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر معالجة عملية الشراء');
}
