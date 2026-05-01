<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/cart_combo_promotions.php';
require_admin_api();

/**
 * @return list<array{variant_id:int,qty:int}>
 */
function ccp_parse_components_text(string $raw): array
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

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $titleAr = trim((string) ($data['title_ar'] ?? ''));
        $titleEn = trim((string) ($data['title_en'] ?? ''));
        $comboPrice = ccp_money($data['combo_price'] ?? 0);
        $reqReg = !empty($data['requires_registered_account']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $comps = ccp_parse_components_text((string) ($data['components_text'] ?? ''));

        if (count($comps) < 2) {
            json_response(['success' => false, 'message' => 'أدخل سطرين على الأقل: رقم متغير وكمية (متغيران مختلفان على الأقل).'], 422);
        }
        $uniqV = [];
        foreach ($comps as $c) {
            $uniqV[(int) $c['variant_id']] = true;
        }
        if (count($uniqV) < 2) {
            json_response(['success' => false, 'message' => 'الكومبو يتطلّب متغيرين مختلفين على الأقل.'], 422);
        }
        if ($comboPrice <= 0) {
            json_response(['success' => false, 'message' => 'أدخل سعر الكومبو (أكبر من صفر).'], 422);
        }

        $vidsForChain = [];
        foreach ($comps as $c) {
            $vidsForChain[] = (int) $c['variant_id'];
        }
        $chainErr = orange_admin_validate_variants_storefront_chain($pdo, $vidsForChain);
        if ($chainErr !== null) {
            json_response(['success' => false, 'message' => $chainErr], 422);
        }

        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode(array_values($comps), $flags);
        if ($json === false) {
            json_response(['success' => false, 'message' => 'تعذر ترميز مكوّنات الكومبو'], 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE cart_combo_promotions SET title_ar = ?, title_en = ?, components_json = ?, combo_price = ?, requires_registered_account = ?, sort_order = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([$titleAr, $titleEn, $json, $comboPrice, $reqReg, $sortOrder, $isActive, $id]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO cart_combo_promotions (title_ar, title_en, components_json, combo_price, requires_registered_account, sort_order, is_active) VALUES (?,?,?,?,?,?,?)'
            );
            $st->execute([$titleAr, $titleEn, $json, $comboPrice, $reqReg, $sortOrder, $isActive]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ عرض الكومبو']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عرض الكومبو');
}
