<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/admin_settings_country.php';

function orange_company_settings_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'company_settings')
        && orange_table_has_column($pdo, 'company_settings', 'country_id');
}

/**
 * @return array<string, mixed>|null
 */
function orange_company_settings_row(PDO $pdo, ?int $countryId = null, bool $forStorefront = false): ?array
{
    if (!orange_table_exists($pdo, 'company_settings')) {
        return null;
    }

    if (orange_company_settings_has_country_column($pdo)) {
        $cid = $forStorefront
            ? orange_storefront_settings_country_id($pdo)
            : orange_admin_settings_effective_country_id($pdo, $countryId);
        if ($cid <= 0) {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM company_settings WHERE country_id = ? LIMIT 1');
        $st->execute([$cid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        return orange_company_settings_ensure_row($pdo, $cid);
    }

    $row = $pdo->query('SELECT * FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>
 */
function orange_company_settings_ensure_row(PDO $pdo, int $countryId): array
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'company_settings')) {
        return [];
    }
    if (orange_company_settings_has_country_column($pdo)) {
        $st = $pdo->prepare('SELECT id FROM company_settings WHERE country_id = ? LIMIT 1');
        $st->execute([$countryId]);
        if (!$st->fetchColumn()) {
            $ins = $pdo->prepare(
                'INSERT INTO company_settings (country_id, company_name_ar, company_name_en, company_logo, commercial_register, phones, address, vat_number, invoice_footer)
                 VALUES (?, \'\', \'\', \'\', \'\', \'\', \'\', \'\', NULL)'
            );
            $ins->execute([$countryId]);
        }
        $row = orange_company_settings_row($pdo, $countryId, false);
        if (is_array($row)) {
            return $row;
        }
    }

    return [];
}

function orange_company_settings_name_ar(PDO $pdo, ?int $countryId = null, bool $forStorefront = false): string
{
    $row = orange_company_settings_row($pdo, $countryId, $forStorefront);
    if (!is_array($row)) {
        return '';
    }

    return trim((string) ($row['company_name_ar'] ?? ''));
}
