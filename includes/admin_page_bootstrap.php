<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

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
