<?php

declare(strict_types=1);

/**
 * Stable GL line identity for order_items (Phase 1B — INV-C delivery slots).
 */

require_once __DIR__ . '/catalog_schema.php';

function orange_order_item_gl_slot_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'order_items')
        && orange_table_has_column($pdo, 'order_items', 'gl_slot');
}

/**
 * Next monotonic gl_slot for a new line on this order (never reuses retired values).
 */
function orange_order_item_allocate_gl_slot(PDO $pdo, int $orderId): int
{
    if ($orderId <= 0) {
        throw new InvalidArgumentException('معرف الطلب غير صالح.');
    }
    orange_catalog_ensure_schema($pdo);
    if (!orange_order_item_gl_slot_ready($pdo)) {
        throw new RuntimeException('عمود gl_slot غير جاهز في order_items.');
    }
    $st = $pdo->prepare('SELECT COALESCE(MAX(gl_slot), 0) FROM order_items WHERE order_id = ? FOR UPDATE');
    $st->execute([$orderId]);

    return (int) $st->fetchColumn() + 1;
}

/**
 * @throws InvalidArgumentException
 */
function orange_order_item_assert_gl_slot(int $glSlot): void
{
    if ($glSlot <= 0) {
        throw new InvalidArgumentException('gl_slot غير صالح.');
    }
}

/**
 * @return list<int>
 */
function orange_order_item_active_gl_slots(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0 || !orange_order_item_gl_slot_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT gl_slot FROM order_items WHERE order_id = ? AND gl_slot > 0 ORDER BY gl_slot ASC'
    );
    $st->execute([$orderId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
        $n = (int) $raw;
        if ($n > 0) {
            $out[] = $n;
        }
    }

    return $out;
}

/**
 * Map legacy order_item.id → gl_slot for adoption bridge (current rows only).
 *
 * @return array<int, int> itemId => glSlot
 */
function orange_order_item_gl_slot_map_by_item_id(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0 || !orange_order_item_gl_slot_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, gl_slot FROM order_items WHERE order_id = ? AND gl_slot > 0');
    $st->execute([$orderId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $glSlot = (int) ($row['gl_slot'] ?? 0);
        if ($itemId > 0 && $glSlot > 0) {
            $map[$itemId] = $glSlot;
        }
    }

    return $map;
}
