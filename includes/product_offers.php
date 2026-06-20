<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_promo_schedule.php';
require_once __DIR__ . '/cart_promo_products.php';

/**
 * عروض منتج واحد (جدول offers) — العرض الخامس للمستهلك (س4).
 */
function orange_product_offer_table(): string
{
    return 'offers';
}

/**
 * شريط SQL للمتجر: نشط + ضمن المدة + غير موقوف.
 */
function orange_product_offer_storefront_sql(string $alias = 'o'): string
{
    return orange_cart_promo_schedule_sql($alias);
}

/**
 * @param array<string,mixed> $row صف offers مع valid_from / valid_to / auto_paused_*
 */
function orange_product_offer_row_is_customer_effective(array $row): bool
{
    return orange_cart_promo_row_is_customer_effective($row);
}

/**
 * إيقاف تلقائي عند نفاد مخزون المنتج المعروض.
 */
function orange_product_offer_sync_stock_pause(PDO $pdo, int $offerId, int $productId, ?int $countryId = null): void
{
    if ($offerId <= 0 || $productId <= 0 || !orange_table_exists($pdo, orange_product_offer_table())) {
        return;
    }
    if (!orange_table_has_column($pdo, orange_product_offer_table(), 'auto_paused_at')) {
        return;
    }
    if (orange_cart_promo_product_has_visitor_stock($pdo, $productId, [], $countryId)) {
        return;
    }
    orange_cart_promo_auto_pause_with_reason($pdo, orange_product_offer_table(), $offerId, 'promo_stock');
}

/**
 * يمرّ على عروض المنتجات النشطة — يفوّض لمحرك فحص المخزون (مرحلة 8).
 *
 * @return array<string,mixed>
 */
function orange_product_offer_sync_all_stock_pauses(PDO $pdo, ?int $countryId = null): array
{
    require_once __DIR__ . '/cart_promo_stock_health.php';

    return orange_cart_promo_run_stock_health($pdo, $countryId, ['offers']);
}

/**
 * خصم الوحدة الفعّال لعرض منتج نشط (مبلغ ثابت يُخصم من سعر الوحدة) — 0 إن لا عرض ساري.
 * نفس شرط المتجر: نشط + ضمن المدة + غير موقوف + ضمن الدولة.
 */
function orange_product_offer_active_unit_discount(PDO $pdo, int $productId, ?int $countryId = null): float
{
    $table = orange_product_offer_table();
    if ($productId <= 0 || !orange_table_exists($pdo, $table)) {
        return 0.0;
    }
    $scheduleSql = orange_table_has_column($pdo, $table, 'valid_from')
        ? orange_product_offer_storefront_sql('o')
        : '';
    $sql = 'SELECT o.discount
            FROM ' . $table . ' o
            INNER JOIN products p ON p.id = o.product_id AND p.is_active = 1
            WHERE o.is_active = 1 AND o.product_id = ?' . $scheduleSql;
    $params = [$productId];
    if (orange_table_has_column($pdo, $table, 'country_id') && $countryId !== null && $countryId > 0) {
        $sql .= ' AND (o.country_id = ? OR o.country_id IS NULL)';
        $params[] = $countryId;
    } elseif (orange_table_has_column($pdo, 'products', 'country_id') && $countryId !== null && $countryId > 0) {
        $sql .= ' AND p.country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY '
        . (orange_table_has_column($pdo, $table, 'sort_order') ? 'o.sort_order ASC, ' : '')
        . 'o.id ASC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $disc = $st->fetchColumn();
    if ($disc === false) {
        return 0.0;
    }

    return max(0.0, round((float) $disc, 4));
}

/**
 * إجمالي خصم عروض المنتج لبنود السلة (لا يشمل بنود الهدية/BOGO؛ مقيّد بسعر البند).
 *
 * @param list<array<string,mixed>> $validatedItems
 */
function orange_product_offer_total_discount_for_items(PDO $pdo, array $validatedItems, ?int $countryId = null): float
{
    $total = 0.0;
    $cache = [];
    foreach ($validatedItems as $line) {
        if (!is_array($line) || !empty($line['is_gift']) || !empty($line['is_bogo_gift'])) {
            continue;
        }
        $pid = (int) (($line['product']['id'] ?? 0));
        $qty = (int) ($line['qty'] ?? 0);
        $price = (float) ($line['price'] ?? 0);
        if ($pid <= 0 || $qty <= 0 || $price <= 0.0001) {
            continue;
        }
        if (!array_key_exists($pid, $cache)) {
            $cache[$pid] = orange_product_offer_active_unit_discount($pdo, $pid, $countryId);
        }
        $unitDisc = min($cache[$pid], $price);
        if ($unitDisc > 0.0001) {
            $total += round($unitDisc * $qty, 4);
        }
    }

    return round($total, 4);
}

/**
 * يقسّم بنود السلة إلى بنود عليها عرض منتج نشط وبقية البنود — لسياسة «العرض بديل» (س4/2).
 * البنود ذات العرض تُستبعد من أساس عروض السلة/الكومبو وتأخذ عرضها فقط.
 *
 * @param list<array<string,mixed>> $validatedItems
 *
 * @return array{non_offer_items: list<array<string,mixed>>, offer_items_value: float, offer_discount: float, offer_product_ids: array<int,bool>}
 */
function orange_product_offer_partition_items(PDO $pdo, array $validatedItems, ?int $countryId = null): array
{
    $nonOffer = [];
    $offerValue = 0.0;
    $offerDiscount = 0.0;
    $offerPids = [];
    $cache = [];
    foreach ($validatedItems as $line) {
        $isGift = is_array($line) && (!empty($line['is_gift']) || !empty($line['is_bogo_gift']));
        $pid = is_array($line) ? (int) (($line['product']['id'] ?? 0)) : 0;
        $qty = is_array($line) ? (int) ($line['qty'] ?? 0) : 0;
        $price = is_array($line) ? (float) ($line['price'] ?? 0) : 0.0;
        $unitDisc = 0.0;
        if (!$isGift && $pid > 0 && $qty > 0 && $price > 0.0001) {
            if (!array_key_exists($pid, $cache)) {
                $cache[$pid] = orange_product_offer_active_unit_discount($pdo, $pid, $countryId);
            }
            $unitDisc = min($cache[$pid], $price);
        }
        if ($unitDisc > 0.0001) {
            $offerPids[$pid] = true;
            $offerValue += round($price * $qty, 4);
            $offerDiscount += round($unitDisc * $qty, 4);
        } else {
            $nonOffer[] = $line;
        }
    }

    return [
        'non_offer_items' => $nonOffer,
        'offer_items_value' => round($offerValue, 4),
        'offer_discount' => round($offerDiscount, 4),
        'offer_product_ids' => $offerPids,
    ];
}
