<?php

declare(strict_types=1);

function require_fields(array $data, array $keys): void
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            throw new RuntimeException('Missing field: ' . $key);
        }
    }
}

function generate_order_number(): string
{
    return 'ORD-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function clean_whatsapp_number(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);

    return $digits !== null && $digits !== '' ? $digits : '';
}

/**
 * Resolve a catalog variant row for an order line (variant_id preferred, else color/size).
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>|null
 */
function orange_order_resolve_variant_from_item(PDO $pdo, array $item): ?array
{
    $vid = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
    if ($vid > 0) {
        $vStmt = $pdo->prepare(
            'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $vStmt->execute([$vid, (int)$item['product_id']]);
        $v = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($v)) {
            return $v;
        }
    }
    $variantStmt = $pdo->prepare(
        'SELECT * FROM product_variants
        WHERE product_id = ? AND color = ? AND size = ?
        LIMIT 1'
    );
    $variantStmt->execute([
        (int)$item['product_id'],
        (string)$item['color'],
        (string)$item['size'],
    ]);
    $v = $variantStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($v)) {
        return $v;
    }
    $one = $pdo->prepare(
        'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1'
    );
    $one->execute([(int)$item['product_id']]);
    $v = $one->fetch(PDO::FETCH_ASSOC);
    return is_array($v) ? $v : null;
}