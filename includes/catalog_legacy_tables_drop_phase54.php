<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/catalog_legacy_closure_phase5.php';

const ORANGE_CATALOG_LEGACY_TABLES_DROPPED_STEP = 'legacy_taxonomy_tables_dropped_v1';

/**
 * @return list<string>
 */
function orange_catalog_foreign_key_names_on_table(PDO $pdo, string $tableName, ?string $referencedTable = null): array
{
    $tableName = trim($tableName);
    $referencedTable = $referencedTable !== null ? trim($referencedTable) : null;
    if ($tableName === '' && ($referencedTable === null || $referencedTable === '')) {
        return [];
    }
    try {
        $sql = 'SELECT DISTINCT k.CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t
               ON k.CONSTRAINT_NAME = t.CONSTRAINT_NAME
              AND k.TABLE_SCHEMA = t.TABLE_SCHEMA
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND t.CONSTRAINT_TYPE = \'FOREIGN KEY\'';
        $params = [];
        if ($referencedTable !== null && $referencedTable !== '') {
            $sql .= ' AND k.REFERENCED_TABLE_NAME = ?';
            $params[] = $referencedTable;
        } else {
            $sql .= ' AND k.TABLE_NAME = ?';
            $params[] = $tableName;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: [])));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array{legacy_categories_table:bool,legacy_subcategories_table:bool,step_applied:bool,ready:bool}
 */
function orange_catalog_phase54_gap_report(PDO $pdo): array
{
    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
    $p5 = orange_catalog_phase5_gap_report($pdo);
    $ready = $unified
        && empty($p5['legacy_category_column'])
        && empty($p5['legacy_subcategory_column'])
        && !empty($p5['product_type_id_not_null']);

    return [
        'legacy_categories_table' => orange_table_exists($pdo, 'categories'),
        'legacy_subcategories_table' => orange_table_exists($pdo, 'subcategories'),
        'step_applied' => orange_catalog_migration_step_applied($pdo, ORANGE_CATALOG_LEGACY_TABLES_DROPPED_STEP),
        'ready' => $ready,
    ];
}

/**
 * المرحلة 5.4 — إسقاط جداول taxonomy التراثية categories / subcategories بعد إغلاق 5.1–5.3.
 */
function orange_catalog_ensure_legacy_taxonomy_tables_dropped_phase54(PDO $pdo): void
{
    if (orange_catalog_migration_step_applied($pdo, ORANGE_CATALOG_LEGACY_TABLES_DROPPED_STEP)) {
        return;
    }
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return;
    }
    if (!orange_table_exists($pdo, 'categories') && !orange_table_exists($pdo, 'subcategories')) {
        orange_catalog_migration_step_record($pdo, ORANGE_CATALOG_LEGACY_TABLES_DROPPED_STEP);

        return;
    }

    if (orange_table_has_column($pdo, 'products', 'category_id')
        || orange_table_has_column($pdo, 'products', 'subcategory_id')) {
        orange_catalog_ensure_legacy_closure_phase5($pdo);
        if (orange_table_has_column($pdo, 'products', 'category_id')
            || orange_table_has_column($pdo, 'products', 'subcategory_id')) {
            return;
        }
    }

    if (!orange_catalog_phase5_product_type_id_not_null_applied($pdo)) {
        orange_catalog_ensure_products_product_type_id_not_null($pdo);
        if (!orange_catalog_phase5_product_type_id_not_null_applied($pdo)) {
            return;
        }
    }

    foreach (['subcategories', 'categories'] as $ref) {
        foreach (orange_catalog_foreign_key_names_on_table($pdo, '', $ref) as $fkName) {
            $tblSt = $pdo->prepare(
                'SELECT k.TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.CONSTRAINT_NAME = ? LIMIT 1'
            );
            $tblSt->execute([$fkName]);
            $tbl = trim((string) ($tblSt->fetchColumn() ?: ''));
            if ($tbl === '') {
                continue;
            }
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE `' . str_replace('`', '``', $tbl) . '` DROP FOREIGN KEY `' . str_replace('`', '``', $fkName) . '`'
            );
        }
    }

    try {
        if (orange_table_exists($pdo, 'subcategories')) {
            foreach (orange_catalog_foreign_key_names_on_table($pdo, 'subcategories') as $fkName) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE subcategories DROP FOREIGN KEY `' . str_replace('`', '``', $fkName) . '`'
                );
            }
            orange_catalog_safe_exec($pdo, 'DROP TABLE IF EXISTS subcategories');
            orange_schema_invalidate_table_exists('subcategories');
        }
        if (orange_table_exists($pdo, 'categories')) {
            foreach (orange_catalog_foreign_key_names_on_table($pdo, 'categories') as $fkName) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE categories DROP FOREIGN KEY `' . str_replace('`', '``', $fkName) . '`'
                );
            }
            orange_catalog_safe_exec($pdo, 'DROP TABLE IF EXISTS categories');
            orange_schema_invalidate_table_exists('categories');
        }
        orange_catalog_migration_step_record($pdo, ORANGE_CATALOG_LEGACY_TABLES_DROPPED_STEP);
        if (function_exists('error_log')) {
            error_log('[orange] phase54: dropped legacy categories/subcategories tables');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_legacy_taxonomy_tables_dropped_phase54: ' . $e->getMessage());
        }
    }
}
