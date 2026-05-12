<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

function orange_supplier_normalize_code(PDO $pdo, $raw): ?string
{
    if (!orange_table_has_column($pdo, 'suppliers', 'code')) {
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
    if (!orange_table_exists($pdo, 'suppliers')) {
        json_response(['success' => false, 'message' => 'جدول الموردين غير متوفر'], 500);
    }
    $data = get_json_input();
    $idIn = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم المورد مطلوب'], 422);
    }
    $hasCode = orange_table_has_column($pdo, 'suppliers', 'code');
    $hasPayableAcc = orange_table_has_column($pdo, 'suppliers', 'payable_account_id');
    $hasIsActive = orange_table_has_column($pdo, 'suppliers', 'is_active');
    $hasPhoneCountryDial = orange_table_has_column($pdo, 'suppliers', 'phone_country_dial');
    $hasPhoneNational = orange_table_has_column($pdo, 'suppliers', 'phone_national');
    $hasCurrencyCode = orange_table_has_column($pdo, 'suppliers', 'currency_code');
    $hasPaymentMode = orange_table_has_column($pdo, 'suppliers', 'payment_mode');
    $hasPaymentTermsDays = orange_table_has_column($pdo, 'suppliers', 'payment_terms_days');
    $hasTaxProfile = orange_table_has_column($pdo, 'suppliers', 'tax_profile');
    $hasTaxNumber = orange_table_has_column($pdo, 'suppliers', 'tax_number');
    $codeSql = orange_supplier_normalize_code($pdo, $data['code'] ?? '');
    $phoneRaw = trim((string) ($data['phone'] ?? ''));
    $admCcRaw = trim((string) ($data['phone_country'] ?? ''));
    $pcParsed = orange_storefront_parse_api_phone_country($admCcRaw);
    $dialForNational = ($pcParsed['dial'] ?? '') !== '' ? (string) $pcParsed['dial'] : null;
    $isFullIntl = (bool) ($pcParsed['full_intl'] ?? false);

    $phoneSql = null;
    $phoneDialSql = null;
    $phoneNationalSql = null;
    if ($phoneRaw !== '') {
        $phoneNorm = orange_normalize_customer_phone($phoneRaw, $dialForNational, $isFullIntl);
        if ($phoneNorm === null) {
            json_response([
                'success' => false,
                'message' => 'رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر الدولة وأدخل الرقم الوطني.',
            ], 422);
        }
        $phoneSql = $phoneNorm;
        if ($hasPhoneCountryDial) {
            $phoneDialSql = $isFullIntl ? null : $dialForNational;
        }
        if ($hasPhoneNational && !$isFullIntl) {
            $nat = preg_replace('/\D+/', '', $phoneRaw);
            $phoneNationalSql = $nat !== null && $nat !== '' ? $nat : null;
        }
    }
    $notesRaw = trim((string) ($data['notes'] ?? ''));
    $notesSql = $notesRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($notesRaw, 0, 255, 'UTF-8') : substr($notesRaw, 0, 255));

    $payableAccountSql = null;
    if ($hasPayableAcc) {
        $pRaw = isset($data['payable_account_id']) ? (int) $data['payable_account_id'] : 0;
        if ($pRaw <= 0) {
            json_response(['success' => false, 'message' => 'حساب ذمة المورد في الدليل إلزامي — أنشئ حساباً فرعياً تحت الخصوم واختره (لا يُستخدم حساب مجمع).'], 422);
        }
        if (!orange_accounts_account_is_posting_leaf($pdo, $pRaw)) {
            json_response(['success' => false, 'message' => 'حساب ذمة المورد يجب أن يكون حساباً فرعياً (ورقة ترحيل) في الدليل.'], 422);
        }
        $payableAccountSql = $pRaw;
    }

    $isActiveSql = 1;
    if ($hasIsActive) {
        $isActiveSql = ((int) ($data['is_active'] ?? 1) === 1) ? 1 : 0;
    }

    $currencySql = 'KWD';
    if ($hasCurrencyCode) {
        $currencyIn = strtoupper(trim((string) ($data['currency_code'] ?? 'KWD')));
        $allowedCurrencies = ['KWD', 'USD', 'SAR', 'AED', 'QAR', 'BHD', 'OMR'];
        if ($currencyIn === '' || !in_array($currencyIn, $allowedCurrencies, true)) {
            json_response(['success' => false, 'message' => 'العملة الافتراضية للمورد غير صالحة'], 422);
        }
        $currencySql = $currencyIn;
    }

    $paymentModeSql = 'cash';
    if ($hasPaymentMode) {
        $modeIn = trim((string) ($data['payment_mode'] ?? 'cash'));
        $allowedModes = ['cash', 'credit', 'transfer'];
        if (!in_array($modeIn, $allowedModes, true)) {
            json_response(['success' => false, 'message' => 'طريقة السداد الافتراضية غير صالحة'], 422);
        }
        $paymentModeSql = $modeIn;
    }

    $paymentTermsDaysSql = null;
    if ($hasPaymentTermsDays) {
        $termsRaw = $data['payment_terms_days'] ?? null;
        if ($termsRaw === '' || $termsRaw === null) {
            $paymentTermsDaysSql = null;
        } else {
            $terms = (int) $termsRaw;
            if ($terms < 0 || $terms > 3650) {
                json_response(['success' => false, 'message' => 'قيمة شروط السداد بالأيام غير صالحة'], 422);
            }
            $paymentTermsDaysSql = $terms;
        }
    }

    $taxProfileSql = 'exempt';
    if ($hasTaxProfile) {
        $taxIn = trim((string) ($data['tax_profile'] ?? 'exempt'));
        $allowedTaxProfiles = ['exempt', 'taxable', 'zero'];
        if (!in_array($taxIn, $allowedTaxProfiles, true)) {
            json_response(['success' => false, 'message' => 'المعاملة الضريبية غير صالحة'], 422);
        }
        $taxProfileSql = $taxIn;
    }

    $taxNumberSql = null;
    if ($hasTaxNumber) {
        $taxRaw = trim((string) ($data['tax_number'] ?? ''));
        $taxNumberSql = $taxRaw === '' ? null : (function_exists('mb_substr') ? mb_substr($taxRaw, 0, 64, 'UTF-8') : substr($taxRaw, 0, 64));
    }

    if ($phoneSql !== null) {
        if ($idIn > 0) {
            $dup = $pdo->prepare('SELECT id FROM suppliers WHERE phone = ? AND id != ? LIMIT 1');
            $dup->execute([$phoneSql, $idIn]);
        } else {
            $dup = $pdo->prepare('SELECT id FROM suppliers WHERE phone = ? LIMIT 1');
            $dup->execute([$phoneSql]);
        }
        if ($dup->fetchColumn()) {
            json_response(['success' => false, 'message' => 'هذا الهاتف مسجّل لمورد آخر'], 409);
        }
    }

    $assertCodeUnique = static function (int $excludeId) use ($pdo, $codeSql, $hasCode): void {
        if (!$hasCode || $codeSql === null) {
            return;
        }
        if ($excludeId > 0) {
            $cd = $pdo->prepare('SELECT id FROM suppliers WHERE code = ? AND id != ? LIMIT 1');
            $cd->execute([$codeSql, $excludeId]);
        } else {
            $cd = $pdo->prepare('SELECT id FROM suppliers WHERE code = ? LIMIT 1');
            $cd->execute([$codeSql]);
        }
        if ($cd->fetchColumn()) {
            json_response(['success' => false, 'message' => 'كود المورد مستخدم بالفعل'], 409);
        }
    };
    $assertTaxNumberUnique = static function (int $excludeId) use ($pdo, $taxNumberSql, $hasTaxNumber): void {
        if (!$hasTaxNumber || $taxNumberSql === null) {
            return;
        }
        if ($excludeId > 0) {
            $tx = $pdo->prepare('SELECT id FROM suppliers WHERE tax_number = ? AND id != ? LIMIT 1');
            $tx->execute([$taxNumberSql, $excludeId]);
        } else {
            $tx = $pdo->prepare('SELECT id FROM suppliers WHERE tax_number = ? LIMIT 1');
            $tx->execute([$taxNumberSql]);
        }
        if ($tx->fetchColumn()) {
            json_response(['success' => false, 'message' => 'الرقم الضريبي مسجّل لمورد آخر'], 409);
        }
    };

    if ($idIn > 0) {
        $exRow = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? LIMIT 1');
        $exRow->execute([$idIn]);
        if (!$exRow->fetchColumn()) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
        $assertCodeUnique($idIn);
        $assertTaxNumberUnique($idIn);

        $fields = ['name = ?', 'phone = ?', 'notes = ?'];
        $params = [$name, $phoneSql, $notesSql];
        if ($hasCode) {
            $fields[] = 'code = ?';
            $params[] = $codeSql;
        }
        if ($hasPayableAcc) {
            $fields[] = 'payable_account_id = ?';
            $params[] = $payableAccountSql;
        }
        if ($hasIsActive) {
            $fields[] = 'is_active = ?';
            $params[] = $isActiveSql;
        }
        if ($hasPhoneCountryDial) {
            $fields[] = 'phone_country_dial = ?';
            $params[] = $phoneDialSql;
        }
        if ($hasPhoneNational) {
            $fields[] = 'phone_national = ?';
            $params[] = $phoneNationalSql;
        }
        if ($hasCurrencyCode) {
            $fields[] = 'currency_code = ?';
            $params[] = $currencySql;
        }
        if ($hasPaymentMode) {
            $fields[] = 'payment_mode = ?';
            $params[] = $paymentModeSql;
        }
        if ($hasPaymentTermsDays) {
            $fields[] = 'payment_terms_days = ?';
            $params[] = $paymentTermsDaysSql;
        }
        if ($hasTaxProfile) {
            $fields[] = 'tax_profile = ?';
            $params[] = $taxProfileSql;
        }
        if ($hasTaxNumber) {
            $fields[] = 'tax_number = ?';
            $params[] = $taxNumberSql;
        }
        $params[] = $idIn;
        $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        audit_log('supplier_update', 'تحديث مورد #' . $idIn . ' — ' . $name, 'suppliers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات المورد', 'id' => $idIn]);

        return;
    }

    $assertCodeUnique(0);
    $assertTaxNumberUnique(0);

    $cols = ['name', 'phone', 'notes'];
    $placeholders = ['?', '?', '?'];
    $params = [$name, $phoneSql, $notesSql];
    if ($hasCode) {
        $cols[] = 'code';
        $placeholders[] = '?';
        $params[] = $codeSql;
    }
    if ($hasPayableAcc) {
        $cols[] = 'payable_account_id';
        $placeholders[] = '?';
        $params[] = $payableAccountSql;
    }
    if ($hasIsActive) {
        $cols[] = 'is_active';
        $placeholders[] = '?';
        $params[] = $isActiveSql;
    }
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
    if ($hasCurrencyCode) {
        $cols[] = 'currency_code';
        $placeholders[] = '?';
        $params[] = $currencySql;
    }
    if ($hasPaymentMode) {
        $cols[] = 'payment_mode';
        $placeholders[] = '?';
        $params[] = $paymentModeSql;
    }
    if ($hasPaymentTermsDays) {
        $cols[] = 'payment_terms_days';
        $placeholders[] = '?';
        $params[] = $paymentTermsDaysSql;
    }
    if ($hasTaxProfile) {
        $cols[] = 'tax_profile';
        $placeholders[] = '?';
        $params[] = $taxProfileSql;
    }
    if ($hasTaxNumber) {
        $cols[] = 'tax_number';
        $placeholders[] = '?';
        $params[] = $taxNumberSql;
    }
    $sql = 'INSERT INTO suppliers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($params);
    $newId = (int) $pdo->lastInsertId();
    audit_log('supplier_create', 'مورد جديد: ' . $name, 'suppliers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة المورد', 'id' => $newId]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المورد');
}
