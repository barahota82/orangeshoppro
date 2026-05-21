<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/country_provision.php';
require_admin_api();

/**
 * @param mixed $v
 */
function orange_country_api_str($v, int $max): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/**
 * @return array<string, string>
 */
function orange_country_provision_reason_labels(): array
{
    return [
        'copied' => 'تم النسخ',
        'target_has_products' => 'الدولة لديها منتجات مسبقاً — تخطي',
        'target_has_channels' => 'الدولة لديها قنوات مسبقاً — تخطي',
        'target_has_accounts' => 'الدولة لديها دليل حسابات — تخطي',
        'target_has_gl_settings' => 'الدولة لديها إعدادات GL — تخطي',
        'source_empty' => 'مصدر الكويت فارغ',
        'same_country' => 'نفس الدولة',
        'no_source_country' => 'لا توجد دولة مصدر',
        'nothing_copied' => 'لا شيء جديد',
        'copy_failed' => 'فشل النسخ',
    ];
}

/**
 * @param array<string, mixed> $provision
 * @return list<string>
 */
function orange_country_provision_human_lines(array $provision): array
{
    $labels = orange_country_provision_reason_labels();
    $lines = [];
    if (!empty($provision['created_warehouse'])) {
        $lines[] = 'تم إنشاء المخزن الافتراضي.';
    }
    if (!empty($provision['created_channel'])) {
        $lines[] = 'تم إنشاء قناة ويب افتراضية.';
    }
    $cc = $provision['channels_copy'] ?? [];
    if (is_array($cc) && (int) ($cc['channels_copied'] ?? 0) > 0) {
        $lines[] = 'قنوات منسوخة: ' . (int) $cc['channels_copied'];
    } elseif (is_array($cc) && ($cc['reason'] ?? '') !== '') {
        $lines[] = 'القنوات: ' . ($labels[(string) $cc['reason']] ?? (string) $cc['reason']);
    }
    $cat = $provision['catalog_copy'] ?? [];
    if (is_array($cat) && (int) ($cat['products_copied'] ?? 0) > 0) {
        $lines[] = 'منتجات منسوخة: ' . (int) $cat['products_copied'];
    } elseif (is_array($cat) && ($cat['reason'] ?? '') !== '') {
        $lines[] = 'الكتalog: ' . ($labels[(string) $cat['reason']] ?? (string) $cat['reason']);
    }
    $ac = $provision['accounts_copy'] ?? [];
    if (is_array($ac) && (int) ($ac['accounts_copied'] ?? 0) > 0) {
        $lines[] = 'حسابات منسوخة: ' . (int) $ac['accounts_copied'];
    } elseif (is_array($ac) && ($ac['reason'] ?? '') !== '') {
        $lines[] = 'دليل الحسابات: ' . ($labels[(string) $ac['reason']] ?? (string) $ac['reason']);
    }
    $gl = $provision['gl_settings_copy'] ?? [];
    if (is_array($gl) && ((int) ($gl['settings_copied'] ?? 0) > 0 || (int) ($gl['alloc_copied'] ?? 0) > 0)) {
        $lines[] = 'إعدادات GL: ' . (int) ($gl['settings_copied'] ?? 0) . ' ربط، ' . (int) ($gl['alloc_copied'] ?? 0) . ' نسبة';
    } elseif (is_array($gl) && ($gl['reason'] ?? '') !== '') {
        $lines[] = 'GL: ' . ($labels[(string) $gl['reason']] ?? (string) $gl['reason']);
    }
    if (!empty($provision['created_governorate'])) {
        $lines[] = 'تم إنشاء محافظة افتراضية للتوصيل.';
    }
    if ($lines === []) {
        $lines[] = 'لا تغييرات جديدة — البيانات موجودة مسبقاً أو المصدر فارغ.';
    }

    return $lines;
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
        $rows = orange_countries_admin_list($pdo);
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (int) ($row['id'] ?? 0);
            $row['provision_status'] = orange_country_provision_status($pdo, $cid);
        }
        unset($row);
        json_response(['success' => true, 'data' => $rows]);
    }

    if ($action === 'status') {
        $countryId = (int) ($data['country_id'] ?? 0);
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'معرّف الدولة مطلوب'], 422);
        }
        json_response([
            'success' => true,
            'provision_status' => orange_country_provision_status($pdo, $countryId),
        ]);
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

    if ($action === 'provision') {
        $countryId = (int) ($data['country_id'] ?? 0);
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'معرّف الدولة مطلوب'], 422);
        }
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if ($row === null) {
            json_response(['success' => false, 'message' => 'الدولة غير موجودة'], 404);
        }
        $provision = orange_country_provision_full($pdo, $countryId);
        json_response([
            'success' => true,
            'message' => 'تمت التهيئة التشغيلية',
            'provision' => $provision,
            'provision_lines' => orange_country_provision_human_lines($provision),
            'provision_status' => orange_country_provision_status($pdo, $countryId),
        ]);
    }

    if ($action === 'create_team_user') {
        $countryId = (int) ($data['country_id'] ?? 0);
        $username = orange_country_api_str($data['username'] ?? '', 64);
        $displayName = orange_country_api_str($data['display_name'] ?? '', 191);
        $password = (string) ($data['password'] ?? '');
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'معرّف الدولة مطلوب'], 422);
        }
        $cRow = orange_country_row_by_id($pdo, $countryId, false);
        if ($cRow === null) {
            json_response(['success' => false, 'message' => 'الدولة غير موجودة'], 404);
        }
        if ($username === '') {
            json_response(['success' => false, 'message' => 'اسم المستخدم مطلوب'], 422);
        }
        if ($password === '') {
            json_response(['success' => false, 'message' => 'كلمة المرور مطلوبة'], 422);
        }
        if (!orange_table_has_column($pdo, 'admins', 'country_id')) {
            json_response(['success' => false, 'message' => 'عمود country_id غير جاهز في admins'], 422);
        }
        $chk = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        $chk->execute([$username]);
        if ($chk->fetch()) {
            json_response(['success' => false, 'message' => 'اسم المستخدم مستخدم'], 409);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $hasSuper = orange_table_has_column($pdo, 'admins', 'is_superuser');
        if ($hasSuper) {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser, country_id) VALUES (?,?,?,?,0,?)'
            )->execute([
                $username,
                $hash,
                $displayName !== '' ? $displayName : $username,
                1,
                $countryId,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO admins (username, password_hash, display_name, is_active, country_id) VALUES (?,?,?,?,?)'
            )->execute([
                $username,
                $hash,
                $displayName !== '' ? $displayName : $username,
                1,
                $countryId,
            ]);
        }
        $newId = (int) $pdo->lastInsertId();
        if (function_exists('audit_log')) {
            audit_log('admin_create', 'مستخدم فريق دولة: ' . $username, 'admins', $newId);
        }
        json_response([
            'success' => true,
            'message' => 'تم إنشاء مستخدم فريق الدولة',
            'admin_id' => $newId,
            'provision_status' => orange_country_provision_status($pdo, $countryId),
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
            $countryId = $id;
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
            $countryId = (int) $pdo->lastInsertId();
        }

        $provision = null;
        if ($isActive === 1 && $countryId > 0) {
            $provision = orange_country_provision_full($pdo, $countryId);
        }

        json_response([
            'success' => true,
            'message' => 'تم حفظ الدولة' . ($provision !== null ? ' وتهيئة البيانات التشغيلية' : ''),
            'country_id' => $countryId,
            'provision' => $provision,
            'provision_lines' => $provision !== null ? orange_country_provision_human_lines($provision) : [],
            'provision_status' => orange_country_provision_status($pdo, $countryId),
        ]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ الدولة');
}
