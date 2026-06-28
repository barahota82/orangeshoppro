<?php

declare(strict_types=1);

/**
 * المرحلة 0 من خطة تاب «العروض» (قرار مالك 2026-06-28):
 * مساعد جلب موحّد يُعيد «بطاقات العروض المرتكزة على منتج» الفعّالة للواجهة
 * (عروض الكومبو + BOGO). عروض المنتجات المفردة (جدول offers) تُجلب مباشرةً في
 * pages/home.php لأنها تغيّر سعر المنتج وتُدمج في الشبكة هناك؛ هذا المساعد يغطّي
 * الكومبو/BOGO التي لا تملك سطحاً للعميل بعد.
 *
 * الحراسة (ح1): الجدولة (orange_cart_promo_schedule_sql) + ربط الدولة
 * (orange_cart_promotion_sql_bind) + النشاط/الإيقاف التلقائي مضمّنة في شرط
 * الجدولة. أهليّة «المسجّلين/أول طلب» لا تُخفي العرض (قرار مالك: إظهار + تحفيز
 * تسجيل) بل تُعلَّم على البطاقة عبر requires_registration / first_delivered_only.
 *
 * الأداء (ح2): جلب بيانات منتجات المكوّنات/الهدايا دفعة واحدة (لا N+1).
 */

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_promo_schedule.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/promo_always_on.php';

/**
 * خريطة عرض المنتجات (اسم مترجم + سعر + تكلفة + صورة مع احتياط + ألوان) لمجموعة
 * معرّفات، باستعلام واحد (+ استعلام احتياط صور واحد عند اللزوم).
 *
 * @param list<int> $productIds
 *
 * @return array<int, array{product_id:int,name:string,price:float,cost:float,main_image:string,has_colors:int}>
 */
function orange_storefront_offer_product_display_map(PDO $pdo, array $productIds): array
{
    $ids = [];
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid > 0) {
            $ids[$pid] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === [] || !orange_table_exists($pdo, 'products')) {
        return [];
    }

    $hasPrice = orange_table_has_column($pdo, 'products', 'price');
    $hasCost = orange_table_has_column($pdo, 'products', 'cost');
    $hasMainImg = orange_table_has_column($pdo, 'products', 'main_image');
    $hasColors = orange_table_has_column($pdo, 'products', 'has_colors');

    $cols = 'id, name';
    foreach (['name_en', 'name_fil', 'name_hi'] as $nameCol) {
        if (orange_table_has_column($pdo, 'products', $nameCol)) {
            $cols .= ', ' . $nameCol;
        }
    }
    if ($hasPrice) {
        $cols .= ', price';
    }
    if ($hasCost) {
        $cols .= ', cost';
    }
    if ($hasMainImg) {
        $cols .= ', main_image';
    }
    if ($hasColors) {
        $cols .= ', has_colors';
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT $cols FROM products WHERE id IN ($placeholders) AND is_active = 1");
    $st->execute($ids);

    $map = [];
    $needImage = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $img = $hasMainImg ? trim((string) ($row['main_image'] ?? '')) : '';
        if ($img === '') {
            $needImage[] = $pid;
        }
        $map[$pid] = [
            'product_id' => $pid,
            'name' => storefront_product_display_name($row),
            'price' => $hasPrice ? (float) ($row['price'] ?? 0) : 0.0,
            'cost' => $hasCost ? (float) ($row['cost'] ?? 0) : 0.0,
            'main_image' => $img,
            'has_colors' => $hasColors ? (int) ($row['has_colors'] ?? 0) : 0,
        ];
    }

    if ($needImage !== [] && function_exists('orange_product_first_colorway_image_map')) {
        $fallback = orange_product_first_colorway_image_map($pdo, $needImage);
        foreach ($needImage as $pid) {
            if (!empty($fallback[$pid]) && isset($map[$pid])) {
                $map[$pid]['main_image'] = (string) $fallback[$pid];
            }
        }
    }

    return $map;
}

/**
 * بطاقات الكومبو الفعّالة للواجهة.
 *
 * @return list<array<string,mixed>>
 */
function orange_storefront_active_combo_cards(PDO $pdo, ?int $countryId, string $lang): array
{
    if (!orange_table_exists($pdo, 'cart_combo_promotions')
        || !orange_table_has_column($pdo, 'cart_combo_promotions', 'show_old_price_to_customer')) {
        return [];
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : 0;
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_combo_promotions', '', $cid);
    $sortSql = orange_table_has_column($pdo, 'cart_combo_promotions', 'sort_order')
        ? ' ORDER BY sort_order ASC, id ASC'
        : ' ORDER BY id ASC';
    $st = $pdo->prepare(
        'SELECT id, title_ar, title_en, show_name_to_customer, show_old_price_to_customer,
                components_json, combo_price, requires_registered_account, first_delivered_order_only,
                is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_combo_promotions
         WHERE 1=1' . orange_cart_promo_schedule_sql('cart_combo_promotions') . $bind['sql'] . $sortSql
    );
    $st->execute($bind['params']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $allProductIds = [];
    $parsed = [];
    foreach ($rows as $row) {
        $comps = orange_cart_promo_parse_components_json($pdo, $row['components_json'] ?? null);
        if (count($comps) < 2) {
            continue;
        }
        foreach ($comps as $c) {
            $allProductIds[] = (int) $c['product_id'];
        }
        $parsed[] = ['row' => $row, 'comps' => $comps];
    }
    if ($parsed === []) {
        return [];
    }
    $pmap = orange_storefront_offer_product_display_map($pdo, $allProductIds);

    $cards = [];
    foreach ($parsed as $entry) {
        $row = $entry['row'];
        $components = [];
        $componentsTotal = 0.0;
        $missing = false;
        foreach ($entry['comps'] as $c) {
            $pid = (int) $c['product_id'];
            $qty = (int) $c['qty'];
            if (!isset($pmap[$pid])) {
                $missing = true;
                break;
            }
            $pinfo = $pmap[$pid];
            $componentsTotal += $pinfo['price'] * $qty;
            $components[] = $pinfo + ['qty' => $qty];
        }
        if ($missing || $components === []) {
            continue;
        }
        $cards[] = [
            'type' => 'combo',
            'offer_id' => (int) $row['id'],
            'name' => orange_promo_customer_display_name([
                'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
                'name_ar' => (string) ($row['title_ar'] ?? ''),
                'name_en' => (string) ($row['title_en'] ?? ''),
            ], $lang),
            'show_old_price' => (int) ($row['show_old_price_to_customer'] ?? 0) === 1,
            'bundle_price' => (float) ($row['combo_price'] ?? 0),
            'components_total' => round($componentsTotal, 4),
            'components' => $components,
            'requires_registration' => (int) ($row['requires_registered_account'] ?? 0) === 1,
            'first_delivered_only' => (int) ($row['first_delivered_order_only'] ?? 0) === 1,
        ];
    }

    return $cards;
}

/**
 * بطاقات BOGO الفعّالة للواجهة (بيانات مُنظَّمة للعرض في التاب وصفحة العرض لاحقاً).
 *
 * @return list<array<string,mixed>>
 */
function orange_storefront_active_bogo_cards(PDO $pdo, ?int $countryId, string $lang): array
{
    if (!orange_table_exists($pdo, 'cart_bogo_promotions')
        || !orange_table_has_column($pdo, 'cart_bogo_promotions', 'show_old_price_to_customer')) {
        return [];
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : 0;
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_bogo_promotions', '', $cid);
    $sortSql = orange_table_has_column($pdo, 'cart_bogo_promotions', 'sort_order')
        ? ' ORDER BY sort_order ASC, id ASC'
        : ' ORDER BY id ASC';
    $svCol = orange_table_has_column($pdo, 'cart_bogo_promotions', 'same_variant_product_id') ? ', same_variant_product_id' : '';
    $st = $pdo->prepare(
        'SELECT id, name_ar, name_en, show_name_to_customer, show_old_price_to_customer, bogo_kind,
                category_id, min_buy_qty, buy_components_json, gift_kind, fixed_variant_id, pool_variant_ids,
                gift_unit_charge_kind, gift_unit_charge_value, requires_registered_account, first_delivered_order_only,
                is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason' . $svCol . '
         FROM cart_bogo_promotions
         WHERE 1=1' . orange_cart_promo_schedule_sql('cart_bogo_promotions') . $bind['sql'] . $sortSql
    );
    $st->execute($bind['params']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }

    // تجميع كل معرّفات المنتجات (مكوّنات الشراء + الهدية الثابتة + بركة الاختيار) لجلب مُجمَّع.
    $allProductIds = [];
    $prepared = [];
    foreach ($rows as $row) {
        $kind = strtolower(trim((string) ($row['bogo_kind'] ?? 'same_variant')));
        $kind = in_array($kind, ['buy_bundle', 'same_category', 'same_variant'], true) ? $kind : 'same_variant';
        $buyComps = $kind === 'buy_bundle'
            ? orange_cart_promo_parse_components_json($pdo, $row['buy_components_json'] ?? null)
            : [];
        // same_variant بمنتج مستهدف: نصوغ «مكوّن شراء» واحداً بكمية الحد الأدنى لتعمل صفحة العرض.
        if ($kind === 'same_variant') {
            $svRaw = isset($row['same_variant_product_id']) ? (int) $row['same_variant_product_id'] : 0;
            if ($svRaw > 0) {
                $buyComps = [['product_id' => $svRaw, 'qty' => max(2, (int) ($row['min_buy_qty'] ?? 2))]];
            }
        }
        $giftKind = strtolower(trim((string) ($row['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
        $fixedPid = 0;
        $poolPids = [];
        if ($giftKind === 'fixed') {
            $fixedStored = (int) ($row['fixed_variant_id'] ?? 0);
            $fixedPid = $fixedStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixedStored) : 0;
        } else {
            $poolPids = orange_cart_promo_parse_product_pool($pdo, $row['pool_variant_ids'] ?? null);
        }
        foreach ($buyComps as $c) {
            $allProductIds[] = (int) $c['product_id'];
        }
        if ($fixedPid > 0) {
            $allProductIds[] = $fixedPid;
        }
        foreach ($poolPids as $pp) {
            $allProductIds[] = (int) $pp;
        }
        $prepared[] = [
            'row' => $row,
            'kind' => $kind,
            'buy_comps' => $buyComps,
            'gift_kind' => $giftKind,
            'fixed_pid' => $fixedPid,
            'pool_pids' => $poolPids,
        ];
    }
    $pmap = orange_storefront_offer_product_display_map($pdo, $allProductIds);

    $resolveList = static function (array $pids) use ($pmap): array {
        $out = [];
        foreach ($pids as $pid) {
            $pid = (int) $pid;
            if (isset($pmap[$pid])) {
                $out[] = $pmap[$pid];
            }
        }

        return $out;
    };

    $cards = [];
    foreach ($prepared as $entry) {
        $row = $entry['row'];
        $buyComponents = [];
        foreach ($entry['buy_comps'] as $c) {
            $pid = (int) $c['product_id'];
            if (isset($pmap[$pid])) {
                $buyComponents[] = $pmap[$pid] + ['qty' => (int) $c['qty']];
            }
        }
        $fixedGift = ($entry['fixed_pid'] > 0 && isset($pmap[$entry['fixed_pid']])) ? $pmap[$entry['fixed_pid']] : null;
        $giftPool = $resolveList($entry['pool_pids']);

        // تخطّي القاعدة إن انعدمت بياناتها المعروضة كلياً (منتجات محذوفة/غير نشطة).
        // same_variant بلا منتج مستهدف = عرض تلقائي «أي منتج» بلا صفحة → لا بطاقة.
        if (($entry['kind'] === 'buy_bundle' || $entry['kind'] === 'same_variant') && $buyComponents === []) {
            continue;
        }
        if ($entry['gift_kind'] === 'fixed' && $fixedGift === null && $entry['kind'] !== 'same_category') {
            continue;
        }

        $cards[] = [
            'type' => 'bogo',
            'offer_id' => (int) $row['id'],
            'name' => orange_promo_customer_display_name([
                'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
                'name_ar' => (string) ($row['name_ar'] ?? ''),
                'name_en' => (string) ($row['name_en'] ?? ''),
            ], $lang),
            'show_old_price' => (int) ($row['show_old_price_to_customer'] ?? 0) === 1,
            'bogo_kind' => $entry['kind'],
            'min_buy_qty' => max(2, (int) ($row['min_buy_qty'] ?? 2)),
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'buy_components' => $buyComponents,
            'gift_kind' => $entry['gift_kind'],
            'fixed_gift' => $fixedGift,
            'gift_pool' => $giftPool,
            'gift_unit_charge_kind' => (string) ($row['gift_unit_charge_kind'] ?? 'free'),
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
            'requires_registration' => (int) ($row['requires_registered_account'] ?? 0) === 1,
            'first_delivered_only' => (int) ($row['first_delivered_order_only'] ?? 0) === 1,
        ];
    }

    return $cards;
}

/**
 * بطاقات العروض الموحّدة المرتكزة على منتج (كومبو + BOGO) للواجهة.
 * عروض المنتجات المفردة تبقى في pages/home.php (تُدمج في الشبكة وتغيّر السعر).
 *
 * @return list<array<string,mixed>>
 */
function orange_storefront_offer_cards(PDO $pdo, ?int $countryId, string $lang): array
{
    return array_merge(
        orange_storefront_active_combo_cards($pdo, $countryId, $lang),
        orange_storefront_active_bogo_cards($pdo, $countryId, $lang)
    );
}

/**
 * هل توجد عروض كومبو/BOGO فعّالة (لتقرير إظهار تاب «العروض» عند غياب عروض المنتجات).
 */
function orange_storefront_has_bundle_offers(PDO $pdo, ?int $countryId, string $lang): bool
{
    if (orange_storefront_active_combo_cards($pdo, $countryId, $lang) !== []) {
        return true;
    }

    return orange_storefront_active_bogo_cards($pdo, $countryId, $lang) !== [];
}
