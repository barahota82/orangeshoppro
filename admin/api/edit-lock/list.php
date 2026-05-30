<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/date_format.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $docKind = trim((string) ($_GET['doc_kind'] ?? 'all'));
    $lockFilter = trim((string) ($_GET['lock_filter'] ?? 'all'));
    $dfRaw = trim((string) ($_GET['date_from'] ?? ''));
    $dtRaw = trim((string) ($_GET['date_to'] ?? ''));
    $df = $dfRaw !== '' ? (orange_normalize_admin_posted_datetime($dfRaw) ?? date('Y-m-01 00:00:00')) : date('Y-m-01 00:00:00');
    $dt = $dtRaw !== '' ? (orange_normalize_admin_posted_datetime($dtRaw) ?? date('Y-m-d 23:59:59')) : date('Y-m-d 23:59:59');
    $rows = orange_edit_lock_list($pdo, $df, $dt, $docKind !== '' ? $docKind : 'all', $lockFilter);
    $kinds = orange_edit_lock_doc_kinds();
    $kindOptions = [];
    foreach ($kinds as $code => $meta) {
        $kindOptions[] = ['code' => $code, 'label' => $meta['label_ar']];
    }
    json_response([
        'success' => true,
        'rows' => $rows,
        'kind_options' => $kindOptions,
        'filter' => [
            'doc_kind' => $docKind,
            'lock_filter' => $lockFilter,
            'date_from' => $df,
            'date_to' => $dt,
        ],
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل قائمة إقفال التعديلات');
}
