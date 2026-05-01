<?php

declare(strict_types=1);

/**
 * ترحيل بيانات تصنيف قديم إلى شجرة المتجر الموحّدة وفق سياسة
 * docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt (مرحلة B).
 */

const ORANGE_CATALOG_LEGACY_UNIFIED_STEP = 'legacy_unified_taxonomy_v1';

/** مزامنة لمرّة واحدة: تعبئة category_id/subcategory_id كـ cache مشتَّق من product_type_id. */
const ORANGE_CATALOG_LEGACY_PRODUCT_ROW_CACHE_STEP = 'legacy_product_row_cache_v1';

/** @internal */
function orange_tax_mig_tables_exist(PDO $pdo, array $tables): bool
{
    foreach ($tables as $t) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?)'
        );
        $stmt->execute([(string) $t]);
        if ((int) $stmt->fetchColumn() < 1) {
            return false;
        }
    }

    return true;
}

/** @internal */
function orange_tax_migration_log_ensure_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS orange_catalog_data_migration_log (
                step_key VARCHAR(64) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_tax_migration log table: ' . $e->getMessage());
        }
    }
}

function orange_catalog_migration_step_applied(PDO $pdo, string $stepKey): bool
{
    try {
        $st = $pdo->prepare('SELECT 1 FROM orange_catalog_data_migration_log WHERE step_key = ? LIMIT 1');
        $st->execute([$stepKey]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function orange_catalog_migration_step_record(PDO $pdo, string $stepKey): void
{
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO orange_catalog_data_migration_log (step_key, applied_at) VALUES (?, CURRENT_TIMESTAMP)'
        );
        $ins->execute([$stepKey]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_tax_migration record: ' . $e->getMessage());
        }
    }
}

/** @internal */
function orange_legacy_unified_ensure_main_section(PDO $pdo, int $departmentId): int
{
    $st = $pdo->prepare('SELECT id FROM catalog_sections WHERE department_id = ? AND slug = ? LIMIT 1');
    $st->execute([$departmentId, 'legacy-main']);
    $ex = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($ex) && isset($ex['id'])) {
        return (int) $ex['id'];
    }

    $depNameAr = 'كتالوج';
    $depNameEn = 'Catalog';
    $dst = $pdo->prepare('SELECT name_ar, name_en FROM departments WHERE id = ? LIMIT 1');
    $dst->execute([$departmentId]);
    $dr = $dst->fetch(PDO::FETCH_ASSOC);
    if (is_array($dr)) {
        if (trim((string) ($dr['name_ar'] ?? '')) !== '') {
            $depNameAr = (string) $dr['name_ar'];
        }
        if (trim((string) ($dr['name_en'] ?? '')) !== '') {
            $depNameEn = (string) $dr['name_en'];
        }
    }

    $ins = $pdo->prepare(
        'INSERT INTO catalog_sections (department_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
         VALUES (?, ?, ?, ?, \'\', \'\', 0, 1)'
    );
    $ins->execute([$departmentId, 'legacy-main', $depNameAr, $depNameEn]);

    return (int) $pdo->lastInsertId();
}

/** ضمان صف تصنيف فرعي «عام» + ورقة منتج واحدة للمنتجات بلا تصنيف فرعي تحت هذه الفئة. */
function orange_legacy_unified_ensure_nosub_branch(PDO $pdo, int $unifiedCatalogCategoryId, int $legacyCategoryId): void
{
    $ucsSlug = sprintf('legacy-nosub-%d', $legacyCategoryId);
    $st = $pdo->prepare(
        'SELECT id FROM catalog_subcategories WHERE catalog_category_id = ? AND slug = ? LIMIT 1'
    );
    $st->execute([$unifiedCatalogCategoryId, $ucsSlug]);
    $ucsRow = $st->fetch(PDO::FETCH_ASSOC);
    $ucsId = is_array($ucsRow) ? (int) ($ucsRow['id'] ?? 0) : 0;
    if ($ucsId <= 0) {
        $pdo->prepare(
            'INSERT INTO catalog_subcategories (catalog_category_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
             VALUES (?, ?, ?, ?, \'\', \'\', 0, 1)'
        )->execute([$unifiedCatalogCategoryId, $ucsSlug, 'عام', 'General']);
        $ucsId = (int) $pdo->lastInsertId();
    }

    $slugPt = sprintf('legacy-ptype-cat-%d', $legacyCategoryId);
    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM product_types WHERE catalog_subcategory_id = ? AND slug = ?'
    );
    $chk->execute([$ucsId, $slugPt]);
    if ((int) $chk->fetchColumn() === 0) {
        $pdo->prepare(
            'INSERT INTO product_types (catalog_subcategory_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active, expected_size_scheme_key)
             VALUES (?, ?, ?, ?, \'\', \'\', 0, 1, \'\')'
        )->execute([$ucsId, $slugPt, 'نوع افتراضي', 'Default type']);
    }
}

function orange_catalog_backfill_product_type_ids_only(PDO $pdo): void
{
    try {
        if (!orange_tax_mig_tables_exist($pdo, ['products', 'product_types'])) {
            return;
        }
        if (!function_exists('orange_table_has_column') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
            return;
        }

        orange_catalog_safe_exec(
            $pdo,
            'UPDATE products p
             INNER JOIN product_types pt ON pt.slug = CONCAT(\'legacy-ptype-sub-\', p.subcategory_id)
             SET p.product_type_id = pt.id
             WHERE p.product_type_id IS NULL AND p.subcategory_id IS NOT NULL AND p.subcategory_id > 0'
        );

        orange_catalog_safe_exec(
            $pdo,
            'UPDATE products p
             INNER JOIN product_types pt ON pt.slug = CONCAT(\'legacy-ptype-cat-\', p.category_id)
             SET p.product_type_id = pt.id
             WHERE p.product_type_id IS NULL
               AND (p.subcategory_id IS NULL OR p.subcategory_id = 0)
               AND p.category_id IS NOT NULL AND p.category_id > 0'
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog backfill product_type_id: ' . $e->getMessage());
        }
    }
}

/** ترحيل لمرّة واحدة؛ يعتمد وجود دوال مخطّط المحمَّلة مسبقاً (catalog_schema). */
function orange_catalog_legacy_unified_migrate_data(PDO $pdo): bool
{
    if (!orange_tax_mig_tables_exist(
        $pdo,
        ['departments', 'categories', 'catalog_sections', 'catalog_categories', 'catalog_subcategories', 'product_types']
    )) {
        return false;
    }
    $catCnt = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($catCnt <= 0) {
        return false;
    }

    if (!function_exists('orange_table_has_column') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $deptCnt = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
        if ($deptCnt === 0) {
            $pdo->exec(
                "INSERT INTO departments (name_ar, name_en, name_fil, name_hi, slug, is_active, sort_order)
                 VALUES ('جميع الفئات', 'All Categories', '', '', 'legacy-root', 1, 0)"
            );
        }

        $minDept = (int) $pdo->query('SELECT MIN(id) FROM departments')->fetchColumn();
        $defaultDept = $minDept > 0 ? $minDept : 1;

        $distinctDepts = $pdo->query(
            'SELECT DISTINCT COALESCE(NULLIF(c.department_id, 0), ' . (int) $defaultDept . ') AS did FROM categories c'
        );
        $deptBucket = [];
        if ($distinctDepts) {
            foreach ($distinctDepts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rw) {
                $d = (int) ($rw['did'] ?? 0);
                if ($d > 0) {
                    $deptBucket[$d] = true;
                }
            }
        }
        if ($deptBucket === []) {
            $deptBucket[$defaultDept] = true;
        }
        foreach (array_keys($deptBucket) as $did) {
            orange_legacy_unified_ensure_main_section($pdo, (int) $did);
        }

        $cats = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cats as $c) {
            if (!is_array($c)) {
                continue;
            }
            $legacyCatId = (int) ($c['id'] ?? 0);
            if ($legacyCatId <= 0) {
                continue;
            }

            $deptRaw = isset($c['department_id']) && $c['department_id'] !== null ? (int) $c['department_id'] : 0;
            $deptUse = $deptRaw > 0 ? $deptRaw : $defaultDept;
            $sectionId = orange_legacy_unified_ensure_main_section($pdo, $deptUse);
            $slugCat = sprintf('legacy-cat-%d', $legacyCatId);

            $exists = $pdo->prepare(
                'SELECT id FROM catalog_categories WHERE catalog_section_id = ? AND slug = ? LIMIT 1'
            );
            $exists->execute([$sectionId, $slugCat]);
            $crow = $exists->fetch(PDO::FETCH_ASSOC);
            if (!is_array($crow)) {
                $sortC = isset($c['sort_order']) ? (int) $c['sort_order'] : 0;
                $actC = isset($c['is_active']) && (int) $c['is_active'] === 0 ? 0 : 1;
                $pdo->prepare(
                    'INSERT INTO catalog_categories (catalog_section_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $sectionId,
                    $slugCat,
                    (string) ($c['name_ar'] ?? ''),
                    (string) ($c['name_en'] ?? ''),
                    (string) ($c['name_fil'] ?? ''),
                    (string) ($c['name_hi'] ?? ''),
                    $sortC,
                    $actC,
                ]);
            }

            $stId = $pdo->prepare(
                'SELECT id FROM catalog_categories WHERE catalog_section_id = ? AND slug = ? LIMIT 1'
            );
            $stId->execute([$sectionId, $slugCat]);
            $unifiedCatId = (int) $stId->fetchColumn();

            if ($unifiedCatId > 0) {
                orange_legacy_unified_ensure_nosub_branch($pdo, $unifiedCatId, $legacyCatId);
            }
        }

        $subs = $pdo->query(
            'SELECT * FROM subcategories ORDER BY category_id ASC, sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($subs as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sid = (int) ($s['id'] ?? 0);
            $cid = (int) ($s['category_id'] ?? 0);
            if ($sid <= 0 || $cid <= 0) {
                continue;
            }

            $slugUnifiedCat = sprintf('legacy-cat-%d', $cid);
            $find = $pdo->prepare(
                'SELECT cc.id FROM catalog_categories cc
                 INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
                 WHERE cc.slug = ? ORDER BY cc.id ASC LIMIT 1'
            );
            $find->execute([$slugUnifiedCat]);
            $ucc = $find->fetch(PDO::FETCH_ASSOC);
            if (!is_array($ucc)) {
                continue;
            }
            $uccId = (int) ($ucc['id'] ?? 0);
            if ($uccId <= 0) {
                continue;
            }

            $slugSub = sprintf('legacy-sub-%d', $sid);
            $chkSub = $pdo->prepare(
                'SELECT COUNT(*) FROM catalog_subcategories WHERE catalog_category_id = ? AND slug = ?'
            );
            $chkSub->execute([$uccId, $slugSub]);
            if ((int) $chkSub->fetchColumn() === 0) {
                $sortS = isset($s['sort_order']) ? (int) $s['sort_order'] : 0;
                $actS = isset($s['is_active']) && (int) $s['is_active'] === 0 ? 0 : 1;
                $pdo->prepare(
                    'INSERT INTO catalog_subcategories (catalog_category_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $uccId,
                    $slugSub,
                    (string) ($s['name_ar'] ?? ''),
                    (string) ($s['name_en'] ?? ''),
                    (string) ($s['name_fil'] ?? ''),
                    (string) ($s['name_hi'] ?? ''),
                    $sortS,
                    $actS,
                ]);
            }

            $stUcid = $pdo->prepare(
                'SELECT id FROM catalog_subcategories WHERE catalog_category_id = ? AND slug = ? LIMIT 1'
            );
            $stUcid->execute([$uccId, $slugSub]);
            $ucsId = (int) $stUcid->fetchColumn();

            $slugPt = sprintf('legacy-ptype-sub-%d', $sid);
            $chkPt = $pdo->prepare(
                'SELECT COUNT(*) FROM product_types WHERE catalog_subcategory_id = ? AND slug = ?'
            );
            $chkPt->execute([$ucsId, $slugPt]);
            if ((int) $chkPt->fetchColumn() === 0 && $ucsId > 0) {
                $actPt = isset($s['is_active']) && (int) $s['is_active'] === 0 ? 0 : 1;
                $sortPt = isset($s['sort_order']) ? (int) $s['sort_order'] : 0;
                $pdo->prepare(
                    'INSERT INTO product_types (catalog_subcategory_id, slug, name_ar, name_en, sort_order, is_active, expected_size_scheme_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $ucsId,
                    $slugPt,
                    (string) ($s['name_ar'] ?? ''),
                    (string) ($s['name_en'] ?? ''),
                    $sortPt,
                    $actPt,
                    '',
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('[orange] legacy_unified_migrate rollback: ' . $e->getMessage());
        }

        return false;
    }

    orange_catalog_backfill_product_type_ids_only($pdo);
    orange_catalog_migration_step_record($pdo, ORANGE_CATALOG_LEGACY_UNIFIED_STEP);
    orange_catalog_fill_legacy_product_row_cache($pdo);

    return true;
}

/**
 * اشتقاق حقول الفئة القديمة على صف المنتج من ورقة الشجرة الموحّدة (كـ Cache فقط).
 *
 * @return array{legacy_category_id: ?int, legacy_subcategory_id: ?int}
 */
function orange_catalog_legacy_classification_cache_for_product_type(PDO $pdo, int $productTypeId): array
{
    $nullOut = ['legacy_category_id' => null, 'legacy_subcategory_id' => null];
    if ($productTypeId <= 0 || !function_exists('orange_table_exists')) {
        return $nullOut;
    }
    if (!orange_table_exists($pdo, 'product_types') || !orange_table_exists($pdo, 'catalog_subcategories')) {
        return $nullOut;
    }
    try {
        $st = $pdo->prepare(
            'SELECT pt.slug AS pt_slug, ucs.slug AS ucs_slug, ucc.slug AS ucc_slug
             FROM product_types pt
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
             WHERE pt.id = ?
             LIMIT 1'
        );
        $st->execute([$productTypeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            return $nullOut;
        }
        $ptSlug = (string) ($row['pt_slug'] ?? '');
        if (preg_match('/^legacy-ptype-sub-(\\d+)$/', $ptSlug, $m)) {
            $sid = (int) $m[1];
            if ($sid > 0 && orange_table_exists($pdo, 'subcategories')) {
                $q = $pdo->prepare('SELECT category_id FROM subcategories WHERE id = ? LIMIT 1');
                $q->execute([$sid]);
                $cid = $q->fetchColumn();
                if ($cid !== false && $cid !== null && (int) $cid > 0) {
                    return ['legacy_category_id' => (int) $cid, 'legacy_subcategory_id' => $sid];
                }
            }

            return $nullOut;
        }
        if (preg_match('/^legacy-ptype-cat-(\\d+)$/', $ptSlug, $m)) {
            $cid = (int) $m[1];

            return $cid > 0 ? ['legacy_category_id' => $cid, 'legacy_subcategory_id' => null] : $nullOut;
        }
        $ucsSlug = (string) ($row['ucs_slug'] ?? '');
        if (preg_match('/^legacy-sub-(\\d+)$/', $ucsSlug, $m)) {
            $sid = (int) $m[1];
            if ($sid > 0 && orange_table_exists($pdo, 'subcategories')) {
                $q = $pdo->prepare('SELECT category_id FROM subcategories WHERE id = ? LIMIT 1');
                $q->execute([$sid]);
                $cid = $q->fetchColumn();
                if ($cid !== false && $cid !== null && (int) $cid > 0) {
                    return ['legacy_category_id' => (int) $cid, 'legacy_subcategory_id' => $sid];
                }
            }

            return $nullOut;
        }
        $uccSlug = (string) ($row['ucc_slug'] ?? '');
        if (preg_match('/^legacy-cat-(\\d+)$/', $uccSlug, $m)) {
            $cid = (int) $m[1];

            return $cid > 0 ? ['legacy_category_id' => $cid, 'legacy_subcategory_id' => null] : $nullOut;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] legacy_classification_cache_for_product_type: ' . $e->getMessage());
        }
    }

    return $nullOut;
}

/** بعد الترحيل الموحّد: يعبّئ صف المنتج مرة واحدة وفق هرَم product_types فقط. */
function orange_catalog_fill_legacy_product_row_cache(PDO $pdo): void
{
    if (
        !function_exists('orange_catalog_nav_use_unified')
        || !orange_catalog_nav_use_unified($pdo)
        || !function_exists('orange_table_has_column')
        || !orange_table_has_column($pdo, 'products', 'product_type_id')
    ) {
        return;
    }
    if (orange_catalog_migration_step_applied($pdo, ORANGE_CATALOG_LEGACY_PRODUCT_ROW_CACHE_STEP)) {
        return;
    }
    try {
        $stmt = $pdo->query(
            'SELECT id, product_type_id FROM products
             WHERE product_type_id IS NOT NULL AND product_type_id > 0'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (! is_array($rows)) {
            $rows = [];
        }
        $up = $pdo->prepare(
            'UPDATE products SET category_id = ?, subcategory_id = ? WHERE id = ? LIMIT 1'
        );
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $pid = (int) ($r['id'] ?? 0);
            $ptid = (int) ($r['product_type_id'] ?? 0);
            if ($pid <= 0 || $ptid <= 0) {
                continue;
            }
            $cache = orange_catalog_legacy_classification_cache_for_product_type($pdo, $ptid);
            $up->execute([
                $cache['legacy_category_id'],
                $cache['legacy_subcategory_id'],
                $pid,
            ]);
        }
        orange_catalog_migration_step_record($pdo, ORANGE_CATALOG_LEGACY_PRODUCT_ROW_CACHE_STEP);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_fill_legacy_product_row_cache: ' . $e->getMessage());
        }
    }
}

/** بعد orange_schema_check_and_bootstrap — يعتمد مخطّط الكتالوج الحالي محمَّلاً. */
function orange_catalog_post_schema_legacy_unified(PDO $pdo): void
{
    orange_tax_migration_log_ensure_table($pdo);

    if (!orange_tax_mig_tables_exist($pdo, ['categories'])) {
        return;
    }

    if (orange_catalog_migration_step_applied($pdo, ORANGE_CATALOG_LEGACY_UNIFIED_STEP)) {
        orange_catalog_backfill_product_type_ids_only($pdo);
        orange_catalog_fill_legacy_product_row_cache($pdo);

        return;
    }

    if (!orange_tax_mig_tables_exist($pdo, ['product_types'])) {
        return;
    }

    orange_catalog_legacy_unified_migrate_data($pdo);
}

function orange_catalog_nav_use_unified(PDO $pdo): bool
{
    if (!orange_catalog_migration_step_applied($pdo, ORANGE_CATALOG_LEGACY_UNIFIED_STEP)) {
        return false;
    }

    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM catalog_sections')->fetchColumn();

        return $cnt > 0;
    } catch (Throwable $e) {
        return false;
    }
}
