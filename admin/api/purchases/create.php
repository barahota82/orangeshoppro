<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $supplierId = (int)($data['supplier_id'] ?? 0);
    $type = trim((string)($data['type'] ?? ''));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $notes = trim((string)($data['notes'] ?? ''));
    $supplierInvoiceNumber = trim((string)($data['supplier_invoice_number'] ?? ''));
    if ($supplierInvoiceNumber !== '' && mb_strlen($supplierInvoiceNumber) > 64) {
        json_response(['success' => false, 'message' => 'رقم فاتورة المورد طويل جداً (64 حرفاً كحد أقصى)'], 422);
    }

    if (!in_array($type, ['cash', 'credit'], true) || count($items) === 0) {
        json_response(['success' => false, 'message' => 'بيانات الشراء غير صحيحة'], 422);
    }

    if ($supplierId > 0) {
        try {
            orange_supplier_assert_active_for_purchase($pdo, $supplierId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    if ($type === 'credit') {
        if ($supplierId <= 0) {
            json_response(['success' => false, 'message' => 'شراء آجل يتطلّب مورداً مربوطاً بحساب ذمة خاص في الدليل.'], 422);
        }
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

    $hasPiDiscount = orange_table_has_column($pdo, 'purchase_items', 'discount_raw');
    $hasInvDiscount = orange_table_has_column($pdo, 'purchases', 'invoice_discount_raw');
    $hasSubtotal = orange_table_has_column($pdo, 'purchases', 'subtotal');

    $computedTotal = 0.0;
    foreach ($items as &$item) {
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر شراء غير صالح');
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
        $item['_discount_raw'] = $discountRaw;
        $item['_discount_amount'] = $discountAmt;
        $computedTotal += ($lineGross - $discountAmt);
    }
    unset($item);

    $subtotal = $computedTotal;
    $invoiceDiscountRaw = trim((string)($data['invoice_discount_raw'] ?? ''));
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
    $netTotal = $subtotal - $invoiceDiscountAmt;

    $purchaseCountryId = orange_admin_context_country_id($pdo);

    // تاريخ الفاتورة/المستند القابل للضبط = تاريخ ترحيل القيد المحاسبي (منفصل عن created_at للتدقيق).
    $documentDate = trim((string)($data['document_date'] ?? ''));
    if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $documentDate = date('Y-m-d');
    }
    orange_fiscal_require_open_for_posting($pdo, $documentDate, $purchaseCountryId);
    $postingAt = $documentDate . ' ' . date('H:i:s');

    $hasSupplierInvoiceCol = orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number');
    $insertCols = 'supplier_id, total, type, notes';
    $insertPlaceholders = '?, ?, ?, ?';
    $insertValues = [$supplierId > 0 ? $supplierId : null, $netTotal, $type, $notes];
    if ($hasSupplierInvoiceCol) {
        $insertCols = 'supplier_id, supplier_invoice_number, total, type, notes';
        $insertPlaceholders = '?, ?, ?, ?, ?';
        array_splice($insertValues, 1, 0, [$supplierInvoiceNumber !== '' ? $supplierInvoiceNumber : null]);
    }
    if (orange_table_has_country_id($pdo, 'purchases') && $purchaseCountryId > 0) {
        $insertCols .= ', country_id';
        $insertPlaceholders .= ', ?';
        $insertValues[] = $purchaseCountryId;
    }
    if ($hasSubtotal) {
        $insertCols .= ', subtotal';
        $insertPlaceholders .= ', ?';
        $insertValues[] = $subtotal;
    }
    if ($hasInvDiscount) {
        $insertCols .= ', invoice_discount_raw, invoice_discount_amount';
        $insertPlaceholders .= ', ?, ?';
        $insertValues[] = $invoiceDiscountRaw;
        $insertValues[] = $invoiceDiscountAmt;
    }
    if (orange_table_has_column($pdo, 'purchases', 'document_date')) {
        $insertCols .= ', document_date';
        $insertPlaceholders .= ', ?';
        $insertValues[] = $documentDate;
    }
    orange_sql_append_document_currency_code(
        $pdo,
        'purchases',
        $purchaseCountryId,
        $insertCols,
        $insertPlaceholders,
        $insertValues
    );
    $stmt = $pdo->prepare("INSERT INTO purchases ($insertCols) VALUES ($insertPlaceholders)");
    $stmt->execute($insertValues);
    $purchaseId = (int)$pdo->lastInsertId();

    $hasPiVariant = orange_table_has_column($pdo, 'purchase_items', 'variant_id');
    $hasPiQtyReceived = orange_table_has_column($pdo, 'purchase_items', 'qty_received');

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        $cost = (float)($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر شراء غير مكتمل');
        }

        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int)($item['variant_id'] ?? 0)
        );

        $lineDiscRaw = (string)($item['_discount_raw'] ?? '');
        $lineDiscAmt = (float)($item['_discount_amount'] ?? 0);

        if ($hasPiVariant && $hasPiQtyReceived) {
            $sql = 'INSERT INTO purchase_items (purchase_id, product_id, variant_id, qty, qty_received, cost';
            $vals = [$purchaseId, $productId, $variantId, $qty, $qty, $cost];
            if ($hasPiDiscount) {
                $sql .= ', discount_raw, discount_amount';
                $vals[] = $lineDiscRaw;
                $vals[] = $lineDiscAmt;
            }
            $sql .= ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            orange_purchase_apply_variant_stock_increase($pdo, $variantId, $qty, $purchaseCountryId);
        } elseif ($hasPiVariant) {
            $sql = 'INSERT INTO purchase_items (purchase_id, product_id, variant_id, qty, cost';
            $vals = [$purchaseId, $productId, $variantId, $qty, $cost];
            if ($hasPiDiscount) {
                $sql .= ', discount_raw, discount_amount';
                $vals[] = $lineDiscRaw;
                $vals[] = $lineDiscAmt;
            }
            $sql .= ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            orange_purchase_apply_variant_stock_increase($pdo, $variantId, $qty, $purchaseCountryId);
        } elseif ($hasPiQtyReceived) {
            $sql = 'INSERT INTO purchase_items (purchase_id, product_id, qty, qty_received, cost';
            $vals = [$purchaseId, $productId, $qty, $qty, $cost];
            if ($hasPiDiscount) {
                $sql .= ', discount_raw, discount_amount';
                $vals[] = $lineDiscRaw;
                $vals[] = $lineDiscAmt;
            }
            $sql .= ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            orange_purchase_apply_variant_stock_increase($pdo, $variantId, $qty, $purchaseCountryId);
        } else {
            $sql = 'INSERT INTO purchase_items (purchase_id, product_id, qty, cost';
            $vals = [$purchaseId, $productId, $qty, $cost];
            if ($hasPiDiscount) {
                $sql .= ', discount_raw, discount_amount';
                $vals[] = $lineDiscRaw;
                $vals[] = $lineDiscAmt;
            }
            $sql .= ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            $pdo->prepare($sql)->execute($vals);
            orange_purchase_apply_variant_stock_increase($pdo, $variantId, $qty, $purchaseCountryId);
        }
    }

    // قرار المالك (2026-06): أُلغيت «البنود الإضافية» على المشتريات؛ مصاريف التوريد/الشحن
    // والخصم المكتسب تُسجَّل بقيد يدوي من المحاسبة. القيد هنا بسيط على صافي الأصناف.
    $payableTotal = (float) $netTotal;

    $glB = orange_gl_purchase_invoice_posting_bundle(
        $pdo,
        $type,
        $supplierId,
        $purchaseId,
        $netTotal,
        $purchaseCountryId
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
    audit_log('purchase_create', 'تم إنشاء فاتورة شراء رقم: ' . $purchaseId, 'purchases', $purchaseId);
    $voucherLinks = orange_gl_posting_voucher_links($pdo, 'purchase', $purchaseId, [
        ['entry_type' => 'purchase', 'journal_type_code' => 'PIN', 'label' => 'قيد فاتورة الشراء'],
    ], $purchaseCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ عملية الشراء',
        'purchase_id' => $purchaseId,
        'voucher_links' => $voucherLinks,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ عملية الشراء');
}
