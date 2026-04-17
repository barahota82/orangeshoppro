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
