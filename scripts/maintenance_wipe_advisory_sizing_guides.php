<?php

declare(strict_types=1);

/**
 * هدام — يفرغ كل أدلة المقاسات الاسترشادية والربط بالمكتبة.
 * قرار المالك 2026-06-21: تشغيل مرة واحدة قبل التسجيل النظيف بعد تنفيذ layout_kind/panel_kind.
 *
 * الاستخدام (من جذر المشروع):
 *   php scripts/maintenance_wipe_advisory_sizing_guides.php --confirm=WIPE_ADVISORY_GUIDES
 *
 * @see docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md
 */

require_once dirname(__DIR__) . '/config.php';

$confirm = '';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--confirm=')) {
        $confirm = substr($arg, strlen('--confirm='));
    }
}

if ($confirm !== 'WIPE_ADVISORY_GUIDES') {
    fwrite(STDERR, "Refusing to run without --confirm=WIPE_ADVISORY_GUIDES\n");
    exit(1);
}

$pdo = db();
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
orange_catalog_ensure_schema($pdo);

if (!orange_table_exists($pdo, 'advisory_sizing_guides')) {
    fwrite(STDOUT, "advisory_sizing_guides table missing — nothing to wipe.\n");
    exit(0);
}

$steps = [];

try {
    $pdo->beginTransaction();

    if (orange_table_exists($pdo, 'advisory_sizing_guide_cells')) {
        $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_cells');
        $steps[] = 'advisory_sizing_guide_cells: ' . $n;
    }
    if (orange_table_exists($pdo, 'advisory_sizing_guide_rows')) {
        $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_rows');
        $steps[] = 'advisory_sizing_guide_rows: ' . $n;
    }
    if (orange_table_exists($pdo, 'advisory_sizing_guide_columns')) {
        $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guide_columns');
        $steps[] = 'advisory_sizing_guide_columns: ' . $n;
    }
    if (orange_table_exists($pdo, 'advisory_sizing_guides')) {
        $n = (int) $pdo->exec('DELETE FROM advisory_sizing_guides');
        $steps[] = 'advisory_sizing_guides: ' . $n;
    }
    if (orange_table_exists($pdo, 'size_family_advisory_library_map')) {
        $n = (int) $pdo->exec('DELETE FROM size_family_advisory_library_map');
        $steps[] = 'size_family_advisory_library_map: ' . $n;
    }
    if (orange_table_exists($pdo, 'advisory_sizing_library_bundles')) {
        $n = (int) $pdo->exec('DELETE FROM advisory_sizing_library_bundles');
        $steps[] = 'advisory_sizing_library_bundles: ' . $n;
    }
    if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
        $n = (int) $pdo->exec(
            'UPDATE products SET sizing_advisory_guide_id = NULL, sizing_guide_scope = \'none\' WHERE sizing_advisory_guide_id IS NOT NULL OR sizing_guide_scope <> \'none\''
        );
        $steps[] = 'products advisory refs cleared: ' . $n;
    }
    if (orange_table_exists($pdo, 'product_types') && orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        $n = (int) $pdo->exec(
            'UPDATE product_types SET default_advisory_sizing_guide_id = NULL WHERE default_advisory_sizing_guide_id IS NOT NULL AND default_advisory_sizing_guide_id > 0'
        );
        $steps[] = 'product_types default guide cleared: ' . $n;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Wipe failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Advisory sizing wipe completed.\n");
foreach ($steps as $line) {
    fwrite(STDOUT, '  - ' . $line . "\n");
}
