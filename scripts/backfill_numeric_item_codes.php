<?php

declare(strict_types=1);

/**
 * CLI only: يعيد توليد كود الصنف الرقمي (DD SS CC UU TT NNNN) لكل المنتجات المرتبطة
 * بنوع منتج في الشجرة الموحّدة — لتحديث الأكواد القديمة (slug) دفعة واحدة بعد اعتماد
 * الترقيم الرقمي. آمن لإعادة التشغيل (يُحدّث القيمة الحالية بنفس المنطق).
 *
 * من مجلد المشروع: php scripts/backfill_numeric_item_codes.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_unified_product_helpers.php';

$pdo = db();

if (! orange_table_has_column($pdo, 'products', 'item_code')) {
    fwrite(STDERR, "products.item_code column missing.\n");
    exit(1);
}
if (! orange_table_has_column($pdo, 'products', 'product_type_id')) {
    fwrite(STDERR, "products.product_type_id column missing (unified taxonomy not ready).\n");
    exit(1);
}

$rows = $pdo->query(
    'SELECT id, product_type_id FROM products
     WHERE product_type_id IS NOT NULL AND product_type_id > 0
     ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare('UPDATE products SET item_code = ? WHERE id = ?');
$done = 0;
$skipped = 0;
foreach ($rows as $r) {
    $pid = (int) $r['id'];
    $ptId = (int) $r['product_type_id'];
    $code = orange_catalog_generate_product_item_code_from_tree($pdo, $ptId, $pid);
    if ($code === null || $code === '') {
        $skipped++;
        continue;
    }
    $upd->execute([$code, $pid]);
    $done++;
}

fwrite(STDOUT, "Numeric item codes backfilled: {$done} updated, {$skipped} skipped (unresolved tree).\n");
exit(0);
