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
 * Sales payment mode for orders: cash (نقدي), credit (آجل), or online (أونلاين).
 */
function orange_normalize_payment_terms(mixed $raw): string
{
    $v = strtolower(trim((string) $raw));
    if ($v === 'credit') {
        return 'credit';
    }
    if ($v === 'online') {
        return 'online';
    }

    return 'cash';
}

/**
 * تسمية عربية لنوع بيع الطلب (نقدي / آجل / أونلاين) للواجهات.
 */
function orange_order_payment_terms_label_ar(mixed $raw): string
{
    $pt = orange_normalize_payment_terms($raw);
    if ($pt === 'credit') {
        return 'آجل';
    }
    if ($pt === 'online') {
        return 'أونلاين';
    }

    return 'نقدي';
}

/**
 * Resolve a catalog variant row for an order line (variant_id preferred, else color/size).
 *
 * @param array<string,mixed> $item
 * @return array<string,mixed>|null
 */
function orange_order_resolve_variant_from_item(PDO $pdo, array $item): ?array
{
    $pid = isset($item['product_id']) ? (int) $item['product_id'] : 0;
    if ($pid <= 0) {
        return null;
    }

    $vid = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
    if ($vid > 0) {
        $vStmt = $pdo->prepare(
            'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $vStmt->execute([$vid, $pid]);
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
        $pid,
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
    $one->execute([$pid]);
    $v = $one->fetch(PDO::FETCH_ASSOC);
    return is_array($v) ? $v : null;
}

/**
 * خصم البند بالدينار (لا يتجاوز إجمالي السطر قبل الخصم).
 *
 * @param array<string, mixed> $item
 */
function orange_order_item_line_discount(array $item): float
{
    $v = isset($item['line_discount']) ? (float) $item['line_discount'] : 0.0;

    return round(max(0.0, $v), 4);
}

/**
 * صافي سطر الطلب بعد الخصم.
 *
 * @param array<string, mixed> $item
 */
function orange_order_item_line_net(array $item): float
{
    $qty = (int) ($item['qty'] ?? 0);
    $gross = round((float) ($item['price'] ?? 0) * $qty, 4);
    $disc = orange_order_item_line_discount($item);
    if ($disc > $gross + 0.0001) {
        return 0.0;
    }

    return max(0.0, round($gross - $disc, 4));
}