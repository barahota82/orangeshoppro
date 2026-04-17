<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
include __DIR__ . '/../includes/header.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    echo '<div class="container"><div class="card-box">Product not found.</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll();

$variantsStmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY color ASC, size ASC, id ASC");
$variantsStmt->execute([$id]);
$variants = $variantsStmt->fetchAll();

$colors = [];
$sizes = [];
$totalStock = 0;
$scope = isset($product['sizing_guide_scope']) ? (string)$product['sizing_guide_scope'] : 'none';
$sizingHint = [
    'none' => '',
    'upper' => 'هذا المنتج: جدول مقاسات علوية (إرشادي).',
    'lower' => 'هذا المنتج: جدول مقاسات سفلية (إرشادي).',
    'both' => 'هذا المنتج: جداول علوية وسفلية (إرشادي).',
];
$sizingText = $sizingHint[$scope] ?? '';

foreach ($variants as $v) {
    if ($v['color'] !== '' && !in_array($v['color'], $colors, true)) $colors[] = $v['color'];
    if ($v['size'] !== '' && !in_array($v['size'], $sizes, true)) $sizes[] = $v['size'];
    $totalStock += (int)$v['stock_quantity'];
}
?>
<div class="container">
    <div class="product-page card-box">
        <div class="product-gallery">
            <img id="mainProductImage" class="main-product-image" src="/uploads/products/<?php echo htmlspecialchars($product['main_image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
            <?php if ($images): ?>
            <div class="thumbs">
                <button class="thumb active" onclick="changeMainImage('/uploads/products/<?php echo htmlspecialchars($product['main_image']); ?>', this)">
                    <img src="/uploads/products/<?php echo htmlspecialchars($product['main_image']); ?>" alt="">
                </button>
                <?php foreach ($images as $img): ?>
                    <button class="thumb" onclick="changeMainImage('/uploads/products/<?php echo htmlspecialchars($img['image_path']); ?>', this)">
                        <img src="/uploads/products/<?php echo htmlspecialchars($img['image_path']); ?>" alt="">
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            <div class="price-row"><strong><?php echo number_format((float)$product['price'], 2); ?> KD</strong></div>
            <p class="product-desc"><?php echo nl2br(htmlspecialchars((string)$product['description'])); ?></p>

            <?php if ($totalStock <= 0): ?>
                <div class="stock-out"><?php echo htmlspecialchars(t('out_of_stock')); ?></div>
            <?php endif; ?>

            <?php if ((int)$product['has_colors'] === 1): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('color')); ?></label>
                    <div class="chips">
                        <?php foreach ($colors as $color): ?>
                            <button type="button" class="chip color-chip" data-color="<?php echo htmlspecialchars($color); ?>" onclick="selectColor(this)">
                                <?php echo htmlspecialchars($color); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ((int)$product['has_sizes'] === 1): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('size')); ?></label>
                    <div class="chips">
                        <?php foreach ($sizes as $size): ?>
                            <button type="button" class="chip size-chip" data-size="<?php echo htmlspecialchars($size); ?>" onclick="selectSize(this)">
                                <?php echo htmlspecialchars($size); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="option-block qty-block">
                <label><?php echo htmlspecialchars(t('quantity')); ?></label>
                <div class="qty-control">
                    <button type="button" onclick="decreaseQty()">-</button>
                    <input type="number" id="qtyInput" value="1" min="1">
                    <button type="button" onclick="increaseQty()">+</button>
                </div>
            </div>

            <?php if ($scope !== 'none' && $sizingText !== ''): ?>
                <div class="option-block">
                    <button type="button" class="btn-secondary" onclick="alert(<?php echo json_encode($sizingText, JSON_UNESCAPED_UNICODE); ?>)">
                        جدول المقاسات (إرشادي)
                    </button>
                </div>
            <?php endif; ?>

            <div class="actions-row">
                <button type="button" class="btn" onclick="addCurrentProductToCart()" <?php echo $totalStock <= 0 ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars(t('add_to_cart')); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/product.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
window.CURRENT_PRODUCT = {
    id: <?php echo (int)$product['id']; ?>,
    name: <?php echo json_encode($product['name'], JSON_UNESCAPED_UNICODE); ?>,
    price: <?php echo json_encode((float)$product['price']); ?>,
    image: <?php echo json_encode($product['main_image'], JSON_UNESCAPED_UNICODE); ?>,
    has_colors: <?php echo (int)$product['has_colors']; ?>,
    has_sizes: <?php echo (int)$product['has_sizes']; ?>,
    sizing_guide_scope: <?php echo json_encode($scope, JSON_UNESCAPED_UNICODE); ?>,
    variants: <?php echo json_encode($variants, JSON_UNESCAPED_UNICODE); ?>
};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
