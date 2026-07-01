<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/cart_bogo_promotions.php';
require_once __DIR__ . '/../../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../../includes/cart_gift_pool_config.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/promo_always_on.php';
require_admin_api();

/**
 * @return list<int>
 */
/**
 * @return list<array{variant_id:int,qty:int}>
 */
function cbp_parse_buy_components_text(string $raw): array
{
    $merged = [];
    $lines = preg_split('/\R/u', trim($raw));
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(\d+)\s*[,:\s]\s*(\d+)/', $line, $m)) {
            $vid = (int) $m[1];
            $q = (int) $m[2];
        } elseif (preg_match('/^(\d+)$/', $line, $m)) {
            $vid = (int) $m[1];
            $q = 1;
        } else {
            continue;
        }
        if ($vid > 0 && $q > 0) {
            $merged[$vid] = ($merged[$vid] ?? 0) + $q;
        }
    }
    $out = [];
    foreach ($merged as $v => $q) {
        $out[] = ['variant_id' => $v, 'qty' => $q];
    }

    return $out;
}

function cbp_parse_pool_input(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    $ids = [];
    foreach ($parts as $p) {
        $n = (int) preg_replace('/\D+/', '', (string) $p);
        if ($n > 0) {
            $ids[$n] = true;
        }
    }

    return array_keys($ids);
}

/**
 * @param mixed $raw
 *
 * @return list<array{product_id:int,charge_kind:string,charge_value:float}>
 */
function cbp_parse_gift_pool_items_input($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $it) {
        if (!is_array($it)) {
            continue;
        }
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $kind = orange_cart_gift_pool_config_normalize_charge_kind((string) ($it['charge_kind'] ?? 'free'));
        $val = round(max(0.0, (float) ($it['charge_value'] ?? 0)), 4);
        if ($kind === 'free') {
            $val = 0.0;
        }
        if ($kind === 'percent_off' && ($val < 0 || $val > 100)) {
            continue;
        }
        if (($kind === 'fixed_unit' || $kind === 'amount_off_unit') && $val < 0) {
            continue;
        }
        $out[] = [
            'product_id' => $pid,
            'charge_kind' => $kind,
            'charge_value' => $val,
        ];
    }

    return $out;
}

/**
 * @param list<array{product_id:int,charge_kind:string,charge_value:float}> $items
 *
 * @return array{0:string,1:float}
 */
function cbp_legacy_charge_from_pool_items(array $items): array
{
    if ($items === []) {
        return ['free', 0.0];
    }
    $first = $items[0];
    $kind = (string) ($first['charge_kind'] ?? 'free');
    $val = (float) ($first['charge_value'] ?? 0);
    foreach ($items as $it) {
        if ((string) ($it['charge_kind'] ?? '') !== $kind) {
            return ['free', 0.0];
        }
        if (abs((float) ($it['charge_value'] ?? 0) - $val) > 0.0001) {
            return ['free', 0.0];
        }
    }

    return [orange_cart_gift_pool_config_normalize_charge_kind($kind), round(max(0.0, $val), 4)];
}

/**
 * @param list<array{product_id:int,charge_kind:string,charge_value:float}> $items
 */
function cbp_validate_gift_pool_items(array $items): ?string
{
    if ($items === []) {
        return 'أضف تسعيراً لكل منتج في الهدية';
    }
    foreach ($items as $it) {
        $kind = (string) ($it['charge_kind'] ?? 'free');
        $val = (float) ($it['charge_value'] ?? 0);
        if ($kind === 'percent_off' && ($val < 0 || $val > 100)) {
            return 'نسبة الخصم على أحد منتجات الهدية يجب أن تكون بين 0 و 100';
        }
        if (($kind === 'fixed_unit' || $kind === 'amount_off_unit') && $val < 0) {
            return 'قيمة التسعير على أحد منتجات الهدية لا يمكن أن تكون سالبة';
        }
    }

    return null;
}

/**
 * @return list<array{product_id:int,charge_kind:string,charge_value:float}>
 */
function cbp_build_gift_pool_items_from_save(
    array $data,
    string $giftKind,
    int $fixedPid,
    array $productIds,
    string $fallbackKind,
    float $fallbackVal
): array {
    $parsed = cbp_parse_gift_pool_items_input($data['gift_pool_items'] ?? null);
    $byPid = [];
    foreach ($parsed as $it) {
        $byPid[(int) $it['product_id']] = $it;
    }

    $targetIds = $giftKind === 'fixed'
        ? ($fixedPid > 0 ? [$fixedPid] : [])
        : $productIds;

    $items = [];
    foreach ($targetIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        if (isset($byPid[$pid])) {
            $items[] = $byPid[$pid];
            continue;
        }
        $items[] = [
            'product_id' => $pid,
            'charge_kind' => orange_cart_gift_pool_config_normalize_charge_kind($fallbackKind),
            'charge_value' => $fallbackKind === 'free' ? 0.0 : round(max(0.0, $fallbackVal), 4),
        ];
    }

    return $items;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'cart_bogo_promotions')) {
        json_response(['success' => false, 'message' => 'جدول cart_bogo_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_cart_bogo_promotions_admin_list($pdo)]);
    }

    if ($action === 'always_on_history') {
        $countryId = orange_cart_promotion_admin_country_id($pdo);
        json_response([
            'success' => true,
            'data' => orange_promo_always_on_history_list($pdo, 'cart_bogo_promotions', $countryId),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = function_exists('mb_substr')
            ? mb_substr(trim((string) ($data['name_ar'] ?? '')), 0, 191, 'UTF-8')
            : substr(trim((string) ($data['name_ar'] ?? '')), 0, 191);
        $nameEn = function_exists('mb_substr')
            ? mb_substr(trim((string) ($data['name_en'] ?? '')), 0, 191, 'UTF-8')
            : substr(trim((string) ($data['name_en'] ?? '')), 0, 191);
        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم العرض بالعربي مطلوب'], 422);
        }
        $bogoRaw = strtolower(trim((string) ($data['bogo_kind'] ?? 'same_variant')));
        if ($bogoRaw === 'same_category') {
            $bogoKind = 'same_category';
        } elseif ($bogoRaw === 'buy_bundle') {
            $bogoKind = 'buy_bundle';
        } else {
            $bogoKind = 'same_variant';
        }
        $catId = (int) ($data['category_id'] ?? 0);
        $minBuy = (int) ($data['min_buy_qty'] ?? 2);
        if ($minBuy < 2) {
            $minBuy = 2;
        }
        if (isset($data['buy_components']) && is_array($data['buy_components'])) {
            $buyComps = orange_cart_promo_parse_components($pdo, $data['buy_components']);
        } else {
            $buyComps = orange_cart_promo_parse_components_text($pdo, (string) ($data['buy_components_text'] ?? ''));
        }
        $buyJson = null;
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $firstDeliveredOnly = !empty($data['first_delivered_order_only']) ? 1 : 0;
        $showNameToCustomer = !empty($data['show_name_to_customer']) ? 1 : 0;
        $showOldPrice = !empty($data['show_old_price_to_customer']) ? 1 : 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isAlwaysOn = !empty($data['is_always_on']) ? 1 : 0;
        $dateErr = null;
        $bounds = orange_cart_promo_parse_required_admin_dates(
            trim((string) ($data['valid_from'] ?? '')),
            trim((string) ($data['valid_to'] ?? '')),
            $dateErr,
            $isAlwaysOn === 1
        );
        if ($bounds === null) {
            json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
        }
        $giftRaw = strtolower(trim((string) ($data['gift_kind'] ?? 'choice')));
        $giftKind = $giftRaw === 'fixed' ? 'fixed' : 'choice';
        // اختيار العميل لمتغيّر الهدية: ذو معنى لهدية ثابتة فقط (نوع «الاختيار» يختار العميل أصلاً).
        $customerPicksVariant = ($giftKind === 'fixed' && !empty($data['gift_customer_picks_variant'])) ? 1 : 0;
        $fixedPid = (int) ($data['fixed_product_id'] ?? $data['fixed_variant_id'] ?? 0);
        if (isset($data['pool_product_ids']) && is_array($data['pool_product_ids'])) {
            $poolIds = array_values(array_unique(array_map('intval', $data['pool_product_ids'])));
        } else {
            $poolIds = orange_cart_promo_parse_product_pool_text($pdo, (string) ($data['pool_variant_ids_text'] ?? ''));
        }

        if ($bogoKind === 'same_category' && $catId <= 0) {
            json_response(['success' => false, 'message' => 'أدخل رقم فئة صالح لنوع «قطعتان من نفس الفئة»'], 422);
        }
        if ($bogoKind === 'same_category' && $catId > 0) {
            if (!orange_catalog_nav_use_unified($pdo) || !orange_table_exists($pdo, 'catalog_categories')) {
                json_response([
                    'success' => false,
                    'message' => 'عروض «نفس الفئة» تتطلّب تفعيل الشجرة الموحّدة والتحقق من معرف فئة الكتالوج (catalog_categories) من «فروع شجرة المنتجات».',
                ], 422);
            }
            if (orange_catalog_validate_unified_catalog_category_id($pdo, $catId) <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'معرف الفئة غير موجود أو غير نشط في الشجرة الموحّدة (catalog_categories). استخدم رقماً من «فروع شجرة المنتجات».',
                ], 422);
            }
        }
        if ($bogoKind === 'buy_bundle') {
            if (count($buyComps) < 2) {
                json_response(['success' => false, 'message' => 'لحزمة الشراء: أضف منتجين مختلفين على الأقل.'], 422);
            }
            $uniqBuy = [];
            foreach ($buyComps as $bc) {
                $uniqBuy[(int) $bc['product_id']] = true;
            }
            if (count($uniqBuy) < 2) {
                json_response(['success' => false, 'message' => 'حزمة الشراء تتطلّب منتجين مختلفين على الأقل.'], 422);
            }
            $buyJson = orange_cart_promo_encode_components_json($buyComps);
            $catId = 0;
        } else {
            $buyJson = null;
        }
        $svPid = 0;
        if ($bogoKind === 'same_variant') {
            $catId = 0;
            $svPid = (int) ($data['same_variant_product_id'] ?? 0);
            if ($svPid < 0) {
                $svPid = 0;
            }
        }

        if ($giftKind === 'fixed') {
            if ($fixedPid <= 0) {
                json_response(['success' => false, 'message' => 'اختر منتجاً للهدية الثابتة'], 422);
            }
            $poolJson = null;
            $maxGiftsPickable = 1;
        } else {
            if (count($poolIds) === 0) {
                json_response(['success' => false, 'message' => 'أضف منتجاً واحداً على الأقل لمجموعة اختيار الهدية'], 422);
            }
            $fixedPid = 0;
            $poolJson = orange_cart_promo_encode_product_pool_json($poolIds);
            $maxGiftsPickable = max(1, (int) ($data['max_gifts_pickable'] ?? 1));
            if ($maxGiftsPickable > count($poolIds)) {
                json_response([
                    'success' => false,
                    'message' => 'عدد الهدايا القابلة للاختيار لا يمكن أن يتجاوز عدد المنتجات في المجموعة (' . count($poolIds) . ')',
                ], 422);
            }
        }

        $productIds = [];
        if ($bogoKind === 'buy_bundle') {
            foreach ($buyComps as $bc) {
                $productIds[] = (int) $bc['product_id'];
            }
        }
        if ($bogoKind === 'same_variant' && $svPid > 0) {
            $productIds[] = $svPid;
        }
        if ($giftKind === 'fixed' && $fixedPid > 0) {
            $productIds[] = $fixedPid;
        } elseif ($giftKind === 'choice') {
            foreach ($poolIds as $poolPid) {
                $productIds[] = (int) $poolPid;
            }
        }
        $prodErr = orange_cart_promo_validate_product_ids($pdo, $productIds);
        if ($prodErr !== null) {
            json_response(['success' => false, 'message' => $prodErr], 422);
        }
        $fixedVid = $fixedPid;

        $gcRaw = strtolower(trim((string) ($data['gift_unit_charge_kind'] ?? 'free')));
        $allowedGc = ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'];
        $fallbackKind = in_array($gcRaw, $allowedGc, true) ? $gcRaw : 'free';
        $fallbackVal = (float) ($data['gift_unit_charge_value'] ?? 0);

        $poolItems = cbp_build_gift_pool_items_from_save(
            $data,
            $giftKind,
            $fixedPid,
            $poolIds,
            $fallbackKind,
            $fallbackVal
        );
        $poolItemsErr = cbp_validate_gift_pool_items($poolItems);
        if ($poolItemsErr !== null) {
            json_response(['success' => false, 'message' => $poolItemsErr], 422);
        }
        $giftPoolConfigJson = orange_cart_gift_pool_config_encode($poolItems);
        [$giftChargeKind, $giftChargeVal] = cbp_legacy_charge_from_pool_items($poolItems);

        $catSql = $bogoKind === 'same_category' && $catId > 0 ? $catId : null;
        $svSql = $bogoKind === 'same_variant' && $svPid > 0 ? $svPid : null;

        try {
            $insertCountryId = orange_cart_promotion_prepare_admin_save($pdo, 'cart_bogo_promotions', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }

        $hasPoolCfg = orange_table_has_column($pdo, 'cart_bogo_promotions', 'gift_pool_config');
        $hasMaxPick = orange_table_has_column($pdo, 'cart_bogo_promotions', 'max_gifts_pickable');

        if ($id > 0) {
            orange_cart_promo_clear_auto_pause($pdo, 'cart_bogo_promotions', $id);
            $sets = [
                'name_ar = ?',
                'name_en = ?',
                'show_name_to_customer = ?',
                'show_old_price_to_customer = ?',
                'bogo_kind = ?',
                'category_id = ?',
                'same_variant_product_id = ?',
                'min_buy_qty = ?',
                'buy_components_json = ?',
                'requires_registered_account = ?',
                'first_delivered_order_only = ?',
                'gift_kind = ?',
                'gift_customer_picks_variant = ?',
                'fixed_variant_id = ?',
                'pool_variant_ids = ?',
                'gift_unit_charge_kind = ?',
                'gift_unit_charge_value = ?',
                'is_active = ?',
                'is_always_on = ?',
                'valid_from = ?',
                'valid_to = ?',
                'auto_paused_at = NULL',
                'auto_paused_reason = NULL',
            ];
            $params = [
                $nameAr,
                $nameEn,
                $showNameToCustomer,
                $showOldPrice,
                $bogoKind,
                $catSql,
                $svSql,
                $minBuy,
                $buyJson,
                $reqReg,
                $firstDeliveredOnly,
                $giftKind,
                $customerPicksVariant,
                $giftKind === 'fixed' ? $fixedVid : null,
                $giftKind === 'choice' ? $poolJson : null,
                $giftChargeKind,
                $giftChargeVal,
                $isActive,
                $isAlwaysOn,
                $bounds['valid_from'],
                $bounds['valid_to'],
            ];
            if ($hasPoolCfg) {
                $sets[] = 'gift_pool_config = ?';
                $params[] = $giftPoolConfigJson;
            }
            if ($hasMaxPick) {
                $sets[] = 'max_gifts_pickable = ?';
                $params[] = $maxGiftsPickable;
            }
            $params[] = $id;
            $st = $pdo->prepare(
                'UPDATE cart_bogo_promotions SET ' . implode(', ', $sets) . ' WHERE id = ?'
            );
            $st->execute($params);
            orange_promo_always_on_sync_history(
                $pdo,
                'cart_bogo_promotions',
                $id,
                $isAlwaysOn,
                orange_cart_promotion_admin_country_id($pdo)
            );
        } else {
            $sortBind = orange_cart_promotion_sql_bind($pdo, 'cart_bogo_promotions', '', $insertCountryId);
            $stSort = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cart_bogo_promotions WHERE 1=1' . $sortBind['sql']
            );
            $stSort->execute($sortBind['params']);
            $sortOrder = (int) ($stSort->fetchColumn() ?: 1);
            $cols = [
                'country_id', 'name_ar', 'name_en', 'show_name_to_customer', 'show_old_price_to_customer',
                'bogo_kind', 'category_id', 'same_variant_product_id', 'min_buy_qty', 'buy_components_json',
                'requires_registered_account', 'first_delivered_order_only', 'gift_kind', 'gift_customer_picks_variant',
                'fixed_variant_id', 'pool_variant_ids', 'gift_unit_charge_kind', 'gift_unit_charge_value',
                'sort_order', 'is_active', 'is_always_on', 'valid_from', 'valid_to',
            ];
            $params = [
                $insertCountryId,
                $nameAr,
                $nameEn,
                $showNameToCustomer,
                $showOldPrice,
                $bogoKind,
                $catSql,
                $svSql,
                $minBuy,
                $buyJson,
                $reqReg,
                $firstDeliveredOnly,
                $giftKind,
                $customerPicksVariant,
                $giftKind === 'fixed' ? $fixedVid : null,
                $giftKind === 'choice' ? $poolJson : null,
                $giftChargeKind,
                $giftChargeVal,
                $sortOrder,
                $isActive,
                $isAlwaysOn,
                $bounds['valid_from'],
                $bounds['valid_to'],
            ];
            if ($hasPoolCfg) {
                $cols[] = 'gift_pool_config';
                $params[] = $giftPoolConfigJson;
            }
            if ($hasMaxPick) {
                $cols[] = 'max_gifts_pickable';
                $params[] = $maxGiftsPickable;
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $st = $pdo->prepare(
                'INSERT INTO cart_bogo_promotions (' . implode(', ', $cols) . ') VALUES (' . $ph . ')'
            );
            $st->execute($params);
            $newId = (int) $pdo->lastInsertId();
            orange_promo_always_on_sync_history(
                $pdo,
                'cart_bogo_promotions',
                $newId,
                $isAlwaysOn,
                $insertCountryId > 0 ? $insertCountryId : orange_cart_promotion_admin_country_id($pdo)
            );
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض BOGO']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض BOGO');
}
