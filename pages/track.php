<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$trackHomeUrl = storefront_url('home', $channelSlug, $lang);
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('track_order')); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($trackHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="card-box">
        <div class="form-grid">
            <div>
                <label><?php echo htmlspecialchars(t('order_number')); ?></label>
                <input id="track_order_number">
            </div>
            <div>
                <label><?php echo htmlspecialchars(t('phone')); ?></label>
                <input id="track_phone">
            </div>
        </div>
        <div class="actions-row" style="margin-top:14px;">
            <button type="button" class="btn" onclick="trackOrderNow()"><?php echo htmlspecialchars(t('track_order')); ?></button>
        </div>
        <div id="trackResult" style="margin-top:18px;"></div>
    </div>
</div>

<script>
async function trackOrderNow() {
    const orderNumber = document.getElementById('track_order_number').value.trim();
    const phone = document.getElementById('track_phone').value.trim();
    const resultBox = document.getElementById('trackResult');

    if (!orderNumber || !phone) {
        resultBox.innerHTML = '<div class="stock-out">' + <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?> + '</div>';
        return;
    }

    const url = (typeof storefrontApiUrl === 'function')
        ? storefrontApiUrl('/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone))
        : '/api/orders/get-order.php?order_number=' + encodeURIComponent(orderNumber) + '&phone=' + encodeURIComponent(phone);

    const response = await fetch(url);
    const result = await response.json();

    if (!result.success) {
        resultBox.innerHTML = '<div class="stock-out">' + (result.message || 'Order not found') + '</div>';
        return;
    }

    let html = '<div class="track-box">';
    html += '<p><strong><?php echo htmlspecialchars(t('order_number')); ?>:</strong> ' + result.order.order_number + '</p>';
    html += '<p><strong>Status:</strong> ' + result.order.status + '</p>';
    html += '<p><strong>Total:</strong> ' + result.order.total + ' KD</p>';
    html += '</div>';

    resultBox.innerHTML = html;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
