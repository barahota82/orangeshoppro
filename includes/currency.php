<?php

declare(strict_types=1);

require_once __DIR__ . '/countries.php';

/** عدد الخانات العشرية للعرض والتقريب حسب ISO 4217 (بدون FX). */
function orange_currency_decimals_for_code(string $currencyCode): int
{
    $code = strtoupper(trim($currencyCode));
    static $three = [
        'KWD' => true,
        'BHD' => true,
        'OMR' => true,
        'JOD' => true,
        'IQD' => true,
        'LYD' => true,
        'TND' => true,
    ];

    return isset($three[$code]) ? 3 : 2;
}

/**
 * عملة الدفتر (functional) لدولة محددة — من countries ثم الخريطة المرجعية.
 */
function orange_country_functional_currency_code(PDO $pdo, int $countryId): string
{
    if ($countryId <= 0) {
        return 'KWD';
    }
    if (orange_table_exists($pdo, 'countries')) {
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if ($row !== null) {
            $fromDb = strtoupper(trim((string) ($row['currency_code'] ?? '')));
            if ($fromDb !== '' && preg_match('/^[A-Z]{3}$/', $fromDb)) {
                return $fromDb;
            }
            $marketCode = trim((string) ($row['code'] ?? ''));
            $fromMap = orange_countries_currency_for_code($marketCode);
            if ($fromMap !== '' && preg_match('/^[A-Z]{3}$/', $fromMap)) {
                return $fromMap;
            }
        }
    }

    return 'KWD';
}

/** عملة GL/المستند: دولة صريحة أو سياق الأدمن. */
function orange_gl_functional_currency_code(PDO $pdo, ?int $countryId = null): string
{
    if ($countryId !== null && $countryId > 0) {
        return orange_country_functional_currency_code($pdo, $countryId);
    }

    return orange_admin_context_currency_code($pdo);
}

function orange_gl_round_money(float $amount, string $currencyCode): float
{
    $dec = orange_currency_decimals_for_code($currencyCode);

    return round($amount, $dec);
}

function orange_gl_money_is_balanced(float $debit, float $credit, string $currencyCode): bool
{
    $dec = orange_currency_decimals_for_code($currencyCode);
    $eps = pow(10, -$dec) / 2;

    return abs($debit - $credit) <= $eps;
}

/**
 * يضيف currency_code لـ INSERT ديناميكي إن وُجد العمود.
 */
function orange_sql_append_document_currency_code(
    PDO $pdo,
    string $table,
    int $countryId,
    string &$cols,
    string &$placeholders,
    array &$params
): void {
    if (!orange_table_has_column($pdo, $table, 'currency_code')) {
        return;
    }
    $cols .= ', currency_code';
    $placeholders .= ', ?';
    $params[] = orange_gl_functional_currency_code($pdo, $countryId > 0 ? $countryId : null);
}
