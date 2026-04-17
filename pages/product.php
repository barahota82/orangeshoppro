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
    $homeUrl = storefront_url('home', $channelSlug, $lang);
    ?>
<div class="container">
    <nav class="product-page-toolbar product-page-toolbar--dual">
        <a class="product-page__back" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="product-page__close" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </nav>
    <div class="card-box product-page product-page--empty">
        <p class="product-page__empty-msg"><?php echo htmlspecialchars(t('product_not_found'), ENT_QUOTES, 'UTF-8'); ?></p>
        <a class="btn" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
    <?php
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
$sizingHintKeys = [
    'none' => '',
    'upper' => 'sizing_hint_upper',
    'lower' => 'sizing_hint_lower',
    'both' => 'sizing_hint_both',
];
$sizingHintKey = $sizingHintKeys[$scope] ?? '';
$sizingText = $sizingHintKey !== '' ? t($sizingHintKey) : '';

$displayName = storefront_product_display_name($product);
$displayDesc = storefront_product_display_description($product);
$homeUrl = storefront_url('home', $channelSlug, $lang);
$needsVariantPick = ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1);

foreach ($variants as $v) {
    if ($v['color'] !== '' && !in_array($v['color'], $colors, true)) {
        $colors[] = $v['color'];
    }
    if ($v['size'] !== '' && !in_array($v['size'], $sizes, true)) {
        $sizes[] = $v['size'];
    }
    $totalStock += (int)$v['stock_quantity'];
}

$mainFile = trim((string)($product['main_image'] ?? ''));
$galleryUrls = [];
if ($mainFile !== '') {
    $galleryUrls[] = '/uploads/products/' . $mainFile;
}
foreach ($images as $img) {
    $rel = trim((string)($img['image_path'] ?? ''));
    if ($rel === '' || $rel === $mainFile) {
        continue;
    }
    $galleryUrls[] = '/uploads/products/' . $rel;
}
$galleryCount = count($galleryUrls);
$glPrevLabel = htmlspecialchars(t('product_gallery_prev'), ENT_QUOTES, 'UTF-8');
$glNextLabel = htmlspecialchars(t('product_gallery_next'), ENT_QUOTES, 'UTF-8');
$glDotsLabel = htmlspecialchars(t('product_gallery_dots'), ENT_QUOTES, 'UTF-8');
?>
<div class="container">
    <nav class="product-page-toolbar product-page-toolbar--dual" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>">
        <a class="product-page__back" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="product-page__close" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </nav>
    <div class="product-page card-box">
        <div class="product-gallery" id="productGallery" data-gallery-count="<?php echo (int)$galleryCount; ?>">
            <div class="product-gallery__stage">
                <?php if ($galleryCount > 1): ?>
                <button type="button" class="product-gallery__nav product-gallery__nav--prev" id="productGalleryPrev" aria-label="<?php echo $glPrevLabel; ?>"><span aria-hidden="true">‹</span></button>
                <?php endif; ?>
                <div class="product-gallery__viewport" id="productGalleryViewport" <?php echo $galleryCount > 1 ? 'tabindex="0"' : ''; ?>>
                    <div class="product-gallery__track" id="productGalleryTrack">
                        <?php foreach ($galleryUrls as $url): ?>
                        <div class="product-gallery__slide">
                            <img class="product-gallery__img" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($galleryCount > 1): ?>
                <button type="button" class="product-gallery__nav product-gallery__nav--next" id="productGalleryNext" aria-label="<?php echo $glNextLabel; ?>"><span aria-hidden="true">›</span></button>
                <?php endif; ?>
            </div>
            <?php if ($galleryCount > 1): ?>
            <div class="product-gallery__dots" id="productGalleryDots" role="tablist" aria-label="<?php echo $glDotsLabel; ?>">
                <?php for ($di = 0; $di < $galleryCount; $di++): ?>
                <button type="button" class="product-gallery__dot<?php echo $di === 0 ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $di === 0 ? 'true' : 'false'; ?>" data-index="<?php echo (int)$di; ?>" aria-label="<?php echo (int)($di + 1); ?> / <?php echo (int)$galleryCount; ?>"></button>
                <?php endfor; ?>
            </div>
            <div class="thumbs product-gallery__thumbs">
                <?php foreach ($galleryUrls as $ti => $turl): ?>
                <button type="button" class="thumb<?php echo $ti === 0 ? ' active' : ''; ?>" data-gallery-index="<?php echo (int)$ti; ?>">
                    <img src="<?php echo htmlspecialchars($turl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <h2 class="product-info__title"><?php echo htmlspecialchars($displayName); ?></h2>
            <div class="price-row product-info__price"><strong><?php echo number_format((float)$product['price'], 2); ?> <?php echo htmlspecialchars(t('currency_kd'), ENT_QUOTES, 'UTF-8'); ?></strong></div>

            <?php if ($totalStock > 0): ?>
            <div id="productStockBanner" class="stock-banner" role="status" aria-live="polite" hidden></div>
            <?php endif; ?>

            <?php if ($displayDesc !== ''): ?>
            <p class="product-desc product-info__desc"><?php echo nl2br(htmlspecialchars($displayDesc)); ?></p>
            <?php endif; ?>

            <?php if ($totalStock <= 0): ?>
                <div class="stock-out product-info__stock"><?php echo htmlspecialchars(t('out_of_stock')); ?></div>
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
                <div class="option-block product-info__sizing">
                    <button type="button" class="btn-secondary" id="productSizingOpen" onclick="openProductSizingDialog()">
                        <?php echo htmlspecialchars(t('sizing_guide')); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div class="actions-row product-info__actions">
                <button type="button" class="btn product-add-cart-btn" onclick="addCurrentProductToCart()" <?php echo ($totalStock <= 0 || $needsVariantPick) ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars(t('add_to_cart')); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($scope !== 'none' && $sizingText !== ''): ?>
<dialog id="productSizingDialog" class="product-sizing-dialog">
    <div class="product-sizing-dialog__inner">
        <h3 class="product-sizing-dialog__title"><?php echo htmlspecialchars(t('sizing_guide')); ?></h3>
        <p class="product-sizing-dialog__body"><?php echo htmlspecialchars($sizingText); ?></p>
        <form method="dialog">
            <button type="submit" class="btn btn-secondary product-sizing-dialog__close"><?php echo htmlspecialchars(t('sizing_guide_close')); ?></button>
        </form>
    </div>
</dialog>
<?php endif; ?>

<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/product.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
window.CURRENT_PRODUCT = {
    id: <?php echo (int)$product['id']; ?>,
    name: <?php echo json_encode($displayName, JSON_UNESCAPED_UNICODE); ?>,
    price: <?php echo json_encode((float)$product['price']); ?>,
    image: <?php echo json_encode($product['main_image'], JSON_UNESCAPED_UNICODE); ?>,
    has_colors: <?php echo (int)$product['has_colors']; ?>,
    has_sizes: <?php echo (int)$product['has_sizes']; ?>,
    sizing_guide_scope: <?php echo json_encode($scope, JSON_UNESCAPED_UNICODE); ?>,
    variants: <?php echo json_encode($variants, JSON_UNESCAPED_UNICODE); ?>,
    total_stock_sum: <?php echo (int)$totalStock; ?>,
    low_stock_threshold: 5
};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
