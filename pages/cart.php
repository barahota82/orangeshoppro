<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
$tabBasketLabel = t('cart_tab_basket');
$tabOrdersLabel = t('cart_tab_my_orders');
$checkoutTitle = t('cart_checkout_title');
$checkoutIntro = t('cart_checkout_intro');
$proceedLabel = t('cart_proceed_to_order');
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($cartHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('cart_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="cart-page-tabs" role="tablist" aria-label="<?php echo htmlspecialchars($tabBasketLabel . ' / ' . $tabOrdersLabel, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-basket" aria-selected="true" aria-controls="cart-panel-basket">
            <span class="cart-page-tab__label"><?php echo htmlspecialchars($tabBasketLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="cart-page-tab__count" id="cartTabBasketCount" hidden>0</span>
        </button>
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-orders" aria-selected="false" aria-controls="cart-panel-orders" tabindex="-1">
            <?php echo htmlspecialchars($tabOrdersLabel, ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </div>

    <div id="cart-panel-basket" role="tabpanel" aria-labelledby="cart-tab-basket" class="cart-page-panel cart-page-panel--basket">
        <div class="card-box cart-basket-card">
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
            <div class="card-box cart-checkout-card" id="cartCheckoutCard">
                <h3 class="cart-section-title"><?php echo htmlspecialchars($checkoutTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="cart-checkout-intro"><?php echo htmlspecialchars($checkoutIntro, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="cart-checkout-intro"><?php echo htmlspecialchars(t('storefront_guest_checkout_note'), ENT_QUOTES, 'UTF-8'); ?></p>
                <div id="cartOrderMiniSummary" class="cart-mini-summary" hidden></div>
                <div class="field">
                    <label for="customer_name"><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_name" autocomplete="name" maxlength="500">
                </div>
                <div class="field">
                    <label for="customer_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="22" placeholder="<?php echo htmlspecialchars(t('phone_field_hint'), ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
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
                <button type="button" class="btn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
window.ORANGE_CART_HOME = <?php echo json_encode(storefront_url('home', $channelSlug, $lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
(function () {
    var TAB_KEY = 'orange_cart_ui_tab';
    var tabBasket = document.getElementById('cart-tab-basket');
    var tabOrders = document.getElementById('cart-tab-orders');
    var panelBasket = document.getElementById('cart-panel-basket');
    var panelOrders = document.getElementById('cart-panel-orders');
    if (!tabBasket || !tabOrders || !panelBasket || !panelOrders) return;

    function showTab(which) {
        var isOrders = which === 'orders';
        tabBasket.setAttribute('aria-selected', isOrders ? 'false' : 'true');
        tabOrders.setAttribute('aria-selected', isOrders ? 'true' : 'false');
        tabBasket.tabIndex = isOrders ? -1 : 0;
        tabOrders.tabIndex = isOrders ? 0 : -1;
        panelBasket.hidden = isOrders;
        panelOrders.hidden = !isOrders;
        try {
            sessionStorage.setItem(TAB_KEY, which);
        } catch (e) {}
        if (typeof renderCart === 'function' && !isOrders) {
            renderCart();
        }
    }

    window.orangeCartUiShowTab = showTab;

    tabBasket.addEventListener('click', function () { showTab('basket'); });
    tabOrders.addEventListener('click', function () { showTab('orders'); });

    try {
        var saved = sessionStorage.getItem(TAB_KEY);
        if (saved === 'orders') {
            showTab('orders');
        }
    } catch (e) {}
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
