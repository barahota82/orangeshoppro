<?php

/**
 * Shared helpers for admin multilingual name auto-translation (AR to EN to tl/hi).
 * Loaded by translate API endpoints only; does not bootstrap the app.
 */

if (!function_exists('translate_names_fetch_url')) {
    function translate_names_fetch_url($url)
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        $res = curl_exec($ch);
        curl_close($ch);
        return is_string($res) ? $res : '';
    }
}

if (!function_exists('translate_names_gtr')) {
    function translate_names_gtr($text, $to, $from = 'auto')
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl='
            . rawurlencode($from) . '&tl=' . rawurlencode($to) . '&dt=t&q=' . rawurlencode($text);
        $raw = translate_names_fetch_url($url);
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j[0]) || !is_array($j[0])) {
            return '';
        }
        $out = '';
        foreach ($j[0] as $p) {
            if (is_array($p) && isset($p[0])) {
                $out .= (string) $p[0];
            }
        }
        return trim($out);
    }
}

if (!function_exists('translate_names_from_ar_en')) {
    /**
     * @param string $nameAr Arabic name (optional if $nameEn is set)
     * @param string $nameEn English name; if empty and $nameAr set, EN is translated from AR.
     *        If EN is non-empty, it is kept and Filipino/Hindi are derived from EN (fix literal EN).
     */
    function translate_names_from_ar_en($nameAr, $nameEn)
    {
        $nameAr = trim((string) $nameAr);
        $nameEn = trim((string) $nameEn);
        if ($nameEn === '' && $nameAr !== '') {
            $nameEn = translate_names_gtr($nameAr, 'en', 'ar');
        }
        if ($nameEn === '') {
            $nameEn = $nameAr;
        }
        $nameFil = translate_names_gtr($nameEn, 'tl', 'en');
        $nameHi = translate_names_gtr($nameEn, 'hi', 'en');
        if ($nameFil === '') {
            $nameFil = $nameEn;
        }
        if ($nameHi === '') {
            $nameHi = $nameEn;
        }
        return [
            'name_en' => $nameEn,
            'name_fil' => $nameFil,
            'name_hi' => $nameHi,
        ];
    }
}

if (!function_exists('translate_text_chunked_gtr')) {
    /**
     * Long-text wrapper for Google translate helper (URL length limits).
     */
    function translate_text_chunked_gtr(string $text, string $to, string $from = 'auto'): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $maxLen = 1000;
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len <= $maxLen) {
            return translate_names_gtr($text, $to, $from);
        }
        $parts = [];
        for ($i = 0; $i < $len; $i += $maxLen) {
            $parts[] = function_exists('mb_substr')
                ? mb_substr($text, $i, $maxLen, 'UTF-8')
                : substr($text, $i, $maxLen);
        }
        $out = [];
        foreach ($parts as $chunk) {
            $out[] = translate_names_gtr($chunk, $to, $from);
        }

        return trim(implode('', $out));
    }
}

if (!function_exists('translate_descriptions_from_ar_en')) {
    /**
     * Same strategy as names: EN from AR if needed; Filipino/Hindi from EN.
     *
     * @return array{description_en: string, description_fil: string, description_hi: string}
     */
    function translate_descriptions_from_ar_en($descAr, $descEn): array
    {
        $descAr = trim((string) $descAr);
        $descEn = trim((string) $descEn);
        if ($descEn === '' && $descAr !== '') {
            $descEn = translate_text_chunked_gtr($descAr, 'en', 'ar');
        }
        if ($descEn === '') {
            $descEn = $descAr;
        }
        $descFil = translate_text_chunked_gtr($descEn, 'tl', 'en');
        $descHi = translate_text_chunked_gtr($descEn, 'hi', 'en');
        if ($descFil === '') {
            $descFil = $descEn;
        }
        if ($descHi === '') {
            $descHi = $descEn;
        }

        return [
            'description_en' => $descEn,
            'description_fil' => $descFil,
            'description_hi' => $descHi,
        ];
    }
}
