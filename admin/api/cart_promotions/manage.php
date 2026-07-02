<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_promotions.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/promo_always_on.php';
require_admin_api();

/**
 * @param mixed $v
 */
function cp_money($v): float
{
    $f = (float) $v;

    return $f >= 0 ? round($f, 4) : 0.0;
}

/**
 * @param mixed $v
 */
function cp_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'cart_promotions')) {
        json_response(['success' => false, 'message' => 'جدول cart_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_cart_promotions_admin_list($pdo)]);
    }

    if ($action === 'always_on_history') {
        $countryId = orange_cart_promotion_admin_country_id($pdo);
        json_response([
            'success' => true,
            'data' => orange_promo_always_on_history_list($pdo, 'cart_promotions', $countryId),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = cp_str191($data['name_ar'] ?? '');
        $nameEn = cp_str191($data['name_en'] ?? '');
        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم العرض بالعربي مطلوب'], 422);
        }
        $minSub = cp_money($data['min_subtotal'] ?? 0);
        $disc = cp_money($data['discount_amount'] ?? 0);
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

        if ($minSub <= 0) {
            json_response(['success' => false, 'message' => 'الحد الأدنى للمجموع يجب أن يكون أكبر من صفر'], 422);
        }
        if ($disc <= 0) {
            json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
        }
        if ($disc > $minSub + 0.00001) {
            json_response(['success' => false, 'message' => 'الخصم لا يجب أن يتجاوز الحد الأدنى للمجموع'], 422);
        }

        try {
            $insertCountryId = orange_cart_promotion_prepare_admin_save($pdo, 'cart_promotions', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                orange_cart_promo_clear_auto_pause($pdo, 'cart_promotions', $id);
                // الترتيب تلقائي بالكامل: لا يُمَسّ عند التعديل (مثل عروض التوصيل).
                $st = $pdo->prepare(
                    'UPDATE cart_promotions SET name_ar = ?, name_en = ?, show_name_to_customer = ?, min_subtotal = ?, discount_amount = ?, requires_registered_account = ?, first_delivered_order_only = ?, is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?, auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = ?'
                );
                $st->execute([
                    $nameAr,
                    $nameEn,
                    $showNameToCustomer,
                    $minSub,
                    $disc,
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
                    'cart_promotions',
                    $id,
                    $isAlwaysOn,
                    orange_cart_promotion_admin_country_id($pdo)
                );
            } else {
                // الترتيب تلقائي: التالي ضمن نفس الدولة (يتجاهل أي إدخال يدوي).
                $sortBind = orange_cart_promotion_sql_bind($pdo, 'cart_promotions', '', $insertCountryId);
                $stSort = $pdo->prepare(
                    'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM cart_promotions WHERE 1=1' . $sortBind['sql']
                );
                $stSort->execute($sortBind['params']);
                $sortOrder = (int) ($stSort->fetchColumn() ?: 1);
                $st = $pdo->prepare(
                    'INSERT INTO cart_promotions (country_id, name_ar, name_en, show_name_to_customer, min_subtotal, discount_amount, requires_registered_account, first_delivered_order_only, sort_order, is_active, is_always_on, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $insertCountryId,
                    $nameAr,
                    $nameEn,
                    $showNameToCustomer,
                    $minSub,
                    $disc,
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
                    'cart_promotions',
                    $newId,
                    $isAlwaysOn,
                    $insertCountryId > 0 ? $insertCountryId : orange_cart_promotion_admin_country_id($pdo)
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض السلة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض السلة');
}
