<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

/**
 * دولة سياق إعدادات الأدmin (مبدّل الشريط أو الكويت الافتراضية).
 */
function orange_admin_settings_effective_country_id(PDO $pdo, ?int $countryId = null): int
{
    if ($countryId !== null && $countryId > 0) {
        return $countryId;
    }
    $ctx = orange_admin_context_country_id($pdo);

    return $ctx > 0 ? $ctx : orange_countries_default_id($pdo);
}

/**
 * دولة سياق الواجهة (cookie / قناة / افتراضي).
 */
function orange_storefront_settings_country_id(PDO $pdo): int
{
    return orange_storefront_current_country_id($pdo);
}
