<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_table_exists($pdo, 'accounts')) {
        json_response(['success' => false, 'message' => 'جدول الحسابات غير متوفر'], 500);
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        json_response(['success' => false, 'message' => 'أدخل كود الحساب'], 422);
    }

    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $sql = 'SELECT id, name, code FROM accounts WHERE code = ?';
    if ($hasGrp) {
        $sql .= ' AND COALESCE(is_group, 0) = 0';
    }
    $sql .= ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        $msg = $hasGrp
            ? 'لا يوجد حساب فرعي بهذا الكود — الحسابات الرئيسية غير معتمدة للقيود'
            : 'لا يوجد حساب بهذا الكود';
        json_response(['success' => false, 'message' => $msg], 404);
    }

    json_response([
        'success' => true,
        'account' => [
            'id' => (int) $row['id'],
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    api_error($e, 'تعذر البحث عن الحساب');
}
