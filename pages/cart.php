<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/storefront_account.php';
require_once __DIR__ . '/../includes/delivery_areas.php';
require_once __DIR__ . '/../includes/storefront_phone_country_select.php';

$pdoCartAcc = db();
orange_catalog_ensure_schema($pdoCartAcc);
$cartSfAccount = current_storefront_account($pdoCartAcc);
$cartSfLoggedIn = $cartSfAccount !== null;

include __DIR__ . '/../includes/header.php';
$orangeDeliveryAreasStorefront = orange_delivery_areas_storefront_payload($pdoCartAcc, $lang);
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
$tabBasketLabel = t('cart_tab_basket');
$tabOrdersLabel = t('cart_tab_my_orders');
$tabDeliveredLabel = t('cart_tab_delivered');
$checkoutTitle = t('cart_checkout_title');
$checkoutIntro = t('cart_checkout_intro');
$proceedLabel = t('cart_proceed_to_order');

$orangeOrderStatusLabelsCart = [
    'pending' => t('order_status_pending'),
    'approved' => t('order_status_approved'),
    'on_the_way' => t('order_status_on_the_way'),
    'completed' => t('order_status_completed'),
    'rejected' => t('order_status_rejected'),
    'cancelled' => t('order_status_cancelled'),
];
$orangeMyOrderUiCart = [
    'status_label' => t('order_status_label'),
    'order_total_label' => t('order_total_label'),
    'currency' => t('currency_kd'),
    'cancel' => t('customer_cancel_order'),
    'cancel_confirm' => t('customer_cancel_confirm'),
    'cancel_ok' => t('customer_cancel_ok'),
    'cancel_err' => t('customer_cancel_err'),
    'cancel_not_allowed' => t('customer_cancel_not_allowed'),
    'amend' => t('customer_amend_order'),
    'amend_not_allowed' => t('customer_amend_not_allowed'),
    'whatsapp_help' => t('customer_whatsapp_help'),
    'whatsapp_prefill' => t('whatsapp_order_prefill'),
    'payment_label' => t('order_payment_terms_label'),
    'payment_cash' => t('payment_cash'),
    'payment_credit' => t('payment_credit'),
    'payment_online' => t('payment_online'),
];
$cartTabAria = $cartSfLoggedIn
    ? ($tabBasketLabel . ' / ' . $tabOrdersLabel . ' / ' . $tabDeliveredLabel)
    : ($tabBasketLabel . ' / ' . $tabOrdersLabel);
$cartWaHref = storefront_whatsapp_href($channel, '');
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($cartHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('cart_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="cart-page-tabs" role="tablist" aria-label="<?php echo htmlspecialchars($cartTabAria, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-basket" aria-selected="true" aria-controls="cart-panel-basket">
            <span class="cart-page-tab__label"><?php echo htmlspecialchars($tabBasketLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="cart-page-tab__count" id="cartTabBasketCount" hidden>0</span>
        </button>
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-orders" aria-selected="false" aria-controls="cart-panel-orders" tabindex="-1">
            <?php echo htmlspecialchars($tabOrdersLabel, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <?php if ($cartSfLoggedIn): ?>
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-delivered" aria-selected="false" aria-controls="cart-panel-delivered" tabindex="-1">
            <?php echo htmlspecialchars($tabDeliveredLabel, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <?php endif; ?>
    </div>

    <div id="cart-panel-basket" role="tabpanel" aria-labelledby="cart-tab-basket" class="cart-page-panel cart-page-panel--basket">
        <div class="card-box cart-basket-card">
            <p class="cart-checkout-intro cart-basket-tab-intro"><?php echo htmlspecialchars(t('cart_basket_tab_intro'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div id="cartItems"></div>
            <div class="cart-basket-footer">
                <button type="button" class="btn cart-proceed-btn" id="cartProceedBtn" onclick="orangeCartProceedToCheckout()" disabled>
                    <?php echo htmlspecialchars($proceedLabel, ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </div>
    </div>

    <div id="cart-panel-orders" role="tabpanel" aria-labelledby="cart-tab-orders" class="cart-page-panel cart-page-panel--orders" hidden>
        <div class="cart-orders-stack">
            <p class="cart-checkout-intro cart-orders-tab-intro"><?php echo htmlspecialchars(t('cart_orders_tab_intro'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($cartSfLoggedIn): ?>
            <div class="card-box cart-account-orders-card">
                <h3 class="cart-account-orders-title"><?php echo htmlspecialchars(t('cart_account_orders_heading_active'), ENT_QUOTES, 'UTF-8'); ?></h3>
                <div id="cartAccountOrdersActiveMount" class="cart-account-orders-mount" data-bucket="active"></div>
            </div>
            <?php else: ?>
            <div class="card-box cart-account-orders-card cart-guest-orders-card">
                <h3 class="cart-account-orders-title"><?php echo htmlspecialchars(t('cart_guest_orders_heading'), ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="cart-checkout-intro"><?php echo htmlspecialchars(t('cart_guest_orders_intro'), ENT_QUOTES, 'UTF-8'); ?></p>
                <div id="cartGuestOrdersMount" class="cart-account-orders-mount" data-bucket="guest"></div>
            </div>
            <?php endif; ?>
            <div class="card-box cart-checkout-card" id="cartCheckoutCard">
                <h3 class="cart-section-title"><?php echo htmlspecialchars($checkoutTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p id="cartAmendModeBanner" class="cart-amend-mode-banner" role="status" aria-live="polite" hidden></p>
                <p class="cart-checkout-intro"><?php echo htmlspecialchars($checkoutIntro, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="cart-checkout-intro"><?php echo htmlspecialchars(t('storefront_guest_checkout_note'), ENT_QUOTES, 'UTF-8'); ?></p>
                <div id="cartOrderMiniSummary" class="cart-mini-summary" hidden></div>
                <div class="field">
                    <label for="customer_name"><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_name" autocomplete="name" maxlength="500">
                </div>
                <div class="field">
                    <label for="customer_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <div class="cart-phone-inline">
                        <?php orange_storefront_render_phone_country_select('customer_phone_country'); ?>
                        <input id="customer_phone" class="cart-phone-inline__input js-orange-phone-input" type="tel" autocomplete="tel" inputmode="tel" maxlength="22" placeholder="<?php echo htmlspecialchars(t('phone_field_hint'), ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="field">
                    <label for="customer_email"><?php echo htmlspecialchars(t('customer_email'), ENT_QUOTES, 'UTF-8'); ?> <span class="form-optional-hint">(<?php echo htmlspecialchars(t('field_optional_short'), ENT_QUOTES, 'UTF-8'); ?>)</span></label>
                    <input id="customer_email" type="email" autocomplete="email" maxlength="255" dir="ltr" lang="en">
                </div>
                <div class="field">
                    <label for="customer_area"><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_area" autocomplete="address-level1" maxlength="500">
                </div>
                <div class="field">
                    <label for="customer_address"><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_address" autocomplete="street-address" maxlength="8000"></textarea>
                </div>
                <div class="field">
                    <label for="customer_notes"><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_notes" maxlength="4000"></textarea>
                </div>
                <button type="button" class="btn" id="cartCheckoutSendBtn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
    </div>

    <?php if ($cartSfLoggedIn): ?>
    <div id="cart-panel-delivered" role="tabpanel" aria-labelledby="cart-tab-delivered" class="cart-page-panel cart-page-panel--delivered" hidden>
        <div class="card-box cart-account-orders-card">
            <h3 class="cart-account-orders-title"><?php echo htmlspecialchars(t('cart_account_orders_heading_delivered'), ENT_QUOTES, 'UTF-8'); ?></h3>
            <div id="cartAccountOrdersDeliveredMount" class="cart-account-orders-mount" data-bucket="delivered"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
window.ORANGE_DELIVERY_AREAS = <?php echo json_encode($orangeDeliveryAreasStorefront, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_CART_HOME = <?php echo json_encode(storefront_url('home', $channelSlug, $lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_REGISTER_URL = <?php echo json_encode(storefront_url('register', $channelSlug, $lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_STOREFRONT_WA = <?php echo json_encode($cartWaHref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_CART_SF_ACCOUNT = <?php echo json_encode(['logged_in' => $cartSfLoggedIn], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_ORDER_STATUS_LABELS = <?php echo json_encode($orangeOrderStatusLabelsCart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_MY_ORDER_UI = <?php echo json_encode($orangeMyOrderUiCart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_TRACK_LABELS = window.ORANGE_TRACK_LABELS || {
    order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
    phone: <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>,
    items_title: <?php echo json_encode(t('track_order_items'), JSON_UNESCAPED_UNICODE); ?>,
    track_another_order: <?php echo json_encode(t('track_another_order'), JSON_UNESCAPED_UNICODE); ?>
};
(function () {
    var TAB_KEY = 'orange_cart_ui_tab';
    var tabBasket = document.getElementById('cart-tab-basket');
    var tabOrders = document.getElementById('cart-tab-orders');
    var tabDelivered = document.getElementById('cart-tab-delivered');
    var panelBasket = document.getElementById('cart-panel-basket');
    var panelOrders = document.getElementById('cart-panel-orders');
    var panelDelivered = document.getElementById('cart-panel-delivered');
    if (!tabBasket || !tabOrders || !panelBasket || !panelOrders) return;

    var threeTabs = !!(tabDelivered && panelDelivered);

    function showTab(which) {
        var isBasket = which === 'basket';
        var isOrders = which === 'orders';
        var isDel = threeTabs && which === 'delivered';
        tabBasket.setAttribute('aria-selected', isBasket ? 'true' : 'false');
        tabOrders.setAttribute('aria-selected', isOrders ? 'true' : 'false');
        if (tabDelivered) {
            tabDelivered.setAttribute('aria-selected', isDel ? 'true' : 'false');
        }
        tabBasket.tabIndex = isBasket ? 0 : -1;
        tabOrders.tabIndex = isOrders ? 0 : -1;
        if (tabDelivered) {
            tabDelivered.tabIndex = isDel ? 0 : -1;
        }
        panelBasket.hidden = !isBasket;
        panelOrders.hidden = !isOrders;
        if (panelDelivered) {
            panelDelivered.hidden = !isDel;
        }
        try {
            sessionStorage.setItem(TAB_KEY, which);
        } catch (e) {}
        if (typeof renderCart === 'function' && isBasket) {
            renderCart();
        }
        if (typeof window.orangeCartOnTabShown === 'function') {
            try {
                window.orangeCartOnTabShown(which);
            } catch (e) {}
        }
    }

    window.orangeCartUiShowTab = showTab;

    tabBasket.addEventListener('click', function () { showTab('basket'); });
    tabOrders.addEventListener('click', function () { showTab('orders'); });
    if (tabDelivered) {
        tabDelivered.addEventListener('click', function () { showTab('delivered'); });
    }

    try {
        var saved = sessionStorage.getItem(TAB_KEY);
        if (saved === 'orders') {
            showTab('orders');
        } else if (saved === 'delivered' && threeTabs) {
            showTab('delivered');
        }
    } catch (e) {}
})();

document.addEventListener('DOMContentLoaded', function () {
    if (
        typeof window.orangeReplaceInputWithDeliveryAreaSelect === 'function' &&
        window.ORANGE_DELIVERY_AREAS &&
        window.ORANGE_DELIVERY_AREAS.length
    ) {
        window.orangeReplaceInputWithDeliveryAreaSelect('customer_area', window.ORANGE_DELIVERY_AREAS);
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
