<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

/**
 * بحث عن متغيرات منتجات نشطة لمنتقي عروض السلة في الأدمن (إعدادات / واجهة).
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $raw = get_json_input();
    if (!is_array($raw) || count($raw) === 0) {
        $raw = $_POST;
    }
    $q = trim((string) ($raw['q'] ?? ''));
    $limit = (int) ($raw['limit'] ?? 80);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 120) {
        $limit = 120;
    }

    $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, pv.stock_quantity,
                   p.id AS product_id, p.name AS product_name, p.name_en AS product_name_en
            FROM product_variants pv
            INNER JOIN products p ON p.id = pv.product_id
            WHERE p.is_active = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (
            p.name LIKE ? OR p.name_en LIKE ?
            OR CAST(pv.id AS CHAR) = ? OR CAST(p.id AS CHAR) = ?
            OR CONCAT(pv.color, \' \', pv.size) LIKE ?
        )';
        $like = '%' . $q . '%';
        $params = [$like, $like, $q, $q, $like];
    }
    $sql .= ' ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC LIMIT ' . (string) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'variant_id' => (int) ($r['variant_id'] ?? 0),
            'product_id' => (int) ($r['product_id'] ?? 0),
            'product_name' => (string) ($r['product_name'] ?? ''),
            'product_name_en' => (string) ($r['product_name_en'] ?? ''),
            'color' => (string) ($r['color'] ?? ''),
            'size' => (string) ($r['size'] ?? ''),
            'stock_quantity' => (int) ($r['stock_quantity'] ?? 0),
        ];
    }

    json_response(['success' => true, 'variants' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر البحث عن المتغيرات');
}
