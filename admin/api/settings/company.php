<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

function req_data() {
    $data = get_json_input();
    if (is_array($data) && count($data) > 0) return $data;
    return $_POST;
}

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name_ar VARCHAR(191) NOT NULL DEFAULT '',
        company_name_en VARCHAR(191) NOT NULL DEFAULT '',
        company_logo VARCHAR(500) NOT NULL DEFAULT '',
        commercial_register VARCHAR(191) NOT NULL DEFAULT '',
        phones VARCHAR(500) NOT NULL DEFAULT '',
        address TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $data = req_data();
    $action = trim((string)($data['action'] ?? 'get'));

    if ($action === 'get') {
        $row = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1")->fetch();
        if (!$row) {
            $pdo->exec("INSERT INTO company_settings (company_name_ar, company_name_en, company_logo, commercial_register, phones, address) VALUES ('', '', '', '', '', '')");
            $row = $pdo->query("SELECT * FROM company_settings ORDER BY id ASC LIMIT 1")->fetch();
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

        $row = $pdo->query("SELECT id FROM company_settings ORDER BY id ASC LIMIT 1")->fetch();
        if ($row) {
            $stmt = $pdo->prepare("UPDATE company_settings SET company_name_ar=?, company_name_en=?, company_logo=?, commercial_register=?, phones=?, address=? WHERE id=?");
            $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address, (int)$row['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO company_settings (company_name_ar, company_name_en, company_logo, commercial_register, phones, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nameAr, $nameEn, $logo, $cr, $phones, $address]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ بيانات الشركة']);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
