<?php
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <div class="page-title-box">
        <h2><?php echo htmlspecialchars(t('cart')); ?></h2>
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
