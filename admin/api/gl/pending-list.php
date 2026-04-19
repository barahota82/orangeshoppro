<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/journal_types.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $status = trim((string) ($_GET['status'] ?? 'pending'));
    $df = trim((string) ($_GET['date_from'] ?? ''));
    $dt = trim((string) ($_GET['date_to'] ?? ''));
    if ($df !== '') {
        $df = str_replace('T', ' ', $df);
    }
    if ($dt !== '') {
        $dt = str_replace('T', ' ', $dt);
    }

    $jtId = (int) ($_GET['journal_type_id'] ?? 0);
    $entryTypes = null;
    $filter = [
        'journal_type_id' => $jtId > 0 ? $jtId : null,
        'entry_type_mode' => 'none',
    ];
    if ($jtId > 0) {
        $mapped = orange_gl_entry_types_for_journal_type_id($pdo, $jtId);
        if ($mapped === []) {
            $mapped = ['__orange_unmapped_jt__'];
            $filter['entry_type_mode'] = 'unmapped_journal_type';
        } else {
            $filter['entry_type_mode'] = 'mapped';
        }
        $entryTypes = $mapped;
    }

    $rows = orange_gl_pending_list($pdo, $status, $df !== '' ? $df : null, $dt !== '' ? $dt : null, $entryTypes);
    json_response(['success' => true, 'movements' => $rows, 'status' => $status, 'filter' => $filter]);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر تحميل الحركات المعلّقة');
}
