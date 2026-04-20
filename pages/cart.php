<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('cart'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($cartHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('cart_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="cart-layout">
        <div class="card-box">
            <div id="cartItems"></div>
        </div>

        <div class="card-box">
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

<script>
window.ORANGE_CART_HOME = <?php echo json_encode(storefront_url('home', $channelSlug, $lang), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
