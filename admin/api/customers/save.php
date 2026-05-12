<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
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
    $codeSql = orange_customer_normalize_code($pdo, $data['code'] ?? '');
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
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $idIn]);

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
        json_response(['success' => true, 'message' => 'تم تحديث بيانات العميل', 'id' => $id]);

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
    json_response(['success' => true, 'message' => 'تم إضافة العميل', 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ العميل');
}
