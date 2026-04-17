<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * @param array<string,mixed> $row
 */
function storefront_catalog_label(array $row, string $lang): string
{
    return match ($lang) {
        'ar' => (string)($row['name_ar'] ?? $row['name_en'] ?? ''),
        'fil' => (string)($row['name_fil'] ?? $row['name_en'] ?? ''),
        'hi' => (string)($row['name_hi'] ?? $row['name_en'] ?? ''),
        default => (string)($row['name_en'] ?? $row['name_ar'] ?? ''),
    };
}

include __DIR__ . '/../includes/header.php';

$homeHeroLines = storefront_home_hero_lines();
$homeHeroJson = json_encode($homeHeroLines, JSON_UNESCAPED_UNICODE);
$homeHeroFirst = (string)($homeHeroLines[0] ?? '');

$pdo = db();
$channel = get_channel_by_slug(current_channel_slug());

/*
 * سياسة التبويبات (الشريط الأفقي): فئات بها منتج نشط فقط — عرض فئة كاملة.
 * القائمة (المينيو): أقسام ← فئات ← تصنيفات فرعية (إن وُجدت) مع اختيار نطاق أضيق.
 */
$categoryProductFilter = '
          AND EXISTS (
              SELECT 1 FROM products p
              WHERE p.category_id = c.id AND p.is_active = 1
          )';

$hasDepartmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
$hasSubcategoriesTable = (bool) $pdo->query("SHOW TABLES LIKE 'subcategories'")->fetchColumn();

$departmentActiveFilter = '';
if ($hasDepartmentsTable) {
    $departmentActiveFilter = '
          AND (
              c.department_id IS NULL
              OR d.id IS NULL
              OR d.is_active = 1
          )';
}

if ($hasDepartmentsTable) {
    $categoriesStmt = $pdo->query(
        "
        SELECT c.*
        FROM categories c
        LEFT JOIN departments d ON d.id = c.department_id
        WHERE c.is_active = 1
          " . $departmentActiveFilter . "
          " . $categoryProductFilter . "
        ORDER BY c.sort_order ASC, c.id ASC
    "
    );
} else {
    $categoriesStmt = $pdo->query(
        "
        SELECT c.*
        FROM categories c
        WHERE c.is_active = 1
          " . $categoryProductFilter . "
        ORDER BY c.sort_order ASC, c.id ASC
    "
    );
}
$categories = $categoriesStmt ? $categoriesStmt->fetchAll() : [];

/** @var array<int,int> */
$categoryToDepartment = [];
$departments = [];
if ($hasDepartmentsTable) {
    $depListStmt = $pdo->query(
        '
        SELECT d.*
        FROM departments d
        WHERE d.is_active = 1
          AND EXISTS (
              SELECT 1
              FROM categories c
              INNER JOIN products p ON p.category_id = c.id AND p.is_active = 1
              WHERE c.department_id = d.id
                AND c.is_active = 1
          )
        ORDER BY d.sort_order ASC, d.id ASC
    '
    );
    $departments = $depListStmt ? $depListStmt->fetchAll() : [];
    $mapStmt = $pdo->query('SELECT id, department_id FROM categories');
    foreach (($mapStmt ? $mapStmt->fetchAll() : []) as $row) {
        $categoryToDepartment[(int) $row['id']] = isset($row['department_id']) && $row['department_id'] !== null
            ? (int) $row['department_id']
            : 0;
    }
}

/** @var array<int, list<array<string,mixed>>> */
$subcategoriesByCategory = [];
if ($hasSubcategoriesTable) {
    $subStmt = $pdo->query(
        "
        SELECT s.*
        FROM subcategories s
        WHERE s.is_active = 1
          AND EXISTS (
              SELECT 1 FROM products p
              WHERE p.subcategory_id = s.id AND p.is_active = 1
          )
        ORDER BY s.category_id ASC, s.sort_order ASC, s.id ASC
    "
    );
    foreach (($subStmt ? $subStmt->fetchAll() : []) as $srow) {
        $scid = (int) $srow['category_id'];
        if (!isset($subcategoriesByCategory[$scid])) {
            $subcategoriesByCategory[$scid] = [];
        }
        $subcategoriesByCategory[$scid][] = $srow;
    }
}

/** @var array<int, list<array<string,mixed>>> */
$catsByDept = [];
$deptIdsInMenu = $hasDepartmentsTable
    ? array_map(static fn (array $d): int => (int) $d['id'], $departments)
    : [];
foreach ($categories as $cat) {
    $did = isset($cat['department_id']) && $cat['department_id'] !== null ? (int) $cat['department_id'] : 0;
    if (!$hasDepartmentsTable) {
        $did = 0;
    } elseif ($did > 0 && !in_array($did, $deptIdsInMenu, true)) {
        $did = 0;
    }
    if (!isset($catsByDept[$did])) {
        $catsByDept[$did] = [];
    }
    $catsByDept[$did][] = $cat;
}

$productsStmt = $pdo->query("
    SELECT p.*
    FROM products p
    WHERE p.is_active = 1
    ORDER BY p.id DESC
");
$products = $productsStmt ? $productsStmt->fetchAll() : [];

$offersStmt = $pdo->query("
    SELECT o.discount, p.*
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    WHERE o.is_active = 1 AND p.is_active = 1
    ORDER BY o.id DESC
");
$offers = $offersStmt ? $offersStmt->fetchAll() : [];

$offerProductIds = [];
foreach ($offers as $op) {
    $offerProductIds[(int) $op['id']] = true;
}

$storefrontExtraFilterSuffix = function (array $row) use ($categoryToDepartment): string {
    $parts = [];
    $cid = (int) ($row['category_id'] ?? 0);
    if ($cid > 0) {
        $did = $categoryToDepartment[$cid] ?? 0;
        if ($did > 0) {
            $parts[] = 'dept-' . $did;
        }
    }
    $sid = isset($row['subcategory_id']) ? (int) $row['subcategory_id'] : 0;
    if ($sid > 0) {
        $parts[] = 'sub-' . $sid;
    }

    return $parts === [] ? '' : ' ' . implode(' ', $parts);
};

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

    <div class="storefront-browse-menu" id="storefrontBrowseMenu" aria-hidden="true" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="storefront-browse-menu__backdrop" data-browse-menu-close tabindex="-1" aria-hidden="true"></div>
        <div class="storefront-browse-menu__panel" id="storefrontBrowseMenuPanel" role="dialog" aria-modal="true" aria-label="<?php echo htmlspecialchars(t('storefront_menu'), ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
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
                    <details class="browse-accordion browse-accordion--dept">
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
                                    <details class="browse-accordion browse-accordion--cat">
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
                    <details class="browse-accordion browse-accordion--dept">
                        <summary class="browse-accordion__summary"><?php echo htmlspecialchars(t('storefront_menu_other_categories'), ENT_QUOTES, 'UTF-8'); ?></summary>
                        <div class="browse-accordion__content">
                            <?php foreach ($orphanCats as $cat): ?>
                                <?php
                                $catId = (int) $cat['id'];
                                $subs = $subcategoriesByCategory[$catId] ?? [];
                                ?>
                                <?php if ($subs !== []): ?>
                                    <details class="browse-accordion browse-accordion--cat">
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

    <section class="tabs-section" dir="<?php echo htmlspecialchars($storefrontListDir, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="tabs-menu-open storefront-browse-menu-open" aria-expanded="false" aria-controls="storefrontBrowseMenuPanel" aria-haspopup="dialog">
            <span class="tabs-menu-open__icon" aria-hidden="true">☰</span>
            <span class="tabs-menu-open__text"><?php echo htmlspecialchars(t('storefront_menu'), ENT_QUOTES, 'UTF-8'); ?></span>
        </button>
        <button type="button" class="tabs-nav-btn tabs-nav-btn--prev" onclick="scrollHomeCategoryTabs(-1)" aria-label="<?php echo htmlspecialchars(t('tabs_scroll_prev'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="tabs-nav-btn__icon" aria-hidden="true">‹</span>
        </button>
        <div class="tabs-scroll" id="homeCategoryTabs">
            <button type="button" class="tab-btn active" data-tab-filter="all" onclick="filterProducts('all', this)"><?php echo htmlspecialchars(t('all')); ?></button>
            <button type="button" class="tab-btn" data-tab-filter="offers" onclick="filterProducts('offers', this)"><?php echo htmlspecialchars(t('offers')); ?></button>
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
        <?php foreach ($offers as $p): ?>
            <article class="product-card" data-filter="offers cat-<?php echo (int) $p['category_id']; ?><?php echo $storefrontExtraFilterSuffix($p); ?>">
                <div class="product-image-wrap">
                    <img src="/uploads/products/<?php echo htmlspecialchars($p['main_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                    <span class="offer-badge"><?php echo htmlspecialchars(t('offers')); ?></span>
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <div class="price-row">
                        <strong><?php echo number_format((float) $p['price'] - (float) $p['discount'], 2); ?> KD</strong>
                        <span class="old-price"><?php echo number_format((float) $p['price'], 2); ?> KD</span>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string) $channel['slug'], $lang, ['id' => (int) $p['id']])); ?>">
                        <?php echo htmlspecialchars(t('view_product')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php foreach ($products as $p): ?>
            <?php if (isset($offerProductIds[(int) $p['id']])) {
                continue;
            } ?>
            <article class="product-card" data-filter="all cat-<?php echo (int) $p['category_id']; ?><?php echo $storefrontExtraFilterSuffix($p); ?>">
                <div class="product-image-wrap">
                    <img src="/uploads/products/<?php echo htmlspecialchars($p['main_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <div class="price-row">
                        <strong><?php echo number_format((float) $p['price'], 2); ?> KD</strong>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string) $channel['slug'], $lang, ['id' => (int) $p['id']])); ?>">
                        <?php echo htmlspecialchars(t('view_product')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<script>
function scrollHomeCategoryTabs(direction) {
    var el = document.getElementById('homeCategoryTabs');
    if (!el) return;
    var section = el.closest('.tabs-section');
    var rtl = section && section.getAttribute('dir') === 'rtl';
    var amount = Math.max(160, Math.round(el.clientWidth * 0.55));
    var delta = direction * amount * (rtl ? -1 : 1);
    el.scrollBy({ left: delta, behavior: 'smooth' });
}
function applyGridFilter(filter) {
    document.querySelectorAll('.product-card').forEach(function (card) {
        var filters = card.getAttribute('data-filter') || '';
        if (filter === 'all') {
            card.style.display = '';
            return;
        }
        card.style.display = filters.indexOf(filter) !== -1 ? '' : 'none';
    });
}
function filterProducts(filter, el) {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
    if (el) el.classList.add('active');
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
    applyGridFilter(filter);
    closeStorefrontBrowseMenu();
}
function openStorefrontBrowseMenu() {
    var root = document.getElementById('storefrontBrowseMenu');
    var btn = document.querySelector('.storefront-browse-menu-open');
    if (!root) return;
    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    if (btn) btn.setAttribute('aria-expanded', 'true');
    document.body.classList.add('storefront-browse-menu-lock');
}
function closeStorefrontBrowseMenu() {
    var root = document.getElementById('storefrontBrowseMenu');
    var btn = document.querySelector('.storefront-browse-menu-open');
    if (!root) return;
    root.classList.remove('is-open');
    root.setAttribute('aria-hidden', 'true');
    if (btn) btn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('storefront-browse-menu-lock');
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
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeStorefrontBrowseMenu();
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
