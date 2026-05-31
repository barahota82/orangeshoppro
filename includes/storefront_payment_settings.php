<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/company_settings.php';

function orange_company_settings_has_payment_online_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'company_settings')
        && orange_table_has_column($pdo, 'company_settings', 'payment_online_enabled');
}

/**
 * هل خيار الدفع الإلكتروني مفعّل لدولة المتجر؟
 */
function orange_storefront_payment_online_enabled(PDO $pdo, ?int $countryId = null, bool $forStorefront = true): bool
{
    if (!orange_company_settings_has_payment_online_column($pdo)) {
        return false;
    }

    $row = orange_company_settings_row($pdo, $countryId, $forStorefront);
    if (!is_array($row)) {
        return false;
    }

    return (int) ($row['payment_online_enabled'] ?? 0) === 1;
}

/**
 * @param array<string, mixed> $row
 */
function orange_storefront_payment_online_enabled_from_row(array $row): bool
{
    return (int) ($row['payment_online_enabled'] ?? 0) === 1;
}
