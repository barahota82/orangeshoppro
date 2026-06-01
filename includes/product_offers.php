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
 * يمرّ على العروض النشطة غير الموقوفة ويوقف ما نفد مخزون منتجه.
 */
function orange_product_offer_sync_all_stock_pauses(PDO $pdo, ?int $countryId = null): void
{
    if (!orange_table_exists($pdo, orange_product_offer_table())) {
        return;
    }
    if (!orange_table_has_column($pdo, orange_product_offer_table(), 'auto_paused_at')) {
        return;
    }
    require_once __DIR__ . '/countries.php';
    $cid = $countryId > 0 ? $countryId : orange_storefront_current_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $cid);
    $scheduleSql = orange_product_offer_storefront_sql('o');
    $st = $pdo->query(
        'SELECT o.id, o.product_id
         FROM offers o
         INNER JOIN products p ON p.id = o.product_id
         WHERE o.is_active = 1' . $scheduleSql . $countrySql
    );
    if ($st === false) {
        return;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        orange_product_offer_sync_stock_pause(
            $pdo,
            (int) ($row['id'] ?? 0),
            (int) ($row['product_id'] ?? 0),
            $cid
        );
    }
}
