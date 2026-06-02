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
 * مرحلة 10: مسح auto_paused عند عودة المخزون + فترة سارية + نشط — لا يمس إيقاف الأدمن أو انتهاء المدة.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $rule
 */
function orange_cart_promo_try_stock_auto_unpause(
    PDO $pdo,
    string $table,
    array $row,
    array $rule,
    ?int $countryId = null
): bool {
    if ($table === 'cart_promotions' || !orange_cart_promo_row_eligible_stock_auto_unpause($row)) {
        return false;
    }
    if (!orange_table_exists($pdo, $table) || !orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        return false;
    }
    if (orange_cart_promo_rule_stock_pause_reason($pdo, $table, $rule, $countryId) !== null) {
        return false;
    }

    $ruleId = (int) ($row['id'] ?? $rule['id'] ?? 0);
    if ($ruleId <= 0) {
        return false;
    }

    $previousReason = trim((string) ($row['auto_paused_reason'] ?? ''));
    orange_cart_promo_clear_auto_pause($pdo, $table, $ruleId);

    require_once __DIR__ . '/cart_promo_monitor.php';
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    orange_cart_promo_log_stock_resume_event($pdo, $table, $ruleId, $previousReason, $cid);

    return true;
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
    $unpauseRow = orange_cart_promo_rule_row_for_stock_unpause($rule);
    if (orange_cart_promo_try_stock_auto_unpause($pdo, $table, $unpauseRow, $rule, $countryId)) {
        $rule['auto_paused_at'] = null;
        $rule['auto_paused_reason'] = null;
    }

    $reason = orange_cart_promo_rule_stock_pause_reason($pdo, $table, $rule, $countryId);
    if ($reason === null) {
        return true;
    }
    $id = (int) ($rule['id'] ?? 0);
    if (!orange_cart_promo_auto_pause_with_reason($pdo, $table, $id, $reason)) {
        return false;
    }
    require_once __DIR__ . '/cart_promo_monitor.php'; // بعد تعريف دوال التقييم — تجنّب دورة تحميل
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    orange_cart_promo_log_pause_event($pdo, $table, $id, $reason, $cid, ['source' => 'storefront_path']);

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
 *   resumed: int,
 *   resumed_rules: list<array{table:string,id:int,previous_reason:string,country_id:int}>,
 *   countries: list<int>
 * }
 */
function orange_cart_promo_run_stock_health(PDO $pdo, ?int $countryId = null, ?array $onlyTables = null): array
{
    require_once __DIR__ . '/cart_promo_monitor.php';
    $sync = orange_cart_promo_sync_stock_checks($pdo, $countryId, $onlyTables, true);

    return [
        'checked' => (int) ($sync['checked'] ?? 0),
        'paused_promo_stock' => (int) ($sync['paused_promo_stock'] ?? 0),
        'paused_gift_stock' => (int) ($sync['paused_gift_stock'] ?? 0),
        'paused' => $sync['paused'] ?? [],
        'resumed' => (int) ($sync['resumed'] ?? 0),
        'resumed_rules' => $sync['resumed_rules'] ?? [],
        'countries' => $sync['countries'] ?? [],
        'rows' => $sync['rows'] ?? [],
    ];
}
