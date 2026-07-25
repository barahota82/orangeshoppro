<?php

declare(strict_types=1);

/**
 * Orange Admin — Central Time Foundation (Phase 1)
 *
 * Owner policy (2026-07-25): absolute moments are UTC; admin display / business-day
 * math uses Country Configuration IANA (`countries.timezone`) via Current Country
 * Context or an explicit country_id. Browser/OS timezone is never authoritative.
 *
 * Semantic kinds (do not conflate):
 * - ORANGE_ADMIN_TIME_KIND_ABSOLUTE — real instant (store/transmit as UTC ISO-8601)
 * - ORANGE_ADMIN_TIME_KIND_DATE_ONLY — calendar date Y-m-d (no timezone conversion)
 * - ORANGE_ADMIN_TIME_KIND_BUSINESS_LOCAL — wall clock in a country (slots/hours);
 *   not treated as a UTC timestamp automatically (Phase 1 documents only)
 *
 * Frozen outside this foundation: Backup Center / Restore Center time paths.
 *
 * @see docs/archive/ORANGE_ADMIN_TIME_POLICY.txt
 */

require_once __DIR__ . '/date_format.php';

const ORANGE_ADMIN_TIME_KIND_ABSOLUTE = 'absolute_moment';
const ORANGE_ADMIN_TIME_KIND_DATE_ONLY = 'date_only';
const ORANGE_ADMIN_TIME_KIND_BUSINESS_LOCAL = 'business_local_time';

/**
 * Configuration / contract failure for admin time (missing/invalid IANA, bad input).
 */
class OrangeAdminTimeConfigException extends RuntimeException
{
}

/**
 * Validate IANA Area/Location. Rejects empty, offsets, UTC/GMT/Etc aliases.
 * Same rules as orange_countries_is_valid_iana_timezone() — kept local so this
 * foundation can load without catalog_schema for isolated self-tests.
 */
function orange_admin_time_is_valid_iana(string $tz): bool
{
    $tz = trim($tz);
    if ($tz === '' || str_contains($tz, ' ')) {
        return false;
    }
    if (preg_match('/^(?:UTC|GMT|[+-]\d)/i', $tz) || strncmp($tz, 'Etc/', 4) === 0) {
        return false;
    }
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        return false;
    }

    return true;
}

/**
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_require_iana(string $tz): string
{
    $tz = trim($tz);
    if (!orange_admin_time_is_valid_iana($tz)) {
        throw new OrangeAdminTimeConfigException('admin_time_invalid_iana_timezone');
    }

    return $tz;
}

/**
 * IANA for an explicit country_id from Country Configuration only.
 * Empty/missing/invalid → configuration error (no silent Asia/Kuwait, no browser TZ).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_timezone_for_country_id(PDO $pdo, int $countryId): string
{
    require_once __DIR__ . '/countries.php';
    if ($countryId <= 0) {
        throw new OrangeAdminTimeConfigException('admin_time_country_id_required');
    }
    $tz = orange_country_timezone($pdo, $countryId);
    if ($tz === '') {
        throw new OrangeAdminTimeConfigException('admin_time_country_timezone_missing');
    }

    return orange_admin_time_require_iana($tz);
}

/**
 * IANA for the current Admin Country Context session.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_timezone_for_admin_context(PDO $pdo): string
{
    require_once __DIR__ . '/countries.php';
    $tz = orange_admin_context_timezone($pdo);
    if ($tz === '') {
        throw new OrangeAdminTimeConfigException('admin_time_context_timezone_missing');
    }

    return orange_admin_time_require_iana($tz);
}

/** Current UTC instant as ISO-8601 with explicit offset (+00:00). */
function orange_admin_time_utc_now_iso(): string
{
    return gmdate('c');
}

/**
 * UTC instant suitable for DATETIME-style storage (Y-m-d H:i:s, UTC wall).
 * Callers that still write naive DATETIME must treat this as UTC by contract.
 */
function orange_admin_time_utc_now_mysql(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Parse an absolute instant. Requires explicit Z or numeric offset (no naive local).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_parse_utc_instant(string $value): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        throw new OrangeAdminTimeConfigException('admin_time_instant_empty');
    }
    if (!preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $value)) {
        throw new OrangeAdminTimeConfigException('admin_time_instant_timezone_required');
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Throwable $e) {
        throw new OrangeAdminTimeConfigException('admin_time_instant_parse_failed');
    }

    return $dt->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Format an absolute instant in an explicit IANA zone.
 * Result does not depend on PHP default timezone or browser TZ.
 *
 * @param string $lang ar|en|fil|hi (display pattern only; instant unchanged)
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_instant_in_iana(
    string $utcInstant,
    string $ianaTimezone,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    $tzName = orange_admin_time_require_iana($ianaTimezone);
    $dt = orange_admin_time_parse_utc_instant($utcInstant)->setTimezone(new DateTimeZone($tzName));
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';

    if ($pattern === 'date') {
        return $dt->format('d/m/Y');
    }
    if ($pattern === 'ymd') {
        return $dt->format('Y-m-d');
    }
    // 12-hour with explicit AM/PM (matches Backup/Restore operator convention for clarity).
    if ($pattern === 'datetime12') {
        return $dt->format('Y-m-d g:i:s A');
    }

    // Default: d/m/Y H:i (24h) — familiar admin tables; lang reserved for future localization.
    unset($lang);

    return $dt->format('d/m/Y H:i');
}

/**
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_instant_for_country_id(
    PDO $pdo,
    string $utcInstant,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    return orange_admin_time_format_instant_in_iana(
        $utcInstant,
        orange_admin_time_timezone_for_country_id($pdo, $countryId),
        $lang,
        $pattern
    );
}

/**
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_instant_for_admin_context(
    PDO $pdo,
    string $utcInstant,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    return orange_admin_time_format_instant_in_iana(
        $utcInstant,
        orange_admin_time_timezone_for_admin_context($pdo),
        $lang,
        $pattern
    );
}

/**
 * Local calendar Y-m-d for an instant in an IANA zone.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_local_ymd_in_iana(string $utcInstant, string $ianaTimezone): string
{
    $tzName = orange_admin_time_require_iana($ianaTimezone);
    $dt = orange_admin_time_parse_utc_instant($utcInstant)->setTimezone(new DateTimeZone($tzName));

    return $dt->format('Y-m-d');
}

/**
 * Current local Y-m-d in an IANA zone (from UTC now).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_today_ymd_in_iana(string $ianaTimezone): string
{
    return orange_admin_time_local_ymd_in_iana(orange_admin_time_utc_now_iso(), $ianaTimezone);
}

/**
 * Inclusive local-day start and exclusive next-day start as UTC ISO-8601.
 * DST-safe: uses IANA transitions (e.g. Africa/Cairo), never a fixed offset.
 *
 * @return array{local_ymd:string, start_utc_iso:string, end_exclusive_utc_iso:string}
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_day_bounds_utc(string $localYmd, string $ianaTimezone): array
{
    $ymd = orange_admin_time_date_only_normalize($localYmd);
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_date_only_invalid');
    }
    $tzName = orange_admin_time_require_iana($ianaTimezone);
    $tz = new DateTimeZone($tzName);
    try {
        $startLocal = new DateTimeImmutable($ymd . ' 00:00:00', $tz);
    } catch (Throwable $e) {
        throw new OrangeAdminTimeConfigException('admin_time_day_bounds_failed');
    }
    $endLocal = $startLocal->modify('+1 day');
    $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
    $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'));

    return [
        'local_ymd' => $ymd,
        'start_utc_iso' => $startUtc->format('c'),
        'end_exclusive_utc_iso' => $endUtc->format('c'),
    ];
}

/**
 * Normalize / accept Date-only Y-m-d. Never applies timezone conversion.
 * Returns '' if invalid (same spirit as orange_parse_admin_date_to_ymd for Y-m-d).
 */
function orange_admin_time_date_only_normalize(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if (!checkdate($mo, $d, $y)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/**
 * Date-only contract: pass-through of a validated Y-m-d (no TZ shift).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_date_only_assert(string $value): string
{
    $ymd = orange_admin_time_date_only_normalize($value);
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_date_only_invalid');
    }

    return $ymd;
}

/**
 * Documented contract marker for Business Local Time values (Phase 1: no slot migration).
 * Callers store/display country wall times without treating them as UTC instants.
 *
 * @return array{kind:string, note:string}
 */
function orange_admin_time_business_local_contract(): array
{
    return [
        'kind' => ORANGE_ADMIN_TIME_KIND_BUSINESS_LOCAL,
        'note' => 'Country wall clock (delivery slots, working hours). Not an absolute UTC moment; do not run through UTC↔IANA conversion as if it were Absolute Moment. Phase 1 documents only — no slot/hours migration.',
    ];
}
