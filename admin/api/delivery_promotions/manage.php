<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

/**
 * @param mixed $v
 */
function dp_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

/**
 * @param mixed $v
 */
function dp_money_non_negative($v, int $decimals): ?float
{
    $s = trim((string) $v);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) {
        return null;
    }
    $n = (float) $s;
    if (!is_finite($n) || $n < 0) {
        return null;
    }

    return round($n, max(0, min(4, $decimals)));
}

/**
 * @param mixed $raw
 * @return list<int>
 */
function dp_int_id_list($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $v) {
        $id = (int) $v;
        if ($id > 0) {
            $out[$id] = true;
        }
    }

    return array_keys($out);
}

/**
 * @param list<int> $governorateIds
 * @return list<int>
 */
function dp_valid_governorate_ids(PDO $pdo, int $countryId, array $governorateIds): array
{
    if ($countryId <= 0 || $governorateIds === [] || !orange_delivery_governorates_table_exists($pdo)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($governorateIds), '?'));
    $params = [$countryId];
    foreach ($governorateIds as $id) {
        $params[] = $id;
    }
    $st = $pdo->prepare(
        'SELECT id FROM delivery_governorates
         WHERE country_id = ?
           AND id IN (' . $ph . ')'
    );
    $st->execute($params);

    return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * @param list<int> $areaIds
 * @return list<int>
 */
function dp_valid_area_ids(PDO $pdo, int $countryId, array $areaIds): array
{
    if ($countryId <= 0 || $areaIds === [] || !orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $hasAreaCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGovJoin = orange_delivery_areas_has_governorate_column($pdo) && orange_delivery_governorates_table_exists($pdo);
    $ph = implode(',', array_fill(0, count($areaIds), '?'));

    if ($hasAreaCountry && $hasGovJoin) {
        $params = [$countryId, $countryId];
        foreach ($areaIds as $id) {
            $params[] = $id;
        }
        $st = $pdo->prepare(
            'SELECT a.id
             FROM delivery_areas a
             LEFT JOIN delivery_governorates g ON g.id = a.governorate_id
             WHERE (a.country_id = ? OR g.country_id = ?)
               AND a.id IN (' . $ph . ')'
        );
        $st->execute($params);

        return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if ($hasAreaCountry) {
        $params = [$countryId];
        foreach ($areaIds as $id) {
            $params[] = $id;
        }
        $st = $pdo->prepare(
            'SELECT id FROM delivery_areas
             WHERE country_id = ?
               AND id IN (' . $ph . ')'
        );
        $st->execute($params);

        return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if ($hasGovJoin) {
        $params = [$countryId];
        foreach ($areaIds as $id) {
            $params[] = $id;
        }
        $st = $pdo->prepare(
            'SELECT a.id
             FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id
             WHERE g.country_id = ?
               AND a.id IN (' . $ph . ')'
        );
        $st->execute($params);

        return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    return [];
}

/**
 * @return list<array{id:int,name_ar:string,name_en:string,is_active:int}>
 */
function dp_targets_governorates(PDO $pdo, int $countryId): array
{
    $rows = orange_delivery_governorates_admin_list($pdo, $countryId);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * @return list<array{id:int,name_ar:string,name_en:string,governorate_id:int,governorate_name_ar:string,is_active:int}>
 */
function dp_targets_areas(PDO $pdo, int $countryId): array
{
    $rows = orange_delivery_areas_admin_list($pdo, $countryId);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'governorate_id' => (int) ($row['governorate_id'] ?? 0),
            'governorate_name_ar' => (string) ($row['governorate_name_ar'] ?? ''),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    return $out;
}

function dp_apply_base_fee_to_active_areas(
    PDO $pdo,
    int $countryId,
    float $previousDefaultFee,
    float $newDefaultFee,
    bool $preserveCustomAreaFees
): int {
    if (!$preserveCustomAreaFees) {
        return orange_delivery_apply_default_fee_to_active_areas($pdo, $countryId, $newDefaultFee);
    }
    if ($countryId <= 0 || !orange_table_exists($pdo, 'delivery_areas')) {
        return 0;
    }
    if (!orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee')) {
        return 0;
    }
    $oldFee = round(max(0.0, $previousDefaultFee), 4);
    $newFee = round(max(0.0, $newDefaultFee), 4);
    $hasCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);

    if ($hasCountry && $hasGov) {
        $up = $pdo->prepare(
            'UPDATE delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id
             SET a.delivery_fee = ?
             WHERE a.is_active = 1
               AND g.is_active = 1
               AND (a.country_id = ? OR g.country_id = ?)
               AND ABS(COALESCE(a.delivery_fee, 0) - ?) < 0.0001'
        );
        $up->execute([$newFee, $countryId, $countryId, $oldFee]);

        return (int) $up->rowCount();
    }
    if ($hasGov) {
        $up = $pdo->prepare(
            'UPDATE delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id
             SET a.delivery_fee = ?
             WHERE a.is_active = 1
               AND g.is_active = 1
               AND g.country_id = ?
               AND ABS(COALESCE(a.delivery_fee, 0) - ?) < 0.0001'
        );
        $up->execute([$newFee, $countryId, $oldFee]);

        return (int) $up->rowCount();
    }
    if ($hasCountry) {
        $up = $pdo->prepare(
            'UPDATE delivery_areas
             SET delivery_fee = ?
             WHERE is_active = 1
               AND country_id = ?
               AND ABS(COALESCE(delivery_fee, 0) - ?) < 0.0001'
        );
        $up->execute([$newFee, $countryId, $oldFee]);

        return (int) $up->rowCount();
    }
    $up = $pdo->prepare(
        'UPDATE delivery_areas
         SET delivery_fee = ?
         WHERE is_active = 1
           AND ABS(COALESCE(delivery_fee, 0) - ?) < 0.0001'
    );
    $up->execute([$newFee, $oldFee]);

    return (int) $up->rowCount();
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    $countryId = orange_delivery_areas_api_country_id($pdo, is_array($data) ? $data : []);
    $moneyDecimals = orange_currency_decimals_for_code(
        orange_country_functional_currency_code($pdo, $countryId)
    );

    if ($action === 'get_base_fee') {
        $policy = orange_delivery_country_policy_read($pdo, $countryId);
        json_response([
            'success' => true,
            'data' => [
                'default_delivery_fee' => (float) ($policy['default_delivery_fee'] ?? 0.0),
                'delivery_fee_policy' => (string) ($policy['delivery_fee_policy'] ?? 'paid_all'),
                'active_areas_count' => orange_delivery_areas_count_active($pdo, $countryId),
            ],
        ]);
    }

    if ($action === 'save_base_fee') {
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }
        $defaultFee = dp_money_non_negative($data['default_delivery_fee'] ?? '', $moneyDecimals);
        if ($defaultFee === null) {
            json_response(['success' => false, 'message' => 'قيمة التوصيل الأساسية غير صحيحة'], 422);
        }
        $applyActiveAreas = !empty($data['apply_active_areas']);
        $preserveCustomAreaFees = array_key_exists('preserve_custom_area_fees', $data)
            ? !empty($data['preserve_custom_area_fees'])
            : true;
        $appliedCount = 0;

        $pdo->beginTransaction();
        try {
            $policy = orange_delivery_country_policy_read($pdo, $countryId);
            $oldDefaultFee = (float) ($policy['default_delivery_fee'] ?? 0.0);
            $policyCode = orange_delivery_fee_policy_normalize((string) ($policy['delivery_fee_policy'] ?? 'paid_all'));
            orange_delivery_country_policy_save($pdo, $countryId, (float) $defaultFee, $policyCode);
            if ($applyActiveAreas) {
                $appliedCount = dp_apply_base_fee_to_active_areas(
                    $pdo,
                    $countryId,
                    $oldDefaultFee,
                    (float) $defaultFee,
                    $preserveCustomAreaFees
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $saved = orange_delivery_country_policy_read($pdo, $countryId);
        if (!$applyActiveAreas) {
            $msg = 'تم حفظ القيمة الأساسية للتوصيل';
        } elseif ($preserveCustomAreaFees) {
            $msg = 'تم حفظ القيمة الأساسية وتحديث ' . $appliedCount . ' منطقة مطابقة للقيمة الأساسية السابقة (بدون المساس بالمناطق ذات التسعير المخصص)';
        } else {
            $msg = 'تم حفظ القيمة الأساسية وتحديث ' . $appliedCount . ' منطقة نشطة (توحيد كامل للمناطق النشطة)';
        }
        json_response([
            'success' => true,
            'message' => $msg,
            'data' => [
                'default_delivery_fee' => (float) ($saved['default_delivery_fee'] ?? 0.0),
                'delivery_fee_policy' => (string) ($saved['delivery_fee_policy'] ?? 'paid_all'),
                'active_areas_count' => orange_delivery_areas_count_active($pdo, $countryId),
                'applied_count' => $appliedCount,
                'preserve_custom_area_fees' => $preserveCustomAreaFees ? 1 : 0,
            ],
        ]);
    }

    if (!orange_table_exists($pdo, 'delivery_fee_promotions')) {
        json_response(['success' => false, 'message' => 'جدول delivery_fee_promotions غير جاهز'], 422);
    }

    if ($action === 'list') {
        json_response([
            'success' => true,
            'data' => orange_delivery_promotions_admin_list($pdo, $countryId),
        ]);
    }

    if ($action === 'list_targets') {
        json_response([
            'success' => true,
            'governorates' => dp_targets_governorates($pdo, $countryId),
            'areas' => dp_targets_areas($pdo, $countryId),
        ]);
    }

    if ($action === 'save') {
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }

        $id = (int) ($data['id'] ?? 0);
        $nameAr = dp_str191($data['name_ar'] ?? '');
        $nameEn = dp_str191($data['name_en'] ?? '');
        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم العرض بالعربي مطلوب'], 422);
        }

        $discountType = orange_delivery_promotion_discount_type_normalize((string) ($data['discount_type'] ?? 'amount'));
        $discountValue = dp_money_non_negative($data['discount_value'] ?? '', $moneyDecimals);
        if ($discountValue === null) {
            json_response(['success' => false, 'message' => 'قيمة الخصم غير صحيحة'], 422);
        }
        if ($discountType === 'free') {
            $discountValue = 0.0;
        } elseif ($discountType === 'percent') {
            if ($discountValue <= 0.0 || $discountValue > 100.0) {
                json_response(['success' => false, 'message' => 'نسبة الخصم يجب أن تكون بين 0 و 100'], 422);
            }
        } elseif ($discountValue <= 0.0) {
            json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
        }

        $dateErr = null;
        $bounds = orange_cart_promo_parse_required_admin_dates(
            trim((string) ($data['valid_from'] ?? '')),
            trim((string) ($data['valid_to'] ?? '')),
            $dateErr
        );
        if ($bounds === null) {
            json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
        }

        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $requiresRegistered = !empty($data['requires_registered_account']) ? 1 : 0;
        $governorateIds = dp_int_id_list($data['target_governorate_ids'] ?? []);
        $areaIds = dp_int_id_list($data['target_area_ids'] ?? []);

        $validGovernorateIds = dp_valid_governorate_ids($pdo, $countryId, $governorateIds);
        $validAreaIds = dp_valid_area_ids($pdo, $countryId, $areaIds);
        if (count($validGovernorateIds) !== count($governorateIds)) {
            json_response(['success' => false, 'message' => 'يوجد محافظات غير صالحة لهذه الدولة'], 422);
        }
        if (count($validAreaIds) !== count($areaIds)) {
            json_response(['success' => false, 'message' => 'يوجد مناطق غير صالحة لهذه الدولة'], 422);
        }

        if ($id > 0) {
            try {
                orange_admin_assert_row_country($pdo, 'delivery_fee_promotions', $id);
            } catch (RuntimeException $e) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            }
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $up = $pdo->prepare(
                    'UPDATE delivery_fee_promotions
                     SET name_ar = ?, name_en = ?, discount_type = ?, discount_value = ?,
                         requires_registered_account = ?, valid_from = ?, valid_to = ?,
                         sort_order = ?, is_active = ?, country_id = ?
                     WHERE id = ?'
                );
                $up->execute([
                    $nameAr,
                    $nameEn,
                    $discountType,
                    $discountValue,
                    $requiresRegistered,
                    $bounds['valid_from'],
                    $bounds['valid_to'],
                    $sortOrder,
                    $isActive,
                    $countryId,
                    $id,
                ]);
                $promotionId = $id;
            } else {
                $stSort = $pdo->prepare(
                    'SELECT COALESCE(MAX(sort_order), 0) + 1
                     FROM delivery_fee_promotions
                     WHERE country_id = ?'
                );
                $stSort->execute([$countryId]);
                $nextSort = (int) ($stSort->fetchColumn() ?: 1);
                if ($sortOrder <= 0) {
                    $sortOrder = $nextSort;
                }
                $ins = $pdo->prepare(
                    'INSERT INTO delivery_fee_promotions
                        (country_id, name_ar, name_en, discount_type, discount_value, requires_registered_account,
                         valid_from, valid_to, sort_order, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $countryId,
                    $nameAr,
                    $nameEn,
                    $discountType,
                    $discountValue,
                    $requiresRegistered,
                    $bounds['valid_from'],
                    $bounds['valid_to'],
                    $sortOrder,
                    $isActive,
                ]);
                $promotionId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM delivery_fee_promotion_governorates WHERE promotion_id = ?')->execute([$promotionId]);
            $pdo->prepare('DELETE FROM delivery_fee_promotion_areas WHERE promotion_id = ?')->execute([$promotionId]);

            if ($validGovernorateIds !== []) {
                $insGov = $pdo->prepare(
                    'INSERT INTO delivery_fee_promotion_governorates (promotion_id, governorate_id)
                     VALUES (?, ?)'
                );
                foreach ($validGovernorateIds as $govId) {
                    $insGov->execute([$promotionId, $govId]);
                }
            }
            if ($validAreaIds !== []) {
                $insArea = $pdo->prepare(
                    'INSERT INTO delivery_fee_promotion_areas (promotion_id, delivery_area_id)
                     VALUES (?, ?)'
                );
                foreach ($validAreaIds as $areaId) {
                    $insArea->execute([$promotionId, $areaId]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        json_response([
            'success' => true,
            'message' => 'تم حفظ عرض التوصيل',
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عروض التوصيل');
}

