<?php
declare(strict_types=1);

/**
 * Explicit translation contract for the departments prototype.
 * Legacy translate_names_from_ar_en stays unchanged for old screens.
 * A null fetch means the provider was not called. Tests inject a local fetch.
 * orange_translate_names_gtr_fetch is the seam to the current translate_names_gtr
 * service. This file does not load that library and does not open a network URL.
 *
 * local_rejected: the code is absent from the documented provider map. No connection.
 * transport_failed: the fetch threw, returned no body, or the library is missing.
 * response_invalid: a body arrived and it is not the gtx translation array.
 * response_untranslated: the gtx array arrived and its translation string is empty.
 * service_unsupported is reserved for a body that actually says the language is
 * unsupported. The current gtx body does not carry that signal, so the caller
 * above does not emit it.
 */

require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_language_catalog_data.php';

/**
 * Documented targets for the current gtx endpoint.
 * fil is stored as fil and sent as tl. zh is sent as zh-CN.
 * A code outside this map is rejected locally. That is not a service limit.
 *
 * @return array<string,string>
 */
function orange_translate_provider_map(): array
{
    return orange_language_catalog_provider_map();
}

function orange_translate_provider_code(string $locale): ?string
{
    $c = orange_content_locale_code($locale);
    if ($c === null) {
        return null;
    }
    $map = orange_translate_provider_map();

    return $map[$c] ?? null;
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
        return $fail('local_rejected');
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
        try {
            $got = $fetch($chunk, $to, $from);
        } catch (Throwable $e) {
            unset($e);

            return $fail('transport_failed');
        }
        if (is_array($got)) {
            $reason = (string) ($got['reason'] ?? '');
            if ($reason === 'transport_failed' || $reason === 'response_invalid' || $reason === 'response_untranslated' || $reason === 'service_unsupported' || $reason === 'local_rejected') {
                return $fail($reason);
            }
            $piece = trim((string) ($got['text'] ?? ''));
            if ($piece === '') {
                return $fail($reason !== '' ? $reason : 'provider_empty_chunk');
            }
            $parts[] = $piece;
            continue;
        }
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
