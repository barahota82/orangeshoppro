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
require_once __DIR__ . '/../../../includes/gl_voucher_slot.php';
require_once __DIR__ . '/../../../includes/journal_types.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/inventory_cost_layers.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
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
    if ($purchaseCountryId <= 0) {
        json_response([
            'success' => false,
            'code' => 'admin_time_country_id_required',
            'message' => 'دولة السياق مطلوبة لإنشاء فاتورة شراء',
        ], 422);
    }

    // تاريخ الفاتورة/المستند = Date-only لليوم المحلي لدولة المستند (منفصل عن created_at UTC).
    $documentDate = trim((string)($data['document_date'] ?? ''));
    if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        try {
            $documentDate = orange_admin_time_document_date_today_for_country_id($pdo, $purchaseCountryId);
        } catch (OrangeAdminTimeConfigException $e) {
            json_response([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => 'تعذر تحديد التاريخ المحلي: منطقة زمنية الدولة غير مضبوطة.',
            ], 422);
        }
    }
    $documentDate = orange_admin_time_date_only_normalize($documentDate);
    if ($documentDate === '') {
        json_response(['success' => false, 'message' => 'تاريخ المستند غير صالح'], 422);
    }
    orange_fiscal_require_open_for_posting($pdo, $documentDate, $purchaseCountryId);
    // GL/pending movement wall — deferred Step 4 (لا نغيّر دلالة ترحيل القيد هنا).
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
    // Absolute Moment: UTC wall في DATETIME (لا تعتمد على DEFAULT/CURRENT_TIMESTAMP الجدارية).
    if (orange_table_has_column($pdo, 'purchases', 'created_at')) {
        $insertCols .= ', created_at';
        $insertPlaceholders .= ', ?';
        $insertValues[] = orange_admin_time_utc_now_mysql();
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

    // FIFO م2: طبقة تكلفة لكل سطر (صافي بعد خصم السطر) + تحديث «آخر تكلفة شراء» الإرشادية في البطاقة.
    $purchaseWarehouseId = orange_warehouse_default_id_for_country($pdo, $purchaseCountryId);
    $hasProductsCost = orange_table_has_column($pdo, 'products', 'cost');
    $updProductCost = $hasProductsCost
        ? $pdo->prepare('UPDATE products SET cost = ? WHERE id = ?')
        : null;
    // «آخر تكلفة شراء» إرشادية على مستوى المتغيّر أيضاً (تغذّي fallback السعر/التكلفة الموثوق).
    $hasVariantCost = orange_table_has_column($pdo, 'product_variants', 'cost');
    $updVariantCost = $hasVariantCost
        ? $pdo->prepare('UPDATE product_variants SET cost = ? WHERE id = ?')
        : null;

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
        $lineNetUnit = $qty > 0 ? round((($qty * $cost) - $lineDiscAmt) / $qty, 5) : round($cost, 5);

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

        // FIFO: طبقة واردة بصافي تكلفة الوحدة (خصم الفاتورة لا يدخل — يبقى «خصم مكتسب»).
        orange_inventory_cost_layer_add(
            $pdo,
            $purchaseWarehouseId,
            $variantId,
            $qty,
            $lineNetUnit,
            'purchase',
            $purchaseId,
            $purchaseCountryId,
            $postingAt,
            'PIN-' . $purchaseId
        );
        // بطاقة الصنف: «آخر تكلفة شراء» إرشادية فقط (لا أثر تقييمي بعد م3).
        if ($updProductCost !== null) {
            $updProductCost->execute([$lineNetUnit, $productId]);
        }
        if ($updVariantCost !== null && $variantId > 0) {
            $updVariantCost->execute([$lineNetUnit, $variantId]);
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
    // خصم الفاتورة → «خصم مكتسب على المشتريات» (قيد مركّب) دون مسّ تكلفة المخزن.
    $glB = orange_gl_purchase_apply_invoice_discount_lines(
        $pdo,
        $glB,
        $netTotal,
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
        $pinJtId = orange_journal_type_id_by_code($pdo, 'PIN', $purchaseCountryId);
        $slotSpec = [
            'doc_kind' => 'purchase',
            'entity_id' => $purchaseId,
            'slot_key' => 'main',
            'entry_type' => 'purchase',
            'country_id' => $purchaseCountryId,
            'journal_type_id' => $pinJtId > 0 ? $pinJtId : null,
        ];
        $vHeader = [
            'voucher_date' => $postingAt,
            'document_entered_at' => $now,
            'description' => $glB['voucher_description'],
            'entry_type' => 'purchase',
            'country_id' => $purchaseCountryId,
        ];
        if ($pinJtId > 0) {
            $vHeader['journal_type_id'] = $pinJtId;
        }
        orange_gl_voucher_immediate_post_bundle_for_slot(
            $pdo,
            $slotSpec,
            $vHeader,
            $glB,
            $payableTotal,
            $afterJson
        );
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
