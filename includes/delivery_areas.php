<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

function orange_delivery_governorates_table_exists(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'delivery_governorates');
}

function orange_delivery_governorates_sort_order_step(): int
{
    return 1;
}

/** ترتيب المحافظة التالي ضمن الدولة (خطوة 10). */
function orange_delivery_governorates_next_sort_order(PDO $pdo, int $countryId): int
{
    $step = orange_delivery_governorates_sort_order_step();
    if (!orange_delivery_governorates_table_exists($pdo) || $countryId <= 0) {
        return $step;
    }
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM delivery_governorates WHERE country_id = ?');
    $st->execute([$countryId]);
    $max = (int) $st->fetchColumn();
    if ($max <= 0) {
        return $step;
    }

    return $max + $step;
}

function orange_delivery_areas_sort_order_step(): int
{
    return orange_delivery_governorates_sort_order_step();
}

/** ترتيب المنطقة التالي (خطوة 10) — ضمن المحافظة إن وُجدت وإلا ضمن الدولة. */
function orange_delivery_areas_next_sort_order(PDO $pdo, int $countryId, int $governorateId = 0): int
{
    $step = orange_delivery_areas_sort_order_step();
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return $step;
    }
    $hasGovCol = orange_delivery_areas_has_governorate_column($pdo);
    $hasCountryCol = orange_delivery_areas_has_country_column($pdo);
    if ($hasGovCol && $governorateId > 0) {
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM delivery_areas WHERE governorate_id = ?');
        $st->execute([$governorateId]);
    } elseif ($hasCountryCol && $countryId > 0) {
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM delivery_areas WHERE country_id = ?');
        $st->execute([$countryId]);
    } else {
        $st = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM delivery_areas');
    }
    $max = $st ? (int) $st->fetchColumn() : 0;
    if ($max <= 0) {
        return $step;
    }

    return $max + $step;
}

function orange_delivery_areas_has_governorate_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'delivery_areas')
        && orange_table_has_column($pdo, 'delivery_areas', 'governorate_id');
}

/**
 * محافظة افتراضية لكل دولة (ترحيل المناطف القديمة).
 */
function orange_delivery_governorate_ensure_default(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_delivery_governorates_table_exists($pdo)) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT id FROM delivery_governorates WHERE country_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1'
    );
    $st->execute([$countryId]);
    $existing = $st->fetchColumn();
    if ($existing !== false && (int) $existing > 0) {
        return (int) $existing;
    }
    $ins = $pdo->prepare(
        'INSERT INTO delivery_governorates (country_id, name_ar, name_en, sort_order, is_active) VALUES (?, ?, ?, ?, 1)'
    );
    $ins->execute([$countryId, 'عام', 'General', 0]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array{id:int, country_id:int, name_ar:string, name_en:string, sort_order:int, is_active:int, areas_count?:int}>
 */
function orange_delivery_governorates_admin_list(PDO $pdo, int $countryId): array
{
    if (!orange_delivery_governorates_table_exists($pdo) || $countryId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT g.id, g.country_id, g.name_ar, g.name_en, g.sort_order, g.is_active,
                (SELECT COUNT(*) FROM delivery_areas a WHERE a.governorate_id = g.id) AS areas_count
         FROM delivery_governorates g
         WHERE g.country_id = ?
         ORDER BY g.sort_order ASC, g.id ASC'
    );
    $st->execute([$countryId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['areas_count'] = (int) ($r['areas_count'] ?? 0);
    }
    unset($r);

    return $rows;
}

/**
 * @return array<int, array{id:int, name_ar:string, name_en:string, delivery_fee?:float, sort_order:int, is_active:int, country_id?:int, governorate_id?:int, governorate_name_ar?:string, governorate_name_en?:string}>
 */
function orange_delivery_areas_admin_list(PDO $pdo, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $hasCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    $hasFee = orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee');
    $feeSelA = $hasFee ? 'a.delivery_fee' : '0 AS delivery_fee';
    $feeSel = $hasFee ? 'delivery_fee' : '0 AS delivery_fee';
    if ($countryId === null && $hasCountry) {
        $countryId = orange_countries_default_id($pdo);
    }
    if ($hasCountry && $countryId !== null && $countryId > 0) {
        if ($hasGov) {
            $st = $pdo->prepare(
                'SELECT a.id, a.name_ar, a.name_en, ' . $feeSelA . ', a.sort_order, a.is_active, a.country_id, a.governorate_id,
                        g.name_ar AS governorate_name_ar, g.name_en AS governorate_name_en
                 FROM delivery_areas a
                 LEFT JOIN delivery_governorates g ON g.id = a.governorate_id
                 WHERE a.country_id = ? OR g.country_id = ?
                 ORDER BY g.sort_order ASC, g.id ASC, a.sort_order ASC, a.id ASC'
            );
            $st->execute([$countryId, $countryId]);
        } else {
            $st = $pdo->prepare(
                'SELECT id, name_ar, name_en, ' . $feeSel . ', sort_order, is_active, country_id
                 FROM delivery_areas WHERE country_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $st->execute([$countryId]);
        }

        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    $st = $pdo->query(
        'SELECT id, name_ar, name_en, ' . $feeSel . ', sort_order, is_active FROM delivery_areas ORDER BY sort_order ASC, id ASC'
    );

    return $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];
}

/**
 * مناطق نشطة للدولة (يشمل الربط عبر المحافظة إن country_id على المنطقة ناقص).
 *
 * @return list<array{id:int, name_ar:string, name_en:string, delivery_fee?:float}>
 */
function orange_delivery_areas_storefront_active_rows(PDO $pdo, int $countryId): array
{
    if (!orange_table_exists($pdo, 'delivery_areas') || $countryId <= 0) {
        return [];
    }
    $hasCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    $hasFee = orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee');
    $feeSelA = $hasFee ? 'a.delivery_fee' : '0 AS delivery_fee';
    $feeSel = $hasFee ? 'delivery_fee' : '0 AS delivery_fee';
    if ($hasCountry && $hasGov) {
        $st = $pdo->prepare(
            'SELECT a.id, a.name_ar, a.name_en, ' . $feeSelA . '
             FROM delivery_areas a
             LEFT JOIN delivery_governorates g ON g.id = a.governorate_id
             WHERE a.is_active = 1
               AND (g.id IS NULL OR g.is_active = 1)
               AND (a.country_id = ? OR g.country_id = ?)'
        );
        $st->execute([$countryId, $countryId]);
    } elseif ($hasCountry) {
        $st = $pdo->prepare(
            'SELECT id, name_ar, name_en, ' . $feeSel . ' FROM delivery_areas WHERE is_active = 1 AND country_id = ?'
        );
        $st->execute([$countryId]);
    } else {
        $st = $pdo->query(
            'SELECT id, name_ar, name_en, ' . $feeSel . ' FROM delivery_areas WHERE is_active = 1'
        );
    }
    if (!$st) {
        return [];
    }

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $row
 */
function orange_delivery_area_fee_from_row(array $row): float
{
    $raw = $row['delivery_fee'] ?? 0;
    $n = is_numeric($raw) ? (float) $raw : 0.0;
    if (!is_finite($n) || $n < 0) {
        return 0.0;
    }

    return round($n, 4);
}

/**
 * @return array<string, bool>
 */
function orange_delivery_fee_policy_values(): array
{
    return [
        'paid_all' => true,
        'free_registered' => true,
        'free_all' => true,
    ];
}

function orange_delivery_fee_policy_normalize(?string $policy): string
{
    $key = strtolower(trim((string) $policy));

    return isset(orange_delivery_fee_policy_values()[$key]) ? $key : 'paid_all';
}

/**
 * @return array{default_delivery_fee:float, delivery_fee_policy:string}
 */
function orange_delivery_country_policy_read(PDO $pdo, ?int $countryId = null): array
{
    $result = [
        'default_delivery_fee' => 0.0,
        'delivery_fee_policy' => 'paid_all',
    ];
    if (!orange_table_exists($pdo, 'countries')) {
        return $result;
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if ($countryId <= 0) {
        return $result;
    }

    $hasFee = orange_table_has_column($pdo, 'countries', 'default_delivery_fee');
    $hasPolicy = orange_table_has_column($pdo, 'countries', 'delivery_fee_policy');
    if (!$hasFee && !$hasPolicy) {
        return $result;
    }
    $feeSel = $hasFee ? 'default_delivery_fee' : '0 AS default_delivery_fee';
    $policySel = $hasPolicy ? "delivery_fee_policy" : "'paid_all' AS delivery_fee_policy";
    $st = $pdo->prepare(
        'SELECT ' . $feeSel . ', ' . $policySel . ' FROM countries WHERE id = ? LIMIT 1'
    );
    $st->execute([$countryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $result;
    }
    $result['default_delivery_fee'] = orange_delivery_area_fee_from_row([
        'delivery_fee' => $row['default_delivery_fee'] ?? 0,
    ]);
    $result['delivery_fee_policy'] = orange_delivery_fee_policy_normalize((string) ($row['delivery_fee_policy'] ?? ''));

    return $result;
}

function orange_delivery_country_default_fee(PDO $pdo, ?int $countryId = null): float
{
    $policy = orange_delivery_country_policy_read($pdo, $countryId);

    return (float) ($policy['default_delivery_fee'] ?? 0.0);
}

function orange_delivery_country_policy_save(PDO $pdo, int $countryId, float $defaultDeliveryFee, string $policy): void
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'countries')) {
        return;
    }
    $fee = round(max(0.0, $defaultDeliveryFee), 4);
    $policyNorm = orange_delivery_fee_policy_normalize($policy);
    $hasFee = orange_table_has_column($pdo, 'countries', 'default_delivery_fee');
    $hasPolicy = orange_table_has_column($pdo, 'countries', 'delivery_fee_policy');
    if (!$hasFee && !$hasPolicy) {
        return;
    }
    $set = [];
    $params = [];
    if ($hasFee) {
        $set[] = 'default_delivery_fee = ?';
        $params[] = $fee;
    }
    if ($hasPolicy) {
        $set[] = 'delivery_fee_policy = ?';
        $params[] = $policyNorm;
    }
    if ($set === []) {
        return;
    }
    $params[] = $countryId;
    $sql = 'UPDATE countries SET ' . implode(', ', $set) . ' WHERE id = ? LIMIT 1';
    $up = $pdo->prepare($sql);
    $up->execute($params);
}

function orange_delivery_apply_default_fee_to_active_areas(PDO $pdo, int $countryId, float $defaultDeliveryFee): int
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'delivery_areas')) {
        return 0;
    }
    if (!orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee')) {
        return 0;
    }
    $fee = round(max(0.0, $defaultDeliveryFee), 4);
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
               AND (a.country_id = ? OR g.country_id = ?)'
        );
        $up->execute([$fee, $countryId, $countryId]);

        return (int) $up->rowCount();
    }
    if ($hasGov) {
        $up = $pdo->prepare(
            'UPDATE delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id
             SET a.delivery_fee = ?
             WHERE a.is_active = 1
               AND g.is_active = 1
               AND g.country_id = ?'
        );
        $up->execute([$fee, $countryId]);

        return (int) $up->rowCount();
    }
    if ($hasCountry) {
        $up = $pdo->prepare(
            'UPDATE delivery_areas SET delivery_fee = ? WHERE is_active = 1 AND country_id = ?'
        );
        $up->execute([$fee, $countryId]);

        return (int) $up->rowCount();
    }
    $up = $pdo->prepare(
        'UPDATE delivery_areas SET delivery_fee = ? WHERE is_active = 1'
    );
    $up->execute([$fee]);

    return (int) $up->rowCount();
}

function orange_delivery_policy_is_free_for_buyer(string $policy, bool $buyerRegistered): bool
{
    $policy = orange_delivery_fee_policy_normalize($policy);
    if ($policy === 'free_all') {
        return true;
    }

    return $policy === 'free_registered' && $buyerRegistered;
}

/**
 * @return array<string, bool>
 */
function orange_delivery_promotion_discount_type_values(): array
{
    return [
        'amount' => true,
        'percent' => true,
        'free' => true,
    ];
}

function orange_delivery_promotion_discount_type_normalize(?string $discountType): string
{
    $key = strtolower(trim((string) $discountType));
    if (isset(orange_delivery_promotion_discount_type_values()[$key])) {
        return $key;
    }

    return 'amount';
}

function orange_delivery_promotions_table_exists(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'delivery_fee_promotions');
}

/**
 * @param list<int> $promotionIds
 * @return array<int, list<int>>
 */
function orange_delivery_promotion_targets_map(
    PDO $pdo,
    array $promotionIds,
    string $targetTable,
    string $targetColumn
): array {
    if ($promotionIds === [] || !orange_table_exists($pdo, $targetTable)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($promotionIds), '?'));
    $sql = 'SELECT promotion_id, ' . $targetColumn . ' AS target_id'
        . ' FROM ' . $targetTable
        . ' WHERE promotion_id IN (' . $ph . ')';
    $st = $pdo->prepare($sql);
    $st->execute($promotionIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['promotion_id'] ?? 0);
        $targetId = (int) ($row['target_id'] ?? 0);
        if ($pid <= 0 || $targetId <= 0) {
            continue;
        }
        if (!isset($out[$pid])) {
            $out[$pid] = [];
        }
        if (!in_array($targetId, $out[$pid], true)) {
            $out[$pid][] = $targetId;
        }
    }

    return $out;
}

/**
 * @param list<int> $promotionIds
 * @return array<int, list<string>>
 */
function orange_delivery_promotion_target_names_map(
    PDO $pdo,
    array $promotionIds,
    string $mapTable,
    string $targetIdColumn,
    string $lookupTable
): array {
    if (
        $promotionIds === []
        || !orange_table_exists($pdo, $mapTable)
        || !orange_table_exists($pdo, $lookupTable)
    ) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($promotionIds), '?'));
    $sql = 'SELECT m.promotion_id, l.name_ar, l.name_en'
        . ' FROM ' . $mapTable . ' m'
        . ' INNER JOIN ' . $lookupTable . ' l ON l.id = m.' . $targetIdColumn
        . ' WHERE m.promotion_id IN (' . $ph . ')';
    $st = $pdo->prepare($sql);
    $st->execute($promotionIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['promotion_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $nameAr = trim((string) ($row['name_ar'] ?? ''));
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        $label = $nameAr !== '' ? $nameAr : $nameEn;
        if ($label === '') {
            continue;
        }
        if (!isset($out[$pid])) {
            $out[$pid] = [];
        }
        if (!in_array($label, $out[$pid], true)) {
            $out[$pid][] = $label;
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_delivery_promotions_admin_list(PDO $pdo, int $countryId): array
{
    if (!orange_delivery_promotions_table_exists($pdo) || $countryId <= 0) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT id, country_id, name_ar, name_en, discount_type, discount_value,
                requires_registered_account, valid_from, valid_to, sort_order, is_active
         FROM delivery_fee_promotions
         WHERE country_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$countryId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }
    $promotionIds = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid > 0) {
            $promotionIds[] = $pid;
        }
    }
    $governorateIdsMap = orange_delivery_promotion_targets_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_governorates',
        'governorate_id'
    );
    $areaIdsMap = orange_delivery_promotion_targets_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_areas',
        'delivery_area_id'
    );
    $governorateNamesMap = orange_delivery_promotion_target_names_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_governorates',
        'governorate_id',
        'delivery_governorates'
    );
    $areaNamesMap = orange_delivery_promotion_target_names_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_areas',
        'delivery_area_id',
        'delivery_areas'
    );
    foreach ($rows as &$row) {
        $pid = (int) ($row['id'] ?? 0);
        $row['id'] = $pid;
        $row['country_id'] = (int) ($row['country_id'] ?? 0);
        $row['name_ar'] = (string) ($row['name_ar'] ?? '');
        $row['name_en'] = (string) ($row['name_en'] ?? '');
        $row['discount_type'] = orange_delivery_promotion_discount_type_normalize((string) ($row['discount_type'] ?? ''));
        $row['discount_value'] = round(max(0.0, (float) ($row['discount_value'] ?? 0)), 4);
        $row['requires_registered_account'] = (int) ($row['requires_registered_account'] ?? 0) === 1 ? 1 : 0;
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
        $row['is_active'] = (int) ($row['is_active'] ?? 0) === 1 ? 1 : 0;
        $row['target_governorate_ids'] = $governorateIdsMap[$pid] ?? [];
        $row['target_area_ids'] = $areaIdsMap[$pid] ?? [];
        $row['target_governorate_names'] = $governorateNamesMap[$pid] ?? [];
        $row['target_area_names'] = $areaNamesMap[$pid] ?? [];
    }
    unset($row);

    return $rows;
}

function orange_delivery_promotion_discount_amount(
    float $baseFee,
    string $discountType,
    float $discountValue
): float {
    $baseFee = round(max(0.0, $baseFee), 4);
    if ($baseFee <= 0.0) {
        return 0.0;
    }
    $discountType = orange_delivery_promotion_discount_type_normalize($discountType);
    $discountValue = round(max(0.0, $discountValue), 4);
    if ($discountType === 'free') {
        return $baseFee;
    }
    if ($discountType === 'percent') {
        $pct = min(100.0, $discountValue);
        if ($pct <= 0.0) {
            return 0.0;
        }

        return round(min($baseFee, $baseFee * $pct / 100.0), 4);
    }
    if ($discountValue <= 0.0) {
        return 0.0;
    }

    return round(min($baseFee, $discountValue), 4);
}

function orange_delivery_area_governorate_id(PDO $pdo, int $deliveryAreaId): int
{
    if (
        $deliveryAreaId <= 0
        || !orange_table_exists($pdo, 'delivery_areas')
        || !orange_delivery_areas_has_governorate_column($pdo)
    ) {
        return 0;
    }
    $st = $pdo->prepare('SELECT governorate_id FROM delivery_areas WHERE id = ? LIMIT 1');
    $st->execute([$deliveryAreaId]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * @param list<int> $areaTargets
 * @param list<int> $governorateTargets
 */
function orange_delivery_promotion_target_matches_area(
    int $deliveryAreaId,
    int $governorateId,
    array $areaTargets,
    array $governorateTargets
): bool {
    if ($areaTargets === [] && $governorateTargets === []) {
        return true;
    }
    if ($deliveryAreaId <= 0) {
        return false;
    }
    if ($areaTargets !== [] && in_array($deliveryAreaId, $areaTargets, true)) {
        return true;
    }
    if ($governorateTargets !== [] && $governorateId > 0 && in_array($governorateId, $governorateTargets, true)) {
        return true;
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function orange_delivery_promotion_resolve_for_checkout(
    PDO $pdo,
    int $deliveryAreaId,
    float $baseFee,
    bool $buyerRegistered,
    ?int $countryId = null
): ?array {
    $baseFee = round(max(0.0, $baseFee), 4);
    if ($baseFee <= 0.0 || $deliveryAreaId <= 0 || !orange_delivery_promotions_table_exists($pdo)) {
        return null;
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if ($countryId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, name_ar, name_en, discount_type, discount_value, requires_registered_account,
                sort_order, valid_from, valid_to
         FROM delivery_fee_promotions
         WHERE country_id = ? AND is_active = 1
           AND valid_from <= CURDATE() AND valid_to >= CURDATE()
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$countryId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return null;
    }
    $promotionIds = [];
    foreach ($rows as $row) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid > 0) {
            $promotionIds[] = $pid;
        }
    }
    $governorateTargetsMap = orange_delivery_promotion_targets_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_governorates',
        'governorate_id'
    );
    $areaTargetsMap = orange_delivery_promotion_targets_map(
        $pdo,
        $promotionIds,
        'delivery_fee_promotion_areas',
        'delivery_area_id'
    );
    $areaGovernorateId = orange_delivery_area_governorate_id($pdo, $deliveryAreaId);
    foreach ($rows as $row) {
        $promotionId = (int) ($row['id'] ?? 0);
        if ($promotionId <= 0) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        $areaTargets = $areaTargetsMap[$promotionId] ?? [];
        $governorateTargets = $governorateTargetsMap[$promotionId] ?? [];
        if (!orange_delivery_promotion_target_matches_area($deliveryAreaId, $areaGovernorateId, $areaTargets, $governorateTargets)) {
            continue;
        }
        $discountType = orange_delivery_promotion_discount_type_normalize((string) ($row['discount_type'] ?? ''));
        $discountValue = round(max(0.0, (float) ($row['discount_value'] ?? 0)), 4);
        $discountAmount = orange_delivery_promotion_discount_amount($baseFee, $discountType, $discountValue);
        if ($discountAmount <= 0.0) {
            continue;
        }
        $netFee = round(max(0.0, $baseFee - $discountAmount), 4);

        return [
            'id' => $promotionId,
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'base_fee' => $baseFee,
            'net_fee' => $netFee,
        ];
    }

    return null;
}

/**
 * @param array<string, mixed>|null $areaRow
 */
function orange_delivery_resolve_checkout_fee_base(
    PDO $pdo,
    int $deliveryAreaId,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?array $areaRow = null
): float {
    if ($deliveryAreaId <= 0) {
        return 0.0;
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if ($areaRow === null) {
        $areaRow = orange_delivery_area_row_active($pdo, $deliveryAreaId, $countryId);
    }
    if (!is_array($areaRow)) {
        return 0.0;
    }
    $baseFee = orange_delivery_area_fee_from_row($areaRow);
    if ($baseFee <= 0.0) {
        return 0.0;
    }
    $policy = orange_delivery_country_policy_read($pdo, $countryId);
    if (orange_delivery_policy_is_free_for_buyer((string) ($policy['delivery_fee_policy'] ?? ''), $buyerRegistered)) {
        return 0.0;
    }

    return $baseFee;
}

/**
 * @param array<string, mixed>|null $areaRow
 * @return array{
 *   base_fee: float,
 *   discount_fee: float,
 *   fee: float,
 *   promotion: array<string, mixed>|null
 * }
 */
function orange_delivery_resolve_checkout_fee_bundle(
    PDO $pdo,
    int $deliveryAreaId,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?array $areaRow = null
): array {
    $baseFee = orange_delivery_resolve_checkout_fee_base(
        $pdo,
        $deliveryAreaId,
        $buyerRegistered,
        $countryId,
        $areaRow
    );
    if ($baseFee <= 0.0) {
        return [
            'base_fee' => 0.0,
            'discount_fee' => 0.0,
            'fee' => 0.0,
            'promotion' => null,
        ];
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $promotion = orange_delivery_promotion_resolve_for_checkout(
        $pdo,
        $deliveryAreaId,
        $baseFee,
        $buyerRegistered,
        $countryId
    );
    $discountFee = $promotion !== null
        ? round(max(0.0, (float) ($promotion['discount_amount'] ?? 0)), 4)
        : 0.0;
    $discountFee = min($discountFee, $baseFee);
    $netFee = round(max(0.0, $baseFee - $discountFee), 4);

    return [
        'base_fee' => $baseFee,
        'discount_fee' => $discountFee,
        'fee' => $netFee,
        'promotion' => $promotion,
    ];
}

/**
 * @param array<string, mixed>|null $areaRow
 */
function orange_delivery_resolve_checkout_fee(
    PDO $pdo,
    int $deliveryAreaId,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?array $areaRow = null
): float {
    $bundle = orange_delivery_resolve_checkout_fee_bundle(
        $pdo,
        $deliveryAreaId,
        $buyerRegistered,
        $countryId,
        $areaRow
    );

    return (float) ($bundle['fee'] ?? 0.0);
}

/**
 * @param array<string, mixed> $data
 */
function orange_delivery_areas_api_country_id(PDO $pdo, array $data): int
{
    $countryId = (int) ($data['country_id'] ?? 0);
    if ($countryId > 0) {
        return $countryId;
    }
    if (isset($_GET['admin_country']) && (string) $_GET['admin_country'] !== '') {
        return orange_admin_context_country_id($pdo);
    }
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '' && preg_match('/[?&]admin_country=([^&]+)/', $ref, $m)) {
        $code = orange_countries_normalize_code(rawurldecode((string) $m[1]));
        $row = orange_country_row_by_code($pdo, $code, false);
        if ($row !== null) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return orange_admin_context_country_id($pdo);
}

/**
 * مناطق نشطة للواجهة: id + اسم حسب لغة العرض (عربي = name_ar، غيره = name_en مع احتياط name_ar).
 *
 * @return list<array{id:int, name:string}>
 */
function orange_delivery_areas_storefront_payload(PDO $pdo, string $lang, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $rows = orange_delivery_areas_storefront_active_rows($pdo, $countryId);
    orange_delivery_areas_sort_rows_by_lang($rows, $lang);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => orange_delivery_area_label_from_row($row, $lang),
        ];
    }

    return $out;
}

/**
 * @param array{name_ar?:string, name_en?:string} $row
 */
function orange_delivery_area_label_from_row(array $row, string $lang): string
{
    $ar = trim((string) ($row['name_ar'] ?? ''));
    $en = trim((string) ($row['name_en'] ?? ''));
    if ($lang === 'ar') {
        return $ar !== '' ? $ar : $en;
    }

    return $en !== '' ? $en : $ar;
}

function orange_delivery_areas_compare_names(string $a, string $b, string $lang = 'ar'): int
{
    $a = trim($a);
    $b = trim($b);
    $locale = $lang === 'ar' ? 'ar' : 'en';
    if (class_exists('Collator', false)) {
        try {
            $col = new Collator($locale);
            if ($col instanceof Collator) {
                $cmp = $col->compare($a, $b);
                if (is_int($cmp)) {
                    return $cmp;
                }
            }
        } catch (Throwable $e) {
            /* fallback below */
        }
    }
    $aKey = function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower($a);
    $bKey = function_exists('mb_strtolower') ? mb_strtolower($b, 'UTF-8') : strtolower($b);

    return strcmp($aKey, $bKey);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function orange_delivery_areas_sort_rows_by_lang(array &$rows, string $lang): void
{
    $sortLang = $lang === 'ar' ? 'ar' : 'en';
    usort($rows, static function (array $a, array $b) use ($sortLang): int {
        $nameArKey = 'name_ar';
        $nameEnKey = 'name_en';
        $keyA = $sortLang === 'ar'
            ? trim((string) ($a[$nameArKey] ?? ''))
            : trim((string) ($a[$nameEnKey] ?? ''));
        $keyB = $sortLang === 'ar'
            ? trim((string) ($b[$nameArKey] ?? ''))
            : trim((string) ($b[$nameEnKey] ?? ''));
        if ($keyA === '') {
            $keyA = trim((string) ($a[$nameEnKey] ?? ''));
        }
        if ($keyB === '') {
            $keyB = trim((string) ($b[$nameEnKey] ?? ''));
        }

        return orange_delivery_areas_compare_names($keyA, $keyB, $sortLang);
    });
}

/**
 * خيارات المنطقة في الأدمن.
 *
 * @param 'supplier'|'customer' $context supplier: نشطة فقط بدون وسوم؛ customer: الكل مع وسم المعطّلة في القائمة
 * @return list<array{value:string, label:string, da_id:int, is_active:int}>
 */
function orange_delivery_areas_admin_select_options(PDO $pdo, int $countryId, string $context = 'customer'): array
{
    if ($countryId <= 0) {
        return [];
    }
    $context = $context === 'supplier' ? 'supplier' : 'customer';
    $rows = orange_delivery_areas_admin_list($pdo, $countryId);
    orange_delivery_areas_sort_rows_by_lang($rows, 'ar');
    $seen = [];
    $options = [];
    foreach ($rows as $daRow) {
        if (!is_array($daRow)) {
            continue;
        }
        $isActive = (int) ($daRow['is_active'] ?? 0) === 1;
        if ($context === 'supplier' && !$isActive) {
            continue;
        }
        $nameAr = trim((string) ($daRow['name_ar'] ?? ''));
        $nameEn = trim((string) ($daRow['name_en'] ?? ''));
        $areaValue = $nameAr !== '' ? $nameAr : $nameEn;
        if ($areaValue === '') {
            continue;
        }
        $areaKey = function_exists('mb_strtolower') ? mb_strtolower($areaValue, 'UTF-8') : strtolower($areaValue);
        if (isset($seen[$areaKey])) {
            continue;
        }
        $seen[$areaKey] = true;
        $label = $nameAr !== '' ? $nameAr : $nameEn;
        if ($context === 'customer' && !$isActive) {
            $label .= ' (غير منطقة توصيل حالياً)';
        }
        $options[] = [
            'value' => $areaValue,
            'label' => $label,
            'da_id' => (int) ($daRow['id'] ?? 0),
            'is_active' => $isActive ? 1 : 0,
        ];
    }

    return $options;
}

function orange_delivery_areas_count_active(PDO $pdo, ?int $countryId = null): int
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return 0;
    }
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    if (orange_delivery_areas_has_country_column($pdo) && $countryId > 0 && $hasGov) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id AND g.is_active = 1
             WHERE a.is_active = 1 AND a.country_id = ?'
        );
        $st->execute([$countryId]);

        return (int) $st->fetchColumn();
    }
    if (orange_delivery_areas_has_country_column($pdo) && $countryId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM delivery_areas WHERE is_active = 1 AND country_id = ?');
        $st->execute([$countryId]);

        return (int) $st->fetchColumn();
    }
    $n = $pdo->query('SELECT COUNT(*) FROM delivery_areas WHERE is_active = 1')->fetchColumn();

    return (int) $n;
}

/**
 * @return array{id?:int, name_ar:string, name_en:string, delivery_fee?:float, sort_order:int, is_active:int, governorate_id?:int}|null
 */
function orange_delivery_area_row_active(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || !orange_table_exists($pdo, 'delivery_areas')) {
        return null;
    }
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    $hasFee = orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee');
    $govSelA = $hasGov ? 'a.governorate_id' : '0 AS governorate_id';
    $govSel = $hasGov ? 'governorate_id' : '0 AS governorate_id';
    $feeSelA = $hasFee ? 'a.delivery_fee' : '0 AS delivery_fee';
    $feeSel = $hasFee ? 'delivery_fee' : '0 AS delivery_fee';
    if (orange_delivery_areas_has_country_column($pdo) && $countryId > 0 && $hasGov) {
        $st = $pdo->prepare(
            'SELECT a.id, a.name_ar, a.name_en, ' . $feeSelA . ', a.sort_order, a.is_active, ' . $govSelA . '
             FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id AND g.is_active = 1
             WHERE a.id = ? AND a.is_active = 1 AND a.country_id = ? LIMIT 1'
        );
        $st->execute([$id, $countryId]);
    } elseif (orange_delivery_areas_has_country_column($pdo) && $countryId > 0) {
        $st = $pdo->prepare(
            'SELECT id, name_ar, name_en, ' . $feeSel . ', sort_order, is_active, ' . $govSel . ' FROM delivery_areas
             WHERE id = ? AND is_active = 1 AND country_id = ? LIMIT 1'
        );
        $st->execute([$id, $countryId]);
    } else {
        $st = $pdo->prepare(
            'SELECT id, name_ar, name_en, ' . $feeSel . ', sort_order, is_active, ' . $govSel . '
             FROM delivery_areas WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $st->execute([$id]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * يطبّق سياسة س8: إن وُجدت مناطق نشطة فيُلزَم delivery_area_id ويُملأ area نصاً للعرض/الواتساب.
 * إن لم تُعرَّف مناطق (أو الجدول غير موجود) يُترك الحقل area كما أرسله العميل (نص حر).
 *
 * @param array<string, mixed> $data
 */
function orange_storefront_normalize_delivery_area_payload(PDO $pdo, array &$data, string $lang): void
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        unset($data['delivery_area_id']);
        unset($data['delivery_fee']);
        unset($data['delivery_fee_base']);
        unset($data['delivery_fee_discount']);
        unset($data['delivery_promotion_id']);

        return;
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    require_once __DIR__ . '/countries.php';
    $countryId = orange_storefront_current_country_id($pdo);
    $n = orange_delivery_areas_count_active($pdo, $countryId);
    if ($n === 0) {
        unset($data['delivery_area_id']);
        unset($data['delivery_fee']);
        unset($data['delivery_fee_base']);
        unset($data['delivery_fee_discount']);
        unset($data['delivery_promotion_id']);

        return;
    }

    $id = (int) ($data['delivery_area_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException(function_exists('t') ? t('checkout_delivery_area_required') : 'Delivery area required');
    }
    $row = orange_delivery_area_row_active($pdo, $id, $countryId);
    if ($row === null) {
        throw new RuntimeException(function_exists('t') ? t('checkout_delivery_area_required') : 'Invalid delivery area');
    }
    $data['delivery_area_id'] = $id;
    $data['area'] = orange_delivery_area_label_from_row($row, $lang);
    $buyerRegistered = !empty($data['_buyer_registered']);
    $feeBundle = orange_delivery_resolve_checkout_fee_bundle(
        $pdo,
        $id,
        $buyerRegistered,
        $countryId,
        $row
    );
    $data['delivery_fee'] = (float) ($feeBundle['fee'] ?? 0.0);
    $data['delivery_fee_base'] = (float) ($feeBundle['base_fee'] ?? 0.0);
    $data['delivery_fee_discount'] = (float) ($feeBundle['discount_fee'] ?? 0.0);
    $promo = $feeBundle['promotion'] ?? null;
    if (is_array($promo) && (int) ($promo['id'] ?? 0) > 0) {
        $data['delivery_promotion_id'] = (int) $promo['id'];
    } else {
        unset($data['delivery_promotion_id']);
    }
}

/**
 * تسجيل / تتبع: دمج delivery_area_id من الطلب مع المدخلات ثم نفس منطق normalize.
 *
 * @param array<string, mixed> $data
 * @return array{area: string, delivery_area_id: int|null}
 */
function orange_storefront_resolve_registration_area(
    PDO $pdo,
    array $data,
    string $lang,
    string $areaFallback,
    ?int $orderDeliveryAreaId
): array {
    $tmp = [
        'delivery_area_id' => (int) ($data['delivery_area_id'] ?? 0),
        'area' => trim($areaFallback),
    ];
    if ($tmp['delivery_area_id'] <= 0 && $orderDeliveryAreaId !== null && $orderDeliveryAreaId > 0) {
        $tmp['delivery_area_id'] = $orderDeliveryAreaId;
    }
    orange_storefront_normalize_delivery_area_payload($pdo, $tmp, $lang);
    require_once __DIR__ . '/countries.php';
    $countryIdReg = orange_storefront_current_country_id($pdo);
    if (orange_delivery_areas_count_active($pdo, $countryIdReg) === 0) {
        unset($tmp['delivery_area_id']);
    }
    $idOut = isset($tmp['delivery_area_id']) && (int) $tmp['delivery_area_id'] > 0 ? (int) $tmp['delivery_area_id'] : null;

    return [
        'area' => trim((string) ($tmp['area'] ?? '')),
        'delivery_area_id' => $idOut,
    ];
}

/**
 * @param list<array{governorate_id:int, governorate_name:string, areas:list<array{id:int, name:string}>}> $groups
 * @return list<array{id:int, name:string}>
 */
function orange_delivery_areas_flatten_groups(array $groups): array
{
    $out = [];
    foreach ($groups as $g) {
        foreach ($g['areas'] ?? [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $id = (int) ($a['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($a['name'] ?? ''),
            ];
        }
    }

    return $out;
}

/**
 * مجموعات محافظة → مناطق للواجهة (optgroup).
 *
 * @return list<array{governorate_id:int, governorate_name:string, areas:list<array{id:int, name:string}>}>
 */
function orange_delivery_areas_storefront_groups(PDO $pdo, string $lang, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    if ($countryId === null || $countryId <= 0) {
        require_once __DIR__ . '/countries.php';
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $rows = orange_delivery_areas_storefront_active_rows($pdo, $countryId);
    orange_delivery_areas_sort_rows_by_lang($rows, $lang);
    $areas = [];
    foreach ($rows as $row) {
        $areas[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => orange_delivery_area_label_from_row($row, $lang),
        ];
    }
    if ($areas === []) {
        return [];
    }

    return [
        [
            'governorate_id' => 0,
            'governorate_name' => '',
            'areas' => $areas,
        ],
    ];
}

/**
 * قائمة مناطق نشطة في الواجهة (س8/س20): ‎<select>‎ من الخادم — لا يعتمد على JS لاستبدال حقل نصّي.
 *
 * @param list<array{id:int,name:string}>|null $areas قيمة ‎orange_delivery_areas_storefront_payload‎ (اختياري)
 * @param list<array{governorate_id:int, governorate_name:string, areas:list<array{id:int, name:string}>}>|null $groups من ‎orange_delivery_areas_storefront_groups‎
 */
function orange_storefront_delivery_area_select_markup(
    string $elementId,
    ?array $areas = null,
    bool $required = true,
    string $nameAttr = 'area',
    string $extraClass = '',
    ?array $groups = null
): string {
    if ($groups === null && $areas !== null) {
        $groups = $areas === [] ? [] : [['governorate_id' => 0, 'governorate_name' => '', 'areas' => $areas]];
    }
    if ($groups === null || $groups === []) {
        return '';
    }
    $hasAny = false;
    foreach ($groups as $g) {
        if (!empty($g['areas'])) {
            $hasAny = true;
            break;
        }
    }
    if (!$hasAny) {
        return '';
    }
    $cls = trim($extraClass);
    $buf = '<select id="' . htmlspecialchars($elementId, ENT_QUOTES, 'UTF-8') . '"'
        . ' name="' . htmlspecialchars($nameAttr, ENT_QUOTES, 'UTF-8') . '"'
        . ' autocomplete="address-level1"';
    if ($required) {
        $buf .= ' required';
    }
    if ($cls !== '') {
        $buf .= ' class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"';
    }
    $buf .= '>';
    $ph = function_exists('t') ? (string) t('checkout_select_area') : 'Select area';
    $buf .= '<option value="">' . htmlspecialchars($ph, ENT_QUOTES, 'UTF-8') . '</option>';
    foreach ($groups as $g) {
        $groupAreas = $g['areas'] ?? [];
        if ($groupAreas === []) {
            continue;
        }
        $govName = trim((string) ($g['governorate_name'] ?? ''));
        $useOptgroup = $govName !== '' && (int) ($g['governorate_id'] ?? 0) > 0;
        if ($useOptgroup) {
            $buf .= '<optgroup label="' . htmlspecialchars($govName, ENT_QUOTES, 'UTF-8') . '">';
        }
        foreach ($groupAreas as $da) {
            $id = (int) ($da['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = (string) ($da['name'] ?? '');
            $buf .= '<option value="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        if ($useOptgroup) {
            $buf .= '</optgroup>';
        }
    }
    $buf .= '</select>';

    return $buf;
}
