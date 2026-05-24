<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/cart_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';
require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_bogo_promotions.php';
require_once __DIR__ . '/storefront_checkout_promo_lines.php';
require_once __DIR__ . '/order_intake_queue.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/order_fulfillment.php';

/**
 * @return list<string>
 */
function orange_invoice_edit_allowed_statuses(): array
{
    return ['approved', 'on_the_way'];
}

/**
 * @return list<string>
 */
function orange_invoice_edit_promo_kinds(): array
{
    return ['combo', 'cart_promotion', 'gift', 'bogo'];
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function orange_invoice_edit_decode_admin_restores($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $k) {
        $k = trim((string) $k);
        if (in_array($k, orange_invoice_edit_promo_kinds(), true)) {
            $out[] = $k;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $kinds
 */
function orange_invoice_edit_encode_admin_restores(array $kinds): ?string
{
    $kinds = orange_invoice_edit_decode_admin_restores($kinds);
    if ($kinds === []) {
        return null;
    }
    $json = json_encode($kinds, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : null;
}

/**
 * @param array<string, mixed> $order
 * @return list<string>
 */
function orange_invoice_edit_read_stored_restores(array $order): array
{
    $raw = trim((string) ($order['promo_admin_override'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return orange_invoice_edit_decode_admin_restores(is_array($decoded) ? $decoded : []);
}

function orange_invoice_edit_is_gift_line(PDO $pdo, array $item, array $order): bool
{
    $price = (float) ($item['price'] ?? 0);
    if ($price <= 0.00001) {
        return true;
    }
    $vid = (int) ($item['variant_id'] ?? 0);
    if ($vid <= 0) {
        return false;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id')) {
        if ($vid === (int) ($order['cart_gift_variant_id'] ?? 0)) {
            return true;
        }
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id')) {
        if ($vid === (int) ($order['cart_bogo_gift_variant_id'] ?? 0)) {
            return true;
        }
    }

    return false;
}

function orange_invoice_edit_is_bogo_gift_line(PDO $pdo, array $item, array $order): bool
{
    $vid = (int) ($item['variant_id'] ?? 0);
    if ($vid <= 0) {
        return false;
    }

    return $vid === (int) ($order['cart_bogo_gift_variant_id'] ?? 0);
}

/**
 * تخطيط بنود مدفوعة: إطار كومbo (§13.11.9.7.6-أ) + بنود مستقلة.
 *
 * @param list<array<string, mixed>> $paidItems
 *
 * @return array{
 *   groups: list<array{
 *     group_id:int,promo_id:int,title:string,combo_price:float,bundle_qty:int,
 *     total_price:float,item_ids:list<int>,items:list<array<string,mixed>>,condition_text:string
 *   }>,
 *   standalone: list<array<string, mixed>>
 * }
 */
function orange_invoice_edit_combo_layout(PDO $pdo, array $order, array $paidItems): array
{
    $empty = ['groups' => [], 'standalone' => $paidItems];
    $comboId = (int) ($order['cart_combo_promotion_id'] ?? 0);
    if ($comboId <= 0 || $paidItems === [] || ! orange_table_exists($pdo, 'cart_combo_promotions')) {
        return $empty;
    }

    $st = $pdo->prepare(
        'SELECT id, title_ar, combo_price, components_json FROM cart_combo_promotions WHERE id = ? LIMIT 1'
    );
    $st->execute([$comboId]);
    $promo = $st->fetch(PDO::FETCH_ASSOC);
    if (! $promo) {
        return $empty;
    }

    $comps = orange_cart_combo_parse_components_json($promo['components_json'] ?? null);
    if (count($comps) < 2) {
        return $empty;
    }

    /** @var array<int, int> $needByVid qty per bundle */
    $needByVid = [];
    foreach ($comps as $c) {
        $needByVid[(int) $c['variant_id']] = (int) $c['qty'];
    }

    /** @var array<int, list<array<string, mixed>>> $byVid */
    $byVid = [];
    foreach ($paidItems as $row) {
        $vid = (int) ($row['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $byVid[$vid][] = $row;
    }

    foreach ($needByVid as $vid => $need) {
        if ($need <= 0 || ! isset($byVid[$vid])) {
            return $empty;
        }
    }

    $bundles = PHP_INT_MAX;
    foreach ($needByVid as $vid => $need) {
        $totalQty = 0;
        foreach ($byVid[$vid] as $row) {
            $totalQty += (int) ($row['qty'] ?? 0);
        }
        if ($totalQty < $need) {
            return $empty;
        }
        $bundles = min($bundles, intdiv($totalQty, $need));
    }
    if ($bundles < 1 || $bundles === PHP_INT_MAX) {
        return $empty;
    }

    /** @var array<int, true> $comboItemIds */
    $comboItemIds = [];
    foreach ($needByVid as $vid => $needPerBundle) {
        $remaining = $needPerBundle * $bundles;
        foreach ($byVid[$vid] as $row) {
            if ($remaining <= 0) {
                break;
            }
            $iid = (int) ($row['id'] ?? 0);
            if ($iid > 0) {
                $comboItemIds[$iid] = true;
            }
            $remaining -= (int) ($row['qty'] ?? 0);
        }
    }

    if ($comboItemIds === []) {
        return $empty;
    }

    $comboRows = [];
    $standalone = [];
    foreach ($paidItems as $row) {
        $iid = (int) ($row['id'] ?? 0);
        if ($iid > 0 && isset($comboItemIds[$iid])) {
            $comboRows[] = $row;
        } else {
            $standalone[] = $row;
        }
    }

    $title = trim((string) ($promo['title_ar'] ?? ''));
    if ($title === '') {
        $title = 'حزمة كومbo';
    }
    $comboPrice = (float) ($promo['combo_price'] ?? 0);

    return [
        'groups' => [[
            'group_id' => 1,
            'promo_id' => $comboId,
            'title' => $title,
            'combo_price' => $comboPrice,
            'bundle_qty' => $bundles,
            'total_price' => round($comboPrice * $bundles, 4),
            'item_ids' => array_map('intval', array_keys($comboItemIds)),
            'items' => $comboRows,
            'condition_text' => 'إطار كومbo — الكل أو لا شيء',
        ]],
        'standalone' => $standalone,
    ];
}

/**
 * @param array<int, array<string, mixed>> $byId
 * @param list<array{item_id:int, qty:int}> $changes
 */
function orange_invoice_edit_validate_combo_all_or_nothing(
    PDO $pdo,
    array $order,
    array $byId,
    array $changes
): void {
    $paid = [];
    foreach ($byId as $row) {
        if (! orange_invoice_edit_is_gift_line($pdo, $row, $order)) {
            $paid[] = $row;
        }
    }
    $layout = orange_invoice_edit_combo_layout($pdo, $order, $paid);
    if ($layout['groups'] === []) {
        return;
    }

    foreach ($layout['groups'] as $group) {
        $itemIds = $group['item_ids'];
        /** @var array<int, int> $newQtys */
        $newQtys = [];
        foreach ($itemIds as $iid) {
            $newQtys[$iid] = (int) ($byId[$iid]['qty'] ?? 0);
        }
        foreach ($changes as $chg) {
            $iid = (int) ($chg['item_id'] ?? 0);
            if (in_array($iid, $itemIds, true)) {
                $newQtys[$iid] = (int) ($chg['qty'] ?? 0);
            }
        }

        $allZero = true;
        $anyChanged = false;
        foreach ($itemIds as $iid) {
            $old = (int) ($byId[$iid]['qty'] ?? 0);
            $nw = $newQtys[$iid] ?? 0;
            if ($nw > 0) {
                $allZero = false;
            }
            if ($nw !== $old) {
                $anyChanged = true;
            }
        }

        if (! $anyChanged) {
            continue;
        }
        if (! $allZero) {
            throw new RuntimeException(
                'كومbo: الكل أو لا شيء — لا يمكن تعديل مكوّن واحد. استخدم «إرجاع الحزمة كاملة» أو عدّل البنود خارج الإطار.'
            );
        }
    }
}

/**
 * @param list<int> $comboItemIds
 */
function orange_invoice_edit_stamp_combo_group(PDO $pdo, int $orderId, array $comboItemIds, int $groupId = 1): void
{
    if (! orange_table_has_column($pdo, 'order_items', 'combo_group_id')) {
        return;
    }
    $pdo->prepare('UPDATE order_items SET combo_group_id = NULL WHERE order_id = ?')
        ->execute([$orderId]);
    if ($comboItemIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($comboItemIds), '?'));
    $pdo->prepare(
        "UPDATE order_items SET combo_group_id = ? WHERE order_id = ? AND id IN ($ph)"
    )->execute(array_merge([$groupId, $orderId], $comboItemIds));
}

/**
 * @param list<array<string, mixed>> $paidItems
 * @return list<array<string, mixed>>
 */
function orange_invoice_edit_paid_lines_to_validated(PDO $pdo, array $items, array $order): array
{
    $out = [];
    foreach ($items as $item) {
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            continue;
        }
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        if (!$variant) {
            continue;
        }
        $pid = (int) ($item['product_id'] ?? $variant['product_id'] ?? 0);
        $prodSt = $pdo->prepare('SELECT id, name FROM products WHERE id = ? LIMIT 1');
        $prodSt->execute([$pid]);
        $prod = $prodSt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            continue;
        }
        $out[] = [
            'product' => ['id' => (int) $prod['id'], 'name' => (string) ($prod['name'] ?? $item['product_name'] ?? '')],
            'qty' => (int) ($item['qty'] ?? 0),
            'color' => (string) ($item['color'] ?? ''),
            'size' => (string) ($item['size'] ?? ''),
            'variant_id' => (int) $variant['id'],
            'price' => (float) ($item['price'] ?? 0),
            'cost' => (float) ($item['cost'] ?? 0),
        ];
    }

    return $out;
}

function orange_invoice_edit_return_stock_for_qty(
    PDO $pdo,
    array $order,
    array $item,
    int $qtyReturn
): void {
    if ($qtyReturn <= 0) {
        return;
    }
    $variant = orange_order_resolve_variant_from_item($pdo, $item);
    if (!$variant) {
        return;
    }
    $stockCtx = orange_warehouse_context_for_order($pdo, $order);
    $ref = orange_order_stock_reference((string) ($order['order_number'] ?? ''));
    $stockChange = orange_warehouse_apply_variant_delta(
        $pdo,
        $stockCtx['warehouse_id'],
        (int) $variant['id'],
        $qtyReturn,
        0
    );
    orange_stock_movement_insert($pdo, [
        'product_id' => (int) ($item['product_id'] ?? 0),
        'variant_id' => (int) $variant['id'],
        'type' => 'order_amend_release',
        'qty' => $qtyReturn,
        'old_stock' => $stockChange['old'],
        'new_stock' => $stockChange['new'],
        'reason' => 'Invoice edit — partial return',
        'reference' => $ref,
        'country_id' => $stockCtx['country_id'],
        'warehouse_id' => $stockCtx['warehouse_id'],
    ]);
}

/**
 * @param array<int, array<string, mixed>> $byId
 * @param array<int, array{item_id:int, qty:int}> $changes
 * @return array<int, array<string, mixed>>
 */
function orange_invoice_edit_simulate_item_qty(array $byId, array $changes): array
{
    $sim = $byId;
    foreach ($changes as $chg) {
        $itemId = (int) ($chg['item_id'] ?? 0);
        $newQty = (int) ($chg['qty'] ?? 0);
        if ($itemId <= 0 || !isset($sim[$itemId])) {
            continue;
        }
        if ($newQty < 0) {
            $newQty = 0;
        }
        if ($newQty === 0) {
            unset($sim[$itemId]);
        } else {
            $sim[$itemId]['qty'] = $newQty;
        }
    }

    return $sim;
}

function orange_invoice_edit_gift_condition_text(PDO $pdo, int $promoId): string
{
    if ($promoId <= 0 || !orange_table_exists($pdo, 'cart_gift_promotions')) {
        return '';
    }
    $st = $pdo->prepare('SELECT min_subtotal FROM cart_gift_promotions WHERE id = ? LIMIT 1');
    $st->execute([$promoId]);
    $min = (float) ($st->fetchColumn() ?: 0);
    if ($min <= 0.00001) {
        return 'بدون حد أدنى للمجموع';
    }

    return 'مجموع البنود ≥ ' . number_format($min, 3);
}

function orange_invoice_edit_bogo_condition_text(PDO $pdo, int $promoId): string
{
    if ($promoId <= 0 || !orange_table_exists($pdo, 'cart_bogo_promotions')) {
        return '';
    }
    $st = $pdo->prepare('SELECT bogo_kind, min_buy_qty FROM cart_bogo_promotions WHERE id = ? LIMIT 1');
    $st->execute([$promoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }
    $kind = strtolower(trim((string) ($row['bogo_kind'] ?? 'same_variant')));
    $n = (int) ($row['min_buy_qty'] ?? 2);
    if ($kind === 'same_category') {
        return '≥ ' . $n . ' منتجات من الفئة';
    }
    if ($kind === 'buy_bundle') {
        return 'حزمة شراء: ≥ ' . $n . ' (كل أو لا شيء)';
    }

    return '≥ ' . $n . ' من نفس المتغير';
}

/**
 * @param array<int, array<string, mixed>> $allItems
 * @return array{combo:?array, cart_promotion:?array, gift:?array, bogo:?array}
 */
function orange_invoice_edit_prior_snapshot(PDO $pdo, array $order, array $allItems): array
{
    $snap = ['combo' => null, 'cart_promotion' => null, 'gift' => null, 'bogo' => null];

    $comboId = (int) ($order['cart_combo_promotion_id'] ?? 0);
    $comboDisc = (float) ($order['cart_combo_discount'] ?? 0);
    if ($comboId > 0 && $comboDisc > 0.00001) {
        $paidForLayout = [];
        foreach ($allItems as $item) {
            if (! orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
                $paidForLayout[] = $item;
            }
        }
        $layout = orange_invoice_edit_combo_layout($pdo, $order, $paidForLayout);
        $comboLabel = 'خصم كومبو: −' . number_format($comboDisc, 3);
        if ($layout['groups'] !== []) {
            $g0 = $layout['groups'][0];
            $comboLabel = (string) ($g0['title'] ?? 'كومبو')
                . ' — ' . number_format((float) ($g0['total_price'] ?? 0), 3)
                . ' (×' . (int) ($g0['bundle_qty'] ?? 1) . ' حزمة)';
        }
        $snap['combo'] = [
            'kind' => 'combo',
            'id' => $comboId,
            'discount' => $comboDisc,
            'label' => $comboLabel,
            'condition_text' => 'إطار كومبو — الكل أو لا شيء',
        ];
    }

    $promoId = (int) ($order['cart_promotion_id'] ?? 0);
    $promoDisc = (float) ($order['cart_promotion_discount'] ?? 0);
    if ($promoId > 0 && $promoDisc > 0.00001) {
        $st = $pdo->prepare('SELECT min_subtotal FROM cart_promotions WHERE id = ? LIMIT 1');
        $st->execute([$promoId]);
        $min = (float) ($st->fetchColumn() ?: 0);
        $snap['cart_promotion'] = [
            'kind' => 'cart_promotion',
            'id' => $promoId,
            'discount' => $promoDisc,
            'label' => 'خصم السلة: −' . number_format($promoDisc, 3),
            'condition_text' => $min > 0.00001 ? 'مجموع ≥ ' . number_format($min, 3) : '',
        ];
    }

    $giftPromoId = (int) ($order['cart_gift_promotion_id'] ?? 0);
    $giftVid = (int) ($order['cart_gift_variant_id'] ?? 0);
    if ($giftPromoId > 0 && $giftVid > 0) {
        $label = 'هدية';
        foreach ($allItems as $item) {
            if (!orange_invoice_edit_is_bogo_gift_line($pdo, $item, $order)
                && (int) ($item['variant_id'] ?? 0) === $giftVid) {
                $label = 'هدية: ' . (string) ($item['product_name'] ?? '');
                break;
            }
        }
        $snap['gift'] = [
            'kind' => 'gift',
            'id' => $giftPromoId,
            'variant_id' => $giftVid,
            'label' => $label,
            'condition_text' => orange_invoice_edit_gift_condition_text($pdo, $giftPromoId),
        ];
    }

    $bogoPromoId = (int) ($order['cart_bogo_promotion_id'] ?? 0);
    $bogoVid = (int) ($order['cart_bogo_gift_variant_id'] ?? 0);
    if ($bogoPromoId > 0 && $bogoVid > 0) {
        $label = 'هدية BOGO';
        foreach ($allItems as $item) {
            if (orange_invoice_edit_is_bogo_gift_line($pdo, $item, $order)) {
                $label = 'BOGO: ' . (string) ($item['product_name'] ?? '');
                break;
            }
        }
        $snap['bogo'] = [
            'kind' => 'bogo',
            'id' => $bogoPromoId,
            'variant_id' => $bogoVid,
            'label' => $label,
            'condition_text' => orange_invoice_edit_bogo_condition_text($pdo, $bogoPromoId),
        ];
    }

    return $snap;
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $paidValidated
 * @param array{combo:?array, cart_promotion:?array, gift:?array, bogo:?array} $priorSnap
 * @param list<string> $adminRestores
 * @return array<string, mixed>
 */
function orange_invoice_edit_compute_promos(
    PDO $pdo,
    array $order,
    array $paidValidated,
    float $subtotal,
    array $priorSnap,
    array $adminRestores
): array {
    $buyerRegistered = false;
    if (orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        $buyerRegistered = (int) ($order['storefront_account_id'] ?? 0) > 0;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    $cid = $countryId > 0 ? $countryId : null;

    $comboPick = orange_cart_combo_best_match($pdo, $paidValidated, $buyerRegistered, $cid);
    $naturalComboDisc = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $naturalComboId = $comboPick !== null ? (int) $comboPick['id'] : null;

    $netAfterCombo = max(0.0, round($subtotal - $naturalComboDisc, 4));
    $promoPick = orange_cart_promotion_resolve($pdo, $netAfterCombo, $buyerRegistered, $cid);
    $naturalPromoDisc = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
    $naturalPromoId = $promoPick !== null ? (int) $promoPick['id'] : null;

    $payload = [
        'gift_variant_id' => (int) ($order['cart_gift_variant_id'] ?? 0),
        'bogo_gift_variant_id' => (int) ($order['cart_bogo_gift_variant_id'] ?? 0),
    ];
    $giftLine = null;
    $giftPromoId = null;
    $giftVariantId = null;
    $bogoLine = null;
    $bogoPromoId = null;
    $bogoGiftVariantId = null;
    try {
        $promoBundle = orange_storefront_build_promotional_gift_lines(
            $pdo,
            $payload,
            $paidValidated,
            $subtotal,
            $buyerRegistered,
            $cid
        );
        $giftLine = $promoBundle['giftLine'];
        $giftPromoId = $promoBundle['giftPromoId'];
        $giftVariantId = $promoBundle['giftVariantId'];
        $bogoLine = $promoBundle['bogoLine'];
        $bogoPromoId = $promoBundle['bogoPromoId'];
        $bogoGiftVariantId = $promoBundle['bogoGiftVariantId'];
    } catch (Throwable $e) {
        // معاينة — لا نوقف الشاشة
    }

    $finalComboId = $naturalComboId;
    $finalComboDisc = $naturalComboDisc;
    $finalPromoId = $naturalPromoId;
    $finalPromoDisc = $naturalPromoDisc;
    $finalGiftLine = $giftLine;
    $finalGiftPromoId = $giftPromoId;
    $finalGiftVariantId = $giftVariantId;
    $finalBogoLine = $bogoLine;
    $finalBogoPromoId = $bogoPromoId;
    $finalBogoGiftVariantId = $bogoGiftVariantId;

    if ($naturalComboId === null && $priorSnap['combo'] !== null && in_array('combo', $adminRestores, true)) {
        $finalComboId = (int) $priorSnap['combo']['id'];
        $finalComboDisc = (float) $priorSnap['combo']['discount'];
    }
    if ($naturalPromoId === null && $priorSnap['cart_promotion'] !== null && in_array('cart_promotion', $adminRestores, true)) {
        $finalPromoId = (int) $priorSnap['cart_promotion']['id'];
        $finalPromoDisc = (float) $priorSnap['cart_promotion']['discount'];
    }
    if ($finalGiftLine === null && $priorSnap['gift'] !== null && in_array('gift', $adminRestores, true)) {
        $pv = (int) ($priorSnap['gift']['variant_id'] ?? 0);
        if ($pv > 0) {
            try {
                $finalGiftLine = orange_storefront_build_gift_order_line($pdo, $pv, $paidValidated, true, 0.0);
                $finalGiftPromoId = (int) $priorSnap['gift']['id'];
                $finalGiftVariantId = $pv;
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    if ($finalBogoLine === null && $priorSnap['bogo'] !== null && in_array('bogo', $adminRestores, true)) {
        $pv = (int) ($priorSnap['bogo']['variant_id'] ?? 0);
        $ruleId = (int) ($priorSnap['bogo']['id'] ?? 0);
        if ($pv > 0 && $ruleId > 0 && orange_table_exists($pdo, 'cart_bogo_promotions')) {
            $st = $pdo->prepare(
                'SELECT gift_unit_charge_kind, gift_unit_charge_value FROM cart_bogo_promotions WHERE id = ? LIMIT 1'
            );
            $st->execute([$ruleId]);
            $ruleRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($ruleRow) {
                try {
                    $bogoUnit = orange_cart_bogo_resolve_gift_unit_price($pdo, $ruleRow, $pv);
                    $finalBogoLine = orange_storefront_build_gift_order_line($pdo, $pv, $paidValidated, true, $bogoUnit);
                    if (is_array($finalBogoLine)) {
                        $finalBogoLine['is_bogo_gift'] = true;
                    }
                    $finalBogoPromoId = $ruleId;
                    $finalBogoGiftVariantId = $pv;
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    }

    $netAfterComboFinal = max(0.0, round($subtotal - $finalComboDisc, 4));
    $orderTotal = max(0.0, round($netAfterComboFinal - $finalPromoDisc, 4));

    $active = [];
    $dropped = [];

    foreach (orange_invoice_edit_promo_kinds() as $kind) {
        $prior = $priorSnap[$kind] ?? null;
        $hasNatural = match ($kind) {
            'combo' => $naturalComboId !== null && $naturalComboDisc > 0.00001,
            'cart_promotion' => $naturalPromoId !== null && $naturalPromoDisc > 0.00001,
            'gift' => $giftLine !== null,
            'bogo' => $bogoLine !== null,
            default => false,
        };
        $hasFinal = match ($kind) {
            'combo' => $finalComboId !== null && $finalComboDisc > 0.00001,
            'cart_promotion' => $finalPromoId !== null && $finalPromoDisc > 0.00001,
            'gift' => $finalGiftLine !== null,
            'bogo' => $finalBogoLine !== null,
            default => false,
        };
        $restored = in_array($kind, $adminRestores, true);

        if ($hasFinal) {
            $row = $prior ?? [
                'kind' => $kind,
                'label' => $kind,
                'condition_text' => '',
            ];
            if ($kind === 'combo') {
                $row['label'] = 'خصم كومبو: −' . number_format($finalComboDisc, 3);
                $row['discount'] = $finalComboDisc;
            } elseif ($kind === 'cart_promotion') {
                $row['label'] = 'خصم السلة: −' . number_format($finalPromoDisc, 3);
                $row['discount'] = $finalPromoDisc;
            } elseif ($kind === 'gift' && is_array($finalGiftLine)) {
                $row['label'] = 'هدية: ' . (string) ($finalGiftLine['product']['name'] ?? '');
            } elseif ($kind === 'bogo' && is_array($finalBogoLine)) {
                $row['label'] = 'BOGO: ' . (string) ($finalBogoLine['product']['name'] ?? '');
            }
            $row['kind'] = $kind;
            $row['deliver'] = true;
            $row['admin_override'] = $restored && !$hasNatural;
            $active[] = $row;
        } elseif ($prior !== null && !$restored) {
            $prior['deliver'] = false;
            $prior['admin_override'] = false;
            $dropped[] = $prior;
        }
    }

    return [
        'subtotal' => $subtotal,
        'total' => $orderTotal,
        'combo_id' => $finalComboId,
        'combo_discount' => $finalComboDisc,
        'promo_id' => $finalPromoId,
        'promo_discount' => $finalPromoDisc,
        'giftLine' => $finalGiftLine,
        'giftPromoId' => $finalGiftPromoId,
        'giftVariantId' => $finalGiftVariantId,
        'bogoLine' => $finalBogoLine,
        'bogoPromoId' => $finalBogoPromoId,
        'bogoGiftVariantId' => $finalBogoGiftVariantId,
        'active' => $active,
        'dropped' => $dropped,
    ];
}

/**
 * @param array<int, array{item_id:int, qty:int}> $changes
 * @param list<string> $adminRestores
 * @return array<string, mixed>
 */
function orange_invoice_edit_preview(PDO $pdo, int $orderId, array $changes, array $adminRestores): array
{
    orange_catalog_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود');
    }
    $status = (string) ($order['status'] ?? '');
    if (!in_array($status, orange_invoice_edit_allowed_statuses(), true)) {
        throw new RuntimeException('الطلب غير مؤهل للمعاينة');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($allItems as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    orange_invoice_edit_validate_combo_all_or_nothing($pdo, $order, $byId, $changes);

    $priorSnap = orange_invoice_edit_prior_snapshot($pdo, $order, $allItems);
    $sim = orange_invoice_edit_simulate_item_qty($byId, $changes);
    foreach ($sim as $id => $item) {
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            unset($sim[$id]);
        }
    }

    $paidValidated = orange_invoice_edit_paid_lines_to_validated($pdo, array_values($sim), $order);
    if ($paidValidated === []) {
        throw new RuntimeException('يجب أن يبقى بند مدفوع واحد على الأقل');
    }

    $subtotal = 0.0;
    foreach ($paidValidated as $row) {
        $subtotal = round($subtotal + (float) $row['price'] * (int) $row['qty'], 4);
    }

    $adminRestores = orange_invoice_edit_decode_admin_restores($adminRestores);
    $computed = orange_invoice_edit_compute_promos($pdo, $order, $paidValidated, $subtotal, $priorSnap, $adminRestores);

    return [
        'subtotal' => $computed['subtotal'],
        'total' => $computed['total'],
        'active' => $computed['active'],
        'dropped' => $computed['dropped'],
        'promo_summary' => [
            'subtotal' => $computed['subtotal'],
            'combo_discount' => $computed['combo_discount'],
            'cart_promotion_discount' => $computed['promo_discount'],
            'has_gift' => $computed['giftLine'] !== null,
            'has_bogo' => $computed['bogoLine'] !== null,
        ],
    ];
}

/**
 * @param array<int, array{item_id:int, qty:int}> $changes
 * @param list<string> $adminRestores
 * @return array{total:float, promo_summary:array<string,mixed>}
 */
function orange_invoice_edit_apply(PDO $pdo, int $orderId, array $changes, bool $markCompleted, array $adminRestores = []): array
{
    orange_catalog_ensure_schema($pdo);

    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('الطلب غير موجود');
    }
    $status = (string) ($order['status'] ?? '');
    if (!in_array($status, orange_invoice_edit_allowed_statuses(), true)) {
        throw new RuntimeException('الطلب غير مؤهل للتعديل — الحالة: ' . $status);
    }
    $src = trim((string) ($order['order_source'] ?? 'website'));
    if ($src === 'company') {
        throw new RuntimeException('تعديل الفاتورة للطلبات الأونلاين فقط');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$orderId]);
    $allItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($allItems as $row) {
        $byId[(int) ($row['id'] ?? 0)] = $row;
    }

    $priorSnap = orange_invoice_edit_prior_snapshot($pdo, $order, $allItems);
    $adminRestores = orange_invoice_edit_decode_admin_restores($adminRestores);

    orange_invoice_edit_validate_combo_all_or_nothing($pdo, $order, $byId, $changes);

    foreach ($changes as $chg) {
        $itemId = (int) ($chg['item_id'] ?? 0);
        $newQty = (int) ($chg['qty'] ?? 0);
        if ($itemId <= 0 || !isset($byId[$itemId])) {
            continue;
        }
        $item = $byId[$itemId];
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            throw new RuntimeException('لا يمكن تعديل سطر الهدية مباشرة — عدّل البنود المدفوعة');
        }
        $oldQty = (int) ($item['qty'] ?? 0);
        if ($newQty < 0) {
            $newQty = 0;
        }
        if ($newQty < $oldQty) {
            orange_invoice_edit_return_stock_for_qty($pdo, $order, $item, $oldQty - $newQty);
        }
        if ($newQty === 0) {
            $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?')->execute([$itemId, $orderId]);
            unset($byId[$itemId]);
        } elseif ($newQty !== $oldQty) {
            $pdo->prepare('UPDATE order_items SET qty = ? WHERE id = ? AND order_id = ?')
                ->execute([$newQty, $itemId, $orderId]);
            $byId[$itemId]['qty'] = $newQty;
        }
    }

    foreach ($byId as $id => $item) {
        if (orange_invoice_edit_is_gift_line($pdo, $item, $order)) {
            orange_invoice_edit_return_stock_for_qty($pdo, $order, $item, (int) ($item['qty'] ?? 0));
            $pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([$id]);
            unset($byId[$id]);
        }
    }

    $paidValidated = orange_invoice_edit_paid_lines_to_validated($pdo, array_values($byId), $order);
    if ($paidValidated === []) {
        throw new RuntimeException('يجب أن يبقى بند مدفوع واحد على الأقل');
    }

    $subtotal = 0.0;
    foreach ($paidValidated as $row) {
        $subtotal = round($subtotal + (float) $row['price'] * (int) $row['qty'], 4);
    }

    $computed = orange_invoice_edit_compute_promos($pdo, $order, $paidValidated, $subtotal, $priorSnap, $adminRestores);

    if ($computed['giftLine'] !== null) {
        orange_storefront_insert_order_items_for_order($pdo, $orderId, [$computed['giftLine']]);
    }
    if ($computed['bogoLine'] !== null) {
        orange_storefront_insert_order_items_for_order($pdo, $orderId, [$computed['bogoLine']]);
    }

    $orderTotal = (float) $computed['total'];
    $comboId = $computed['combo_id'];
    $comboDiscount = (float) $computed['combo_discount'];
    $promoId = $computed['promo_id'];
    $promoDiscount = (float) $computed['promo_discount'];
    $giftPromoId = $computed['giftPromoId'];
    $giftVariantId = $computed['giftVariantId'];
    $bogoPromoId = $computed['bogoPromoId'];
    $bogoGiftVariantId = $computed['bogoGiftVariantId'];

    $setParts = ['total = ?', 'updated_at = NOW()'];
    $updParams = [$orderTotal];
    if (orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')) {
        $setParts[] = 'cart_combo_promotion_id = ?';
        $setParts[] = 'cart_combo_discount = ?';
        $updParams[] = $comboId !== null && $comboId > 0 ? $comboId : null;
        $updParams[] = $comboDiscount;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_promotion_id')) {
        $setParts[] = 'cart_promotion_id = ?';
        $setParts[] = 'cart_promotion_discount = ?';
        $updParams[] = $promoId !== null && $promoId > 0 ? $promoId : null;
        $updParams[] = $promoDiscount;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')) {
        $setParts[] = 'cart_gift_promotion_id = ?';
        $setParts[] = 'cart_gift_variant_id = ?';
        $updParams[] = $giftPromoId !== null && $giftPromoId > 0 ? $giftPromoId : null;
        $updParams[] = $giftVariantId !== null && $giftVariantId > 0 ? $giftVariantId : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')) {
        $setParts[] = 'cart_bogo_promotion_id = ?';
        $setParts[] = 'cart_bogo_gift_variant_id = ?';
        $updParams[] = $bogoPromoId !== null && $bogoPromoId > 0 ? $bogoPromoId : null;
        $updParams[] = $bogoGiftVariantId !== null && $bogoGiftVariantId > 0 ? $bogoGiftVariantId : null;
    }
    if (orange_table_has_column($pdo, 'orders', 'promo_admin_override')) {
        $setParts[] = 'promo_admin_override = ?';
        $updParams[] = orange_invoice_edit_encode_admin_restores($adminRestores);
    }
    $updParams[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($updParams);

    $remainingPaid = [];
    foreach ($byId as $row) {
        if (! orange_invoice_edit_is_gift_line($pdo, $row, $order)) {
            $remainingPaid[] = $row;
        }
    }
    $postLayout = orange_invoice_edit_combo_layout($pdo, $order, $remainingPaid);
    $comboStampIds = $postLayout['groups'][0]['item_ids'] ?? [];
    orange_invoice_edit_stamp_combo_group($pdo, $orderId, $comboStampIds);

    if ($markCompleted) {
        $pdo->prepare('UPDATE orders SET status = ?, completed_at = NOW() WHERE id = ?')
            ->execute(['completed', $orderId]);
        orange_complete_order_fulfillment($pdo, $orderId);
    }

    return [
        'total' => $orderTotal,
        'promo_summary' => [
            'subtotal' => $subtotal,
            'combo_discount' => $comboDiscount,
            'cart_promotion_discount' => $promoDiscount,
            'has_gift' => $computed['giftLine'] !== null,
            'has_bogo' => $computed['bogoLine'] !== null,
        ],
        'active' => $computed['active'],
        'dropped' => $computed['dropped'],
    ];
}
