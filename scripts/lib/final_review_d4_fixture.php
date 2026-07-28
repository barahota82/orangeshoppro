<?php

declare(strict_types=1);

/**
 * FSR Batch D4 — disposable MySQL Promotions / Loyalty fixture helpers (test-only).
 * Extends D3 bootstrap; never touches Production .env.php / Production data.
 */

require_once __DIR__ . '/final_review_d3_fixture.php';

/**
 * Load Promotion + Loyalty production helpers without config.php / .env.php.
 */
function orange_d4_load_production_helpers(string $projectRoot): void
{
    orange_d3_load_production_helpers($projectRoot);
    // Storefront helpers live in config.php — test-only stubs (no .env.php / Production config).
    if (!defined('PUBLIC_BASE_PATH')) {
        define('PUBLIC_BASE_PATH', '');
    }
    if (!function_exists('storefront_public_path')) {
        function storefront_public_path(string $path): string
        {
            $path = '/' . ltrim($path, '/');

            return (PUBLIC_BASE_PATH === '' ? '' : rtrim(PUBLIC_BASE_PATH, '/')) . $path;
        }
    }
    if (!function_exists('storefront_product_display_name')) {
        function storefront_product_display_name(array $product): string
        {
            foreach (['name_ar', 'name', 'name_en', 'title'] as $k) {
                $v = trim((string) ($product[$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }

            return 'product';
        }
    }
    if (!function_exists('current_lang')) {
        function current_lang(): string
        {
            return 'ar';
        }
    }
    require_once $projectRoot . '/includes/cart_promotion_country.php';
    require_once $projectRoot . '/includes/cart_promo_schedule.php';
    require_once $projectRoot . '/includes/promo_always_on.php';
    require_once $projectRoot . '/includes/cart_promo_products.php';
    require_once $projectRoot . '/includes/cart_promo_gift_charge.php';
    require_once $projectRoot . '/includes/cart_promo_stock_health.php';
    require_once $projectRoot . '/includes/cart_promotions.php';
    require_once $projectRoot . '/includes/cart_gift_promotions.php';
    require_once $projectRoot . '/includes/cart_gift_pool_config.php';
    require_once $projectRoot . '/includes/cart_bogo_promotions.php';
    require_once $projectRoot . '/includes/cart_combo_promotions.php';
    require_once $projectRoot . '/includes/product_offers.php';
    require_once $projectRoot . '/includes/storefront_checkout_promo_lines.php';
    require_once $projectRoot . '/includes/delivery_areas.php';
    require_once $projectRoot . '/includes/loyalty.php';
    require_once $projectRoot . '/includes/order_helpers.php';
}

/**
 * @return array{
 *   ok:bool,
 *   pdo?:PDO,
 *   db_name?:string,
 *   cleanup?:callable,
 *   ids?:array<string,int|string>,
 *   schema?:array<string,mixed>,
 *   error?:string,
 *   env?:string
 * }
 */
function orange_d4_bootstrap_isolated_db(string $projectRoot): array
{
    $boot = orange_d3_bootstrap_isolated_db($projectRoot);
    if (empty($boot['ok'])) {
        return $boot;
    }

    /** @var PDO $pdo */
    $pdo = $boot['pdo'];
    /** @var array<string,int|string> $ids */
    $ids = $boot['ids'] ?? [];
    $dbName = (string) ($boot['db_name'] ?? '');

    try {
        orange_d4_load_production_helpers($projectRoot);
        orange_d2_set_admin_country((int) ($ids['kw_country_id'] ?? 1), 'kw');
        $extra = orange_d4_seed_promo_loyalty_spine($pdo, $ids);
        $ids = array_merge($ids, $extra);
        orange_d4_verify_promo_loyalty_schema($pdo);
        orange_d2_seal_schema_gate($pdo, $dbName);
        $boot['ids'] = $ids;
        $boot['env'] = 'MYSQL_DISPOSABLE_D4';

        return $boot;
    } catch (Throwable $e) {
        if (isset($boot['cleanup']) && is_callable($boot['cleanup'])) {
            ($boot['cleanup'])();
        }

        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }
}

function orange_d4_php_bin(): string
{
    return orange_d3_php_bin();
}

/**
 * @param array<string,int|string> $ids
 * @return array<string,int|string>
 */
function orange_d4_seed_promo_loyalty_spine(PDO $pdo, array $ids): array
{
    $kw = (int) ($ids['kw_country_id'] ?? 1);
    $eg = (int) ($ids['eg_country_id'] ?? 2);
    $kwWh = (int) ($ids['kw_warehouse_id'] ?? 10);
    $now = gmdate('Y-m-d H:i:s');
    $farPast = '2020-01-01 00:00:00';
    $farFuture = '2035-12-31 23:59:59';
    $windowFrom = '2026-01-01 00:00:00';
    $windowTo = '2026-12-31 23:59:59';

    // Extra KW products for combo / gift / BOGO
    orange_d1_insert_if_table($pdo, 'products', [
        [
            'id' => 510,
            'country_id' => $kw,
            'name' => 'KW Combo A',
            'name_en' => 'KW Combo A',
            'product_type_id' => 1,
            'price' => 12.0000,
            'cost' => 5.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 511,
            'country_id' => $kw,
            'name' => 'KW Combo B',
            'name_en' => 'KW Combo B',
            'product_type_id' => 1,
            'price' => 8.0000,
            'cost' => 3.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 512,
            'country_id' => $kw,
            'name' => 'KW Gift',
            'name_en' => 'KW Gift',
            'product_type_id' => 1,
            'price' => 5.0000,
            'cost' => 1.0000,
            'is_active' => 1,
            'created_at' => $now,
        ],
        [
            'id' => 513,
            'country_id' => $kw,
            'name' => 'KW Inactive',
            'name_en' => 'KW Inactive',
            'product_type_id' => 1,
            'price' => 9.0000,
            'cost' => 2.0000,
            'is_active' => 0,
            'created_at' => $now,
        ],
    ], true);

    orange_d1_insert_if_table($pdo, 'product_variants', [
        [
            'id' => 610,
            'product_id' => 510,
            'item_code' => 'KW-510-A',
            'price' => 12.0000,
            'cost' => 5.0000,
            'stock_quantity' => 200,
        ],
        [
            'id' => 611,
            'product_id' => 511,
            'item_code' => 'KW-511-A',
            'price' => 8.0000,
            'cost' => 3.0000,
            'stock_quantity' => 200,
        ],
        [
            'id' => 612,
            'product_id' => 512,
            'item_code' => 'KW-512-G',
            'price' => 5.0000,
            'cost' => 1.0000,
            'stock_quantity' => 50,
        ],
        [
            'id' => 613,
            'product_id' => 513,
            'item_code' => 'KW-513-X',
            'price' => 9.0000,
            'cost' => 2.0000,
            'stock_quantity' => 0,
        ],
    ], true);

    foreach ([600, 610, 611, 612] as $vid) {
        orange_d1_insert_if_table($pdo, 'warehouse_variant_stock', [
            [
                'warehouse_id' => $kwWh,
                'variant_id' => $vid,
                'quantity' => 100,
            ],
        ], true);
    }

    // Loyalty settings — predictable rates (not Production dump rates).
    orange_d1_insert_if_table($pdo, 'loyalty_settings', [
        [
            'id' => 1,
            'country_id' => $kw,
            'is_active' => 1,
            'earn_rate' => 1.000000,
            'point_value' => 0.010000,
            'min_redeem_points' => 5,
            'max_redeem_pct' => 50.00,
            'expiry_months' => 12,
            'created_at' => $now,
        ],
        [
            'id' => 2,
            'country_id' => $eg,
            'is_active' => 1,
            'earn_rate' => 1.000000,
            'point_value' => 0.010000,
            'min_redeem_points' => 5,
            'max_redeem_pct' => 50.00,
            'expiry_months' => 6,
            'created_at' => $now,
        ],
    ], true);

    // Cart-total promotions
    orange_d1_insert_if_table($pdo, 'cart_promotions', [
        [
            'id' => 1,
            'country_id' => $kw,
            'name_ar' => 'خصم سلة KW',
            'name_en' => 'KW cart',
            'min_subtotal' => 20.0000,
            'discount_amount' => 3.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
        ],
        [
            'id' => 2,
            'country_id' => $kw,
            'name_ar' => 'خصم نافذة',
            'name_en' => 'Window cart',
            'min_subtotal' => 10.0000,
            'discount_amount' => 1.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'sort_order' => 2,
            'is_active' => 1,
            'is_always_on' => 0,
            'valid_from' => $windowFrom,
            'valid_to' => $windowTo,
            'show_name_to_customer' => 1,
        ],
        [
            'id' => 3,
            'country_id' => $kw,
            'name_ar' => 'مستقبل',
            'name_en' => 'Future cart',
            'min_subtotal' => 5.0000,
            'discount_amount' => 9.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'sort_order' => 3,
            'is_active' => 1,
            'is_always_on' => 0,
            'valid_from' => '2030-01-01 00:00:00',
            'valid_to' => '2030-12-31 23:59:59',
            'show_name_to_customer' => 1,
        ],
        [
            'id' => 4,
            'country_id' => $eg,
            'name_ar' => 'خصم مصر',
            'name_en' => 'EG cart',
            'min_subtotal' => 15.0000,
            'discount_amount' => 2.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
        ],
        [
            'id' => 5,
            'country_id' => $kw,
            'name_ar' => 'أول طلب مسلّم',
            'name_en' => 'First delivered only',
            'min_subtotal' => 5.0000,
            'discount_amount' => 1.5000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 1,
            'sort_order' => 5,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
        ],
    ], true);

    // Gift promotion (fixed product id stored in fixed_variant_id column per storefront contract)
    orange_d1_insert_if_table($pdo, 'cart_gift_promotions', [
        [
            'id' => 1,
            'country_id' => $kw,
            'name_ar' => 'هدية سلة',
            'name_en' => 'Cart gift',
            'min_subtotal' => 25.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'gift_kind' => 'fixed',
            'fixed_variant_id' => 512,
            'pool_variant_ids' => null,
            'gift_unit_charge_kind' => 'free',
            'gift_unit_charge_value' => 0.0000,
            'sort_order' => 1,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
            'max_gifts_pickable' => 1,
        ],
    ], true);

    // Combo: 510+511 → combo_price 15 (retail 20 → save 5)
    orange_d1_insert_if_table($pdo, 'cart_combo_promotions', [
        [
            'id' => 1,
            'country_id' => $kw,
            'title_ar' => 'كومبو KW',
            'title_en' => 'KW combo',
            'components_json' => json_encode([
                ['product_id' => 510, 'qty' => 1],
                ['product_id' => 511, 'qty' => 1],
            ], JSON_UNESCAPED_UNICODE),
            'combo_price' => 15.0000,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
        ],
    ], true);

    // BOGO same product: buy 2 of 500 get gift 512
    orange_d1_insert_if_table($pdo, 'cart_bogo_promotions', [
        [
            'id' => 1,
            'country_id' => $kw,
            'name_ar' => 'BOGO KW',
            'name_en' => 'BOGO KW',
            'bogo_kind' => 'same_variant',
            'same_variant_product_id' => 500,
            'min_buy_qty' => 2,
            'requires_registered_account' => 0,
            'first_delivered_order_only' => 0,
            'gift_kind' => 'fixed',
            'fixed_variant_id' => 512,
            'pool_variant_ids' => null,
            'gift_unit_charge_kind' => 'free',
            'gift_unit_charge_value' => 0.0000,
            'sort_order' => 1,
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'show_name_to_customer' => 1,
            'max_gifts_pickable' => 1,
        ],
    ], true);

    // Product offer on 500 (amount 2)
    orange_d1_insert_if_table($pdo, 'offers', [
        [
            'id' => 1,
            'product_id' => 500,
            'discount' => 2.00,
            'discount_type' => 'amount',
            'is_active' => 1,
            'is_always_on' => 1,
            'valid_from' => $farPast,
            'valid_to' => $farFuture,
            'sort_order' => 1,
            'name_ar' => 'عرض منتج',
            'name_en' => 'Product offer',
            'show_name_to_customer' => 1,
        ],
    ], true);

    // Delivery spine
    if (orange_table_exists($pdo, 'delivery_governorates')) {
        $gov = [
            'id' => 1,
            'country_id' => $kw,
            'name_ar' => 'محافظة KW',
            'name_en' => 'KW Gov',
            'is_active' => 1,
            'sort_order' => 1,
        ];
        if (orange_d1_has_column($pdo, 'delivery_governorates', 'default_delivery_fee')) {
            $gov['default_delivery_fee'] = 2.0000;
        }
        if (orange_d1_has_column($pdo, 'delivery_governorates', 'default_company_delivery_cost')) {
            $gov['default_company_delivery_cost'] = 1.0000;
        }
        orange_d1_insert_if_table($pdo, 'delivery_governorates', [$gov], true);
    }
    if (orange_table_exists($pdo, 'delivery_areas')) {
        $areaRow = [
            'id' => 1,
            'country_id' => $kw,
            'name_ar' => 'منطقة KW',
            'name_en' => 'KW Area',
            'delivery_fee' => 2.0000,
            'is_active' => 1,
            'sort_order' => 1,
        ];
        if (orange_d1_has_column($pdo, 'delivery_areas', 'governorate_id')) {
            $areaRow['governorate_id'] = 1;
        }
        if (orange_d1_has_column($pdo, 'delivery_areas', 'company_delivery_cost')) {
            $areaRow['company_delivery_cost'] = 1.0000;
        }
        orange_d1_insert_if_table($pdo, 'delivery_areas', [$areaRow], true);
    }
    if (orange_table_exists($pdo, 'delivery_fee_promotions')) {
        orange_d1_insert_if_table($pdo, 'delivery_fee_promotions', [
            [
                'id' => 1,
                'country_id' => $kw,
                'name_ar' => 'توصيل مجاني',
                'name_en' => 'Free delivery',
                'discount_type' => 'free',
                'discount_value' => 0.0000,
                'requires_registered_account' => 0,
                'first_delivered_order_only' => 0,
                'valid_from' => '2026-01-01',
                'valid_to' => '2035-12-31',
                'sort_order' => 1,
                'is_active' => 1,
                'is_always_on' => 1,
                'show_name_to_customer' => 1,
            ],
            [
                'id' => 2,
                'country_id' => $eg,
                'name_ar' => 'خصم توصيل مصر',
                'name_en' => 'EG delivery',
                'discount_type' => 'amount',
                'discount_value' => 1.0000,
                'requires_registered_account' => 0,
                'first_delivered_order_only' => 0,
                'valid_from' => '2026-01-01',
                'valid_to' => '2035-12-31',
                'sort_order' => 1,
                'is_active' => 1,
                'is_always_on' => 1,
                'show_name_to_customer' => 1,
            ],
        ], true);
        if (orange_table_exists($pdo, 'delivery_fee_promotion_areas')) {
            orange_d1_insert_if_table($pdo, 'delivery_fee_promotion_areas', [
                ['id' => 1, 'promotion_id' => 1, 'delivery_area_id' => 1],
            ], true);
        }
    }

    return [
        'kw_product_combo_a' => 510,
        'kw_product_combo_b' => 511,
        'kw_product_gift' => 512,
        'kw_variant_combo_a' => 610,
        'kw_variant_combo_b' => 611,
        'kw_variant_gift' => 612,
        'kw_cart_promo_always' => 1,
        'kw_cart_promo_window' => 2,
        'kw_cart_promo_future' => 3,
        'eg_cart_promo' => 4,
        'kw_cart_promo_first_delivered' => 5,
        'kw_gift_promo' => 1,
        'kw_combo_promo' => 1,
        'kw_bogo_promo' => 1,
        'kw_product_offer' => 1,
        'kw_delivery_area' => 1,
        'kw_delivery_promo' => 1,
        'eg_delivery_promo' => 2,
    ];
}

function orange_d4_verify_promo_loyalty_schema(PDO $pdo): void
{
    foreach ([
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
        'offers',
        'loyalty_settings',
        'loyalty_ledger',
    ] as $t) {
        if (!orange_table_exists($pdo, $t)) {
            throw new RuntimeException('D4 schema missing table: ' . $t);
        }
    }
    if ((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION !== 124) {
        throw new RuntimeException('D4 requires Schema 124');
    }
}

/**
 * Build a storefront-validated cart line for promo helpers.
 *
 * @return array<string,mixed>
 */
function orange_d4_cart_line(int $productId, int $variantId, float $price, int $qty, float $cost = 0.0): array
{
    return [
        'product' => ['id' => $productId, 'name' => 'P' . $productId, 'is_active' => 1],
        'variant_id' => $variantId,
        'qty' => $qty,
        'price' => $price,
        'cost' => $cost,
        'color' => '',
        'size' => '',
    ];
}

/**
 * Mirror order_intake stacking order (product-offer partition → combo → cart).
 *
 * @param list<array<string,mixed>> $items
 * @return array<string,mixed>
 */
function orange_d4_evaluate_stack(
    PDO $pdo,
    array $items,
    bool $buyerRegistered,
    int $countryId,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): array {
    $subtotal = 0.0;
    foreach ($items as $ln) {
        $subtotal += round((float) ($ln['price'] ?? 0) * (int) ($ln['qty'] ?? 0), 4);
    }
    $subtotal = round($subtotal, 4);
    $offerPartition = orange_product_offer_partition_items($pdo, $items, $countryId);
    $nonOfferItems = $offerPartition['non_offer_items'];
    $nonOfferSubtotal = max(0.0, round($subtotal - (float) $offerPartition['offer_items_value'], 4));
    $comboPick = orange_cart_combo_best_match(
        $pdo,
        $nonOfferItems,
        $buyerRegistered,
        $countryId,
        $buyerAccountId,
        $buyerPhone
    );
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $cartPromoBase = max(0.0, round($nonOfferSubtotal - $comboDiscount, 4));
    $promoPick = orange_cart_promotion_resolve(
        $pdo,
        $cartPromoBase,
        $buyerRegistered,
        $countryId,
        $buyerAccountId,
        $buyerPhone
    );
    $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
    $productOfferDiscount = (float) $offerPartition['offer_discount'];
    $giftRule = orange_cart_gift_promotion_select_rule(
        $pdo,
        $subtotal,
        $buyerRegistered,
        $countryId,
        $buyerAccountId,
        $buyerPhone
    );
    $bogoRule = orange_cart_bogo_promotion_select_rule(
        $pdo,
        $items,
        $buyerRegistered,
        $countryId,
        $buyerAccountId,
        $buyerPhone
    );

    return [
        'subtotal' => $subtotal,
        'offer_discount' => $productOfferDiscount,
        'combo_id' => $comboPick['id'] ?? null,
        'combo_discount' => $comboDiscount,
        'cart_promo_id' => $promoPick['id'] ?? null,
        'cart_promo_discount' => $promoDiscount,
        'cart_promo_base' => $cartPromoBase,
        'gift_promo_id' => $giftRule['id'] ?? null,
        'bogo_promo_id' => $bogoRule['id'] ?? null,
        'payable_merch' => max(0.0, round($subtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4)),
    ];
}
