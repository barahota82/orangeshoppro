<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/department_countries.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/catalog_sizing_dictionary.php';

/**
 * slug لاتيني صالح لـ product_types (نفس قواعد الأدمن).
 */
function orange_catalog_product_type_slug_sanitize(string $raw): string
{
    $t = strtolower(trim($raw));
    if ($t === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/', $t)) {
        return '';
    }

    return $t;
}

/**
 * @return array{expected_commercial_kind_key:string,expected_sizing_category_key:string}
 */
function orange_catalog_kw_dept_expected_sizing_pair(PDO $pdo, int $departmentId, string $deptSlug, string $deptNameAr): array
{
    $empty = ['expected_commercial_kind_key' => '', 'expected_sizing_category_key' => ''];
    if ($departmentId <= 0) {
        return $empty;
    }

    try {
        $st = $pdo->prepare(
            'SELECT pt.expected_commercial_kind_key AS ck, pt.expected_sizing_category_key AS sk, COUNT(*) AS c
             FROM product_types pt
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id
             WHERE cs.department_id = ?
               AND pt.is_active = 1
               AND pt.expected_commercial_kind_key <> \'\'
               AND pt.expected_sizing_category_key <> \'\'
             GROUP BY pt.expected_commercial_kind_key, pt.expected_sizing_category_key
             ORDER BY c DESC
             LIMIT 1'
        );
        $st->execute([$departmentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $ck = trim((string) ($row['ck'] ?? ''));
            $sk = trim((string) ($row['sk'] ?? ''));
            if ($ck !== '' && $sk !== '' && orange_catalog_validate_size_family_dictionary_consistency($pdo, $ck, $sk) === null) {
                return ['expected_commercial_kind_key' => $ck, 'expected_sizing_category_key' => $sk];
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_kw_dept_expected_sizing_pair dept stats: ' . $e->getMessage());
        }
    }

    $blob = strtolower($deptSlug . ' ' . $deptNameAr);
    $candidates = [];
    if (preg_match('/women|woman|ladies|lady|نساء|نسائي/u', $blob)) {
        $candidates[] = ['clothing', 'womens_tops'];
        $candidates[] = ['clothing', 'womens_dresses'];
    }
    if (preg_match('/bag|handbag|حقائب|حقيب/u', $blob)) {
        $candidates[] = ['bags', 'handbags'];
        $candidates[] = ['accessories', 'bags'];
    }
    if (preg_match('/accessor|إكسسو/u', $blob)) {
        $candidates[] = ['accessories', 'jewelry'];
        $candidates[] = ['accessories', 'general'];
    }
    if (preg_match('/beaut|cosmetic|makeup|جمال|مكياج/u', $blob)) {
        return $empty;
    }

    foreach ($candidates as [$ck, $sk]) {
        if (orange_catalog_validate_size_family_dictionary_consistency($pdo, $ck, $sk) === null) {
            return ['expected_commercial_kind_key' => $ck, 'expected_sizing_category_key' => $sk];
        }
    }

    if (!orange_catalog_sizing_dictionary_kinds_enforced($pdo)) {
        return $empty;
    }

    try {
        $ckRow = $pdo->query(
            'SELECT kind_key FROM commercial_kind_dictionary WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ckRow)) {
            return $empty;
        }
        $ck = trim((string) ($ckRow['kind_key'] ?? ''));
        if ($ck === '') {
            return $empty;
        }
        $skSt = $pdo->prepare(
            'SELECT category_key FROM sizing_category_dictionary
             WHERE commercial_kind_key = ? AND is_active = 1
             ORDER BY sort_order ASC, category_key ASC LIMIT 1'
        );
        $skSt->execute([$ck]);
        $sk = trim((string) ($skSt->fetchColumn() ?: ''));

        return $sk !== ''
            ? ['expected_commercial_kind_key' => $ck, 'expected_sizing_category_key' => $sk]
            : ['expected_commercial_kind_key' => $ck, 'expected_sizing_category_key' => ''];
    } catch (Throwable $e) {
        return $empty;
    }
}

/**
 * عدد catalog_subcategories النشطة تحت أقسام KW المفعّلة بلا product_type نشط.
 */
function orange_catalog_kw_subcategories_missing_active_product_type_count(PDO $pdo, int $countryId): int
{
    if (
        $countryId <= 0
        || !function_exists('orange_table_exists')
        || !orange_table_exists($pdo, 'product_types')
        || !orange_table_exists($pdo, 'catalog_subcategories')
    ) {
        return 0;
    }

    $depSql = orange_department_country_active_sql($pdo, 'd', $countryId);

    try {
        $sql = '
            SELECT COUNT(*)
            FROM catalog_subcategories ucs
            INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
            INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
            INNER JOIN departments d ON d.id = cs.department_id AND (' . $depSql . ')
            WHERE ucs.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM product_types pt
                  WHERE pt.catalog_subcategory_id = ucs.id AND pt.is_active = 1
              )';

        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * مرحلة 2: ≥1 product_type نشط لكل catalog_subcategory نشط تحت قسم KW مفعّل — idempotent، probe ثم خروج.
 *
 * @return array{inserted:int,reactivated:int,sizing_filled:int,remaining:int}
 */
function orange_catalog_ensure_kw_product_types(PDO $pdo): array
{
    $out = ['inserted' => 0, 'reactivated' => 0, 'sizing_filled' => 0, 'remaining' => 0];

    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return $out;
    }
    if (
        !orange_table_exists($pdo, 'product_types')
        || !orange_table_exists($pdo, 'catalog_subcategories')
        || !orange_table_exists($pdo, 'departments')
    ) {
        return $out;
    }

    $countryId = orange_countries_default_id($pdo);
    if ($countryId <= 0) {
        return $out;
    }

    $remaining = orange_catalog_kw_subcategories_missing_active_product_type_count($pdo, $countryId);
    $out['remaining'] = $remaining;
    if ($remaining <= 0) {
        orange_catalog_backfill_kw_product_type_expected_sizing($pdo, $countryId, $out);

        return $out;
    }

    $depSql = orange_department_country_active_sql($pdo, 'd', $countryId);
    $hasCk = orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key');
    $hasSk = orange_table_has_column($pdo, 'product_types', 'expected_sizing_category_key');

    try {
        $rows = $pdo->query(
            'SELECT ucs.id AS ucs_id, ucs.slug AS ucs_slug, ucs.name_ar, ucs.name_en, ucs.name_fil, ucs.name_hi,
                    ucs.sort_order, cs.department_id, d.slug AS dept_slug, d.name_ar AS dept_name_ar
             FROM catalog_subcategories ucs
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
             INNER JOIN departments d ON d.id = cs.department_id AND (' . $depSql . ')
             WHERE ucs.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM product_types pt
                   WHERE pt.catalog_subcategory_id = ucs.id AND pt.is_active = 1
               )
             ORDER BY d.sort_order ASC, d.id ASC, ucc.sort_order ASC, ucs.sort_order ASC, ucs.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reactSt = $pdo->prepare(
            'SELECT id, expected_commercial_kind_key, expected_sizing_category_key
             FROM product_types
             WHERE catalog_subcategory_id = ? AND is_active = 0
             ORDER BY sort_order ASC, id ASC LIMIT 1'
        );

        $insCols = 'catalog_subcategory_id, slug, name_ar, name_en, name_fil, name_hi, sort_order, is_active';
        $insVals = '?, ?, ?, ?, ?, ?, ?, 1';
        if ($hasCk && $hasSk) {
            $insCols .= ', expected_commercial_kind_key, expected_sizing_category_key';
            $insVals .= ', ?, ?';
        }
        $ins = $pdo->prepare('INSERT INTO product_types (' . $insCols . ') VALUES (' . $insVals . ')');

        $reactUp = $hasCk && $hasSk
            ? $pdo->prepare(
                'UPDATE product_types SET is_active = 1, expected_commercial_kind_key = ?, expected_sizing_category_key = ? WHERE id = ?'
            )
            : $pdo->prepare('UPDATE product_types SET is_active = 1 WHERE id = ?');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ucsId = (int) ($row['ucs_id'] ?? 0);
            if ($ucsId <= 0) {
                continue;
            }

            $deptId = (int) ($row['department_id'] ?? 0);
            $pair = orange_catalog_kw_dept_expected_sizing_pair(
                $pdo,
                $deptId,
                (string) ($row['dept_slug'] ?? ''),
                (string) ($row['dept_name_ar'] ?? '')
            );
            $expCk = $pair['expected_commercial_kind_key'];
            $expSk = $pair['expected_sizing_category_key'];

            $reactSt->execute([$ucsId]);
            $inactive = $reactSt->fetch(PDO::FETCH_ASSOC);
            if (is_array($inactive) && (int) ($inactive['id'] ?? 0) > 0) {
                $ptId = (int) $inactive['id'];
                $curCk = trim((string) ($inactive['expected_commercial_kind_key'] ?? ''));
                $curSk = trim((string) ($inactive['expected_sizing_category_key'] ?? ''));
                if ($hasCk && $hasSk && ($curCk === '' || $curSk === '') && $expCk !== '' && $expSk !== '') {
                    $reactUp->execute([$expCk, $expSk, $ptId]);
                } elseif ($hasCk && $hasSk) {
                    $reactUp->execute([$curCk !== '' ? $curCk : $expCk, $curSk !== '' ? $curSk : $expSk, $ptId]);
                } else {
                    $reactUp->execute([$ptId]);
                }
                $out['reactivated']++;
                continue;
            }

            $slug = orange_catalog_product_type_slug_sanitize((string) ($row['ucs_slug'] ?? ''));
            if ($slug === '') {
                $slug = 'sub-' . $ucsId;
            }

            $dup = $pdo->prepare(
                'SELECT COUNT(*) FROM product_types WHERE catalog_subcategory_id = ? AND slug = ?'
            );
            $dup->execute([$ucsId, $slug]);
            if ((int) $dup->fetchColumn() > 0) {
                $slug = 'sub-' . $ucsId;
            }

            $nameAr = trim((string) ($row['name_ar'] ?? ''));
            $nameEn = trim((string) ($row['name_en'] ?? ''));
            if ($nameAr === '') {
                $nameAr = 'نوع ' . $ucsId;
            }
            if ($nameEn === '') {
                $nameEn = $nameAr !== '' ? $nameAr : ('Type ' . $ucsId);
            }

            $params = [
                $ucsId,
                $slug,
                $nameAr,
                $nameEn,
                (string) ($row['name_fil'] ?? ''),
                (string) ($row['name_hi'] ?? ''),
                (int) ($row['sort_order'] ?? 0),
            ];
            if ($hasCk && $hasSk) {
                $params[] = $expCk;
                $params[] = $expSk;
            }
            $ins->execute($params);
            $out['inserted']++;
        }

        if ($out['inserted'] > 0 || $out['reactivated'] > 0) {
            if (function_exists('error_log')) {
                error_log(
                    '[orange] kw product_types seed: inserted=' . $out['inserted']
                    . ' reactivated=' . $out['reactivated']
                );
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_kw_product_types: ' . $e->getMessage());
        }
    }

    $out['remaining'] = orange_catalog_kw_subcategories_missing_active_product_type_count($pdo, $countryId);
    orange_catalog_backfill_kw_product_type_expected_sizing($pdo, $countryId, $out);

    return $out;
}

/**
 * تعبئة expected_commercial_kind / sizing_category على أنواع KW الناقصة (2.4).
 *
 * @param array{inserted:int,reactivated:int,sizing_filled:int,remaining:int} $stats
 */
function orange_catalog_backfill_kw_product_type_expected_sizing(PDO $pdo, int $countryId, array &$stats): void
{
    if (
        $countryId <= 0
        || !orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key')
        || !orange_table_has_column($pdo, 'product_types', 'expected_sizing_category_key')
    ) {
        return;
    }

    $depSql = orange_department_country_active_sql($pdo, 'd', $countryId);

    try {
        $rows = $pdo->query(
            'SELECT pt.id AS pt_id, cs.department_id, d.slug AS dept_slug, d.name_ar AS dept_name_ar,
                    pt.expected_commercial_kind_key AS ck, pt.expected_sizing_category_key AS sk
             FROM product_types pt
             INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
             INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
             INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
             INNER JOIN departments d ON d.id = cs.department_id AND (' . $depSql . ')
             WHERE pt.is_active = 1
               AND (pt.expected_commercial_kind_key = \'\' OR pt.expected_sizing_category_key = \'\')'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $up = $pdo->prepare(
            'UPDATE product_types SET expected_commercial_kind_key = ?, expected_sizing_category_key = ? WHERE id = ?'
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ptId = (int) ($row['pt_id'] ?? 0);
            if ($ptId <= 0) {
                continue;
            }
            $curCk = trim((string) ($row['ck'] ?? ''));
            $curSk = trim((string) ($row['sk'] ?? ''));
            if ($curCk !== '' && $curSk !== '') {
                continue;
            }

            $pair = orange_catalog_kw_dept_expected_sizing_pair(
                $pdo,
                (int) ($row['department_id'] ?? 0),
                (string) ($row['dept_slug'] ?? ''),
                (string) ($row['dept_name_ar'] ?? '')
            );
            $newCk = $curCk !== '' ? $curCk : $pair['expected_commercial_kind_key'];
            $newSk = $curSk !== '' ? $curSk : $pair['expected_sizing_category_key'];

            if ($newCk === '' && $newSk === '') {
                continue;
            }
            if ($newCk !== '' && $newSk === '') {
                continue;
            }
            if (orange_catalog_validate_size_family_dictionary_consistency($pdo, $newCk, $newSk) !== null) {
                continue;
            }

            $up->execute([$newCk, $newSk, $ptId]);
            $stats['sizing_filled']++;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_backfill_kw_product_type_expected_sizing: ' . $e->getMessage());
        }
    }
}
