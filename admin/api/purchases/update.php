<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/inventory_cost_layers.php';
require_admin_api();

function reverse_purchase_stock(PDO $pdo, int $purchaseId, int $countryId): void
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
        orange_purchase_apply_variant_stock_decrease($pdo, $vid, $qty, $countryId);
    }
}

/**
 * يُعيد إدراج أصناف الشراء (مع خصم السطر إن وُجد العمود) ويرجع **إجمالي صافي الأصناف**
 * (subtotal = مجموع الكمية×التكلفة − خصم السطر) — متسق مع create.php.
 */
function apply_purchase_items(PDO $pdo, int $purchaseId, array $items, int $countryId, string $postingAt): float
{
    $hasV = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $hasRecv = orange_table_has_column($pdo, 'purchase_items', 'qty_received');
    $hasDiscount = orange_table_has_column($pdo, 'purchase_items', 'discount_raw');
    // FIFO م2: إعادة بناء طبقات التكلفة + تحديث «آخر تكلفة شراء» الإرشادية.
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    $hasProductsCost = orange_table_has_column($pdo, 'products', 'cost');
    $updProductCost = $hasProductsCost
        ? $pdo->prepare('UPDATE products SET cost = ? WHERE id = ?')
        : null;
    $subtotal = 0.0;
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صحيح');
        }
        $lineGross = $qty * $cost;
        $discountRaw = trim((string)($item['discount_raw'] ?? ''));
        $discountAmt = 0.0;
        if ($discountRaw !== '') {
            if (str_ends_with($discountRaw, '%')) {
                $pct = (float) rtrim($discountRaw, '%');
                $discountAmt = round($lineGross * $pct / 100, 4);
            } else {
                $discountAmt = round((float) $discountRaw, 4);
            }
            if ($discountAmt < 0) {
                $discountAmt = 0.0;
            }
            if ($discountAmt > $lineGross) {
                $discountAmt = $lineGross;
            }
        }
        $subtotal += ($lineGross - $discountAmt);
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int)($item['variant_id'] ?? 0)
        );
        $cols = ['purchase_id', 'product_id', 'qty', 'cost'];
        $vals = [$purchaseId, $productId, $qty, $cost];
        if ($hasV) {
            array_splice($cols, 2, 0, ['variant_id']);
            array_splice($vals, 2, 0, [$variantId]);
        }
        if ($hasRecv) {
            $cols[] = 'qty_received';
            $vals[] = $qty;
        }
        if ($hasDiscount) {
            $cols[] = 'discount_raw';
            $cols[] = 'discount_amount';
            $vals[] = $discountRaw;
            $vals[] = $discountAmt;
        }
        $pdo->prepare(
            'INSERT INTO purchase_items (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($vals), '?')) . ')'
        )->execute($vals);
        orange_purchase_apply_variant_stock_increase($pdo, $variantId, $qty, $countryId);

        $lineNetUnit = $qty > 0 ? round((($qty * $cost) - $discountAmt) / $qty, 5) : round($cost, 5);
        orange_inventory_cost_layer_add(
            $pdo,
            $warehouseId,
            $variantId,
            $qty,
            $lineNetUnit,
            'purchase',
            $purchaseId,
            $countryId,
            $postingAt,
            'PIN-' . $purchaseId
        );
        if ($updProductCost !== null) {
            $updProductCost->execute([$lineNetUnit, $productId]);
        }
    }

    return $subtotal;
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
    try {
        orange_admin_assert_entity_country($pdo, 'purchases', $purchaseId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    require_once __DIR__ . '/../../../includes/countries.php';
    $purchaseCountryId = (int) ($purchase['country_id'] ?? 0);
    if ($purchaseCountryId <= 0) {
        $purchaseCountryId = orange_admin_context_country_id($pdo);
    }

    $adminRow = current_admin();
    if ($adminRow) {
        try {
            orange_edit_lock_assert_may_mutate(
                $pdo,
                $adminRow,
                'purchase',
                $purchaseId,
                $action === 'delete' ? 'delete' : 'edit',
                $purchaseCountryId > 0 ? $purchaseCountryId : null
            );
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    $accRow = orange_voucher_find_by_document($pdo, 'purchase', $purchaseId, 'purchase', $purchaseCountryId > 0 ? $purchaseCountryId : null)
        ?? orange_accounting_row_by_reference($pdo, 'PUR-' . $purchaseId);
    if (orange_accounting_is_locked($pdo, $accRow)) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن تعديل أو حذف شراء مرتبط بسنة مالية مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $pdo->beginTransaction();
    reverse_purchase_stock($pdo, $purchaseId, $purchaseCountryId);
    $pdo->prepare("DELETE FROM purchase_items WHERE purchase_id = ?")->execute([$purchaseId]);
    // FIFO م2: نحذف طبقات هذه الفاتورة قبل إعادة بنائها (آمن — لا استهلاك قبل م3).
    orange_inventory_cost_layers_delete_for_source($pdo, 'purchase', $purchaseId);

    if ($action === 'delete') {
        orange_purchase_remove_receive_accounting($pdo, $purchaseId);
        orange_purchase_remove_accounting($pdo, $purchaseId, $purchaseCountryId > 0 ? $purchaseCountryId : null);
        orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('purchase', $purchaseId));
        orange_invoice_ancillary_extra_lines_delete_for_doc(
            $pdo,
            orange_invoice_ancillary_doc_kind_purchase(),
            $purchaseId
        );
        $pdo->prepare("DELETE FROM purchases WHERE id = ?")->execute([$purchaseId]);
        $pdo->commit();
        audit_log('purchase_delete', 'تم حذف فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
        json_response(['success' => true, 'message' => 'تم حذف عملية الشراء']);
    }

    $type = trim((string)($data['type'] ?? $purchase['type']));
    $supplierId = (int)($data['supplier_id'] ?? (int)$purchase['supplier_id']);
    $notes = trim((string)($data['notes'] ?? (string)$purchase['notes']));
    $hasSupplierInvoiceCol = orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number');
    $supplierInvoiceNumber = array_key_exists('supplier_invoice_number', $data)
        ? trim((string)($data['supplier_invoice_number'] ?? ''))
        : trim((string)($purchase['supplier_invoice_number'] ?? ''));
    if ($supplierInvoiceNumber !== '' && mb_strlen($supplierInvoiceNumber) > 64) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'رقم فاتورة المورد طويل جداً (64 حرفاً كحد أقصى)'], 422);
    }
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    if (!in_array($type, ['cash', 'credit'], true) || count($items) === 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }

    if ($supplierId > 0) {
        try {
            orange_supplier_assert_active_for_purchase($pdo, $supplierId);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
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

    // تاريخ الفاتورة/المستند = تاريخ ترحيل القيد المحاسبي (يُحفظ عند التعديل، ويُحترم في السنة المالية).
    $documentDate = '';
    if (array_key_exists('document_date', $data)) {
        $documentDate = trim((string)($data['document_date'] ?? ''));
    }
    if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $existingDocDate = trim((string)($purchase['document_date'] ?? ''));
        $documentDate = ($existingDocDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $existingDocDate))
            ? substr($existingDocDate, 0, 10)
            : date('Y-m-d');
    }
    orange_fiscal_require_open_for_posting($pdo, $documentDate, $purchaseCountryId);
    $postingAt = $documentDate . ' ' . date('H:i:s');
    $hasDocumentDateCol = orange_table_has_column($pdo, 'purchases', 'document_date');

    $subtotal = apply_purchase_items($pdo, $purchaseId, $items, $purchaseCountryId, $postingAt);

    // خصم الفاتورة (يُعالَج كـ «خصم مكتسب» محاسبياً — لا يمسّ تكلفة المخزن).
    $hasInvDiscount = orange_table_has_column($pdo, 'purchases', 'invoice_discount_raw');
    $hasSubtotalCol = orange_table_has_column($pdo, 'purchases', 'subtotal');
    if (array_key_exists('invoice_discount_raw', $data)) {
        $invoiceDiscountRaw = trim((string)($data['invoice_discount_raw'] ?? ''));
    } else {
        $invoiceDiscountRaw = trim((string)($purchase['invoice_discount_raw'] ?? ''));
    }
    $invoiceDiscountAmt = 0.0;
    if ($invoiceDiscountRaw !== '') {
        if (str_ends_with($invoiceDiscountRaw, '%')) {
            $pct = (float) rtrim($invoiceDiscountRaw, '%');
            $invoiceDiscountAmt = round($subtotal * $pct / 100, 4);
        } else {
            $invoiceDiscountAmt = round((float) $invoiceDiscountRaw, 4);
        }
        if ($invoiceDiscountAmt < 0) {
            $invoiceDiscountAmt = 0.0;
        }
        if ($invoiceDiscountAmt > $subtotal) {
            $invoiceDiscountAmt = $subtotal;
        }
    }
    $newTotal = round($subtotal - $invoiceDiscountAmt, 4);

    $setCols = ['supplier_id = ?'];
    $params = [$supplierId > 0 ? $supplierId : null];
    if ($hasSupplierInvoiceCol) {
        $setCols[] = 'supplier_invoice_number = ?';
        $params[] = $supplierInvoiceNumber !== '' ? $supplierInvoiceNumber : null;
    }
    $setCols[] = 'total = ?';
    $params[] = $newTotal;
    $setCols[] = 'type = ?';
    $params[] = $type;
    $setCols[] = 'notes = ?';
    $params[] = $notes;
    if ($hasSubtotalCol) {
        $setCols[] = 'subtotal = ?';
        $params[] = round($subtotal, 4);
    }
    if ($hasInvDiscount) {
        $setCols[] = 'invoice_discount_raw = ?';
        $params[] = $invoiceDiscountRaw;
        $setCols[] = 'invoice_discount_amount = ?';
        $params[] = $invoiceDiscountAmt;
    }
    if ($hasDocumentDateCol) {
        $setCols[] = 'document_date = ?';
        $params[] = $documentDate;
    }
    $setCols[] = 'updated_at = NOW()';
    $params[] = $purchaseId;
    $pdo->prepare('UPDATE purchases SET ' . implode(', ', $setCols) . ' WHERE id = ?')
        ->execute($params);

    // قرار المالك (2026-06): أُلغيت «البنود الإضافية» على المشتريات. نحذف أي بنود قديمة
    // محفوظة لهذه الفاتورة حتى لا يبقى أثر لا يقابله ترحيل، والقيد يصبح بسيطاً على صافي الأصناف.
    orange_invoice_ancillary_extra_lines_delete_for_doc(
        $pdo,
        orange_invoice_ancillary_doc_kind_purchase(),
        $purchaseId
    );
    $payableTotal = (float) $newTotal;

    orange_purchase_remove_receive_accounting($pdo, $purchaseId);
    orange_purchase_remove_accounting($pdo, $purchaseId, $purchaseCountryId > 0 ? $purchaseCountryId : null);
    orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('purchase', $purchaseId));

    $glB = orange_gl_purchase_invoice_posting_bundle(
        $pdo,
        $type,
        $supplierId,
        $purchaseId,
        $newTotal,
        $purchaseCountryId
    );
    // خصم الفاتورة → «خصم مكتسب على المشتريات» (قيد مركّب) دون مسّ تكلفة المخزن.
    $glB = orange_gl_purchase_apply_invoice_discount_lines(
        $pdo,
        $glB,
        $newTotal,
        $invoiceDiscountAmt,
        $purchaseCountryId,
        false
    );
    $pendingKey = orange_gl_pending_source_key('purchase', $purchaseId);
    $srcLabel = 'PIN-' . $purchaseId;
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
                $postingAt,
                $postingAt,
                $glB['voucher_description'],
                'purchase',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingKey,
                'source_label' => $srcLabel,
                'movement_at' => $postingAt,
                'voucher_date' => $postingAt,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $payableTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
                'after_post_json' => $afterJson,
            ]);
        }
    } else {
        if ($glB['is_multi']) {
            $vid = orange_voucher_post($pdo, [
                'voucher_date' => $postingAt,
                'document_entered_at' => $now,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
                'country_id' => $purchaseCountryId,
            ], $glB['lines']);
            orange_gl_apply_voucher_after_post_hooks($pdo, $vid, $afterJson);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $postingAt,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $payableTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase',
            ]);

            if ($glB['legacy_ap_subledger']) {
                orange_purchase_record_ap_subledger($pdo, $purchaseId, $supplierId, $type, $payableTotal);
            }
        }
    }

    $pdo->commit();
    orange_edit_lock_register_purchase($pdo, $purchaseId, $purchaseCountryId, $payableTotal, $now);
    orange_edit_lock_log_mutation($pdo, 'purchase', $purchaseId, 'edit');
    audit_log('purchase_update', 'تم تعديل فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    json_response(['success' => true, 'message' => 'تم تعديل عملية الشراء']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر معالجة عملية الشراء');
}
