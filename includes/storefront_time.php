<?php

declare(strict_types=1);

/**
 * Storefront / customer Absolute Moment helpers (Phase 3 Step 4).
 * Reuses includes/admin_time.php — Country Authority = record/order country, never Admin Context.
 */

require_once __DIR__ . '/admin_time.php';
require_once __DIR__ . '/countries.php';

/**
 * Absolute MySQL UTC DATETIME → customer API fields (UTC ISO + Country-IANA 12h display).
 *
 * @return array{utc:string,display:string,timezone:string}
 */
function orange_storefront_time_api_instant_from_mysql_utc(
    PDO $pdo,
    ?string $mysqlUtcDatetime,
    int $recordCountryId,
    string $lang = 'ar'
): array {
    $raw = trim((string) ($mysqlUtcDatetime ?? ''));
    if ($raw === '' || $recordCountryId <= 0) {
        return ['utc' => '', 'display' => '—', 'timezone' => ''];
    }
    try {
        $iana = orange_admin_time_timezone_for_country_id($pdo, $recordCountryId);
        $utc = orange_admin_time_parse_mysql_utc_datetime($raw)->format('c');
        $display = orange_admin_time_format_instant_in_iana($utc, $iana, $lang, 'datetime');

        return [
            'utc' => $utc,
            'display' => $display,
            'timezone' => $iana,
        ];
    } catch (Throwable $e) {
        return ['utc' => '', 'display' => '—', 'timezone' => ''];
    }
}

/**
 * Enrich a storefront order list/detail row: replace ambiguous created_at (and peers) with UTC ISO + display.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function orange_storefront_time_enrich_order_row(PDO $pdo, array $row, string $lang = 'ar'): array
{
    $countryId = (int) ($row['country_id'] ?? 0);
    $fields = ['created_at', 'completed_at', 'cancelled_at', 'updated_at'];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $raw = $row[$field];
        unset($row[$field]);
        $inst = orange_storefront_time_api_instant_from_mysql_utc(
            $pdo,
            is_string($raw) || is_numeric($raw) ? (string) $raw : null,
            $countryId,
            $lang
        );
        $row[$field . '_utc'] = $inst['utc'];
        $row[$field . '_display'] = $inst['display'];
        if ($field === 'created_at' && $inst['timezone'] !== '') {
            $row['time_zone'] = $inst['timezone'];
        }
    }

    return $row;
}

/**
 * Date-only calendar Y-m-d → d/m/Y (no strtotime / no TZ shift).
 */
function orange_storefront_time_date_only_display(?string $ymdOrDatetime): string
{
    $raw = trim((string) ($ymdOrDatetime ?? ''));
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        return '';
    }

    return $m[3] . '/' . $m[2] . '/' . $m[1];
}

/**
 * Print/generated Absolute now for a document country (12h AM/PM).
 */
function orange_storefront_time_now_display_for_country(
    PDO $pdo,
    int $countryId,
    string $lang = 'ar'
): string {
    if ($countryId <= 0) {
        return '—';
    }
    try {
        return orange_admin_time_format_instant_for_country_id(
            $pdo,
            orange_admin_time_utc_now_iso(),
            $countryId,
            $lang,
            'datetime'
        );
    } catch (Throwable $e) {
        return '—';
    }
}
