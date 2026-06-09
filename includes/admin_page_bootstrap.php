<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

/**
 * PDO لصفحات الأدمن: يعيد اتصال index.php دون إعادة ترحيل المخطط.
 */
function orange_admin_page_pdo(): PDO
{
    if (isset($GLOBALS['orangeAdminPdo']) && $GLOBALS['orangeAdminPdo'] instanceof PDO) {
        return $GLOBALS['orangeAdminPdo'];
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (function_exists('orange_catalog_ensure_country_id_columns_once')) {
        orange_catalog_ensure_country_id_columns_once($pdo);
    }

    return $pdo;
}

/** اسم الدولة العربي (أو رمز العرض) لسياق الأدمن الحالي — لسطر «سياق الدولة» في ترويسة الشاشة. */
function orange_admin_page_country_label(PDO $pdo): string
{
    $id = orange_admin_context_country_id($pdo);
    if ($id > 0) {
        $row = orange_country_row_by_id($pdo, $id, false);
        $label = trim((string) ($row['name_ar'] ?? ''));
        if ($label === '' && $row !== null) {
            $label = trim((string) ($row['name_en'] ?? ''));
        }
        if ($label !== '') {
            return $label;
        }
    }

    return orange_countries_display_code(orange_admin_context_country_code($pdo));
}
