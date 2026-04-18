<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

$allowed = ['unclassified', 'asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'];

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_has_column($pdo, 'accounts', 'account_class')) {
        json_response(['success' => false, 'message' => 'عمود تصنيف الحساب غير متوفر'], 500);
    }
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    $class = strtolower(trim((string)($data['account_class'] ?? '')));
    if ($id <= 0 || !in_array($class, $allowed, true)) {
        json_response(['success' => false, 'message' => 'معرف الحساب أو التصنيف غير صالح'], 422);
    }
    $chk = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'الحساب غير موجود'], 404);
    }
    $pdo->prepare('UPDATE accounts SET account_class = ? WHERE id = ?')->execute([$class, $id]);
    audit_log('account_class_update', 'تصنيف حساب رقم ' . $id . ' = ' . $class, 'accounts', $id);
    json_response(['success' => true, 'message' => 'تم حفظ التصنيف']);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ التصنيف');
}
