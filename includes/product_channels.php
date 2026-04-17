<?php

declare(strict_types=1);

/**
 * Link a product to every active sales channel (single-warehouse catalog visibility).
 */
function orange_product_attach_all_active_channels(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        return;
    }
    $stmt = $pdo->query('SELECT id FROM channels WHERE is_active = 1');
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!is_array($ids) || $ids === []) {
        return;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO product_channels (product_id, channel_id) VALUES (?, ?)');
    foreach ($ids as $cid) {
        $ins->execute([$productId, (int)$cid]);
    }
}
