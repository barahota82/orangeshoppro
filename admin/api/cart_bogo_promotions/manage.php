<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../../includes/cart_bogo_promotions.php';
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

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
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
        $buyComps = cbp_parse_buy_components_text((string) ($data['buy_components_text'] ?? ''));
        $buyJson = null;
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $giftRaw = strtolower(trim((string) ($data['gift_kind'] ?? 'choice')));
        $giftKind = $giftRaw === 'fixed' ? 'fixed' : 'choice';
        $fixedVid = (int) ($data['fixed_variant_id'] ?? 0);
        $poolIds = cbp_parse_pool_input((string) ($data['pool_variant_ids_text'] ?? ''));

        if ($bogoKind === 'same_category' && $catId <= 0) {
            json_response(['success' => false, 'message' => 'أدخل رقم فئة صالح لنوع «قطعتان من نفس الفئة»'], 422);
        }
        if ($bogoKind === 'buy_bundle') {
            if (count($buyComps) < 2) {
                json_response(['success' => false, 'message' => 'لحزمة الشراء: أدخل سطرين على الأقل (متغير + كمية).'], 422);
            }
            $uniqBuy = [];
            foreach ($buyComps as $bc) {
                $uniqBuy[(int) $bc['variant_id']] = true;
            }
            if (count($uniqBuy) < 2) {
                json_response(['success' => false, 'message' => 'حزمة الشراء تتطلّب متغيرين مختلفين على الأقل (اشترِ أ واحصل على ب).'], 422);
            }
            $flagsB = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flagsB |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $buyJson = json_encode(array_values($buyComps), $flagsB);
            if ($buyJson === false) {
                json_response(['success' => false, 'message' => 'تعذر ترميز مكوّنات الشراء'], 422);
            }
            $catId = 0;
        } else {
            $buyJson = null;
        }
        if ($bogoKind === 'same_variant') {
            $catId = 0;
        }

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
            json_response(['success' => false, 'message' => 'نسبة الخصم على هدية BOGO يجب أن تكون بين 0 و 100'], 422);
        }
        if (($giftChargeKind === 'fixed_unit' || $giftChargeKind === 'amount_off_unit') && $giftChargeVal < 0) {
            json_response(['success' => false, 'message' => 'قيمة التسعير الجزئي لهدية BOGO لا يمكن أن تكون سالبة'], 422);
        }

        $catSql = $bogoKind === 'same_category' && $catId > 0 ? $catId : null;

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE cart_bogo_promotions SET bogo_kind = ?, category_id = ?, min_buy_qty = ?, buy_components_json = ?, requires_registered_account = ?, gift_kind = ?, fixed_variant_id = ?, pool_variant_ids = ?, gift_unit_charge_kind = ?, gift_unit_charge_value = ?, sort_order = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([
                $bogoKind,
                $catSql,
                $minBuy,
                $buyJson,
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
                'INSERT INTO cart_bogo_promotions (bogo_kind, category_id, min_buy_qty, buy_components_json, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $bogoKind,
                $catSql,
                $minBuy,
                $buyJson,
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

        json_response(['success' => true, 'message' => 'تم حفظ عرض BOGO']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض BOGO');
}
