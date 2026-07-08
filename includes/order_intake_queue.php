<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/delivery_areas.php';
require_once __DIR__ . '/storefront_cart_items.php';
require_once __DIR__ . '/cart_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';
require_once __DIR__ . '/product_offers.php';
require_once __DIR__ . '/storefront_checkout_promo_lines.php';
require_once __DIR__ . '/invoice_ancillary_lines.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/storefront_payment_settings.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/storefront_api_errors.php';

/**
 * نص خطأ آمن لحقل order_intake_queue.error_message (code:… فقط — لا نص PDO/SQL ولا getMessage).
 */
function orange_order_intake_error_for_queue(Throwable $e): string
{
    return orange_storefront_order_intake_error_for_queue($e);
}

/**
 * دولة صف الطابور: قناة الحمولة، ثم الطلب المرتبط، ثم الدولة الافتراضية.
 *
 * @param array<string, mixed> $row
 */
function orange_order_intake_row_country_id(PDO $pdo, array $row): int
{
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    if (is_array($payload) && isset($payload['channel_id'])) {
        $chCid = orange_country_id_for_channel($pdo, (int) $payload['channel_id']);
        if ($chCid > 0) {
            return $chCid;
        }
    }
    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId > 0 && orange_table_has_country_id($pdo, 'orders')) {
        $st = $pdo->prepare('SELECT country_id FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $oc = (int) ($st->fetchColumn() ?: 0);
        if ($oc > 0) {
            return $oc;
        }
    }

    return orange_countries_default_id($pdo);
}

/**
 * @param array<string, mixed> $row
 */
function orange_order_intake_row_matches_context(PDO $pdo, array $row, ?int $contextCountryId = null): bool
{
    if ($contextCountryId === null) {
        $contextCountryId = orange_admin_context_country_id($pdo);
    }
    if ($contextCountryId <= 0) {
        return true;
    }

    return orange_order_intake_row_country_id($pdo, $row) === $contextCountryId;
}

/**
 * @throws RuntimeException
 */
function orange_admin_assert_order_intake_id(PDO $pdo, int $queueId): void
{
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0 || $queueId <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT id, payload_json, order_id FROM order_intake_queue WHERE id = ? LIMIT 1');
    $st->execute([$queueId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('صف الطابور غير موجود.');
    }
    if (!orange_order_intake_row_matches_context($pdo, $row, $ctx)) {
        throw new RuntimeException('السجل لا يتبع الدولة المختارة في لوحة التحكم.');
    }
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function orange_order_intake_filter_rows_by_context(PDO $pdo, array $rows): array
{
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        return $rows;
    }

    return array_values(array_filter(
        $rows,
        static fn (array $r): bool => orange_order_intake_row_matches_context($pdo, $r, $ctx)
    ));
}

/**
 * فلترة SQL لطابور الطلبات حسب channel_id في payload_json (بند 13).
 *
 * @return array{join:string, where:string, params:list<int>}|null
 */
function orange_order_intake_sql_country_scope(PDO $pdo, string $alias = 'oiq', ?int $countryId = null): ?array
{
    if ($countryId === null) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    if ($countryId <= 0 || !orange_channels_has_country_column($pdo)) {
        return null;
    }
    $defaultCid = orange_countries_default_id($pdo);
    $a = trim($alias) !== '' ? trim($alias) : 'oiq';
    $chAlias = $a . '_ch';

    return [
        'join' => ' LEFT JOIN channels ' . $chAlias . ' ON ' . $chAlias . '.id = CAST(JSON_UNQUOTE(JSON_EXTRACT('
            . $a . '.payload_json, \'$.channel_id\')) AS UNSIGNED) ',
        'where' => ' AND COALESCE(NULLIF(' . $chAlias . '.country_id, 0), ' . (int) $defaultCid . ') = ? ',
        'params' => [$countryId],
    ];
}

/**
 * Upsert customer by phone for storefront checkout (phone = unique key for the customer row).
 * Updates name, email, phone fields from the latest order; appends order notes to customer notes.
 *
 * **المهمة 2:** لا يكتب area/address على «الحالي» للعميل عند إنشاء الطلب (كان يدهس السابق)؛
 * يبقى عنوان الطلب على صف الطلب نفسه، وتُرقّى قيمة «الحالي» + يُضاف للسجل عند إنشاء قيد التسليم
 * (orange_customer_address_promote_from_order في includes/customer_addresses.php).
 */
function orange_storefront_upsert_customer_from_checkout(
    PDO $pdo,
    string $name,
    string $phone,
    ?string $phoneCountryDial,
    ?string $phoneNational,
    string $area,
    string $address,
    string $emailRaw,
    string $orderNotes,
    string $orderNumber,
    ?int $countryId = null
): ?int {
    if (!orange_table_exists($pdo, 'customers')) {
        return null;
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    $nameAr = trim($name) !== '' ? trim($name) : 'عميل';
    $area = trim($area);
    $address = trim($address);
    $email = trim($emailRaw);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    $emailSql = $email !== '' ? $email : null;

    $snippet = preg_replace('/\s+/u', ' ', trim($orderNotes));
    if (function_exists('mb_substr')) {
        $snippet = $snippet !== '' ? mb_substr($snippet, 0, 500, 'UTF-8') : '—';
    } else {
        $snippet = $snippet !== '' ? substr($snippet, 0, 500) : '—';
    }
    $appendLine = '[' . date('Y-m-d H:i') . '] ' . $orderNumber . ': ' . $snippet;

    $hasArea = orange_table_has_column($pdo, 'customers', 'area');
    $hasAddress = orange_table_has_column($pdo, 'customers', 'address');
    $hasEmail = orange_table_has_column($pdo, 'customers', 'email');
    $hasCustDial = orange_table_has_column($pdo, 'customers', 'phone_country_dial');
    $hasCustNat = orange_table_has_column($pdo, 'customers', 'phone_national');
    $hasCustCountry = orange_table_has_country_id($pdo, 'customers');
    $dialSql = ($phoneCountryDial !== null && $phoneCountryDial !== '') ? substr(preg_replace('/\D+/', '', $phoneCountryDial), 0, 8) : null;
    $dialSql = ($dialSql !== null && $dialSql !== '') ? $dialSql : null;
    $natSql = ($phoneNational !== null && $phoneNational !== '') ? substr(preg_replace('/\D+/', '', $phoneNational), 0, 32) : null;
    $natSql = ($natSql !== null && $natSql !== '') ? $natSql : null;

    if ($hasCustCountry && $countryId > 0) {
        $find = $pdo->prepare('SELECT id, notes FROM customers WHERE phone = ? AND country_id = ? LIMIT 1');
        $find->execute([$phone, $countryId]);
    } else {
        $find = $pdo->prepare('SELECT id, notes FROM customers WHERE phone = ? LIMIT 1');
        $find->execute([$phone]);
    }
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    $mergeNotes = static function (?string $prev, string $line): string {
        $base = trim((string) $prev);
        $add = trim($line);
        $out = $base === '' ? $add : ($base . "\n" . $add);
        if (function_exists('mb_strlen') && mb_strlen($out, 'UTF-8') > 60000) {
            $out = mb_substr($out, -60000, null, 'UTF-8');
        } elseif (strlen($out) > 60000) {
            $out = substr($out, -60000);
        }

        return $out;
    };

    if ($existing !== false && $existing !== null) {
        $id = (int) $existing['id'];
        $newNotes = $mergeNotes($existing['notes'] ?? null, $appendLine);

        // المهمة 2: لا نكتب area/address هنا — يبقيان على صف الطلب ويُرقّيان عند قيد التسليم.
        $set = ['name_ar = ?'];
        $params = [$nameAr];
        if ($hasEmail && $emailSql !== null) {
            $set[] = 'email = ?';
            $params[] = $emailSql;
        }
        if ($hasCustDial) {
            $set[] = 'phone_country_dial = ?';
            $params[] = $dialSql;
        }
        if ($hasCustNat) {
            $set[] = 'phone_national = ?';
            $params[] = $natSql;
        }
        $set[] = 'notes = ?';
        $params[] = $newNotes;
        $params[] = $id;
        $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        return $id;
    }

    $newNotes = $mergeNotes(null, $appendLine);
    $cols = ['name_ar', 'phone'];
    $placeholders = ['?', '?'];
    $params = [$nameAr, $phone];
    if ($hasCustCountry && $countryId > 0) {
        $cols[] = 'country_id';
        $placeholders[] = '?';
        $params[] = $countryId;
    }
    // المهمة 2: لا نكتب area/address عند إنشاء العميل من الطلب — تُرقّى عند قيد التسليم.
    if ($hasEmail) {
        $cols[] = 'email';
        $placeholders[] = '?';
        $params[] = $emailSql;
    }
    if ($hasCustDial) {
        $cols[] = 'phone_country_dial';
        $placeholders[] = '?';
        $params[] = $dialSql;
    }
    if ($hasCustNat) {
        $cols[] = 'phone_national';
        $placeholders[] = '?';
        $params[] = $natSql;
    }
    $cols[] = 'notes';
    $placeholders[] = '?';
    $params[] = $newNotes;

    try {
        $sql = 'INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $pdo->prepare($sql)->execute($params);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        $dup = (int) (($e->errorInfo[1] ?? 0));
        if ($dup === 1062 || str_contains($e->getMessage(), 'Duplicate')) {
            $find2 = $pdo->prepare(
                $hasCustCountry && $countryId > 0
                    ? 'SELECT id, notes FROM customers WHERE phone = ? AND country_id = ? LIMIT 1'
                    : 'SELECT id, notes FROM customers WHERE phone = ? LIMIT 1'
            );
            if ($hasCustCountry && $countryId > 0) {
                $find2->execute([$phone, $countryId]);
            } else {
                $find2->execute([$phone]);
            }
            $ex2 = $find2->fetch(PDO::FETCH_ASSOC);
            if ($ex2 !== false && $ex2 !== null) {
                $id = (int) $ex2['id'];
                $newNotes2 = $mergeNotes($ex2['notes'] ?? null, $appendLine);
                // المهمة 2: لا نكتب area/address هنا — تُرقّى عند قيد التسليم.
                $set = ['name_ar = ?'];
                $params2 = [$nameAr];
                if ($hasEmail && $emailSql !== null) {
                    $set[] = 'email = ?';
                    $params2[] = $emailSql;
                }
                if ($hasCustDial) {
                    $set[] = 'phone_country_dial = ?';
                    $params2[] = $dialSql;
                }
                if ($hasCustNat) {
                    $set[] = 'phone_national = ?';
                    $params2[] = $natSql;
                }
                $set[] = 'notes = ?';
                $params2[] = $newNotes2;
                $params2[] = $id;
                $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params2);

                return $id;
            }
        }
        throw $e;
    }
}

/**
 * إدراج أسطر order_items من ناتج orange_storefront_validate_cart_items_core (داخل معاملة).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_storefront_insert_order_items_for_order(PDO $pdo, int $orderId, array $validatedItems): void
{
    $hasVariantCol = orange_table_has_column($pdo, 'order_items', 'variant_id');
    if ($hasVariantCol) {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?,?)'
        );
    } else {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?)'
        );
    }
    foreach ($validatedItems as $row) {
        if ($hasVariantCol) {
            $itemStmt->execute([
                $orderId,
                (int) $row['product']['id'],
                (int) ($row['variant_id'] ?? 0) ?: null,
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        } else {
            $itemStmt->execute([
                $orderId,
                (int) $row['product']['id'],
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        }
    }
}

/**
 * Core checkout: validate cart, insert order + lines, then reserve variant stock for the web queue.
 * Stock: orange_order_apply_pending_stock_reservation() inserts pending_order movements and decrements
 * product_variants.stock_quantity; release on cancel/reject via orange_order_release_pending_stock_reservation().
 * Must run inside caller transaction (no begin/commit).
 *
 * @param array<string,mixed> $data
 * @return array{
 *   order_id:int,
 *   order_number:string,
 *   total:float,
 *   delivery_fee:float,
 *   delivery_fee_base:float,
 *   delivery_fee_discount:float,
 *   delivery_promotion_id:?int,
 *   whatsapp_number:string,
 *   whatsapp_url:string
 * }
 */
function orange_storefront_execute_checkout_payload(PDO $pdo, array $data): array
{
    $langCheckout = isset($data['lang']) ? (string) $data['lang'] : 'en';
    if (!preg_match('/^(ar|en|fil|hi)$/', $langCheckout)) {
        $langCheckout = 'en';
    }
    orange_storefront_normalize_delivery_area_payload($pdo, $data, $langCheckout);

    require_fields($data, ['name', 'phone', 'area', 'address', 'channel_id', 'items']);

    $emailCheck = trim((string) ($data['email'] ?? ''));
    if ($emailCheck !== '' && !filter_var($emailCheck, FILTER_VALIDATE_EMAIL)) {
        orange_storefront_throw_customer('checkout_invalid_email', 'Invalid email in checkout payload');
    }
    $data['email'] = $emailCheck;

    $pcParsed = orange_storefront_parse_api_phone_country((string) ($data['phone_country'] ?? ''));
    if (!$pcParsed['full_intl'] && $pcParsed['dial'] === '') {
        orange_storefront_throw_customer('phone_country_required', 'phone_country missing in checkout');
    }
    $phoneRawIn = trim((string) ($data['phone'] ?? ''));
    $dialForNational = $pcParsed['full_intl'] ? null : $pcParsed['dial'];
    $phoneNorm = orange_normalize_customer_phone($phoneRawIn, $dialForNational, $pcParsed['full_intl']);
    if ($phoneNorm === null) {
        orange_storefront_throw_customer('checkout_invalid_phone', 'phone normalization failed');
    }
    $data['phone'] = $phoneNorm;
    $data['phone_country'] = $pcParsed['full_intl'] ? '' : $pcParsed['dial'];
    $partsStore = orange_storefront_phone_storage_parts($phoneRawIn, $dialForNational);
    $data['phone_country_dial'] = $partsStore['country_dial'];
    $data['phone_national'] = $partsStore['national'];

    if (!is_array($data['items']) || count($data['items']) === 0) {
        orange_storefront_throw_customer('checkout_cart_items_required', 'Cart items are required');
    }

    $channelStmt = $pdo->prepare('SELECT * FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $channelStmt->execute([(int) $data['channel_id']]);
    $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        orange_storefront_throw_customer('checkout_invalid_channel', 'channel_id=' . (int) $data['channel_id']);
    }

    $orderCountryId = orange_country_id_for_channel($pdo, (int) $data['channel_id']);
    $orderWarehouseId = orange_warehouse_default_id_for_country($pdo, $orderCountryId);
    $orderNumber = orange_generate_order_number_for_country($pdo, $orderCountryId);

    [$subtotal, $validatedItems] = orange_storefront_validate_cart_items_core($pdo, $data['items'], true);

    // طلب الواجهة: نقدي (COD) افتراضياً؛ أونلاين فقط إذا مفعّل في إعدادات الدولة.
    $paymentTerms = 'cash';
    if (isset($data['payment_terms'])) {
        $requested = orange_normalize_payment_terms($data['payment_terms']);
        if ($requested === 'online' && orange_storefront_payment_online_enabled($pdo, $orderCountryId, true)) {
            $paymentTerms = 'online';
        }
    }
    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');
    $hasCustomerId = orange_table_has_column($pdo, 'orders', 'customer_id');
    $hasSfa = orange_table_has_column($pdo, 'orders', 'storefront_account_id');
    $sfaLink = null;
    if ($hasSfa && isset($data['storefront_account_id'])) {
        $sfaLink = orange_storefront_verified_account_id($pdo, (int) $data['storefront_account_id']);
    }

    $buyerRegistered = $sfaLink !== null && $sfaLink > 0;
    $buyerAccountId = ($sfaLink !== null && $sfaLink > 0) ? (int) $sfaLink : null;
    $buyerPhone = isset($data['phone']) ? trim((string) $data['phone']) : null;
    if ($buyerPhone === '') {
        $buyerPhone = null;
    }
    $deliveryAreaId = isset($data['delivery_area_id']) ? (int) $data['delivery_area_id'] : 0;
    $deliveryBundle = $deliveryAreaId > 0
        ? orange_delivery_resolve_checkout_fee_bundle(
            $pdo,
            $deliveryAreaId,
            $buyerRegistered,
            $orderCountryId,
            null,
            $buyerAccountId,
            $buyerPhone
        )
        : [
            'base_fee' => 0.0,
            'discount_fee' => 0.0,
            'fee' => 0.0,
            'promotion' => null,
        ];
    $deliveryFeeBase = round(max(0.0, (float) ($deliveryBundle['base_fee'] ?? 0)), 4);
    $deliveryFeeDiscount = round(max(0.0, (float) ($deliveryBundle['discount_fee'] ?? 0)), 4);
    $deliveryFee = round(max(0.0, (float) ($deliveryBundle['fee'] ?? 0)), 4);
    if ($deliveryFeeDiscount > $deliveryFeeBase) {
        $deliveryFeeDiscount = $deliveryFeeBase;
    }
    $deliveryPromotion = $deliveryBundle['promotion'] ?? null;
    $deliveryPromotionId = is_array($deliveryPromotion) ? (int) ($deliveryPromotion['id'] ?? 0) : 0;
    // سياسة «العرض بديل» (س4/2): البنود ذات عرض منتج نشط تُستبعد من أساس الكومبو/خصم السلة وتأخذ عرضها فقط.
    $offerPartition = orange_product_offer_partition_items($pdo, $validatedItems, $orderCountryId);
    $nonOfferItems = $offerPartition['non_offer_items'];
    $nonOfferSubtotal = max(0.0, round($subtotal - (float) $offerPartition['offer_items_value'], 4));
    $comboPick = orange_cart_combo_best_match($pdo, $nonOfferItems, $buyerRegistered, $orderCountryId, $buyerAccountId, $buyerPhone);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
    $cartPromoBase = max(0.0, round($nonOfferSubtotal - $comboDiscount, 4));
    $promoPick = orange_cart_promotion_resolve($pdo, $cartPromoBase, $buyerRegistered, $orderCountryId, $buyerAccountId, $buyerPhone);
    $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
    $promoId = $promoPick !== null ? (int) $promoPick['id'] : null;
    // عروض المنتج: خصم فعلي عند الدفع (السعر الأصلي على البند + بند خصم منفصل) — بديل لا يتراكم مع الكومبو/السلة.
    $productOfferDiscount = (float) $offerPartition['offer_discount'];
    $maxOfferRoom = max(0.0, round($subtotal - $comboDiscount - $promoDiscount, 4));
    if ($productOfferDiscount > $maxOfferRoom) {
        $productOfferDiscount = $maxOfferRoom;
    }
    $orderTotal = max(0.0, round($subtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4));

    $promoBundle = orange_storefront_build_promotional_gift_lines($pdo, $data, $validatedItems, $subtotal, $buyerRegistered, $orderCountryId, $buyerAccountId, $buyerPhone);
    $giftLines = $promoBundle['giftLines'] ?? [];
    $giftLine = $promoBundle['giftLine'];
    $giftPromoId = $promoBundle['giftPromoId'];
    $giftVariantId = $promoBundle['giftVariantId'];
    $giftSelectionsJson = $promoBundle['giftSelectionsJson'] ?? null;
    $bogoLine = $promoBundle['bogoLine'];
    $bogoPromoId = $promoBundle['bogoPromoId'];
    $bogoGiftVariantId = $promoBundle['bogoGiftVariantId'];
    $giftDiscount = round((float) ($promoBundle['giftDiscount'] ?? 0), 4);
    $bogoDiscount = round((float) ($promoBundle['bogoDiscount'] ?? 0), 4);
    $linesForStock = $promoBundle['linesForStock'];

    // البنود مُسعَّرة بالتجزئة؛ ما يدفعه العميل فعلاً = (تجزئة − خصم الهدية الصريح).
    $giftLinesCharge = 0.0;
    foreach ($giftLines as $gl) {
        $giftLinesCharge += round((float) ($gl['price'] ?? 0) * (int) ($gl['qty'] ?? 1), 4);
    }
    $giftLinesCharge = round(max(0.0, $giftLinesCharge - $giftDiscount), 4);
    if ($bogoLine !== null) {
        $giftLinesCharge += round((float) ($bogoLine['price'] ?? 0) * (int) ($bogoLine['qty'] ?? 1) - $bogoDiscount, 4);
    }
    $giftLinesCharge = round(max(0.0, $giftLinesCharge), 4);
    $orderTotal = max(0.0, round($orderTotal + $giftLinesCharge + $deliveryFee, 4));

    $customerRowId = orange_storefront_upsert_customer_from_checkout(
        $pdo,
        trim((string) $data['name']),
        trim((string) $data['phone']),
        $data['phone_country_dial'] ?? null,
        $data['phone_national'] ?? null,
        trim((string) $data['area']),
        trim((string) $data['address']),
        trim((string) $data['email']),
        isset($data['notes']) ? trim((string) $data['notes']) : '',
        $orderNumber,
        orange_country_id_for_channel($pdo, (int) $data['channel_id'])
    );

    // استبدال نقاط الولاء (للحساب المسجَّل فقط): يقلّل المبلغ المستحق؛ الاستهلاك FIFO بعد إدراج الطلب.
    $loyaltyRedeemPoints = 0;
    $loyaltyRedeemValue = 0.0;
    $loyaltyPayableBeforeRedeem = $orderTotal;
    if ($buyerRegistered && $customerRowId !== null && $customerRowId > 0) {
        require_once __DIR__ . '/loyalty.php';
        $redeemReq = (int) ($data['redeem_points'] ?? 0);
        if ($redeemReq > 0 && orange_loyalty_is_active($pdo, $orderCountryId)) {
            // معاينة مقفولة (FOR UPDATE): القيمة بأسعار الطبقات FIFO، وتُطابق ما سيُطبَّق لاحقاً
            // في نفس المعاملة — فلا انجراف بين إجمالي الطلب وبند الاستبدال في الفاتورة.
            $prev = orange_loyalty_redemption_value_preview(
                $pdo,
                (int) $customerRowId,
                $orderCountryId,
                $redeemReq,
                $orderTotal
            );
            $loyaltyRedeemPoints = (int) $prev['points'];
            $loyaltyRedeemValue = round((float) $prev['value'], 4);
            if ($loyaltyRedeemPoints <= 0 || $loyaltyRedeemValue <= 0.0001) {
                $loyaltyRedeemPoints = 0;
                $loyaltyRedeemValue = 0.0;
            } else {
                $orderTotal = max(0.0, round($orderTotal - $loyaltyRedeemValue, 4));
            }
        }
    }

    $hasOrdDial = orange_table_has_column($pdo, 'orders', 'phone_country_dial');
    $hasOrdNat = orange_table_has_column($pdo, 'orders', 'phone_national');
    $cols = 'order_number, customer_name';
    $ph = '?, ?';
    $params = [
        $orderNumber,
        trim((string) $data['name']),
    ];
    if ($hasOrdDial) {
        $cols .= ', phone_country_dial';
        $ph .= ', ?';
        $params[] = $data['phone_country_dial'] ?? null;
    }
    if ($hasOrdNat) {
        $cols .= ', phone_national';
        $ph .= ', ?';
        $params[] = $data['phone_national'] ?? null;
    }
    $cols .= ', phone, area, address, notes, channel_id, status, total';
    $ph .= ', ?, ?, ?, ?, ?, ?, \'pending\', ?';
    array_push(
        $params,
        trim((string) $data['phone']),
        trim((string) $data['area']),
        trim((string) $data['address']),
        isset($data['notes']) ? trim((string) $data['notes']) : '',
        (int) $data['channel_id'],
        $orderTotal
    );
    if ($hasSource) {
        $cols .= ', order_source';
        $ph .= ', ?';
        $params[] = 'website';
    }
    if ($hasPay) {
        $cols .= ', payment_terms';
        $ph .= ', ?';
        $params[] = $paymentTerms;
    }
    if ($hasCustomerId && $customerRowId !== null && $customerRowId > 0) {
        $cols .= ', customer_id';
        $ph .= ', ?';
        $params[] = $customerRowId;
    }
    if ($hasSfa && $sfaLink !== null && $sfaLink > 0) {
        $cols .= ', storefront_account_id';
        $ph .= ', ?';
        $params[] = $sfaLink;
    }
    $hasDeliveryArea = orange_table_has_column($pdo, 'orders', 'delivery_area_id');
    if ($hasDeliveryArea) {
        $cols .= ', delivery_area_id';
        $ph .= ', ?';
        $daIns = isset($data['delivery_area_id']) ? (int) $data['delivery_area_id'] : 0;
        $params[] = $daIns > 0 ? $daIns : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
        $cols .= ', delivery_fee';
        $ph .= ', ?';
        $params[] = $deliveryFee;
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee_base')) {
        $cols .= ', delivery_fee_base';
        $ph .= ', ?';
        $params[] = $deliveryFeeBase;
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee_discount')) {
        $cols .= ', delivery_fee_discount';
        $ph .= ', ?';
        $params[] = $deliveryFeeDiscount;
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_promotion_id')) {
        $cols .= ', delivery_promotion_id';
        $ph .= ', ?';
        $params[] = $deliveryPromotionId > 0 ? $deliveryPromotionId : null;
    }
    $hasCartPromo = orange_table_has_column($pdo, 'orders', 'cart_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
    $hasComboCols = orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_combo_discount');
    if ($hasComboCols) {
        $cols .= ', cart_combo_promotion_id, cart_combo_discount';
        $ph .= ', ?, ?';
        $params[] = $comboId !== null && $comboId > 0 ? $comboId : null;
        $params[] = $comboDiscount > 0 ? $comboDiscount : 0.0;
    }
    if ($hasCartPromo) {
        $cols .= ', cart_promotion_id, cart_promotion_discount';
        $ph .= ', ?, ?';
        $params[] = $promoId !== null && $promoId > 0 ? $promoId : null;
        $params[] = $promoDiscount > 0 ? $promoDiscount : 0.0;
    }
    $hasGiftCols = orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id');
    if ($hasGiftCols) {
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
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_selections_json')) {
        $cols .= ', cart_gift_selections_json';
        $ph .= ', ?';
        $params[] = is_string($giftSelectionsJson) && $giftSelectionsJson !== '' ? $giftSelectionsJson : null;
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
    $hasBogoCols = orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id');
    if ($hasBogoCols) {
        $cols .= ', cart_bogo_promotion_id, cart_bogo_gift_variant_id';
        $ph .= ', ?, ?';
        $params[] = $bogoPromoId !== null && $bogoPromoId > 0 ? $bogoPromoId : null;
        $params[] = $bogoGiftVariantId !== null && $bogoGiftVariantId > 0 ? $bogoGiftVariantId : null;
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

    $orderId = (int) $pdo->lastInsertId();

    orange_storefront_insert_order_items_for_order($pdo, $orderId, $linesForStock);

    if ($loyaltyRedeemPoints > 0 && $loyaltyRedeemValue > 0.0001 && $customerRowId !== null && $customerRowId > 0) {
        $applied = orange_loyalty_apply_redemption(
            $pdo,
            (int) $customerRowId,
            $orderCountryId,
            $loyaltyRedeemPoints,
            $loyaltyPayableBeforeRedeem,
            'order',
            $orderId
        );
        $loyaltyRedeemValue = round((float) $applied['value'], 4);
    } else {
        $loyaltyRedeemValue = 0.0;
    }

    if (orange_invoice_ancillary_tables_ready($pdo)) {
        $autoLines = orange_invoice_ancillary_merge_auto_delivery_lines(
            $pdo,
            $orderCountryId,
            [
                'delivery_fee' => $deliveryFee,
                'delivery_fee_base' => $deliveryFeeBase,
                'delivery_fee_discount' => $deliveryFeeDiscount,
            ],
            []
        );
        $autoLines = orange_invoice_ancillary_merge_auto_promo_lines(
            $pdo,
            $orderCountryId,
            [
                'promo_combo_discount' => $comboDiscount,
                'promo_cart_discount' => $promoDiscount,
                'promo_gift_discount' => $giftDiscount,
                'promo_bogo_discount' => $bogoDiscount,
                'product_offer_discount' => $productOfferDiscount,
                'loyalty_points_redemption' => $loyaltyRedeemValue,
            ],
            $autoLines
        );
        if ($autoLines !== []) {
            orange_invoice_ancillary_extra_lines_replace_for_doc(
                $pdo,
                orange_invoice_ancillary_doc_kind_sales(),
                $orderId,
                $orderCountryId,
                $autoLines
            );
        }
    }

    orange_order_apply_pending_stock_reservation($pdo, $orderNumber, $linesForStock, $orderCountryId, $orderWarehouseId);

    $messageLines = [];
    $messageLines[] = "Order Number: {$orderNumber}";
    $messageLines[] = 'Customer: ' . trim((string) $data['name']);
    $messageLines[] = 'Phone: ' . trim((string) $data['phone']);
    if (trim((string) $data['email']) !== '') {
        $messageLines[] = 'Email: ' . trim((string) $data['email']);
    }
    $messageLines[] = 'Area: ' . trim((string) $data['area']);
    $messageLines[] = 'Address: ' . trim((string) $data['address']);
    if (!empty($data['notes'])) {
        $messageLines[] = 'Notes: ' . trim((string) $data['notes']);
    }
    $messageLines[] = '';
    $messageLines[] = 'Items:';
    foreach ($linesForStock as $idx => $row) {
        $tag = '';
        if (!empty($row['is_bogo_gift'])) {
            $tag = ' (BOGO GIFT)';
        } elseif (!empty($row['is_gift'])) {
            $tag = ' (FREE GIFT)';
        }
        $messageLines[] = ($idx + 1) . ') ' . $row['product']['name'] . $tag;
        if ($row['color'] !== '') {
            $messageLines[] = '   Color: ' . $row['color'];
        }
        if ($row['size'] !== '') {
            $messageLines[] = '   Size: ' . $row['size'];
        }
        $messageLines[] = '   Qty: ' . $row['qty'];
        $messageLines[] = '   Price: ' . number_format($row['price'], 2) . ' KD';
    }
    $messageLines[] = '';
    $payAr = orange_order_payment_terms_label_ar($paymentTerms);
    $payEn = $paymentTerms === 'online' ? 'Online' : 'Cash';
    $messageLines[] = 'Payment: ' . $payEn . ' / ' . $payAr;
    $messageLines[] = 'Subtotal: ' . number_format($subtotal, 2) . ' KD';
    if ($comboDiscount > 0.00001) {
        $messageLines[] = 'Combo bundle: -' . number_format($comboDiscount, 2) . ' KD';
    }
    if ($promoDiscount > 0.00001) {
        $messageLines[] = 'Cart promotion: -' . number_format($promoDiscount, 2) . ' KD';
    }
    if ($deliveryFeeBase > 0.00001) {
        $messageLines[] = 'Delivery fee (base): +' . number_format($deliveryFeeBase, 2) . ' KD';
    }
    if ($deliveryFeeDiscount > 0.00001) {
        $messageLines[] = 'Delivery discount: -' . number_format($deliveryFeeDiscount, 2) . ' KD';
    }
    if ($deliveryFee > 0.00001 && $deliveryFeeDiscount > 0.00001) {
        $messageLines[] = 'Delivery fee (net): +' . number_format($deliveryFee, 2) . ' KD';
    } elseif ($deliveryFee > 0.00001 && $deliveryFeeBase <= 0.00001) {
        $messageLines[] = 'Delivery fee: +' . number_format($deliveryFee, 2) . ' KD';
    }
    $messageLines[] = 'Total: ' . number_format($orderTotal, 2) . ' KD';

    $whatsAppNumber = clean_whatsapp_number((string) $channel['whatsapp_number']);
    $whatsAppUrl = 'https://wa.me/' . $whatsAppNumber . '?text=' . rawurlencode(implode("\n", $messageLines));

    return [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total' => $orderTotal,
        'delivery_fee' => $deliveryFee,
        'delivery_fee_base' => $deliveryFeeBase,
        'delivery_fee_discount' => $deliveryFeeDiscount,
        'delivery_promotion_id' => $deliveryPromotionId > 0 ? $deliveryPromotionId : null,
        'whatsapp_number' => $whatsAppNumber,
        'whatsapp_url' => $whatsAppUrl,
    ];
}

/**
 * Process the oldest pending intake job (FIFO). Uses one transaction with SAVEPOINT so order failure marks the queue row failed.
 *
 * @param bool $respectAdminCountry when true, only dequeue rows for orange_admin_context_country_id()
 */
function orange_order_intake_process_next(PDO $pdo, bool $respectAdminCountry = false): bool
{
    $pdo->beginTransaction();
    $qid = 0;
    try {
        $scope = $respectAdminCountry ? orange_order_intake_sql_country_scope($pdo, 'oiq') : null;
        $join = $scope['join'] ?? '';
        $whereExtra = $scope['where'] ?? '';
        $scopeParams = $scope['params'] ?? [];
        $sel = $pdo->prepare(
            'SELECT oiq.id, oiq.payload_json FROM order_intake_queue oiq'
            . $join
            . " WHERE oiq.status = 'pending'"
            . $whereExtra
            . ' ORDER BY oiq.id ASC LIMIT 1 FOR UPDATE'
        );
        $sel->execute($scopeParams);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->commit();

            return false;
        }
        $qid = (int) $row['id'];
        $payload = json_decode((string) $row['payload_json'], true);

        $pdo->exec('SAVEPOINT orange_intake_sp');
        try {
            if (!is_array($payload)) {
                orange_storefront_throw_customer('checkout_failed_generic', 'Invalid queue payload qid=' . $qid);
            }
            $out = orange_storefront_execute_checkout_payload($pdo, $payload);
            $pdo->exec('RELEASE SAVEPOINT orange_intake_sp');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT orange_intake_sp');
            $errText = orange_order_intake_error_for_queue($e);
            $pdo->prepare(
                'UPDATE order_intake_queue SET status = ?, error_message = ?, attempts = attempts + 1 WHERE id = ?'
            )->execute(['failed', $errText, $qid]);
            if (function_exists('error_log')) {
                error_log('[orange] order_intake failed queue_id=' . $qid . ' err=' . $errText);
            }
            $pdo->commit();

            return true;
        }

        $pdo->prepare(
            'UPDATE order_intake_queue SET status = ?, order_id = ?, order_number = ?, whatsapp_url = ?, whatsapp_number = ? WHERE id = ?'
        )->execute([
            'completed',
            $out['order_id'],
            $out['order_number'],
            $out['whatsapp_url'],
            $out['whatsapp_number'],
            $qid,
        ]);
        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string,mixed> $data
 * @return array{id:int, public_token:string}
 */
function orange_order_intake_enqueue(PDO $pdo, array $data): array
{
    $token = bin2hex(random_bytes(16));
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        throw new RuntimeException('Could not encode checkout payload');
    }
    $ins = $pdo->prepare(
        "INSERT INTO order_intake_queue (public_token, status, payload_json) VALUES (?, 'pending', ?)"
    );
    $ins->execute([$token, $json]);

    return ['id' => (int) $pdo->lastInsertId(), 'public_token' => $token];
}
