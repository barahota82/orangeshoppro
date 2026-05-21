<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_return_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

function reverse_purchase_return_stock(PDO $pdo, int $returnId): void
{
    $hasV = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
    $cols = 'product_id, qty';
    if ($hasV) {
        $cols .= ', variant_id';
    }
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM purchase_return_items WHERE purchase_return_id = ?');
    $st->execute([$returnId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        $vid = $hasV ? (int) ($row['variant_id'] ?? 0) : 0;
        if ($vid <= 0) {
            $vid = orange_purchase_resolve_variant_id($pdo, $pid, 0);
        }
        orange_purchase_return_restore_line_stock($pdo, $pid, $vid, $qty);
    }
}

/**
 * @return float الإجمالي المحسوب
 */
function apply_purchase_return_items(PDO $pdo, int $returnId, array $items): float
{
    $hasV = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
    $total = 0.0;
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر مردود غير صحيح');
        }
        $total += $qty * $cost;
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        orange_purchase_return_apply_line_stock($pdo, $productId, $variantId, $qty);
        if ($hasV) {
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

    return round($total, 4);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $returnId = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? 'update'));
    if ($returnId <= 0) {
        json_response(['success' => false, 'message' => 'معرف مردود المشتريات مطلوب'], 422);
    }

    $st = $pdo->prepare('SELECT * FROM purchase_returns WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'مردود المشتريات غير موجود'], 404);
    }
    try {
        $prSupplierId = (int) ($row['supplier_id'] ?? 0);
        if ($prSupplierId > 0) {
            orange_admin_assert_entity_country($pdo, 'suppliers', $prSupplierId);
        }
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $retRef = 'PR-' . $returnId;
    $accRow = orange_accounting_row_by_reference($pdo, $retRef);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن تعديل أو حذف مردود مرتبط بسنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $pdo->beginTransaction();
    reverse_purchase_return_stock($pdo, $returnId);
    $pdo->prepare('DELETE FROM purchase_return_items WHERE purchase_return_id = ?')->execute([$returnId]);

    if ($action === 'delete') {
        orange_purchase_return_remove_accounting($pdo, $retRef);
        orange_gl_pending_remove_by_reference($pdo, $retRef);
        $pdo->prepare('DELETE FROM purchase_returns WHERE id = ?')->execute([$returnId]);
        $pdo->commit();
        audit_log('purchase_return_delete', 'تم حذف مردود مشتريات رقم: ' . $returnId, 'purchase_returns', $returnId);
        json_response(['success' => true, 'message' => 'تم حذف مردود المشتريات']);
    }

    $type = trim((string) ($data['type'] ?? (string) ($row['type'] ?? 'credit')));
    $supplierId = (int) ($data['supplier_id'] ?? (int) ($row['supplier_id'] ?? 0));
    $purchaseIdOpt = (int) ($data['purchase_id'] ?? (int) ($row['purchase_id'] ?? 0));
    $notes = trim((string) ($data['notes'] ?? (string) ($row['notes'] ?? '')));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

    if (!in_array($type, ['cash', 'credit'], true) || $items === []) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }

    if ($type === 'credit' && $supplierId <= 0) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'مردود آجل يتطلّب مورداً مربوطاً بحساب ذمة خاص في الدليل.'], 422);
    }
    if ($type === 'credit') {
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
    if ($type === 'cash' && $supplierId > 0) {
        try {
            orange_supplier_required_payable_account_id($pdo, $supplierId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    if ($purchaseIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE id = ? LIMIT 1');
        $chk->execute([$purchaseIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'فاتورة الشراء المرجعية غير موجودة'], 422);
        }
    }

    $newTotal = apply_purchase_return_items($pdo, $returnId, $items);
    $pdo->prepare(
        'UPDATE purchase_returns SET purchase_id = ?, supplier_id = ?, type = ?, total = ?, notes = ? WHERE id = ?'
    )->execute([
        $purchaseIdOpt > 0 ? $purchaseIdOpt : null,
        $supplierId > 0 ? $supplierId : null,
        $type,
        $newTotal,
        $notes !== '' ? $notes : null,
        $returnId,
    ]);

    orange_purchase_return_remove_accounting($pdo, $retRef);
    orange_gl_pending_remove_by_reference($pdo, $retRef);

    $glB = orange_gl_purchase_return_posting_bundle($pdo, $type, $supplierId, $returnId, $newTotal);
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
                'amount' => $newTotal,
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
                'amount' => $newTotal,
                'reference' => $retRef,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
            ]);
            if ($glB['legacy_ap_subledger']) {
                orange_purchase_return_record_ap_subledger($pdo, $returnId, $supplierId, $type, $newTotal);
            }
        }
    }

    $pdo->commit();
    audit_log('purchase_return_update', 'تم تعديل مردود مشتريات رقم: ' . $returnId, 'purchase_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم تحديث مردود المشتريات']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تحديث مردود المشتريات');
}
