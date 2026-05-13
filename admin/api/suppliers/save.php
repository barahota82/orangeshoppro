<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/phone_validation.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

function orange_supplier_next_auto_code(PDO $pdo): ?string
{
    if (!orange_table_has_column($pdo, 'suppliers', 'code')) {
        return null;
    }
    $rows = $pdo->query('SELECT code FROM suppliers WHERE code IS NOT NULL AND TRIM(code) <> \'\' ORDER BY id DESC LIMIT 5000')
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
    $chk = $pdo->prepare('SELECT id FROM suppliers WHERE code = ? LIMIT 1');
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
    if (function_exists('orange_catalog_ensure_schema_core')) {
        orange_catalog_ensure_schema_core($pdo);
    }
    if (!orange_table_exists($pdo, 'suppliers')) {
        json_response(['success' => false, 'message' => 'جدول الموردين غير متوفر'], 500);
    }
    $data = get_json_input();
    $openingBalanceProvided = is_array($data) && array_key_exists('opening_balance', $data);
    $idIn = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'message' => 'اسم المورد مطلوب'], 422);
    }
    $hasCode = orange_table_has_column($pdo, 'suppliers', 'code');
    $existingSupplierRow = null;
    if ($idIn > 0) {
        $exSupplier = $pdo->prepare('SELECT id, code FROM suppliers WHERE id = ? LIMIT 1');
        $exSupplier->execute([$idIn]);
        $existingSupplierRow = $exSupplier->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$existingSupplierRow) {
            json_response(['success' => false, 'message' => 'المورد غير موجود'], 404);
        }
    }
    $hasPayableAcc = orange_table_has_column($pdo, 'suppliers', 'payable_account_id');
    $hasStatus = orange_table_has_column($pdo, 'suppliers', 'status');
    $hasPhoneCountryDial = orange_table_has_column($pdo, 'suppliers', 'phone_country_dial');
    $hasPhoneNational = orange_table_has_column($pdo, 'suppliers', 'phone_national');
    $hasCurrencyCode = orange_table_has_column($pdo, 'suppliers', 'currency_code');
    $hasPaymentMode = orange_table_has_column($pdo, 'suppliers', 'payment_mode');
    $hasPaymentTermsDays = orange_table_has_column($pdo, 'suppliers', 'payment_terms_days');
    $hasTaxProfile = orange_table_has_column($pdo, 'suppliers', 'tax_profile');
    $hasTaxNumber = orange_table_has_column($pdo, 'suppliers', 'tax_number');
    $hasContactPerson = orange_table_has_column($pdo, 'suppliers', 'contact_person');
    $hasEmail = orange_table_has_column($pdo, 'suppliers', 'email');
    $hasCommercialReg = orange_table_has_column($pdo, 'suppliers', 'commercial_reg');
    $hasAddressLine = orange_table_has_column($pdo, 'suppliers', 'address_line');
    $hasCityArea = orange_table_has_column($pdo, 'suppliers', 'city_area');
    $hasOpeningBalance = orange_table_has_column($pdo, 'suppliers', 'opening_balance');
    $hasCreditLimit = orange_table_has_column($pdo, 'suppliers', 'credit_limit');
    $hasBankName = orange_table_has_column($pdo, 'suppliers', 'bank_name');
    $hasBankIban = orange_table_has_column($pdo, 'suppliers', 'bank_iban');
    $hasBankAccountHolder = orange_table_has_column($pdo, 'suppliers', 'bank_account_holder');
    $hasPreferredWarehouseId = orange_table_has_column($pdo, 'suppliers', 'preferred_warehouse_id');
    $hasBlockReason = orange_table_has_column($pdo, 'suppliers', 'block_reason');
    $hasAttachmentsJson = orange_table_has_column($pdo, 'suppliers', 'attachments_json');
    // سياسة الموردين: الكود يُولَّد تلقائياً فقط؛ لا نقبل إدخالاً يدوياً من الواجهة.
    $codeSql = null;
    if ($hasCode) {
        if ($idIn > 0) {
            $existingCode = trim((string) (($existingSupplierRow['code'] ?? '')));
            $codeSql = $existingCode !== '' ? $existingCode : orange_supplier_next_auto_code($pdo);
        } else {
            $codeSql = orange_supplier_next_auto_code($pdo);
        }
        if ($codeSql === null) {
            json_response(['success' => false, 'message' => 'تعذّر توليد كود المورد تلقائياً. حاول مرة أخرى.'], 500);
        }
    }
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

    $statusSql = strtolower(trim((string) ($data['status'] ?? 'active')));
    $allowedStatuses = ['active', 'inactive', 'blocked'];
    if (!in_array($statusSql, $allowedStatuses, true)) {
        json_response(['success' => false, 'message' => 'حالة المورد غير صالحة'], 422);
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

    $contactPersonSql = null;
    if ($hasContactPerson) {
        $raw = trim((string) ($data['contact_person'] ?? ''));
        $contactPersonSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 160, 'UTF-8') : substr($raw, 0, 160));
    }

    $emailSql = null;
    if ($hasEmail) {
        $rawEmail = trim((string) ($data['email'] ?? ''));
        if ($rawEmail !== '') {
            if (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
                json_response(['success' => false, 'message' => 'البريد الإلكتروني غير صالح'], 422);
            }
            $emailSql = $rawEmail;
        }
    }

    $commercialRegSql = null;
    if ($hasCommercialReg) {
        $raw = trim((string) ($data['commercial_reg'] ?? ''));
        $commercialRegSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 64, 'UTF-8') : substr($raw, 0, 64));
    }

    $addressLineSql = null;
    if ($hasAddressLine) {
        $raw = trim((string) ($data['address_line'] ?? ''));
        $addressLineSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 255, 'UTF-8') : substr($raw, 0, 255));
    }

    $cityAreaSql = null;
    if ($hasCityArea) {
        $raw = trim((string) ($data['city_area'] ?? ''));
        $cityAreaSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 160, 'UTF-8') : substr($raw, 0, 160));
    }

    $openingBalanceSql = null;
    if ($hasOpeningBalance) {
        if (!$openingBalanceProvided && $idIn > 0) {
            $obStmt = $pdo->prepare('SELECT opening_balance FROM suppliers WHERE id = ? LIMIT 1');
            $obStmt->execute([$idIn]);
            $openingBalanceSql = $obStmt->fetchColumn();
            if ($openingBalanceSql === false) {
                $openingBalanceSql = null;
            }
        } else {
            $raw = $data['opening_balance'] ?? null;
            if ($raw === '' || $raw === null) {
                $openingBalanceSql = null;
            } else {
                $val = round((float) $raw, 4);
                $openingBalanceSql = abs($val) > 0.0001 ? $val : null;
            }
        }
    }

    $creditLimitSql = null;
    if ($hasCreditLimit) {
        $raw = $data['credit_limit'] ?? null;
        if ($raw === '' || $raw === null) {
            $creditLimitSql = null;
        } else {
            $val = round((float) $raw, 4);
            if ($val < 0) {
                json_response(['success' => false, 'message' => 'الحد الائتماني يجب أن يكون رقماً موجباً أو صفراً'], 422);
            }
            $creditLimitSql = $val > 0.0001 ? $val : null;
        }
    }

    $bankNameSql = null;
    if ($hasBankName) {
        $raw = trim((string) ($data['bank_name'] ?? ''));
        $bankNameSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 160, 'UTF-8') : substr($raw, 0, 160));
    }

    $bankIbanSql = null;
    if ($hasBankIban) {
        $raw = strtoupper(trim((string) ($data['bank_iban'] ?? '')));
        $bankIbanSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 64, 'UTF-8') : substr($raw, 0, 64));
    }

    $bankAccountHolderSql = null;
    if ($hasBankAccountHolder) {
        $raw = trim((string) ($data['bank_account_holder'] ?? ''));
        $bankAccountHolderSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 160, 'UTF-8') : substr($raw, 0, 160));
    }

    $preferredWarehouseSql = null;
    if ($hasPreferredWarehouseId) {
        $whRaw = isset($data['preferred_warehouse_id']) ? (int) $data['preferred_warehouse_id'] : 0;
        $preferredWarehouseSql = $whRaw > 0 ? $whRaw : 1;
    }

    $blockReasonSql = null;
    if ($hasBlockReason) {
        $raw = trim((string) ($data['block_reason'] ?? ''));
        $blockReasonSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 255, 'UTF-8') : substr($raw, 0, 255));
        if ($statusSql !== 'blocked') {
            $blockReasonSql = null;
        }
    }
    if ($statusSql === 'blocked' && $blockReasonSql === null) {
        json_response(['success' => false, 'message' => 'سبب الحظر مطلوب عند تفعيل حالة الحظر'], 422);
    }

    $attachmentsJsonSql = null;
    if ($hasAttachmentsJson) {
        $raw = trim((string) ($data['attachments_json'] ?? ''));
        $attachmentsJsonSql = $raw === '' ? null : (function_exists('mb_substr') ? mb_substr($raw, 0, 8000, 'UTF-8') : substr($raw, 0, 8000));
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
        if ($hasStatus) {
            $fields[] = 'status = ?';
            $params[] = $statusSql;
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
        if ($hasContactPerson) {
            $fields[] = 'contact_person = ?';
            $params[] = $contactPersonSql;
        }
        if ($hasEmail) {
            $fields[] = 'email = ?';
            $params[] = $emailSql;
        }
        if ($hasCommercialReg) {
            $fields[] = 'commercial_reg = ?';
            $params[] = $commercialRegSql;
        }
        if ($hasAddressLine) {
            $fields[] = 'address_line = ?';
            $params[] = $addressLineSql;
        }
        if ($hasCityArea) {
            $fields[] = 'city_area = ?';
            $params[] = $cityAreaSql;
        }
        if ($hasOpeningBalance) {
            $fields[] = 'opening_balance = ?';
            $params[] = $openingBalanceSql;
        }
        if ($hasCreditLimit) {
            $fields[] = 'credit_limit = ?';
            $params[] = $creditLimitSql;
        }
        if ($hasBankName) {
            $fields[] = 'bank_name = ?';
            $params[] = $bankNameSql;
        }
        if ($hasBankIban) {
            $fields[] = 'bank_iban = ?';
            $params[] = $bankIbanSql;
        }
        if ($hasBankAccountHolder) {
            $fields[] = 'bank_account_holder = ?';
            $params[] = $bankAccountHolderSql;
        }
        if ($hasPreferredWarehouseId) {
            $fields[] = 'preferred_warehouse_id = ?';
            $params[] = $preferredWarehouseSql;
        }
        if ($hasBlockReason) {
            $fields[] = 'block_reason = ?';
            $params[] = $blockReasonSql;
        }
        if ($hasAttachmentsJson) {
            $fields[] = 'attachments_json = ?';
            $params[] = $attachmentsJsonSql;
        }
        $params[] = $idIn;
        $pdo->prepare('UPDATE suppliers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        audit_log('supplier_update', 'تحديث مورد #' . $idIn . ' — ' . $name, 'suppliers', $idIn);
        json_response(['success' => true, 'message' => 'تم تحديث بيانات المورد', 'id' => $idIn, 'code' => $codeSql]);

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
    if ($hasStatus) {
        $cols[] = 'status';
        $placeholders[] = '?';
        $params[] = $statusSql;
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
    if ($hasContactPerson) {
        $cols[] = 'contact_person';
        $placeholders[] = '?';
        $params[] = $contactPersonSql;
    }
    if ($hasEmail) {
        $cols[] = 'email';
        $placeholders[] = '?';
        $params[] = $emailSql;
    }
    if ($hasCommercialReg) {
        $cols[] = 'commercial_reg';
        $placeholders[] = '?';
        $params[] = $commercialRegSql;
    }
    if ($hasAddressLine) {
        $cols[] = 'address_line';
        $placeholders[] = '?';
        $params[] = $addressLineSql;
    }
    if ($hasCityArea) {
        $cols[] = 'city_area';
        $placeholders[] = '?';
        $params[] = $cityAreaSql;
    }
    if ($hasOpeningBalance) {
        $cols[] = 'opening_balance';
        $placeholders[] = '?';
        $params[] = $openingBalanceSql;
    }
    if ($hasCreditLimit) {
        $cols[] = 'credit_limit';
        $placeholders[] = '?';
        $params[] = $creditLimitSql;
    }
    if ($hasBankName) {
        $cols[] = 'bank_name';
        $placeholders[] = '?';
        $params[] = $bankNameSql;
    }
    if ($hasBankIban) {
        $cols[] = 'bank_iban';
        $placeholders[] = '?';
        $params[] = $bankIbanSql;
    }
    if ($hasBankAccountHolder) {
        $cols[] = 'bank_account_holder';
        $placeholders[] = '?';
        $params[] = $bankAccountHolderSql;
    }
    if ($hasPreferredWarehouseId) {
        $cols[] = 'preferred_warehouse_id';
        $placeholders[] = '?';
        $params[] = $preferredWarehouseSql;
    }
    if ($hasBlockReason) {
        $cols[] = 'block_reason';
        $placeholders[] = '?';
        $params[] = $blockReasonSql;
    }
    if ($hasAttachmentsJson) {
        $cols[] = 'attachments_json';
        $placeholders[] = '?';
        $params[] = $attachmentsJsonSql;
    }
    $sql = 'INSERT INTO suppliers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($params);
    $newId = (int) $pdo->lastInsertId();
    audit_log('supplier_create', 'مورد جديد: ' . $name, 'suppliers', $newId);
    json_response(['success' => true, 'message' => 'تم إضافة المورد', 'id' => $newId, 'code' => $codeSql]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ المورد');
}
