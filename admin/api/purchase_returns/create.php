<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/purchase_return_helpers.php';
require_once __DIR__ . '/../../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../../includes/purchase_gl_accounts.php';
require_once __DIR__ . '/../../../includes/gl_voucher_slot.php';
require_once __DIR__ . '/../../../includes/journal_types.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/inventory_cost_layers.php';
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

    $returnCountryId = orange_admin_context_country_id($pdo);

    if ($purchaseIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM purchases WHERE id = ? LIMIT 1');
        $chk->execute([$purchaseIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'فاتورة الشراء المرجعية غير موجودة'], 422);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'purchases', $purchaseIdOpt);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if (orange_table_has_country_id($pdo, 'purchases')) {
            $pc = $pdo->prepare('SELECT country_id FROM purchases WHERE id = ? LIMIT 1');
            $pc->execute([$purchaseIdOpt]);
            $returnCountryId = (int) ($pc->fetchColumn() ?: $returnCountryId);
        }
    }

    $hasPriDiscount = orange_table_has_column($pdo, 'purchase_return_items', 'discount_raw');
    $hasRetInvDiscount = orange_table_has_column($pdo, 'purchase_returns', 'invoice_discount_raw');
    $hasRetSubtotal = orange_table_has_column($pdo, 'purchase_returns', 'subtotal');

    try {
        if ($purchaseIdOpt > 0) {
            orange_purchase_return_lock_reference_purchase($pdo, $purchaseIdOpt);
        }
        orange_purchase_return_assert_qty_against_purchase($pdo, $purchaseIdOpt, $items);
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }

    $computedTotal = 0.0;
    foreach ($items as &$item) {
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($qty <= 0 || $cost < 0) {
            throw new RuntimeException('عنصر مردود غير صالح');
        }
        $lineGross = $qty * $cost;
        $discountRaw = trim((string) ($item['discount_raw'] ?? ''));
        $discountAmt = orange_purchase_return_parse_discount_amount($discountRaw, $lineGross);
        $item['_discount_raw'] = $discountRaw;
        $item['_discount_amount'] = $discountAmt;
        $computedTotal += ($lineGross - $discountAmt);
    }
    unset($item);

    $subtotal = $computedTotal;
    $invoiceDiscountRaw = trim((string) ($data['invoice_discount_raw'] ?? ''));
    $invoiceDiscountAmt = orange_purchase_return_parse_discount_amount($invoiceDiscountRaw, $subtotal);
    $netTotal = round($subtotal - $invoiceDiscountAmt, 4);

    if ($supplierId > 0) {
        try {
            orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if (orange_table_has_country_id($pdo, 'suppliers')) {
            $sc = $pdo->prepare('SELECT country_id FROM suppliers WHERE id = ? LIMIT 1');
            $sc->execute([$supplierId]);
            $returnCountryId = (int) ($sc->fetchColumn() ?: $returnCountryId);
        }
    }

    // تاريخ المستند (مردود المشتريات) = تاريخ ترحيل القيد المحاسبي (منفصل عن created_at).
    $documentDate = trim((string)($data['document_date'] ?? ''));
    if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $documentDate = date('Y-m-d');
    }
    orange_fiscal_require_open_for_posting($pdo, $documentDate, $returnCountryId);
    $postingAt = $documentDate . ' ' . date('H:i:s');

    $tmpNum = 'PR-TMP-' . bin2hex(random_bytes(6));
    $insertCols = 'return_number, purchase_id, supplier_id, type, total, notes';
    $insertPlaceholders = '?,?,?,?,?,?';
    $insertValues = [
        $tmpNum,
        $purchaseIdOpt > 0 ? $purchaseIdOpt : null,
        $supplierId > 0 ? $supplierId : null,
        $type,
        $netTotal,
        $notes !== '' ? $notes : null,
    ];
    if (orange_table_has_column($pdo, 'purchase_returns', 'document_date')) {
        $insertCols .= ', document_date';
        $insertPlaceholders .= ', ?';
        $insertValues[] = $documentDate;
    }
    if ($hasRetSubtotal) {
        $insertCols .= ', subtotal';
        $insertPlaceholders .= ', ?';
        $insertValues[] = round($subtotal, 4);
    }
    if ($hasRetInvDiscount) {
        $insertCols .= ', invoice_discount_raw, invoice_discount_amount';
        $insertPlaceholders .= ', ?, ?';
        $insertValues[] = $invoiceDiscountRaw;
        $insertValues[] = $invoiceDiscountAmt;
    }
    $pdo->prepare("INSERT INTO purchase_returns ($insertCols) VALUES ($insertPlaceholders)")
        ->execute($insertValues);
    $returnId = (int) $pdo->lastInsertId();
    if (orange_table_has_column($pdo, 'purchase_returns', 'currency_code')) {
        $retCur = orange_gl_functional_currency_code($pdo, $returnCountryId);
        $pdo->prepare('UPDATE purchase_returns SET currency_code = ? WHERE id = ?')->execute([$retCur, $returnId]);
    }
    $retRef = orange_country_document_ref($pdo, 'PR', $returnId, $returnCountryId);
    $pdo->prepare('UPDATE purchase_returns SET return_number = ? WHERE id = ?')->execute([$retRef, $returnId]);

    $hasVariant = orange_table_has_column($pdo, 'purchase_return_items', 'variant_id');
    // FIFO م2: مردود المشتريات يخفّض طبقات المخزون (طبقة الشراء الأصلية أولاً ثم الأحدث).
    $returnWarehouseId = orange_warehouse_default_id_for_country($pdo, $returnCountryId);

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        $cost = (float) ($item['cost'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير مكتمل');
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $productId);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        orange_purchase_return_apply_line_stock($pdo, $productId, $variantId, $qty);

        // FIFO: نخفّض طبقات فاتورة الشراء المرجعية فقط (قابل للعكس عند التعديل/الحذف).
        // مردود بلا مرجع شراء لا يمسّ الطبقات في م2 (يُطابَق في م4).
        if ($purchaseIdOpt > 0) {
            orange_inventory_cost_layers_reduce_for_source(
                $pdo,
                'purchase',
                $purchaseIdOpt,
                $variantId,
                $returnWarehouseId,
                $qty
            );
        }

        if ($hasVariant) {
            if ($hasPriDiscount) {
                $pdo->prepare(
                    'INSERT INTO purchase_return_items (purchase_return_id, product_id, variant_id, qty, cost, discount_raw, discount_amount)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $returnId,
                    $productId,
                    $variantId,
                    $qty,
                    $cost,
                    (string) ($item['_discount_raw'] ?? ''),
                    (float) ($item['_discount_amount'] ?? 0),
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO purchase_return_items (purchase_return_id, product_id, variant_id, qty, cost)
                     VALUES (?,?,?,?,?)'
                )->execute([$returnId, $productId, $variantId, $qty, $cost]);
            }
        } elseif ($hasPriDiscount) {
            $pdo->prepare(
                'INSERT INTO purchase_return_items (purchase_return_id, product_id, qty, cost, discount_raw, discount_amount)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $returnId,
                $productId,
                $qty,
                $cost,
                (string) ($item['_discount_raw'] ?? ''),
                (float) ($item['_discount_amount'] ?? 0),
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO purchase_return_items (purchase_return_id, product_id, qty, cost)
                 VALUES (?,?,?,?)'
            )->execute([$returnId, $productId, $qty, $cost]);
        }
    }

    $glB = orange_gl_purchase_return_posting_bundle($pdo, $type, $supplierId, $returnId, $netTotal, $returnCountryId);
    // عكس خصم الفاتورة المكتسب بحصة المردود (قيد مركّب) — دون مسّ تكلفة المخزن.
    $glB = orange_gl_purchase_apply_invoice_discount_lines(
        $pdo,
        $glB,
        $netTotal,
        $invoiceDiscountAmt,
        $returnCountryId,
        true
    );
    $pendingKey = orange_gl_pending_source_key('purchase_return', $returnId);
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
                $retRef,
                $postingAt,
                $postingAt,
                $glB['voucher_description'],
                'purchase_return',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingKey,
                'source_label' => $retRef,
                'movement_at' => $postingAt,
                'voucher_date' => $postingAt,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $netTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'purchase_return',
                'after_post_json' => $afterJson,
            ]);
        }
    } else {
        $pdnJtId = orange_journal_type_id_by_code($pdo, 'PDN', $returnCountryId);
        $slotSpec = [
            'doc_kind' => 'purchase_return',
            'entity_id' => $returnId,
            'slot_key' => 'main',
            'entry_type' => 'purchase_return',
            'country_id' => $returnCountryId,
            'journal_type_id' => $pdnJtId > 0 ? $pdnJtId : null,
        ];
        $vHeader = [
            'voucher_date' => $postingAt,
            'document_entered_at' => $now,
            'description' => $glB['voucher_description'],
            'entry_type' => 'purchase_return',
            'country_id' => $returnCountryId,
        ];
        if ($pdnJtId > 0) {
            $vHeader['journal_type_id'] = $pdnJtId;
        }
        orange_gl_voucher_immediate_post_bundle_for_slot(
            $pdo,
            $slotSpec,
            $vHeader,
            $glB,
            $netTotal,
            $afterJson
        );
    }

    $pdo->commit();
    orange_edit_lock_register_purchase_return(
        $pdo,
        $returnId,
        $returnCountryId > 0 ? $returnCountryId : null,
        $netTotal,
        $retRef,
        $now
    );
    audit_log('purchase_return_create', 'تم إنشاء مردود مشتريات رقم: ' . $returnId, 'purchase_returns', $returnId);
    $voucherLinks = orange_gl_posting_voucher_links($pdo, 'purchase_return', $returnId, [
        ['entry_type' => 'purchase_return', 'journal_type_code' => 'PDN', 'label' => 'قيد مردود المشتريات'],
    ], $returnCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ مردود المشتريات',
        'purchase_return_id' => $returnId,
        'voucher_links' => $voucherLinks,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ مردود المشتريات');
}
