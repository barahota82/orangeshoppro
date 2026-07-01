<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/cart_promo_gift_charge.php';

/**
 * Per-product gift pricing JSON on cart_gift_promotions.gift_pool_config (v113+).
 *
 * Schema:
 * {"version":1,"items":[{"product_id":12,"charge_kind":"free","charge_value":0}]}
 *
 * @param list<string> $allowed
 */
function orange_cart_gift_pool_config_allowed_charge_kinds(): array
{
    return ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'];
}

function orange_cart_gift_pool_config_normalize_charge_kind(?string $kind): string
{
    $k = strtolower(trim((string) $kind));
    if (!in_array($k, orange_cart_gift_pool_config_allowed_charge_kinds(), true)) {
        return 'free';
    }

    return $k;
}

/**
 * @return array{version:int,items:list<array{product_id:int,charge_kind:string,charge_value:float}>}
 */
function orange_cart_gift_pool_config_decode(?string $json): array
{
    $empty = ['version' => 1, 'items' => []];
    if ($json === null || trim($json) === '') {
        return $empty;
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return $empty;
    }
    $items = [];
    if (isset($d['items']) && is_array($d['items'])) {
        foreach ($d['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $items[] = [
                'product_id' => $pid,
                'charge_kind' => orange_cart_gift_pool_config_normalize_charge_kind((string) ($it['charge_kind'] ?? 'free')),
                'charge_value' => round(max(0.0, (float) ($it['charge_value'] ?? 0)), 4),
            ];
        }
    }

    return [
        'version' => (int) ($d['version'] ?? 1),
        'items' => $items,
    ];
}

/**
 * @param list<array{product_id:int,charge_kind:string,charge_value:float}> $items
 */
function orange_cart_gift_pool_config_encode(array $items): string
{
    $out = [];
    $seen = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0 || isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        $out[] = [
            'product_id' => $pid,
            'charge_kind' => orange_cart_gift_pool_config_normalize_charge_kind((string) ($it['charge_kind'] ?? 'free')),
            'charge_value' => round(max(0.0, (float) ($it['charge_value'] ?? 0)), 4),
        ];
    }

    return json_encode(
        ['version' => 1, 'items' => $out],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?: '{"version":1,"items":[]}';
}

/**
 * Build per-product items from legacy rule-level gift_unit_charge_* (migration v113).
 *
 * @param array<string,mixed> $row
 *
 * @return list<array{product_id:int,charge_kind:string,charge_value:float}>
 */
function orange_cart_gift_pool_config_build_items_from_legacy_rule(PDO $pdo, array $row): array
{
    $kind = orange_cart_gift_pool_config_normalize_charge_kind((string) ($row['gift_unit_charge_kind'] ?? 'free'));
    $val = round(max(0.0, (float) ($row['gift_unit_charge_value'] ?? 0)), 4);
    $giftKind = strtolower(trim((string) ($row['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';

    $productIds = [];
    if ($giftKind === 'fixed') {
        $stored = (int) ($row['fixed_variant_id'] ?? 0);
        $pid = $stored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $stored) : 0;
        if ($pid > 0) {
            $productIds[] = $pid;
        }
    } else {
        $poolJson = isset($row['pool_variant_ids']) ? (string) $row['pool_variant_ids'] : '';
        $productIds = orange_cart_promo_parse_product_pool($pdo, $poolJson !== '' ? $poolJson : null);
    }

    $items = [];
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $items[] = [
            'product_id' => $pid,
            'charge_kind' => $kind,
            'charge_value' => $val,
        ];
    }

    return $items;
}

/**
 * @param array{version:int,items:list<array{product_id:int,charge_kind:string,charge_value:float}>} $config
 */
function orange_cart_gift_pool_config_find_item(array $config, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    foreach ($config['items'] as $it) {
        if ((int) ($it['product_id'] ?? 0) === $productId) {
            return $it;
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $rule
 *
 * @return array{version:int,items:list<array{product_id:int,charge_kind:string,charge_value:float}>}
 */
function orange_cart_gift_pool_config_for_rule(PDO $pdo, array $rule): array
{
    if (isset($rule['gift_pool_config']) && is_string($rule['gift_pool_config']) && trim($rule['gift_pool_config']) !== '') {
        $cfg = orange_cart_gift_pool_config_decode($rule['gift_pool_config']);
        if (count($cfg['items']) > 0) {
            return $cfg;
        }
    }
    if (isset($rule['_gift_pool_config']) && is_array($rule['_gift_pool_config'])) {
        $items = [];
        foreach ($rule['_gift_pool_config']['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $items[] = [
                'product_id' => $pid,
                'charge_kind' => orange_cart_gift_pool_config_normalize_charge_kind((string) ($it['charge_kind'] ?? 'free')),
                'charge_value' => round(max(0.0, (float) ($it['charge_value'] ?? 0)), 4),
            ];
        }
        if (count($items) > 0) {
            return ['version' => 1, 'items' => $items];
        }
    }

    return orange_cart_gift_pool_config_decode(
        orange_cart_gift_pool_config_encode(
            orange_cart_gift_pool_config_build_items_from_legacy_rule($pdo, $rule)
        )
    );
}

function orange_cart_gift_pool_config_charge_unit(float $retail, string $kind, float $val): float
{
    $kind = orange_cart_gift_pool_config_normalize_charge_kind($kind);
    if ($kind === 'free') {
        return 0.0;
    }
    if ($kind === 'fixed_unit') {
        return max(0.0, round($val, 4));
    }
    $retail = max(0.0, round($retail, 4));
    if ($kind === 'percent_off') {
        $pct = min(100.0, max(0.0, $val));

        return max(0.0, round($retail * (1.0 - $pct / 100.0), 4));
    }
    if ($kind === 'amount_off_unit') {
        return max(0.0, round($retail - max(0.0, $val), 4));
    }

    return 0.0;
}

/**
 * Per-product charge for threshold gift (cart_gift_promotions).
 *
 * @param array<string,mixed> $rule
 */
function orange_cart_gift_resolve_product_charge(PDO $pdo, array $rule, int $productId, int $variantId): float
{
    if ($variantId <= 0) {
        return 0.0;
    }
    if ($productId <= 0) {
        $st = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ? LIMIT 1');
        $st->execute([$variantId]);
        $productId = (int) ($st->fetchColumn() ?: 0);
    }
    if ($productId <= 0) {
        return 0.0;
    }
    $retail = orange_cart_promo_gift_variant_retail_unit($pdo, $variantId);
    $cfg = orange_cart_gift_pool_config_for_rule($pdo, $rule);
    $item = orange_cart_gift_pool_config_find_item($cfg, $productId);
    if ($item === null) {
        return orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $rule, $variantId);
    }

    return orange_cart_gift_pool_config_charge_unit(
        $retail,
        (string) ($item['charge_kind'] ?? 'free'),
        (float) ($item['charge_value'] ?? 0)
    );
}

/**
 * @param array<string,mixed> $rule
 */
function orange_cart_gift_resolve_unit_price(PDO $pdo, array $rule, int $variantId): float
{
    if ($variantId <= 0) {
        return 0.0;
    }
    if (($rule['promo_type'] ?? '') !== 'threshold_gift') {
        return orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $rule, $variantId);
    }

    return orange_cart_gift_resolve_product_charge($pdo, $rule, 0, $variantId);
}

function orange_cart_gift_product_charge_is_fully_free(float $chargeUnit): bool
{
    return $chargeUnit <= 0.00001;
}
