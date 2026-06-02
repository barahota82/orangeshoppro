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
