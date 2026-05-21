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
