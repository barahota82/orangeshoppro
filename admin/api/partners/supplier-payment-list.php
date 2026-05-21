<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => true, 'ids' => []]);
    }

    $countryBind = orange_gl_voucher_country_bind($pdo, 'jv');
    $st = $pdo->prepare(
        "SELECT jv.id FROM journal_vouchers jv WHERE jv.entry_type = 'supplier_payment'"
        . $countryBind['sql']
        . ' ORDER BY jv.id ASC'
    );
    $st->execute($countryBind['params']);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

    json_response(['success' => true, 'ids' => $ids]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل قائمة السندات');
}
