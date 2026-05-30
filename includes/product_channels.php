<?php

declare(strict_types=1);

/**
 * Link a product to active sales channels in the same country as the product.
 */
function orange_product_attach_all_active_channels(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    require_once __DIR__ . '/catalog_schema.php';

    $countryId = 0;
    if (orange_table_has_country_id($pdo, 'products')) {
        $stP = $pdo->prepare('SELECT country_id FROM products WHERE id = ? LIMIT 1');
        $stP->execute([$productId]);
        $countryId = (int) ($stP->fetchColumn() ?: 0);
    }

    if ($countryId > 0 && orange_table_exists($pdo, 'channels') && orange_channels_has_country_column($pdo)) {
        $stmt = $pdo->prepare('SELECT id FROM channels WHERE is_active = 1 AND country_id = ?');
        $stmt->execute([$countryId]);
    } else {
        $stmt = $pdo->query('SELECT id FROM channels WHERE is_active = 1');
    }
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!is_array($ids) || $ids === []) {
        return;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO product_channels (product_id, channel_id) VALUES (?, ?)');
    foreach ($ids as $cid) {
        $ins->execute([$productId, (int) $cid]);
    }
}

/**
 * @throws RuntimeException
 */
function orange_product_assert_channel_same_country(PDO $pdo, int $productId, int $channelId): void
{
    if ($productId <= 0 || $channelId <= 0) {
        return;
    }
    require_once __DIR__ . '/countries.php';
    if (!orange_table_has_country_id($pdo, 'products') || !orange_channels_has_country_column($pdo)) {
        return;
    }
    $st = $pdo->prepare('SELECT country_id FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    $productCountryId = (int) ($st->fetchColumn() ?: 0);
    $channelCountryId = orange_country_id_for_channel($pdo, $channelId);
    if ($productCountryId > 0 && $channelCountryId > 0 && $productCountryId !== $channelCountryId) {
        throw new RuntimeException('المنتج لا يتبع دولة القناة المختارة.');
    }
}

/**
 * عند الرفع/أول طلب: يربط المنتجات النشطة بقنوات دولتها إن وُجد نقص (INSERT IGNORE — idempotent).
 */
function orange_product_channels_ensure_missing_links(PDO $pdo): void
{
    if (!function_exists('orange_table_exists')
        || !orange_table_exists($pdo, 'product_channels')
        || !orange_table_exists($pdo, 'products')
        || !orange_table_exists($pdo, 'channels')
    ) {
        return;
    }

    require_once __DIR__ . '/countries.php';

    try {
        $needsBackfill = false;
        if (orange_table_has_country_id($pdo, 'products') && orange_channels_has_country_column($pdo)) {
            $probe = $pdo->query(
                'SELECT 1
                 FROM products p
                 INNER JOIN channels c ON c.is_active = 1 AND c.country_id = p.country_id
                 WHERE p.is_active = 1 AND p.country_id IS NOT NULL AND p.country_id > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM product_channels pc
                       WHERE pc.product_id = p.id AND pc.channel_id = c.id
                   )
                 LIMIT 1'
            );
            $needsBackfill = $probe && (bool) $probe->fetchColumn();
        } else {
            $probe = $pdo->query(
                'SELECT 1
                 FROM products p
                 INNER JOIN channels c ON c.is_active = 1
                 WHERE p.is_active = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM product_channels pc
                       WHERE pc.product_id = p.id AND pc.channel_id = c.id
                   )
                 LIMIT 1'
            );
            $needsBackfill = $probe && (bool) $probe->fetchColumn();
        }

        if (!$needsBackfill) {
            return;
        }

        if (orange_table_has_country_id($pdo, 'products') && orange_channels_has_country_column($pdo)) {
            $pdo->exec(
                'INSERT IGNORE INTO product_channels (product_id, channel_id)
                 SELECT p.id, c.id
                 FROM products p
                 INNER JOIN channels c ON c.is_active = 1 AND c.country_id = p.country_id
                 WHERE p.is_active = 1 AND p.country_id IS NOT NULL AND p.country_id > 0'
            );
        } else {
            $pdo->exec(
                'INSERT IGNORE INTO product_channels (product_id, channel_id)
                 SELECT p.id, c.id
                 FROM products p
                 CROSS JOIN channels c
                 WHERE p.is_active = 1 AND c.is_active = 1'
            );
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_product_channels_ensure_missing_links: ' . $e->getMessage());
        }
    }
}
