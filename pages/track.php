<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';

$trackHomeUrl = storefront_url('home', $channelSlug, $lang);
$registerUrl = htmlspecialchars(storefront_url('register', $channelSlug, $lang), ENT_QUOTES, 'UTF-8');
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

    <div class="track-signup-cta card-box" role="region" aria-label="<?php echo htmlspecialchars(t('track_signup_cta_aria'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="track-signup-cta__inner">
            <div class="track-signup-cta__copy">
                <p class="track-signup-cta__title"><?php echo htmlspecialchars(t('track_signup_cta_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="track-signup-cta__text"><?php echo htmlspecialchars(t('track_signup_cta_text'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <a class="btn track-signup-cta__btn" href="<?php echo $registerUrl; ?>"><?php echo htmlspecialchars(t('storefront_register'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </div>

    <div class="card-box track-page-card">
        <h3 class="cart-section-title track-page-card__title"><?php echo htmlspecialchars(t('track_form_section_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
        <p class="track-form-intro"><?php echo htmlspecialchars(t('track_order_howto'), ENT_QUOTES, 'UTF-8'); ?></p>
        <hr class="track-form-divider" aria-hidden="true">
        <form class="track-page-form" id="track-page-form" action="#" method="get" novalidate>
            <div class="field">
                <label for="track_order_number"><?php echo htmlspecialchars(t('order_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="track_order_number" name="order_number" autocomplete="off" inputmode="text">
            </div>
            <div class="field">
                <label for="track_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="track_phone" name="phone" autocomplete="tel" inputmode="tel">
            </div>
            <div class="actions-row track-page-actions">
                <button type="submit" class="btn btn--track-submit"><?php echo htmlspecialchars(t('track_order'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </form>
        <div id="trackResult" class="cart-track-result track-page-result" style="margin-top:18px;" tabindex="-1"></div>
    </div>
</div>

<script>
window.ORANGE_STOREFRONT_WA = <?php echo json_encode($waHref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_ORDER_STATUS_LABELS = <?php echo json_encode($orangeOrderStatusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_MY_ORDER_UI = <?php echo json_encode($orangeMyOrderUi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.ORANGE_TRACK_LABELS = {
    order_number: <?php echo json_encode(t('order_number'), JSON_UNESCAPED_UNICODE); ?>,
    phone: <?php echo json_encode(t('phone'), JSON_UNESCAPED_UNICODE); ?>,
    items_title: <?php echo json_encode(t('track_order_items'), JSON_UNESCAPED_UNICODE); ?>
};

(function () {
    var tf = document.getElementById('track-page-form');
    if (tf) {
        tf.addEventListener('submit', function (e) {
            e.preventDefault();
            pageTrackOrderNow();
        });
    }
})();

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
