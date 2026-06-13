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
require_once __DIR__ . '/../../../includes/edit_lock.php';
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
 * يُعيد إدراج أصناف المردود (مع خصم السطر إن وُجد العمود) ويرجع **إجمالي صافي الأصناف**
 * (subtotal = مجموع الكمية×التكلفة − خصم السطر) — متسق مع create.php.
 */
function apply_purchase_return_items(PDO $pdo, int $returnId, array $items): float
{
    $hasV = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
    $hasDiscount = orange_table_has_column($pdo, 'purchase_return_items', 'discount_raw');
    $subtotal = 0.0;
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر مردود غير صحيح');
        }
        $lineGross = $qty * $cost;
        $discountRaw = trim((string) ($item['discount_raw'] ?? ''));
        $discountAmt = orange_purchase_return_parse_discount_amount($discountRaw, $lineGross);
        $subtotal += ($lineGross - $discountAmt);
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        orange_purchase_return_apply_line_stock($pdo, $productId, $variantId, $qty);
        $cols = ['purchase_return_id', 'product_id', 'qty', 'cost'];
        $vals = [$returnId, $productId, $qty, $cost];
        if ($hasV) {
            array_splice($cols, 2, 0, ['variant_id']);
            array_splice($vals, 2, 0, [$variantId]);
        }
        if ($hasDiscount) {
            $cols[] = 'discount_raw';
            $cols[] = 'discount_amount';
            $vals[] = $discountRaw;
            $vals[] = $discountAmt;
        }
        $pdo->prepare(
            'INSERT INTO purchase_return_items (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($vals), '?')) . ')'
        )->execute($vals);
    }

    return round($subtotal, 4);
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
    $admin = current_admin();
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $returnCountryLock = orange_edit_lock_country_for_purchase_return($pdo, $row);
    try {
        orange_edit_lock_assert_may_mutate(
            $pdo,
            $admin,
            'purchase_return',
            $returnId,
            $action === 'delete' ? 'delete' : 'edit',
            $returnCountryLock
        );
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }
    try {
        $prSupplierId = (int) ($row['supplier_id'] ?? 0);
        if ($prSupplierId > 0) {
            orange_admin_assert_entity_country($pdo, 'suppliers', $prSupplierId);
        }
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $accRow = orange_voucher_find_by_document($pdo, 'purchase_return', $returnId, 'purchase_return')
        ?? orange_accounting_row_by_reference($pdo, 'PR-' . $returnId);
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
        orange_purchase_return_remove_accounting($pdo, $returnId);
        orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('purchase_return', $returnId));
        $pdo->prepare('DELETE FROM purchase_returns WHERE id = ?')->execute([$returnId]);
        orange_edit_lock_unregister($pdo, 'purchase_return', $returnId, $returnCountryLock);
        orange_edit_lock_log_mutation($pdo, 'purchase_return', $returnId, 'delete');
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

    try {
        if ($supplierId > 0) {
            orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
        }
        if ($purchaseIdOpt > 0) {
            orange_admin_assert_entity_country($pdo, 'purchases', $purchaseIdOpt);
        }
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                orange_admin_assert_entity_country($pdo, 'products', $productId);
            }
        }
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $subtotal = apply_purchase_return_items($pdo, $returnId, $items);

    // خصم الفاتورة على المردود (يُعكَس كـ «خصم مكتسب» محاسبياً — لا يمسّ تكلفة المخزن).
    $hasRetInvDiscount = orange_table_has_column($pdo, 'purchase_returns', 'invoice_discount_raw');
    $hasRetSubtotal = orange_table_has_column($pdo, 'purchase_returns', 'subtotal');
    if (array_key_exists('invoice_discount_raw', $data)) {
        $invoiceDiscountRaw = trim((string) ($data['invoice_discount_raw'] ?? ''));
    } else {
        $invoiceDiscountRaw = trim((string) ($row['invoice_discount_raw'] ?? ''));
    }
    $invoiceDiscountAmt = orange_purchase_return_parse_discount_amount($invoiceDiscountRaw, $subtotal);
    $newTotal = round($subtotal - $invoiceDiscountAmt, 4);

    $setCols = ['purchase_id = ?', 'supplier_id = ?', 'type = ?', 'total = ?', 'notes = ?'];
    $params = [
        $purchaseIdOpt > 0 ? $purchaseIdOpt : null,
        $supplierId > 0 ? $supplierId : null,
        $type,
        $newTotal,
        $notes !== '' ? $notes : null,
    ];
    if ($hasRetSubtotal) {
        $setCols[] = 'subtotal = ?';
        $params[] = round($subtotal, 4);
    }
    if ($hasRetInvDiscount) {
        $setCols[] = 'invoice_discount_raw = ?';
        $params[] = $invoiceDiscountRaw;
        $setCols[] = 'invoice_discount_amount = ?';
        $params[] = $invoiceDiscountAmt;
    }
    $params[] = $returnId;
    $pdo->prepare('UPDATE purchase_returns SET ' . implode(', ', $setCols) . ' WHERE id = ?')
        ->execute($params);

    orange_purchase_return_remove_accounting($pdo, $returnId);
    orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('purchase_return', $returnId));

    $returnCountryId = orange_admin_context_country_id($pdo);
    if ($purchaseIdOpt > 0 && orange_table_has_country_id($pdo, 'purchases')) {
        $pc = $pdo->prepare('SELECT country_id FROM purchases WHERE id = ? LIMIT 1');
        $pc->execute([$purchaseIdOpt]);
        $returnCountryId = (int) ($pc->fetchColumn() ?: $returnCountryId);
    }
    if ($supplierId > 0 && orange_table_has_country_id($pdo, 'suppliers')) {
        $sc = $pdo->prepare('SELECT country_id FROM suppliers WHERE id = ? LIMIT 1');
        $sc->execute([$supplierId]);
        $returnCountryId = (int) ($sc->fetchColumn() ?: $returnCountryId);
    }

    $glB = orange_gl_purchase_return_posting_bundle($pdo, $type, $supplierId, $returnId, $newTotal, $returnCountryId);
    // عكس خصم الفاتورة المكتسب بحصة المردود (قيد مركّب) — دون مسّ تكلفة المخزن.
    $glB = orange_gl_purchase_apply_invoice_discount_lines(
        $pdo,
        $glB,
        $newTotal,
        $invoiceDiscountAmt,
        $returnCountryId,
        true
    );
    $pendingKey = orange_gl_pending_source_key('purchase_return', $returnId);
    $srcLabel = trim((string) ($row['return_number'] ?? ('PR-' . $returnId)));
    $now = date('Y-m-d H:i:s');
    $afterJson = $glB['after_post'] !== null
        ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE)
        : null;

    if (orange_gl_use_pending_queue($pdo)) {
        if ($glB['is_multi']) {
            orange_gl_pending_enqueue_multi(
                $pdo,
                $glB['lines'],
                $pendingKey,
                $srcLabel,
                $now,
                $now,
                $glB['voucher_description'],
                'purchase_return',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingKey,
                'source_label' => $srcLabel,
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
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
                'country_id' => $returnCountryId,
            ], $glB['lines']);
            orange_gl_apply_voucher_after_post_hooks($pdo, $vid, $afterJson);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $newTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
            ]);
            if ($glB['legacy_ap_subledger']) {
                orange_purchase_return_record_ap_subledger($pdo, $returnId, $supplierId, $type, $newTotal);
            }
        }
    }

    $pdo->commit();
    orange_edit_lock_register_purchase_return(
        $pdo,
        $returnId,
        $returnCountryId > 0 ? $returnCountryId : null,
        $newTotal,
        $srcLabel,
        $now
    );
    orange_edit_lock_log_mutation($pdo, 'purchase_return', $returnId, 'edit');
    audit_log('purchase_return_update', 'تم تعديل مردود مشتريات رقم: ' . $returnId, 'purchase_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم تحديث مردود المشتريات']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تحديث مردود المشتريات');
}
