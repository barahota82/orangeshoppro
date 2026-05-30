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
