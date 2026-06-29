<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/sales_invoice_company.php';
require_once __DIR__ . '/../../../includes/sales_doc_channel.php';
require_once __DIR__ . '/../../../includes/cart_promotions.php';
require_once __DIR__ . '/../../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../../includes/cart_bogo_promotions.php';
require_once __DIR__ . '/../../../includes/cart_combo_promotions.php';
require_once __DIR__ . '/../../../includes/product_offers.php';
require_once __DIR__ . '/../../../includes/loyalty.php';
require_once __DIR__ . '/../../../includes/company_invoice_offers.php';
require_admin_api();

/**
 * كشف العروض المطابقة لبنود فاتورة الشركة (INV-C) ليؤكّدها المحاسب قبل التطبيق.
 * مرآة لمنطق api/cart/checkout-preview.php لكن في سياق الأدمن:
 * - دولة الطلب من القناة (لا دولة المتجر).
 * - «مسجّل» = وجود عميل محدَّد (customer_id / هاتف مطابق) لأهلية عروض المسجّلين والولاء.
 * لا يطبّق أي خصم ولا يكتب أي شيء — كشف فقط. التطبيق الفعلي في create-manual.php.
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        json_response(['success' => false, 'message' => 'أضف بنداً واحداً على الأقل'], 422);
    }

    $channelId = (int) ($data['channel_id'] ?? 0);
    $countryId = orange_sales_order_country_id_for_channel($pdo, $channelId);

    // عميل الفاتورة: يحدّد أهلية عروض «المسجّلين فقط» والولاء.
    $customerId = (int) ($data['customer_id'] ?? 0);
    $phone = trim((string) ($data['phone'] ?? ''));
    $resolvedCustomerId = $customerId;
    if ($resolvedCustomerId <= 0 && $phone !== '') {
        if (orange_table_has_country_id($pdo, 'customers') && $countryId > 0) {
            $cs = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND country_id = ? LIMIT 1');
            $cs->execute([$phone, $countryId]);
        } else {
            $cs = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
            $cs->execute([$phone]);
        }
        $resolvedCustomerId = (int) ($cs->fetchColumn() ?: 0);
    }

    // «مسجّل بالموقع» = حساب متجر مُفعَّل بالبريد بنفس الهاتف (يختلف عن مجرد سجل عميل).
    $registeredOnSite = false;
    if ($phone !== '' && orange_table_exists($pdo, 'storefront_accounts')) {
        $sa = $pdo->prepare(
            'SELECT id FROM storefront_accounts WHERE customer_phone = ? AND email_verified_at IS NOT NULL LIMIT 1'
        );
        $sa->execute([$phone]);
        $registeredOnSite = (bool) ($sa->fetchColumn());
    }

    // فاتورة الشركة تكشف كل العروض (سلطة تجاوز شرط التسجيل)؛ المحاسب يقرّر الصرف.
    $detectReg = true;

    // بناء البنود المُتحقَّق منها بنفس شكل مسار المتجر (دون رمي خطأ على المخزون — كشف فقط).
    $subtotal = 0.0;
    $validatedItems = [];
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? $item['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $productStmt->execute([$pid]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            continue;
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            continue;
        }

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $color = isset($item['color']) ? trim((string) $item['color']) : '';
        $size = isset($item['size']) ? trim((string) $item['size']) : '';
        $variantIdIn = (int) ($item['variant_id'] ?? 0);
        $variant = null;

        // مطابقة منطق الإنشاء (create-manual.php): الربط حسب عدد المتغيّرات الفعلي،
        // والسعر/التكلفة عبر COALESCE(variant, product) كي يطابق كشفُ العروض الفاتورةَ الفعلية.
        $variantCountStmt = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
        $variantCountStmt->execute([$pid]);
        $variantCount = (int) $variantCountStmt->fetchColumn();

        if ($variantCount > 0) {
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1');
                $vStmt->execute([$variantIdIn, $pid]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$variant && ($color !== '' || $size !== '')) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE product_id = ? AND color = ? AND size = ? LIMIT 1'
                );
                $vStmt->execute([$pid, $color, $size]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$variant && $variantCount === 1) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1'
                );
                $vStmt->execute([$pid]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$variant) {
                continue;
            }
        }

        $price = orange_variant_effective_price($product, $variant);
        $cost = orange_variant_effective_cost($product, $variant);
        $subtotal += $price * $qty;
        $validatedItems[] = [
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string) $variant['color'] : $color,
            'size' => $variant ? (string) $variant['size'] : $size,
            'variant_id' => $variant ? (int) $variant['id'] : 0,
            'price' => $price,
            'cost' => $cost,
        ];
    }

    if ($validatedItems === []) {
        json_response(['success' => false, 'message' => 'لا توجد بنود صالحة للكشف عن عروضها'], 422);
    }
    $subtotal = round($subtotal, 4);

    // اختيارات الخصومات بنفس صيَغ التطبيق (مصدر واحد مشترك: company_invoice_offers.php).
    $picks = orange_company_invoice_offer_picks($pdo, $validatedItems, $subtotal, $countryId);
    $comboDiscount = (float) $picks['combo_discount'];
    $comboId = $picks['combo_id'];
    $promo = $picks['promo'];
    $promoDiscount = (float) $picks['promo_discount'];
    $productOfferDiscount = (float) $picks['product_offer_discount'];

    // الهدية ومجموع السلة / BOGO: نعرض القاعدة المطابقة (إن وُجدت) ليختار المحاسب الهدية.
    $giftPayload = null;
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $detectReg, $countryId);
    if ($giftRule !== null) {
        if (($giftRule['gift_kind'] ?? '') === 'fixed') {
            $fv = (int) ($giftRule['fixed_variant_id'] ?? 0);
            $opts = $fv > 0 ? orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, false) : [];
            if (count($opts) > 0) {
                $giftPayload = ['id' => (int) $giftRule['id'], 'gift_kind' => 'fixed', 'fixed_variant_id' => $fv, 'pool' => []];
            }
        } else {
            $pool = orange_cart_gift_promotion_pool_options($pdo, $giftRule['pool_variant_ids'], $validatedItems, false);
            if (count($pool) > 0) {
                $giftPayload = ['id' => (int) $giftRule['id'], 'gift_kind' => 'choice', 'fixed_variant_id' => null, 'pool' => $pool];
            }
        }
        if ($giftPayload !== null) {
            $giftPayload['gift_unit_charge_kind'] = (string) ($giftRule['gift_unit_charge_kind'] ?? 'free');
            $giftPayload['gift_unit_charge_value'] = (float) ($giftRule['gift_unit_charge_value'] ?? 0);
            $giftPayload['preview_max_gift_unit_charge'] =
                orange_cart_promo_preview_gift_max_unit_charge($pdo, $giftRule, $validatedItems);
        }
    }

    $bogoPayload = null;
    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $detectReg, $countryId);
    if ($bogoRule !== null) {
        if (($bogoRule['gift_kind'] ?? '') === 'fixed') {
            $bfv = (int) ($bogoRule['fixed_variant_id'] ?? 0);
            $bopts = $bfv > 0 ? orange_cart_gift_promotion_pool_options($pdo, [$bfv], $validatedItems, false) : [];
            if (count($bopts) > 0) {
                $bogoPayload = ['id' => (int) $bogoRule['id'], 'bogo_kind' => $bogoRule['bogo_kind'], 'gift_kind' => 'fixed', 'fixed_variant_id' => $bfv, 'pool' => []];
            }
        } else {
            $bp = orange_cart_gift_promotion_pool_options($pdo, $bogoRule['pool_variant_ids'], $validatedItems, false);
            if (count($bp) > 0) {
                $bogoPayload = ['id' => (int) $bogoRule['id'], 'bogo_kind' => $bogoRule['bogo_kind'], 'gift_kind' => 'choice', 'fixed_variant_id' => null, 'pool' => $bp];
            }
        }
        if ($bogoPayload !== null) {
            $bogoPayload['gift_unit_charge_kind'] = (string) ($bogoRule['gift_unit_charge_kind'] ?? 'free');
            $bogoPayload['gift_unit_charge_value'] = (float) ($bogoRule['gift_unit_charge_value'] ?? 0);
            $bogoPayload['preview_max_gift_unit_charge'] =
                orange_cart_bogo_preview_gift_charge_upper_bound($pdo, $bogoRule, $validatedItems);
        }
    }

    // الولاء: الرصيد القابل للاستخدام مقابل المستحق بعد الخصومات (كسب/استبدال للعميل المسجّل فقط).
    $payableAfterDiscounts = max(0.0, round($subtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4));
    $loyalty = ['active' => false];
    if ($resolvedCustomerId > 0 && orange_loyalty_is_active($pdo, $countryId)) {
        $info = orange_loyalty_redeemable($pdo, $resolvedCustomerId, $countryId, $payableAfterDiscounts);
        $sLoy = orange_loyalty_settings($pdo, $countryId);
        $loyalty = [
            'active' => true,
            'customer_id' => $resolvedCustomerId,
            'balance' => (int) $info['balance'],
            'redeemable_points' => (int) $info['points'],
            'redeemable_value' => (float) $info['value'],
            'point_value' => $sLoy !== null ? (float) $sLoy['point_value'] : 0.0,
            'min_redeem_points' => $sLoy !== null ? (int) $sLoy['min_redeem_points'] : 0,
        ];
    }

    // شرط «للمسجّلين فقط» لكل عرض مكتشَف — ليوضّح للمحاسب أنه يتجاوزه عند الصرف لغير المسجّل.
    $requiresReg = static function (PDO $pdo, string $table, int $id): bool {
        if ($id <= 0 || !orange_table_exists($pdo, $table)) {
            return false;
        }
        $st = $pdo->prepare("SELECT requires_registered_account FROM {$table} WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return (int) ($st->fetchColumn() ?: 0) === 1;
    };
    $comboRequiresReg = $comboId !== null ? $requiresReg($pdo, 'cart_combo_promotions', (int) $comboId) : false;
    $promoRequiresReg = $promo !== null ? $requiresReg($pdo, 'cart_promotions', (int) $promo['id']) : false;
    if ($giftPayload !== null && $giftRule !== null) {
        $giftPayload['requires_registered'] = (int) ($giftRule['requires_registered_account'] ?? 0) === 1;
    }
    if ($bogoPayload !== null && $bogoRule !== null) {
        $bogoPayload['requires_registered'] = (int) ($bogoRule['requires_registered_account'] ?? 0) === 1;
    }

    json_response([
        'success' => true,
        'country_id' => $countryId,
        // هل العميل مسجّل بالموقع فعلاً (حساب متجر مُفعَّل بالبريد) — يُعرض للمحاسب.
        'customer_registered_on_site' => $registeredOnSite,
        'subtotal' => $subtotal,
        'combo_promotion_id' => $comboId,
        'combo_discount' => $comboDiscount,
        'combo_requires_registered' => $comboRequiresReg,
        'promotion_id' => $promo !== null ? (int) $promo['id'] : null,
        'promotion_discount' => $promoDiscount,
        'promotion_requires_registered' => $promoRequiresReg,
        'product_offer_discount' => $productOfferDiscount,
        'gift_promotion' => $giftPayload,
        'bogo_promotion' => $bogoPayload,
        'loyalty' => $loyalty,
        'payable_after_item_discounts' => $payableAfterDiscounts,
    ]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر كشف عروض الفاتورة');
}
