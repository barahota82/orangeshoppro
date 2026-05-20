<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * @param mixed $v
 */
function orange_country_api_str($v, int $max): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'countries')) {
        json_response(['success' => false, 'message' => 'جدول countries غير جاهز — شغّل ترحيل المخطط'], 422);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_countries_admin_list($pdo)]);
    }

    if ($action === 'derive') {
        $nameAr = orange_country_api_str($data['name_ar'] ?? '', 191);
        $nameEn = orange_country_api_str($data['name_en'] ?? '', 191);
        $code = orange_countries_code_for_names($nameAr, $nameEn);
        $currency = $code !== '' ? orange_countries_currency_for_code($code) : '';

        json_response([
            'success' => true,
            'code' => orange_countries_display_code($code),
            'currency_code' => $currency,
            'next_sort_order' => orange_countries_next_sort_order($pdo),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = orange_country_api_str($data['name_ar'] ?? '', 191);
        $nameEn = orange_country_api_str($data['name_en'] ?? '', 191);
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($id > 0) {
            $existing = orange_country_row_by_id($pdo, $id);
            if ($existing === null) {
                json_response(['success' => false, 'message' => 'الدولة غير موجودة'], 404);
            }
            $code = orange_countries_normalize_code((string) ($existing['code'] ?? ''));
        } else {
            $code = orange_countries_code_for_names($nameAr, $nameEn);
        }

        $currency = orange_countries_currency_for_code($code);

        if ($code === '' || strlen($code) > 8) {
            json_response([
                'success' => false,
                'message' => 'لا يوجد رمز تلقائي لهذا الاسم — استخدم اسماً معرّفاً (مثل الكويت أو مصر أو الإمارات أو السعودية أو تركيا)',
            ], 422);
        }
        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'الاسم العربي مطلوب'], 422);
        }
        if ($currency === '') {
            json_response([
                'success' => false,
                'message' => 'لا توجد عملة تلقائية لرمز الدولة — استخدم اسماً معرّفاً في السجل',
            ], 422);
        }

        if ($id > 0) {
            $dup = $pdo->prepare('SELECT id FROM countries WHERE code = ? AND id <> ? LIMIT 1');
            $dup->execute([$code, $id]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'رمز الدولة مستخدم'], 409);
            }
            $st = $pdo->prepare(
                'UPDATE countries SET code = ?, name_ar = ?, name_en = ?, currency_code = ?, is_active = ? WHERE id = ?'
            );
            $st->execute([$code, $nameAr, $nameEn, $currency, $isActive, $id]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
            $dup->execute([$code]);
            if ($dup->fetch()) {
                json_response(['success' => false, 'message' => 'رمز الدولة مستخدم'], 409);
            }
            $sortOrder = orange_countries_next_sort_order($pdo);
            $st = $pdo->prepare(
                'INSERT INTO countries (code, name_ar, name_en, currency_code, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$code, $nameAr, $nameEn, $currency, $sortOrder, $isActive]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ الدولة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الدولة');
}
