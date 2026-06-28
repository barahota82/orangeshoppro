<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/warehouses.php';
require_once __DIR__ . '/../includes/catalog_labels.php';
require_once __DIR__ . '/../includes/storefront_account.php';
require_once __DIR__ . '/../includes/storefront_offer_cards.php';
require_once __DIR__ . '/../includes/storefront_variant_picker.php';

$pdo = db();
orange_catalog_ensure_storefront_page($pdo);

$tbState = storefront_toolbar_state();
$channelSlug = $tbState['channelSlug'];
$lang = $tbState['lang'];
$sfCountryId = orange_storefront_current_country_id($pdo);
$sfCurrencyUnit = orange_storefront_currency_unit($pdo, $sfCountryId);

$offerType = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : 'combo';
$offerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isCombo = $offerType === 'combo';
$isBogo = $offerType === 'bogo';

$homeUrl = storefront_url('home', $channelSlug, $lang);
$cartUrl = storefront_url('cart', $channelSlug, $lang);

/**
 * سعر وحدة هدية BOGO من سعر التجزئة وقاعدة التسعير (يطابق منطق
 * orange_cart_promo_resolve_gift_unit_price_from_rule لكن من سعر معروف مسبقاً).
 */
$offerGiftChargePrice = static function (string $kind, float $value, float $retail): float {
    switch ($kind) {
        case 'free':
            return 0.0;
        case 'fixed_unit':
            return max(0.0, round($value, 4));
        case 'percent_off':
            $pct = min(100.0, max(0.0, $value));
            return max(0.0, round($retail * (1.0 - $pct / 100.0), 4));
        case 'amount_off_unit':
            return max(0.0, round($retail - max(0.0, $value), 4));
        default:
            return 0.0;
    }
};

// جلب العرض: كومبو، أو BOGO من نوع «حزمة شراء» فقط في هذه الصفحة (الأنواع الأخرى لاحقاً).
$card = null;
if ($offerId > 0 && $isCombo) {
    foreach (orange_storefront_active_combo_cards($pdo, $sfCountryId, $lang) as $c) {
        if ((int) $c['offer_id'] === $offerId) {
            $card = $c;
            break;
        }
    }
} elseif ($offerId > 0 && $isBogo) {
    foreach (orange_storefront_active_bogo_cards($pdo, $sfCountryId, $lang) as $c) {
        if ((int) $c['offer_id'] === $offerId && (string) ($c['bogo_kind'] ?? '') === 'buy_bundle') {
            $card = $c;
            break;
        }
    }
}

if ($card === null) {
    $ORANGE_STOREFRONT_PAGE_TITLE = t('offer_not_found') . ' | ' . t('storefront_brand');
    $ORANGE_STOREFRONT_META_DESCRIPTION = '';
    include __DIR__ . '/../includes/header.php';
    ?>
<div class="container">
    <nav class="product-page-toolbar product-page-toolbar--dual">
        <a class="product-page__back" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="product-page__close" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </nav>
    <div class="card-box product-page product-page--empty">
        <p class="product-page__empty-msg"><?php echo htmlspecialchars(t('offer_not_found'), ENT_QUOTES, 'UTF-8'); ?></p>
        <a class="btn" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// مكوّنات الشراء (الكومبو: components، BOGO: buy_components) → بناء منتقي لكل منها.
$rawComponents = $isCombo ? ($card['components'] ?? []) : ($card['buy_components'] ?? []);
$components = [];
$componentsTotal = 0.0;
foreach ($rawComponents as $comp) {
    $pid = (int) ($comp['product_id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $view = orange_storefront_product_variant_view($pdo, $pid, $sfCountryId, $lang);
    $rawImg = (string) ($comp['main_image'] ?? '');
    $qty = max(1, (int) ($comp['qty'] ?? 1));
    $price = (float) ($comp['price'] ?? $view['price']);
    $componentsTotal += $price * $qty;
    $components[] = [
        'product_id' => $pid,
        'qty' => $qty,
        'name' => $view['name'] !== '' ? $view['name'] : (string) ($comp['name'] ?? ''),
        'price' => $price,
        'image_display' => $rawImg !== '' ? storefront_product_image_href($rawImg) : $view['main_image'],
        'image_cart' => $rawImg,
        'has_colors' => (int) $view['has_colors'],
        'has_sizes' => (int) $view['has_sizes'],
        'total_stock' => (int) $view['total_stock'],
        'colors' => $view['colors'],
        'sizes' => $view['sizes'],
        'variants' => $view['variants'],
    ];
}
$componentsTotal = round($componentsTotal, 4);

$offerName = (string) ($card['name'] ?? '');
$requiresRegistration = !empty($card['requires_registration']);
$buyerRegistered = current_storefront_account($pdo) !== null;
$showRegisterTeaser = $requiresRegistration && !$buyerRegistered;

// نموذج السعر: الكومبو سعر حزمة مخفّض؛ BOGO إجمالي الشراء (الفائدة هي الهدية).
if ($isCombo) {
    $bundlePrice = (float) ($card['bundle_price'] ?? 0);
    $showOldPrice = !empty($card['show_old_price']) && $componentsTotal > $bundlePrice + 1e-6;
    $priceLabel = t('offer_price_label');
} else {
    $bundlePrice = $componentsTotal;
    $showOldPrice = false;
    $priceLabel = t('offer_buy_total');
}

// هدية BOGO (عرض ساكن فقط؛ تُحقن تلقائياً على الخادم — لا تُضاف للسلة هنا).
$gift = null;
if ($isBogo) {
    $giftKind = (string) ($card['gift_kind'] ?? 'choice');
    $chargeKind = (string) ($card['gift_unit_charge_kind'] ?? 'free');
    $chargeVal = (float) ($card['gift_unit_charge_value'] ?? 0);
    if ($giftKind === 'fixed' && !empty($card['fixed_gift'])) {
        $fg = $card['fixed_gift'];
        $retail = (float) ($fg['price'] ?? 0);
        $chargePrice = $offerGiftChargePrice($chargeKind, $chargeVal, $retail);
        $gift = [
            'kind' => 'fixed',
            'name' => (string) ($fg['name'] ?? ''),
            'image' => ($fg['main_image'] ?? '') !== '' ? storefront_product_image_href((string) $fg['main_image']) : '',
            'retail' => $retail,
            'charge' => $chargePrice,
            'free' => $chargePrice <= 1e-6,
            'show_old' => !empty($card['show_old_price']) && $retail > $chargePrice + 1e-6,
        ];
    } else {
        $pool = [];
        foreach (($card['gift_pool'] ?? []) as $pg) {
            $pool[] = [
                'name' => (string) ($pg['name'] ?? ''),
                'image' => ($pg['main_image'] ?? '') !== '' ? storefront_product_image_href((string) $pg['main_image']) : '',
            ];
        }
        $gift = ['kind' => 'choice', 'pool' => $pool];
    }
}

$ORANGE_STOREFRONT_PAGE_TITLE = ($offerName !== '' ? $offerName : t('offers')) . ' | ' . t('storefront_brand');
$ORANGE_STOREFRONT_META_DESCRIPTION = '';

include __DIR__ . '/../includes/header.php';

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
?>
<div class="container offer-page" data-offer-id="<?php echo (int) $card['offer_id']; ?>" data-offer-type="<?php echo htmlspecialchars($offerType, ENT_QUOTES, 'UTF-8'); ?>">
    <nav class="product-page-toolbar product-page-toolbar--dual">
        <a class="product-page__back" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?></a>
        <a class="product-page__close" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </nav>

    <div class="offer-head">
        <?php if ($offerName !== ''): ?>
        <h1 class="offer-head__title"><?php echo htmlspecialchars($offerName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php endif; ?>
        <p class="offer-head__includes"><?php echo htmlspecialchars(t('offer_includes'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($showRegisterTeaser): ?>
        <p class="offer-head__teaser" role="status"><?php echo htmlspecialchars(t('offer_registered_only'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="offer-components">
        <?php foreach ($components as $ci => $comp): ?>
        <div class="offer-component" data-comp-index="<?php echo (int) $ci; ?>">
            <div class="offer-component__media">
                <?php if ($comp['image_display'] !== ''): ?>
                <img src="<?php echo htmlspecialchars((string) $comp['image_display'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $comp['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                <?php endif; ?>
            </div>
            <div class="offer-component__body">
                <h3 class="offer-component__name">
                    <?php echo htmlspecialchars((string) $comp['name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ((int) $comp['qty'] > 1): ?>
                    <span class="offer-component__qty">&times;<?php echo (int) $comp['qty']; ?></span>
                    <?php endif; ?>
                </h3>

                <?php if ((int) $comp['has_colors'] === 1 && $comp['colors'] !== []): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('color'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="chips">
                        <?php foreach ($comp['colors'] as $col): ?>
                        <button type="button" class="chip color-chip" data-color="<?php echo htmlspecialchars((string) $col['key'], ENT_QUOTES, 'UTF-8'); ?>" onclick="offerSelectColor(<?php echo (int) $ci; ?>, this)">
                            <?php if (($col['color'] ?? '') !== ''): ?>
                            <span class="chip-text chip-text--color"><?php echo htmlspecialchars((string) $col['color'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                            <?php if (($col['pattern'] ?? '') !== ''): ?>
                            <span class="chip-text chip-text--pattern"><?php echo htmlspecialchars((string) $col['pattern'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ((int) $comp['has_sizes'] === 1 && $comp['sizes'] !== []): ?>
                <div class="option-block">
                    <label><?php echo htmlspecialchars(t('size'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="chips">
                        <?php foreach ($comp['sizes'] as $sz): ?>
                        <button type="button" class="chip size-chip" data-size="<?php echo htmlspecialchars((string) $sz['key'], ENT_QUOTES, 'UTF-8'); ?>" onclick="offerSelectSize(<?php echo (int) $ci; ?>, this)">
                            <?php echo htmlspecialchars((string) $sz['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ((int) $comp['total_stock'] <= 0): ?>
                <div class="stock-out"><?php echo htmlspecialchars(t('out_of_stock'), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($gift !== null): ?>
    <div class="offer-gift">
        <h2 class="offer-gift__title"><?php echo htmlspecialchars(t('offer_your_gift'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if ($gift['kind'] === 'fixed'): ?>
        <div class="offer-gift__item">
            <div class="offer-gift__media">
                <?php if ($gift['image'] !== ''): ?>
                <img src="<?php echo htmlspecialchars((string) $gift['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $gift['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                <?php endif; ?>
            </div>
            <div class="offer-gift__body">
                <h3 class="offer-gift__name"><?php echo htmlspecialchars((string) $gift['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="price-row">
                    <?php if (!empty($gift['free'])): ?>
                    <strong class="offer-gift__free"><?php echo htmlspecialchars(t('offer_gift_free'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php else: ?>
                    <strong><?php echo number_format((float) $gift['charge'], 2); ?> <?php echo htmlspecialchars($sfCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($gift['show_old'])): ?>
                    <span class="old-price"><?php echo number_format((float) $gift['retail'], 2); ?> <?php echo htmlspecialchars($sfCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <p class="offer-gift__choose-note"><?php echo htmlspecialchars(t('offer_gift_choose_in_cart'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if (($gift['pool'] ?? []) !== []): ?>
        <div class="offer-gift__pool">
            <?php foreach ($gift['pool'] as $pg): ?>
            <div class="offer-gift__pool-item">
                <?php if (($pg['image'] ?? '') !== ''): ?>
                <img src="<?php echo htmlspecialchars((string) $pg['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $pg['name'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                <?php endif; ?>
                <span><?php echo htmlspecialchars((string) $pg['name'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="offer-sticky-bar" role="region" aria-label="<?php echo htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="offer-sticky-bar__inner">
        <div class="offer-sticky-bar__price">
            <span class="offer-sticky-bar__label"><?php echo htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <strong id="offerBundlePrice"><?php echo number_format($bundlePrice, 2); ?> <?php echo htmlspecialchars($sfCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php if ($showOldPrice): ?>
            <span class="old-price" id="offerOldPrice"><?php echo number_format($componentsTotal, 2); ?> <?php echo htmlspecialchars($sfCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
        <div class="offer-sticky-bar__qty qty-control">
            <button type="button" onclick="offerDecQty()" aria-label="-">-</button>
            <input type="number" id="offerQty" value="1" min="1" inputmode="numeric">
            <button type="button" onclick="offerIncQty()" aria-label="+">+</button>
        </div>
        <button type="button" class="btn offer-add-btn" id="offerAddBtn" onclick="addOfferToCart()" disabled>
            <?php echo htmlspecialchars(t('offer_add_to_cart'), ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </div>
</div>

<script>
window.ORANGE_OFFER = {
    type: <?php echo json_encode($offerType, $jsonFlags); ?>,
    offer_id: <?php echo (int) $card['offer_id']; ?>,
    name: <?php echo json_encode($offerName, $jsonFlags); ?>,
    bundle_price: <?php echo json_encode($bundlePrice); ?>,
    components_total: <?php echo json_encode($componentsTotal); ?>,
    show_old_price: <?php echo $showOldPrice ? 'true' : 'false'; ?>,
    requires_registration: <?php echo $requiresRegistration ? 'true' : 'false'; ?>,
    currency_unit: <?php echo json_encode($sfCurrencyUnit, $jsonFlags); ?>,
    cart_url: <?php echo json_encode($cartUrl, $jsonFlags); ?>,
    components: <?php echo json_encode($components, $jsonFlags); ?>,
    t: {
        pick_required: <?php echo json_encode(t('offer_pick_required'), $jsonFlags); ?>,
        added: <?php echo json_encode(t('offer_added'), $jsonFlags); ?>
    }
};
</script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/offer.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
