<?php
declare(strict_types=1);

/**
 * Department suggestion provider chain.
 * Loads translate_names_lib.php and calls orange_translate_names_gtr_result,
 * which builds the current gtx URL. The returned reason stays intact:
 * transport_failed, response_invalid, response_untranslated, or translated.
 * This file does not load config.php and does not collapse those reasons to ''.
 */

require_once __DIR__ . '/orange_translate_contract.php';

function orange_department_suggest_library_path(): string
{
    return dirname(__DIR__) . '/admin/api/lib/translate_names_lib.php';
}

/**
 * @return array{ok:bool,reason:string,text:string,provider_to:string,provider_from:string,url?:string}
 */
function orange_department_suggest_provider_fetch(string $text, string $to, string $from): array
{
    $library = orange_department_suggest_library_path();
    if (!is_file($library)) {
        return [
            'ok' => false,
            'reason' => 'transport_failed',
            'text' => '',
            'provider_to' => $to,
            'provider_from' => $from,
            'url' => '',
        ];
    }
    require_once $library;
    if (!function_exists('orange_translate_names_gtr_result')) {
        return [
            'ok' => false,
            'reason' => 'transport_failed',
            'text' => '',
            'provider_to' => $to,
            'provider_from' => $from,
            'url' => '',
        ];
    }

    return orange_translate_names_gtr_result($text, $to, $from);
}
