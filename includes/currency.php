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

/** وحدة العرض المختصرة في الأدمن (KWD → KD محلياً؛ غير ذلك رمز ISO). */
function orange_currency_display_unit(string $currencyCode): string
{
    $code = strtoupper(trim($currencyCode));

    return $code === 'KWD' ? 'KD' : ($code !== '' ? $code : 'KWD');
}

/**
 * @return array<string, string> ISO => تسمية عربية للقوائم
 */
function orange_currency_option_labels(): array
{
    return [
        'KWD' => 'دينار كويتي (KWD)',
        'EGP' => 'جنيه مصري (EGP)',
        'SAR' => 'ريال سعودي (SAR)',
        'AED' => 'درهم إماراتي (AED)',
        'QAR' => 'ريال قطري (QAR)',
        'BHD' => 'دينار بحريني (BHD)',
        'OMR' => 'ريال عُماني (OMR)',
        'JOD' => 'دينار أردني (JOD)',
        'LBP' => 'ليرة لبنانية (LBP)',
        'IQD' => 'دينار عراقي (IQD)',
        'MAD' => 'درهم مغربي (MAD)',
        'TND' => 'دينار تونسي (TND)',
        'DZD' => 'دينار جزائري (DZD)',
        'LYD' => 'دينار ليبي (LYD)',
        'SDG' => 'جنيه سوداني (SDG)',
        'YER' => 'ريال يمني (YER)',
        'TRY' => 'ليرة تركية (TRY)',
        'USD' => 'دولار أمريكي (USD)',
    ];
}

function orange_currency_option_label(string $currencyCode): string
{
    $code = strtoupper(trim($currencyCode));
    if ($code === '') {
        return '';
    }
    $labels = orange_currency_option_labels();

    return $labels[$code] ?? ($code . ' (' . $code . ')');
}

/**
 * خيارات select في الأدmin — عملة السياق أولاً ثم عملات الأسواق المرجعية.
 *
 * @return array<string, string>
 */
function orange_currency_admin_select_options(PDO $pdo, ?string $primaryCode = null): array
{
    $primary = strtoupper(trim($primaryCode ?? orange_admin_context_currency_code($pdo)));
    if ($primary === '' || !preg_match('/^[A-Z]{3}$/', $primary)) {
        $primary = 'KWD';
    }
    $options = [$primary => orange_currency_option_label($primary)];
    foreach (orange_countries_currency_map() as $code) {
        $cur = strtoupper(trim((string) $code));
        if ($cur === '' || !preg_match('/^[A-Z]{3}$/', $cur) || isset($options[$cur])) {
            continue;
        }
        $options[$cur] = orange_currency_option_label($cur);
    }

    return $options;
}

function orange_format_money_amount(float $amount, string $currencyCode, bool $withUnit = true): string
{
    $dec = orange_currency_decimals_for_code($currencyCode);
    $formatted = number_format($amount, $dec, '.', ',');
    if (!$withUnit) {
        return $formatted;
    }

    return $formatted . ' ' . orange_currency_display_unit($currencyCode);
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
