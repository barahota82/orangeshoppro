<?php

declare(strict_types=1);

/**
 * CLI: يملأ عمود reference لحركات stock_movements (delivered_order و order_return)
 * عندما يكون سطر الطلب المطابق (منتج + متغيّر + كمية) فريداً عبر الطلبات.
 *
 * الاستخدام من جذر المشروع:
 *   php scripts/backfill_delivered_order_reference.php
 *   php scripts/backfill_delivered_order_reference.php --dry-run
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/order_stock.php';

/**
 * @return non-empty-string|null
 */
function orange_backfill_resolve_order_number_for_stock_line(PDO $pdo, array $sm): ?string
{
    $pid = (int) ($sm['product_id'] ?? 0);
    $vid = (int) ($sm['variant_id'] ?? 0);
    $qty = (int) ($sm['qty'] ?? 0);
    if ($pid <= 0 || $qty <= 0) {
        return null;
    }

    if ($vid > 0) {
        $st = $pdo->prepare(
            'SELECT o.order_number
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = ? AND oi.variant_id = ? AND oi.qty = ?
               AND o.order_number IS NOT NULL AND TRIM(o.order_number) != \'\' 
             GROUP BY o.id, o.order_number'
        );
        $st->execute([$pid, $vid, $qty]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return count($rows) === 1 ? trim((string) $rows[0]['order_number']) : null;
    }

    $st = $pdo->prepare(
        'SELECT o.order_number
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE oi.product_id = ? AND oi.qty = ?
           AND (oi.variant_id IS NULL OR oi.variant_id = 0)
           AND o.order_number IS NOT NULL AND TRIM(o.order_number) != \'\' 
         GROUP BY o.id, o.order_number'
    );
    $st->execute([$pid, $qty]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return count($rows) === 1 ? trim((string) $rows[0]['order_number']) : null;
}

$dryRun = in_array('--dry-run', $argv, true);

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!orange_table_has_column($pdo, 'stock_movements', 'reference')) {
    fwrite(STDERR, "عمود reference غير موجود في stock_movements.\n");
    exit(1);
}

$q = $pdo->query(
    "SELECT id, product_id, variant_id, qty, created_at, type
     FROM stock_movements
     WHERE type IN ('delivered_order', 'order_return')
       AND (reference IS NULL OR TRIM(reference) = '')"
);
$rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
if ($rows === []) {
    fwrite(STDOUT, "لا توجد حركات delivered_order / order_return بلا مرجع.\n");
    exit(0);
}

$updated = 0;
$skipped = 0;

$upd = $pdo->prepare(
    "UPDATE stock_movements SET reference = ? WHERE id = ? AND type IN ('delivered_order', 'order_return')"
);

foreach ($rows as $sm) {
    $orderNumber = orange_backfill_resolve_order_number_for_stock_line($pdo, $sm);
    if ($orderNumber === null || $orderNumber === '') {
        ++$skipped;
        fwrite(STDOUT, 'تخطي sm.id=' . (int) $sm['id'] . ' type=' . (string) ($sm['type'] ?? '')
            . " — تطابق الطلب ليس فريداً أو غير موجود.\n");
        continue;
    }
    $ref = orange_order_stock_reference($orderNumber);
    if ($dryRun) {
        fwrite(STDOUT, '[dry-run] sm.id=' . (int) $sm['id'] . ' type=' . (string) ($sm['type'] ?? '')
            . ' => reference=' . $ref . "\n");
    } else {
        $upd->execute([$ref, (int) $sm['id']]);
        if ($upd->rowCount() === 1) {
            ++$updated;
        } else {
            ++$skipped;
        }
    }
}

if ($dryRun) {
    fwrite(STDOUT, 'وضع تجريبي: لم يُحفظ شيء. عدد الصفوف المرشحة: ' . count($rows) . "\n");
} else {
    fwrite(STDOUT, "تم تحديث المرجع لـ {$updated} حركة، وتخطي {$skipped}.\n");
}

exit(0);
