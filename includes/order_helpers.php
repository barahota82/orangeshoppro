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