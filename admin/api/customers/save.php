<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_admin_api();

/**
 * @return string|null كود نظيف أو NULL
 */
function orange_customer_normalize_code(PDO $pdo, $raw): ?string
{
    if (!orange_table_has_column($pdo, 'customers', 'code')) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }

    return function_exists('mb_substr') ? mb_substr($s, 0, 32, 'UTF-8') : substr($s, 0, 32);
}

/**
 * س15: توليد كود عميل تسلسلي تلقائي (نفس آلية الموردين). أعلى رقم موجود في any code + 1، ثم تحقق فريد.
 */
function orange_customer_next_auto_code(PDO $pdo): ?string
{
    if (!orange_table_has_column($pdo, 'customers', 'code')) {
        return null;
    }
    $rows = $pdo->query('SELECT code FROM customers WHERE code IS NOT NULL AND TRIM(code) <> \'\' ORDER BY id DESC LIMIT 5000')
        ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $max = 0;
    foreach ($rows as $rawCode) {
        $c = trim((string) $rawCode);
        if ($c === '') {
            continue;
        }
        if (preg_match_all('/\d+/', $c, $m) && isset($m[0]) && is_array($m[0])) {
            foreach ($m[0] as $chunk) {
                $n = (int) $chunk;
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
    }
    $start = max(1, $max + 1);
    $chk = $pdo->prepare('SELECT id FROM customers WHERE code = ? LIMIT 1');
    for ($i = $start; $i < $start + 20000; $i++) {
        $candidate = (string) $i;
        $chk->execute([$candidate]);
        if (!$chk->fetchColumn()) {
            return $candidate;
        }
    }

    return null;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }
    $data = get_json_input();
    $idIn = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name_ar'] ?? ''));
    $phoneRaw = trim((string) ($data['phone'] ?? ''));
    if ($phoneRaw === '') {
        json_response(['success' => false, 'message' => 'رقم الهاتف مطلوب كمعرّف للعميل'], 422);
    }
    $admCcRaw = trim((string) ($data['phone_country'] ?? ''));
    $pcParsed = orange_storefront_parse_api_phone_country($admCcRaw);
    $dialForNational = ($pcParsed['dial'] ?? '') !== '' ? $pcParsed['dial'] : null;
    $isFullIntl = (bool) ($pcParsed['full_intl'] ?? false);
    $phone = orange_normalize_customer_phone($phoneRaw, $dialForNational, $pcParsed['full_intl']);
    if ($phone === null) {
        json_response(['success' => false, 'message' => 'رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر الدولة وأدخل الرقم الوطني (8–14 رقماً مع الكود).'], 422);
    }
    if ($name === '') {
        $name = 'عميل';
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255);

    $hasLimit = orange_table_has_column($pdo, 'customers', 'credit_limit');
    $hasNotes = orange_table_has_column($pdo, 'customers', 'notes');
    $hasCode = orange_table_has_column($pdo, 'customers', 'code');
    $hasArea = orange_table_has_column($pdo, 'customers', 'area');
    $hasAddress = orange_table_has_column($pdo, 'customers', 'address');
    $hasEmail = orange_table_has_column($pdo, 'customers', 'email');
    $hasPhoneCountryDial = orange_table_has_column($pdo, 'customers', 'phone_country_dial');
    $hasPhoneNational = orange_table_has_column($pdo, 'customers', 'phone_national');
    $hasDeliveryArea = orange_table_has_column($pdo, 'customers', 'delivery_area_id');
    $codeSql = orange_customer_normalize_code($pdo, $data['code'] ?? '');
    // س15: عند الإنشاء (id = 0) ولا كود مدخل، نولّد كوداً تلقائياً تسلسلياً.
    // عند التعديل، نحافظ على كود الصف القائم إن كان فارغاً في الطلب.
    if ($hasCode) {
        if ($idIn <= 0) {
            if ($codeSql === null) {
                $codeSql = orange_customer_next_auto_code($pdo);
                if ($codeSql === null) {
                    json_response(['success' => false, 'message' => 'تعذر توليد كود العميل التلقائي'], 500);
                }
            }
        } else {
            // تعديل: إن لم يُرسَل كود، احتفظ بالكود الحالي للعميل.
            if ($codeSql === null) {
                $exSt = $pdo->prepare('SELECT code FROM customers WHERE id = ? LIMIT 1');
                $exSt->execute([$idIn]);
                $existingCode = $exSt->fetchColumn();
                if ($existingCode !== false && $existingCode !== null && trim((string) $existingCode) !== '') {
                    $codeSql = trim((string) $existingCode);
                } else {
                    // عميل قديم بلا كود: نولّد كوداً الآن.
                    $codeSql = orange_customer_next_auto_code($pdo);
                }
            }
        }
    }
    $phoneDialSql = null;
    $phoneNationalSql = null;
    if ($hasPhoneCountryDial) {
        $phoneDialSql = $isFullIntl ? null : ($dialForNational !== null ? (string) $dialForNational : null);
    }
    if ($hasPhoneNational && !$isFullIntl) {
        $nat = preg_replace('/\D+/', '', $phoneRaw);
        $phoneNationalSql = ($nat !== null && $nat !== '') ? $nat : null;
    }

    $area = trim((string) ($data['area'] ?? ''));
    $area = function_exists('mb_substr') ? mb_substr($area, 0, 255, 'UTF-8') : substr($area, 0, 255);
    $deliveryAreaIdSql = null;
    // توحيد مع شاشة الموردين: الواجهة ترسل اسم المنطقة نصاً في `area`.
    // نحاول مطابقة الاسم بـ delivery_areas للحصول على FK (يدعم تقارير المبيعات حسب المنطقة).
    // الواجهة العامة (الأونلاين) قد ترسل `delivery_area_id` صريحاً — نحترمه أيضاً.
    if ($hasDeliveryArea) {
        if (array_key_exists('delivery_area_id', $data) && $data['delivery_area_id'] !== null && $data['delivery_area_id'] !== '') {
            $daIdRaw = (int) $data['delivery_area_id'];
            if ($daIdRaw > 0) {
                $daExists = $pdo->prepare('SELECT id, name_ar, name_en FROM delivery_areas WHERE id = ? LIMIT 1');
                $daExists->execute([$daIdRaw]);
                $daRowSql = $daExists->fetch(PDO::FETCH_ASSOC);
                if (!$daRowSql) {
                    json_response(['success' => false, 'message' => 'منطقة التوصيل غير موجودة'], 422);
                }
                $deliveryAreaIdSql = $daIdRaw;
                if ($area === '') {
                    $area = trim((string) ($daRowSql['name_ar'] ?? ''));
                    if ($area === '') {
                        $area = trim((string) ($daRowSql['name_en'] ?? ''));
                    }
                }
            }
        } elseif ($area !== '') {
            // البحث عن منطقة بنفس الاسم (name_ar أولاً ثم name_en) — تشمل المعطّلة (الأدمن قد يستخدمها لعميل تاريخي).
            $daByName = $pdo->prepare(
                'SELECT id, name_ar, name_en FROM delivery_areas
                 WHERE name_ar = ? OR name_en = ?
                 ORDER BY is_active DESC, id ASC LIMIT 1'
            );
            $daByName->execute([$area, $area]);
            $daRowByName = $daByName->fetch(PDO::FETCH_ASSOC);
            if ($daRowByName) {
                $deliveryAreaIdSql = (int) $daRowByName['id'];
            }
            // لو لم نجد منطقة بنفس الاسم: نحفظ النص فقط بدون FK (مرونة الأدمن).
        }
    }
    $address = trim((string) ($data['address'] ?? ''));
    $address = function_exists('mb_substr') ? mb_substr($address, 0, 2000, 'UTF-8') : substr($address, 0, 2000);
    $emailIn = trim((string) ($data['email'] ?? ''));
    $emailSql = null;
    if ($emailIn !== '') {
        if (!filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => 'بريد إلكتروني غير صالح'], 422);
        }
        $emailSql = $emailIn;
    }

    $creditLimitSql = null;
    if ($hasLimit && array_key_exists('credit_limit', $data)) {
        $rawLim = $data['credit_limit'];
        if ($rawLim === null || $rawLim === '') {
            $creditLimitSql = null;
        } else {
            $f = round((float) $rawLim, 4);
            $creditLimitSql = $f > 0.0001 ? $f : null;
        }
    }
    $notesRaw = trim((string) ($data['notes'] ?? ''));
    $notesSql = $notesRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($notesRaw, 0, 60000, 'UTF-8') : substr($notesRaw, 0, 60000));

    $assertCodeUnique = static function (int $excludeId) use ($pdo, $codeSql, $hasCode): void {
        if (!$hasCode || $codeSql === null) {
            return;
        }
        if ($excludeId > 0) {
            $cd = $pdo->prepare('SELECT id FROM customers WHERE code = ? AND id != ? LIMIT 1');
            $cd->execute([$codeSql, $excludeId]);
        } else {
            $cd = $pdo->prepare('SELECT id FROM customers WHERE code = ? LIMIT 1');
            $cd->execute([$codeSql]);
        }
        if ($cd->fetchColumn()) {
            json_response(['success' => false, 'message' => 'كود العميل مستخدم بالفعل'], 409);
        }
    };

    if ($idIn > 0) {
        $exRow = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $exRow->execute([$idIn]);
        if (!$exRow->fetchColumn()) {
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 404);
        }
        $dup = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND id != ? LIMIT 1');
        $dup->execute([$phone, $idIn]);
        if ($dup->fetchColumn()) {
            json_response(['success' => false, 'message' => 'هاتف مسجّل لعميل آخر'], 409);
        }
        $assertCodeUnique($idIn);

        $fields = ['name_ar = ?', 'phone = ?'];
        $params = [$name, $phone];
        if ($hasPhoneCountryDial) {
            $fields[] = 'phone_country_dial = ?';
            $params[] = $phoneDialSql;
        }
        if ($hasPhoneNational) {
            $fields[] = 'phone_national = ?';
            $params[] = $phoneNationalSql;
        }
        if ($hasArea) {
            $fields[] = 'area = ?';
            $params[] = $area;
        }
        if ($hasDeliveryArea) {
            $fields[] = 'delivery_area_id = ?';
            $params[] = $deliveryAreaIdSql;
        }
        if ($hasAddress) {
            $fields[] = 'address = ?';
            $params[] = $address;
        }
        if ($hasEmail) {
            $fields[] = 'email = ?';
            $params[] = $emailSql;
        }
        if ($hasLimit) {
            $fields[] = 'credit_limit = ?';
            $params[] = $creditLimitSql;
        }
        if ($hasNotes) {
            $fields[] = 'notes = ?';
            $params[] = $notesSql;
        }
        if ($hasCode) {
            $fields[] = 'code = ?';
            $params[] = $codeSql;
        }
        $params[] = $idIn;
        $pdo->prepare('UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        audit_log('customer_update', 'تحديث عميل #' . $idIn . ' — ' . $phone, 'customers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $idIn, 'code' => $codeSql]);

        return;
    }

    $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
    $st->execute([$phone]);
    $ex = $st->fetchColumn();
    if ($ex) {
        $id = (int) $ex;
        $assertCodeUnique($id);
        $fields = ['name_ar = ?'];
        $params = [$name];
        if ($hasPhoneCountryDial) {
            $fields[] = 'phone_country_dial = ?';
            $params[] = $phoneDialSql;
        }
        if ($hasPhoneNational) {
            $fields[] = 'phone_national = ?';
            $params[] = $phoneNationalSql;
        }
        if ($hasArea) {
            $fields[] = 'area = ?';
            $params[] = $area;
        }
        if ($hasDeliveryArea) {
            $fields[] = 'delivery_area_id = ?';
            $params[] = $deliveryAreaIdSql;
        }
        if ($hasAddress) {
            $fields[] = 'address = ?';
            $params[] = $address;
        }
        if ($hasEmail) {
            $fields[] = 'email = ?';
            $params[] = $emailSql;
        }
        if ($hasLimit && array_key_exists('credit_limit', $data)) {
            $fields[] = 'credit_limit = ?';
            $params[] = $creditLimitSql;
        }
        if ($hasNotes) {
            $fields[] = 'notes = ?';
            $params[] = $notesSql;
        }
        if ($hasCode) {
            $fields[] = 'code = ?';
            $params[] = $codeSql;
        }
        $params[] = $id;
        $pdo->prepare('UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        audit_log('customer_update', 'تحديث عميل: ' . $phone, 'customers', $id);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $id, 'code' => $codeSql]);

        return;
    }

    $assertCodeUnique(0);
    $cols = ['name_ar', 'phone'];
    $placeholders = ['?', '?'];
    $params = [$name, $phone];
    if ($hasPhoneCountryDial) {
        $cols[] = 'phone_country_dial';
        $placeholders[] = '?';
        $params[] = $phoneDialSql;
    }
    if ($hasPhoneNational) {
        $cols[] = 'phone_national';
        $placeholders[] = '?';
        $params[] = $phoneNationalSql;
    }
    if ($hasArea) {
        $cols[] = 'area';
        $placeholders[] = '?';
        $params[] = $area;
    }
    if ($hasDeliveryArea) {
        $cols[] = 'delivery_area_id';
        $placeholders[] = '?';
        $params[] = $deliveryAreaIdSql;
    }
    if ($hasAddress) {
        $cols[] = 'address';
        $placeholders[] = '?';
        $params[] = $address;
    }
    if ($hasEmail) {
        $cols[] = 'email';
        $placeholders[] = '?';
        $params[] = $emailSql;
    }
    if ($hasLimit) {
        $cols[] = 'credit_limit';
        $placeholders[] = '?';
        $params[] = $creditLimitSql;
    }
    if ($hasNotes) {
        $cols[] = 'notes';
        $placeholders[] = '?';
        $params[] = $notesSql;
    }
    if ($hasCode) {
        $cols[] = 'code';
        $placeholders[] = '?';
        $params[] = $codeSql;
    }
    $sql = 'INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($params);
    $newId = (int) $pdo->lastInsertId();
    audit_log('customer_create', 'عميل جديد: ' . $phone, 'customers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة العميل', 'id' => $newId, 'code' => $codeSql]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ العميل');
}
