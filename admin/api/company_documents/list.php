<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/company_documents.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_company_documents')) {
        json_response(['success' => true, 'rows' => [], 'total' => 0]);
    }

    $data = get_json_input();
    $q = trim((string) ($data['q'] ?? ''));
    $docType = trim((string) ($data['doc_type'] ?? ''));
    $entityTable = trim((string) ($data['entity_table'] ?? ''));
    $limit = (int) ($data['limit'] ?? 100);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 300) {
        $limit = 300;
    }

    $sql = 'SELECT d.*, a.username AS created_by_username
            FROM orange_company_documents d
            LEFT JOIN admins a ON a.id = d.created_by_admin_id
            WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (d.title_ar LIKE ? OR d.reference_number LIKE ? OR d.notes LIKE ? OR d.original_filename LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($docType !== '') {
        $sql .= ' AND d.doc_type = ?';
        $params[] = $docType;
    }
    if ($entityTable !== '') {
        $sql .= ' AND d.entity_table = ?';
        $params[] = $entityTable;
    }

    $cntSql = 'SELECT COUNT(*) FROM orange_company_documents d WHERE 1=1';
    $cntParams = [];
    if ($q !== '') {
        $cntSql .= ' AND (d.title_ar LIKE ? OR d.reference_number LIKE ? OR d.notes LIKE ? OR d.original_filename LIKE ?)';
        $like = '%' . $q . '%';
        array_push($cntParams, $like, $like, $like, $like);
    }
    if ($docType !== '') {
        $cntSql .= ' AND d.doc_type = ?';
        $cntParams[] = $docType;
    }
    if ($entityTable !== '') {
        $cntSql .= ' AND d.entity_table = ?';
        $cntParams[] = $entityTable;
    }

    $stc = $pdo->prepare($cntSql);
    $stc->execute($cntParams);
    $total = (int) $stc->fetchColumn();

    $sql .= ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $typeLabels = orange_company_document_type_labels();
    $entityPresets = orange_company_document_entity_presets();
    foreach ($rows as &$r) {
        $dt = (string) ($r['doc_type'] ?? '');
        $r['doc_type_label'] = $typeLabels[$dt] ?? $dt;
        $et = (string) ($r['entity_table'] ?? '');
        $r['entity_label'] = $entityPresets[$et] ?? ($et !== '' ? $et : 'عام');
    }
    unset($r);

    json_response(['success' => true, 'rows' => $rows, 'total' => $total]);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] company_documents list: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'تعذر جلب المستندات'], 500);
}
