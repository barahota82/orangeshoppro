<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

function orange_cart_promotion_storefront_country_id(PDO $pdo, ?int $countryId): int
{
    if ($countryId !== null && $countryId > 0) {
        return $countryId;
    }
    require_once __DIR__ . '/countries.php';

    return orange_storefront_current_country_id($pdo);
}

/**
 * @return array{sql:string, params:list<int>}
 */
function orange_cart_promotion_sql_bind(PDO $pdo, string $table, string $alias, int $countryId): array
{
    if ($countryId <= 0 || !orange_table_has_country_id($pdo, $table)) {
        return ['sql' => '', 'params' => []];
    }
    $col = trim($alias) !== '' ? trim($alias) . '.country_id' : $table . '.country_id';

    return ['sql' => ' AND ' . $col . ' = ?', 'params' => [$countryId]];
}

function orange_cart_promotion_admin_country_id(PDO $pdo): int
{
    require_once __DIR__ . '/countries.php';
    $cid = orange_admin_context_country_id($pdo);
    if ($cid > 0) {
        return $cid;
    }

    return orange_countries_default_id($pdo);
}

/**
 * assert على التعديل؛ يُرجع country_id للإدراج.
 *
 * @throws RuntimeException
 */
function orange_cart_promotion_prepare_admin_save(PDO $pdo, string $table, int $id): int
{
    require_once __DIR__ . '/countries.php';
    if ($id > 0) {
        orange_admin_assert_row_country($pdo, $table, $id);

        return 0;
    }
    $cid = orange_cart_promotion_admin_country_id($pdo);
    if ($cid <= 0) {
        throw new RuntimeException('تعذر تحديد الدولة لحفظ عرض السلة.');
    }

    return $cid;
}
