<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
$tabBasketLabel = t('cart_tab_basket');
$tabOrdersLabel = t('cart_tab_my_orders');
$myOrdersIntro = t('my_orders_intro');
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
                <div id="cartOrderMiniSummary" class="cart-mini-summary" hidden></div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_name" autocomplete="name">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_phone" autocomplete="tel">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_area" autocomplete="address-level1">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_address" autocomplete="street-address"></textarea>
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_notes"></textarea>
                </div>
                <p class="cart-checkout-cash-note"><?php echo htmlspecialchars(t('checkout_online_cash_only'), ENT_QUOTES, 'UTF-8'); ?></p>
                <button type="button" class="btn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>

            <div class="card-box cart-track-card cart-my-orders-box">
                <h3 class="cart-section-title"><?php echo htmlspecialchars($trackSectionTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="my-orders-intro"><?php echo htmlspecialchars($myOrdersIntro, ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="form-grid">
                    <div>
                        <label><?php echo htmlspecialchars(t('order_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="cart_track_order_number" autocomplete="off">
                    </div>
                    <div>
                        <label><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="cart_track_phone" autocomplete="tel">
                    </div>
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

function orangeBuildCustomerWaUrl(prefillText) {
    var base = window.ORANGE_STOREFRONT_WA;
    if (!base || typeof base !== 'string') {
        return null;
    }
    var q = base.indexOf('?');
    var path = q >= 0 ? base.substring(0, q) : base;
    return path + '?text=' + encodeURIComponent(prefillText);
}

function cartRenderTrackedOrder(resultBox, order, orderNumber, phone) {
    var UI = window.ORANGE_MY_ORDER_UI || {};
    var labels = window.ORANGE_ORDER_STATUS_LABELS || {};
    var st = String(order.status || '').toLowerCase().trim();
    var statusText = labels[st] || order.status || '—';
    var lblOrder = <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>;
    var lblPhone = <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>;

    var canCancel = st === 'pending' || st === 'approved';
    var prefill = String(UI.whatsapp_prefill || '').replace(/\{order\}/g, String(order.order_number || orderNumber));
    var waUrl = orangeBuildCustomerWaUrl(prefill);

    window.__orangeCartTrack = { orderNumber: orderNumber, phone: phone };

    var html = '<div class="track-box track-box--order">';
    html += '<p class="order-status-row"><strong>' + escCartHtmlMy(UI.status_label || '') + ':</strong> ';
    html += '<span class="order-status-pill order-status-pill--' + escCartHtmlMy(st) + '">' + escCartHtmlMy(statusText) + '</span></p>';
    html += '<p><strong>' + escCartHtmlMy(lblOrder) + ':</strong> ' + escCartHtmlMy(String(order.order_number || '')) + '</p>';
    if (order.phone) {
        html += '<p><strong>' + escCartHtmlMy(lblPhone) + ':</strong> ' + escCartHtmlMy(String(order.phone)) + '</p>';
    }
    html += '<p><strong>' + escCartHtmlMy(UI.order_total_label || '') + ':</strong> ' + escCartHtmlMy(String(order.total)) + ' ' + escCartHtmlMy(UI.currency || 'KD') + '</p>';
    var pt = String(order.payment_terms || 'cash').toLowerCase();
    var ptLabel = pt === 'credit' ? (UI.payment_credit || '') : (UI.payment_cash || '');
    if (UI.payment_label && ptLabel) {
        html += '<p><strong>' + escCartHtmlMy(UI.payment_label) + ':</strong> ' + escCartHtmlMy(ptLabel) + '</p>';
    }
    html += '<div class="customer-order-actions">';

    html += '<button type="button" class="btn btn-danger customer-order-cancel"';
    if (!canCancel) {
        html += ' disabled title="' + escAttrMy(UI.cancel_not_allowed || '') + '"';
    }
    html += ' onclick="cartCustomerCancelOrder()">' + escCartHtmlMy(UI.cancel || '') + '</button>';

    if (waUrl) {
        html += '<a class="btn btn-secondary customer-order-wa" href="' + escAttrMy(waUrl) + '" target="_blank" rel="noopener noreferrer">';
        html += escCartHtmlMy(UI.whatsapp_help || 'WhatsApp') + '</a>';
    }

    html += '</div>';
    if (!canCancel && st !== 'cancelled' && st !== 'rejected') {
        html += '<p class="cart-cancel-hint">' + escCartHtmlMy(UI.cancel_not_allowed || '') + '</p>';
    }
    html += '</div>';
    resultBox.innerHTML = html;
}

function escCartHtmlMy(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function escAttrMy(s) {
    return escCartHtmlMy(s).replace(/'/g, '&#39;');
}

async function cartTrackOrderNow() {
    var orderNumber = document.getElementById('cart_track_order_number').value.trim();
    var phone = document.getElementById('cart_track_phone').value.trim();
    var resultBox = document.getElementById('cartTrackResult');
    var msgMissing = <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>;

    if (!orderNumber || !phone) {
        resultBox.innerHTML = '<div class="stock-out">' + msgMissing + '</div>';
        window.__orangeCartTrack = null;
        return;
    }

    var url = (typeof storefrontApiUrl === 'function')
        ? storefrontApiUrl('/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone))
        : '/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone);

    var response = await fetch(url);
    var result = await response.json();

    if (!result.success) {
        resultBox.innerHTML = '<div class="stock-out">' + (result.message || '—') + '</div>';
        window.__orangeCartTrack = null;
        return;
    }

    cartRenderTrackedOrder(resultBox, result.order, orderNumber, phone);
}

async function cartCustomerCancelOrder() {
    var ctx = window.__orangeCartTrack;
    var UI = window.ORANGE_MY_ORDER_UI || {};
    if (!ctx || !ctx.orderNumber || !ctx.phone) {
        return;
    }
    if (!confirm(UI.cancel_confirm || '')) {
        return;
    }
    var api = (typeof storefrontApiUrl === 'function')
        ? storefrontApiUrl('/api/orders/cancel-by-customer.php')
        : '/api/orders/cancel-by-customer.php';
    try {
        var res = await fetch(api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_number: ctx.orderNumber, phone: ctx.phone }),
        });
        var data = await res.json();
        if (data.success) {
            alert(UI.cancel_ok || '');
            await cartTrackOrderNow();
            return;
        }
        var code = data.code || '';
        if (code === 'cancel_not_allowed') {
            alert(UI.cancel_not_allowed || UI.cancel_err || '');
        } else {
            alert(UI.cancel_err || '');
        }
    } catch (e) {
        alert(UI.cancel_err || '');
    }
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
