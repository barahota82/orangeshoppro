<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../includes/catalog_labels.php';
require_once __DIR__ . '/../includes/advisory_sizing_guides.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/warehouses.php';
require_once __DIR__ . '/../includes/stock_alerts.php';
require_once __DIR__ . '/../includes/product_preview.php';
require_once __DIR__ . '/../includes/product_offers.php';

$pdo = db();
orange_catalog_ensure_storefront_page($pdo);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sfProductCountryId = orange_storefront_current_country_id($pdo);

/*
 * معاينة المنتج قبل النشر (docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt):
 * إن كانت جلسة معاينة صالحة والمعرّف المطلوب هو صفّ الظِلّ نفسه، نعرضه متجاوزين is_active
 * والحارس الموحّد وحارس الدولة (السياق يأتي من دولة الظِلّ). بقية المنتجات تبقى طبيعية.
 * لا تكلفة على المسار الساخن للعميل: لا استعلام معاينة إلا عند وجود جلسة معاينة فعّالة.
 */
$orangePreviewCtx = orange_preview_active_context($pdo);
$orangeProductPreview = ($orangePreviewCtx !== null && (int) $orangePreviewCtx['draft_id'] === $id && $id > 0);

if ($orangeProductPreview) {
    $product = $orangePreviewCtx['product'];
    if (orange_table_has_column($pdo, 'products', 'country_id') && (int) ($product['country_id'] ?? 0) > 0) {
        $sfProductCountryId = (int) $product['country_id'];
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (
        $product !== false
        && is_array($product)
        && orange_table_has_column($pdo, 'products', 'country_id')
        && (int) ($product['country_id'] ?? 0) !== $sfProductCountryId
    ) {
        $product = false;
    }
    if (
        $product !== false
        && is_array($product)
        && !orange_storefront_product_in_active_unified_chain($pdo, $id)
    ) {
        $product = false;
    }
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

if ($orangeProductPreview) {
    echo '<div class="container"><div class="orange-preview-product-note" role="status">هذه معاينة منتجك غير المحفوظ — يظهر لك وحدك ولا يراه العملاء.</div></div>';
}

$sfProductJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => storefront_product_display_name($product),
    'description' => storefront_product_seo_meta_description($product),
    'sku' => trim((string) ($product['item_code'] ?? '')) ?: (string) $id,
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => orange_storefront_currency_code($pdo),
        'price' => number_format((float) ($product['price'] ?? 0), 3, '.', ''),
        'availability' => 'https://schema.org/InStock',
        'url' => $ORANGE_STOREFRONT_CANONICAL_URL ?? '',
    ],
];
$mainImgLd = trim((string) ($product['main_image'] ?? ''));
if ($mainImgLd !== '') {
    $imgLdPath = storefront_product_image_href($mainImgLd);
    if ($imgLdPath !== '') {
        $sfProductJsonLd['image'] = storefront_absolute_url($imgLdPath);
    }
}

$imagesStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
$imagesStmt->execute([$id]);
$images = $imagesStmt->fetchAll();

$variantsStmt = $pdo->prepare(
    "SELECT v.*,
        cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id,
        sfs.label_ar AS sfs_la, sfs.label_en AS sfs_le,
        sfs.label_fil AS sfs_lf, sfs.label_hi AS sfs_lh,
        sfs.sort_order AS sfs_so
     FROM product_variants v
     LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
     LEFT JOIN size_family_sizes sfs ON sfs.id = v.size_family_size_id
     WHERE v.product_id = ?
     ORDER BY v.id ASC"
);
$variantsStmt->execute([$id]);
$variants = $variantsStmt->fetchAll();
foreach ($variants as $vi => $vRow) {
    if (!is_array($vRow)) {
        continue;
    }
    $vid = (int) ($vRow['id'] ?? 0);
    if ($orangeProductPreview) {
        /* المعاينة: نموذج المنتج الجديد لا يلتقط مخزون المتغيّر (يأتي لاحقاً من أرصدة أول المدة)،
           لذا نعرض صفّ الظِلّ كمتوفّر ليتصفّحه الأدمن كعميل ويختبر الألوان/المقاسات والإضافة للسلة.
           الطلب في وضع المعاينة محاكاة فقط ولا يُرسَل فعلياً. */
        $pvStock = (int) ($vRow['stock_quantity'] ?? 0);
        $variants[$vi]['stock_quantity'] = $pvStock > 0 ? $pvStock : 99;
    } elseif ($vid > 0) {
        $variants[$vi]['stock_quantity'] = orange_warehouse_effective_variant_stock($pdo, $vid, $sfProductCountryId);
    }
}

$colorChipOrder = [];
/** @var array<string, array{color: string, pattern: string}> */
$colorChipMeta = [];
$sizeChipOrder = [];
/** @var array<string, string> */
$sizeChipLabel = [];
/** @var array<string, int> */
$sizeChipSort = [];
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

$sfId = isset($product['size_family_id']) ? (int) $product['size_family_id'] : 0;
$advisorySizing = ['use_dynamic' => false, 'sections' => []];
// سلسلة الأولوية (قرار المالك 2026-06-22): دليل المنتج → دليل نوع المنتج → دليل العائلة العام.
$agProductGuideId = 0;
if (
    $sfId > 0
    && orange_table_exists($pdo, 'products')
    && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')
) {
    $agProductGuideId = orange_advisory_sizing_resolve_guide_id($pdo, $product);
}
if ($sfId > 0 && $agProductGuideId > 0) {
    $advisorySizing = orange_advisory_sizing_build_sections_for_guide_id($pdo, $agProductGuideId, $sfId, $lang);
    $advSecCount = !empty($advisorySizing['use_dynamic']) ? count($advisorySizing['sections'] ?? []) : 0;
    if ($advSecCount > 0) {
        if ($advSecCount > 1) {
            $scope = 'both';
        } else {
            $sk = strtolower(trim((string) ($advisorySizing['sections'][0]['scope_kind'] ?? '')));
            $scope = in_array($sk, ['upper', 'lower', 'single'], true) ? $sk : 'single';
        }
        $sizingHintKey = $sizingHintKeys[$scope] ?? '';
        $sizingText = $sizingHintKey !== '' ? t($sizingHintKey) : '';
    } else {
        $advisorySizing = ['use_dynamic' => false, 'sections' => []];
    }
}

$sizingChartRows = [];
if ($sfId > 0 && orange_table_exists($pdo, 'size_family_sizes')) {
    $cols = 'label_ar, label_en';
    if (
        orange_table_has_column($pdo, 'size_family_sizes', 'label_fil')
        && orange_table_has_column($pdo, 'size_family_sizes', 'label_hi')
    ) {
        $cols .= ', label_fil, label_hi';
    }
    $sst = $pdo->prepare(
        "SELECT {$cols} FROM size_family_sizes
         WHERE size_family_id = ? AND is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    $sst->execute([$sfId]);
    $sizingChartRows = $sst->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$advisorySizingReady = !empty($advisorySizing['use_dynamic']) && ($advisorySizing['sections'] ?? []) !== [];
$advUxHasLength = false;
$advUxSystemsOrdered = [];
$advUxSystemsSeen = [];
if ($advisorySizingReady) {
    foreach (($advisorySizing['sections'] ?? []) as $secUx) {
        foreach (($secUx['columns'] ?? []) as $cUx) {
            if (($cUx['storage_measure'] ?? '') === 'length_cm') {
                $advUxHasLength = true;
            }
            $dsUx = orange_advisory_normalize_display_system((string) ($cUx['display_system'] ?? ''));
            if ($dsUx !== '' && !isset($advUxSystemsSeen[$dsUx])) {
                $advUxSystemsSeen[$dsUx] = true;
                $advUxSystemsOrdered[] = $dsUx;
            }
        }
    }
}
$advUxShowToolbar = $advisorySizingReady && ($advUxHasLength || $advUxSystemsOrdered !== []);
$advUxDefaultSystem = $advUxSystemsOrdered[0] ?? '';
$legacySizingReady = !$advisorySizingReady && $sizingChartRows !== [];
$showSizingGuide = $scope !== 'none' && ($sizingText !== '' || $advisorySizingReady || $legacySizingReady);

$displayName = storefront_product_display_name($product);
$displayDesc = storefront_product_display_description($product);
$homeUrl = storefront_url('home', $channelSlug, $lang);
$needsVariantPick = ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1);
// خصم العرض المفرد النشط (إن وُجد) لعرض «قبل/بعد» على صفحة المنتج — لا يُحسب للمعاينة.
$sfProductOfferDisc = $orangeProductPreview
    ? 0.0
    : orange_product_offer_active_unit_discount($pdo, (int) $product['id'], $sfProductCountryId);
$sfProductCurrencyUnit = orange_storefront_currency_unit($pdo);

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
            /* ترتيب المقاس من جدول المقاسات (size_family_sizes.sort_order)؛ غياب الربط يُبقي ترتيب الظهور. */
            $sizeChipSort[$sk] = isset($v['sfs_so']) && $v['sfs_so'] !== null
                ? (int) $v['sfs_so']
                : (1000000 + count($sizeChipSort));
        }
    }
    $totalStock += (int) $v['stock_quantity'];
}

/* الألوان: ترتيب الإضافة عند تسجيل المنتج (ترتيب إنشاء المتغيّر v.id) — مضبوط مسبقاً عبر ترتيب الاستعلام. */
$colors = $colorChipOrder;
/* المقاسات: ترتيب جدول المقاسات (sort_order) مع ترتيب ثابت عند التساوي. */
$sizes = $sizeChipOrder;
if ($sizes !== [] && $sizeChipSort !== []) {
    $sizeStableIndex = array_flip($sizeChipOrder);
    usort($sizes, static function ($a, $b) use ($sizeChipSort, $sizeStableIndex) {
        $sa = $sizeChipSort[$a] ?? PHP_INT_MAX;
        $sb = $sizeChipSort[$b] ?? PHP_INT_MAX;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return ($sizeStableIndex[$a] ?? 0) <=> ($sizeStableIndex[$b] ?? 0);
    });
}

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

/*
 * احتياط المعرض الافتراضي: عند غياب الصورة العامة وصور المعرض، نستخدم أوّل مجموعة صور لون متاحة
 * (مُحمّلة أصلاً أعلاه — بلا استعلام إضافي) ليفتح المعرض بصورة بدل أن يكون فارغاً.
 */
if ($galleryUrls === [] && $colorwayGalleryByChip !== []) {
    foreach ($colorwayGalleryByChip as $cwChipUrls) {
        if (is_array($cwChipUrls) && $cwChipUrls !== []) {
            $galleryUrls = $cwChipUrls;
            break;
        }
    }
    $galleryCount = count($galleryUrls);
}

$glPrevLabel = htmlspecialchars(t('product_gallery_prev'), ENT_QUOTES, 'UTF-8');
$glNextLabel = htmlspecialchars(t('product_gallery_next'), ENT_QUOTES, 'UTF-8');
$glDotsLabel = htmlspecialchars(t('product_gallery_dots'), ENT_QUOTES, 'UTF-8');
$sfProductJsonLdFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $sfProductJsonLdFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
?>
<script type="application/ld+json"><?php echo json_encode($sfProductJsonLd, $sfProductJsonLdFlags); ?></script>
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
                            <img class="product-gallery__img" src="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>" decoding="async">
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
            <?php
            $sfPpBase = (float) $product['price'];
            $sfPpAfter = max(0.0, $sfPpBase - (float) $sfProductOfferDisc);
            ?>
            <div class="price-row product-info__price">
                <strong id="productPagePrice"><?php echo number_format($sfPpAfter, 2); ?> <?php echo htmlspecialchars($sfProductCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="old-price" id="productPagePriceOld"<?php echo ((float) $sfProductOfferDisc > 0) ? '' : ' hidden'; ?>><?php echo number_format($sfPpBase, 2); ?> <?php echo htmlspecialchars($sfProductCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

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
                    <span class="qty-field">
                        <input type="number" id="qtyInput" value="1" min="1" inputmode="numeric">
                        <select id="qtySelect" class="qty-picker" aria-label="<?php echo htmlspecialchars(t('quantity'), ENT_QUOTES, 'UTF-8'); ?>"></select>
                    </span>
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
            <?php if ($advUxShowToolbar): ?>
                <div class="product-sizing-adv-toolbar" id="productSizingAdvToolbar">
                    <?php if ($advUxSystemsOrdered !== []): ?>
                        <div class="product-sizing-adv-toolbar__field" id="productSizingAdvSysWrap">
                            <label for="productSizingAdvSys"><?php echo htmlspecialchars(t('sizing_adv_toolbar_system'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <select id="productSizingAdvSys" class="product-sizing-adv-toolbar__select">
                                <?php foreach ($advUxSystemsOrdered as $sysOpt): ?>
                                    <option value="<?php echo htmlspecialchars($sysOpt, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sysOpt === $advUxDefaultSystem ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars(orange_advisory_display_system_storefront_label($sysOpt), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php if ($advUxHasLength): ?>
                        <div class="product-sizing-adv-toolbar__field" id="productSizingAdvUnitWrap">
                            <label for="productSizingAdvUnit"><?php echo htmlspecialchars(t('sizing_adv_toolbar_unit'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <select id="productSizingAdvUnit" class="product-sizing-adv-toolbar__select">
                                <option value="cm"><?php echo htmlspecialchars(t('sizing_unit_cm_short'), ENT_QUOTES, 'UTF-8'); ?></option>
                                <option value="inch"><?php echo htmlspecialchars(t('sizing_unit_inch_short'), ENT_QUOTES, 'UTF-8'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php $advUseTabs = ($advSectionCount > 1); ?>
            <?php if ($advUseTabs): ?>
                <div class="product-sizing-tabs" id="productSizingTabs" role="tablist">
                    <?php foreach ($advSections as $tabIdx => $tabSec): ?>
                        <?php
                        $tk = strtolower(trim((string) ($tabSec['scope_kind'] ?? '')));
                        $tabKey = $tk === 'lower' ? 'sizing_guide_section_lower' : ($tk === 'upper' ? 'sizing_guide_section_upper' : '');
                        $tabLabel = $tabKey !== '' ? t($tabKey) : ('#' . ((int) $tabIdx + 1));
                        ?>
                        <button type="button" class="product-sizing-tabs__btn<?php echo $tabIdx === 0 ? ' is-active' : ''; ?>"
                            role="tab" data-adv-tab="<?php echo (int) $tabIdx; ?>"
                            aria-selected="<?php echo $tabIdx === 0 ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($advSections as $secTableIdx => $sec): ?>
                <?php
                $cols = isset($sec['columns']) && is_array($sec['columns']) ? $sec['columns'] : [];
                $srows = isset($sec['rows']) && is_array($sec['rows']) ? $sec['rows'] : [];
                $colCount = max(1, count($cols));
                $secKind = strtolower(trim((string) ($sec['scope_kind'] ?? '')));
                $subKey = '';
                if (!$advUseTabs && $scope === 'both' && $advSectionCount > 1) {
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
                <div class="product-sizing-table-panel<?php echo $advUseTabs ? ' product-sizing-adv-panel' . ($secTableIdx === 0 ? ' is-active' : '') : ''; ?>"<?php echo $advUseTabs ? ' data-adv-panel="' . (int) $secTableIdx . '"' : ''; ?>>
                    <div class="product-sizing-table-wrap" role="region" aria-label="<?php echo htmlspecialchars(t('sizing_guide'), ENT_QUOTES, 'UTF-8'); ?>">
                        <table class="product-sizing-table product-sizing-table--pro" data-adv-table="<?php echo (int) $secTableIdx; ?>">
                            <thead>
                                <tr>
                                    <?php foreach ($cols as $ci => $col): ?>
                                        <?php
                                        $vk = strtolower(trim((string) ($col['value_kind'] ?? 'text')));
                                        $smCol = (string) ($col['storage_measure'] ?? '');
                                        $dsCol = orange_advisory_normalize_display_system((string) ($col['display_system'] ?? ''));
                                        $thNum = ($vk === 'number' || $smCol === 'length_cm') ? ' product-sizing-table__th--num' : '';
                                        ?>
                                        <th class="product-sizing-table__th<?php echo $thNum; ?>"
                                            scope="col"
                                            data-adv-col="<?php echo (int) $ci; ?>"
                                            data-adv-dsys="<?php echo htmlspecialchars($dsCol, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($col['header'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></th>
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
                                                $colMeta = $cols[$ci] ?? [];
                                                $colVk = strtolower(trim((string) ($colMeta['value_kind'] ?? 'text')));
                                                $sm = (string) ($colMeta['storage_measure'] ?? '');
                                                $ds = orange_advisory_normalize_display_system((string) ($colMeta['display_system'] ?? ''));
                                                $cmSpan = $sm === 'length_cm' ? orange_advisory_parse_stored_cm_span((string) $cv) : null;
                                                $tdNum = ($colVk === 'number' || $sm === 'length_cm') ? ' product-sizing-table__td--num' : '';
                                                $dispCell = (string) $cv;
                                                $cmAttrSingle = '';
                                                $cmAttrLo = '';
                                                $cmAttrHi = '';
                                                if ($cmSpan !== null) {
                                                    [$cmLo, $cmHi] = $cmSpan;
                                                    $dispCell = orange_advisory_format_cm_measure($cmLo, $cmHi) . ' ' . t('sizing_unit_cm_short');
                                                    if (abs($cmLo - $cmHi) < 1e-9) {
                                                        $cmAttrSingle = (string) $cmLo;
                                                    } else {
                                                        $cmAttrLo = (string) $cmLo;
                                                        $cmAttrHi = (string) $cmHi;
                                                    }
                                                }
                                                ?>
                                                <td class="product-sizing-table__td<?php echo $tdNum; ?>"
                                                    data-adv-col="<?php echo (int) $ci; ?>"
                                                    data-adv-dsys="<?php echo htmlspecialchars($ds, ENT_QUOTES, 'UTF-8'); ?>"
                                                    <?php if ($cmAttrSingle !== ''): ?> data-adv-cm="<?php echo htmlspecialchars($cmAttrSingle, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                                                    <?php if ($cmAttrLo !== '' && $cmAttrHi !== ''): ?> data-adv-cm-lo="<?php echo htmlspecialchars($cmAttrLo, ENT_QUOTES, 'UTF-8'); ?>" data-adv-cm-hi="<?php echo htmlspecialchars($cmAttrHi, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>><?php echo htmlspecialchars($dispCell, ENT_QUOTES, 'UTF-8'); ?></td>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sizingChartRows as $srow): ?>
                                <tr class="product-sizing-table__data-row">
                                    <td class="product-sizing-table__td"><?php echo htmlspecialchars(storefront_size_chart_cell_label($srow), ENT_QUOTES, 'UTF-8'); ?></td>
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
    <?php if ($advisorySizingReady && $advUxShowToolbar): ?>
<script>
window.ORANGE_ADVISORY_UX = {
    hasLength: <?php echo $advUxHasLength ? 'true' : 'false'; ?>,
    hasSystems: <?php echo count($advUxSystemsOrdered) > 0 ? 'true' : 'false'; ?>,
    systems: <?php echo json_encode($advUxSystemsOrdered, JSON_UNESCAPED_UNICODE); ?>,
    defaultSystem: <?php echo json_encode($advUxDefaultSystem, JSON_UNESCAPED_UNICODE); ?>,
    labelCm: <?php echo json_encode(t('sizing_unit_cm_short'), JSON_UNESCAPED_UNICODE); ?>,
    labelInch: <?php echo json_encode(t('sizing_unit_inch_short'), JSON_UNESCAPED_UNICODE); ?>
};
</script>
    <?php endif; ?>
    <?php if (!empty($advUseTabs)): ?>
<script>
(function () {
    var tabs = document.getElementById('productSizingTabs');
    if (!tabs) { return; }
    var dialog = document.getElementById('productSizingDialog');
    if (!dialog) { return; }
    var btns = tabs.querySelectorAll('.product-sizing-tabs__btn');
    var panels = dialog.querySelectorAll('.product-sizing-adv-panel');
    tabs.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.product-sizing-tabs__btn');
        if (!btn) { return; }
        var idx = btn.getAttribute('data-adv-tab');
        btns.forEach(function (b) {
            var on = b === btn;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panels.forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-adv-panel') === idx);
        });
    });
})();
</script>
    <?php endif; ?>
<?php endif; ?>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/product.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script>
window.CURRENT_PRODUCT = {
    id: <?php echo (int)$product['id']; ?>,
    name: <?php echo json_encode($displayName, JSON_UNESCAPED_UNICODE); ?>,
    price: <?php echo json_encode((float)$product['price']); ?>,
    offer_discount: <?php echo json_encode((float) $sfProductOfferDisc); ?>,
    currency_unit: <?php echo json_encode($sfProductCurrencyUnit, JSON_UNESCAPED_UNICODE); ?>,
    image: <?php echo json_encode($product['main_image'], JSON_UNESCAPED_UNICODE); ?>,
    has_colors: <?php echo (int)$product['has_colors']; ?>,
    has_sizes: <?php echo (int)$product['has_sizes']; ?>,
    sizing_guide_scope: <?php echo json_encode($scope, JSON_UNESCAPED_UNICODE); ?>,
    variants: <?php echo json_encode($variants, JSON_UNESCAPED_UNICODE); ?>,
    total_stock_sum: <?php echo (int)$totalStock; ?>,
    low_stock_threshold: <?php echo (int) orange_storefront_low_stock_display_threshold($pdo, $sfProductCountryId); ?>,
    colorway_gallery: <?php echo json_encode($colorwayGalleryByChip, JSON_UNESCAPED_UNICODE); ?>,
    default_gallery_urls: <?php echo json_encode($galleryUrls, JSON_UNESCAPED_UNICODE); ?>
};
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
