<?php
declare(strict_types=1);

/**
 * Explicit translation contract for the departments prototype.
 * Legacy translate_names_from_ar_en stays unchanged for old screens.
 * A null fetch means the provider was not called. Tests inject a local fetch.
 * orange_translate_names_gtr_fetch is the seam to the current translate_names_gtr
 * service. This file does not load that library and does not open a network URL.
 */

function orange_translate_provider_code(string $locale): ?string
{
    $c = orange_content_locale_code($locale);
    if ($c === null) {
        return null;
    }
    if ($c === 'fil') {
        return 'tl';
    }
    if (in_array($c, ['ar', 'en', 'tl', 'hi'], true)) {
        return $c;
    }
    return null;
}

/**
 * Adapter to the current name translator. The host defines translate_names_gtr.
 * Calling this with the real library performs the current HTTP request, so the
 * prototype tests define a local stand-in or pass a different fetch.
 */
function orange_translate_names_gtr_fetch(string $text, string $to, string $from): string
{
    if (!function_exists('translate_names_gtr')) {
        throw new RuntimeException('translate_names_gtr_missing');
    }
    return (string) translate_names_gtr($text, $to, $from);
}

/**
 * @param callable(string,string,string):string|null $fetch text, providerTo, providerFrom
 * @return array{ok:bool,text:string,reason:string,provider_from:?string,provider_to:?string}
 */
function orange_translate_text(string $text, string $fromLocale, string $toLocale, ?callable $fetch, int $chunkLen = 1000): array
{
    $from = orange_translate_provider_code($fromLocale);
    $to = orange_translate_provider_code($toLocale);
    $fail = static function (string $reason) use ($from, $to): array {
        return [
            'ok' => false,
            'text' => '',
            'reason' => $reason,
            'provider_from' => $from,
            'provider_to' => $to,
        ];
    };
    if ($from === null || $to === null) {
        return $fail('unsupported_locale');
    }
    if (trim($text) === '') {
        return $fail('empty_source');
    }
    if ($fetch === null) {
        return $fail('provider_not_called');
    }
    $len = mb_strlen($text, 'UTF-8');
    $size = max(1, $chunkLen);
    $parts = [];
    for ($i = 0; $i < $len; $i += $size) {
        $chunk = mb_substr($text, $i, $size, 'UTF-8');
        $got = $fetch($chunk, $to, $from);
        if (!is_string($got) || trim($got) === '') {
            return $fail('provider_empty_chunk');
        }
        $parts[] = $got;
    }
    return [
        'ok' => true,
        'text' => implode('', $parts),
        'reason' => 'ok',
        'provider_from' => $from,
        'provider_to' => $to,
    ];
}
