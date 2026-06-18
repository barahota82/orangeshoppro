<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $moneyDecimals = (int) ((orange_admin_currency_context($pdo)['decimals'] ?? 3));

    $idFrom = (int) ($data['id_from'] ?? 0);
    $idTo = (int) ($data['id_to'] ?? 0);
    $dateFrom = trim((string) ($data['date_from'] ?? ''));
    $dateTo = trim((string) ($data['date_to'] ?? ''));

    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => true, 'results' => []]);
    }

    $where = ["v.entry_type = 'supplier_payment'"];
    $params = [];
    if ($idFrom > 0) {
        $where[] = 'v.id >= ?';
        $params[] = $idFrom;
    }
    if ($idTo > 0) {
        $where[] = 'v.id <= ?';
        $params[] = $idTo;
    }
    if ($dateFrom !== '') {
        $where[] = 'v.voucher_date >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'v.voucher_date <= ?';
        $params[] = $dateTo;
    }

    $countryBind = orange_gl_voucher_country_bind($pdo, 'v');
    if ($countryBind['sql'] !== '') {
        $where[] = ltrim($countryBind['sql'], ' AND ');
        $params = array_merge($params, $countryBind['params']);
    }

    $sql = 'SELECT v.id, v.voucher_date, v.reference, v.description,
                   (SELECT SUM(jl.debit) FROM journal_lines jl WHERE jl.voucher_id = v.id) AS total
            FROM journal_vouchers v
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY v.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id' => (int) $r['id'],
            'voucher_date' => orange_format_date_dmY((string) ($r['voucher_date'] ?? '')),
            'reference' => (string) ($r['reference'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
            'total' => round((float) ($r['total'] ?? 0), $moneyDecimals),
        ];
    }

    json_response(['success' => true, 'results' => $results]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر البحث');
}
