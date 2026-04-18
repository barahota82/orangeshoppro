<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }
    $roots = orange_accounts_roots_ordered($pdo);
    $out = [];
    foreach ($roots as $r) {
        $row = [
            'id' => (int) $r['id'],
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'rank' => (int) $r['rank'],
            'can_delete' => !empty($r['can_delete']),
        ];
        if (array_key_exists('name_en', $r)) {
            $row['name_en'] = (string) ($r['name_en'] ?? '');
        } else {
            $row['name_en'] = '';
        }
        $out[] = $row;
    }
    json_response(['success' => true, 'roots' => $out]);
} catch (Throwable $e) {
    api_error($e, 'تعذر قراءة جذور الدليل');
}
