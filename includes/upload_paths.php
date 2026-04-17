<?php

declare(strict_types=1);

/**
 * Product image uploads live under project root /uploads/products (web: /uploads/products/).
 */
function orange_products_upload_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
}

/**
 * Ensure directory exists and is writable. Returns absolute path or null.
 */
function orange_ensure_products_upload_dir(): ?string
{
    $dir = orange_products_upload_dir();
    if (is_dir($dir)) {
        return is_writable($dir) ? $dir : null;
    }
    if (@mkdir($dir, 0775, true) || is_dir($dir)) {
        return is_writable($dir) ? $dir : null;
    }

    return null;
}
