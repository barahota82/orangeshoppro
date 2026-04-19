<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
$tabBasketLabel = t('cart_tab_basket');
$tabOrdersLabel = t('cart_tab_my_orders');
$checkoutTitle = t('cart_checkout_title');
$checkoutIntro = t('cart_checkout_intro');
$trackSectionTitle = t('cart_track_section_title');
$proceedLabel = t('cart_proceed_to_order');
$waHref = storefront_whatsapp_href($channel, '');
$orangeOrderStatusLabels = [
    'pending' => t('order_status_pending'),
    'approved' => t('order_status_approved'),
    'on_the_way' => t('order_status_on_the_way'),
    'completed' => t('order_status_completed'),
    'rejected' => t('order_status_rejected'),
    'cancelled' => t('order_status_cancelled'),
];
$orangeMyOrderUi = [
    'status_label' => t('order_status_label'),
    'order_total_label' => t('order_total_label'),
    'currency' => t('currency_kd'),
    'cancel' => t('customer_cancel_order'),
    'cancel_confirm' => t('customer_cancel_confirm'),
    'cancel_ok' => t('customer_cancel_ok'),
    'cancel_err' => t('customer_cancel_err'),
    'cancel_not_allowed' => t('customer_cancel_not_allowed'),
    'whatsapp_help' => t('customer_whatsapp_help'),
    'whatsapp_prefill' => t('whatsapp_order_prefill'),
    'payment_label' => t('order_payment_terms_label'),
    'payment_cash' => t('payment_cash'),
    'payment_credit' => t('payment_credit'),
    'payment_online' => t('payment_online'),
];
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
                    <input id="customer_name" autocomplete="name">
                </div>
                <div class="field">
                    <label for="customer_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_phone" autocomplete="tel">
                </div>
                <div class="field">
                    <label for="customer_email"><?php echo htmlspecialchars(t('customer_email'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_email" type="email" autocomplete="email" inputmode="email">
                </div>
                <div class="field">
                    <label for="customer_area"><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_area" autocomplete="address-level1">
                </div>
                <div class="field">
                    <label for="customer_address"><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_address" autocomplete="street-address"></textarea>
                </div>
                <div class="field">
                    <label for="customer_notes"><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_notes"></textarea>
                </div>
                <div class="field cart-checkout-payment-radios" role="group" aria-labelledby="cart_pay_method_legend">
                    <span id="cart_pay_method_legend" class="cart-payment-legend"><?php echo htmlspecialchars(t('payment_terms_label'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="cart-pay-opts">
                        <label class="cart-pay-opt">
                            <input type="radio" name="checkout_payment_terms" value="cash" checked>
                            <?php echo htmlspecialchars(t('payment_cash'), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <label class="cart-pay-opt">
                            <input type="radio" name="checkout_payment_terms" value="online">
                            <?php echo htmlspecialchars(t('payment_online'), ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                    </div>
                </div>
                <p class="cart-checkout-cash-note"><?php echo htmlspecialchars(t('checkout_online_cash_only'), ENT_QUOTES, 'UTF-8'); ?></p>
                <button type="button" class="btn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>

            <div class="card-box cart-track-card cart-my-orders-box">
                <h3 class="cart-section-title"><?php echo htmlspecialchars($trackSectionTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="track-form-intro"><?php echo htmlspecialchars(t('track_order_howto'), ENT_QUOTES, 'UTF-8'); ?></p>
                <hr class="track-form-divider" aria-hidden="true">
                <div class="field">
                    <label for="cart_track_order_number"><?php echo htmlspecialchars(t('order_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="cart_track_order_number" name="cart_track_order_number" autocomplete="off" inputmode="text">
                </div>
                <div class="field">
                    <label for="cart_track_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="cart_track_phone" name="cart_track_phone" autocomplete="tel" inputmode="tel">
                </div>
                <div class="actions-row" style="margin-top:14px;">
                    <button type="button" class="btn btn-secondary" onclick="cartTrackOrderNow()"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
                <div id="cartTrackResult" class="cart-track-result" style="margin-top:18px;"></div>
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

window.ORANGE_STOREFRONT_WA = <?php echo json_encode($waHref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_ORDER_STATUS_LABELS = <?php echo json_encode($orangeOrderStatusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_MY_ORDER_UI = <?php echo json_encode($orangeMyOrderUi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_TRACK_LABELS = {
    order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
    phone: <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>,
    items_title: <?php echo json_encode(t('track_order_items'), JSON_UNESCAPED_UNICODE); ?>
};

async function cartTrackOrderNow() {
    var msgMissing = <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>;
    var msgNotFound = <?php echo json_encode(t('track_order_not_found'), JSON_UNESCAPED_UNICODE); ?>;
    await orangeTrackOrderFetchAndRender(
        document.getElementById('cartTrackResult'),
        document.getElementById('cart_track_order_number').value.trim(),
        document.getElementById('cart_track_phone').value.trim(),
        msgMissing,
        msgNotFound
    );
}
window.__orangeCartTrackRefresh = cartTrackOrderNow;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
