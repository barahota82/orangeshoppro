<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_admin_api();

$catalog = orange_invoice_ancillary_sales_line_kind_catalog();
$out = [];
foreach ($catalog as $key => $meta) {
    $out[] = [
        'key' => $key,
        'label_ar' => (string) ($meta['label_ar'] ?? $key),
        'side' => (string) ($meta['side'] ?? ''),
    ];
}

json_response(['success' => true, 'line_kinds' => $out]);
