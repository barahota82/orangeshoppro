<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../../includes/cart_promo_products.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_admin_api();

/**
 * @return list<int>
 */
function cgp_parse_pool_input(string $raw): array
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
 * @param mixed $v
 */
function cgp_money($v): float
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

    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        json_response(['success' => false, 'message' => 'جدول cart_gift_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_cart_gift_promotions_admin_list($pdo)]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $minSub = cgp_money($data['min_subtotal'] ?? 0);
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $dateErr = null;
        $bounds = orange_cart_promo_parse_required_admin_dates(
            trim((string) ($data['valid_from'] ?? '')),
            trim((string) ($data['valid_to'] ?? '')),
            $dateErr
        );
        if ($bounds === null) {
            json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
        }
        $kindRaw = strtolower(trim((string) ($data['gift_kind'] ?? 'choice')));
        $giftKind = $kindRaw === 'fixed' ? 'fixed' : 'choice';
        $fixedPid = (int) ($data['fixed_product_id'] ?? $data['fixed_variant_id'] ?? 0);
        if (isset($data['pool_product_ids']) && is_array($data['pool_product_ids'])) {
            $poolIds = array_values(array_unique(array_map('intval', $data['pool_product_ids'])));
        } else {
            $poolIds = orange_cart_promo_parse_product_pool_text($pdo, (string) ($data['pool_variant_ids_text'] ?? ''));
        }

        if ($giftKind === 'fixed') {
            if ($fixedPid <= 0) {
                json_response(['success' => false, 'message' => 'اختر منتجاً للهدية الثابتة'], 422);
            }
            $poolJson = null;
        } else {
            if (count($poolIds) === 0) {
                json_response(['success' => false, 'message' => 'أضف منتجاً واحداً على الأقل لمجموعة اختيار الهدية'], 422);
            }
            $fixedPid = 0;
            $poolJson = orange_cart_promo_encode_product_pool_json($poolIds);
        }
        $prodErr = orange_cart_promo_validate_product_ids(
            $pdo,
            $giftKind === 'fixed' ? [$fixedPid] : $poolIds
        );
        if ($prodErr !== null) {
            json_response(['success' => false, 'message' => $prodErr], 422);
        }
        $fixedVid = $fixedPid;

        $gcRaw = strtolower(trim((string) ($data['gift_unit_charge_kind'] ?? 'free')));
        $allowedGc = ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'];
        $giftChargeKind = in_array($gcRaw, $allowedGc, true) ? $gcRaw : 'free';
        $giftChargeVal = (float) ($data['gift_unit_charge_value'] ?? 0);
        if ($giftChargeKind === 'free') {
            $giftChargeVal = 0.0;
        }
        if ($giftChargeKind === 'percent_off' && ($giftChargeVal < 0 || $giftChargeVal > 100)) {
            json_response(['success' => false, 'message' => 'نسبة الخصم على هدية المجموع يجب أن تكون بين 0 و 100'], 422);
        }
        if (($giftChargeKind === 'fixed_unit' || $giftChargeKind === 'amount_off_unit') && $giftChargeVal < 0) {
            json_response(['success' => false, 'message' => 'قيمة التسعير الجزئي للهدية لا يمكن أن تكون سالبة'], 422);
        }

        try {
            $insertCountryId = orange_cart_promotion_prepare_admin_save($pdo, 'cart_gift_promotions', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }

        if ($id > 0) {
            orange_cart_promo_clear_auto_pause($pdo, 'cart_gift_promotions', $id);
            $st = $pdo->prepare(
                'UPDATE cart_gift_promotions SET min_subtotal = ?, requires_registered_account = ?, gift_kind = ?, fixed_variant_id = ?, pool_variant_ids = ?, gift_unit_charge_kind = ?, gift_unit_charge_value = ?, sort_order = ?, is_active = ?, valid_from = ?, valid_to = ?, auto_paused_at = NULL, auto_paused_reason = NULL WHERE id = ?'
            );
            $st->execute([
                $minSub,
                $reqReg,
                $giftKind,
                $giftKind === 'fixed' ? $fixedVid : null,
                $giftKind === 'choice' ? $poolJson : null,
                $giftChargeKind,
                $giftChargeVal,
                $sortOrder,
                $isActive,
                $bounds['valid_from'],
                $bounds['valid_to'],
                $id,
            ]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO cart_gift_promotions (country_id, min_subtotal, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $insertCountryId,
                $minSub,
                $reqReg,
                $giftKind,
                $giftKind === 'fixed' ? $fixedVid : null,
                $giftKind === 'choice' ? $poolJson : null,
                $giftChargeKind,
                $giftChargeVal,
                $sortOrder,
                $isActive,
                $bounds['valid_from'],
                $bounds['valid_to'],
            ]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض الهدية']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض الهدية');
}
