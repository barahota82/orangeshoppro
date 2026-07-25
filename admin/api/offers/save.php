<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/promo_always_on.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $offersMoneyDecimals = (int) ((orange_admin_currency_context($pdo)['decimals'] ?? 3));

    if (empty($data['product_id']) || !isset($data['discount'])) {
        json_response(['success' => false, 'message' => 'بيانات العرض مطلوبة'], 422);
    }

    $pid = (int) $data['product_id'];
    $isAlwaysOn = !empty($data['is_always_on']) ? 1 : 0;
    $offerCountryId = 0;
    if (orange_table_has_column($pdo, 'products', 'country_id')) {
        $cst = $pdo->prepare('SELECT country_id FROM products WHERE id = ? LIMIT 1');
        $cst->execute([$pid]);
        $offerCountryId = (int) ($cst->fetchColumn() ?: 0);
    }
    if ($offerCountryId <= 0) {
        json_response([
            'success' => false,
            'code' => 'offer_country_required',
            'message' => 'دولة المنتج مطلوبة لتفسير تواريخ العرض (لا يُستخدم سياق الدولة كبديل صامت)',
        ], 422);
    }
    $dateErr = null;
    $bounds = orange_cart_promo_parse_required_admin_dates(
        trim((string) ($data['valid_from'] ?? '')),
        trim((string) ($data['valid_to'] ?? '')),
        $dateErr,
        $isAlwaysOn === 1,
        $pdo,
        $offerCountryId,
        false
    );
    if ($bounds === null) {
        json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
    }

    $discount = round((float) $data['discount'], $offersMoneyDecimals);
    if ($discount <= 0) {
        json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
    }

    // نوع الخصم: مبلغ ثابت أو نسبة % (عمود offers.discount_type — مع توافق رجعي إن غاب).
    $hasTypeCol = orange_table_has_column($pdo, 'offers', 'discount_type');
    $discountType = ($hasTypeCol && strtolower(trim((string) ($data['discount_type'] ?? 'amount'))) === 'percent')
        ? 'percent'
        : 'amount';
    if ($discountType === 'percent' && $discount > 100) {
        json_response(['success' => false, 'message' => 'نسبة الخصم لا تتجاوز 100%'], 422);
    }

    // حارس التكلفة لكل متغيّر (قرار مالك 2026-06-27، وُسِّع للمتغيّرات 2026-06-29):
    // لا يصحّ أن ينزل سعر أي متغيّر بعد الخصم تحت تكلفته الفعّالة، ولا أن يكون صفراً/سالباً.
    $hasPriceCol = orange_table_has_column($pdo, 'products', 'price');
    $hasCostCol = orange_table_has_column($pdo, 'products', 'cost');
    $hasVarPrice = orange_table_has_column($pdo, 'product_variants', 'price');
    $hasVarCost = orange_table_has_column($pdo, 'product_variants', 'cost');
    $ch = $pdo->prepare(
        'SELECT id, '
        . ($hasPriceCol ? 'price' : '0 AS price') . ', '
        . ($hasCostCol ? 'cost' : '0 AS cost')
        . ' FROM products WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $ch->execute([$pid]);
    $prodRow = $ch->fetch(PDO::FETCH_ASSOC);
    if (!$prodRow) {
        json_response(['success' => false, 'message' => 'المنتج غير موجود أو غير نشط'], 422);
    }
    if ($hasPriceCol) {
        // أزواج (سعر، تكلفة) فعّالة: متغيّرات إن وُجدت (COALESCE(متغيّر، منتج))، وإلا مستوى المنتج.
        $pairs = [];
        if ($hasVarPrice) {
            $vSql = 'SELECT COALESCE(pv.price, p.price) AS eff_price, '
                . ($hasVarCost ? 'COALESCE(pv.cost, p.cost)' : 'p.cost') . ' AS eff_cost
                FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id
                WHERE pv.product_id = ?';
            $vst = $pdo->prepare($vSql);
            $vst->execute([$pid]);
            while ($vr = $vst->fetch(PDO::FETCH_ASSOC)) {
                $pairs[] = [(float) $vr['eff_price'], $hasCostCol ? (float) $vr['eff_cost'] : 0.0];
            }
        }
        if ($pairs === []) {
            $pairs[] = [(float) ($prodRow['price'] ?? 0), $hasCostCol ? (float) ($prodRow['cost'] ?? 0) : 0.0];
        }
        foreach ($pairs as $pair) {
            $pp = round((float) $pair[0], $offersMoneyDecimals);
            $pc = round((float) $pair[1], $offersMoneyDecimals);
            $unitDisc = $discountType === 'percent'
                ? round($pp * min(100.0, $discount) / 100.0, $offersMoneyDecimals)
                : min($discount, $pp);
            $after = round($pp - $unitDisc, $offersMoneyDecimals);
            if ($after <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'الخصم يجعل سعر أحد المتغيّرات صفراً أو سالباً — قلّل قيمة الخصم.',
                ], 422);
            }
            if ($hasCostCol && $pc > 0 && $after < $pc) {
                json_response([
                    'success' => false,
                    'message' => 'السعر بعد الخصم أقل من تكلفة أحد المتغيّرات — غير مسموح. قلّل قيمة الخصم.',
                ], 422);
            }
        }
    }
    try {
        orange_admin_assert_entity_country($pdo, 'products', $pid);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
    if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
        json_response([
            'success' => false,
            'message' => 'المنتج غير ضمن الكتالوج الموحّد النشط — لا يمكن ربط عرض به في الوضع الحالي.',
        ], 422);
    }

    $hasSort = orange_table_has_column($pdo, 'offers', 'sort_order');
    // التفعيل (نشط/مخفي) — افتراضياً نشط للحفاظ على سلوك النداءات القديمة بلا الحقل.
    $isActive = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;

    // اسم العرض (اختياري لعروض المنتجات — المنتج هو الهوية) + علم الظهور للعميل على الكتالوج.
    $hasNameCols = orange_table_has_column($pdo, 'offers', 'name_ar')
        && orange_table_has_column($pdo, 'offers', 'name_en');
    $hasShowCol = orange_table_has_column($pdo, 'offers', 'show_name_to_customer');
    $hasShowOldCol = orange_table_has_column($pdo, 'offers', 'show_old_price_to_customer');
    $nameAr = function_exists('mb_substr')
        ? mb_substr(trim((string) ($data['name_ar'] ?? '')), 0, 191, 'UTF-8')
        : substr(trim((string) ($data['name_ar'] ?? '')), 0, 191);
    $nameEn = function_exists('mb_substr')
        ? mb_substr(trim((string) ($data['name_en'] ?? '')), 0, 191, 'UTF-8')
        : substr(trim((string) ($data['name_en'] ?? '')), 0, 191);
    $showName = !empty($data['show_name_to_customer']) ? 1 : 0;
    $showOldPrice = !empty($data['show_old_price_to_customer']) ? 1 : 0;

    $offerId = (int) ($data['id'] ?? 0);
    if ($offerId > 0) {
        // دولة العرض = دولة منتجه (العرض مرتبط بمنتج واحد، والمنتج تابع لدولة واحدة؛ الجدول offers
        // بلا country_id بالتصميم). نتحقق من دولة المنتج الحالي للعرض قبل التعديل لمنع إعادة توجيه
        // عرض يخص دولة أخرى — لأن orange_admin_assert_entity_country لا يدعم الكيان 'offers'.
        $existingOffer = $pdo->prepare('SELECT product_id FROM offers WHERE id = ? LIMIT 1');
        $existingOffer->execute([$offerId]);
        $existingOfferPid = (int) ($existingOffer->fetchColumn() ?: 0);
        if ($existingOfferPid <= 0) {
            json_response(['success' => false, 'message' => 'العرض غير موجود'], 404);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $existingOfferPid);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        // الترتيب تلقائي بالكامل: لا يُمَسّ عند التعديل.
        $sets = ['product_id = ?', 'discount = ?', 'is_active = ?'];
        $vals = [$pid, $discount, $isActive];
        if ($hasTypeCol) {
            $sets[] = 'discount_type = ?';
            $vals[] = $discountType;
        }
        if ($hasNameCols) {
            $sets[] = 'name_ar = ?';
            $sets[] = 'name_en = ?';
            $vals[] = $nameAr;
            $vals[] = $nameEn;
        }
        if ($hasShowCol) {
            $sets[] = 'show_name_to_customer = ?';
            $vals[] = $showName;
        }
        if ($hasShowOldCol) {
            $sets[] = 'show_old_price_to_customer = ?';
            $vals[] = $showOldPrice;
        }
        $sets[] = 'is_always_on = ?';
        $sets[] = 'valid_from = ?';
        $sets[] = 'valid_to = ?';
        $sets[] = 'auto_paused_at = NULL';
        $sets[] = 'auto_paused_reason = NULL';
        $vals[] = $isAlwaysOn;
        $vals[] = $bounds['valid_from'];
        $vals[] = $bounds['valid_to'];
        $vals[] = $offerId;
        $st = $pdo->prepare('UPDATE offers SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->execute($vals);
        orange_promo_always_on_sync_history(
            $pdo,
            'offers',
            $offerId,
            $isAlwaysOn,
            orange_admin_context_country_id($pdo)
        );
        json_response(['success' => true, 'message' => 'تم تحديث العرض', 'id' => $offerId]);

        return;
    }

    $cols = ['product_id', 'discount'];
    $vals = [$pid, $discount];
    if ($hasTypeCol) {
        $cols[] = 'discount_type';
        $vals[] = $discountType;
    }
    if ($hasSort) {
        // الترتيب تلقائي: التالي في الجدول (offers بلا country_id بالتصميم).
        $sortOrder = (int) ($pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM offers')->fetchColumn() ?: 1);
        $cols[] = 'sort_order';
        $vals[] = $sortOrder;
    }
    if ($hasNameCols) {
        $cols[] = 'name_ar';
        $cols[] = 'name_en';
        $vals[] = $nameAr;
        $vals[] = $nameEn;
    }
    if ($hasShowCol) {
        $cols[] = 'show_name_to_customer';
        $vals[] = $showName;
    }
    if ($hasShowOldCol) {
        $cols[] = 'show_old_price_to_customer';
        $vals[] = $showOldPrice;
    }
    $cols[] = 'is_active';
    $cols[] = 'is_always_on';
    $cols[] = 'valid_from';
    $cols[] = 'valid_to';
    $vals[] = $isActive;
    $vals[] = $isAlwaysOn;
    $vals[] = $bounds['valid_from'];
    $vals[] = $bounds['valid_to'];
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $stmt = $pdo->prepare('INSERT INTO offers (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($vals);
    $newId = (int) $pdo->lastInsertId();
    orange_promo_always_on_sync_history(
        $pdo,
        'offers',
        $newId,
        $isAlwaysOn,
        orange_admin_context_country_id($pdo)
    );

    json_response(['success' => true, 'message' => 'تم حفظ العرض', 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ العرض');
}
