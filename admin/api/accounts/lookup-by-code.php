<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
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

    if (orange_accounts_code_is_first_level_root($code)) {
        $maxRoot = orange_accounts_code_first_level_max_numeric();
        $minSub = orange_accounts_code_min_posting_numeric();
        json_response([
            'success' => false,
            'message' => 'أكواد المستوى الأول (1–' . $maxRoot . ') غير معتمدة للقيود — استخدم حساباً فرعياً من كود ' . $minSub . ' فما فوق.',
        ], 404);
    }

    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $sql = 'SELECT a.id, a.name, a.code FROM accounts a WHERE a.code = ? AND ' . orange_accounts_posting_leaf_where_sql($pdo, 'a') . ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        $msg = $hasPar
            ? 'لا يوجد حساب فرعي (ورقة ترحيل) بهذا الكود — الجذور ومجلدات الدليل غير معتمدة للقيود'
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
