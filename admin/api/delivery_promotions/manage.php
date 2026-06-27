<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_once __DIR__ . '/../../../includes/promo_always_on.php';
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
 * @param list<int> $areaIds
 * @return list<int>
 */
function dp_valid_active_area_ids_for_governorate(
    PDO $pdo,
    int $countryId,
    int $governorateId,
    array $areaIds
): array {
    if (
        $countryId <= 0
        || $governorateId <= 0
        || $areaIds === []
        || !orange_table_exists($pdo, 'delivery_areas')
        || !orange_delivery_areas_has_governorate_column($pdo)
        || !orange_delivery_governorates_table_exists($pdo)
    ) {
        return [];
    }
    $hasAreaCountry = orange_delivery_areas_has_country_column($pdo);
    $ph = implode(',', array_fill(0, count($areaIds), '?'));
    if ($hasAreaCountry) {
        $params = [$governorateId, $countryId, $countryId, $countryId];
        foreach ($areaIds as $id) {
            $params[] = $id;
        }
        $st = $pdo->prepare(
            'SELECT a.id
             FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id
             WHERE g.id = ?
               AND g.country_id = ?
               AND g.is_active = 1
               AND a.is_active = 1
               AND (a.country_id = ? OR g.country_id = ?)
               AND a.id IN (' . $ph . ')'
        );
        $st->execute($params);

        return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $params = [$governorateId, $countryId];
    foreach ($areaIds as $id) {
        $params[] = $id;
    }
    $st = $pdo->prepare(
        'SELECT a.id
         FROM delivery_areas a
         INNER JOIN delivery_governorates g ON g.id = a.governorate_id
         WHERE g.id = ?
           AND g.country_id = ?
           AND g.is_active = 1
           AND a.is_active = 1
           AND a.id IN (' . $ph . ')'
    );
    $st->execute($params);

    return array_map(static fn ($v): int => (int) $v, $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
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
 * @return list<array{id:int,name_ar:string,name_en:string,governorate_id:int,governorate_name_ar:string,is_active:int,delivery_fee_pending:int}>
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
            'delivery_fee_pending' => (int) ($row['delivery_fee_pending'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    return $out;
}

/**
 * ملخص قيم/تكاليف التوصيل للقراءة فقط (مصدره شاشة المحافظات/المناطق).
 *
 * لكل محافظة: القيم الافتراضية + مجموعات المناطق النشطة حسب (قيمة + تكلفة) + المناطق غير النشطة.
 *
 * @return array{
 *   governorates:list<array{
 *     governorate_id:int,
 *     governorate_name_ar:string,
 *     governorate_name_en:string,
 *     default_delivery_fee:float|null,
 *     default_company_delivery_cost:float|null,
 *     active_count:int,
 *     inactive_count:int,
 *     fee_groups:list<array{
 *       delivery_fee:float,
 *       company_delivery_cost:float,
 *       area_count:int,
 *       is_default:bool,
 *       areas:list<array{id:int,name_ar:string,name_en:string}>
 *     }>,
 *     inactive_areas:list<array{id:int,name_ar:string,name_en:string}>
 *   }>
 * }
 */
function dp_build_delivery_fee_summary(PDO $pdo, int $countryId): array
{
    $govMap = [];
    $governorates = orange_delivery_governorates_table_exists($pdo)
        ? orange_delivery_governorates_admin_list($pdo, $countryId)
        : [];
    foreach ($governorates as $row) {
        if (!is_array($row)) {
            continue;
        }
        $gid = (int) ($row['id'] ?? 0);
        if ($gid <= 0) {
            continue;
        }
        $govMap[(string) $gid] = [
            'governorate_id' => $gid,
            'governorate_name_ar' => (string) ($row['name_ar'] ?? ''),
            'governorate_name_en' => (string) ($row['name_en'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'default_delivery_fee' => ($row['default_delivery_fee'] === null)
                ? null
                : round(max(0.0, (float) $row['default_delivery_fee']), 4),
            'default_company_delivery_cost' => ($row['default_company_delivery_cost'] === null)
                ? null
                : round(max(0.0, (float) $row['default_company_delivery_cost']), 4),
            'active_groups' => [],
            'inactive_areas' => [],
        ];
    }

    $areas = orange_table_exists($pdo, 'delivery_areas')
        ? orange_delivery_areas_admin_list($pdo, $countryId)
        : [];
    foreach ($areas as $row) {
        if (!is_array($row)) {
            continue;
        }
        $areaId = (int) ($row['id'] ?? 0);
        if ($areaId <= 0) {
            continue;
        }
        $gid = (int) ($row['governorate_id'] ?? 0);
        $key = (string) $gid;
        if (!isset($govMap[$key])) {
            $govMap[$key] = [
                'governorate_id' => $gid,
                'governorate_name_ar' => (string) ($row['governorate_name_ar'] ?? ''),
                'governorate_name_en' => (string) ($row['governorate_name_en'] ?? ''),
                'sort_order' => 999999,
                'default_delivery_fee' => null,
                'default_company_delivery_cost' => null,
                'active_groups' => [],
                'inactive_areas' => [],
            ];
        }

        $areaInfo = [
            'id' => $areaId,
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
        ];

        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        if (!$isActive) {
            $govMap[$key]['inactive_areas'][] = $areaInfo;
            continue;
        }

        $followFee = (int) ($row['fee_follows_gov'] ?? 0) === 1;
        $followCost = (int) ($row['cost_follows_gov'] ?? 0) === 1;
        $defFee = $govMap[$key]['default_delivery_fee'];
        $defCost = $govMap[$key]['default_company_delivery_cost'];
        $fee = ($followFee && $defFee !== null)
            ? $defFee
            : round(max(0.0, (float) ($row['delivery_fee'] ?? 0.0)), 4);
        $cost = ($followCost && $defCost !== null)
            ? $defCost
            : round(max(0.0, (float) ($row['company_delivery_cost'] ?? 0.0)), 4);

        $groupKey = number_format($fee, 4, '.', '') . '|' . number_format($cost, 4, '.', '');
        if (!isset($govMap[$key]['active_groups'][$groupKey])) {
            $govMap[$key]['active_groups'][$groupKey] = [
                'delivery_fee' => $fee,
                'company_delivery_cost' => $cost,
                'areas' => [],
            ];
        }
        $govMap[$key]['active_groups'][$groupKey]['areas'][] = $areaInfo;
    }

    $govList = array_values($govMap);
    usort($govList, static function (array $a, array $b): int {
        $soA = (int) ($a['sort_order'] ?? 0);
        $soB = (int) ($b['sort_order'] ?? 0);
        if ($soA !== $soB) {
            return $soA <=> $soB;
        }

        return ((int) ($a['governorate_id'] ?? 0)) <=> ((int) ($b['governorate_id'] ?? 0));
    });

    $outGovs = [];
    foreach ($govList as $gov) {
        $defFee = $gov['default_delivery_fee'];
        $defCost = $gov['default_company_delivery_cost'];
        $groups = array_values(is_array($gov['active_groups'] ?? null) ? $gov['active_groups'] : []);
        usort($groups, static function (array $a, array $b): int {
            $fA = (float) ($a['delivery_fee'] ?? 0.0);
            $fB = (float) ($b['delivery_fee'] ?? 0.0);
            if (abs($fA - $fB) > 0.00005) {
                return $fA <=> $fB;
            }
            $cA = (float) ($a['company_delivery_cost'] ?? 0.0);
            $cB = (float) ($b['company_delivery_cost'] ?? 0.0);

            return $cA <=> $cB;
        });

        $groupsOut = [];
        $activeCount = 0;
        foreach ($groups as $group) {
            $fee = round(max(0.0, (float) ($group['delivery_fee'] ?? 0.0)), 4);
            $cost = round(max(0.0, (float) ($group['company_delivery_cost'] ?? 0.0)), 4);
            $areas = is_array($group['areas'] ?? null) ? array_values($group['areas']) : [];
            $isDefault = $defFee !== null && $defCost !== null
                && abs($fee - $defFee) < 0.00005
                && abs($cost - $defCost) < 0.00005;
            $activeCount += count($areas);
            $groupsOut[] = [
                'delivery_fee' => $fee,
                'company_delivery_cost' => $cost,
                'area_count' => count($areas),
                'is_default' => $isDefault,
                'areas' => $areas,
            ];
        }

        $inactiveAreas = is_array($gov['inactive_areas'] ?? null) ? array_values($gov['inactive_areas']) : [];

        $outGovs[] = [
            'governorate_id' => (int) ($gov['governorate_id'] ?? 0),
            'governorate_name_ar' => (string) ($gov['governorate_name_ar'] ?? ''),
            'governorate_name_en' => (string) ($gov['governorate_name_en'] ?? ''),
            'default_delivery_fee' => $defFee,
            'default_company_delivery_cost' => $defCost,
            'active_count' => $activeCount,
            'inactive_count' => count($inactiveAreas),
            'fee_groups' => $groupsOut,
            'inactive_areas' => $inactiveAreas,
        ];
    }

    return [
        'governorates' => $outGovs,
    ];
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

    if ($action === 'get_fee_summary') {
        json_response([
            'success' => true,
            'data' => dp_build_delivery_fee_summary($pdo, $countryId),
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

    if ($action === 'always_on_history') {
        json_response([
            'success' => true,
            'data' => orange_promo_always_on_history_list($pdo, 'delivery_fee_promotions', $countryId),
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

        // الترتيب تلقائي بالكامل: لا إدخال يدوي — التالي عند الإنشاء، ولا يُمَسّ عند التعديل.
        $sortOrder = 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $requiresRegistered = !empty($data['requires_registered_account']) ? 1 : 0;
        $firstDeliveredOnly = !empty($data['first_delivered_order_only']) ? 1 : 0;
        $showNameToCustomer = !empty($data['show_name_to_customer']) ? 1 : 0;
        // سياسة «اللقطة» الصارمة: الاستهداف بمعرّفات المناطق فقط.
        // أي استهداف محافظة وارد يُتجاهَل (كان يضمّ المناطق الجديدة ديناميكياً) — لا يُخزَّن.
        $validGovernorateIds = [];
        $areaIds = dp_int_id_list($data['target_area_ids'] ?? []);
        $validAreaIds = dp_valid_area_ids($pdo, $countryId, $areaIds);
        if (count($validAreaIds) !== count($areaIds)) {
            json_response(['success' => false, 'message' => 'يوجد مناطق غير صالحة لهذه الدولة'], 422);
        }
        // منع الحفظ بدون استهداف مناطق: العرض الفارغ كان يُطبَّق على كل المناطق (مخالفة سياسة «اللقطة»).
        if ($validAreaIds === []) {
            json_response([
                'success' => false,
                'message' => 'اختر نطاق العرض من جدول الاستهداف: «كل مناطق التوصيل» أو محافظة أو مناطق محددة قبل الحفظ.',
            ], 422);
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
                     SET name_ar = ?, name_en = ?, show_name_to_customer = ?, discount_type = ?, discount_value = ?,
                         requires_registered_account = ?, first_delivered_order_only = ?,
                         valid_from = ?, valid_to = ?,
                         is_active = ?, is_always_on = ?, country_id = ?
                     WHERE id = ?'
                );
                $up->execute([
                    $nameAr,
                    $nameEn,
                    $showNameToCustomer,
                    $discountType,
                    $discountValue,
                    $requiresRegistered,
                    $firstDeliveredOnly,
                    $bounds['valid_from'],
                    $bounds['valid_to'],
                    $isActive,
                    $isAlwaysOn,
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
                $sortOrder = (int) ($stSort->fetchColumn() ?: 1);
                $ins = $pdo->prepare(
                    'INSERT INTO delivery_fee_promotions
                        (country_id, name_ar, name_en, show_name_to_customer, discount_type, discount_value, requires_registered_account,
                         first_delivered_order_only, valid_from, valid_to, sort_order, is_active, is_always_on)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $ins->execute([
                    $countryId,
                    $nameAr,
                    $nameEn,
                    $showNameToCustomer,
                    $discountType,
                    $discountValue,
                    $requiresRegistered,
                    $firstDeliveredOnly,
                    $bounds['valid_from'],
                    $bounds['valid_to'],
                    $sortOrder,
                    $isActive,
                    $isAlwaysOn,
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

            orange_promo_always_on_sync_history(
                $pdo,
                'delivery_fee_promotions',
                $promotionId,
                $isAlwaysOn,
                $countryId
            );

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

