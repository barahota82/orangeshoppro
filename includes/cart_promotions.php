<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/cart_promo_schedule.php';

/**
 * @return list<array{id:int, min_subtotal:float, discount_amount:float, requires_registered_account:int, sort_order:int, is_active:int}>
 */
function orange_cart_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_promotions')) {
        return [];
    }
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_promotions', '', $cid);
    $st = $pdo->prepare(
        'SELECT id, name_ar, name_en, min_subtotal, discount_amount, requires_registered_account,
                first_delivered_order_only, sort_order, is_active, is_always_on,
                valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_promotions WHERE 1=1' . $bind['sql'] . ' ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($bind['params']);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * أفضل عرض يطابق مجموع السلة: أعلى حد أدنى للمجموع يحقق الشرط، ثم أعلى خصم عند التعادل.
 * عروض «للمسجّلين فقط» تُطبَّق فقط إذا buyerIsRegistered.
 *
 * @return array{id:int, discount:float, min_subtotal:float}|null
 */
function orange_cart_promotion_resolve(
    PDO $pdo,
    float $subtotal,
    bool $buyerIsRegistered,
    ?int $countryId = null,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): ?array {
    if (!orange_table_exists($pdo, 'cart_promotions')) {
        return null;
    }
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_promotions', '', $cid);
    $st = $pdo->prepare(
        "SELECT id, min_subtotal, discount_amount, requires_registered_account, first_delivered_order_only,
                is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_promotions
         WHERE 1=1" . orange_cart_promo_schedule_sql('cart_promotions') . $bind['sql'] . "
         ORDER BY min_subtotal DESC, discount_amount DESC, id DESC"
    );
    $st->execute($bind['params']);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!orange_cart_promo_row_is_customer_effective($row)) {
            continue;
        }
        $min = (float) ($row['min_subtotal'] ?? 0);
        if ($subtotal + 0.00001 < $min) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerIsRegistered) {
            continue;
        }
        if ((int) ($row['first_delivered_order_only'] ?? 0) === 1
            && !orange_cart_promo_buyer_first_delivered_ok($pdo, $buyerAccountId, $buyerPhone, $cid)) {
            continue;
        }
        $disc = (float) ($row['discount_amount'] ?? 0);
        if ($disc <= 0) {
            continue;
        }
        $applied = min($disc, $subtotal);

        return [
            'id' => (int) $row['id'],
            'discount' => round($applied, 4),
            'min_subtotal' => $min,
        ];
    }

    return null;
}

/**
 * أفضل عرض نشط «للمسجّلين فقط» يحققه المجموع (س7 — تحفيز التسجيل).
 *
 * @return array{min_subtotal: float, discount_amount: float}|null
 */
function orange_cart_promotion_best_registered_only_match(PDO $pdo, float $subtotal, ?int $countryId = null): ?array
{
    if (!orange_table_exists($pdo, 'cart_promotions')) {
        return null;
    }
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_promotions', '', $cid);
    $st = $pdo->prepare(
        "SELECT min_subtotal, discount_amount, is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_promotions
         WHERE requires_registered_account = 1" . orange_cart_promo_schedule_sql('cart_promotions') . $bind['sql'] . "
         ORDER BY min_subtotal DESC, discount_amount DESC, id DESC"
    );
    $st->execute($bind['params']);
    if (!$st) {
        return null;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!orange_cart_promo_row_is_customer_effective($row)) {
            continue;
        }
        $min = (float) ($row['min_subtotal'] ?? 0);
        if ($subtotal + 0.00001 < $min) {
            continue;
        }
        $disc = (float) ($row['discount_amount'] ?? 0);
        if ($disc <= 0) {
            continue;
        }

        return [
            'min_subtotal' => $min,
            'discount_amount' => round(min($disc, $subtotal), 4),
        ];
    }

    return null;
}

/**
 * فرق الخصم الإضافي للضيف إن سجّل — يُعرض فقط إن كان أوفر من عرض الضيف الحالي.
 *
 * @return array{you_save_extra: float, discount_amount: float, min_subtotal: float}|null
 */
function orange_cart_promotion_register_incentive_teaser(PDO $pdo, float $subtotal, bool $buyerIsRegistered, ?int $countryId = null): ?array
{
    if ($buyerIsRegistered || $subtotal <= 0) {
        return null;
    }
    $guestPromo = orange_cart_promotion_resolve($pdo, $subtotal, false, $countryId);
    $guestDisc = $guestPromo !== null ? (float) $guestPromo['discount'] : 0.0;
    $regOnly = orange_cart_promotion_best_registered_only_match($pdo, $subtotal, $countryId);
    if ($regOnly === null) {
        return null;
    }
    $regDisc = (float) $regOnly['discount_amount'];
    if ($regDisc <= $guestDisc + 1e-6) {
        return null;
    }

    return [
        'you_save_extra' => round($regDisc - $guestDisc, 4),
        'discount_amount' => $regDisc,
        'min_subtotal' => (float) $regOnly['min_subtotal'],
    ];
}
