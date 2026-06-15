<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/company_settings.php';
require_once __DIR__ . '/../../../includes/storefront_payment_settings.php';
require_admin_api();

function req_data() {
    $data = get_json_input();
    if (is_array($data) && count($data) > 0) return $data;
    return $_POST;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name_ar VARCHAR(191) NOT NULL DEFAULT '',
        company_name_en VARCHAR(191) NOT NULL DEFAULT '',
        company_logo VARCHAR(500) NOT NULL DEFAULT '',
        commercial_register VARCHAR(191) NOT NULL DEFAULT '',
        phones VARCHAR(500) NOT NULL DEFAULT '',
        address TEXT NULL,
        vat_number VARCHAR(191) NOT NULL DEFAULT '',
        invoice_footer TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $data = req_data();
    $action = trim((string)($data['action'] ?? 'get'));
    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);

    if ($action === 'get') {
        $row = orange_company_settings_row($pdo, $ctxCountryId);
        if (!$row) {
            $row = orange_company_settings_ensure_row($pdo, $ctxCountryId);
        }
        json_response(['success' => true, 'data' => $row]);
    }

    if ($action === 'save') {
        $nameAr = trim((string)($data['company_name_ar'] ?? ''));
        $nameEn = trim((string)($data['company_name_en'] ?? ''));
        $logo = trim((string)($data['company_logo'] ?? ''));
        $cr = trim((string)($data['commercial_register'] ?? ''));
        $phones = trim((string)($data['phones'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $vatNumber = trim((string)($data['vat_number'] ?? ''));
        $invoiceFooter = trim((string)($data['invoice_footer'] ?? ''));
        $invoiceFooterDb = $invoiceFooter === '' ? null : $invoiceFooter;
        $paymentOnlineEnabled = (int)($data['payment_online_enabled'] ?? 0) === 1 ? 1 : 0;
        $hasPayOnlineCol = orange_company_settings_has_payment_online_column($pdo);

        orange_company_settings_ensure_row($pdo, $ctxCountryId);
        if (orange_company_settings_has_country_column($pdo)) {
            if ($hasPayOnlineCol) {
                $stmt = $pdo->prepare(
                    "UPDATE company_settings SET company_name_ar=?, company_name_en=?, company_logo=?, commercial_register=?, phones=?, address=?, vat_number=?, invoice_footer=?, payment_online_enabled=? WHERE country_id=?"
                );
                $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb, $paymentOnlineEnabled, $ctxCountryId]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE company_settings SET company_name_ar=?, company_name_en=?, company_logo=?, commercial_register=?, phones=?, address=?, vat_number=?, invoice_footer=? WHERE country_id=?"
                );
                $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb, $ctxCountryId]);
            }
        } else {
            $row = $pdo->query("SELECT id FROM company_settings ORDER BY id ASC LIMIT 1")->fetch();
            if ($row) {
                if ($hasPayOnlineCol) {
                    $stmt = $pdo->prepare("UPDATE company_settings SET company_name_ar=?, company_name_en=?, company_logo=?, commercial_register=?, phones=?, address=?, vat_number=?, invoice_footer=?, payment_online_enabled=? WHERE id=?");
                    $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb, $paymentOnlineEnabled, (int)$row['id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE company_settings SET company_name_ar=?, company_name_en=?, company_logo=?, commercial_register=?, phones=?, address=?, vat_number=?, invoice_footer=? WHERE id=?");
                    $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb, (int)$row['id']]);
                }
            } else {
                if ($hasPayOnlineCol) {
                    $stmt = $pdo->prepare("INSERT INTO company_settings (company_name_ar, company_name_en, company_logo, commercial_register, phones, address, vat_number, invoice_footer, payment_online_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb, $paymentOnlineEnabled]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO company_settings (company_name_ar, company_name_en, company_logo, commercial_register, phones, address, vat_number, invoice_footer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, $vatNumber, $invoiceFooterDb]);
                }
            }
        }

        if (array_key_exists('vat_rate', $data) && orange_table_has_column($pdo, 'company_settings', 'vat_rate')) {
            $vatRate = (float) $data['vat_rate'];
            if ($vatRate < 0 || $vatRate >= 100) {
                $vatRate = 0.0;
            }
            $vatRate = round($vatRate, 3);
            if (orange_company_settings_has_country_column($pdo)) {
                $pdo->prepare('UPDATE company_settings SET vat_rate = ? WHERE country_id = ?')
                    ->execute([$vatRate, $ctxCountryId]);
            } else {
                $rowVr = $pdo->query('SELECT id FROM company_settings ORDER BY id ASC LIMIT 1')->fetch();
                if ($rowVr) {
                    $pdo->prepare('UPDATE company_settings SET vat_rate = ? WHERE id = ?')
                        ->execute([$vatRate, (int) $rowVr['id']]);
                }
            }
        }

        if (array_key_exists('low_stock_threshold', $data) && orange_table_has_column($pdo, 'company_settings', 'low_stock_threshold')) {
            $lowTh = (int) $data['low_stock_threshold'];
            if ($lowTh < 1) {
                $lowTh = 3;
            }
            if ($lowTh > 100000) {
                $lowTh = 100000;
            }
            if (orange_company_settings_has_country_column($pdo)) {
                $pdo->prepare('UPDATE company_settings SET low_stock_threshold = ? WHERE country_id = ?')
                    ->execute([$lowTh, $ctxCountryId]);
            } else {
                $rowLt = $pdo->query('SELECT id FROM company_settings ORDER BY id ASC LIMIT 1')->fetch();
                if ($rowLt) {
                    $pdo->prepare('UPDATE company_settings SET low_stock_threshold = ? WHERE id = ?')
                        ->execute([$lowTh, (int) $rowLt['id']]);
                }
            }
        }

        if (array_key_exists('customer_low_stock_threshold', $data) && orange_table_has_column($pdo, 'company_settings', 'customer_low_stock_threshold')) {
            $custLowTh = (int) $data['customer_low_stock_threshold'];
            if ($custLowTh < 1) {
                $custLowTh = 5;
            }
            if ($custLowTh > 100000) {
                $custLowTh = 100000;
            }
            if (orange_company_settings_has_country_column($pdo)) {
                $pdo->prepare('UPDATE company_settings SET customer_low_stock_threshold = ? WHERE country_id = ?')
                    ->execute([$custLowTh, $ctxCountryId]);
            } else {
                $rowClt = $pdo->query('SELECT id FROM company_settings ORDER BY id ASC LIMIT 1')->fetch();
                if ($rowClt) {
                    $pdo->prepare('UPDATE company_settings SET customer_low_stock_threshold = ? WHERE id = ?')
                        ->execute([$custLowTh, (int) $rowClt['id']]);
                }
            }
        }

        if (orange_table_has_column($pdo, 'company_settings', 'invoice_footer_ar')) {
            $footerAr = trim((string) ($data['invoice_footer_ar'] ?? ''));
            $footerEn = trim((string) ($data['invoice_footer_en'] ?? ''));
            $footerArDb = $footerAr === '' ? null : $footerAr;
            $footerEnDb = $footerEn === '' ? null : $footerEn;
            if (orange_company_settings_has_country_column($pdo)) {
                $pdo->prepare('UPDATE company_settings SET invoice_footer_ar = ?, invoice_footer_en = ? WHERE country_id = ?')
                    ->execute([$footerArDb, $footerEnDb, $ctxCountryId]);
            } else {
                $rowF = $pdo->query('SELECT id FROM company_settings ORDER BY id ASC LIMIT 1')->fetch();
                if ($rowF) {
                    $pdo->prepare('UPDATE company_settings SET invoice_footer_ar = ?, invoice_footer_en = ? WHERE id = ?')
                        ->execute([$footerArDb, $footerEnDb, (int) $rowF['id']]);
                }
            }
        }

        json_response(['success' => true, 'message' => 'تم حفظ بيانات الشركة']);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ إعدادات الشركة');
}
