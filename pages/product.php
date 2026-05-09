<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../includes/catalog_labels.php';
require_once __DIR__ . '/../includes/advisory_sizing_guides.php';
require_once __DIR__ . '/../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (
    $product !== false
    && is_array($product)
    && !orange_storefront_product_in_active_unified_chain($pdo, $id)
) {
    $product = false;
}

$tbState = storefront_toolbar_state();
$channelSlug = $tbState['channelSlug'];
$lang = $tbState['lang'];

if (!$product) {
    $ORANGE_STOREFRONT_PAGE_TITLE = t('product_not_found') . ' | ' . t('storefront_brand');
    $ORANGE_STOREFRONT_META_DESCRIPTION = '';
    include __DIR__ . '/../includes/header.php';
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

$seoTitlePart = storefront_product_seo_meta_title($product);
$ORANGE_STOREFRONT_PAGE_TITLE = ($seoTitlePart !== '' ? $seoTitlePart . ' | ' : '') . t('storefront_brand');
$ORANGE_STOREFRONT_META_DESCRIPTION = storefront_product_seo_meta_description($product);
$ORANGE_STOREFRONT_CANONICAL_URL = storefront_absolute_url(
    storefront_url('product', $channelSlug, $lang, ['id' => $id])
);
$ORANGE_STOREFRONT_OG_TYPE = 'product';
$mainForOg = trim((string) ($product['main_image'] ?? ''));
if ($mainForOg !== '') {
    $ogPath = storefront_product_image_href($mainForOg);
    if ($ogPath !== '') {
        $ORANGE_STOREFRONT_OG_IMAGE = storefront_absolute_url($ogPath);
    }
}

include __DIR__ . '/../includes/header.php';

$imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll();

$variantsStmt = $pdo->prepare(
    "SELECT v.*,
        cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id,
        sfs.label_ar AS sfs_la, sfs.label_en AS sfs_le,
        sfs.label_fil AS sfs_lf, sfs.label_hi AS sfs_lh
     FROM product_variants v
     LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
     LEFT JOIN size_family_sizes sfs ON sfs.id = v.size_family_size_id
     WHERE v.product_id = ? ORDER BY v.color ASC, v.size ASC, v.id ASC"
);
$variantsStmt->execute([$id]);
$variants = $variantsStmt->fetchAll();

$colorChipOrder = [];
/** @var array<string, array{color: string, pattern: string}> */
$colorChipMeta = [];
$sizeChipOrder = [];
/** @var array<string, string> */
$sizeChipLabel = [];
$totalStock = 0;
$scope = isset($product['sizing_guide_scope']) ? (string)$product['sizing_guide_scope'] : 'none';
$sizingHintKeys = [
    'none' => '',
    'upper' => 'sizing_hint_upper',
    'lower' => 'sizing_hint_lower',
    'both' => 'sizing_hint_both',
    'single' => 'sizing_hint_single',
];
$sizingHintKey = $sizingHintKeys[$scope] ?? '';
$sizingText = $sizingHintKey !== '' ? t($sizingHintKey) : '';

$sizingKinds = orange_advisory_sizing_product_scope_kinds($scope);
$advisorySizing = ['use_dynamic' => false, 'sections' => []];
$sfId = isset($product['size_family_id']) ? (int) $product['size_family_id'] : 0;
if ($sfId > 0 && $sizingKinds !== []) {
    $advisorySizing = orange_advisory_sizing_build_sections($pdo, $sfId, $sizingKinds, $lang);
}

$sizingChartRows = [];
$sizingShowFoot = false;
if ($sfId > 0 && orange_table_exists($pdo, 'size_family_sizes')) {
    $hasFootCol = orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm');
    $cols = 'label_ar, label_en';
    if (
        orange_table_has_column($pdo, 'size_family_sizes', 'label_fil')
        && orange_table_has_column($pdo, 'size_family_sizes', 'label_hi')
    ) {
        $cols .= ', label_fil, label_hi';
    }
    if ($hasFootCol) {
        $cols .= ', foot_length_cm';
    }
    $sst = $pdo->prepare(
        "SELECT {$cols} FROM size_family_sizes
         WHERE size_family_id = ? AND is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $sst->execute([$sfId]);
    $sizingChartRows = $sst->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($hasFootCol) {
        foreach ($sizingChartRows as $r) {
            if (isset($r['foot_length_cm']) && $r['foot_length_cm'] !== null && trim((string) $r['foot_length_cm']) !== '') {
                $sizingShowFoot = true;
                break;
            }
        }
    }
}
$advisorySizingReady = !empty($advisorySizing['use_dynamic']) && ($advisorySizing['sections'] ?? []) !== [];
$legacySizingReady = !$advisorySizingReady && $sizingChartRows !== [];
$showSizingGuide = $scope !== 'none' && ($sizingText !== '' || $advisorySizingReady || $legacySizingReady);

$displayName = storefront_product_display_name($product);
$displayDesc = storefront_product_display_description($product);
$homeUrl = storefront_url('home', $channelSlug, $lang);
$needsVariantPick = ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1);

foreach ($variants as $v) {
    if ((int)$product['has_colors'] === 1) {
        $mk = trim((string)($v['color'] ?? ''));
        if ($mk !== '' && !isset($colorChipMeta[$mk])) {
            $pc = isset($v['primary_color_id']) ? (int) $v['primary_color_id'] : 0;
            $sc = isset($v['secondary_color_id']) ? (int) $v['secondary_color_id'] : 0;
            $pp = isset($v['primary_pattern_id']) ? (int) $v['primary_pattern_id'] : 0;
            $psp = isset($v['secondary_pattern_id']) ? (int) $v['secondary_pattern_id'] : 0;
            if ($pc > 0 || $sc > 0 || $pp > 0 || $psp > 0) {
                $segs = orange_colorway_display_segments(
                    $pdo,
                    $pc > 0 ? $pc : null,
                    $sc > 0 ? $sc : null,
                    $pp > 0 ? $pp : null,
                    $psp > 0 ? $psp : null,
                    $lang
                );
            } else {
                $segs = orange_storefront_split_variant_color_field($mk);
            }
            $colorChipMeta[$mk] = $segs;
            $colorChipOrder[] = $mk;
        }
    }
    if ((int)$product['has_sizes'] === 1) {
        $sk = trim((string)($v['size'] ?? ''));
        if ($sk !== '' && !isset($sizeChipLabel[$sk])) {
            $szRow = null;
            if (
                isset($v['sfs_la'])
                || isset($v['sfs_le'])
                || (isset($v['sfs_lf']) && trim((string) $v['sfs_lf']) !== '')
                || (isset($v['sfs_lh']) && trim((string) $v['sfs_lh']) !== '')
            ) {
                $szRow = [
                    'label_ar' => (string) ($v['sfs_la'] ?? ''),
                    'label_en' => (string) ($v['sfs_le'] ?? ''),
                    'label_fil' => (string) ($v['sfs_lf'] ?? ''),
                    'label_hi' => (string) ($v['sfs_lh'] ?? ''),
                ];
            }
            $sizeChipLabel[$sk] = $szRow ? orange_size_display_label($szRow, $lang) : $sk;
            $sizeChipOrder[] = $sk;
        }
    }
    $totalStock += (int) $v['stock_quantity'];
}

$colors = $colorChipOrder;
$sizes = $sizeChipOrder;

$mainFile = trim((string)($product['main_image'] ?? ''));
$galleryUrls = [];
if ($mainFile !== '') {
    $uMain = storefront_product_image_href($mainFile);
    if ($uMain !== '') {
        $galleryUrls[] = $uMain;
    }
}
foreach ($images as $img) {
    $rel = trim((string)($img['image_path'] ?? ''));
    if ($rel === '' || $rel === $mainFile) {
        continue;
    }
    $uExtra = storefront_product_image_href($rel);
    if ($uExtra !== '') {
        $galleryUrls[] = $uExtra;
    }
}
$galleryCount = count($galleryUrls);

$colorwayGalleryByChip = [];
if ((int) $product['has_colors'] === 1 && orange_table_exists($pdo, 'product_colorway_images')) {
    $cwIdToChip = [];
    foreach ($variants as $v) {
        $cid = isset($v['product_colorway_id']) ? (int) $v['product_colorway_id'] : 0;
        if ($cid > 0 && !isset($cwIdToChip[$cid])) {
            $cwIdToChip[$cid] = trim((string) ($v['color'] ?? ''));
        }
    }
    if ($cwIdToChip !== []) {
        $stPci = $pdo->prepare(
            'SELECT pci.product_colorway_id, pci.image_path
             FROM product_colorway_images pci
             INNER JOIN product_colorways cw ON cw.id = pci.product_colorway_id
             WHERE cw.product_id = ?
             ORDER BY pci.product_colorway_id ASC, pci.sort_order ASC, pci.id ASC'
        );
        $stPci->execute([$id]);
        while ($rw = $stPci->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($rw)) {
                continue;
            }
            $ccw = isset($rw['product_colorway_id']) ? (int) $rw['product_colorway_id'] : 0;
            $chip = $cwIdToChip[$ccw] ?? '';
            if ($chip === '') {
                continue;
            }
            $rel = trim((string) ($rw['image_path'] ?? ''));
            if ($rel === '') {
                continue;
            }
            $href = storefront_product_image_href($rel);
            if ($href === '') {
                continue;
            }
            if (!isset($colorwayGalleryByChip[$chip])) {
                $colorwayGalleryByChip[$chip] = [];
            }
            $colorwayGalleryByChip[$chip][] = $href;
        }
    }
}

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
            <h2 class="product-info__title"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="price-row product-info__price"><strong><?php echo number_format((float)$product['price'], 2); ?> <?php echo htmlspecialchars(t('currency_kd'), ENT_QUOTES, 'UTF-8'); ?></strong></div>

            <?php if ($totalStock > 0): ?>
            <div id="productStockBanner" class="stock-banner" role="status" aria-live="polite" hidden></div>
            <?php endif; ?>

            <?php if ($displayDesc !== ''): ?>
            <p class="product-desc product-info__desc"><?php echo nl2br(htmlspecialchars($displayDesc, ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>

            <?php if ($totalStock <= 0): ?>
                <div class="stock-out product-info__stock"><?php echo htmlspecialchars(t('out_of_stock'), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ((int)$product['has_colors'] === 1): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('color'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="chips">
                        <?php foreach ($colors as $cMatch): ?>
                            <?php $segPm = $colorChipMeta[$cMatch] ?? ['color' => $cMatch, 'pattern' => '']; ?>
                            <button type="button" class="chip color-chip" data-color="<?php echo htmlspecialchars($cMatch, ENT_QUOTES, 'UTF-8'); ?>" onclick="selectColor(this)">
                                <?php if (($segPm['color'] ?? '') !== ''): ?>
                                <span class="chip-text chip-text--color"><?php echo htmlspecialchars((string) $segPm['color'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if (($segPm['pattern'] ?? '') !== ''): ?>
                                <span class="chip-text chip-text--pattern"><?php echo htmlspecialchars((string) $segPm['pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ((int)$product['has_sizes'] === 1): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('size'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="chips">
                        <?php foreach ($sizes as $sizeKey): ?>
                            <?php $sd = $sizeChipLabel[$sizeKey] ?? $sizeKey; ?>
                            <button type="button" class="chip size-chip" data-size="<?php echo htmlspecialchars($sizeKey, ENT_QUOTES, 'UTF-8'); ?>" onclick="selectSize(this)">
                                <?php echo htmlspecialchars($sd, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="option-block qty-block">
                <label><?php echo htmlspecialchars(t('quantity'), ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="qty-control">
                    <button type="button" onclick="decreaseQty()">-</button>
                    <input type="number" id="qtyInput" value="1" min="1">
                    <button type="button" onclick="increaseQty()">+</button>
                </div>
            </div>

            <?php if ($showSizingGuide): ?>
                <div class="option-block product-info__sizing">
                    <button type="button" class="btn-secondary" id="productSizingOpen" onclick="openProductSizingDialog()">
                        <?php echo htmlspecialchars(t('sizing_guide'), ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                </div>
            <?php endif; ?>

            <div class="actions-row product-info__actions">
                <button type="button" class="btn product-add-cart-btn" onclick="addCurrentProductToCart()" <?php echo ($totalStock <= 0 || $needsVariantPick) ? 'disabled' : ''; ?>>
                    <?php echo htmlspecialchars(t('add_to_cart'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($showSizingGuide): ?>
<dialog id="productSizingDialog" class="product-sizing-dialog<?php echo $advisorySizingReady ? ' product-sizing-dialog--advisory' : ''; ?>">
    <div class="product-sizing-dialog__inner">
        <h3 class="product-sizing-dialog__title"><?php echo htmlspecialchars(t('sizing_guide'), ENT_QUOTES, 'UTF-8'); ?></h3>
        <?php if ($sizingText !== ''): ?>
            <p class="product-sizing-dialog__body"><?php echo htmlspecialchars($sizingText, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($advisorySizingReady): ?>
            <?php
            $advSections = $advisorySizing['sections'] ?? [];
            $advSectionCount = is_array($advSections) ? count($advSections) : 0;
            ?>
            <?php foreach ($advSections as $sec): ?>
                <?php
                $cols = isset($sec['columns']) && is_array($sec['columns']) ? $sec['columns'] : [];
                $srows = isset($sec['rows']) && is_array($sec['rows']) ? $sec['rows'] : [];
                $colCount = max(1, count($cols));
                $secKind = strtolower(trim((string) ($sec['scope_kind'] ?? '')));
                $subKey = '';
                if ($scope === 'both' && $advSectionCount > 1) {
                    if ($secKind === 'upper') {
                        $subKey = 'sizing_guide_section_upper';
                    } elseif ($secKind === 'lower') {
                        $subKey = 'sizing_guide_section_lower';
                    }
                }
                ?>
                <?php if ($subKey !== ''): ?>
                    <h4 class="product-sizing-dialog__subtitle"><?php echo htmlspecialchars(t($subKey), ENT_QUOTES, 'UTF-8'); ?></h4>
                <?php endif; ?>
                <div class="product-sizing-table-panel">
                    <div class="product-sizing-table-wrap" role="region" aria-label="<?php echo htmlspecialchars(t('sizing_guide'), ENT_QUOTES, 'UTF-8'); ?>">
                        <table class="product-sizing-table product-sizing-table--pro">
                            <thead>
                                <tr>
                                    <?php foreach ($cols as $col): ?>
                                        <?php
                                        $vk = strtolower(trim((string) ($col['value_kind'] ?? 'text')));
                                        $thNum = $vk === 'number' ? ' product-sizing-table__th--num' : '';
                                        ?>
                                        <th class="product-sizing-table__th<?php echo $thNum; ?>" scope="col"><?php echo htmlspecialchars((string) ($col['header'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($srows as $srow): ?>
                                    <?php if (($srow['kind'] ?? '') === 'label'): ?>
                                        <tr class="product-sizing-table__label-row">
                                            <td class="product-sizing-table__label-cell" colspan="<?php echo (int) $colCount; ?>"><?php echo htmlspecialchars((string) ($srow['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr class="product-sizing-table__data-row">
                                            <?php
                                            $cells = isset($srow['cells']) && is_array($srow['cells']) ? $srow['cells'] : [];
                                            for ($ci = 0; $ci < $colCount; $ci++):
                                                $cv = $cells[$ci] ?? '';
                                                $colVk = strtolower(trim((string) (($cols[$ci]['value_kind'] ?? 'text')))));
                                                $tdNum = $colVk === 'number' ? ' product-sizing-table__td--num' : '';
                                                ?>
                                                <td class="product-sizing-table__td<?php echo $tdNum; ?>"><?php echo htmlspecialchars((string) $cv, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif ($sizingChartRows !== []): ?>
            <div class="product-sizing-table-panel">
                <div class="product-sizing-table-wrap" role="region" aria-label="<?php echo htmlspecialchars(t('sizing_guide'), ENT_QUOTES, 'UTF-8'); ?>">
                    <table class="product-sizing-table product-sizing-table--pro">
                        <thead>
                            <tr>
                                <th class="product-sizing-table__th" scope="col"><?php echo htmlspecialchars(t('sizing_col_size'), ENT_QUOTES, 'UTF-8'); ?></th>
                                <?php if ($sizingShowFoot): ?>
                                    <th class="product-sizing-table__th product-sizing-table__th--num" scope="col"><?php echo htmlspecialchars(t('sizing_col_foot_cm'), ENT_QUOTES, 'UTF-8'); ?></th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sizingChartRows as $srow): ?>
                                <tr class="product-sizing-table__data-row">
                                    <td class="product-sizing-table__td"><?php echo htmlspecialchars(storefront_size_chart_cell_label($srow), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if ($sizingShowFoot): ?>
                                        <td class="product-sizing-table__td product-sizing-table__td--num"><?php
                                        $fc = $srow['foot_length_cm'] ?? null;
                                        echo $fc !== null && trim((string) $fc) !== ''
                                            ? htmlspecialchars((string) $fc, ENT_QUOTES, 'UTF-8')
                                            : '—';
                                        ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        <form method="dialog">
            <button type="submit" class="btn btn-secondary product-sizing-dialog__close"><?php echo htmlspecialchars(t('sizing_guide_close'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
    </div>
</dialog>
<?php endif; ?>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/product.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
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
    low_stock_threshold: 5,
    colorway_gallery: <?php echo json_encode($colorwayGalleryByChip, JSON_UNESCAPED_UNICODE); ?>,
    default_gallery_urls: <?php echo json_encode($galleryUrls, JSON_UNESCAPED_UNICODE); ?>
};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
