<?php
declare(strict_types=1);

/**
 * Extra storefront packs merged into get_translations().
 * A file name is the locale code. There is no per-language condition.
 * A pack that does not cover every required English key is not offered.
 * includes/i18n/catalogs is not loaded here.
 */

function orange_storefront_ui_pack_directory(): string
{
    return __DIR__ . '/storefront_ui_packs';
}

/**
 * @return array<string,array<string,mixed>>
 */
function orange_storefront_ui_load_pack_files(): array
{
    $dir = orange_storefront_ui_pack_directory();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.php') ?: [];
    sort($files, SORT_STRING);
    $out = [];
    foreach ($files as $file) {
        $code = strtolower(basename($file, '.php'));
        if (!preg_match('/^[a-z]{2,3}$/', $code)) {
            continue;
        }
        $pack = require $file;
        if (!is_array($pack)) {
            continue;
        }
        $out[$code] = $pack;
    }

    return $out;
}

/**
 * @param array<string,array<string,mixed>> $builtin
 * @return array<string,array<string,mixed>>
 */
function orange_storefront_ui_merge_packs(array $builtin): array
{
    foreach (orange_storefront_ui_load_pack_files() as $code => $pack) {
        if (!isset($builtin[$code])) {
            $builtin[$code] = $pack;
        }
    }

    return $builtin;
}
