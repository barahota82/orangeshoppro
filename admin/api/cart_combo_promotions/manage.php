<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/cart_combo_promotions.php';
require_once __DIR__ . '/../../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/promo_always_on.php';
require_admin_api();

/**
 * @param mixed $raw
 *
 * @return list<array{product_id:int,qty:int}>
 */
function ccp_parse_components_save(PDO $pdo, array $data): array
{
    if (isset($data['components']) && is_array($data['components'])) {
        return orange_cart_promo_parse_components($pdo, $data['components']);
    }

    return orange_cart_promo_parse_components_text($pdo, (string) ($data['components_text'] ?? ''));
}

/**
 * @param mixed $v
 */
function ccp_money($v): float
{
    $f = (float) $v;

    return $f >= 0 ? round($f, 4) : 0.0;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        json_response(['success' => false, 'message' => 'جدول cart_combo_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_cart_combo_promotions_admin_list($pdo)]);
    }

    if ($action === 'always_on_history') {
        $countryId = orange_cart_promotion_admin_country_id($pdo);
        json_response([
            'success' => true,
            'data' => orange_promo_always_on_history_list($pdo, 'cart_combo_promotions', $countryId),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $titleAr = function_exists('mb_substr')
            ? mb_substr(trim((string) ($data['title_ar'] ?? '')), 0, 191, 'UTF-8')
            : substr(trim((string) ($data['title_ar'] ?? '')), 0, 191);
        $titleEn = function_exists('mb_substr')
            ? mb_substr(trim((string) ($data['title_en'] ?? '')), 0, 191, 'UTF-8')
            : substr(trim((string) ($data['title_en'] ?? '')), 0, 191);
        if ($titleAr === '') {
            json_response(['success' => false, 'message' => 'اسم العرض بالعربي مطلوب'], 422);
        }
        $comboPrice = ccp_money($data['combo_price'] ?? 0);
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $firstDeliveredOnly = !empty($data['first_delivered_order_only']) ? 1 : 0;
        $showNameToCustomer = !empty($data['show_name_to_customer']) ? 1 : 0;
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
        $comps = ccp_parse_components_save($pdo, $data);

        if (count($comps) < 2) {
            json_response(['success' => false, 'message' => 'أضف منتجين مختلفين على الأقل (كل منتج = أي لون أو مقاس).'], 422);
        }
        $uniqP = [];
        foreach ($comps as $c) {
            $uniqP[(int) $c['product_id']] = true;
        }
        if (count($uniqP) < 2) {
            json_response(['success' => false, 'message' => 'الكومبو يتطلّب منتجين مختلفين على الأقل.'], 422);
        }
        if ($comboPrice <= 0) {
            json_response(['success' => false, 'message' => 'أدخل سعر الكومبو (أكبر من صفر).'], 422);
        }

        $pids = array_map(static fn (array $c): int => (int) $c['product_id'], $comps);
        $chainErr = orange_cart_promo_validate_product_ids($pdo, $pids);
        if ($chainErr !== null) {
            json_response(['success' => false, 'message' => $chainErr], 422);
        }

        $json = orange_cart_promo_encode_components_json($comps);

        try {
            $insertCountryId = orange_cart_promotion_prepare_admin_save($pdo, 'cart_combo_promotions', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }

        if ($id > 0) {
            orange_cart_promo_clear_auto_pause($pdo, 'cart_combo_promotions', $id);
            // الترتيب تلقائي بالكامل: لا يُمَسّ عند التعديل.
            $st = $pdo->prepare(
                'UPDATE cart_combo_promotions SET title_ar = ?, title_en = ?, show_name_to_customer = ?, components_json = ?, combo_price = ?, requires_registered_account = ?, first_delivered_order_only = ?, is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?, auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = ?'
            );
            $st->execute([
                $titleAr,
                $titleEn,
                $showNameToCustomer,
                $json,
                $comboPrice,
                $reqReg,
                $firstDeliveredOnly,
                $isActive,
                $isAlwaysOn,
                $bounds['valid_from'],
                $bounds['valid_to'],
                $id,
            ]);
            orange_promo_always_on_sync_history(
                $pdo,
                'cart_combo_promotions',
                $id,
                $isAlwaysOn,
                orange_cart_promotion_admin_country_id($pdo)
            );
        } else {
            // الترتيب تلقائي: التالي ضمن نفس الدولة.
            $sortBind = orange_cart_promotion_sql_bind($pdo, 'cart_combo_promotions', '', $insertCountryId);
            $stSort = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cart_combo_promotions WHERE 1=1' . $sortBind['sql']
            );
            $stSort->execute($sortBind['params']);
            $sortOrder = (int) ($stSort->fetchColumn() ?: 1);
            $st = $pdo->prepare(
                'INSERT INTO cart_combo_promotions (country_id, title_ar, title_en, show_name_to_customer, components_json, combo_price, requires_registered_account, first_delivered_order_only, sort_order, is_active, is_always_on, valid_from, valid_to) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->execute([
                $insertCountryId,
                $titleAr,
                $titleEn,
                $showNameToCustomer,
                $json,
                $comboPrice,
                $reqReg,
                $firstDeliveredOnly,
                $sortOrder,
                $isActive,
                $isAlwaysOn,
                $bounds['valid_from'],
                $bounds['valid_to'],
            ]);
            $newId = (int) $pdo->lastInsertId();
            orange_promo_always_on_sync_history(
                $pdo,
                'cart_combo_promotions',
                $newId,
                $isAlwaysOn,
                $insertCountryId > 0 ? $insertCountryId : orange_cart_promotion_admin_country_id($pdo)
            );
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض الكومبو']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض الكومبو');
}
