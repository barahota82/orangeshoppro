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

    $flat = orange_accounts_flat($pdo);
    $tree = orange_accounts_build_tree($flat);
    $firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;

    ob_start();
    if ($tree === []) {
        echo '<p class="muted">لا توجد حسابات بعد. افتح «إعداد الدليل» أو اضغط «إضافة» ثم احفظ.</p>';
    } else {
        orange_render_coa_tree($tree, 0, $flat, 0);
    }
    $html = ob_get_clean();

    json_response([
        'success' => true,
        'html' => $html,
        'first_id' => $firstId,
    ]);
} catch (Throwable $e) {
    api_error($e, 'تعذر بناء شجرة الحسابات');
}
