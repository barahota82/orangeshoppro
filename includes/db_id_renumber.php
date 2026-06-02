<?php

declare(strict_types=1);

/**
 * إعادة ترقيم id كثيفة من 1 (قرار المالك — خطة db_id_renumber).
 * يُستدعى من ترحيلات catalog_schema فقط؛ آمن لإعادة التشغيل عند اكتمال الترقيم.
 */

function orange_db_id_renumber_valid_table_name(string $table): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $table);
}

function orange_db_id_renumber_quote_table(string $table): string
{
    if (!orange_db_id_renumber_valid_table_name($table)) {
        throw new InvalidArgumentException('Invalid table name for id renumber');
    }

    return '`' . $table . '`';
}

/**
 * @return bool true إذا MIN(id)=1 ولا فراغات بين MIN و MAX
 */
function orange_db_ids_dense_from_one(PDO $pdo, string $table): bool
{
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, $table)) {
        return true;
    }

    $qt = orange_db_id_renumber_quote_table($table);
    $row = $pdo->query("SELECT MIN(id) AS mn, MAX(id) AS mx, COUNT(*) AS c FROM {$qt}")->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['c'] ?? 0) === 0) {
        return true;
    }

    $mn = (int) $row['mn'];
    $mx = (int) $row['mx'];
    $c = (int) $row['c'];

    return $mn === 1 && ($mx - $mn + 1) === $c;
}

/**
 * @return array<int, int> خريطة id قديم => id جديد (فقط حيث يتغيّر)
 */
function orange_db_build_dense_id_map(PDO $pdo, string $table): array
{
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, $table)) {
        return [];
    }

    $qt = orange_db_id_renumber_quote_table($table);
    $st = $pdo->query("SELECT id FROM {$qt} ORDER BY id ASC");
    if ($st === false) {
        return [];
    }

    $map = [];
    $n = 1;
    while (($raw = $st->fetchColumn()) !== false) {
        $old = (int) $raw;
        if ($old !== $n) {
            $map[$old] = $n;
        }
        ++$n;
    }

    return $map;
}

/**
 * @return array<int, int> خريطة كاملة old=>new بعد الترقيم (فارغة إن لم يُنفَّذ شيء)
 */
function orange_db_renumber_table_to_dense_ids(PDO $pdo, string $table): array
{
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, $table)) {
        return [];
    }

    if (orange_db_ids_dense_from_one($pdo, $table)) {
        return [];
    }

    $qt = orange_db_id_renumber_quote_table($table);
    $ids = $pdo->query("SELECT id FROM {$qt} ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $ids = array_map('intval', $ids);
    if ($ids === []) {
        return [];
    }

    $offset = max($ids) + 10000000;
    $upOffset = $pdo->prepare("UPDATE {$qt} SET id = ? WHERE id = ?");
    foreach ($ids as $old) {
        $upOffset->execute([$old + $offset, $old]);
    }

    $map = [];
    $n = 1;
    $upFinal = $pdo->prepare("UPDATE {$qt} SET id = ? WHERE id = ?");
    foreach ($ids as $old) {
        $temp = $old + $offset;
        $upFinal->execute([$n, $temp]);
        if ($old !== $n) {
            $map[$old] = $n;
        }
        ++$n;
    }

    orange_db_align_table_auto_increment($pdo, $table);

    return $map;
}

/**
 * @param array<int, int> $map
 */
function orange_db_apply_id_map_to_column(
    PDO $pdo,
    string $table,
    string $column,
    array $map,
): void {
    if ($map === [] || !function_exists('orange_table_exists') || !orange_table_exists($pdo, $table)) {
        return;
    }
    if (!function_exists('orange_table_has_column') || !orange_table_has_column($pdo, $table, $column)) {
        return;
    }
    if (!orange_db_id_renumber_valid_table_name($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return;
    }

    $qt = orange_db_id_renumber_quote_table($table);
    $qc = '`' . $column . '`';
    $up = $pdo->prepare("UPDATE {$qt} SET {$qc} = ? WHERE {$qc} = ?");
    foreach ($map as $old => $new) {
        $old = (int) $old;
        $new = (int) $new;
        if ($old === $new || $old <= 0 || $new <= 0) {
            continue;
        }
        $up->execute([$new, $old]);
    }
}

function orange_db_align_table_auto_increment(PDO $pdo, string $table): void
{
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, $table)) {
        return;
    }

    $qt = orange_db_id_renumber_quote_table($table);
    $max = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM {$qt}")->fetchColumn();
    $pdo->exec('ALTER TABLE ' . $qt . ' AUTO_INCREMENT = ' . (string) ($max + 1));
}

/**
 * مرحلة 1: analytical_dimension + storefront_copy_lines (+ محاذاة AUTO_INCREMENT لجداول سليمة).
 */
function orange_db_id_renumber_run_phase1(PDO $pdo): void
{
    $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        if (orange_table_exists($pdo, 'analytical_dimension') && !orange_db_ids_dense_from_one($pdo, 'analytical_dimension')) {
            $dimMap = orange_db_renumber_table_to_dense_ids($pdo, 'analytical_dimension');
            orange_db_apply_id_map_to_column($pdo, 'analytical_dimension_value', 'dimension_id', $dimMap);
        }

        if (orange_table_exists($pdo, 'storefront_copy_lines') && !orange_db_ids_dense_from_one($pdo, 'storefront_copy_lines')) {
            orange_db_renumber_table_to_dense_ids($pdo, 'storefront_copy_lines');
        }

        foreach (['countries', 'fiscal_years', 'storefront_home_hero', 'orange_catalog_schema_checkpoint', 'size_family_advisory_library_map'] as $t) {
            if (orange_table_exists($pdo, $t)) {
                orange_db_align_table_auto_increment($pdo, $t);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
    }
}

/**
 * مرحلة 2: مقاسات، أدلة استرشادية، خيارات سمات الكتالوج.
 */
function orange_db_id_renumber_run_phase2(PDO $pdo): void
{
    $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $schemeMap = [];
        if (orange_table_exists($pdo, 'size_scheme_template_sizes')) {
            $schemeMap = orange_db_renumber_table_to_dense_ids($pdo, 'size_scheme_template_sizes');
            orange_db_apply_id_map_to_column($pdo, 'size_family_sizes', 'scheme_template_size_id', $schemeMap);
        }

        $familyMap = [];
        if (orange_table_exists($pdo, 'size_family_sizes')) {
            $familyMap = orange_db_renumber_table_to_dense_ids($pdo, 'size_family_sizes');
            orange_db_apply_id_map_to_column($pdo, 'product_variants', 'size_family_size_id', $familyMap);
            orange_db_apply_id_map_to_column($pdo, 'advisory_sizing_guide_rows', 'size_family_size_id', $familyMap);
        }

        $colMap = [];
        if (orange_table_exists($pdo, 'advisory_sizing_guide_columns')) {
            $colMap = orange_db_renumber_table_to_dense_ids($pdo, 'advisory_sizing_guide_columns');
        }

        $rowMap = [];
        if (orange_table_exists($pdo, 'advisory_sizing_guide_rows')) {
            $rowMap = orange_db_renumber_table_to_dense_ids($pdo, 'advisory_sizing_guide_rows');
        }

        if (orange_table_exists($pdo, 'advisory_sizing_guide_cells')) {
            orange_db_apply_id_map_to_column($pdo, 'advisory_sizing_guide_cells', 'column_id', $colMap);
            orange_db_apply_id_map_to_column($pdo, 'advisory_sizing_guide_cells', 'row_id', $rowMap);
            orange_db_renumber_table_to_dense_ids($pdo, 'advisory_sizing_guide_cells');
        }

        if (orange_table_exists($pdo, 'catalog_attribute_options')) {
            orange_db_renumber_table_to_dense_ids($pdo, 'catalog_attribute_options');
        }

        if (orange_table_exists($pdo, 'advisory_sizing_guides') && orange_db_ids_dense_from_one($pdo, 'advisory_sizing_guides')) {
            orange_db_align_table_auto_increment($pdo, 'advisory_sizing_guides');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
    }
}

/**
 * مرحلة 3: أنواع القيود وقواعد ربط GL (إعداد محاسبة).
 */
function orange_db_id_renumber_run_phase3(PDO $pdo): void
{
    $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $jtMap = [];
        if (orange_table_exists($pdo, 'journal_types')) {
            $jtMap = orange_db_renumber_table_to_dense_ids($pdo, 'journal_types');
            foreach (
                [
                    ['orange_gl_journal_type_rules', 'journal_type_id'],
                    ['orange_gl_account_settings', 'journal_type_id'],
                    ['journal_vouchers', 'journal_type_id'],
                    ['orange_gl_pending_movements', 'journal_type_id'],
                ] as [$childTable, $childCol]
            ) {
                orange_db_apply_id_map_to_column($pdo, $childTable, $childCol, $jtMap);
            }
        }

        if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
            orange_db_renumber_table_to_dense_ids($pdo, 'orange_gl_journal_type_rules');
        }

        if (orange_table_exists($pdo, 'orange_gl_setting_alloc')
            && orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'id')
            && !orange_db_ids_dense_from_one($pdo, 'orange_gl_setting_alloc')) {
            orange_db_renumber_table_to_dense_ids($pdo, 'orange_gl_setting_alloc');
        } elseif (orange_table_exists($pdo, 'orange_gl_setting_alloc')) {
            orange_db_align_table_auto_increment($pdo, 'orange_gl_setting_alloc');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
    }
}

/**
 * @return list<array{table:string,column:string}>
 */
function orange_db_fetch_incoming_fk_columns(PDO $pdo, string $referencedTable): array
{
    if (!orange_db_id_renumber_valid_table_name($referencedTable)) {
        return [];
    }

    $st = $pdo->prepare(
        'SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = ?
           AND REFERENCED_COLUMN_NAME = \'id\''
    );
    $st->execute([$referencedTable]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $tbl = (string) ($row['tbl'] ?? '');
        $col = (string) ($row['col'] ?? '');
        if ($tbl !== '' && $col !== '' && orange_db_id_renumber_valid_table_name($tbl) && preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
            $out[] = ['table' => $tbl, 'column' => $col];
        }
    }

    return $out;
}

/**
 * @return array<int, int>
 */
function orange_db_renumber_table_and_update_incoming_fks(PDO $pdo, string $table): array
{
    $map = orange_db_renumber_table_to_dense_ids($pdo, $table);
    if ($map === []) {
        return [];
    }

    foreach (orange_db_fetch_incoming_fk_columns($pdo, $table) as $ref) {
        orange_db_apply_id_map_to_column($pdo, $ref['table'], $ref['column'], $map);
    }

    return $map;
}

/**
 * @param array<int, int> $map
 */
function orange_db_apply_party_subledger_party_id(PDO $pdo, string $partyKind, array $map): void
{
    if ($map === [] || $partyKind === '') {
        return;
    }

    foreach (['party_subledger', 'party_subledger_allocations'] as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        $up = $pdo->prepare("UPDATE `{$tbl}` SET party_id = ? WHERE party_kind = ? AND party_id = ?");
        foreach ($map as $old => $new) {
            $up->execute([(int) $new, $partyKind, (int) $old]);
        }
    }
}

/**
 * @param array<int, int> $map
 */
function orange_db_apply_party_subledger_ref_id(PDO $pdo, string $refType, array $map): void
{
    if ($map === [] || $refType === '') {
        return;
    }

    if (orange_table_exists($pdo, 'party_subledger')) {
        $up = $pdo->prepare('UPDATE party_subledger SET ref_id = ? WHERE ref_type = ? AND ref_id = ?');
        foreach ($map as $old => $new) {
            $up->execute([(int) $new, $refType, (int) $old]);
        }
    }

    if (orange_table_exists($pdo, 'party_subledger_allocations')) {
        $up = $pdo->prepare(
            'UPDATE party_subledger_allocations SET target_ref_id = ? WHERE target_ref_type = ? AND target_ref_id = ?'
        );
        foreach ($map as $old => $new) {
            $up->execute([(int) $new, $refType, (int) $old]);
        }
    }
}

/**
 * @param array<int, int> $productMap
 */
function orange_db_apply_product_map_to_cart_promo_tables(PDO $pdo, array $productMap): void
{
    if ($productMap === []) {
        return;
    }

    foreach (['cart_gift_promotions', 'cart_bogo_promotions', 'cart_combo_promotions'] as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (orange_table_has_column($pdo, $tbl, 'fixed_variant_id')) {
            orange_db_apply_id_map_to_column($pdo, $tbl, 'fixed_variant_id', $productMap);
        }
        if (!orange_table_has_column($pdo, $tbl, 'pool_variant_ids')) {
            continue;
        }
        $rows = $pdo->query("SELECT id, pool_variant_ids FROM `{$tbl}` WHERE pool_variant_ids IS NOT NULL AND pool_variant_ids <> ''")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $up = $pdo->prepare("UPDATE `{$tbl}` SET pool_variant_ids = ? WHERE id = ?");
        foreach ($rows as $row) {
            $raw = (string) ($row['pool_variant_ids'] ?? '');
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $changed = false;
            foreach ($decoded as $i => $pid) {
                $old = (int) $pid;
                if ($old > 0 && isset($productMap[$old])) {
                    $decoded[$i] = $productMap[$old];
                    $changed = true;
                }
            }
            if ($changed) {
                $enc = json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE);
                if (is_string($enc)) {
                    $up->execute([$enc, (int) ($row['id'] ?? 0)]);
                }
            }
        }
    }
}

/**
 * @return list<string>
 */
function orange_db_id_renumber_phase4_table_order(): array
{
    return [
        'suppliers',
        'customers',
        'products',
        'product_colorways',
        'product_colorway_images',
        'product_variants',
        'product_images',
        'product_channels',
        'product_attribute_values',
        'offers',
        'orders',
        'order_intake_queue',
        'order_items',
        'purchases',
        'purchase_items',
        'purchase_returns',
        'purchase_return_items',
        'sales_returns',
        'sales_return_items',
        'stock_movements',
        'warehouse_variant_stock',
        'journal_vouchers',
        'journal_lines',
        'party_subledger',
        'party_subledger_allocations',
        'expenses',
        'orange_gl_pending_movements',
        'accounts',
    ];
}

/**
 * مرحلة 4: تشغيل ثقيل — عملاء، منتجات، طلبات، مشتريات، مخزون، قيود، حسابات (إن لزم).
 */
function orange_db_id_renumber_run_phase4(PDO $pdo): void
{
    $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $maps = [];

        foreach (orange_db_id_renumber_phase4_table_order() as $table) {
            if (!orange_table_exists($pdo, $table) || orange_db_ids_dense_from_one($pdo, $table)) {
                continue;
            }

            $maps[$table] = orange_db_renumber_table_and_update_incoming_fks($pdo, $table);
        }

        if (isset($maps['product_colorways']) && $maps['product_colorways'] !== []) {
            orange_db_apply_id_map_to_column($pdo, 'product_colorway_images', 'product_colorway_id', $maps['product_colorways']);
            orange_db_apply_id_map_to_column($pdo, 'product_variants', 'product_colorway_id', $maps['product_colorways']);
        }

        if (isset($maps['customers']) && $maps['customers'] !== []) {
            orange_db_apply_party_subledger_party_id($pdo, 'customer', $maps['customers']);
        }
        if (isset($maps['suppliers']) && $maps['suppliers'] !== []) {
            orange_db_apply_party_subledger_party_id($pdo, 'supplier', $maps['suppliers']);
        }
        if (isset($maps['orders']) && $maps['orders'] !== []) {
            orange_db_apply_party_subledger_ref_id($pdo, 'order', $maps['orders']);
        }
        if (isset($maps['purchases']) && $maps['purchases'] !== []) {
            orange_db_apply_party_subledger_ref_id($pdo, 'purchase', $maps['purchases']);
        }
        if (isset($maps['purchase_returns']) && $maps['purchase_returns'] !== []) {
            orange_db_apply_party_subledger_ref_id($pdo, 'purchase_return', $maps['purchase_returns']);
        }
        if (isset($maps['sales_returns']) && $maps['sales_returns'] !== []) {
            orange_db_apply_party_subledger_ref_id($pdo, 'sales_return', $maps['sales_returns']);
        }
        if (isset($maps['products']) && $maps['products'] !== []) {
            orange_db_apply_product_map_to_cart_promo_tables($pdo, $maps['products']);
        }
        if (isset($maps['product_variants']) && $maps['product_variants'] !== []) {
            orange_db_apply_id_map_to_column($pdo, 'warehouse_variant_stock', 'variant_id', $maps['product_variants']);
        }

        if (orange_table_exists($pdo, 'journal_entries') && !orange_db_ids_dense_from_one($pdo, 'journal_entries')) {
            $jeMap = orange_db_renumber_table_and_update_incoming_fks($pdo, 'journal_entries');
            if ($jeMap !== [] && isset($maps['accounts']) && $maps['accounts'] !== []) {
                orange_db_apply_id_map_to_column($pdo, 'journal_entries', 'account_debit', $maps['accounts']);
                orange_db_apply_id_map_to_column($pdo, 'journal_entries', 'account_credit', $maps['accounts']);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
    }
}

/**
 * إعادة ترقيم channels → 1..n بعد حذف المكرر يدوياً (يحدّث product_channels، orders، sales_returns).
 *
 * @return array<int, int> خريطة قديم=>جديد (فارغة إن لم يُنفَّذ شيء)
 */
function orange_db_id_renumber_run_channels(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'channels') || orange_db_ids_dense_from_one($pdo, 'channels')) {
        return [];
    }

    $prevFk = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        $map = orange_db_renumber_table_and_update_incoming_fks($pdo, 'channels');
        foreach (
            [
                ['product_channels', 'channel_id'],
                ['orders', 'channel_id'],
                ['sales_returns', 'channel_id'],
            ] as [$tbl, $col]
        ) {
            orange_db_apply_id_map_to_column($pdo, $tbl, $col, $map);
        }

        return $map;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (string) $prevFk);
    }
}
