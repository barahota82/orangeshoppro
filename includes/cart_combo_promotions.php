<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array{variant_id:int,qty:int}>
 */
function orange_cart_combo_parse_components_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    /** @var array<int, int> $merged */
    $merged = [];
    foreach ($d as $row) {
        if (!is_array($row)) {
            continue;
        }
        $vid = (int) ($row['variant_id'] ?? 0);
        $q = (int) ($row['qty'] ?? 0);
        if ($vid <= 0 || $q <= 0) {
            continue;
        }
        $merged[$vid] = ($merged[$vid] ?? 0) + $q;
    }
    $out = [];
    foreach ($merged as $vid => $q) {
        $out[] = ['variant_id' => $vid, 'qty' => $q];
    }

    return $out;
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array<int, array{qty:int, unit:float}>
 */
function orange_cart_combo_aggregate_variant_units(array $validatedItems): array
{
    /** @var array<int, array{sum:float, qty:int}> $agg */
    $agg = [];
    foreach ($validatedItems as $li) {
        $vid = (int) ($li['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $q = max(1, (int) ($li['qty'] ?? 1));
        $pr = (float) ($li['price'] ?? 0);
        if (!isset($agg[$vid])) {
            $agg[$vid] = ['sum' => 0.0, 'qty' => 0];
        }
        $agg[$vid]['sum'] += $pr * $q;
        $agg[$vid]['qty'] += $q;
    }
    $out = [];
    foreach ($agg as $vid => $row) {
        $q = max(1, $row['qty']);
        $out[$vid] = [
            'qty' => $row['qty'],
            'unit' => $row['sum'] > 0 ? $row['sum'] / $q : 0.0,
        ];
    }

    return $out;
}

/**
 * أفضل عرض كومبو واحد: أقصى توفير يحققه المخزون الحالي في السلة.
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array{id:int, discount:float, bundles:int}|null
 */
function orange_cart_combo_best_match(PDO $pdo, array $validatedItems, bool $buyerRegistered): ?array
{
    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        return null;
    }
    $st = $pdo->query(
        'SELECT id, components_json, combo_price, requires_registered_account
         FROM cart_combo_promotions
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return null;
    }
    $byV = orange_cart_combo_aggregate_variant_units($validatedItems);
    $best = null;
    $bestDisc = 0.0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        $comps = orange_cart_combo_parse_components_json($row['components_json'] ?? null);
        if (count($comps) < 2) {
            continue;
        }
        $comboPrice = round((float) ($row['combo_price'] ?? 0), 4);
        if ($comboPrice <= 0) {
            continue;
        }
        $bundles = PHP_INT_MAX;
        $sumPerBundle = 0.0;
        $ok = true;
        foreach ($comps as $c) {
            $vid = $c['variant_id'];
            $need = $c['qty'];
            if (!isset($byV[$vid]) || $byV[$vid]['qty'] < $need) {
                $ok = false;
                break;
            }
            $bundles = min($bundles, intdiv($byV[$vid]['qty'], $need));
            $sumPerBundle += $byV[$vid]['unit'] * $need;
        }
        if (!$ok || $bundles < 1 || $bundles === PHP_INT_MAX) {
            continue;
        }
        if ($sumPerBundle <= $comboPrice + 1e-6) {
            continue;
        }
        $savings = round($bundles * ($sumPerBundle - $comboPrice), 4);
        if ($savings > $bestDisc + 1e-6) {
            $bestDisc = $savings;
            $best = [
                'id' => (int) $row['id'],
                'discount' => $savings,
                'bundles' => $bundles,
            ];
        }
    }

    return $best;
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_combo_register_unlock_teaser_applies(
    PDO $pdo,
    array $validatedItems,
    bool $buyerIsRegistered
): bool {
    if ($buyerIsRegistered || count($validatedItems) === 0) {
        return false;
    }
    $asGuest = orange_cart_combo_best_match($pdo, $validatedItems, false);
    $asReg = orange_cart_combo_best_match($pdo, $validatedItems, true);
    if ($asReg === null || $asReg['discount'] <= 1e-6) {
        return false;
    }
    $gd = $asGuest !== null ? (float) $asGuest['discount'] : 0.0;

    return $asReg['discount'] > $gd + 1e-6;
}

/**
 * @return list<array{id:int,title_ar:string,title_en:string,components:list<array{variant_id:int,qty:int}>,combo_price:float,requires_registered_account:int,sort_order:int,is_active:int}>
 */
function orange_cart_combo_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, title_ar, title_en, components_json, combo_price, requires_registered_account, sort_order, is_active
         FROM cart_combo_promotions ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return [];
    }
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'id' => (int) $row['id'],
            'title_ar' => (string) ($row['title_ar'] ?? ''),
            'title_en' => (string) ($row['title_en'] ?? ''),
            'components' => orange_cart_combo_parse_components_json($row['components_json'] ?? null),
            'combo_price' => (float) ($row['combo_price'] ?? 0),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0),
        ];
    }

    return $out;
}
