<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/admin_settings_country.php';

function orange_opening_stock_lock_column_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'company_settings')
        && orange_table_has_column($pdo, 'company_settings', 'opening_stock_locked');
}

function orange_opening_stock_lock_country_id(PDO $pdo, ?int $countryId = null): int
{
    if ($countryId !== null && $countryId > 0) {
        return $countryId;
    }

    return orange_admin_settings_effective_country_id($pdo, $countryId);
}

function orange_opening_stock_is_locked(PDO $pdo, ?int $countryId = null): bool
{
    if (!orange_opening_stock_lock_column_ready($pdo)) {
        return false;
    }
    $cid = orange_opening_stock_lock_country_id($pdo, $countryId);
    if ($cid <= 0) {
        return false;
    }
    orange_company_settings_ensure_row($pdo, $cid);
    $st = $pdo->prepare('SELECT opening_stock_locked FROM company_settings WHERE country_id = ? LIMIT 1');
    $st->execute([$cid]);

    return (int) ($st->fetchColumn() ?: 0) === 1;
}

function orange_opening_stock_set_locked(PDO $pdo, bool $locked, ?int $countryId = null): void
{
    if (!orange_opening_stock_lock_column_ready($pdo)) {
        throw new RuntimeException('عمود إقفال رصيد المخزون غير جاهز — حدّث المخطط');
    }
    $cid = orange_opening_stock_lock_country_id($pdo, $countryId);
    if ($cid <= 0) {
        throw new RuntimeException('حدّد سياق الدولة أولاً');
    }
    orange_company_settings_ensure_row($pdo, $cid);
    $pdo->prepare('UPDATE company_settings SET opening_stock_locked = ? WHERE country_id = ?')
        ->execute([$locked ? 1 : 0, $cid]);
}
