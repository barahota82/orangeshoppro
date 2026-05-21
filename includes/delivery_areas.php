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
 * @return array<int, array{id:int, name_ar:string, name_en:string, sort_order:int, is_active:int, country_id?:int, governorate_id?:int, governorate_name_ar?:string, governorate_name_en?:string}>
 */
function orange_delivery_areas_admin_list(PDO $pdo, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $hasCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    if ($countryId === null && $hasCountry) {
        $countryId = orange_countries_default_id($pdo);
    }
    if ($hasCountry && $countryId !== null && $countryId > 0) {
        if ($hasGov) {
            $st = $pdo->prepare(
                'SELECT a.id, a.name_ar, a.name_en, a.sort_order, a.is_active, a.country_id, a.governorate_id,
                        g.name_ar AS governorate_name_ar, g.name_en AS governorate_name_en
                 FROM delivery_areas a
                 LEFT JOIN delivery_governorates g ON g.id = a.governorate_id
                 WHERE a.country_id = ? OR g.country_id = ?
                 ORDER BY g.sort_order ASC, g.id ASC, a.sort_order ASC, a.id ASC'
            );
            $st->execute([$countryId, $countryId]);
        } else {
            $st = $pdo->prepare(
                'SELECT id, name_ar, name_en, sort_order, is_active, country_id
                 FROM delivery_areas WHERE country_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $st->execute([$countryId]);
        }

        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    $st = $pdo->query(
        'SELECT id, name_ar, name_en, sort_order, is_active FROM delivery_areas ORDER BY sort_order ASC, id ASC'
    );

    return $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];
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
    $hasCountry = orange_delivery_areas_has_country_column($pdo);
    $hasGov = orange_delivery_areas_has_governorate_column($pdo)
        && orange_delivery_governorates_table_exists($pdo);
    if ($hasCountry && $countryId > 0 && $hasGov) {
        $st = $pdo->prepare(
            'SELECT a.id, a.name_ar, a.name_en
             FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id AND g.is_active = 1
             WHERE a.is_active = 1 AND a.country_id = ?
             ORDER BY g.sort_order ASC, g.id ASC, a.sort_order ASC, a.id ASC'
        );
        $st->execute([$countryId]);
    } elseif ($hasCountry && $countryId > 0) {
        $st = $pdo->prepare(
            'SELECT id, name_ar, name_en FROM delivery_areas WHERE is_active = 1 AND country_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $st->execute([$countryId]);
    } else {
        $st = $pdo->query(
            'SELECT id, name_ar, name_en FROM delivery_areas WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
    }
    if (!$st) {
        return [];
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $col = new Collator($locale);
        if ($col instanceof Collator) {
            $cmp = $col->compare($a, $b);
            if (is_int($cmp)) {
                return $cmp;
            }
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
 * خيارات المنطقة في الأدمن (موردين/عملاء): اسم المنطقة فقط، مرتبة أبجدياً بالعربي.
 *
 * @return list<array{value:string, label:string, da_id:int}>
 */
function orange_delivery_areas_admin_select_options(PDO $pdo, int $countryId): array
{
    if ($countryId <= 0) {
        return [];
    }
    $rows = orange_delivery_areas_admin_list($pdo, $countryId);
    orange_delivery_areas_sort_rows_by_lang($rows, 'ar');
    $seen = [];
    $options = [];
    foreach ($rows as $daRow) {
        if (!is_array($daRow)) {
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
        if ((int) ($daRow['is_active'] ?? 0) !== 1) {
            $label .= ' (غير منطقة توصيل حالياً)';
        }
        $options[] = [
            'value' => $areaValue,
            'label' => $label,
            'da_id' => (int) ($daRow['id'] ?? 0),
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
 * @return array{name_ar:string, name_en:string, sort_order:int, is_active:int}|null
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
    if (orange_delivery_areas_has_country_column($pdo) && $countryId > 0 && $hasGov) {
        $st = $pdo->prepare(
            'SELECT a.name_ar, a.name_en, a.sort_order, a.is_active
             FROM delivery_areas a
             INNER JOIN delivery_governorates g ON g.id = a.governorate_id AND g.is_active = 1
             WHERE a.id = ? AND a.is_active = 1 AND a.country_id = ? LIMIT 1'
        );
        $st->execute([$id, $countryId]);
    } elseif (orange_delivery_areas_has_country_column($pdo) && $countryId > 0) {
        $st = $pdo->prepare(
            'SELECT name_ar, name_en, sort_order, is_active FROM delivery_areas
             WHERE id = ? AND is_active = 1 AND country_id = ? LIMIT 1'
        );
        $st->execute([$id, $countryId]);
    } else {
        $st = $pdo->prepare(
            'SELECT name_ar, name_en, sort_order, is_active FROM delivery_areas WHERE id = ? AND is_active = 1 LIMIT 1'
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

        return;
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    require_once __DIR__ . '/countries.php';
    $countryId = orange_storefront_current_country_id($pdo);
    $n = orange_delivery_areas_count_active($pdo, $countryId);
    if ($n === 0) {
        unset($data['delivery_area_id']);

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
        $countryId = orange_storefront_current_country_id($pdo);
    }
    if (!orange_delivery_areas_has_governorate_column($pdo) || !orange_delivery_governorates_table_exists($pdo)) {
        $flat = orange_delivery_areas_storefront_payload($pdo, $lang, $countryId);
        if ($flat === []) {
            return [];
        }

        return [
            [
                'governorate_id' => 0,
                'governorate_name' => '',
                'areas' => $flat,
            ],
        ];
    }
    $st = $pdo->prepare(
        'SELECT g.id AS governorate_id, g.name_ar AS g_name_ar, g.name_en AS g_name_en,
                a.id AS area_id, a.name_ar AS a_name_ar, a.name_en AS a_name_en
         FROM delivery_governorates g
         INNER JOIN delivery_areas a ON a.governorate_id = g.id AND a.is_active = 1
         WHERE g.country_id = ? AND g.is_active = 1 AND a.country_id = ?
         ORDER BY g.sort_order ASC, g.id ASC, a.sort_order ASC, a.id ASC'
    );
    $st->execute([$countryId, $countryId]);
    $groups = [];
    $index = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $gid = (int) ($row['governorate_id'] ?? 0);
        if ($gid <= 0) {
            continue;
        }
        if (!isset($index[$gid])) {
            $gLabel = orange_delivery_area_label_from_row(
                ['name_ar' => (string) ($row['g_name_ar'] ?? ''), 'name_en' => (string) ($row['g_name_en'] ?? '')],
                $lang
            );
            $index[$gid] = count($groups);
            $groups[] = [
                'governorate_id' => $gid,
                'governorate_name' => $gLabel,
                'areas' => [],
            ];
        }
        $aid = (int) ($row['area_id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $groups[$index[$gid]]['areas'][] = [
            'id' => $aid,
            'name' => orange_delivery_area_label_from_row(
                ['name_ar' => (string) ($row['a_name_ar'] ?? ''), 'name_en' => (string) ($row['a_name_en'] ?? '')],
                $lang
            ),
            'name_ar' => (string) ($row['a_name_ar'] ?? ''),
            'name_en' => (string) ($row['a_name_en'] ?? ''),
        ];
    }
    $sortLang = $lang === 'ar' ? 'ar' : 'en';
    foreach ($groups as &$group) {
        if (!isset($group['areas']) || !is_array($group['areas'])) {
            continue;
        }
        usort($group['areas'], static function (array $a, array $b) use ($sortLang): int {
            $keyA = $sortLang === 'ar'
                ? trim((string) ($a['name_ar'] ?? ''))
                : trim((string) ($a['name_en'] ?? ''));
            $keyB = $sortLang === 'ar'
                ? trim((string) ($b['name_ar'] ?? ''))
                : trim((string) ($b['name_en'] ?? ''));
            if ($keyA === '') {
                $keyA = trim((string) ($a['name'] ?? ''));
            }
            if ($keyB === '') {
                $keyB = trim((string) ($b['name'] ?? ''));
            }

            return orange_delivery_areas_compare_names($keyA, $keyB, $sortLang);
        });
        foreach ($group['areas'] as &$areaRow) {
            unset($areaRow['name_ar'], $areaRow['name_en']);
        }
        unset($areaRow);
    }
    unset($group);

    return $groups;
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
