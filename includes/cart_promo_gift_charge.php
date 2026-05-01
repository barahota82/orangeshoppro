<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_unified_product_helpers.php';

/**
 * تسعير وحدة بند هدية ترويجية (عروض مجموع سلة + BOGO) — مجاني أو نسبة/مبلغ/سعر ثابت.
 *
 * @param array<string,mixed> $rule يحتوي gift_unit_charge_kind و gift_unit_charge_value
 */
function orange_cart_promo_resolve_gift_unit_price_from_rule(PDO $pdo, array $rule, int $variantId): float
{
    if ($variantId <= 0) {
        return 0.0;
    }
    $kind = strtolower(trim((string) ($rule['gift_unit_charge_kind'] ?? 'free')));
    if (!in_array($kind, ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'], true)) {
        $kind = 'free';
    }
    if ($kind === 'free') {
        return 0.0;
    }
    $val = (float) ($rule['gift_unit_charge_value'] ?? 0);
    if ($kind === 'fixed_unit') {
        return max(0.0, round($val, 4));
    }
    $st = $pdo->prepare(
        'SELECT p.id AS product_id, p.price FROM products p
         INNER JOIN product_variants v ON v.product_id = p.id
         WHERE v.id = ? AND p.is_active = 1 LIMIT 1'
    );
    $st->execute([$variantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0.0;
    }
    $giftPid = (int) ($row['product_id'] ?? 0);
    if ($giftPid <= 0 || !orange_storefront_product_in_active_unified_chain($pdo, $giftPid)) {
        return 0.0;
    }
    $retail = (float) ($row['price'] ?? 0);
    if ($kind === 'percent_off') {
        $pct = min(100.0, max(0.0, $val));

        return max(0.0, round($retail * (1.0 - $pct / 100.0), 4));
    }
    if ($kind === 'amount_off_unit') {
        return max(0.0, round($retail - max(0.0, $val), 4));
    }

    return 0.0;
}
