<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

try {

/**
 * @param array<string,mixed> $row
 */
function storefront_catalog_label(array $row, string $lang): string
{
    return match ($lang) {
        'ar' => (string)($row['name_ar'] ?? $row['name'] ?? $row['name_en'] ?? ''),
        'fil' => (string)($row['name_fil'] ?? $row['name_en'] ?? $row['name'] ?? ''),
        'hi' => (string)($row['name_hi'] ?? $row['name_en'] ?? $row['name'] ?? ''),
        default => (string)($row['name_en'] ?? $row['name'] ?? $row['name_ar'] ?? ''),
    };
}

require_once __DIR__ . '/../includes/catalog_schema.php';
orange_catalog_ensure_storefront_page(db());
require_once __DIR__ . '/../includes/catalog_labels.php';
require_once __DIR__ . '/../includes/catalog_unified_nav.php';
require_once __DIR__ . '/../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/department_countries.php';

$tbState = storefront_toolbar_state();
$channel = $tbState['channel'];
$lang = $tbState['lang'];
$channelSlug = $tbState['channelSlug'];

$pdo = db();

/*
 * التنقل والتبويبات: من الشجرة الموحّدة فقط (catalog_* + product_type_id).
 * لا استعلامات واجهة على جداول categories/subcategories التراثية.
 */
require_once __DIR__ . '/../includes/catalog_taxonomy_migrate.php';

$sfUnifiedNavPack = orange_storefront_unified_nav_for_home($pdo);
$navUnified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);

$departments = $sfUnifiedNavPack['departments'];
$categories = $sfUnifiedNavPack['categories'];
$subcategoriesByCategory = $sfUnifiedNavPack['subcategoriesByCategory'];
$catsByDept = $sfUnifiedNavPack['catsByDept'];
$categoryToDepartment = $sfUnifiedNavPack['categoryToDepartment'];

$canUnifiedProductSql = $navUnified
    && function_exists('orange_table_exists')
    && orange_table_exists($pdo, 'product_types')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_sections')
    && orange_table_has_column($pdo, 'products', 'product_type_id');

$sfHomeCountryId = orange_storefront_current_country_id($pdo);
$homeProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $sfHomeCountryId);
$homeDeptActiveSql = orange_department_country_active_sql($pdo, 'd', $sfHomeCountryId);

if ($canUnifiedProductSql) {
    $productsSql = '
    SELECT p.*, ucs2.department_id AS uf_dept_id, ucc.id AS uf_cat_id, ucs.id AS uf_sub_id
    FROM products p
    INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
    INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
    INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
    INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
    INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $homeDeptActiveSql . ')
    WHERE p.is_active = 1' . $homeProductsCountrySql . '
    ORDER BY p.sort_order ASC, p.id ASC
';
    $offersScheduleSql = orange_table_has_column($pdo, 'offers', 'valid_from')
        ? orange_product_offer_storefront_sql('o')
        : '';
    $offersSql = '
    SELECT o.id AS offer_id, o.discount,
           p.*, ucs2.department_id AS uf_dept_id, ucc.id AS uf_cat_id, ucs.id AS uf_sub_id
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
    INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
    INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
    INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
    INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $homeDeptActiveSql . ')
    WHERE o.is_active = 1 AND p.is_active = 1' . $offersScheduleSql . $homeProductsCountrySql . '
    ORDER BY '
        . (orange_table_has_column($pdo, 'offers', 'sort_order') ? 'o.sort_order ASC, ' : '')
        . 'p.sort_order ASC, p.id ASC, o.id ASC
';
} else {
    /* مرحلة 5: لا مسار legacy — منتجات فارغة حتى اكتمال الشجرة الموحّدة */
    $productsSql = 'SELECT p.* FROM products p WHERE 1=0';
    $offersSql = 'SELECT o.discount, p.* FROM offers o INNER JOIN products p ON p.id = o.product_id WHERE 1=0';
}

$productsStmt = $pdo->query($productsSql);
$products = $productsStmt ? $productsStmt->fetchAll() : [];

$offersStmt = $pdo->query($offersSql);
$offers = $offersStmt ? $offersStmt->fetchAll() : [];

$sfHomeGridInitial = 24;
$sfHomeGridScrollBatch = 24;
$offersInitial = array_slice($offers, 0, $sfHomeGridInitial);
$offersLazyRows = array_slice($offers, $sfHomeGridInitial);

$offerProductIds = [];
foreach ($offers as $op) {
    $offerProductIds[(int) $op['id']] = true;
}

/** @var list<array<string,mixed>> */
$productsNonOffer = [];
foreach ($products as $p) {
    if (isset($offerProductIds[(int) $p['id']])) {
        continue;
    }
    $productsNonOffer[] = $p;
}
$productsInitial = array_slice($productsNonOffer, 0, $sfHomeGridInitial);
$productsLazyRows = array_slice($productsNonOffer, $sfHomeGridInitial);

$sfHomeCardColorPidList = [];
foreach ($products as $hp) {
    if ((int) ($hp['has_colors'] ?? 0) === 1) {
        $sfHomeCardColorPidList[] = (int) $hp['id'];
    }
}
foreach ($offers as $hop) {
    if ((int) ($hop['has_colors'] ?? 0) === 1) {
        $sfHomeCardColorPidList[] = (int) $hop['id'];
    }
}
/** @var array<int, list<array{color: string, pattern: string}>> */
$sfProductCardVariantLines = orange_storefront_product_card_variant_line_map($pdo, $sfHomeCardColorPidList, $lang);

$sfHomeAllProductIds = [];
foreach ($products as $hpRow) {
    $sfHomeAllProductIds[(int) $hpRow['id']] = true;
}
foreach ($offers as $hopRow) {
    $sfHomeAllProductIds[(int) $hopRow['id']] = true;
}
/** @var array<int, array<string, string>> */
$sfHomeProductAttrMap = orange_storefront_product_attr_map($pdo, array_keys($sfHomeAllProductIds));
$sfHomeAttrFacets = orange_storefront_home_filterable_facets($pdo, $lang, $sfHomeCountryId);

$sfHomeCardAttrAttr = static function (int $pid) use ($sfHomeProductAttrMap): string {
    return orange_storefront_attr_data_attribute($sfHomeProductAttrMap[$pid] ?? []);
};

$homeHeroLines = storefront_home_hero_lines();
$homeHeroJson = json_encode($homeHeroLines, JSON_UNESCAPED_UNICODE);
$homeHeroFirst = (string) ($homeHeroLines[0] ?? '');

$ORANGE_STOREFRONT_PAGE_TITLE = t('home') . ' | ' . t('storefront_brand');
$ORANGE_STOREFRONT_META_DESCRIPTION = t('storefront_home_meta_description');
$ORANGE_STOREFRONT_CANONICAL_URL = storefront_absolute_url(storefront_url('home', $channelSlug, $lang));
$ORANGE_STOREFRONT_OG_TYPE = 'website';
$wordmarkOg = storefront_public_path(storefront_asset_url(
    storefront_asset_image_preferred_path('/assets/images/orange-company-wordmark.png')
));
if ($wordmarkOg !== '') {
    $ORANGE_STOREFRONT_OG_IMAGE = storefront_absolute_url($wordmarkOg);
}

include __DIR__ . '/../includes/header.php';

$sfHomeFilterCatalogId = static function (array $row): int {
    return isset($row['uf_cat_id']) ? (int) $row['uf_cat_id'] : 0;
};

$sfHomeFilterSubcategoryId = static function (array $row): int {
    return isset($row['uf_sub_id']) ? (int) $row['uf_sub_id'] : 0;
};

$storefrontExtraFilterSuffix = function (array $row) use ($categoryToDepartment, $sfHomeFilterCatalogId, $sfHomeFilterSubcategoryId): string {
    $parts = [];
    $fc = $sfHomeFilterCatalogId($row);
    if ($fc > 0) {
        $didDirect = isset($row['uf_dept_id']) && $row['uf_dept_id'] !== null ? (int) $row['uf_dept_id'] : 0;
        $did = $didDirect > 0 ? $didDirect : ($categoryToDepartment[$fc] ?? 0);
        if ($did > 0) {
            $parts[] = 'dept-' . $did;
        }
    }

    $sid = $sfHomeFilterSubcategoryId($row);
    if ($sid > 0) {
        $parts[] = 'sub-' . $sid;
    }

    return $parts === [] ? '' : ' ' . implode(' ', $parts);
};

/** @var list<array{id:int,df:string,imgSrc:string,title:string,price:string,href:string,vl:list<array{c:string,p:string}>}> */
$lazyForJs = [];
foreach ($productsLazyRows as $p) {
    $pid = (int) $p['id'];
    $vl = [];
    foreach ($sfProductCardVariantLines[$pid] ?? [] as $ln) {
        $vl[] = ['c' => (string) $ln['color'], 'p' => (string) $ln['pattern']];
    }
    $lazyForJs[] = [
        'id' => $pid,
        'df' => 'all cat-' . $sfHomeFilterCatalogId($p) . $storefrontExtraFilterSuffix($p),
        'imgSrc' => storefront_product_image_href((string) ($p['main_image'] ?? '')),
        'title' => storefront_product_display_name($p),
        'price' => number_format((float) $p['price'], 2),
        'href' => storefront_url('product', (string) $channel['slug'], $lang, ['id' => $pid]),
        'vl' => $vl,
        'attrs' => $sfHomeCardAttrAttr($pid),
    ];
}

$lazyOffersForJs = [];
foreach ($offersLazyRows as $p) {
    $pid = (int) $p['id'];
    $vlOff = [];
    foreach ($sfProductCardVariantLines[$pid] ?? [] as $ln) {
        $vlOff[] = ['c' => (string) $ln['color'], 'p' => (string) $ln['pattern']];
    }
    $lazyOffersForJs[] = [
        'id' => $pid,
        'df' => 'offers cat-' . $sfHomeFilterCatalogId($p) . $storefrontExtraFilterSuffix($p),
        'imgSrc' => storefront_product_image_href((string) ($p['main_image'] ?? '')),
        'title' => storefront_product_display_name($p),
        'oldPrice' => number_format((float) $p['price'], 2),
        'salePrice' => number_format((float) $p['price'] - (float) $p['discount'], 2),
        'href' => storefront_url('product', (string) $channel['slug'], $lang, ['id' => $pid]),
        'vl' => $vlOff,
        'attrs' => $sfHomeCardAttrAttr($pid),
    ];
}

/** اتجاه القائمة والشريط: عربي = يمين لليسار */
$storefrontListDir = $lang === 'ar' ? 'rtl' : 'ltr';
?>
<div class="container">
    <section class="hero-banner hero-banner--intro hero-banner--rotator" aria-label="<?php echo htmlspecialchars(t('home'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="hero-content">
            <p class="hero-rotator-wrap" aria-live="polite">
                <span class="hero-rotator-text" id="homeHeroRotator" dir="auto"><?php echo htmlspecialchars($homeHeroFirst, ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
        </div>
    </section>
    <textarea id="home-hero-lines-json" hidden readonly class="storefront-home-hero-json"><?php echo htmlspecialchars((string) $homeHeroJson, ENT_QUOTES, 'UTF-8'); ?></textarea>

    <?php if ($sfHomeAttrFacets !== []): ?>
    <section class="storefront-attr-filters" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('storefront_attr_filters_title'), ENT_QUOTES, 'UTF-8'); ?>">
        <h2 class="storefront-attr-filters__title"><?php echo htmlspecialchars(t('storefront_attr_filters_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="storefront-attr-filters__groups">
            <?php foreach ($sfHomeAttrFacets as $facet): ?>
                <?php
                $fKey = (string) ($facet['attribute_key'] ?? '');
                $fLabel = (string) ($facet['label'] ?? $fKey);
                $fValues = is_array($facet['values'] ?? null) ? $facet['values'] : [];
                if ($fKey === '' || $fValues === []) {
                    continue;
                }
                ?>
                <details class="storefront-attr-filters__group">
                    <summary class="storefront-attr-filters__summary"><?php echo htmlspecialchars($fLabel, ENT_QUOTES, 'UTF-8'); ?></summary>
                    <div class="storefront-attr-filters__values">
                        <?php foreach ($fValues as $fv): ?>
                            <?php
                            $fvVal = trim((string) ($fv['value'] ?? ''));
                            if ($fvVal === '') {
                                continue;
                            }
                            ?>
                            <button type="button" class="storefront-attr-filters__chip" data-attr-key="<?php echo htmlspecialchars($fKey, ENT_QUOTES, 'UTF-8'); ?>" data-attr-value="<?php echo htmlspecialchars($fvVal, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($fvVal, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <button type="button" class="storefront-attr-filters__clear" id="storefrontAttrFiltersClear" hidden><?php echo htmlspecialchars(t('storefront_attr_filters_clear'), ENT_QUOTES, 'UTF-8'); ?></button>
    </section>
    <?php endif; ?>

    <section class="tabs-section" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="storefront-browse-wrap">
        <button type="button" class="tabs-menu-open storefront-browse-menu-open" aria-expanded="false" aria-controls="storefrontBrowseMenuPanel" aria-haspopup="true">
            <span class="tabs-menu-open__icon" aria-hidden="true">☰</span>
            <span class="tabs-menu-open__text"><?php echo htmlspecialchars(t('storefront_menu'), ENT_QUOTES, 'UTF-8'); ?></span>
        </button>
        <div class="storefront-browse-menu" id="storefrontBrowseMenu" aria-hidden="true" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="storefront-browse-menu__backdrop" data-browse-menu-close tabindex="-1" aria-hidden="true"></div>
            <div class="storefront-browse-menu__panel" id="storefrontBrowseMenuPanel" role="region" aria-label="<?php echo htmlspecialchars(t('storefront_menu'), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="storefront-browse-menu__head">
                    <h2 class="storefront-browse-menu__title"><?php echo htmlspecialchars(t('storefront_menu'), ENT_QUOTES, 'UTF-8'); ?></h2>
                    <button type="button" class="storefront-browse-menu__close" data-browse-menu-close aria-label="<?php echo htmlspecialchars(t('storefront_menu_close'), ENT_QUOTES, 'UTF-8'); ?>">×</button>
                </div>
                <div class="storefront-browse-menu__body">
                    <button type="button" class="storefront-browse-menu__cta" data-apply-filter="all"><?php echo htmlspecialchars(t('storefront_menu_all_products'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <button type="button" class="storefront-browse-menu__cta storefront-browse-menu__cta--secondary" data-apply-filter="offers"><?php echo htmlspecialchars(t('offers'), ENT_QUOTES, 'UTF-8'); ?></button>

                    <?php foreach ($departments as $dep): ?>
                        <?php
                        $deptId = (int) $dep['id'];
                        $deptCats = $catsByDept[$deptId] ?? [];
                        if ($deptCats === []) {
                            continue;
                        }
                        ?>
                        <details class="browse-accordion browse-accordion--dept" id="browse-dept-<?php echo $deptId; ?>">
                            <summary class="browse-accordion__summary"><?php echo htmlspecialchars(storefront_catalog_label($dep, $lang), ENT_QUOTES, 'UTF-8'); ?></summary>
                            <div class="browse-accordion__content">
                                <button type="button" class="browse-accordion__action" data-apply-filter="dept-<?php echo $deptId; ?>">
                                    <?php echo htmlspecialchars(t('storefront_menu_whole_department'), ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                                <?php foreach ($deptCats as $cat): ?>
                                    <?php
                                    $catId = (int) $cat['id'];
                                    $subs = $subcategoriesByCategory[$catId] ?? [];
                                    ?>
                                    <?php if ($subs !== []): ?>
                                        <details class="browse-accordion browse-accordion--cat" id="browse-cat-<?php echo $catId; ?>">
                                            <summary class="browse-accordion__summary browse-accordion__summary--nested"><?php echo htmlspecialchars(storefront_catalog_label($cat, $lang), ENT_QUOTES, 'UTF-8'); ?></summary>
                                            <div class="browse-accordion__content browse-accordion__content--nested">
                                                <button type="button" class="browse-accordion__action" data-apply-filter="cat-<?php echo $catId; ?>">
                                                    <?php echo htmlspecialchars(t('storefront_menu_whole_category'), ENT_QUOTES, 'UTF-8'); ?>
                                                </button>
                                                <?php foreach ($subs as $sub): ?>
                                                    <button type="button" class="browse-accordion__action browse-accordion__action--sub" data-apply-filter="sub-<?php echo (int) $sub['id']; ?>">
                                                        <?php echo htmlspecialchars(storefront_catalog_label($sub, $lang), ENT_QUOTES, 'UTF-8'); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <button type="button" class="browse-accordion__action browse-accordion__action--flat" data-apply-filter="cat-<?php echo $catId; ?>">
                                            <?php echo htmlspecialchars(storefront_catalog_label($cat, $lang), ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>

                    <?php
                    $orphanCats = $catsByDept[0] ?? [];
                    if ($orphanCats !== []) {
                        ?>
                        <details class="browse-accordion browse-accordion--dept" id="browse-dept-other">
                            <summary class="browse-accordion__summary"><?php echo htmlspecialchars(t('storefront_menu_other_categories'), ENT_QUOTES, 'UTF-8'); ?></summary>
                            <div class="browse-accordion__content">
                                <?php foreach ($orphanCats as $cat): ?>
                                    <?php
                                    $catId = (int) $cat['id'];
                                    $subs = $subcategoriesByCategory[$catId] ?? [];
                                    ?>
                                    <?php if ($subs !== []): ?>
                                        <details class="browse-accordion browse-accordion--cat" id="browse-cat-<?php echo $catId; ?>">
                                            <summary class="browse-accordion__summary browse-accordion__summary--nested"><?php echo htmlspecialchars(storefront_catalog_label($cat, $lang), ENT_QUOTES, 'UTF-8'); ?></summary>
                                            <div class="browse-accordion__content browse-accordion__content--nested">
                                                <button type="button" class="browse-accordion__action" data-apply-filter="cat-<?php echo $catId; ?>">
                                                    <?php echo htmlspecialchars(t('storefront_menu_whole_category'), ENT_QUOTES, 'UTF-8'); ?>
                                                </button>
                                                <?php foreach ($subs as $sub): ?>
                                                    <button type="button" class="browse-accordion__action browse-accordion__action--sub" data-apply-filter="sub-<?php echo (int) $sub['id']; ?>">
                                                        <?php echo htmlspecialchars(storefront_catalog_label($sub, $lang), ENT_QUOTES, 'UTF-8'); ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <button type="button" class="browse-accordion__action browse-accordion__action--flat" data-apply-filter="cat-<?php echo $catId; ?>">
                                            <?php echo htmlspecialchars(storefront_catalog_label($cat, $lang), ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php } ?>
                </div>
            </div>
        </div>
        </div>
        <button type="button" class="tabs-nav-btn tabs-nav-btn--prev" onclick="scrollHomeCategoryTabs(-1)" aria-label="<?php echo htmlspecialchars(t('tabs_scroll_prev'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="tabs-nav-btn__icon" aria-hidden="true">‹</span>
        </button>
        <div class="tabs-scroll" id="homeCategoryTabs">
            <button type="button" class="tab-btn active" data-tab-filter="all" onclick="filterProducts('all', this)"><?php echo htmlspecialchars(t('all'), ENT_QUOTES, 'UTF-8'); ?></button>
            <button type="button" class="tab-btn" data-tab-filter="offers" onclick="filterProducts('offers', this)"><?php echo htmlspecialchars(t('offers'), ENT_QUOTES, 'UTF-8'); ?></button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="tab-btn" data-tab-filter="cat-<?php echo (int) $cat['id']; ?>" onclick="filterProducts('cat-<?php echo (int) $cat['id']; ?>', this)">
                    <?php echo htmlspecialchars(storefront_catalog_label($cat, $lang), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="tabs-nav-btn tabs-nav-btn--next" onclick="scrollHomeCategoryTabs(1)" aria-label="<?php echo htmlspecialchars(t('tabs_scroll_next'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="tabs-nav-btn__icon" aria-hidden="true">›</span>
        </button>
    </section>

    <section id="productsGrid" class="products-grid">
        <?php foreach ($offersInitial as $p): ?>
            <?php
            $sfCardPidOff = (int) $p['id'];
            $sfCardAttrsOff = $sfHomeCardAttrAttr($sfCardPidOff);
            ?>
            <article class="product-card" data-product-id="<?php echo $sfCardPidOff; ?>" data-filter="offers cat-<?php echo $sfHomeFilterCatalogId($p); ?><?php echo $storefrontExtraFilterSuffix($p); ?>"<?php echo $sfCardAttrsOff !== '' ? ' data-attrs="' . htmlspecialchars($sfCardAttrsOff, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                <div class="product-image-wrap">
                    <img src="<?php echo htmlspecialchars(storefront_product_image_href((string) ($p['main_image'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(storefront_product_display_name($p), ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                    <span class="offer-badge"><?php echo htmlspecialchars(t('offers'), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars(storefront_product_display_name($p), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php
                    $sfCvOff = $sfProductCardVariantLines[(int) $p['id']] ?? [];
                    if ($sfCvOff !== []) {
                        ?>
                    <div class="product-card-variant-meta" dir="auto">
                        <?php foreach ($sfCvOff as $sfLn): ?>
                        <div class="product-card-variant-line">
                            <?php if (($sfLn['color'] ?? '') !== ''): ?>
                            <span class="product-card-color"><?php echo htmlspecialchars((string) $sfLn['color'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (($sfLn['pattern'] ?? '') !== ''): ?>
                            <span class="product-card-pattern"><?php echo htmlspecialchars((string) $sfLn['pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                        <?php
                    }
                    ?>
                    <div class="price-row">
                        <strong><?php echo number_format((float) $p['price'] - (float) $p['discount'], 2); ?> KD</strong>
                        <span class="old-price"><?php echo number_format((float) $p['price'], 2); ?> KD</span>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string) $channel['slug'], $lang, ['id' => (int) $p['id']]), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(t('view_product'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php foreach ($productsInitial as $p): ?>
            <?php
            $sfCardPidReg = (int) $p['id'];
            $sfCardAttrsReg = $sfHomeCardAttrAttr($sfCardPidReg);
            ?>
            <article class="product-card" data-product-id="<?php echo $sfCardPidReg; ?>" data-filter="all cat-<?php echo $sfHomeFilterCatalogId($p); ?><?php echo $storefrontExtraFilterSuffix($p); ?>"<?php echo $sfCardAttrsReg !== '' ? ' data-attrs="' . htmlspecialchars($sfCardAttrsReg, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                <div class="product-image-wrap">
                    <img src="<?php echo htmlspecialchars(storefront_product_image_href((string) ($p['main_image'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(storefront_product_display_name($p), ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars(storefront_product_display_name($p), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php
                    $sfCvReg = $sfProductCardVariantLines[(int) $p['id']] ?? [];
                    if ($sfCvReg !== []) {
                        ?>
                    <div class="product-card-variant-meta" dir="auto">
                        <?php foreach ($sfCvReg as $sfLn): ?>
                        <div class="product-card-variant-line">
                            <?php if (($sfLn['color'] ?? '') !== ''): ?>
                            <span class="product-card-color"><?php echo htmlspecialchars((string) $sfLn['color'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (($sfLn['pattern'] ?? '') !== ''): ?>
                            <span class="product-card-pattern"><?php echo htmlspecialchars((string) $sfLn['pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                        <?php
                    }
                    ?>
                    <div class="price-row">
                        <strong><?php echo number_format((float) $p['price'], 2); ?> KD</strong>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string) $channel['slug'], $lang, ['id' => (int) $p['id']]), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(t('view_product'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
        <div id="orangeSfGridSentinel" aria-hidden="true" style="width:100%;height:1px;pointer-events:none;flex-basis:100%"></div>
    </section>
</div>

<script>
<?php
$orangeSfGridJsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $orangeSfGridJsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
?>
window.ORANGE_SF_GRID_LAZY_PRODUCTS = <?php echo json_encode($lazyForJs, $orangeSfGridJsonFlags); ?>;
window.ORANGE_SF_GRID_LAZY_OFFERS = <?php echo json_encode($lazyOffersForJs, $orangeSfGridJsonFlags); ?>;
window.ORANGE_SF_GRID_BATCH = <?php echo (int) $sfHomeGridScrollBatch; ?>;
window.ORANGE_SF_GRID_VIEW_LABEL = <?php echo json_encode(t('view_product'), $orangeSfGridJsonFlags); ?>;
window.ORANGE_SF_OFFERS_BADGE = <?php echo json_encode(t('offers'), $orangeSfGridJsonFlags); ?>;
var ORANGE_SF_GRID_FILTER_KEY = 'orange_sf_grid_filter';
var ORANGE_BROWSE_DETAILS_OPEN_KEY = 'orange_browse_details_open';
var orangeSfActiveAttrFilters = {};
function orangeSfParseAttrs(raw) {
    var out = {};
    String(raw || '').split(';').forEach(function (part) {
        if (!part) return;
        var i = part.indexOf(':');
        if (i <= 0) return;
        try {
            var k = decodeURIComponent(part.slice(0, i));
            var v = decodeURIComponent(part.slice(i + 1));
            if (k && v) out[k] = v;
        } catch (e) {}
    });
    return out;
}
function orangeSfCardMatchesAttrFilters(card) {
    var keys = Object.keys(orangeSfActiveAttrFilters);
    if (!keys.length) return true;
    var attrs = orangeSfParseAttrs(card.getAttribute('data-attrs') || '');
    for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var want = orangeSfActiveAttrFilters[k];
        if (want && attrs[k] !== want) return false;
    }
    return true;
}
function orangeSfSyncAttrFilterUi() {
    document.querySelectorAll('.storefront-attr-filters__chip').forEach(function (btn) {
        var k = btn.getAttribute('data-attr-key') || '';
        var v = btn.getAttribute('data-attr-value') || '';
        btn.classList.toggle('is-active', orangeSfActiveAttrFilters[k] === v);
    });
    var clr = document.getElementById('storefrontAttrFiltersClear');
    if (clr) clr.hidden = Object.keys(orangeSfActiveAttrFilters).length === 0;
}
function orangeSfApplyAttrChip(btn) {
    if (!btn) return;
    var k = btn.getAttribute('data-attr-key') || '';
    var v = btn.getAttribute('data-attr-value') || '';
    if (!k || !v) return;
    if (orangeSfActiveAttrFilters[k] === v) {
        delete orangeSfActiveAttrFilters[k];
    } else {
        orangeSfActiveAttrFilters[k] = v;
    }
    orangeSfSyncAttrFilterUi();
    applyGridFilter(orangeGetActiveGridFilter());
}
var orangeSfLazyRenderedIds = new Set();
var orangeSfGridObserver = null;
function orangeSfLazyTokenMatch(df, filter) {
    if (filter === 'all') {
        return false;
    }
    var tokens = String(df || '').trim().split(/\s+/).filter(Boolean);
    return tokens.indexOf(filter) !== -1;
}
function orangeSfPublicPath(path) {
    var raw = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    var base = raw.replace(/\/+$/, '');
    var p = path.charAt(0) === '/' ? path : '/' + path;
    return base + p;
}
function orangeSfResolveUploadsUrl(u) {
    if (!u) {
        return u;
    }
    var s = String(u);
    if (s.indexOf('//') === 0) {
        return s;
    }
    if (s.charAt(0) === '/' && (s.indexOf('/uploads/') === 0 || s.indexOf('/assets/') === 0)) {
        return orangeSfPublicPath(s);
    }
    return s;
}
function orangeSfImgSrcFromMainImage(img) {
    var name = String(img || '').trim();
    if (!name) {
        return '';
    }
    var segs = name.split(/[/\\]/);
    name = segs[segs.length - 1] || '';
    if (!name || name === '.' || name === '..') {
        return '';
    }
    var prefix = orangeSfPublicPath('/uploads/products/');
    return prefix + encodeURIComponent(name);
}
function orangeSfProductCardImgSrc(item) {
    if (item && item.imgSrc) {
        return orangeSfResolveUploadsUrl(item.imgSrc);
    }
    return orangeSfImgSrcFromMainImage(item && item.img);
}
function orangeSfMountProductThumb(wrap, item) {
    if (!wrap || !item) {
        return;
    }
    var alt = item.title || '';
    var primary = orangeSfProductCardImgSrc(item);
    if (!primary) {
        return;
    }
    var pathOnly = primary.split('?')[0].split('#')[0];
    var isWebp = pathOnly.toLowerCase().lastIndexOf('.webp') === pathOnly.length - 5;
    if (isWebp) {
        var imgW = document.createElement('img');
        imgW.setAttribute('src', primary);
        imgW.setAttribute('alt', alt);
        imgW.loading = 'lazy';
        imgW.decoding = 'async';
        wrap.appendChild(imgW);
        return;
    }
    var m = primary.match(/^(.+\/)([^/?#]+)$/);
    if (!m) {
        var imgF = document.createElement('img');
        imgF.setAttribute('src', primary);
        imgF.setAttribute('alt', alt);
        imgF.loading = 'lazy';
        imgF.decoding = 'async';
        wrap.appendChild(imgF);
        return;
    }
    var dir = m[1];
    var fileEnc = m[2];
    var file = fileEnc;
    try {
        file = decodeURIComponent(fileEnc);
    } catch (e1) {
        file = fileEnc;
    }
    var stem = file.indexOf('.') !== -1 ? file.slice(0, file.lastIndexOf('.')) : file;
    var webpSrc = dir + encodeURIComponent(stem + '.webp');
    var pic = document.createElement('picture');
    var srcEl = document.createElement('source');
    srcEl.setAttribute('type', 'image/webp');
    srcEl.setAttribute('srcset', webpSrc);
    pic.appendChild(srcEl);
    var imgP = document.createElement('img');
    imgP.setAttribute('src', primary);
    imgP.setAttribute('alt', alt);
    imgP.loading = 'lazy';
    imgP.decoding = 'async';
    pic.appendChild(imgP);
    wrap.appendChild(pic);
}
function orangeSfAppendCardVariantMeta(bodyEl, lines) {
    if (!bodyEl || !lines || !lines.length) {
        return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'product-card-variant-meta';
    wrap.setAttribute('dir', 'auto');
    var i;
    for (i = 0; i < lines.length; i++) {
        var ln = lines[i] || {};
        var line = document.createElement('div');
        line.className = 'product-card-variant-line';
        if (ln.c) {
            var sc = document.createElement('span');
            sc.className = 'product-card-color';
            sc.textContent = ln.c;
            line.appendChild(sc);
        }
        if (ln.p) {
            var sp = document.createElement('span');
            sp.className = 'product-card-pattern';
            sp.textContent = ln.p;
            line.appendChild(sp);
        }
        if (line.firstChild) {
            wrap.appendChild(line);
        }
    }
    if (!wrap.firstChild) {
        return;
    }
    var h3v = bodyEl.querySelector('h3');
    if (h3v) {
        h3v.insertAdjacentElement('afterend', wrap);
    } else {
        bodyEl.insertBefore(wrap, bodyEl.firstChild);
    }
}
function orangeSfGridSentinelEl() {
    return document.getElementById('orangeSfGridSentinel');
}
function orangeSfLastCardMatchingOfferToken(wantOffer) {
    var cards = document.querySelectorAll('#productsGrid .product-card[data-product-id]');
    var last = null;
    cards.forEach(function (c) {
        var tokens = (c.getAttribute('data-filter') || '').trim().split(/\s+/).filter(Boolean);
        var isOffer = tokens.indexOf('offers') !== -1;
        if (wantOffer ? isOffer : !isOffer) {
            last = c;
        }
    });
    return last;
}
function orangeSfInsertAfter(parent, el, ref) {
    if (!parent || !el) {
        return;
    }
    if (!ref) {
        parent.insertBefore(el, parent.firstChild);
        return;
    }
    if (ref.nextSibling) {
        parent.insertBefore(el, ref.nextSibling);
    } else {
        parent.appendChild(el);
    }
}
function orangeSfKeepSentinelLast() {
    var grid = document.getElementById('productsGrid');
    var sent = orangeSfGridSentinelEl();
    if (grid && sent && sent.parentNode === grid) {
        grid.appendChild(sent);
    }
}
function orangeSfAppendOfferCard(item) {
    var grid = document.getElementById('productsGrid');
    if (!grid || !item) {
        return;
    }
    var art = document.createElement('article');
    art.className = 'product-card';
    art.setAttribute('data-product-id', String(item.id));
    art.setAttribute('data-filter', item.df);
    if (item.attrs) {
        art.setAttribute('data-attrs', item.attrs);
    }
    var wrap = document.createElement('div');
    wrap.className = 'product-image-wrap';
    var img = document.createElement('img');
    img.setAttribute('src', orangeSfProductCardImgSrc(item));
    img.setAttribute('alt', item.title);
    img.loading = 'lazy';
    img.decoding = 'async';
    wrap.appendChild(img);
    var badge = document.createElement('span');
    badge.className = 'offer-badge';
    badge.textContent = window.ORANGE_SF_OFFERS_BADGE || '';
    wrap.appendChild(badge);
    var body = document.createElement('div');
    body.className = 'product-body';
    var h3 = document.createElement('h3');
    h3.textContent = item.title;
    var pr = document.createElement('div');
    pr.className = 'price-row';
    var strong = document.createElement('strong');
    strong.textContent = item.salePrice + ' KD';
    var oldSpan = document.createElement('span');
    oldSpan.className = 'old-price';
    oldSpan.textContent = item.oldPrice + ' KD';
    pr.appendChild(strong);
    pr.appendChild(oldSpan);
    var a = document.createElement('a');
    a.className = 'btn';
    a.setAttribute('href', item.href);
    a.textContent = window.ORANGE_SF_GRID_VIEW_LABEL || '';
    body.appendChild(h3);
    orangeSfAppendCardVariantMeta(body, item.vl || []);
    body.appendChild(pr);
    body.appendChild(a);
    art.appendChild(wrap);
    art.appendChild(body);
    var refOff = orangeSfLastCardMatchingOfferToken(true);
    orangeSfInsertAfter(grid, art, refOff);
    orangeSfKeepSentinelLast();
}
function orangeSfAppendRegularCard(item) {
    var grid = document.getElementById('productsGrid');
    if (!grid || !item) {
        return;
    }
    var art = document.createElement('article');
    art.className = 'product-card';
    art.setAttribute('data-product-id', String(item.id));
    art.setAttribute('data-filter', item.df);
    if (item.attrs) {
        art.setAttribute('data-attrs', item.attrs);
    }
    var wrap = document.createElement('div');
    wrap.className = 'product-image-wrap';
    orangeSfMountProductThumb(wrap, item);
    var body = document.createElement('div');
    body.className = 'product-body';
    var h3 = document.createElement('h3');
    h3.textContent = item.title;
    var pr = document.createElement('div');
    pr.className = 'price-row';
    var strong = document.createElement('strong');
    strong.textContent = item.price + ' KD';
    pr.appendChild(strong);
    var a = document.createElement('a');
    a.className = 'btn';
    a.setAttribute('href', item.href);
    a.textContent = window.ORANGE_SF_GRID_VIEW_LABEL || '';
    body.appendChild(h3);
    orangeSfAppendCardVariantMeta(body, item.vl || []);
    body.appendChild(pr);
    body.appendChild(a);
    art.appendChild(wrap);
    art.appendChild(body);
    var refReg = orangeSfLastCardMatchingOfferToken(false);
    var lastOff = orangeSfLastCardMatchingOfferToken(true);
    var anchor = refReg || lastOff;
    if (anchor) {
        orangeSfInsertAfter(grid, art, anchor);
    } else {
        var sent = orangeSfGridSentinelEl();
        if (sent && sent.parentNode === grid) {
            grid.insertBefore(art, sent);
        } else {
            grid.appendChild(art);
        }
    }
    orangeSfKeepSentinelLast();
}
function orangeSfEnsureLazyForFilter(filter) {
    if (filter === 'all') {
        return;
    }
    var off = window.ORANGE_SF_GRID_LAZY_OFFERS || [];
    var pool = window.ORANGE_SF_GRID_LAZY_PRODUCTS || [];
    if (!off.length && !pool.length) {
        return;
    }
    var grid = document.getElementById('productsGrid');
    var sent = document.getElementById('orangeSfGridSentinel');
    var i;
    for (i = 0; i < off.length; i++) {
        var oi = off[i];
        if (orangeSfLazyRenderedIds.has(oi.id)) {
            continue;
        }
        if (!orangeSfLazyTokenMatch(oi.df, filter)) {
            continue;
        }
        orangeSfAppendOfferCard(oi);
        orangeSfLazyRenderedIds.add(oi.id);
    }
    for (i = 0; i < pool.length; i++) {
        var item = pool[i];
        if (orangeSfLazyRenderedIds.has(item.id)) {
            continue;
        }
        if (!orangeSfLazyTokenMatch(item.df, filter)) {
            continue;
        }
        orangeSfAppendRegularCard(item);
        orangeSfLazyRenderedIds.add(item.id);
    }
    if (sent && grid && sent.parentNode !== grid) {
        grid.appendChild(sent);
    }
}
function orangeSfGridLazyRemainingCount() {
    var off = window.ORANGE_SF_GRID_LAZY_OFFERS || [];
    var pool = window.ORANGE_SF_GRID_LAZY_PRODUCTS || [];
    var n = 0;
    var i;
    for (i = 0; i < off.length; i++) {
        if (!orangeSfLazyRenderedIds.has(off[i].id)) {
            n++;
        }
    }
    for (i = 0; i < pool.length; i++) {
        if (!orangeSfLazyRenderedIds.has(pool[i].id)) {
            n++;
        }
    }
    return n;
}
function orangeSfLoadNextScrollBatch() {
    var off = window.ORANGE_SF_GRID_LAZY_OFFERS || [];
    var pool = window.ORANGE_SF_GRID_LAZY_PRODUCTS || [];
    var batch = window.ORANGE_SF_GRID_BATCH || 24;
    if (orangeGetActiveGridFilter() !== 'all') {
        return;
    }
    if (!off.length && !pool.length) {
        return;
    }
    var loaded = 0;
    var i;
    for (i = 0; i < off.length && loaded < batch; i++) {
        var oi = off[i];
        if (orangeSfLazyRenderedIds.has(oi.id)) {
            continue;
        }
        orangeSfAppendOfferCard(oi);
        orangeSfLazyRenderedIds.add(oi.id);
        loaded++;
    }
    for (i = 0; i < pool.length && loaded < batch; i++) {
        var item = pool[i];
        if (orangeSfLazyRenderedIds.has(item.id)) {
            continue;
        }
        orangeSfAppendRegularCard(item);
        orangeSfLazyRenderedIds.add(item.id);
        loaded++;
    }
}
function orangeSfMaybeDisconnectObserver() {
    if (orangeSfGridLazyRemainingCount() === 0 && orangeSfGridObserver) {
        orangeSfGridObserver.disconnect();
        orangeSfGridObserver = null;
    }
}
function orangeSfInitGridInfiniteScroll() {
    document.querySelectorAll('#productsGrid .product-card[data-product-id]').forEach(function (c) {
        var id = parseInt(c.getAttribute('data-product-id'), 10);
        if (!isNaN(id)) {
            orangeSfLazyRenderedIds.add(id);
        }
    });
    var pool = window.ORANGE_SF_GRID_LAZY_PRODUCTS || [];
    var off = window.ORANGE_SF_GRID_LAZY_OFFERS || [];
    var sent = document.getElementById('orangeSfGridSentinel');
    if ((!pool.length && !off.length) || !sent || typeof IntersectionObserver === 'undefined') {
        orangeSfMaybeDisconnectObserver();
        return;
    }
    orangeSfGridObserver = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) {
                    return;
                }
                if (orangeGetActiveGridFilter() !== 'all') {
                    return;
                }
                orangeSfLoadNextScrollBatch();
                applyGridFilterVisibility(orangeGetActiveGridFilter());
                orangeSfMaybeDisconnectObserver();
            });
        },
        { root: null, rootMargin: '320px', threshold: 0 }
    );
    orangeSfGridObserver.observe(sent);
}

function orangePersistGridFilter(filter) {
    try {
        if (filter) {
            sessionStorage.setItem(ORANGE_SF_GRID_FILTER_KEY, filter);
        }
    } catch (e) {}
}
function orangeGetActiveGridFilter() {
    var a = document.querySelector('.tab-btn.active');
    if (a) {
        var f = a.getAttribute('data-tab-filter');
        if (f) {
            return f;
        }
    }
    try {
        var s = sessionStorage.getItem(ORANGE_SF_GRID_FILTER_KEY);
        if (s) {
            return s;
        }
    } catch (e) {}
    return 'all';
}
function orangeExpandBrowseMenuForFilter(filter) {
    var root = document.getElementById('storefrontBrowseMenu');
    if (!root || !filter || filter === 'all' || filter === 'offers') {
        return;
    }
    var m = /^dept-(\d+)$/.exec(filter);
    if (m) {
        var dDept = document.getElementById('browse-dept-' + m[1]);
        if (dDept) {
            dDept.open = true;
        }
        return;
    }
    m = /^cat-(\d+)$/.exec(filter);
    if (m) {
        var dCat = document.getElementById('browse-cat-' + m[1]);
        if (dCat) {
            dCat.open = true;
            var p = dCat.closest('details.browse-accordion--dept');
            if (p) {
                p.open = true;
            }
        }
        return;
    }
    m = /^sub-(\d+)$/.exec(filter);
    if (m) {
        var subBtn = root.querySelector('[data-apply-filter="sub-' + m[1] + '"]');
        if (subBtn) {
            var catDet = subBtn.closest('details.browse-accordion--cat');
            if (catDet) {
                catDet.open = true;
            }
            var deptDet = catDet ? catDet.closest('details.browse-accordion--dept') : null;
            if (deptDet) {
                deptDet.open = true;
            }
        }
    }
}
function orangePersistBrowseDetailsOpen() {
    var root = document.getElementById('storefrontBrowseMenu');
    if (!root) {
        return;
    }
    var ids = [];
    root.querySelectorAll('details[id]').forEach(function (d) {
        if (d.open && d.id) {
            ids.push(d.id);
        }
    });
    try {
        sessionStorage.setItem(ORANGE_BROWSE_DETAILS_OPEN_KEY, JSON.stringify(ids));
    } catch (e) {}
}
function orangeRestoreBrowseDetailsOpen() {
    var root = document.getElementById('storefrontBrowseMenu');
    if (!root) {
        return;
    }
    var ids = [];
    try {
        var raw = sessionStorage.getItem(ORANGE_BROWSE_DETAILS_OPEN_KEY);
        if (raw) {
            ids = JSON.parse(raw);
        }
    } catch (e) {}
    if (!Array.isArray(ids)) {
        return;
    }
    ids.forEach(function (id) {
        var d = document.getElementById(id);
        if (d) {
            d.open = true;
        }
    });
}
function scrollHomeCategoryTabs(direction) {
    var el = document.getElementById('homeCategoryTabs');
    if (!el) return;
    var section = el.closest('.tabs-section');
    var rtl = section && section.getAttribute('dir') === 'rtl';
    var amount = Math.max(160, Math.round(el.clientWidth * 0.55));
    var delta = direction * amount * (rtl ? -1 : 1);
    el.scrollBy({ left: delta, behavior: 'smooth' });
}
function applyGridFilterVisibility(filter) {
    document.querySelectorAll('.product-card').forEach(function (card) {
        var raw = card.getAttribute('data-filter') || '';
        var catOk = true;
        if (filter !== 'all') {
            var tokens = raw.trim().split(/\s+/).filter(Boolean);
            catOk = tokens.indexOf(filter) !== -1;
        }
        card.style.display = (catOk && orangeSfCardMatchesAttrFilters(card)) ? '' : 'none';
    });
}
function applyGridFilter(filter) {
    orangeSfEnsureLazyForFilter(filter);
    applyGridFilterVisibility(filter);
    orangeSfMaybeDisconnectObserver();
}
function filterProducts(filter, el) {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
    if (el) el.classList.add('active');
    orangePersistGridFilter(filter);
    applyGridFilter(filter);
}
function filterFromBrowseMenu(filter) {
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.classList.remove('active');
        if (b.getAttribute('data-tab-filter') === filter) {
            b.classList.add('active');
        }
    });
    if (filter === 'all') {
        var allBtn = document.querySelector('.tab-btn[data-tab-filter="all"]');
        if (allBtn) allBtn.classList.add('active');
    }
    orangePersistGridFilter(filter);
    applyGridFilter(filter);
    orangeExpandBrowseMenuForFilter(filter);
    orangePersistBrowseDetailsOpen();
    closeStorefrontBrowseMenu();
}
function openStorefrontBrowseMenu() {
    var root = document.getElementById('storefrontBrowseMenu');
    var btn = document.querySelector('.storefront-browse-menu-open');
    var wrap = document.querySelector('.storefront-browse-wrap');
    if (!root) return;
    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    if (wrap) wrap.classList.add('storefront-browse-wrap--open');
    orangeRestoreBrowseDetailsOpen();
    orangeExpandBrowseMenuForFilter(orangeGetActiveGridFilter());
    orangePersistBrowseDetailsOpen();
}
function closeStorefrontBrowseMenu() {
    var root = document.getElementById('storefrontBrowseMenu');
    var btn = document.querySelector('.storefront-browse-menu-open');
    var wrap = document.querySelector('.storefront-browse-wrap');
    if (!root) return;
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    if (wrap) wrap.classList.remove('storefront-browse-wrap--open');
}
(function () {
    var openBtn = document.querySelector('.storefront-browse-menu-open');
    if (openBtn) {
        openBtn.addEventListener('click', function () {
            if (document.getElementById('storefrontBrowseMenu').classList.contains('is-open')) {
                closeStorefrontBrowseMenu();
            } else {
                openStorefrontBrowseMenu();
            }
        });
    }
    document.querySelectorAll('[data-browse-menu-close]').forEach(function (el) {
        el.addEventListener('click', closeStorefrontBrowseMenu);
    });
    var menuRoot = document.getElementById('storefrontBrowseMenu');
    if (menuRoot) {
        menuRoot.addEventListener('click', function (e) {
            var t = e.target.closest('[data-apply-filter]');
            if (!t) return;
            var f = t.getAttribute('data-apply-filter');
            if (f) filterFromBrowseMenu(f);
        });
        menuRoot.addEventListener('toggle', function (e) {
            var t = e.target;
            if (t && t.tagName === 'DETAILS' && menuRoot.contains(t)) {
                orangePersistBrowseDetailsOpen();
            }
        }, true);
    }
    orangeSfInitGridInfiniteScroll();
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeStorefrontBrowseMenu();
    });
    document.addEventListener(
        'pointerdown',
        function (e) {
            var root = document.getElementById('storefrontBrowseMenu');
            var wrap = document.querySelector('.storefront-browse-wrap');
            if (!root || !root.classList.contains('is-open')) {
                return;
            }
            if (wrap && e.target instanceof Node && wrap.contains(e.target)) {
                return;
            }
            closeStorefrontBrowseMenu();
        },
        true
    );
    document.querySelectorAll('.storefront-attr-filters__chip').forEach(function (btn) {
        btn.addEventListener('click', function () {
            orangeSfApplyAttrChip(btn);
        });
    });
    var attrClr = document.getElementById('storefrontAttrFiltersClear');
    if (attrClr) {
        attrClr.addEventListener('click', function () {
            orangeSfActiveAttrFilters = {};
            orangeSfSyncAttrFilterUi();
            applyGridFilter(orangeGetActiveGridFilter());
        });
    }
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php } catch (Throwable $__homeFatalErr) {
    if (function_exists('error_log')) {
        error_log('[orange] HOME FATAL: ' . $__homeFatalErr->getMessage() . ' in ' . $__homeFatalErr->getFile() . ':' . $__homeFatalErr->getLine());
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $__homeFatalErr->getMessage() . "\n" . $__homeFatalErr->getFile() . ':' . $__homeFatalErr->getLine();
} ?>
