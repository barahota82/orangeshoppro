<?php

declare(strict_types=1);

/**
 * فراغ أدلة المقاس الاسترشادية + ربط المكتبة — قرار المالك 2026-06-21.
 *
 * @return list<string> سطور ملخص (عدد الصفوف المحذوفة / إعادة العداد)
 */
function orange_advisory_sizing_wipe_all(PDO $pdo): array
{
    if (!function_exists('orange_table_exists')) {
        require_once __DIR__ . '/catalog_schema.php';
    }

    if (!orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return ['advisory_sizing_guides table missing — skipped'];
    }

    $steps = [];

    $pdo->beginTransaction();
    try {
        if (orange_table_exists($pdo, 'advisory_sizing_guide_cells')) {
            $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_cells');
            $steps[] = 'advisory_sizing_guide_cells deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'advisory_sizing_guide_rows')) {
            $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_rows');
            $steps[] = 'advisory_sizing_guide_rows deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'advisory_sizing_guide_columns')) {
            $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_columns');
            $steps[] = 'advisory_sizing_guide_columns deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'advisory_sizing_guides')) {
            $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guides');
            $steps[] = 'advisory_sizing_guides deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'size_family_advisory_library_map')) {
            $n = (int) $pdo->exec('DELETE FROM size_family_advisory_library_map');
            $steps[] = 'size_family_advisory_library_map deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'advisory_sizing_library_bundles')) {
            $n = (int) $pdo->exec('DELETE FROM advisory_sizing_library_bundles');
            $steps[] = 'advisory_sizing_library_bundles deleted: ' . $n;
        }
        if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
            $n = (int) $pdo->exec(
                'UPDATE products SET sizing_advisory_guide_id = NULL, sizing_guide_scope = \'none\'
                 WHERE sizing_advisory_guide_id IS NOT NULL OR sizing_guide_scope <> \'none\''
            );
            $steps[] = 'products advisory refs cleared: ' . $n;
        }
        if (orange_table_exists($pdo, 'product_types') && orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
            $n = (int) $pdo->exec(
                'UPDATE product_types SET default_advisory_sizing_guide_id = NULL
                 WHERE default_advisory_sizing_guide_id IS NOT NULL AND default_advisory_sizing_guide_id > 0'
            );
            $steps[] = 'product_types default guide cleared: ' . $n;
        }

        foreach ([
            'advisory_sizing_guide_cells',
            'advisory_sizing_guide_rows',
            'advisory_sizing_guide_columns',
            'advisory_sizing_guides',
            'size_family_advisory_library_map',
            'advisory_sizing_library_bundles',
        ] as $tbl) {
            if (!orange_table_exists($pdo, $tbl)) {
                continue;
            }
            $pdo->exec('ALTER TABLE `' . $tbl . '` AUTO_INCREMENT = 1');
            $steps[] = $tbl . ' AUTO_INCREMENT = 1';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $steps;
}
