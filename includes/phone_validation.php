<?php

declare(strict_types=1);

/**
 * Payload `phone_country` من الواجهة: أرقام بادئة (مثل 965) أو الرمز **__intl__** لخيار «رقم دولي كامل» (صفر§4).
 *
 * @return array{full_intl: bool, dial: string}
 */
function orange_storefront_parse_api_phone_country(string $raw): array
{
    $t = trim($raw);
    if ($t === '__intl__') {
        return ['full_intl' => true, 'dial' => ''];
    }

    return ['full_intl' => false, 'dial' => preg_replace('/\D+/', '', $t)];
}

/**
 * Normalize storefront / customer phone to +digits (8–14 digits after +, incl. country code).
 * Accepts leading 00 (converted to +). Rejects letters.
 *
 * When a trusted Country dial is supplied:
 * - national/local digits → canonicalize once with that dial;
 * - already-canonical E.164 (+… / 00…) whose prefix matches the dial → return the same canonical value (idempotent);
 * - already-canonical E.164 whose prefix conflicts with the dial → reject.
 *
 * @param string|null $countryDialDigits e.g. "965" when the user picked Kuwait and typed the national number only
 * @param bool $internationalSingleField عند true (قائمة «دولي كامل»): لا يُطبَّق افتراض الكويت على 8 أرقام وطنية
 */
function orange_normalize_customer_phone(string $raw, ?string $countryDialDigits = null, bool $internationalSingleField = false): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (preg_match('/[a-zA-Z\x{0600}-\x{06FF}]/u', $s)) {
        return null;
    }
    if (preg_match('/[^\d\+\s\-\(\)\.]/u', $s)) {
        return null;
    }
    // Reject duplicate leading plus (e.g. "++965…") before digit extraction.
    if (str_starts_with($s, '++')) {
        return null;
    }

    $cc = null;
    if ($countryDialDigits !== null && $countryDialDigits !== '') {
        $cc = preg_replace('/\D+/', '', $countryDialDigits);
        if ($cc === '' || ($cc[0] ?? '') === '0') {
            $cc = null;
        }
    }

    if ($cc !== null && (str_starts_with($s, '+') || str_starts_with($s, '00'))) {
        // Downstream / Intake may re-normalize an already-canonical E.164 with the same trusted dial.
        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        }
        if (!str_starts_with($s, '+') || substr_count($s, '+') !== 1) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $s);
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 14) {
            return null;
        }
        if ($digits[0] === '0') {
            return null;
        }
        if (!str_starts_with($digits, $cc)) {
            return null;
        }
        if (substr($digits, strlen($cc)) === '') {
            return null;
        }

        return '+' . $digits;
    }

    if ($cc === null && str_starts_with($s, '00')) {
        $s = '+' . substr($s, 2);
    }

    $hasPlus = str_starts_with($s, '+');
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '' || strlen($digits) > 14) {
        return null;
    }
    if ($digits[0] === '0') {
        return null;
    }

    if ($cc !== null) {
        if (str_starts_with($digits, $cc)) {
            $digits = substr($digits, strlen($cc));
        }
        if ($digits === '') {
            return null;
        }
        $full = $cc . $digits;
        $fLen = strlen($full);
        if ($fLen < 8 || $fLen > 14) {
            return null;
        }

        return '+' . $full;
    }

    if ($hasPlus) {
        $len = strlen($digits);
        if ($len < 8 || $len > 14) {
            return null;
        }

        return '+' . $digits;
    }

    $len = strlen($digits);
    if (!$internationalSingleField && $len === 8 && preg_match('/^[569]/', $digits)) {
        $full = '965' . $digits;
        if (strlen($full) > 14) {
            return null;
        }

        return '+' . $full;
    }

    if ($len >= 8 && $len <= 14) {
        return '+' . $digits;
    }

    return null;
}

/**
 * Parts to store alongside canonical E.164 in `phone`: country dial (digits) and national digits from the number field.
 *
 * @return array{country_dial: ?string, national: ?string}
 */
function orange_storefront_phone_storage_parts(string $rawInput, ?string $countryDialDigits): array
{
    $cc = null;
    if ($countryDialDigits !== null && $countryDialDigits !== '') {
        $d = preg_replace('/\D+/', '', $countryDialDigits);
        $cc = ($d !== '') ? $d : null;
    }
    $national = null;
    if ($cc !== null) {
        $national = preg_replace('/\D+/', '', $rawInput);
        if ($national !== '' && str_starts_with($national, $cc)) {
            $national = substr($national, strlen($cc));
        }
        $national = $national !== '' ? $national : null;
    }

    return ['country_dial' => $cc, 'national' => $national];
}

/**
 * Derive national digits from stored E.164 when national was not saved (e.g. legacy queue payload).
 */
function orange_storefront_national_from_e164(string $e164, string $countryDialDigits): ?string
{
    $digits = preg_replace('/\D+/', '', $e164);
    $cc = preg_replace('/\D+/', '', $countryDialDigits);
    if ($digits === '' || $cc === '') {
        return null;
    }
    if (!str_starts_with($digits, $cc)) {
        return null;
    }
    $n = substr($digits, strlen($cc));

    return $n !== '' ? $n : null;
}
