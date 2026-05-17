<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => true, 'ids' => []]);
    }

    $st = $pdo->prepare(
        "SELECT id FROM journal_vouchers WHERE entry_type = 'supplier_payment' ORDER BY id ASC"
    );
    $st->execute();
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

    json_response(['success' => true, 'ids' => $ids]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل قائمة السندات');
}
