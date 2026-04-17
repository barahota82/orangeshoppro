<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
$tabBasketLabel = t('cart_tab_basket');
$tabOrdersLabel = t('cart_tab_my_orders');
$myOrdersIntro = t('my_orders_intro');
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($cartHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('cart_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="cart-page-tabs" role="tablist" aria-label="<?php echo htmlspecialchars($tabBasketLabel . ' / ' . $tabOrdersLabel, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-basket" aria-selected="true" aria-controls="cart-panel-basket">
            <?php echo htmlspecialchars($tabBasketLabel, ENT_QUOTES, 'UTF-8'); ?>
        </button>
        <button type="button" class="cart-page-tab" role="tab" id="cart-tab-orders" aria-selected="false" aria-controls="cart-panel-orders" tabindex="-1">
            <?php echo htmlspecialchars($tabOrdersLabel, ENT_QUOTES, 'UTF-8'); ?>
        </button>
    </div>

    <div id="cart-panel-basket" role="tabpanel" aria-labelledby="cart-tab-basket" class="cart-page-panel">
        <div class="cart-layout">
            <div class="card-box">
                <div id="cartItems"></div>
            </div>

            <div class="card-box">
                <div class="field">
                    <label><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_name">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_phone">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="customer_area">
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_address"></textarea>
                </div>
                <div class="field">
                    <label><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="customer_notes"></textarea>
                </div>

                <button class="btn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
    </div>

    <div id="cart-panel-orders" role="tabpanel" aria-labelledby="cart-tab-orders" class="cart-page-panel" hidden>
        <div class="card-box cart-my-orders-box">
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
                <button type="button" class="btn" onclick="cartTrackOrderNow()"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
            <div id="cartTrackResult" class="cart-track-result" style="margin-top:18px;"></div>
        </div>
    </div>
</div>

<script>
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

    tabBasket.addEventListener('click', function () { showTab('basket'); });
    tabOrders.addEventListener('click', function () { showTab('orders'); });

    try {
        var saved = sessionStorage.getItem(TAB_KEY);
        if (saved === 'orders') {
            showTab('orders');
        }
    } catch (e) {}
})();

async function cartTrackOrderNow() {
    var orderNumber = document.getElementById('cart_track_order_number').value.trim();
    var phone = document.getElementById('cart_track_phone').value.trim();
    var resultBox = document.getElementById('cartTrackResult');
    var msgMissing = <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>;

    if (!orderNumber || !phone) {
        resultBox.innerHTML = '<div class="stock-out">' + msgMissing + '</div>';
        return;
    }

    var url = (typeof storefrontApiUrl === 'function')
        ? storefrontApiUrl('/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone))
        : '/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone);

    var response = await fetch(url);
    var result = await response.json();

    if (!result.success) {
        resultBox.innerHTML = '<div class="stock-out">' + (result.message || '—') + '</div>';
        return;
    }

    var lblOrder = <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>;
    var lblPhone = <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>;
    var html = '<div class="track-box">';
    html += '<p><strong>' + lblOrder + ':</strong> ' + String(result.order.order_number) + '</p>';
    html += '<p><strong>Status:</strong> ' + String(result.order.status) + '</p>';
    html += '<p><strong>Total:</strong> ' + String(result.order.total) + ' KD</p>';
    if (result.order.phone) {
        html += '<p><strong>' + lblPhone + ':</strong> ' + String(result.order.phone) + '</p>';
    }
    html += '</div>';
    resultBox.innerHTML = html;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
