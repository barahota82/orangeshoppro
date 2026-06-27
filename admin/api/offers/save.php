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

    $isAlwaysOn = !empty($data['is_always_on']) ? 1 : 0;
    $bounds = orange_cart_promo_parse_required_admin_dates(
        trim((string) ($data['valid_from'] ?? '')),
        trim((string) ($data['valid_to'] ?? '')),
        $dateErr,
        $isAlwaysOn === 1
    );
    if ($bounds === null) {
        json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
    }

    $pid = (int) $data['product_id'];
    $discount = round((float) $data['discount'], $offersMoneyDecimals);
    if ($discount <= 0) {
        json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
    }

    // جلب سعر/تكلفة المنتج للتحقق من ألا يهبط السعر بعد الخصم تحت التكلفة (قرار مالك 2026-06-27).
    $prodCols = 'id';
    $hasPriceCol = orange_table_has_column($pdo, 'products', 'price');
    $hasCostCol = orange_table_has_column($pdo, 'products', 'cost');
    if ($hasPriceCol) {
        $prodCols .= ', price';
    }
    if ($hasCostCol) {
        $prodCols .= ', cost';
    }
    $ch = $pdo->prepare('SELECT ' . $prodCols . ' FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
    $ch->execute([$pid]);
    $prodRow = $ch->fetch(PDO::FETCH_ASSOC);
    if (!$prodRow) {
        json_response(['success' => false, 'message' => 'المنتج غير موجود أو غير نشط'], 422);
    }
    if ($hasPriceCol) {
        $prodPrice = round((float) ($prodRow['price'] ?? 0), $offersMoneyDecimals);
        $prodCost = $hasCostCol ? round((float) ($prodRow['cost'] ?? 0), $offersMoneyDecimals) : 0.0;
        if ($discount >= $prodPrice) {
            json_response([
                'success' => false,
                'message' => 'قيمة الخصم تساوي سعر البيع أو تتجاوزه — السعر بعد الخصم لا يصحّ أن يكون صفراً أو سالباً.',
            ], 422);
        }
        if ($hasCostCol && $prodCost > 0) {
            $priceAfter = round($prodPrice - $discount, $offersMoneyDecimals);
            if ($priceAfter < $prodCost) {
                json_response([
                    'success' => false,
                    'message' => 'السعر بعد الخصم أقل من تكلفة المنتج الأساسية — غير مسموح. قلّل قيمة الخصم.',
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
    $nameAr = function_exists('mb_substr')
        ? mb_substr(trim((string) ($data['name_ar'] ?? '')), 0, 191, 'UTF-8')
        : substr(trim((string) ($data['name_ar'] ?? '')), 0, 191);
    $nameEn = function_exists('mb_substr')
        ? mb_substr(trim((string) ($data['name_en'] ?? '')), 0, 191, 'UTF-8')
        : substr(trim((string) ($data['name_en'] ?? '')), 0, 191);
    $showName = !empty($data['show_name_to_customer']) ? 1 : 0;

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
