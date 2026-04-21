<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return array<int, array{id:int, name_ar:string, name_en:string, sort_order:int, is_active:int}>
 */
function orange_delivery_areas_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
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
function orange_delivery_areas_storefront_payload(PDO $pdo, string $lang): array
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return [];
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    $st = $pdo->query(
        'SELECT id, name_ar, name_en FROM delivery_areas WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return [];
    }
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'id' => (int) $row['id'],
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

function orange_delivery_areas_count_active(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'delivery_areas')) {
        return 0;
    }
    $n = $pdo->query('SELECT COUNT(*) FROM delivery_areas WHERE is_active = 1')->fetchColumn();

    return (int) $n;
}

/**
 * @return array{name_ar:string, name_en:string, sort_order:int, is_active:int}|null
 */
function orange_delivery_area_row_active(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !orange_table_exists($pdo, 'delivery_areas')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT name_ar, name_en, sort_order, is_active FROM delivery_areas WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $st->execute([$id]);
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
    $n = orange_delivery_areas_count_active($pdo);
    if ($n === 0) {
        unset($data['delivery_area_id']);

        return;
    }

    $id = (int) ($data['delivery_area_id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException(function_exists('t') ? t('checkout_delivery_area_required') : 'Delivery area required');
    }
    $row = orange_delivery_area_row_active($pdo, $id);
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
    if (orange_delivery_areas_count_active($pdo) === 0) {
        unset($tmp['delivery_area_id']);
    }
    $idOut = isset($tmp['delivery_area_id']) && (int) $tmp['delivery_area_id'] > 0 ? (int) $tmp['delivery_area_id'] : null;

    return [
        'area' => trim((string) ($tmp['area'] ?? '')),
        'delivery_area_id' => $idOut,
    ];
}
