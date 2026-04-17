<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$cartHomeUrl = storefront_url('home', $channelSlug, $lang);
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('cart')); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($cartHomeUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('cart_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="cart-layout">
        <div class="card-box">
            <div id="cartItems"></div>
        </div>

        <div class="card-box">
            <div class="field">
                <label><?php echo htmlspecialchars(t('customer_name')); ?></label>
                <input id="customer_name">
            </div>
            <div class="field">
                <label><?php echo htmlspecialchars(t('phone')); ?></label>
                <input id="customer_phone">
            </div>
            <div class="field">
                <label><?php echo htmlspecialchars(t('area')); ?></label>
                <input id="customer_area">
            </div>
            <div class="field">
                <label><?php echo htmlspecialchars(t('address')); ?></label>
                <textarea id="customer_address"></textarea>
            </div>
            <div class="field">
                <label><?php echo htmlspecialchars(t('notes')); ?></label>
                <textarea id="customer_notes"></textarea>
            </div>

            <button class="btn" onclick="sendOrderNow()"><?php echo htmlspecialchars(t('send_order')); ?></button>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
