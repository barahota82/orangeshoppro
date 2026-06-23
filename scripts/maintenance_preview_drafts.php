<?php

declare(strict_types=1);

/**
 * صيانة اختيارية: حذف صفوف معاينة المنتج (الظِلّ/المسودّة) المنتهية صلاحيتها.
 * المرجع: docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt (P8 — التنظيف).
 *
 * التنظيف الأساسي يجري lazy عند فتح معاينة جديدة (حذف مسوّدات الأدمن السابقة).
 * هذا السكربت احتياطي لتنظيف ما تبقّى منتهياً عبر كل الأدمن (تشغيل يدوي/مجدول).
 *
 * الاستخدام:  php scripts/maintenance_preview_drafts.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/product_preview.php';

$pdo = db();

if (! orange_table_has_column($pdo, 'products', 'is_preview_draft')) {
    fwrite(STDOUT, "أعمدة المعاينة غير موجودة — لا شيء للتنظيف.\n");

    return;
}

try {
    $st = $pdo->query(
        'SELECT id FROM products
         WHERE is_preview_draft = 1
           AND preview_expires_at IS NOT NULL AND preview_expires_at <= NOW()'
    );
    $ids = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    $count = 0;
    foreach ($ids as $pid) {
        orange_preview_delete_draft_row($pdo, (int) $pid);
        $count++;
    }
    fwrite(STDOUT, 'تم حذف ' . $count . " صفّ معاينة منتهٍ.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'خطأ أثناء التنظيف: ' . $e->getMessage() . "\n");
}
