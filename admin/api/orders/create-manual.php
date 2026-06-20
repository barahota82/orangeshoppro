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
require_once __DIR__ . '/../../../includes/company_invoice_offers.php';
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

    // ===== العروض والولاء على فاتورة الشركة (INV-C) =====
    // المحاسب يؤكّد كل عرض مكتشَف (تطبيق/تجاهل) ويملك سلطة تجاوز شرط «للمسجّلين فقط».
    // الأرقام تُحسَب على الخادم (أساس التجزئة) بنفس صيَغ مسار الأونلاين — لا يُوثَق بأي مبلغ من العميل.
    $resolvedCustomerId = (int) ($data['customer_id'] ?? 0);
    if ($resolvedCustomerId <= 0 && $phoneNorm !== '' && orange_table_exists($pdo, 'customers')) {
        if (orange_table_has_country_id($pdo, 'customers') && $orderCountryId > 0) {
            $csLk = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND country_id = ? LIMIT 1');
            $csLk->execute([$phoneNorm, $orderCountryId]);
        } else {
            $csLk = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
            $csLk->execute([$phoneNorm]);
        }
        $resolvedCustomerId = (int) ($csLk->fetchColumn() ?: 0);
    }

    $applyCombo = !empty($data['apply_combo']);
    $applyCart = !empty($data['apply_cart_promotion']);
    $applyProductOffer = !empty($data['apply_product_offer']);
    $applyGift = !empty($data['apply_gift']);
    $applyBogo = !empty($data['apply_bogo']);
    $giftPickVid = (int) ($data['gift_variant_id'] ?? 0);
    $bogoPickVid = (int) ($data['bogo_gift_variant_id'] ?? 0);
    $redeemPointsReq = max(0, (int) ($data['redeem_points'] ?? 0));

    $retailSubtotal = 0.0;
    foreach ($validatedItems as $vi) {
        $retailSubtotal += (float) $vi['price'] * (int) $vi['qty'];
    }
    $retailSubtotal = round($retailSubtotal, 4);

    $picks = orange_company_invoice_offer_picks($pdo, $validatedItems, $retailSubtotal, $orderCountryId);

    $comboId = $applyCombo ? $picks['combo_id'] : null;
    $comboDiscount = $applyCombo ? (float) $picks['combo_discount'] : 0.0;
    $promoId = $applyCart ? $picks['promo_id'] : null;
    $promoDiscount = $applyCart ? (float) $picks['promo_discount'] : 0.0;
    $productOfferDiscount = $applyProductOffer ? (float) $picks['product_offer_discount'] : 0.0;

    $giftLine = null;
    $giftPromoId = null;
    $giftVariantId = null;
    $giftDiscount = 0.0;
    if ($applyGift) {
        $g = orange_company_invoice_resolve_gift_line($pdo, $validatedItems, $retailSubtotal, $orderCountryId, $giftPickVid);
        if ($g !== null) {
            $giftLine = $g['line'];
            $giftPromoId = $g['promo_id'];
            $giftVariantId = $g['variant_id'];
            $giftDiscount = (float) $g['discount'];
        }
    }
    $linesForBogoBase = $validatedItems;
    if ($giftLine !== null) {
        $linesForBogoBase[] = $giftLine;
    }
    $bogoLine = null;
    $bogoPromoId = null;
    $bogoGiftVariantId = null;
    $bogoDiscount = 0.0;
    if ($applyBogo) {
        $b = orange_company_invoice_resolve_bogo_line($pdo, $linesForBogoBase, $orderCountryId, $bogoPickVid);
        if ($b !== null) {
            $bogoLine = $b['line'];
            $bogoPromoId = $b['promo_id'];
            $bogoGiftVariantId = $b['variant_id'];
            $bogoDiscount = (float) $b['discount'];
        }
    }

    $giftStockLines = [];
    if ($giftLine !== null) {
        $giftStockLines[] = $giftLine;
    }
    if ($bogoLine !== null) {
        $giftStockLines[] = $bogoLine;
    }

    // الإجماليات: إيراد البضاعة الإجمالي = صافي بنود البيع + أسطر الهدايا بالتجزئة (= ما يحسبه القيد لاحقاً)؛
    // الخصومات الترويجية والولاء بنود contra تُخفّض المستحق. الإجمالي يُخزَّن بلا ضريبة (الضريبة بند مستقل).
    $giftRetailTotal = 0.0;
    foreach ($giftStockLines as $gl) {
        $giftRetailTotal += (float) ($gl['price'] ?? 0) * (int) ($gl['qty'] ?? 1);
    }
    $giftRetailTotal = round($giftRetailTotal, 4);
    $goodsGross = round($total + $giftRetailTotal, 4);
    $totalPromo = round($comboDiscount + $promoDiscount + $productOfferDiscount + $giftDiscount + $bogoDiscount, 4);
    if ($totalPromo > $goodsGross) {
        $totalPromo = $goodsGross;
    }
    $goodsAfterPromo = max(0.0, round($goodsGross - $totalPromo, 4));

    // استبدال نقاط الولاء (يحدّده المحاسب)؛ مقيَّد بالرصيد القابل للاستخدام؛ الترحيل بعد إدراج الطلب.
    $loyaltyRedeemPoints = 0;
    $loyaltyRedeemValue = 0.0;
    $loyaltyPayableBeforeRedeem = $goodsAfterPromo;
    if ($redeemPointsReq > 0 && $resolvedCustomerId > 0 && orange_loyalty_is_active($pdo, $orderCountryId)) {
        $redeemInfo = orange_loyalty_redeemable($pdo, $resolvedCustomerId, $orderCountryId, $goodsAfterPromo);
        $loyaltyRedeemPoints = min($redeemPointsReq, (int) $redeemInfo['points']);
        if ($loyaltyRedeemPoints > 0) {
            $loySet = orange_loyalty_settings($pdo, $orderCountryId);
            $pv = $loySet !== null ? (float) $loySet['point_value'] : 0.0;
            $loyaltyRedeemValue = round(min($loyaltyRedeemPoints * $pv, $goodsAfterPromo), 4);
            if ($loyaltyRedeemValue <= 0.0001) {
                $loyaltyRedeemPoints = 0;
                $loyaltyRedeemValue = 0.0;
            }
        }
    }
    $orderTotalFinal = max(0.0, round($goodsAfterPromo - $loyaltyRedeemValue, 4));

    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');
    $hasAmountPaidCol = orange_table_has_column($pdo, 'orders', 'amount_paid');
    $amountPaidIn = max(0.0, (float) ($data['amount_paid'] ?? 0));
    $amountPaidIn = min($amountPaidIn, $orderTotalFinal);

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
        $orderTotalFinal,
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
    if (
        orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_combo_discount')
    ) {
        $cols .= ', cart_combo_promotion_id, cart_combo_discount';
        $ph .= ', ?, ?';
        $params[] = $comboId !== null && $comboId > 0 ? $comboId : null;
        $params[] = $comboDiscount > 0 ? $comboDiscount : 0.0;
    }
    if (
        orange_table_has_column($pdo, 'orders', 'cart_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount')
    ) {
        $cols .= ', cart_promotion_id, cart_promotion_discount';
        $ph .= ', ?, ?';
        $params[] = $promoId !== null && $promoId > 0 ? $promoId : null;
        $params[] = $promoDiscount > 0 ? $promoDiscount : 0.0;
    }
    if (
        orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id')
    ) {
        $cols .= ', cart_gift_promotion_id, cart_gift_variant_id';
        $ph .= ', ?, ?';
        $params[] = $giftPromoId !== null && $giftPromoId > 0 ? $giftPromoId : null;
        $params[] = $giftVariantId !== null && $giftVariantId > 0 ? $giftVariantId : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_discount')) {
        $cols .= ', cart_gift_discount';
        $ph .= ', ?';
        $params[] = $giftDiscount > 0 ? $giftDiscount : 0.0;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_bogo_discount')) {
        $cols .= ', cart_bogo_discount';
        $ph .= ', ?';
        $params[] = $bogoDiscount > 0 ? $bogoDiscount : 0.0;
    }
    if (orange_table_has_column($pdo, 'orders', 'product_offer_discount')) {
        $cols .= ', product_offer_discount';
        $ph .= ', ?';
        $params[] = $productOfferDiscount > 0 ? $productOfferDiscount : 0.0;
    }
    if (
        orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id')
    ) {
        $cols .= ', cart_bogo_promotion_id, cart_bogo_gift_variant_id';
        $ph .= ', ?, ?';
        $params[] = $bogoPromoId !== null && $bogoPromoId > 0 ? $bogoPromoId : null;
        $params[] = $bogoGiftVariantId !== null && $bogoGiftVariantId > 0 ? $bogoGiftVariantId : null;
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

    // أسطر الهدية/BOGO (بنود مجانية/مخفّضة مُسعَّرة بالتجزئة)؛ يخصمها بند contra ترويجي لاحقاً.
    foreach ($giftStockLines as $row) {
        $bind = [$orderId];
        $bind[] = (int) $row['product']['id'];
        if ($hasVariantCol) {
            $bind[] = (int) ($row['variant_id'] ?? 0) ?: null;
        }
        $bind[] = $row['product']['name'];
        $bind[] = (string) ($row['color'] ?? '');
        $bind[] = (string) ($row['size'] ?? '');
        $bind[] = (int) ($row['qty'] ?? 1);
        $bind[] = (float) ($row['price'] ?? 0);
        $bind[] = (float) ($row['cost'] ?? 0);
        if ($hasLineDiscountCol) {
            $bind[] = 0.0;
        }
        $itemStmt->execute($bind);
    }

    // استبدال نقاط الولاء: يكتب سجل الاستهلاك (FIFO) ويعيد القيمة الفعلية المستخدَمة.
    if ($loyaltyRedeemPoints > 0 && $loyaltyRedeemValue > 0.0001 && $resolvedCustomerId > 0) {
        $appliedRedeem = orange_loyalty_apply_redemption(
            $pdo,
            $resolvedCustomerId,
            $orderCountryId,
            $loyaltyRedeemPoints,
            $loyaltyPayableBeforeRedeem,
            'order',
            $orderId
        );
        $loyaltyRedeemValue = round((float) $appliedRedeem['value'], 4);
    } else {
        $loyaltyRedeemValue = 0.0;
    }

    $extraInput = orange_invoice_ancillary_parse_request_lines(
        $data,
        orange_invoice_ancillary_doc_kind_sales()
    );
    // الضريبة على صافي البضاعة بعد الخصومات الترويجية (= القيمة الخاضعة للضريبة).
    $extraInput = orange_invoice_ancillary_merge_auto_vat(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales(),
        $orderCountryId,
        (float) $goodsAfterPromo,
        $extraInput
    );
    // بنود contra ترويجية/ولاء تلقائية (sales_debit_contra) تُخفّض المستحق في القيد المُجمَّع.
    $promoAmountsByKey = [
        'promo_combo_discount' => $comboDiscount,
        'promo_cart_discount' => $promoDiscount,
        'promo_gift_discount' => $giftDiscount,
        'promo_bogo_discount' => $bogoDiscount,
        'product_offer_discount' => $productOfferDiscount,
        'loyalty_points_redemption' => $loyaltyRedeemValue,
    ];
    $extraInput = orange_invoice_ancillary_merge_auto_promo_lines(
        $pdo,
        $orderCountryId,
        $promoAmountsByKey,
        $extraInput
    );
    // حارس اتزان: كل خصم/استبدال مطبَّق يجب أن يولّد بند contra (حساب مربوط)؛ وإلا توقّف
    // بدل خفض الإجمالي دون قيد مقابل (اختلال الذمم). يُربط الحساب في «إعدادات البنود الإضافية».
    $promoKeyLabels = [
        'promo_combo_discount' => 'خصم الكومبو',
        'promo_cart_discount' => 'خصم مجموع السلة',
        'promo_gift_discount' => 'خصم هدية مجموع السلة',
        'promo_bogo_discount' => 'خصم هدية BOGO',
        'product_offer_discount' => 'خصم عرض المنتج',
        'loyalty_points_redemption' => 'استبدال نقاط الولاء',
    ];
    $promoGeneratedKeys = [];
    foreach ($extraInput as $ln) {
        if (is_array($ln) && !empty($ln['auto_promo']) && isset($ln['system_key'])) {
            $promoGeneratedKeys[(string) $ln['system_key']] = true;
        }
    }
    foreach ($promoAmountsByKey as $pk => $pAmt) {
        if ((float) $pAmt > 0.0001 && empty($promoGeneratedKeys[$pk])) {
            throw new RuntimeException(
                'تعذّر تطبيق «' . ($promoKeyLabels[$pk] ?? $pk) . '»: لا يوجد حساب «بند خصم تلقائي» مربوط لهذه الدولة. '
                . 'اربط الحساب من «إعدادات البنود الإضافية» ثم أعد الحفظ، أو تجاهل العرض.'
            );
        }
    }

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
