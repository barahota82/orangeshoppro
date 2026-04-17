<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $families = $pdo->query(
        'SELECT * FROM size_families ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $sizesStmt = $pdo->query(
        'SELECT * FROM size_family_sizes ORDER BY size_family_id ASC, sort_order ASC, id ASC'
    );
    $allSizes = $sizesStmt ? $sizesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $byFamily = [];
    foreach ($allSizes as $s) {
        $fid = (int)$s['size_family_id'];
        if (!isset($byFamily[$fid])) {
            $byFamily[$fid] = [];
        }
        $byFamily[$fid][] = $s;
    }

    foreach ($families as &$f) {
        $fid = (int)$f['id'];
        $f['sizes'] = $byFamily[$fid] ?? [];
    }
    unset($f);

    json_response(['success' => true, 'families' => $families]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
