<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * @param mixed $v
 */
function da_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'delivery_areas')) {
        json_response(['success' => false, 'message' => 'جدول delivery_areas غير جاهز'], 422);
    }

    $countryId = (int) ($data['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }

    if ($action === 'list_governorates') {
        json_response([
            'success' => true,
            'data' => orange_delivery_governorates_admin_list($pdo, $countryId),
        ]);
    }

    if ($action === 'save_governorate') {
        if (!orange_delivery_governorates_table_exists($pdo)) {
            json_response(['success' => false, 'message' => 'جدول المحافظات غير جاهز'], 422);
        }
        $id = (int) ($data['id'] ?? 0);
        $nameAr = da_str191($data['name_ar'] ?? '');
        $nameEn = da_str191($data['name_en'] ?? '');
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المحافظة بالعربي مطلوب'], 422);
        }
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }

        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE delivery_governorates SET name_ar = ?, name_en = ?, is_active = ?, country_id = ? WHERE id = ?'
            );
            $st->execute([$nameAr, $nameEn, $isActive, $countryId, $id]);
        } else {
            $sortOrder = orange_delivery_governorates_next_sort_order($pdo, $countryId);
            $st = $pdo->prepare(
                'INSERT INTO delivery_governorates (country_id, name_ar, name_en, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$countryId, $nameAr, $nameEn, $sortOrder, $isActive]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ المحافظة']);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_delivery_areas_admin_list($pdo, $countryId)]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $governorateId = (int) ($data['governorate_id'] ?? 0);
        $nameAr = da_str191($data['name_ar'] ?? '');
        $nameEn = da_str191($data['name_en'] ?? '');
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المنطقة بالعربي مطلوب'], 422);
        }
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }
        $hasGovCol = orange_delivery_areas_has_governorate_column($pdo);
        if ($hasGovCol && $governorateId <= 0) {
            json_response(['success' => false, 'message' => 'اختر المحافظة'], 422);
        }
        if ($hasGovCol && $governorateId > 0) {
            $gv = $pdo->prepare('SELECT id FROM delivery_governorates WHERE id = ? AND country_id = ? LIMIT 1');
            $gv->execute([$governorateId, $countryId]);
            if (!$gv->fetch()) {
                json_response(['success' => false, 'message' => 'المحافظة غير موجودة لهذه الدولة'], 422);
            }
        }

        $hasCountryCol = orange_delivery_areas_has_country_column($pdo);

        if ($id > 0) {
            if ($hasCountryCol && $hasGovCol) {
                $st = $pdo->prepare(
                    'UPDATE delivery_areas SET name_ar = ?, name_en = ?, is_active = ?, country_id = ?, governorate_id = ? WHERE id = ?'
                );
                $st->execute([$nameAr, $nameEn, $isActive, $countryId, $governorateId, $id]);
            } elseif ($hasCountryCol) {
                $st = $pdo->prepare(
                    'UPDATE delivery_areas SET name_ar = ?, name_en = ?, is_active = ?, country_id = ? WHERE id = ?'
                );
                $st->execute([$nameAr, $nameEn, $isActive, $countryId, $id]);
            } else {
                $st = $pdo->prepare(
                    'UPDATE delivery_areas SET name_ar = ?, name_en = ?, is_active = ? WHERE id = ?'
                );
                $st->execute([$nameAr, $nameEn, $isActive, $id]);
            }
        } else {
            $sortOrder = orange_delivery_areas_next_sort_order($pdo, $countryId, $governorateId);
            if ($hasCountryCol && $hasGovCol) {
                $st = $pdo->prepare(
                    'INSERT INTO delivery_areas (name_ar, name_en, sort_order, is_active, country_id, governorate_id) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $st->execute([$nameAr, $nameEn, $sortOrder, $isActive, $countryId, $governorateId]);
            } elseif ($hasCountryCol) {
                $st = $pdo->prepare(
                    'INSERT INTO delivery_areas (name_ar, name_en, sort_order, is_active, country_id) VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([$nameAr, $nameEn, $sortOrder, $isActive, $countryId]);
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO delivery_areas (name_ar, name_en, sort_order, is_active) VALUES (?, ?, ?, ?)'
                );
                $st->execute([$nameAr, $nameEn, $sortOrder, $isActive]);
            }
        }

        json_response(['success' => true, 'message' => 'تم حفظ المنطقة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ بيانات المناطق');
}
