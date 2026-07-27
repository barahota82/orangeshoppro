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
    // 12-hour with explicit AM/PM (Y-m-d wall — Backup/Restore / operator tooling).
    if ($pattern === 'datetime12') {
        return $dt->format('Y-m-d g:i:s A');
    }
    // Admin Entry Date / Absolute Moment with seconds (d/m/Y).
    if ($pattern === 'datetime_his12') {
        unset($lang);

        return $dt->format('d/m/Y g:i:s A');
    }

    // Default admin Absolute Moment / Entry Date: d/m/Y + 12-hour AM/PM (Phase 3 Step 2).
    // Browser TZ and PHP default TZ do not affect the instant.
    unset($lang);

    return $dt->format('d/m/Y g:i A');
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
 * Civil-day contract (Phase 4 Step 2):
 * - start = first valid instant belonging to the local calendar date
 * - end_exclusive = first valid instant belonging to the next local calendar date
 * Next date is computed as a calendar operation, then each boundary is parsed
 * independently in the IANA zone. Do not derive end from start->modify('+1 day')
 * when local midnight may be nonexistent (Cairo spring-forward → 23h day).
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

    // Calendar +1 day in UTC date math only (not wall-clock from a normalized start).
    $cal = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
    if (!$cal instanceof DateTimeImmutable) {
        throw new OrangeAdminTimeConfigException('admin_time_day_bounds_failed');
    }
    $nextYmd = $cal->modify('+1 day')->format('Y-m-d');

    try {
        $startLocal = new DateTimeImmutable($ymd . ' 00:00:00', $tz);
        $endLocal = new DateTimeImmutable($nextYmd . ' 00:00:00', $tz);
    } catch (Throwable $e) {
        throw new OrangeAdminTimeConfigException('admin_time_day_bounds_failed');
    }
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

/**
 * Parse DATETIME stored as UTC wall (Y-m-d H:i:s or ISO with offset/Z).
 * Naive strings are treated as UTC — never as PHP default / browser TZ.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_parse_mysql_utc_datetime(string $value): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        throw new OrangeAdminTimeConfigException('admin_time_instant_empty');
    }
    if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $value)) {
        return orange_admin_time_parse_utc_instant($value);
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $value, $m)) {
        throw new OrangeAdminTimeConfigException('admin_time_instant_parse_failed');
    }
    try {
        $dt = new DateTimeImmutable($m[1] . 'T' . $m[2] . '+00:00');
    } catch (Throwable $e) {
        throw new OrangeAdminTimeConfigException('admin_time_instant_parse_failed');
    }

    return $dt->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Format a MySQL UTC DATETIME (or ISO instant) for an explicit country_id.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_mysql_utc_for_country_id(
    PDO $pdo,
    string $mysqlUtcDatetime,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    $iso = orange_admin_time_parse_mysql_utc_datetime($mysqlUtcDatetime)->format('c');

    return orange_admin_time_format_instant_for_country_id($pdo, $iso, $countryId, $lang, $pattern);
}

/**
 * Format MySQL UTC DATETIME using Current Country Context IANA.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_mysql_utc_for_admin_context(
    PDO $pdo,
    string $mysqlUtcDatetime,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    $iso = orange_admin_time_parse_mysql_utc_datetime($mysqlUtcDatetime)->format('c');

    return orange_admin_time_format_instant_for_admin_context($pdo, $iso, $lang, $pattern);
}

/**
 * Local calendar day → UTC MySQL DATETIME bounds for SQL filters:
 *   start_utc_mysql <= col AND col < end_exclusive_utc_mysql
 *
 * @return array{local_ymd:string, start_utc_mysql:string, end_exclusive_utc_mysql:string}
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_day_bounds_mysql_utc(string $localYmd, string $ianaTimezone): array
{
    $b = orange_admin_time_day_bounds_utc($localYmd, $ianaTimezone);
    $start = orange_admin_time_parse_utc_instant($b['start_utc_iso']);
    $end = orange_admin_time_parse_utc_instant($b['end_exclusive_utc_iso']);

    return [
        'local_ymd' => $b['local_ymd'],
        'start_utc_mysql' => $start->format('Y-m-d H:i:s'),
        'end_exclusive_utc_mysql' => $end->format('Y-m-d H:i:s'),
    ];
}

/**
 * Inclusive local from/to dates → UTC MySQL range for filters (end exclusive next day after $toYmd).
 *
 * @return array{start_utc_mysql:string, end_exclusive_utc_mysql:string}|null null if both empty
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_filter_range_mysql_utc(
    string $fromYmd,
    string $toYmd,
    string $ianaTimezone
): ?array {
    $from = orange_admin_time_date_only_normalize($fromYmd);
    $to = orange_admin_time_date_only_normalize($toYmd);
    if ($from === '' && $to === '') {
        return null;
    }
    if ($from === '') {
        $from = $to;
    }
    if ($to === '') {
        $to = $from;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $startB = orange_admin_time_day_bounds_mysql_utc($from, $ianaTimezone);
    $endB = orange_admin_time_day_bounds_mysql_utc($to, $ianaTimezone);

    return [
        'start_utc_mysql' => $startB['start_utc_mysql'],
        'end_exclusive_utc_mysql' => $endB['end_exclusive_utc_mysql'],
    ];
}

/**
 * Safe display helper for country-filtered admin lists:
 * - empty → "—"
 * - country_id > 0 → record country IANA
 * - country_id missing → Current Country Context (list already isolated by context)
 * Does not use browser TZ. For single-record screens prefer
 * orange_admin_time_display_mysql_utc_for_record() (fail-closed).
 */
function orange_admin_time_display_mysql_utc_or_dash(
    PDO $pdo,
    ?string $mysqlUtcDatetime,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    $raw = trim((string) ($mysqlUtcDatetime ?? ''));
    if ($raw === '') {
        return '—';
    }
    try {
        if ($countryId > 0) {
            return orange_admin_time_format_mysql_utc_for_country_id($pdo, $raw, $countryId, $lang, $pattern);
        }

        return orange_admin_time_format_mysql_utc_for_admin_context($pdo, $raw, $lang, $pattern);
    } catch (OrangeAdminTimeConfigException $e) {
        return '[' . $e->getMessage() . ']';
    }
}

/**
 * Unix epoch for the current absolute instant (UTC-based; independent of PHP default TZ wall).
 */
function orange_admin_time_unix_now(): int
{
    return time();
}

/**
 * UTC ISO-8601 (+00:00) from a Unix epoch.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_utc_iso_from_unix(int $unix): string
{
    if ($unix < 0) {
        throw new OrangeAdminTimeConfigException('admin_time_unix_invalid');
    }

    return gmdate('c', $unix);
}

/**
 * SQL expression that binds a Unix epoch into MySQL TIMESTAMP/DATETIME without
 * treating a UTC wall string as session-local (+03:00). Use with a bound int:
 *   SET paid_at = FROM_UNIXTIME(?)
 */
function orange_admin_time_sql_from_unix(): string
{
    return 'FROM_UNIXTIME(?)';
}

/**
 * Normalize a selected UNIX_TIMESTAMP(...) value (or null/empty) to int|null.
 */
function orange_admin_time_unix_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $unix = (int) $value;

    return $unix > 0 ? $unix : null;
}

/**
 * Convert a TIMESTAMP value returned by MySQL under the session time_zone into
 * a Unix epoch. Uses MySQL UNIX_TIMESTAMP() so the session wall is not misread as UTC.
 *
 * Prefer selecting UNIX_TIMESTAMP(col) in SQL when possible.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_mysql_timestamp_session_wall_to_unix(PDO $pdo, ?string $sessionWall): ?int
{
    $raw = trim((string) ($sessionWall ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    if (ctype_digit($raw)) {
        return orange_admin_time_unix_or_null($raw);
    }
    $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(?)');
    $st->execute([$raw]);
    $unix = orange_admin_time_unix_or_null($st->fetchColumn());
    if ($unix === null) {
        throw new OrangeAdminTimeConfigException('admin_time_timestamp_parse_failed');
    }

    return $unix;
}

/**
 * Format a Unix absolute instant for an explicit country_id IANA.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_unix_for_country_id(
    PDO $pdo,
    int $unix,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    return orange_admin_time_format_instant_for_country_id(
        $pdo,
        orange_admin_time_utc_iso_from_unix($unix),
        $countryId,
        $lang,
        $pattern
    );
}

/**
 * Format Unix instant using Current Country Context (country-filtered lists only).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_format_unix_for_admin_context(
    PDO $pdo,
    int $unix,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    return orange_admin_time_format_instant_for_admin_context(
        $pdo,
        orange_admin_time_utc_iso_from_unix($unix),
        $lang,
        $pattern
    );
}

/**
 * Record-scoped DATETIME (UTC wall) display — fail closed if country_id missing/invalid.
 * No silent Current Country Context fallback.
 */
function orange_admin_time_display_mysql_utc_for_record(
    PDO $pdo,
    ?string $mysqlUtcDatetime,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    $raw = trim((string) ($mysqlUtcDatetime ?? ''));
    if ($raw === '') {
        return '—';
    }
    if ($countryId <= 0) {
        return '[admin_time_country_id_required]';
    }
    try {
        return orange_admin_time_format_mysql_utc_for_country_id($pdo, $raw, $countryId, $lang, $pattern);
    } catch (OrangeAdminTimeConfigException $e) {
        return '[' . $e->getMessage() . ']';
    }
}

/**
 * Record-scoped TIMESTAMP (unix epoch) display — fail closed if country_id missing/invalid.
 */
function orange_admin_time_display_unix_for_record(
    PDO $pdo,
    ?int $unix,
    int $countryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    if ($unix === null || $unix <= 0) {
        return '—';
    }
    if ($countryId <= 0) {
        return '[admin_time_country_id_required]';
    }
    try {
        return orange_admin_time_format_unix_for_country_id($pdo, $unix, $countryId, $lang, $pattern);
    } catch (OrangeAdminTimeConfigException $e) {
        return '[' . $e->getMessage() . ']';
    }
}

/**
 * Date-only default: local calendar today for an explicit country_id (IANA).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_document_date_today_for_country_id(PDO $pdo, int $countryId): string
{
    return orange_admin_time_today_ymd_in_iana(
        orange_admin_time_timezone_for_country_id($pdo, $countryId)
    );
}

/**
 * Date-only default for Current Country Context (admin forms).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_document_date_today_for_admin_context(PDO $pdo): string
{
    return orange_admin_time_today_ymd_in_iana(
        orange_admin_time_timezone_for_admin_context($pdo)
    );
}

/**
 * First calendar day of the current local month in an IANA zone (Y-m-d, Date-only).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_month_start_ymd_in_iana(string $ianaTimezone): string
{
    $today = orange_admin_time_today_ymd_in_iana($ianaTimezone);

    return substr($today, 0, 8) . '01';
}

/**
 * Last calendar day of the current local month in an IANA zone (Y-m-d, Date-only).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_month_end_ymd_in_iana(string $ianaTimezone): string
{
    $start = orange_admin_time_month_start_ymd_in_iana($ianaTimezone);
    $tz = new DateTimeZone(orange_admin_time_require_iana($ianaTimezone));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
    if (!$dt instanceof DateTimeImmutable) {
        throw new OrangeAdminTimeConfigException('admin_time_month_end_invalid');
    }

    return $dt->modify('last day of this month')->format('Y-m-d');
}

/**
 * Absolute “now” display for admin Entry Date preview (Current Country Context IANA, 12h AM/PM).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_now_display_for_admin_context(
    PDO $pdo,
    string $lang = 'ar',
    string $pattern = 'datetime'
): string {
    return orange_admin_time_format_instant_for_admin_context(
        $pdo,
        orange_admin_time_utc_now_iso(),
        $lang,
        $pattern
    );
}

/**
 * Convert a country-local wall DATETIME (Y-m-d H:i:s) to UTC MySQL wall for DATETIME storage.
 * Used when a caller passes document-local wall (e.g. document_date + time) into Absolute Moment columns
 * such as inventory_cost_layers.layer_date — without changing GL $postingAt semantics (Step 5).
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_country_local_wall_to_utc_mysql(PDO $pdo, string $localWall, int $countryId): string
{
    $wall = trim($localWall);
    if ($wall === '') {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $wall, $m)) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    if ($countryId <= 0) {
        throw new OrangeAdminTimeConfigException('admin_time_country_id_required');
    }
    $ymd = orange_admin_time_date_only_normalize($m[1]);
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    $hhmmss = $m[2];
    $tzName = orange_admin_time_timezone_for_country_id($pdo, $countryId);

    return orange_admin_time_local_wall_to_utc_mysql_in_iana($ymd . ' ' . $hhmmss, $tzName);
}

/**
 * Local wall Y-m-d H:i:s in an IANA zone → UTC MySQL wall.
 * Fail closed on nonexistent (DST gap) or ambiguous (DST fold) civil times — no silent pick.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_local_wall_to_utc_mysql_in_iana(string $localWall, string $ianaTimezone): string
{
    $wall = trim($localWall);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $wall, $m)) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    $ymd = orange_admin_time_date_only_normalize($m[1]);
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    $hhmmss = $m[2];
    $expected = $ymd . ' ' . $hhmmss;
    $tzName = orange_admin_time_require_iana($ianaTimezone);
    $tz = new DateTimeZone($tzName);
    $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expected, $tz);
    if (!$local instanceof DateTimeImmutable) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_invalid');
    }
    // Nonexistent (spring gap): PHP folds forward → wall roundtrip mismatches.
    if ($local->format('Y-m-d H:i:s') !== $expected) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_nonexistent');
    }
    $unix = $local->getTimestamp();
    // Ambiguous (fall fold): more than one UTC instant maps to the same local wall.
    $matchingUnix = [];
    foreach ([$unix - 7200, $unix - 3600, $unix, $unix + 3600, $unix + 7200] as $try) {
        $cand = (new DateTimeImmutable('@' . $try))->setTimezone($tz);
        if ($cand->format('Y-m-d H:i:s') === $expected) {
            $matchingUnix[$cand->getTimestamp()] = true;
        }
    }
    if (count($matchingUnix) > 1) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_ambiguous');
    }
    $utc = $local->setTimezone(new DateTimeZone('UTC'));
    $back = $utc->setTimezone($tz);
    if ($back->format('Y-m-d H:i:s') !== $expected) {
        throw new OrangeAdminTimeConfigException('admin_time_local_wall_nonexistent');
    }

    return $utc->format('Y-m-d H:i:s');
}

/**
 * Build admin API payload fields for an absolute instant from Unix epoch.
 *
 * @return array{utc:string, display:string}
 */
function orange_admin_time_api_instant_from_unix(
    PDO $pdo,
    ?int $unix,
    int $recordCountryId,
    string $lang = 'ar',
    string $pattern = 'datetime'
): array {
    if ($unix === null || $unix <= 0) {
        return ['utc' => '', 'display' => '—'];
    }
    $utc = orange_admin_time_utc_iso_from_unix($unix);

    return [
        'utc' => $utc,
        'display' => orange_admin_time_display_unix_for_record($pdo, $unix, $recordCountryId, $lang, $pattern),
    ];
}

/**
 * Report default From/To (Date-only): local month start → local today for Current Country Context.
 *
 * @return array{from_ymd:string,to_ymd:string,iana:string}
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_report_default_from_to_for_admin_context(PDO $pdo): array
{
    $iana = orange_admin_time_timezone_for_admin_context($pdo);

    return [
        'from_ymd' => orange_admin_time_month_start_ymd_in_iana($iana),
        'to_ymd' => orange_admin_time_today_ymd_in_iana($iana),
        'iana' => $iana,
    ];
}

/**
 * Local calendar months covering inclusive From/To (Date-only) with Absolute UTC MySQL bounds.
 * DST-safe via IANA day bounds — never DATE()/MONTH() on UTC timestamps.
 *
 * @return list<array{ym:string,yy:int,mm:int,start_utc_mysql:string,end_exclusive_utc_mysql:string}>
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_local_month_buckets_mysql_utc(
    string $fromYmd,
    string $toYmd,
    string $ianaTimezone
): array {
    $from = orange_admin_time_date_only_normalize($fromYmd);
    $to = orange_admin_time_date_only_normalize($toYmd);
    if ($from === '' || $to === '') {
        throw new OrangeAdminTimeConfigException('admin_time_date_only_invalid');
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $tzName = orange_admin_time_require_iana($ianaTimezone);
    $tz = new DateTimeZone($tzName);
    $cur = DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 8) . '01', $tz);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 8) . '01', $tz);
    if (!$cur instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
        throw new OrangeAdminTimeConfigException('admin_time_month_bucket_invalid');
    }
    $buckets = [];
    while ($cur <= $end) {
        $ym = $cur->format('Y-m');
        $monthStart = $cur->format('Y-m-d');
        $monthEnd = $cur->modify('last day of this month')->format('Y-m-d');
        $rangeFrom = $monthStart < $from ? $from : $monthStart;
        $rangeTo = $monthEnd > $to ? $to : $monthEnd;
        $bounds = orange_admin_time_filter_range_mysql_utc($rangeFrom, $rangeTo, $tzName);
        if ($bounds === null) {
            $cur = $cur->modify('first day of next month');
            continue;
        }
        $buckets[] = [
            'ym' => $ym,
            'yy' => (int) $cur->format('Y'),
            'mm' => (int) $cur->format('n'),
            'start_utc_mysql' => $bounds['start_utc_mysql'],
            'end_exclusive_utc_mysql' => $bounds['end_exclusive_utc_mysql'],
        ];
        $cur = $cur->modify('first day of next month');
    }

    return $buckets;
}

/**
 * Build a sargable CASE expression that maps an Absolute Moment column to local YYYY-MM keys.
 *
 * @param list<array{ym:string,start_utc_mysql:string,end_exclusive_utc_mysql:string}> $buckets
 * @return array{sql:string,params:list<string>}
 */
function orange_admin_time_sql_local_month_key_expr(string $columnSql, array $buckets): array
{
    $columnSql = trim($columnSql);
    if ($columnSql === '' || $buckets === []) {
        return ['sql' => "''", 'params' => []];
    }
    $parts = ['CASE'];
    $params = [];
    foreach ($buckets as $b) {
        $ym = (string) ($b['ym'] ?? '');
        $start = (string) ($b['start_utc_mysql'] ?? '');
        $end = (string) ($b['end_exclusive_utc_mysql'] ?? '');
        if ($ym === '' || $start === '' || $end === '') {
            continue;
        }
        $parts[] = 'WHEN ' . $columnSql . ' >= ? AND ' . $columnSql . ' < ? THEN ?';
        $params[] = $start;
        $params[] = $end;
        $params[] = $ym;
    }
    $parts[] = "ELSE '' END";

    return ['sql' => implode(' ', $parts), 'params' => $params];
}

/**
 * Absolute From/To (local Date-only inputs) → UTC MySQL inclusive/exclusive bounds for Current Country Context.
 *
 * @return array{start_utc_mysql:string,end_exclusive_utc_mysql:string,iana:string}|null
 * @throws OrangeAdminTimeConfigException
 */
function orange_admin_time_filter_range_mysql_utc_for_admin_context(
    PDO $pdo,
    string $fromYmd,
    string $toYmd
): ?array {
    $iana = orange_admin_time_timezone_for_admin_context($pdo);
    $range = orange_admin_time_filter_range_mysql_utc($fromYmd, $toYmd, $iana);
    if ($range === null) {
        return null;
    }

    return [
        'start_utc_mysql' => $range['start_utc_mysql'],
        'end_exclusive_utc_mysql' => $range['end_exclusive_utc_mysql'],
        'iana' => $iana,
    ];
}
