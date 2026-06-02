<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=UTF-8');

/**
 * بدون HEALTH_CHECK_KEY في .env.php: ردّ بسيط فقط (لا يكشف اتصال DB أو أعداد جداول).
 * مع مفتاح: أرسل ?key=... مطابقاً لإظهار فحص DB/الجلسة (للمراقبة الداخلية فقط).
 */
$key = trim((string) ($env['HEALTH_CHECK_KEY'] ?? ''));
$provided = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($key === '') {
    echo "OK\n";
    exit;
}

if ($provided === '' || !hash_equals($key, $provided)) {
    http_response_code(404);
    exit;
}

echo "PHP OK\n";

try {
    $pdo = db();
    echo "DB OK\n";
    $r = $pdo->query('SELECT COUNT(*) c FROM admins')->fetch();
    echo 'admins table OK, count=' . (int) ($r['c'] ?? 0) . "\n";
} catch (Throwable $e) {
    echo 'DB/admins ERROR: ' . $e->getMessage() . "\n";
}

try {
    $_SESSION['__t'] = '1';
    echo "SESSION OK\n";
} catch (Throwable $e) {
    echo 'SESSION ERROR: ' . $e->getMessage() . "\n";
}

$rollout = isset($_GET['rollout']) ? trim((string) $_GET['rollout']) : '';
if ($rollout === 'unified-phase1') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_taxonomy_migrate.php';
        require_once __DIR__ . '/includes/department_countries.php';
        require_once __DIR__ . '/includes/countries.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $navUnified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdoRollout);
        echo 'unified_nav=' . ($navUnified ? '1' : '0') . "\n";
        if ($navUnified && orange_table_has_column($pdoRollout, 'products', 'product_type_id')) {
            $missingPt = (int) $pdoRollout->query(
                'SELECT COUNT(*) FROM products WHERE is_active = 1 AND (product_type_id IS NULL OR product_type_id <= 0)'
            )->fetchColumn();
            echo 'active_products_missing_product_type_id=' . $missingPt . "\n";
        }
        $kwId = orange_countries_default_id($pdoRollout);
        if ($kwId > 0 && orange_department_countries_table_ready($pdoRollout)) {
            $stKw = $pdoRollout->prepare(
                'SELECT COUNT(*) FROM department_countries WHERE country_id = ? AND is_active = 1'
            );
            $stKw->execute([$kwId]);
            echo 'kw_active_departments=' . (int) $stKw->fetchColumn() . "\n";
        }
        if (orange_table_exists($pdoRollout, 'product_channels') && orange_table_exists($pdoRollout, 'channels')) {
            $chRows = $pdoRollout->query(
                'SELECT c.slug, COUNT(pc.product_id) AS link_count
                 FROM channels c
                 LEFT JOIN product_channels pc ON pc.channel_id = c.id
                 WHERE c.is_active = 1
                 GROUP BY c.id, c.slug
                 ORDER BY c.slug ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($chRows as $cr) {
                echo 'product_channels_' . (string) ($cr['slug'] ?? '?') . '=' . (int) ($cr['link_count'] ?? 0) . "\n";
            }
        }
        if ($navUnified && orange_table_exists($pdoRollout, 'catalog_sections')) {
            echo 'catalog_sections=' . (int) $pdoRollout->query('SELECT COUNT(*) FROM catalog_sections')->fetchColumn() . "\n";
            echo 'catalog_categories=' . (int) $pdoRollout->query('SELECT COUNT(*) FROM catalog_categories')->fetchColumn() . "\n";
        }
        echo "ROLLOUT_PHASE1_OK\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE1_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase2') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_taxonomy_migrate.php';
        require_once __DIR__ . '/includes/catalog_kw_product_types_seed.php';
        require_once __DIR__ . '/includes/countries.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $kwId = orange_countries_default_id($pdoRollout);
        $missing = -1;
        if ($kwId > 0) {
            $missing = orange_catalog_kw_subcategories_missing_active_product_type_count($pdoRollout, $kwId);
            echo 'kw_subcategories_missing_product_type=' . $missing . "\n";
            $ptKw = (int) $pdoRollout->query(
                'SELECT COUNT(*) FROM product_types pt
                 INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
                 INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
                 INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
                 INNER JOIN departments d ON d.id = cs.department_id
                 WHERE pt.is_active = 1'
            )->fetchColumn();
            echo 'kw_active_product_types=' . $ptKw . "\n";
        }
        echo $missing === 0 ? "ROLLOUT_PHASE2_OK\n" : "ROLLOUT_PHASE2_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE2_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase3') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_kw_products_phase3.php';
        require_once __DIR__ . '/includes/countries.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $kwId = orange_countries_default_id($pdoRollout);
        if ($kwId > 0) {
            $gaps = orange_catalog_kw_products_phase3_gap_counts($pdoRollout, $kwId);
            echo 'kw_missing_product_type=' . (int) ($gaps['missing_product_type'] ?? 0) . "\n";
            echo 'kw_missing_default_variants=' . (int) ($gaps['missing_variants'] ?? 0) . "\n";
            echo 'kw_missing_attributes_template=' . (int) ($gaps['missing_attributes'] ?? 0) . "\n";
            $allOk = ($gaps['missing_product_type'] ?? 1) === 0
                && ($gaps['missing_variants'] ?? 1) === 0
                && ($gaps['missing_attributes'] ?? 1) === 0;
            echo $allOk ? "ROLLOUT_PHASE3_OK\n" : "ROLLOUT_PHASE3_PENDING\n";
        }
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE3_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase4') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_multicountry_runtime.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $active = orange_catalog_active_country_ids($pdoRollout);
        echo 'active_countries=' . count($active) . "\n";
        $allOk = true;
        foreach ($active as $cid) {
            $rep = orange_catalog_multicountry_phase4_gap_report($pdoRollout, $cid);
            $code = '';
            $stC = $pdoRollout->prepare('SELECT code FROM countries WHERE id = ? LIMIT 1');
            $stC->execute([$cid]);
            $code = strtolower(trim((string) ($stC->fetchColumn() ?: 'c' . $cid)));
            echo 'country_' . $code . '_warehouse=' . (($rep['warehouse'] ?? false) ? '1' : '0') . "\n";
            echo 'country_' . $code . '_channels=' . (($rep['channels_ok'] ?? false) ? '1' : '0') . "\n";
            echo 'country_' . $code . '_departments=' . (($rep['departments_ok'] ?? false) ? '1' : '0') . "\n";
            if (empty($rep['warehouse']) || empty($rep['channels_ok']) || empty($rep['departments_ok'])) {
                $allOk = false;
            }
        }
        echo $allOk ? "ROLLOUT_PHASE4_OK\n" : "ROLLOUT_PHASE4_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE4_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase5') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_legacy_closure_phase5.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $rep = orange_catalog_phase5_gap_report($pdoRollout);
        echo 'unified_nav=' . (($rep['unified_nav'] ?? false) ? '1' : '0') . "\n";
        echo 'bad_product_type=' . (int) ($rep['bad_product_type'] ?? -1) . "\n";
        echo 'legacy_category_column=' . (($rep['legacy_category_column'] ?? false) ? '1' : '0') . "\n";
        echo 'legacy_subcategory_column=' . (($rep['legacy_subcategory_column'] ?? false) ? '1' : '0') . "\n";
        echo 'product_type_id_not_null=' . (($rep['product_type_id_not_null'] ?? false) ? '1' : '0') . "\n";
        $allOk = !empty($rep['unified_nav'])
            && (int) ($rep['bad_product_type'] ?? 1) === 0
            && empty($rep['legacy_category_column'])
            && empty($rep['legacy_subcategory_column'])
            && !empty($rep['product_type_id_not_null']);
        echo $allOk ? "ROLLOUT_PHASE5_OK\n" : "ROLLOUT_PHASE5_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE5_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase6') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_polish_phase6.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $rep = orange_catalog_phase6_gap_report($pdoRollout);
        echo 'filterable_attrs=' . (int) ($rep['filterable_attrs'] ?? -1) . "\n";
        echo 'pt_default_guide_col=' . (($rep['pt_default_guide_col'] ?? false) ? '1' : '0') . "\n";
        echo 'products_missing_seo=' . (int) ($rep['products_missing_seo'] ?? -1) . "\n";
        $allOk = !empty($rep['pt_default_guide_col'])
            && (int) ($rep['filterable_attrs'] ?? 0) >= 0
            && (int) ($rep['products_missing_seo'] ?? 1) === 0;
        echo $allOk ? "ROLLOUT_PHASE6_OK\n" : "ROLLOUT_PHASE6_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE6_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'unified-phase54') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/catalog_legacy_tables_drop_phase54.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $rep = orange_catalog_phase54_gap_report($pdoRollout);
        echo 'legacy_categories_table=' . (($rep['legacy_categories_table'] ?? false) ? '1' : '0') . "\n";
        echo 'legacy_subcategories_table=' . (($rep['legacy_subcategories_table'] ?? false) ? '1' : '0') . "\n";
        echo 'step_applied=' . (($rep['step_applied'] ?? false) ? '1' : '0') . "\n";
        echo 'ready=' . (($rep['ready'] ?? false) ? '1' : '0') . "\n";
        $allOk = !empty($rep['ready'])
            && empty($rep['legacy_categories_table'])
            && empty($rep['legacy_subcategories_table'])
            && !empty($rep['step_applied']);
        echo $allOk ? "ROLLOUT_PHASE54_OK\n" : "ROLLOUT_PHASE54_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PHASE54_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'multicountry-stock') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/multicountry_stock_gap.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        orange_multicountry_ensure_stock_scoped_phase1($pdoRollout);
        $rep = orange_multicountry_stock_gap_report($pdoRollout);
        echo 'active_countries=' . (int) ($rep['active_countries'] ?? 0) . "\n";
        echo 'countries_without_warehouse=' . (int) ($rep['countries_without_warehouse'] ?? -1) . "\n";
        echo 'products_missing_country_id=' . (int) ($rep['products_missing_country_id'] ?? -1) . "\n";
        echo 'orders_missing_country_id=' . (int) ($rep['orders_missing_country_id'] ?? -1) . "\n";
        echo 'orders_warehouse_mismatch=' . (int) ($rep['orders_warehouse_mismatch'] ?? -1) . "\n";
        echo 'stock_movements_missing_country=' . (int) ($rep['stock_movements_missing_country'] ?? -1) . "\n";
        echo 'step_applied=' . ((!empty($rep['step_applied'])) ? '1' : '0') . "\n";
        $allOk = !empty($rep['ready']) && !empty($rep['step_applied']);
        echo $allOk ? "ROLLOUT_MC_STOCK_OK\n" : "ROLLOUT_MC_STOCK_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_MC_STOCK_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'promo-stock-health') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/cart_promo_stock_health.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        $rep = orange_cart_promo_run_stock_health($pdoRollout, null, null);
        echo 'countries=' . count($rep['countries'] ?? []) . "\n";
        echo 'checked=' . (int) ($rep['checked'] ?? 0) . "\n";
        echo 'paused_promo_stock=' . (int) ($rep['paused_promo_stock'] ?? 0) . "\n";
        echo 'paused_gift_stock=' . (int) ($rep['paused_gift_stock'] ?? 0) . "\n";
        echo 'resumed=' . (int) ($rep['resumed'] ?? 0) . "\n";
        echo "ROLLOUT_PROMO_STOCK_HEALTH_OK\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_PROMO_STOCK_HEALTH_ERROR: ' . $e->getMessage() . "\n";
    }
}

if ($rollout === 'multicountry-stock-phase2') {
    try {
        require_once __DIR__ . '/includes/catalog_schema.php';
        require_once __DIR__ . '/includes/multicountry_stock_gap.php';
        $pdoRollout = db();
        orange_catalog_ensure_schema($pdoRollout);
        orange_multicountry_ensure_operational_phase2($pdoRollout);
        $rep = orange_multicountry_stock_phase2_gap_report($pdoRollout);
        echo 'markets_active=' . (int) ($rep['markets_active'] ?? 0) . "\n";
        echo 'markets_provision_ready=' . (int) ($rep['markets_provision_ready'] ?? 0) . "\n";
        foreach ($rep['markets'] ?? [] as $code => $m) {
            if (!is_array($m)) {
                continue;
            }
            echo 'market_' . $code . '_active=' . ((!empty($m['is_active'])) ? '1' : '0') . "\n";
            echo 'market_' . $code . '_warehouse=' . ((!empty($m['warehouse'])) ? '1' : '0') . "\n";
            echo 'market_' . $code . '_channels=' . ((!empty($m['channels_ok'])) ? '1' : '0') . "\n";
            echo 'market_' . $code . '_products=' . (int) ($m['products_count'] ?? 0) . "\n";
            echo 'market_' . $code . '_wvs_missing=' . (int) ($m['variants_missing_wvs'] ?? -1) . "\n";
            echo 'market_' . $code . '_ready=' . ((!empty($m['provision_ready'])) ? '1' : '0') . "\n";
        }
        echo 'step_applied=' . ((!empty($rep['step_applied'])) ? '1' : '0') . "\n";
        $allOk = !empty($rep['ready']) && !empty($rep['step_applied']);
        echo $allOk ? "ROLLOUT_MC_STOCK_PHASE2_OK\n" : "ROLLOUT_MC_STOCK_PHASE2_PENDING\n";
    } catch (Throwable $e) {
        echo 'ROLLOUT_MC_STOCK_PHASE2_ERROR: ' . $e->getMessage() . "\n";
    }
}
