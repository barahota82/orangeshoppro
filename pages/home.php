<?php
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';

$pdo = db();
$channel = get_channel_by_slug(current_channel_slug());
$channelId = (int)($channel['id'] ?? 0);

$categoriesSql = "
    SELECT c.*
    FROM categories c
    WHERE c.is_active = 1
      AND EXISTS (
          SELECT 1 FROM products p
          INNER JOIN product_channels pc ON pc.product_id = p.id
          WHERE p.category_id = c.id
            AND p.is_active = 1
            AND pc.channel_id = ?
      )
    ORDER BY c.sort_order ASC, c.id ASC
";
$stmt = $pdo->prepare($categoriesSql);
$stmt->execute([$channelId]);
$categories = $stmt->fetchAll();

$productsStmt = $pdo->prepare("
    SELECT DISTINCT p.*
    FROM products p
    INNER JOIN product_channels pc ON pc.product_id = p.id
    WHERE p.is_active = 1 AND pc.channel_id = ?
    ORDER BY p.id DESC
");
$productsStmt->execute([$channelId]);
$products = $productsStmt->fetchAll();

$offersStmt = $pdo->prepare("
    SELECT o.discount, p.*
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    INNER JOIN product_channels pc ON pc.product_id = p.id
    WHERE o.is_active = 1 AND p.is_active = 1 AND pc.channel_id = ?
    ORDER BY o.id DESC
");
$offersStmt->execute([$channelId]);
$offers = $offersStmt->fetchAll();
?>
<div class="container">
    <section class="hero-banner">
        <div class="hero-content">
            <h2><?php echo htmlspecialchars($channel['name']); ?></h2>
            <p><?php echo htmlspecialchars(t('offers')); ?> / <?php echo htmlspecialchars(t('category_products')); ?></p>
        </div>
    </section>

    <section class="tabs-section">
        <div class="tabs-scroll">
            <button class="tab-btn active" onclick="filterProducts('all', this)"><?php echo htmlspecialchars(t('all')); ?></button>
            <button class="tab-btn" onclick="filterProducts('offers', this)"><?php echo htmlspecialchars(t('offers')); ?></button>
            <?php foreach ($categories as $cat): ?>
                <button class="tab-btn" onclick="filterProducts('cat-<?php echo (int)$cat['id']; ?>', this)">
                    <?php echo htmlspecialchars($lang === 'ar' ? ($cat['name_ar'] ?: $cat['name_en']) : $cat['name_en']); ?>
                </button>
            <?php endforeach; ?>
        </div>
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
                    <a class="btn" href="/pages/product.php?id=<?php echo (int)$p['id']; ?>&channel=<?php echo urlencode($channel['slug']); ?>&lang=<?php echo urlencode($lang); ?>">
                        <?php echo htmlspecialchars(t('view_product')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>

        <?php foreach ($products as $p): ?>
            <article class="product-card" data-filter="all cat-<?php echo (int)$p['category_id']; ?>">
                <div class="product-image-wrap">
                    <img src="/uploads/products/<?php echo htmlspecialchars($p['main_image']); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>">
                </div>
                <div class="product-body">
                    <h3><?php echo htmlspecialchars($p['name']); ?></h3>
                    <div class="price-row">
                        <strong><?php echo number_format((float)$p['price'], 2); ?> KD</strong>
                    </div>
                    <a class="btn" href="/pages/product.php?id=<?php echo (int)$p['id']; ?>&channel=<?php echo urlencode($channel['slug']); ?>&lang=<?php echo urlencode($lang); ?>">
                        <?php echo htmlspecialchars(t('view_product')); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<script>
function filterProducts(filter, el) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    el.classList.add('active');

    document.querySelectorAll('.product-card').forEach(card => {
        const filters = card.dataset.filter || '';
        if (filter === 'all') {
            card.style.display = filters.includes('offers') ? '' : '';
            return;
        }
        card.style.display = filters.includes(filter) ? '' : 'none';
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
