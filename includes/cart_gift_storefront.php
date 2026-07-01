<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_gift_pool_config.php';
require_once __DIR__ . '/catalog_labels.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/product_colorway_images.php';
require_once __DIR__ . '/variant_pricing.php';

/**
 * @param array<string,mixed> $rule
 *
 * @return list<int>
 */
function orange_cart_gift_storefront_rule_product_ids(PDO $pdo, array $rule): array
{
    $kind = strtolower(trim((string) ($rule['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
    if ($kind === 'fixed') {
        $stored = (int) ($rule['fixed_variant_id'] ?? $rule['fixed_product_id'] ?? 0);
        $pid = $stored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $stored) : 0;

        return $pid > 0 ? [$pid] : [];
    }
    $pool = $rule['pool_variant_ids'] ?? $rule['pool_product_ids'] ?? [];
    if (!is_array($pool)) {
        return [];
    }
    $ids = [];
    foreach ($pool as $sid) {
        $pid = orange_cart_promo_resolve_stored_product_id($pdo, (int) $sid);
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }

    return array_keys($ids);
}

function orange_cart_gift_storefront_product_href(int $productId): string
{
    if ($productId <= 0) {
        return '';
    }
    $q = ['id' => $productId];
    if (function_exists('current_lang')) {
        $lang = current_lang();
        if ($lang !== '') {
            $q['lang'] = $lang;
        }
    }
    if (!empty($_GET['channel'])) {
        $q['channel'] = (string) $_GET['channel'];
    }

    return storefront_public_path('/pages/product.php') . '?' . http_build_query($q);
}

/**
 * @param list<int> $variantIds
 *
 * @return array<int,int>
 */
function orange_cart_gift_storefront_variant_stock_map(PDO $pdo, array $variantIds, int $countryId): array
{
    $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
    $map = [];
    foreach ($variantIds as $vid) {
        if ($vid > 0) {
            $map[$vid] = 0;
        }
    }
    if ($map === []) {
        return $map;
    }
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId > 0 && orange_warehouses_table_exists($pdo)) {
        $ph = implode(',', array_fill(0, count($variantIds), '?'));
        $st = $pdo->prepare(
            'SELECT variant_id, quantity FROM warehouse_variant_stock
             WHERE warehouse_id = ? AND variant_id IN (' . $ph . ')'
        );
        $st->execute(array_merge([$warehouseId], $variantIds));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vid = (int) ($row['variant_id'] ?? 0);
            if ($vid > 0) {
                $map[$vid] = (int) ($row['quantity'] ?? 0);
            }
        }
    }
    if (orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)) {
        $missing = [];
        foreach ($map as $vid => $qty) {
            if ($qty <= 0) {
                $missing[] = $vid;
            }
        }
        if ($missing !== [] && orange_table_exists($pdo, 'product_variants')) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $st = $pdo->prepare(
                'SELECT id, stock_quantity FROM product_variants WHERE id IN (' . $ph . ')'
            );
            $st->execute($missing);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int) ($row['id'] ?? 0);
                if ($vid > 0 && (int) ($map[$vid] ?? 0) <= 0) {
                    $map[$vid] = (int) ($row['stock_quantity'] ?? 0);
                }
            }
        }
    }

    return $map;
}

/**
 * بطاقات منتجات الهدية (مجمّع — بلا N+1 على المنتجات/المتغيّرات).
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return list<array<string,mixed>>
 */
function orange_cart_gift_storefront_pool_products(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    bool $lockVariants,
    ?int $countryId = null
): array {
    $productIds = orange_cart_gift_storefront_rule_product_ids($pdo, $rule);
    if ($productIds === []) {
        return [];
    }
    $stockCountryId = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $cfg = orange_cart_gift_pool_config_for_rule($pdo, $rule);
    $lockSql = $lockVariants ? ' FOR UPDATE' : '';

    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $pStmt = $pdo->prepare(
        'SELECT * FROM products WHERE id IN (' . $ph . ') AND is_active = 1'
    );
    $pStmt->execute($productIds);
    $productsById = [];
    while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
        $productsById[(int) ($row['id'] ?? 0)] = $row;
    }

    $vStmt = $pdo->prepare(
        'SELECT v.* FROM product_variants v
         INNER JOIN products p ON p.id = v.product_id
         WHERE v.product_id IN (' . $ph . ') AND p.is_active = 1
         ORDER BY v.product_id ASC, v.id ASC' . $lockSql
    );
    $vStmt->execute($productIds);
    $variantsByProduct = [];
    $allVariantIds = [];
    while ($row = $vStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) ($row['product_id'] ?? 0);
        $vid = (int) ($row['id'] ?? 0);
        if ($pid <= 0 || $vid <= 0) {
            continue;
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            continue;
        }
        $variantsByProduct[$pid][] = $row;
        $allVariantIds[] = $vid;
    }

    $stockMap = orange_cart_gift_storefront_variant_stock_map($pdo, $allVariantIds, $stockCountryId);

    $imageFallback = function_exists('orange_product_first_colorway_image_map')
        ? orange_product_first_colorway_image_map($pdo, $productIds)
        : [];

    $cards = [];
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0 || !isset($productsById[$pid])) {
            continue;
        }
        $product = $productsById[$pid];
        $chargeItem = orange_cart_gift_pool_config_find_item($cfg, $pid);
        $chargeKind = $chargeItem !== null
            ? (string) ($chargeItem['charge_kind'] ?? 'free')
            : (string) ($rule['gift_unit_charge_kind'] ?? 'free');
        $chargeVal = $chargeItem !== null
            ? (float) ($chargeItem['charge_value'] ?? 0)
            : (float) ($rule['gift_unit_charge_value'] ?? 0);

        $mainFile = trim((string) ($product['main_image'] ?? ''));
        if ($mainFile === '' && !empty($imageFallback[$pid])) {
            $mainFile = (string) $imageFallback[$pid];
        }
        $imageUrl = $mainFile !== '' ? storefront_product_image_href($mainFile) : '';

        $variantsOut = [];
        $defaultVariant = null;
        foreach ($variantsByProduct[$pid] ?? [] as $vRow) {
            $vid = (int) ($vRow['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $used = orange_cart_gift_variant_usage_in_lines($validatedItems, $vid);
            $stockRaw = (int) ($stockMap[$vid] ?? 0);
            $stockAvail = $stockRaw - $used;
            if ($stockAvail < 1) {
                continue;
            }
            $retail = round(orange_variant_effective_price($product, $vRow), 4);
            $charge = orange_cart_gift_pool_config_charge_unit($retail, $chargeKind, $chargeVal);
            $vOut = [
                'variant_id' => $vid,
                'color' => (string) ($vRow['color'] ?? ''),
                'size' => (string) ($vRow['size'] ?? ''),
                'stock' => $stockAvail,
                'retail_unit' => $retail,
                'charge_unit' => $charge,
                'is_fully_free' => orange_cart_gift_product_charge_is_fully_free($charge),
            ];
            $variantsOut[] = $vOut;
            if ($defaultVariant === null) {
                $defaultVariant = $vOut;
            }
        }
        if ($defaultVariant === null) {
            continue;
        }

        $cards[] = [
            'product_id' => $pid,
            'product_name' => storefront_product_display_name($product),
            'image_url' => $imageUrl,
            'product_url' => orange_cart_gift_storefront_product_href($pid),
            'retail_unit' => (float) ($defaultVariant['retail_unit'] ?? 0),
            'charge_unit' => (float) ($defaultVariant['charge_unit'] ?? 0),
            'is_fully_free' => (bool) ($defaultVariant['is_fully_free'] ?? true),
            'requires_decline' => !((bool) ($defaultVariant['is_fully_free'] ?? true)),
            'charge_kind' => orange_cart_gift_pool_config_normalize_charge_kind($chargeKind),
            'variants' => $variantsOut,
            'variant_id' => (int) ($defaultVariant['variant_id'] ?? 0),
            'color' => (string) ($defaultVariant['color'] ?? ''),
            'size' => (string) ($defaultVariant['size'] ?? ''),
            'stock' => (int) ($defaultVariant['stock'] ?? 0),
        ];
    }

    return $cards;
}

/**
 * @param list<array<string,mixed>> $cards
 */
function orange_cart_gift_storefront_cards_max_charge(array $cards): float
{
    $max = 0.0;
    foreach ($cards as $card) {
        foreach ($card['variants'] ?? [] as $v) {
            $c = (float) ($v['charge_unit'] ?? 0);
            if ($c > $max) {
                $max = $c;
            }
        }
    }

    return round($max, 4);
}

function orange_cart_gift_storefront_session_key(int $promoId): string
{
    return 'orange_cart_gift_selections_' . max(0, $promoId);
}

/**
 * @return list<array{product_id:int,variant_id:int,accepted:bool,declined:bool}>
 */
function orange_cart_gift_storefront_parse_selections_array(array $raw): array
{
    $out = [];
    $seen = [];
    foreach ($raw as $it) {
        if (!is_array($it)) {
            continue;
        }
        $vid = (int) ($it['variant_id'] ?? 0);
        if ($vid <= 0 || isset($seen[$vid])) {
            continue;
        }
        $seen[$vid] = true;
        $accepted = !empty($it['accepted']);
        $declined = !empty($it['declined']);
        if ($declined && $accepted) {
            $accepted = false;
        }
        $out[] = [
            'product_id' => (int) ($it['product_id'] ?? 0),
            'variant_id' => $vid,
            'accepted' => $accepted,
            'declined' => $declined,
        ];
    }

    return $out;
}

/**
 * @param array<string,mixed> $data
 *
 * @return list<array{product_id:int,variant_id:int,accepted:bool,declined:bool}>
 */
function orange_cart_gift_storefront_parse_request_selections(array $data): array
{
    if (isset($data['gift_selections']) && is_array($data['gift_selections'])) {
        return orange_cart_gift_storefront_parse_selections_array($data['gift_selections']);
    }
    $vid = (int) ($data['gift_variant_id'] ?? 0);
    if ($vid > 0) {
        return [[
            'product_id' => 0,
            'variant_id' => $vid,
            'accepted' => true,
            'declined' => false,
        ]];
    }

    return [];
}

/**
 * @param array<string,mixed> $data
 *
 * @return list<array{product_id:int,variant_id:int,accepted:bool,declined:bool}>
 */
function orange_cart_gift_storefront_load_selections(int $promoId, array $data): array
{
    $fromReq = orange_cart_gift_storefront_parse_request_selections($data);
    if ($fromReq !== []) {
        return $fromReq;
    }
    if ($promoId <= 0) {
        return [];
    }
    $key = orange_cart_gift_storefront_session_key($promoId);
    if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
        return orange_cart_gift_storefront_parse_selections_array($_SESSION[$key]);
    }

    return [];
}

/**
 * @param list<array<string,mixed>> $selections
 */
function orange_cart_gift_storefront_save_selections(int $promoId, array $selections): void
{
    if ($promoId <= 0) {
        return;
    }
    $store = [];
    foreach ($selections as $sel) {
        if (!is_array($sel)) {
            continue;
        }
        $store[] = [
            'product_id' => (int) ($sel['product_id'] ?? 0),
            'variant_id' => (int) ($sel['variant_id'] ?? 0),
            'accepted' => !empty($sel['accepted']),
            'declined' => !empty($sel['declined']),
        ];
    }
    $_SESSION[orange_cart_gift_storefront_session_key($promoId)] = $store;
}

/**
 * @return array<int,array{product_id:int,variant_id:int,retail_unit:float,charge_unit:float,is_fully_free:bool}>
 */
function orange_cart_gift_storefront_allowed_variant_map(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): array {
    $cards = orange_cart_gift_storefront_pool_products($pdo, $rule, $validatedItems, false, $countryId);
    $map = [];
    foreach ($cards as $card) {
        $pid = (int) ($card['product_id'] ?? 0);
        foreach ($card['variants'] ?? [] as $v) {
            if (!is_array($v)) {
                continue;
            }
            $vid = (int) ($v['variant_id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $map[$vid] = [
                'product_id' => $pid,
                'variant_id' => $vid,
                'retail_unit' => (float) ($v['retail_unit'] ?? 0),
                'charge_unit' => (float) ($v['charge_unit'] ?? 0),
                'is_fully_free' => (bool) ($v['is_fully_free'] ?? true),
            ];
        }
    }

    return $map;
}

/**
 * @param list<array{product_id:int,variant_id:int,accepted:bool,declined:bool}> $rawSelections
 *
 * @return list<array{product_id:int,variant_id:int,accepted:bool,declined:bool,valid:bool,retail_unit:float,charge_unit:float,is_fully_free:bool}>
 */
function orange_cart_gift_storefront_resolve_selections(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    array $rawSelections,
    ?int $countryId = null
): array {
    $kind = (string) ($rule['gift_kind'] ?? 'choice');
    $maxPick = max(1, (int) ($rule['max_gifts_pickable'] ?? 1));
    $allowed = orange_cart_gift_storefront_allowed_variant_map($pdo, $rule, $validatedItems, $countryId);

    if ($rawSelections === [] && $kind === 'fixed') {
        foreach ($allowed as $vid => $info) {
            return [[
                'product_id' => (int) $info['product_id'],
                'variant_id' => (int) $vid,
                'accepted' => true,
                'declined' => false,
                'valid' => true,
                'retail_unit' => (float) $info['retail_unit'],
                'charge_unit' => (float) $info['charge_unit'],
                'is_fully_free' => (bool) $info['is_fully_free'],
            ]];
        }

        return [];
    }

    $out = [];
    $acceptedCount = 0;
    foreach ($rawSelections as $raw) {
        $vid = (int) ($raw['variant_id'] ?? 0);
        $accepted = !empty($raw['accepted']);
        $declined = !empty($raw['declined']);
        if ($declined && $accepted) {
            $accepted = false;
        }
        $info = $allowed[$vid] ?? null;
        if ($info === null) {
            $out[] = [
                'product_id' => (int) ($raw['product_id'] ?? 0),
                'variant_id' => $vid,
                'accepted' => false,
                'declined' => $declined,
                'valid' => false,
                'retail_unit' => 0.0,
                'charge_unit' => 0.0,
                'is_fully_free' => true,
            ];
            continue;
        }
        if ($info['is_fully_free']) {
            $declined = false;
        }
        if ($accepted) {
            if ($kind === 'fixed' && $acceptedCount >= 1) {
                $accepted = false;
            } elseif ($kind === 'choice' && $acceptedCount >= $maxPick) {
                $accepted = false;
            } else {
                $acceptedCount++;
            }
        }
        $out[] = [
            'product_id' => (int) $info['product_id'],
            'variant_id' => (int) $vid,
            'accepted' => $accepted,
            'declined' => $declined && !$info['is_fully_free'],
            'valid' => true,
            'retail_unit' => (float) $info['retail_unit'],
            'charge_unit' => (float) $info['charge_unit'],
            'is_fully_free' => (bool) $info['is_fully_free'],
        ];
    }

    return $out;
}

/**
 * تحقق جاهزية إرسال الطلب (choice: موافقة واحدة على الأقل؛ fixed مدفوع: قرار صريح accept/decline).
 *
 * @param list<array{product_id:int,variant_id:int,accepted:bool,declined:bool}> $rawSelections
 * @param list<array<string,mixed>> $resolvedSelections
 *
 * @throws RuntimeException
 */
function orange_cart_gift_storefront_assert_checkout_ready(
    array $rule,
    array $rawSelections,
    array $resolvedSelections
): void {
    foreach ($resolvedSelections as $sel) {
        if (empty($sel['valid']) && (int) ($sel['variant_id'] ?? 0) > 0) {
            throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift choice.');
        }
    }

    $kind = (string) ($rule['gift_kind'] ?? 'choice');
    $accepted = [];
    foreach ($resolvedSelections as $sel) {
        if (!empty($sel['valid']) && !empty($sel['accepted']) && empty($sel['declined'])) {
            $accepted[] = $sel;
        }
    }

    if ($kind === 'choice') {
        if ($accepted === []) {
            throw new RuntimeException(function_exists('t') ? t('checkout_gift_pick_required') : 'Choose your free gift.');
        }

        return;
    }

    $hasPaid = false;
    foreach ($resolvedSelections as $sel) {
        if (!empty($sel['valid']) && empty($sel['is_fully_free'])) {
            $hasPaid = true;
            break;
        }
    }
    if (!$hasPaid) {
        return;
    }

    if ($rawSelections === []) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_pick_required') : 'Choose your free gift.');
    }

    $decided = false;
    foreach ($resolvedSelections as $sel) {
        if (empty($sel['valid'])) {
            continue;
        }
        if (!empty($sel['accepted']) || !empty($sel['declined'])) {
            $decided = true;
            break;
        }
    }
    if (!$decided) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_pick_required') : 'Choose your free gift.');
    }
}

/**
 * @param list<array<string,mixed>> $selections
 */
function orange_cart_gift_storefront_charge_from_selections(array $selections): float
{
    $sum = 0.0;
    foreach ($selections as $sel) {
        if (empty($sel['valid']) || empty($sel['accepted']) || !empty($sel['declined'])) {
            continue;
        }
        $sum += (float) ($sel['charge_unit'] ?? 0);
    }

    return round($sum, 4);
}

/**
 * @param list<array<string,mixed>> $selections
 */
function orange_cart_gift_storefront_discount_from_selections(array $selections): float
{
    $sum = 0.0;
    foreach ($selections as $sel) {
        if (empty($sel['valid']) || empty($sel['accepted']) || !empty($sel['declined'])) {
            continue;
        }
        $retail = (float) ($sel['retail_unit'] ?? 0);
        $charge = (float) ($sel['charge_unit'] ?? 0);
        if ($retail < $charge) {
            $retail = $charge;
        }
        $sum += max(0.0, $retail - $charge);
    }

    return round($sum, 4);
}

/**
 * @param list<array<string,mixed>> $selections
 *
 * @return list<array<string,mixed>>
 */
function orange_cart_gift_storefront_selections_for_response(array $selections): array
{
    $out = [];
    foreach ($selections as $sel) {
        if (!is_array($sel)) {
            continue;
        }
        $out[] = [
            'product_id' => (int) ($sel['product_id'] ?? 0),
            'variant_id' => (int) ($sel['variant_id'] ?? 0),
            'accepted' => !empty($sel['accepted']),
            'declined' => !empty($sel['declined']),
            'valid' => !empty($sel['valid']),
            'retail_unit' => round((float) ($sel['retail_unit'] ?? 0), 4),
            'charge_unit' => round((float) ($sel['charge_unit'] ?? 0), 4),
            'is_fully_free' => !empty($sel['is_fully_free']),
        ];
    }

    return $out;
}

/**
 * @param list<array<string,mixed>> $pool
 * @param list<array<string,mixed>> $selections
 *
 * @return list<array<string,mixed>>
 */
function orange_cart_gift_storefront_apply_selections_to_pool(array $pool, array $selections): array
{
    $acceptedVids = [];
    foreach ($selections as $sel) {
        if (!empty($sel['valid']) && !empty($sel['accepted']) && empty($sel['declined'])) {
            $acceptedVids[(int) ($sel['variant_id'] ?? 0)] = true;
        }
    }
    $out = [];
    foreach ($pool as $card) {
        if (!is_array($card)) {
            continue;
        }
        $card['selected'] = isset($acceptedVids[(int) ($card['variant_id'] ?? 0)]);
        $out[] = $card;
    }

    return $out;
}

/**
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_gift_threshold_preview_max_unit_charge(PDO $pdo, array $rule, array $validatedItems): float
{
    $cards = orange_cart_gift_storefront_pool_products($pdo, $rule, $validatedItems, false);

    return orange_cart_gift_storefront_cards_max_charge($cards);
}

/**
 * Payload موحّد لهدية مجموع السلة — checkout-preview والواجهة (مرحلة 2+).
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @param array<string,mixed>|null $requestData POST body (gift_selections / gift_variant_id)
 *
 * @return array<string,mixed>|null
 */
function orange_cart_gift_storefront_payload(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    ?int $countryId = null,
    ?array $requestData = null
): ?array {
    $rule['promo_type'] = 'threshold_gift';
    $kind = (string) ($rule['gift_kind'] ?? 'choice');
    $showOld = (int) ($rule['show_old_price_to_customer'] ?? 0) === 1;
    $maxPick = max(1, (int) ($rule['max_gifts_pickable'] ?? 1));
    $promoId = (int) ($rule['id'] ?? 0);
    $req = is_array($requestData) ? $requestData : [];
    $rawFromReq = orange_cart_gift_storefront_parse_request_selections($req);
    $hasRequestSelections = $rawFromReq !== [];
    $rawSelections = $hasRequestSelections
        ? $rawFromReq
        : orange_cart_gift_storefront_load_selections($promoId, $req);

    $commonMeta = [
        'show_name_to_customer' => (int) ($rule['show_name_to_customer'] ?? 0) === 1,
        'show_old_price' => $showOld,
        'display_name' => (string) ($rule['display_name'] ?? ''),
    ];

    if ($kind === 'fixed') {
        $cards = orange_cart_gift_storefront_pool_products($pdo, $rule, $validatedItems, false, $countryId);
        if ($cards === []) {
            return null;
        }
        $fixed = $cards[0];
        $previewMax = orange_cart_gift_storefront_cards_max_charge($cards);
        $selections = orange_cart_gift_storefront_resolve_selections(
            $pdo,
            $rule,
            $validatedItems,
            $rawSelections,
            $countryId
        );
        if ($hasRequestSelections) {
            orange_cart_gift_storefront_save_selections($promoId, $selections);
        }
        $chargePreview = orange_cart_gift_storefront_charge_from_selections($selections);
        if ($selections === []) {
            $chargePreview = $previewMax;
        }
        $fixed['selected'] = true;

        return array_merge($commonMeta, [
            'id' => $promoId,
            'gift_kind' => 'fixed',
            'promo_type' => 'threshold_gift',
            'fixed_variant_id' => (int) ($fixed['variant_id'] ?? 0),
            'fixed_product' => $fixed,
            'pool' => [],
            'max_gifts_pickable' => 1,
            'preview_max_gift_unit_charge' => $previewMax,
            'gift_charge_preview' => $chargePreview,
            'gift_discount_preview' => orange_cart_gift_storefront_discount_from_selections($selections),
            'gift_selections' => orange_cart_gift_storefront_selections_for_response($selections),
            'gift_unit_charge_kind' => (string) ($fixed['charge_kind'] ?? 'free'),
            'gift_unit_charge_value' => (float) ($fixed['charge_unit'] ?? 0),
        ]);
    }

    $pool = orange_cart_gift_storefront_pool_products($pdo, $rule, $validatedItems, false, $countryId);
    if ($pool === []) {
        return null;
    }
    $previewMax = orange_cart_gift_storefront_cards_max_charge($pool);
    $selections = orange_cart_gift_storefront_resolve_selections(
        $pdo,
        $rule,
        $validatedItems,
        $rawSelections,
        $countryId
    );
    if ($hasRequestSelections) {
        orange_cart_gift_storefront_save_selections($promoId, $selections);
    }
    $chargePreview = orange_cart_gift_storefront_charge_from_selections($selections);
    if ($selections === []) {
        $chargePreview = $previewMax;
    }
    $pool = orange_cart_gift_storefront_apply_selections_to_pool($pool, $selections);

    return array_merge($commonMeta, [
        'id' => $promoId,
        'gift_kind' => 'choice',
        'promo_type' => 'threshold_gift',
        'fixed_variant_id' => null,
        'fixed_product' => null,
        'pool' => $pool,
        'max_gifts_pickable' => $maxPick,
        'preview_max_gift_unit_charge' => $previewMax,
        'gift_charge_preview' => $chargePreview,
        'gift_discount_preview' => orange_cart_gift_storefront_discount_from_selections($selections),
        'gift_selections' => orange_cart_gift_storefront_selections_for_response($selections),
        'gift_unit_charge_kind' => $previewMax <= 0.00001 ? 'free' : 'mixed',
        'gift_unit_charge_value' => $previewMax,
    ]);
}
