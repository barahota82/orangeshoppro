<?php
declare(strict_types=1);

/**
 * Department suggestion provider chain.
 * Loads the existing translate_names_lib.php, then calls translate_names_gtr
 * through orange_translate_names_gtr_fetch. Does not change those functions.
 * Does not load config.php.
 */

require_once __DIR__ . '/orange_translate_contract.php';

function orange_department_suggest_library_path(): string
{
    return dirname(__DIR__) . '/admin/api/lib/translate_names_lib.php';
}

function orange_department_suggest_provider_fetch(string $text, string $to, string $from): string
{
    $library = orange_department_suggest_library_path();
    if (!is_file($library)) {
        return '';
    }
    require_once $library;
    try {
        return orange_translate_names_gtr_fetch($text, $to, $from);
    } catch (Throwable $e) {
        return '';
    }
}
