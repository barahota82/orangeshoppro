<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/order_helpers.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/document_sequences.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../../includes/sales_invoice_company.php';
require_once __DIR__ . '/../../../includes/sales_doc_channel.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    require_fields($data, ['customer_name', 'phone', 'items']);
    $phoneRawIn = trim((string) ($data['phone'] ?? ''));
    $ctxDial = orange_admin_context_phone_dial($pdo);
    $phoneCountryRaw = trim((string) ($data['phone_country'] ?? ''));
    $pcParsed = orange_storefront_parse_api_phone_country($phoneCountryRaw !== '' ? $phoneCountryRaw : $ctxDial);
    if (!empty($pcParsed['full_intl'])) {
        json_response([
            'success' => false,
            'message' => 'في فاتورة المبيعات اختر كود الدولة من القائمة واكتب الرقم المحلي فقط.',
        ], 422);
    }
    $dialForNational = ($pcParsed['dial'] ?? '') !== '' ? (string) $pcParsed['dial'] : $ctxDial;
    if ($phoneRawIn !== '' && $dialForNational === '') {
        json_response(['success' => false, 'message' => 'اختيار كود الدولة إلزامي عند إدخال رقم الهاتف.'], 422);
    }
    if ($phoneRawIn !== '' && preg_match('/^\s*(\+|00)/', $phoneRawIn)) {
        json_response([
            'success' => false,
            'message' => 'اكتب الهاتف كرقم محلي فقط بدون + أو 00؛ كود الدولة يُؤخذ من القائمة.',
        ], 422);
    }
    $phoneNorm = orange_normalize_customer_phone(
        $phoneRawIn,
        $dialForNational !== '' ? $dialForNational : null,
        false
    );
    if ($phoneNorm === null) {
        json_response([
            'success' => false,
            'message' => 'رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو أدخل رقماً وطنياً صالحاً (مثلاً جوال كويت 8 أرقام).',
        ], 422);
    }
    if (!is_array($data['items']) || count($data['items']) === 0) {
        json_response(['success' => false, 'message' => 'أضف سطرًا واحدًا على الأقل من المنتجات المسجّرة'], 422);
    }

    $paymentTerms = orange_normalize_payment_terms($data['payment_terms'] ?? 'cash');
    if ($paymentTerms === 'credit') {
        $customerIdIn = (int) ($data['customer_id'] ?? 0);
        if ($customerIdIn > 0) {
            try {
                orange_admin_assert_entity_country($pdo, 'customers', $customerIdIn);
            } catch (RuntimeException $e) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            }
        }
        $civilChk = orange_customer_credit_sale_civil_check($pdo, $customerIdIn, $phoneNorm);
        if (!$civilChk['ok']) {
            json_response(['success' => false, 'message' => $civilChk['message']], 422);
        }
    }

    foreach ($data['items'] as $item) {
        $pidCheck = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        if ($pidCheck <= 0) {
            json_response(['success' => false, 'message' => 'يُقبل فقط بيع منتجات مسجّلة في «المنتجات» — لا بند نصي أو سعر يدوي بدون صنف'], 422);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $pidCheck);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    $channelId = (int) ($data['channel_id'] ?? 0);
    if ($channelId > 0) {
        try {
            orange_admin_assert_row_country($pdo, 'channels', $channelId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }

        $channelStmt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
        $channelStmt->execute([$channelId]);
        if (!$channelStmt->fetchColumn()) {
            json_response(['success' => false, 'message' => 'قناة غير صالحة'], 422);
        }
    }

    $orderCountryId = orange_sales_order_country_id_for_channel($pdo, $channelId);
    $orderWarehouseId = orange_warehouse_default_id_for_country($pdo, $orderCountryId);

    // تاريخ الفاتورة/المستند القابل للضبط = تاريخ ترحيل القيد المحاسبي (منفصل عن created_at).
    $documentDate = trim((string)($data['document_date'] ?? ''));
    if ($documentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        $documentDate = date('Y-m-d');
    }
    orange_fiscal_require_open_for_posting($pdo, $documentDate, $orderCountryId);

    $pdo->beginTransaction();

    $orderNumber = orange_generate_order_number_for_country($pdo, $orderCountryId);
    $total = 0.0;
    $validatedItems = [];

    foreach ($data['items'] as $item) {
        $lineDiscount = (float) ($item['line_discount'] ?? 0);
        $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;

        require_fields($item, ['qty']);
        $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $productStmt->execute([$pid]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('منتج غير موجود: ' . $pid);
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            throw new RuntimeException(
                'المنتج غير ضمن الكتالوج الموحّد النشط أو غير مرتبط بسلسلة التصنيف الصالحة: ' . $pid
            );
        }

        $qty = max(1, (int)$item['qty']);
        $variantIdIn = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
        $color = isset($item['color']) ? trim((string)$item['color']) : '';
        $size = isset($item['size']) ? trim((string)$item['size']) : '';

        if ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1) {
            $variant = null;
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
                );
                $vStmt->execute([$variantIdIn, (int)$product['id']]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants
                    WHERE product_id = ? AND color = ? AND size = ?
                    LIMIT 1'
                );
                $variantStmt->execute([(int)$product['id'], $color, $size]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                throw new RuntimeException('لم يُعثر على متغير للمنتج: ' . $product['name']);
            }
            $variantId = (int) $variant['id'];
            $available = orange_warehouse_effective_variant_stock($pdo, $variantId, $orderCountryId);
            if ($available < $qty) {
                throw new RuntimeException('مخزون غير كافٍ: ' . $product['name']);
            }
        } else {
            $variant = null;
        }

        $price = (float)$product['price'];
        $cost = (float)$product['cost'];
        $lineNet = max(0.0, round($price * $qty - $lineDiscount, 4));
        $total += $lineNet;

        $validatedItems[] = [
            'kind' => 'product',
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string)$variant['color'] : $color,
            'size' => $variant ? (string)$variant['size'] : $size,
            'variant_id' => $variant ? (int)$variant['id'] : 0,
            'price' => $price,
            'cost' => $cost,
            'line_discount' => $lineDiscount,
        ];
    }

    if ($validatedItems === []) {
        json_response(['success' => false, 'message' => 'أضف سطرًا واحدًا على الأقل من المنتجات المسجّلة'], 422);
    }

    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');
    $hasAmountPaidCol = orange_table_has_column($pdo, 'orders', 'amount_paid');
    $amountPaidIn = max(0.0, (float) ($data['amount_paid'] ?? 0));
    $amountPaidIn = min($amountPaidIn, $total);

    $cols = 'order_number, customer_name, phone, area, address, notes, channel_id, status, total';
    $ph = '?, ?, ?, ?, ?, ?, ?, \'completed\', ?';
    $params = [
        $orderNumber,
        trim((string)$data['customer_name']),
        $phoneNorm,
        trim((string)($data['area'] ?? '')),
        trim((string)($data['address'] ?? '')),
        trim((string)($data['notes'] ?? '')),
        $channelId > 0 ? $channelId : null,
        $total,
    ];
    if ($hasSource) {
        $cols .= ', order_source';
        $ph .= ', ?';
        $params[] = 'company';
    }
    if ($hasPay) {
        $cols .= ', payment_terms';
        $ph .= ', ?';
        $params[] = $paymentTerms;
    }
    if ($hasAmountPaidCol) {
        $cols .= ', amount_paid';
        $ph .= ', ?';
        $params[] = $amountPaidIn;
    }
    if (orange_table_has_country_id($pdo, 'orders') && $orderCountryId > 0) {
        $cols .= ', country_id';
        $ph .= ', ?';
        $params[] = $orderCountryId;
    }
    if (orange_table_has_column($pdo, 'orders', 'warehouse_id') && $orderWarehouseId > 0) {
        $cols .= ', warehouse_id';
        $ph .= ', ?';
        $params[] = $orderWarehouseId;
    }
    if (orange_table_has_column($pdo, 'orders', 'document_date')) {
        $cols .= ', document_date';
        $ph .= ', ?';
        $params[] = $documentDate;
    }
    orange_sql_append_document_currency_code(
        $pdo,
        'orders',
        $orderCountryId,
        $cols,
        $ph,
        $params
    );
    $cols .= ', created_at';
    $ph .= ', NOW()';

    $orderStmt = $pdo->prepare("INSERT INTO orders ($cols) VALUES ($ph)");
    $orderStmt->execute($params);

    $orderId = (int)$pdo->lastInsertId();

    $colsStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'"
    );
    $oiCols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $oiCols = is_array($oiCols) ? $oiCols : [];
    $hasVariantCol = in_array('variant_id', $oiCols, true);
    $hasLineDiscountCol = in_array('line_discount', $oiCols, true);

    $insertCols = ['order_id', 'product_id'];
    if ($hasVariantCol) {
        $insertCols[] = 'variant_id';
    }
    $insertCols = array_merge($insertCols, ['product_name', 'color', 'size', 'qty', 'price', 'cost']);
    if ($hasLineDiscountCol) {
        $insertCols[] = 'line_discount';
    }
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')'
    );

    foreach ($validatedItems as $row) {
        $bind = [$orderId];
        $bind[] = (int) $row['product']['id'];
        if ($hasVariantCol) {
            $bind[] = (int) ($row['variant_id'] ?? 0) ?: null;
        }
        $bind[] = $row['product']['name'];
        $bind[] = $row['color'];
        $bind[] = $row['size'];
        $bind[] = $row['qty'];
        $bind[] = $row['price'];
        $bind[] = $row['cost'];
        if ($hasLineDiscountCol) {
            $bind[] = (float) ($row['line_discount'] ?? 0);
        }
        $itemStmt->execute($bind);
    }

    $extraInput = orange_invoice_ancillary_parse_request_lines(
        $data,
        orange_invoice_ancillary_doc_kind_sales()
    );
    $extraInput = orange_invoice_ancillary_merge_auto_vat(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales(),
        $orderCountryId,
        (float) $total,
        $extraInput
    );

    orange_complete_order_fulfillment($pdo, $orderId);

    orange_invoice_ancillary_extra_lines_replace_for_doc(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales(),
        $orderId,
        $orderCountryId,
        $extraInput
    );

    $ordSt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $ordSt->execute([$orderId]);
    $orderRow = $ordSt->fetch(PDO::FETCH_ASSOC) ?: [];
    orange_order_assign_inv_c_if_needed($pdo, $orderId, $orderRow);
    orange_post_order_delivery_accounting($pdo, $orderId);

    $ordSt->execute([$orderId]);
    $orderRow = $ordSt->fetch(PDO::FETCH_ASSOC) ?: [];
    orange_sales_invoice_company_register_edit_lock($pdo, $orderId, $orderRow);

    $pdo->commit();

    $ofCountryId = (int) ($orderRow['country_id'] ?? 0);
    if ($ofCountryId <= 0) {
        $ofCountryId = orange_admin_context_country_id($pdo);
    }
    $paymentTerms = trim((string) ($orderRow['payment_terms'] ?? 'cash'));
    $isCredit = $paymentTerms === 'credit';
    $isOnline = orange_order_delivery_sale_uses_online_revenue_account($pdo, $orderRow);
    $saleJtCode = $isOnline ? 'OSI' : ($isCredit ? 'SIN' : 'CSI');
    $cogsJtCode = $isOnline ? 'CGO' : ($isCredit ? 'CGT' : 'CGC');
    $voucherLinks = orange_gl_posting_voucher_links($pdo, 'order', $orderId, [
        ['entry_type' => 'order_delivery_sale', 'journal_type_code' => $saleJtCode, 'label' => 'قيد المبيعات'],
        ['entry_type' => 'order_delivery_cogs', 'journal_type_code' => $cogsJtCode, 'label' => 'قيد تكلفة المبيعات'],
    ], $ofCountryId > 0 ? $ofCountryId : null);

    json_response([
        'success' => true,
        'message' => 'تم تسجيل فاتورة الشركة',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'voucher_links' => $voucherLinks,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر تسجيل فاتورة الشركة');
}
