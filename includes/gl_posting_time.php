<?php

declare(strict_types=1);

/**
 * Phase 2 / Step 5 — GL posting time contracts.
 *
 * - Accounting voucher_date (DATETIME column): Date-only Y-m-d stored as Y-m-d 12:00:00
 *   (calendar day of the voucher country; NOT converted through UTC).
 * - Posting / entry Absolute Moments: UTC MySQL wall via admin_time.
 *
 * @see docs/archive/ORANGE_ADMIN_TIME_POLICY.txt
 */

require_once __DIR__ . '/admin_time.php';

/**
 * Normalize an accounting calendar day into journal_vouchers.voucher_date DATETIME shape.
 *
 * @throws OrangeAdminTimeConfigException
 */
function orange_gl_accounting_voucher_date_mysql(string $ymdOrDatetime): string
{
    $raw = trim($ymdOrDatetime);
    if ($raw === '') {
        throw new OrangeAdminTimeConfigException('admin_time_accounting_date_required');
    }
    $ymd = orange_admin_time_date_only_normalize(substr($raw, 0, 10));
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_accounting_date_invalid');
    }

    return $ymd . ' 12:00:00';
}

/**
 * Extract accounting Y-m-d from a stored voucher_date DATETIME (first 10 chars).
 */
function orange_gl_accounting_ymd_from_voucher_datetime(string $voucherDateMysql): string
{
    return orange_admin_time_date_only_normalize(substr(trim($voucherDateMysql), 0, 10));
}

/**
 * Bundle for automated / manual GL writers that need both semantics.
 *
 * @return array{
 *   accounting_ymd:string,
 *   voucher_date:string,
 *   document_entered_at:string,
 *   movement_at:string,
 *   posting_instant_utc:string
 * }
 * @throws OrangeAdminTimeConfigException
 */
function orange_gl_posting_times_for_country(PDO $pdo, int $countryId, ?string $accountingYmd = null): array
{
    if ($countryId <= 0) {
        throw new OrangeAdminTimeConfigException('admin_time_country_id_required');
    }
    // Touch IANA early — fail closed on missing/invalid country timezone.
    orange_admin_time_timezone_for_country_id($pdo, $countryId);

    $ymd = $accountingYmd !== null && trim($accountingYmd) !== ''
        ? orange_admin_time_date_only_normalize(substr(trim($accountingYmd), 0, 10))
        : orange_admin_time_document_date_today_for_country_id($pdo, $countryId);
    if ($ymd === '') {
        throw new OrangeAdminTimeConfigException('admin_time_accounting_date_invalid');
    }
    $instant = orange_admin_time_utc_now_mysql();
    $voucherDate = orange_gl_accounting_voucher_date_mysql($ymd);

    return [
        'accounting_ymd' => $ymd,
        'voucher_date' => $voucherDate,
        'document_entered_at' => $instant,
        'movement_at' => $instant,
        'posting_instant_utc' => $instant,
    ];
}
