<?php

declare(strict_types=1);

/**
 * Disposable test fixture only. Production Brand Identity must call the real
 * storefront_lang_options() from config.php — never this file.
 */
if (!function_exists('storefront_lang_options')) {
    function storefront_lang_options(): array
    {
        return [
            'ar' => ['label' => 'العربية'],
            'en' => ['label' => 'English'],
            'fil' => ['label' => 'Filipino'],
            'hi' => ['label' => 'हिन्दी'],
        ];
    }
}
