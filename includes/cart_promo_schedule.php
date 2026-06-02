<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_promotion_country.php';

/**
 * جدول عرض سلة: جدولة تواريخ + إيقاف تلقائي عند نفاد مخزون منتجات العرض أو الهدية.
 *
 * @return list<string>
 */
function orange_cart_promo_scheduled_tables(): array
{
    return [
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
    ];
}

/**
 * @return list<string> جداول فيها هدية (إيقاف gift_stock).
 */
function orange_cart_promo_gift_stock_tables(): array
{
    return ['cart_gift_promotions', 'cart_bogo_promotions'];
}

/**
 * @return list<string> أسباب إيقاف تلقائي معروفة في الأدمن.
 */
function orange_cart_promo_auto_pause_reasons(): array
{
    return ['promo_stock', 'gift_stock'];
}

function orange_cart_promo_admin_pause_reason_label_ar(string $reason): string
{
    return match (trim($reason)) {
        'promo_stock' => 'نفاد مخزون منتجات العرض',
        'gift_stock' => 'عدم توفر الهدية (نفاد مخزون الهدية)',
        'stock_resumed' => 'إعادة تفعيل تلقائي (عودة المخزون)',
        default => 'إيقاف تلقائي',
    };
}

/**
 * مرحلة 10: إعادة تفعيل تلقائي فقط بعد إيقاف مخزون — لا تُطبَّق إن انتهت الفترة أو أوقف الأدمن (is_active=0).
 *
 * @param array<string,mixed> $row
 */
function orange_cart_promo_row_eligible_stock_auto_unpause(array $row): bool
{
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return false;
    }
    $reason = trim((string) ($row['auto_paused_reason'] ?? ''));
    if ($reason === '' || !in_array($reason, orange_cart_promo_auto_pause_reasons(), true)) {
        return false;
    }

    return orange_cart_promo_is_within_schedule(
        (string) ($row['valid_from'] ?? ''),
        (string) ($row['valid_to'] ?? '')
    );
}

/**
 * @param array<string,mixed> $rule
 *
 * @return array<string,mixed>
 */
function orange_cart_promo_rule_row_for_stock_unpause(array $rule): array
{
    return [
        'id' => (int) ($rule['id'] ?? 0),
        'is_active' => (int) ($rule['is_active'] ?? 1),
        'valid_from' => (string) ($rule['valid_from'] ?? ''),
        'valid_to' => (string) ($rule['valid_to'] ?? ''),
        'auto_paused_at' => $rule['auto_paused_at'] ?? null,
        'auto_paused_reason' => (string) ($rule['auto_paused_reason'] ?? ''),
    ];
}

/**
 * @param array<string,mixed> $row
 */
function orange_cart_promo_row_is_customer_effective(array $row): bool
{
    if ((int) ($row['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (!empty($row['auto_paused_at']) || trim((string) ($row['auto_paused_reason'] ?? '')) !== '') {
        return false;
    }

    return orange_cart_promo_is_within_schedule(
        (string) ($row['valid_from'] ?? ''),
        (string) ($row['valid_to'] ?? '')
    );
}

function orange_cart_promo_is_within_schedule(string $validFrom, string $validTo): bool
{
    $from = strtotime(trim($validFrom));
    $to = strtotime(trim($validTo));
    if ($from === false || $to === false) {
        return false;
    }
    $now = time();

    return $now >= $from && $now <= $to;
}

/**
 * شريط SQL: نشط + ضمن المدة + غير موقوف تلقائياً.
 */
function orange_cart_promo_schedule_sql(string $alias): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
    if ($a === '') {
        $a = 't';
    }

    return ' AND ' . $a . '.is_active = 1'
        . ' AND ' . $a . '.auto_paused_at IS NULL'
        . ' AND ' . $a . '.valid_from <= NOW()'
        . ' AND ' . $a . '.valid_to >= NOW()';
}

/**
 * تحقق تواريخ الأدمن (Y-m-d) — إلزامية؛ valid_to نهاية اليوم inclusive.
 *
 * @return array{valid_from:string,valid_to:string}|null null + رسالة عبر $err
 */
function orange_cart_promo_parse_required_admin_dates(string $fromIso, string $toIso, ?string &$err = null): ?array
{
    $err = null;
    $fromIso = trim($fromIso);
    $toIso = trim($toIso);
    if ($fromIso === '' || $toIso === '') {
        $err = 'تاريخ بداية ونهاية العرض إلزاميان';

        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toIso)) {
        $err = 'صيغة التاريخ غير صالحة (YYYY-MM-DD)';

        return null;
    }
    $fromTs = strtotime($fromIso . ' 00:00:00');
    $toTs = strtotime($toIso . ' 23:59:59');
    if ($fromTs === false || $toTs === false) {
        $err = 'تاريخ غير صالح';

        return null;
    }
    if ($fromTs > $toTs) {
        $err = 'تاريخ البداية يجب أن يسبق أو يساوي تاريخ النهاية';

        return null;
    }

    return [
        'valid_from' => date('Y-m-d H:i:s', $fromTs),
        'valid_to' => date('Y-m-d H:i:s', $toTs),
    ];
}

/**
 * @param array<string,mixed> $row
 */
function orange_cart_promo_admin_schedule_label(array $row): string
{
    $vf = trim((string) ($row['valid_from'] ?? ''));
    $vt = trim((string) ($row['valid_to'] ?? ''));
    if ($vf === '' || $vt === '') {
        return '—';
    }

    return substr($vf, 0, 10) . ' → ' . substr($vt, 0, 10);
}

/**
 * @return list<array{table:string,id:int,kind:string,label:string,reason:string,reason_label:string,paused_at:string}>
 */
function orange_cart_promo_admin_auto_paused_alerts(PDO $pdo): array
{
    $out = [];
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $labels = [
        'cart_promotions' => 'خصم مجموع السلة',
        'cart_gift_promotions' => 'هدية مجموع السلة',
        'cart_bogo_promotions' => 'BOGO',
        'cart_combo_promotions' => 'كومبو',
        'offers' => 'عرض منتج',
    ];
    $pageMap = [
        'cart_promotions' => 'cart_promotions',
        'cart_gift_promotions' => 'cart_gift_promotions',
        'cart_bogo_promotions' => 'cart_bogo_promotions',
        'cart_combo_promotions' => 'cart_combo_promotions',
        'offers' => 'offers',
    ];
    $tables = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    $reasonIn = implode(',', array_map(static fn (string $r): string => $pdo->quote($r), orange_cart_promo_auto_pause_reasons()));
    foreach ($tables as $table) {
        if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_reason')) {
            continue;
        }
        $bind = orange_cart_promotion_sql_bind($pdo, $table, 't', $cid);
        $st = $pdo->prepare(
            'SELECT t.id, t.auto_paused_at, t.auto_paused_reason
             FROM ' . $table . ' t
             WHERE t.auto_paused_reason IN (' . $reasonIn . ')' . $bind['sql'] . '
             ORDER BY t.auto_paused_at DESC, t.id DESC
             LIMIT 20'
        );
        $st->execute($bind['params']);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $reason = trim((string) ($row['auto_paused_reason'] ?? ''));
            $kind = $labels[$table] ?? $table;
            $out[] = [
                'table' => $table,
                'page' => $pageMap[$table] ?? $table,
                'id' => (int) ($row['id'] ?? 0),
                'kind' => $kind,
                'label' => $kind . ' #' . (int) ($row['id'] ?? 0) . ' — ' . orange_cart_promo_admin_pause_reason_label_ar($reason),
                'reason' => $reason,
                'reason_label' => orange_cart_promo_admin_pause_reason_label_ar($reason),
                'paused_at' => (string) ($row['auto_paused_at'] ?? ''),
            ];
        }
    }

    return $out;
}

function orange_cart_promo_clear_auto_pause(PDO $pdo, string $table, int $id): void
{
    $pausable = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    if ($id <= 0 || !in_array($table, $pausable, true)) {
        return;
    }
    if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        return;
    }
    $st = $pdo->prepare(
        'UPDATE ' . $table . ' SET auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = ?'
    );
    $st->execute([$id]);
}

function orange_cart_promo_auto_pause_with_reason(PDO $pdo, string $table, int $id, string $reason): bool
{
    $reason = trim($reason);
    if ($id <= 0 || !in_array($reason, orange_cart_promo_auto_pause_reasons(), true)) {
        return false;
    }
    $pausable = array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
    if (!in_array($table, $pausable, true)) {
        return false;
    }
    if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        return false;
    }
    $st = $pdo->prepare(
        "UPDATE {$table} SET auto_paused_at = NOW(), auto_paused_reason = ?
         WHERE id = ? AND (auto_paused_at IS NULL OR auto_paused_reason IS NULL OR auto_paused_reason = '')"
    );
    $st->execute([$reason, $id]);

    return $st->rowCount() > 0;
}

/**
 * منتجات «العرض» المربوطة بقاعدة (شراء كومبو/BOGO) — ليست مجموعة الهدية.
 *
 * @param array<string,mixed> $rule
 *
 * @return list<int> product_ids
 */
function orange_cart_promo_rule_offer_product_ids(PDO $pdo, string $table, array $rule): array
{
    require_once __DIR__ . '/cart_promo_products.php';

    if ($table === 'cart_combo_promotions') {
        $comps = orange_cart_promo_parse_components_json($pdo, $rule['components_json'] ?? null);
        $ids = [];
        foreach ($comps as $c) {
            $pid = (int) ($c['product_id'] ?? 0);
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }

        return array_keys($ids);
    }
    if ($table === 'cart_bogo_promotions') {
        $kind = strtolower(trim((string) ($rule['bogo_kind'] ?? '')));
        if ($kind !== 'buy_bundle') {
            return [];
        }
        $comps = orange_cart_promo_parse_components_json($pdo, $rule['buy_components_json'] ?? null);
        $ids = [];
        foreach ($comps as $c) {
            $pid = (int) ($c['product_id'] ?? 0);
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }

        return array_keys($ids);
    }

    return [];
}

/**
 * أي منتج مطلوب للعرض بلا مخزون للزائر → إيقاف promo_stock.
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_offer_products_have_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    require_once __DIR__ . '/cart_promo_products.php';

    $pids = orange_cart_promo_rule_offer_product_ids($pdo, $table, $rule);
    if ($pids === []) {
        return true;
    }
    foreach ($pids as $pid) {
        if (!orange_cart_promo_product_has_visitor_stock($pdo, (int) $pid, $validatedItems, $countryId)) {
            return false;
        }
    }

    return true;
}

/**
 * هل للقاعدة هدية متوفرة مخزوناً (أي متغير لأي منتج في المجموعة/الثابت).
 *
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_gift_rule_has_stock(
    PDO $pdo,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    require_once __DIR__ . '/cart_gift_promotions.php';

    $gKind = strtolower(trim((string) ($rule['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
    if ($gKind === 'fixed') {
        $fv = (int) ($rule['fixed_variant_id'] ?? $rule['fixed_product_id'] ?? 0);

        return $fv > 0
            && count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, false, $countryId)) > 0;
    }
    $pool = $rule['pool_variant_ids'] ?? $rule['pool_product_ids'] ?? [];
    if (!is_array($pool) || count($pool) === 0) {
        return false;
    }

    return count(
        orange_cart_gift_promotion_pool_options($pdo, $pool, $validatedItems, false, $countryId)
    ) > 0;
}

/**
 * @param array<string,mixed> $rule
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_promo_maybe_pause_rule_if_no_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool
{
    require_once __DIR__ . '/cart_promo_stock_health.php';

    return orange_cart_promo_apply_stock_pause_for_rule($pdo, $table, $rule, $countryId);
}

/** @deprecated استخدم orange_cart_promo_maybe_pause_rule_if_no_stock */
function orange_cart_promo_maybe_pause_gift_rule_if_no_stock(
    PDO $pdo,
    string $table,
    array $rule,
    array $validatedItems,
    ?int $countryId = null
): bool {
    return orange_cart_promo_maybe_pause_rule_if_no_stock($pdo, $table, $rule, $validatedItems, $countryId);
}
