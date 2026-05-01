<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';

/**
 * بيانات شاشات الأدمن لشجرة الكتالوج الموحّد (أقسام داخلية + فئة + تصنيف فرعي قبل أنواع المنتجات).
 *
 * @return array{
 *   has_unified_tables: bool,
 *   unified_nav_active: bool,
 *   departments: list<array<string,mixed>>,
 *   sections_flat: list<array<string,mixed>>,
 *   categories_flat: list<array<string,mixed>>,
 *   subcats_flat: list<array<string,mixed>>,
 *   section_select_options: list<array{id:int,label:string}>,
 *   category_select_options: list<array{id:int,label:string}>,
 *   section_opts_json: string,
 *   category_opts_json: string
 * }
 */
function orange_admin_uc_branch_bootstrap(PDO $pdo): array
{
    orange_catalog_ensure_schema($pdo);

    $hasUnified =
        orange_table_exists($pdo, 'catalog_sections')
        && orange_table_exists($pdo, 'catalog_categories')
        && orange_table_exists($pdo, 'catalog_subcategories')
        && orange_table_exists($pdo, 'departments');

    $unifiedActive = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);

    $defaults = [
        'has_unified_tables' => $hasUnified,
        'unified_nav_active' => $unifiedActive,
        'departments' => [],
        'sections_flat' => [],
        'categories_flat' => [],
        'subcats_flat' => [],
        'section_select_options' => [],
        'category_select_options' => [],
        'section_opts_json' => '[]',
        'category_opts_json' => '[]',
        'deps_empty_for_sections' => true,
        'sections_empty_for_categories' => true,
        'categories_empty_for_subcats' => true,
    ];

    if (! $hasUnified || ! orange_table_exists($pdo, 'departments')) {
        return $defaults;
    }

    $departments = [];
    $sectionsFlat = [];
    $categoriesFlat = [];
    $subcatsFlat = [];

    try {
        $departments = $pdo->query(
            'SELECT id, name_ar, name_en, slug FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $sectionsFlat = $pdo->query(
            'SELECT cs.id, cs.slug, cs.name_ar, cs.name_en, cs.name_fil, cs.name_hi, cs.department_id, cs.sort_order, cs.is_active,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_sections cs
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $categoriesFlat = $pdo->query(
            'SELECT cc.id, cc.slug, cc.name_ar, cc.name_en, cc.name_fil, cc.name_hi, cc.catalog_section_id, cc.sort_order, cc.is_active,
                    cs.slug AS sec_slug,
                    COALESCE(NULLIF(TRIM(cs.name_ar), \'\'), cs.name_en, cs.slug) AS sec_label,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_categories cc
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, cc.sort_order ASC, cc.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $subcatsFlat = $pdo->query(
            'SELECT csub.id, csub.slug, csub.name_ar, csub.name_en, csub.name_fil, csub.name_hi, csub.catalog_category_id, csub.sort_order, csub.is_active,
                    COALESCE(NULLIF(TRIM(cc.name_ar), \'\'), cc.name_en, cc.slug) AS cat_label,
                    COALESCE(NULLIF(TRIM(cs.name_ar), \'\'), cs.name_en, cs.slug) AS sec_label,
                    COALESCE(NULLIF(TRIM(d.name_ar), \'\'), d.name_en, d.slug) AS dept_label
             FROM catalog_subcategories csub
             INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC, cc.sort_order ASC, cc.id ASC, csub.sort_order ASC, csub.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $defaults;
    }

    $sectionSelectOptions = [];
    foreach ($sectionsFlat as $s) {
        if (! is_array($s)) {
            continue;
        }
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $sectionSelectOptions[] = [
            'id' => $sid,
            'label' => trim((string) ($s['dept_label'] ?? '')) . ' ← ' . trim((string) (($s['name_ar'] ?: $s['name_en']) ?: $s['slug'] ?? '')),
        ];
    }

    $categorySelectOptions = [];
    foreach ($categoriesFlat as $c) {
        if (! is_array($c)) {
            continue;
        }
        $cid = (int) ($c['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $categorySelectOptions[] = [
            'id' => $cid,
            'label' => trim((string) ($c['dept_label'] ?? '')) . ' ← ' . trim((string) ($c['sec_label'] ?? '')) . ' ← '
                . trim((string) (($c['name_ar'] ?: $c['name_en']) ?: $c['slug'] ?? '')),
        ];
    }

    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return [
        'has_unified_tables' => true,
        'unified_nav_active' => $unifiedActive,
        'departments' => is_array($departments) ? $departments : [],
        'sections_flat' => is_array($sectionsFlat) ? $sectionsFlat : [],
        'categories_flat' => is_array($categoriesFlat) ? $categoriesFlat : [],
        'subcats_flat' => is_array($subcatsFlat) ? $subcatsFlat : [],
        'section_select_options' => $sectionSelectOptions,
        'category_select_options' => $categorySelectOptions,
        'section_opts_json' => json_encode($sectionSelectOptions, $flags) ?: '[]',
        'category_opts_json' => json_encode($categorySelectOptions, $flags) ?: '[]',
        'deps_empty_for_sections' => $departments === [],
        'sections_empty_for_categories' => $sectionSelectOptions === [],
        'categories_empty_for_subcats' => $categorySelectOptions === [],
    ];
}
