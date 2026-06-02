<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_promo_schedule.php';
require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<string>
 */
function orange_cart_promo_stock_health_tables(): array
{
    return array_merge(orange_cart_promo_scheduled_tables(), ['offers']);
}

/**
 * @return list<int>
 */
function orange_cart_promo_stock_health_country_ids(PDO $pdo, ?int $countryId): array
{
    if ($countryId !== null && $countryId > 0) {
        return [$countryId];
    }
    require_once __DIR__ . '/catalog_multicountry_runtime.php';

    $ids = orange_catalog_active_country_ids($pdo);

    if ($ids !== []) {
        return $ids;
    }
    require_once __DIR__ . '/countries.php';
    $def = orange_countries_default_id($pdo);

    return $def > 0 ? [$def] : [];
}

/**
 * منتجات نشطة في فئة الكتالوج الموحّدة (لـ BOGO same_category).
 *
 * @return list<int>
 */
function orange_cart_promo_catalog_category_product_ids(PDO $pdo, int $categoryId, int $countryId): array
{
    if ($categoryId <= 0) {
        return [];
    }
    require_once __DIR__ . '/catalog_taxonomy_migrate.php';
    require_once __DIR__ . '/department_countries.php';

    if (
        !function_exists('orange_catalog_nav_use_unified')
        || !orange_catalog_nav_use_unified($pdo)
        || !orange_table_exists($pdo, 'product_types')
        || !orange_table_exists($pdo, 'catalog_subcategories')
    ) {
        return [];
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $deptSql = orange_department_country_active_sql($pdo, 'd', $countryId);
    $st = $pdo->prepare(
        'SELECT DISTINCT p.id
         FROM products p
         INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
         INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
         INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1 AND ucc.id = ?
         INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
         INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $deptSql . ')
         WHERE p.is_active = 1' . $countrySql
    );
    $st->execute([$categoryId]);
    $out = [];
    while ($pid = $st->fetchColumn()) {
        $pid = (int) $pid;
        if ($pid > 0) {
            $out[] = $pid;
        }
    }

    return $out;
}

/**
 * كل منتجات الفئة بلا مخزون فعّال للزائر (سياسة مرحلة 8).
 */
function orange_cart_promo_bogo_category_fully_out_of_stock(PDO $pdo, int $categoryId, ?int $countryId): bool
{
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $pids = orange_cart_promo_catalog_category_product_ids($pdo, $categoryId, $cid);
    if ($pids === []) {
        return true;
    }
    foreach ($pids as $pid) {
        if (orange_cart_promo_product_has_visitor_stock($pdo, $pid, [], $cid)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string,mixed> $row
 */
function orange_cart_promo_gift_rule_from_db_row(PDO $pdo, array $row): array
{
    require_once __DIR__ . '/cart_gift_promotions.php';

    $kindRaw = strtolower(trim((string) ($row['gift_kind'] ?? 'choice')));
    $kind = $kindRaw === 'fixed' ? 'fixed' : 'choice';
    $fixedStored = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
    $fixedPid = $fixedStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixedStored) : 0;
    $pool = orange_cart_gift_parse_pool($pdo, $row['pool_variant_ids'] ?? null);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'gift_kind' => $kind,
        'fixed_product_id' => $fixedPid > 0 ? $fixedPid : null,
        'fixed_variant_id' => $fixedPid > 0 ? $fixedPid : null,
        'pool_product_ids' => $pool,
        'pool_variant_ids' => $pool,
        'gift_unit_charge_kind' => (string) ($row['gift_unit_charge_kind'] ?? 'free'),
        'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
        'bogo_kind' => (string) ($row['bogo_kind'] ?? ''),
        'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
        'buy_components_json' => $row['buy_components_json'] ?? null,
        'components_json' => $row['components_json'] ?? null,
        'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
    ];
}

/**
 * سبب الإيقاف أو null إن المخزون كافٍ.
 *
 * @param array<string,mixed> $rule
 */
function orange_cart_promo_rule_stock_pause_reason(
    PDO $pdo,
    string $table,
    array $rule,
    ?int $countryId = null
): ?string {
    $id = (int) ($rule['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);

    if ($table === 'offers') {
        $pid = (int) ($rule['product_id'] ?? 0);
        if ($pid > 0 && !orange_cart_promo_product_has_visitor_stock($pdo, $pid, [], $cid)) {
            return 'promo_stock';
        }

        return null;
    }

    if ($table === 'cart_bogo_promotions') {
        $bogoKind = strtolower(trim((string) ($rule['bogo_kind'] ?? '')));
        if ($bogoKind === 'same_category') {
            $catId = (int) ($rule['category_id'] ?? 0);
            if ($catId > 0 && orange_cart_promo_bogo_category_fully_out_of_stock($pdo, $catId, $cid)) {
                return 'promo_stock';
            }
        }
    }

    if (in_array($table, ['cart_combo_promotions', 'cart_bogo_promotions'], true)) {
        if (!orange_cart_promo_offer_products_have_stock($pdo, $table, $rule, [], $cid)) {
            return 'promo_stock';
        }
    }

    if (in_array($table, orange_cart_promo_gift_stock_tables(), true)) {
        if (!orange_cart_promo_gift_rule_has_stock($pdo, $rule, [], $cid)) {
            return 'gift_stock';
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $rule
 */
function orange_cart_promo_apply_stock_pause_for_rule(
    PDO $pdo,
    string $table,
    array $rule,
    ?int $countryId = null
): bool {
    $reason = orange_cart_promo_rule_stock_pause_reason($pdo, $table, $rule, $countryId);
    if ($reason === null) {
        return true;
    }
    orange_cart_promo_auto_pause_with_reason($pdo, $table, (int) ($rule['id'] ?? 0), $reason);

    return false;
}

/**
 * @param list<string>|null $onlyTables null = الكل
 *
 * @return array{
 *   checked:int,
 *   paused_promo_stock:int,
 *   paused_gift_stock:int,
 *   paused: list<array{table:string,id:int,reason:string,country_id:int}>,
 *   countries: list<int>
 * }
 */
function orange_cart_promo_run_stock_health(PDO $pdo, ?int $countryId = null, ?array $onlyTables = null): array
{
    $result = [
        'checked' => 0,
        'paused_promo_stock' => 0,
        'paused_gift_stock' => 0,
        'paused' => [],
        'countries' => [],
    ];

    $tables = $onlyTables !== null && $onlyTables !== []
        ? array_values(array_intersect(orange_cart_promo_stock_health_tables(), $onlyTables))
        : orange_cart_promo_stock_health_tables();

    $countryIds = orange_cart_promo_stock_health_country_ids($pdo, $countryId);
    $result['countries'] = $countryIds;

    foreach ($countryIds as $cid) {
        foreach ($tables as $table) {
            if ($table === 'cart_promotions') {
                continue;
            }
            if (!orange_table_exists($pdo, $table)) {
                continue;
            }
            if (!orange_table_has_column($pdo, $table, 'auto_paused_at')) {
                continue;
            }

            $scheduleSql = orange_table_has_column($pdo, $table, 'valid_from')
                ? orange_cart_promo_schedule_sql('t')
                : ' AND t.is_active = 1 AND t.auto_paused_at IS NULL';

            if ($table === 'offers') {
                $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $cid);
                $sql = 'SELECT t.id, t.product_id
                        FROM offers t
                        INNER JOIN products p ON p.id = t.product_id
                        WHERE 1=1' . $scheduleSql . $countrySql;
                $st = $pdo->query($sql);
            } else {
                $bind = orange_cart_promotion_sql_bind($pdo, $table, 't', $cid);
                $cols = 't.id';
                if (in_array($table, orange_cart_promo_gift_stock_tables(), true)) {
                    $cols .= ', t.gift_kind, t.fixed_variant_id, t.pool_variant_ids, t.gift_unit_charge_kind, t.gift_unit_charge_value';
                }
                if ($table === 'cart_bogo_promotions') {
                    $cols .= ', t.bogo_kind, t.category_id, t.buy_components_json, t.gift_kind, t.fixed_variant_id, t.pool_variant_ids';
                }
                if ($table === 'cart_combo_promotions') {
                    $cols .= ', t.components_json';
                }
                $st = $pdo->prepare(
                    'SELECT ' . $cols . ' FROM ' . $table . ' t WHERE 1=1' . $scheduleSql . $bind['sql']
                );
                $st->execute($bind['params']);
            }

            if ($st === false) {
                continue;
            }

            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $result['checked']++;
                $rule = orange_cart_promo_gift_rule_from_db_row($pdo, $row);
                if ($table === 'offers') {
                    $rule = [
                        'id' => (int) ($row['id'] ?? 0),
                        'product_id' => (int) ($row['product_id'] ?? 0),
                    ];
                }

                $reason = orange_cart_promo_rule_stock_pause_reason($pdo, $table, $rule, $cid);
                if ($reason === null) {
                    continue;
                }

                orange_cart_promo_auto_pause_with_reason($pdo, $table, (int) $rule['id'], $reason);

                if ($reason === 'gift_stock') {
                    $result['paused_gift_stock']++;
                } else {
                    $result['paused_promo_stock']++;
                }
                $result['paused'][] = [
                    'table' => $table,
                    'id' => (int) $rule['id'],
                    'reason' => $reason,
                    'country_id' => $cid,
                ];
            }
        }
    }

    return $result;
}
