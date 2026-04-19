<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';

$trackHomeUrl = storefront_url('home', $channelSlug, $lang);
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
        <h2><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($trackHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="card-box">
        <div class="form-grid">
            <div>
                <label><?php echo htmlspecialchars(t('order_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="track_order_number" autocomplete="off">
            </div>
            <div>
                <label><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="track_phone" autocomplete="tel">
            </div>
        </div>
        <div class="actions-row" style="margin-top:14px;">
            <button type="button" class="btn" onclick="pageTrackOrderNow()"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
        <div id="trackResult" class="cart-track-result" style="margin-top:18px;"></div>
    </div>
</div>

<script>
window.ORANGE_STOREFRONT_WA = <?php echo json_encode($waHref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_ORDER_STATUS_LABELS = <?php echo json_encode($orangeOrderStatusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_MY_ORDER_UI = <?php echo json_encode($orangeMyOrderUi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_TRACK_LABELS = {
    order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
    phone: <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>
};

async function pageTrackOrderNow() {
    var msgMissing = <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>;
    var msgNotFound = <?php echo json_encode(t('track_order_not_found'), JSON_UNESCAPED_UNICODE); ?>;
    await orangeTrackOrderFetchAndRender(
        document.getElementById('trackResult'),
        document.getElementById('track_order_number').value.trim(),
        document.getElementById('track_phone').value.trim(),
        msgMissing,
        msgNotFound
    );
}
window.__orangeCartTrackRefresh = pageTrackOrderNow;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
