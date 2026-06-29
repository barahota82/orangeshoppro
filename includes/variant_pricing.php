<?php

declare(strict_types=1);

/**
 * المصدر الموثوق لسعر/تكلفة المتغيّر (قرار المالك — قسم «ثاني عشر» في مرجع السياسة):
 * السعر/التكلفة الفعّالان = COALESCE(product_variants.price/cost, products.price/cost).
 *
 * دالّتان نقيّتان (بلا أي استعلام DB) تعملان على صفوف محمّلة مسبقاً، ومتوافقتان مع
 * المخططات القديمة: إن غاب عمود المتغيّر (لا مفتاح price/cost) أو كان NULL/فارغاً
 * يُرجَع مستوى المنتج تلقائياً.
 */

if (!function_exists('orange_variant_effective_price')) {
    /**
     * @param array<string,mixed> $product صف المنتج (يجب أن يحوي price)
     * @param array<string,mixed>|null $variant صف المتغيّر (قد يحوي price)
     */
    function orange_variant_effective_price(array $product, ?array $variant): float
    {
        if (
            is_array($variant)
            && array_key_exists('price', $variant)
            && $variant['price'] !== null
            && $variant['price'] !== ''
        ) {
            return (float) $variant['price'];
        }

        return (float) ($product['price'] ?? 0);
    }
}

if (!function_exists('orange_variant_effective_cost')) {
    /**
     * @param array<string,mixed> $product صف المنتج (يجب أن يحوي cost)
     * @param array<string,mixed>|null $variant صف المتغيّر (قد يحوي cost)
     */
    function orange_variant_effective_cost(array $product, ?array $variant): float
    {
        if (
            is_array($variant)
            && array_key_exists('cost', $variant)
            && $variant['cost'] !== null
            && $variant['cost'] !== ''
        ) {
            return (float) $variant['cost'];
        }

        return (float) ($product['cost'] ?? 0);
    }
}
