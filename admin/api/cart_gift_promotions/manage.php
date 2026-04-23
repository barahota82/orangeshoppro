<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_gift_promotions.php';
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
        $kindRaw = strtolower(trim((string) ($data['gift_kind'] ?? 'choice')));
        $giftKind = $kindRaw === 'fixed' ? 'fixed' : 'choice';
        $fixedVid = (int) ($data['fixed_variant_id'] ?? 0);
        $poolIds = cgp_parse_pool_input((string) ($data['pool_variant_ids_text'] ?? ''));

        if ($giftKind === 'fixed') {
            if ($fixedVid <= 0) {
                json_response(['success' => false, 'message' => 'أدخل رقم متغير صالح للهدية الثابتة'], 422);
            }
            $poolJson = null;
        } else {
            if (count($poolIds) === 0) {
                json_response(['success' => false, 'message' => 'أدخل قائمة أرقام متغيرات لمجموعة اختيار الهدية'], 422);
            }
            $fixedVid = 0;
            $flags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $poolJson = json_encode(array_values($poolIds), $flags);
            if ($poolJson === false) {
                json_response(['success' => false, 'message' => 'تعذر ترميز قائمة المتغيرات'], 422);
            }
        }

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

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE cart_gift_promotions SET min_subtotal = ?, requires_registered_account = ?, gift_kind = ?, fixed_variant_id = ?, pool_variant_ids = ?, gift_unit_charge_kind = ?, gift_unit_charge_value = ?, sort_order = ?, is_active = ? WHERE id = ?'
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
                $id,
            ]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO cart_gift_promotions (min_subtotal, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            ]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض الهدية']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض الهدية');
}
