<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array{id:int, min_subtotal:float, discount_amount:float, requires_registered_account:int, sort_order:int, is_active:int}>
 */
function orange_cart_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_promotions')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, min_subtotal, discount_amount, requires_registered_account, sort_order, is_active
         FROM cart_promotions ORDER BY sort_order ASC, id ASC'
    );

    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * أفضل عرض يطابق مجموع السلة: أعلى حد أدنى للمجموع يحقق الشرط، ثم أعلى خصم عند التعادل.
 * عروض «للمسجّلين فقط» تُطبَّق فقط إذا buyerIsRegistered.
 *
 * @return array{id:int, discount:float, min_subtotal:float}|null
 */
function orange_cart_promotion_resolve(PDO $pdo, float $subtotal, bool $buyerIsRegistered): ?array
{
    if (!orange_table_exists($pdo, 'cart_promotions')) {
        return null;
    }
    $st = $pdo->query(
        "SELECT id, min_subtotal, discount_amount, requires_registered_account
         FROM cart_promotions
         WHERE is_active = 1
         ORDER BY min_subtotal DESC, discount_amount DESC, id DESC"
    );
    if (!$st) {
        return null;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $min = (float) ($row['min_subtotal'] ?? 0);
        if ($subtotal + 0.00001 < $min) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerIsRegistered) {
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
