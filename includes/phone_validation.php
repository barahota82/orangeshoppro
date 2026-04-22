<?php

declare(strict_types=1);

/**
 * Normalize storefront / customer phone to +digits (8–14 digits after +, incl. country code).
 * Accepts leading 00 (converted to +). Rejects letters.
 *
 * @param string|null $countryDialDigits e.g. "965" when the user picked Kuwait and typed the national number only
 */
function orange_normalize_customer_phone(string $raw, ?string $countryDialDigits = null): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (str_starts_with($s, '00')) {
        $s = '+' . substr($s, 2);
    }
    if (preg_match('/[a-zA-Z\x{0600}-\x{06FF}]/u', $s)) {
        return null;
    }
    if (preg_match('/[^\d\+\s\-\(\)\.]/u', $s)) {
        return null;
    }

    $hasPlus = str_starts_with($s, '+');
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '' || strlen($digits) > 14) {
        return null;
    }
    if ($digits[0] === '0') {
        return null;
    }

    $cc = null;
    if ($countryDialDigits !== null && $countryDialDigits !== '') {
        $cc = preg_replace('/\D+/', '', $countryDialDigits);
        if ($cc === '' || ($cc[0] ?? '') === '0') {
            $cc = null;
        }
    }

    if ($hasPlus) {
        $len = strlen($digits);
        if ($len < 8 || $len > 14) {
            return null;
        }

        return '+' . $digits;
    }

    if ($cc !== null) {
        $full = $cc . $digits;
        $fLen = strlen($full);
        if ($fLen < 8 || $fLen > 14) {
            return null;
        }

        return '+' . $full;
    }

    $len = strlen($digits);
    if ($len === 8 && preg_match('/^[569]/', $digits)) {
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
