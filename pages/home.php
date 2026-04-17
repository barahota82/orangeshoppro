<?php
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';

$homeHeroLines = storefront_home_hero_lines();
$homeHeroJson = json_encode($homeHeroLines, JSON_UNESCAPED_UNICODE);
$homeHeroFirst = (string)($homeHeroLines[0] ?? '');

$pdo = db();
$channel = get_channel_by_slug(current_channel_slug());

/**
 * مؤقت للتجربة: true = كل الأقسام النشطة تظهر حتى لو مفيش منتجات.
 * ارجعها false عند الرجوع للسياسة: تبويب القسم يظهر فقط لو فيه منتج نشط في الفئة.
 */
$storefront_show_all_active_categories = true;

$categoryProductFilter = $storefront_show_all_active_categories
    ? ''
    : '
          AND EXISTS (
              SELECT 1 FROM products p
              WHERE p.category_id = c.id AND p.is_active = 1
          )';

$hasDepartmentsTable = (bool) $pdo->query("SHOW TABLES LIKE 'departments'")->fetchColumn();
$departmentActiveFilter = '';
if ($hasDepartmentsTable && !$storefront_show_all_active_categories) {
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
    $offerProductIds[(int)$op['id']] = true;
}
?>
<div class="container">
    <section class="hero-banner hero-banner--intro hero-banner--rotator" aria-label="<?php echo htmlspecialchars(t('home'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="hero-content">
            <p class="hero-rotator-wrap" aria-live="polite">
                <span class="hero-rotator-text" id="homeHeroRotator" dir="auto"><?php echo htmlspecialchars($homeHeroFirst, ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
        </div>
    </section>
    <textarea id="home-hero-lines-json" hidden readonly class="storefront-home-hero-json"><?php echo htmlspecialchars((string)$homeHeroJson, ENT_QUOTES, 'UTF-8'); ?></textarea>

    <section class="tabs-section" dir="ltr">
        <button type="button" class="tabs-nav-btn tabs-nav-btn--prev" onclick="scrollHomeCategoryTabs(-1)" aria-label="<?php echo htmlspecialchars(t('tabs_scroll_prev'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="tabs-nav-btn__icon" aria-hidden="true">‹</span>
        </button>
        <div class="tabs-scroll" id="homeCategoryTabs">
            <button type="button" class="tab-btn active" onclick="filterProducts('all', this)"><?php echo htmlspecialchars(t('all')); ?></button>
            <button type="button" class="tab-btn" onclick="filterProducts('offers', this)"><?php echo htmlspecialchars(t('offers')); ?></button>
            <?php foreach ($categories as $cat): ?>
                <button type="button" class="tab-btn" onclick="filterProducts('cat-<?php echo (int)$cat['id']; ?>', this)">
                    <?php
                    $catLabel = match ($lang) {
                        'ar' => (string)($cat['name_ar'] ?: $cat['name_en'] ?? ''),
                        'fil' => (string)($cat['name_fil'] ?? $cat['name_en'] ?? ''),
                        'hi' => (string)($cat['name_hi'] ?? $cat['name_en'] ?? ''),
                        default => (string)($cat['name_en'] ?? $cat['name_ar'] ?? ''),
                    };
                    echo htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8');
                    ?>
                </button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="tabs-nav-btn tabs-nav-btn--next" onclick="scrollHomeCategoryTabs(1)" aria-label="<?php echo htmlspecialchars(t('tabs_scroll_next'), ENT_QUOTES, 'UTF-8'); ?>">
            <span class="tabs-nav-btn__icon" aria-hidden="true">›</span>
        </button>
    </section>

    <section id="productsGrid" class="products-grid">
        <?php foreach ($offers as $p): ?>
            <article class="product-card" data-filter="offers cat-<?php echo (int)$p['category_id']; ?>">
                <div class="product-image-wrap">
                    <img src="/uploads/products/<?php echo htmlspecialchars($p['main_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                    <span class="offer-badge"><?php echo htmlspecialchars(t('offers')); ?></span>
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <div class="price-row">
                        <strong><?php echo number_format((float)$p['price'] - (float)$p['discount'], 2); ?> KD</strong>
                        <span class="old-price"><?php echo number_format((float)$p['price'], 2); ?> KD</span>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string)$channel['slug'], $lang, ['id' => (int)$p['id']])); ?>">
                        <?php echo htmlspecialchars(t('view_product')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php foreach ($products as $p): ?>
            <?php if (isset($offerProductIds[(int)$p['id']])) {
                continue;
            } ?>
            <article class="product-card" data-filter="all cat-<?php echo (int)$p['category_id']; ?>">
                <div class="product-image-wrap">
                    <img src="/uploads/products/<?php echo htmlspecialchars($p['main_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <div class="price-row">
                        <strong><?php echo number_format((float)$p['price'], 2); ?> KD</strong>
                    </div>
                    <a class="btn" href="<?php echo htmlspecialchars(storefront_url('product', (string)$channel['slug'], $lang, ['id' => (int)$p['id']])); ?>">
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
    var amount = Math.max(160, Math.round(el.clientWidth * 0.55));
    el.scrollBy({ left: direction * amount, behavior: 'smooth' });
}
function filterProducts(filter, el) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    el.classList.add('active');

    document.querySelectorAll('.product-card').forEach(card => {
        const filters = card.dataset.filter || '';
        if (filter === 'all') {
            card.style.display = '';
            return;
        }
        card.style.display = filters.includes(filter) ? '' : 'none';
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
