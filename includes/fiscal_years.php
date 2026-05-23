<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/admin_settings_country.php';
require_once __DIR__ . '/countries.php';

function orange_fiscal_years_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'fiscal_years')
        && orange_table_has_column($pdo, 'fiscal_years', 'country_id');
}

/**
 * @return list<array<string, mixed>>
 */
function orange_fiscal_years_list(PDO $pdo, ?int $countryId = null): array
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return [];
    }
    if (orange_fiscal_years_has_country_column($pdo)) {
        $cid = orange_admin_settings_effective_country_id($pdo, $countryId);
        $st = $pdo->prepare(
            'SELECT * FROM fiscal_years WHERE country_id = ? ORDER BY start_date DESC, id DESC'
        );
        $st->execute([$cid]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $pdo->query('SELECT * FROM fiscal_years ORDER BY start_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, mixed>|null
 */
function orange_fiscal_find_for_date(PDO $pdo, string $dateYmdOrDatetime, ?int $countryId = null): ?array
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return null;
    }
    $d = substr($dateYmdOrDatetime, 0, 10);
    if (orange_fiscal_years_has_country_column($pdo)) {
        $cid = orange_admin_settings_effective_country_id($pdo, $countryId);
        $st = $pdo->prepare(
            'SELECT * FROM fiscal_years WHERE country_id = ? AND ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$cid, $d]);
    } else {
        $st = $pdo->prepare(
            'SELECT * FROM fiscal_years WHERE ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$d]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * يمنع القيد على سنة مغلقة أو خارج أي سنة معرفة.
 *
 * @throws RuntimeException
 */
function orange_fiscal_require_open_for_posting(PDO $pdo, string $datetime, ?int $countryId = null): int
{
    orange_catalog_ensure_schema($pdo);
    $row = orange_fiscal_find_for_date($pdo, $datetime, $countryId);
    if (!$row) {
        throw new RuntimeException(
            'لا توجد سنة مالية تغطي تاريخ القيد. عرّف السنة من «السنوات المالية».'
        );
    }
    if ((int) $row['is_closed'] === 1) {
        $label = trim((string) ($row['label_ar'] ?? ''));
        if ($label === '') {
            $label = '#' . (int) $row['id'];
        }
        throw new RuntimeException('السنة المالية «' . $label . '» مغلقة — لا يمكن إضافة أو عكس قيود عليها.');
    }

    return (int) $row['id'];
}

function orange_fiscal_is_closed_for_entry(PDO $pdo, array $journalRow, ?int $countryId = null): bool
{
    orange_catalog_ensure_schema($pdo);
    $fyId = (int) ($journalRow['fiscal_year_id'] ?? 0);
    if ($fyId > 0 && orange_table_exists($pdo, 'fiscal_years')) {
        $st = $pdo->prepare('SELECT is_closed FROM fiscal_years WHERE id = ? LIMIT 1');
        $st->execute([$fyId]);
        $c = (int) $st->fetchColumn();

        return $c === 1;
    }
    $d = (string) ($journalRow['date'] ?? '');
    $fy = orange_fiscal_find_for_date($pdo, $d, $countryId);

    return $fy ? ((int) $fy['is_closed'] === 1) : false;
}

/**
 * السنة المالية التالية لإقفال ملخص الدخل إلى المحتجز.
 *
 * @return array<string, mixed>|null
 */
function orange_fiscal_year_next_after_end(PDO $pdo, string $endDateYmd, ?int $countryId = null): ?array
{
    if (! orange_table_exists($pdo, 'fiscal_years') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDateYmd)) {
        return null;
    }
    $nextDay = date('Y-m-d', strtotime($endDateYmd . ' +1 day'));
    $scoped = orange_fiscal_years_has_country_column($pdo);
    $cid = $scoped ? orange_admin_settings_effective_country_id($pdo, $countryId) : 0;
    if ($scoped && $cid > 0) {
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE country_id = ? AND start_date = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$cid, $nextDay]);
    } else {
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE start_date = ? ORDER BY id ASC LIMIT 1');
        $st->execute([$nextDay]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    if ($scoped && $cid > 0) {
        $st = $pdo->prepare(
            'SELECT * FROM fiscal_years WHERE country_id = ? AND start_date > ? ORDER BY start_date ASC, id ASC LIMIT 1'
        );
        $st->execute([$cid, $endDateYmd]);
    } else {
        $st = $pdo->prepare('SELECT * FROM fiscal_years WHERE start_date > ? ORDER BY start_date ASC, id ASC LIMIT 1');
        $st->execute([$endDateYmd]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * تداخل النطاقات: يوجد صف يقطع المدى [start,end]؟
 */
function orange_fiscal_range_overlaps_existing(PDO $pdo, string $start, string $end, ?int $exceptId = null, ?int $countryId = null): bool
{
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        return false;
    }
    $scoped = orange_fiscal_years_has_country_column($pdo);
    $cid = $scoped ? orange_admin_settings_effective_country_id($pdo, $countryId) : 0;
    $sql = 'SELECT COUNT(*) FROM fiscal_years WHERE NOT (end_date < ? OR start_date > ?)';
    $params = [$start, $end];
    if ($scoped && $cid > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $cid;
    }
    if ($exceptId !== null && $exceptId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn() > 0;
}

/** هل توجد قيود/مستندات مرتبطة بهذه السنة؟ (يمنع الحذف) */
function orange_fiscal_year_has_journal_activity(PDO $pdo, int $fiscalYearId): bool
{
    if ($fiscalYearId <= 0) {
        return false;
    }
    if (orange_table_exists($pdo, 'journal_vouchers')) {
        $st = $pdo->prepare('SELECT 1 FROM journal_vouchers WHERE fiscal_year_id = ? LIMIT 1');
        $st->execute([$fiscalYearId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }
    if (orange_table_exists($pdo, 'journal_entries')) {
        $st = $pdo->prepare('SELECT 1 FROM journal_entries WHERE fiscal_year_id = ? LIMIT 1');
        $st->execute([$fiscalYearId]);
        if ($st->fetchColumn()) {
            return true;
        }
    }

    return false;
}

function orange_fiscal_year_country_id(PDO $pdo, int $fiscalYearId): int
{
    if ($fiscalYearId <= 0 || !orange_fiscal_years_has_country_column($pdo)) {
        return 0;
    }
    $st = $pdo->prepare('SELECT country_id FROM fiscal_years WHERE id = ? LIMIT 1');
    $st->execute([$fiscalYearId]);
    $cid = (int) ($st->fetchColumn() ?: 0);

    return $cid > 0 ? $cid : orange_countries_default_id($pdo);
}

/** رمز السوق للعرض في مرجع OB (KW، EG، UAE، KSA، …). */
function orange_opening_balance_country_code(PDO $pdo, ?int $countryId = null): string
{
    if ($countryId === null || $countryId <= 0) {
        if (function_exists('orange_admin_context_country_id')) {
            $countryId = orange_admin_context_country_id($pdo);
        }
    }
    if ($countryId > 0) {
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if ($row !== null) {
            $raw = trim((string) ($row['code'] ?? ''));
            if ($raw !== '') {
                return orange_countries_display_code($raw);
            }
        }
    }

    return orange_countries_display_code(orange_admin_context_country_code($pdo));
}

/** مرجع سند رصيد افتتاحي: OBV-KW-1، OBV-EG-2، … (الجزء الأخير = voucher_serial). */
function orange_opening_balance_reference(PDO $pdo, int $fyId, ?int $countryId = null): string
{
    if ($fyId <= 0) {
        return '';
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_fiscal_year_country_id($pdo, $fyId);
    }
    if (!function_exists('orange_voucher_auto_reference_preview')) {
        require_once __DIR__ . '/journal_voucher.php';
    }

    return orange_voucher_auto_reference_preview(
        $pdo,
        'opening_balance',
        $fyId,
        $countryId > 0 ? $countryId : null
    );
}

/** مراجع قديمة قبل OBV/رمز الدولة. */
function orange_opening_balance_reference_legacy(int $fyId): string
{
    return $fyId > 0 ? 'OB-' . $fyId : '';
}

function orange_opening_balance_clear_pending_refs(PDO $pdo, int $fyId, ?int $countryId = null): void
{
    require_once __DIR__ . '/gl_pending_movements.php';
    if (!function_exists('orange_gl_pending_source_key')) {
        require_once __DIR__ . '/journal_voucher.php';
    }
    orange_gl_pending_remove_by_reference($pdo, orange_opening_balance_reference_legacy($fyId));
    orange_gl_pending_remove_by_reference($pdo, orange_opening_balance_reference($pdo, $fyId, $countryId));
    if ($fyId > 0) {
        orange_gl_pending_remove_by_reference($pdo, orange_gl_pending_source_key('opening_balance', $fyId));
    }
}
