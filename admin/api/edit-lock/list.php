<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/journal_types.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    orange_journal_types_sync_canonical_defaults($pdo);

    $lockFilter = trim((string) ($_GET['lock_filter'] ?? 'all'));
    $dfRaw = trim((string) ($_GET['date_from'] ?? ''));
    $dtRaw = trim((string) ($_GET['date_to'] ?? ''));
    $df = $dfRaw !== '' ? (orange_normalize_admin_posted_datetime($dfRaw) ?? date('Y-m-01 00:00:00')) : date('Y-m-01 00:00:00');
    $dt = $dtRaw !== '' ? (orange_normalize_admin_posted_datetime($dtRaw) ?? date('Y-m-d 23:59:59')) : date('Y-m-d 23:59:59');

    $allMovements = !empty($_GET['all_movements']) || trim((string) ($_GET['doc_kind'] ?? '')) === 'all';
    $journalTypeId = (int) ($_GET['journal_type_id'] ?? 0);
    $resolved = orange_edit_lock_resolve_journal_type_filter($pdo, $journalTypeId, $allMovements);
    $entryTypes = $resolved['entry_types'];

    $rows = orange_edit_lock_list($pdo, $df, $dt, 'all', $lockFilter, $entryTypes);

    $journalTypes = [];
    foreach (orange_journal_types_list($pdo) as $jt) {
        $journalTypes[] = [
            'id' => (int) ($jt['id'] ?? 0),
            'name_ar' => trim((string) ($jt['name_ar'] ?? '')),
        ];
    }

    json_response([
        'success' => true,
        'rows' => $rows,
        'journal_types' => $journalTypes,
        'filter' => array_merge($resolved['filter'], [
            'lock_filter' => $lockFilter,
            'date_from' => $df,
            'date_to' => $dt,
            'all_movements' => $allMovements,
        ]),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل قائمة إقفال التعديلات');
}
