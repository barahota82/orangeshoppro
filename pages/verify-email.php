<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/storefront_account.php';
require_once __DIR__ . '/../includes/party_subledger.php';

$pdo = db();
orange_catalog_ensure_storefront_page($pdo);

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$result = ['ok' => false, 'reason' => 'bad'];

if ($token !== '' && orange_table_exists($pdo, 'storefront_accounts')) {
    $result = orange_storefront_verify_email_token($pdo, $token);
}

if (!empty($result['ok']) && !empty($result['account_id'])) {
    // س15: عند تأكيد البريد لأول مرة، أنشئ/حدّث صف العميل في customers واربط customer_id.
    try {
        orange_sync_storefront_account_to_customer($pdo, (int) $result['account_id']);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] verify-email customer sync: ' . $e->getMessage());
        }
    }
    storefront_account_login($pdo, (int) $result['account_id']);
}

$ORANGE_STOREFRONT_PAGE_TITLE = t('storefront_verify_title');

include __DIR__ . '/../includes/header.php';

$homeHref = storefront_url('home', $channelSlug, $lang);
$msgKey = 'storefront_verify_bad_token';
if (!empty($result['ok'])) {
    if (($result['reason'] ?? '') === 'already') {
        $msgKey = 'storefront_verify_already';
    } else {
        $msgKey = 'storefront_verify_ok';
    }
} elseif (($result['reason'] ?? '') === 'expired') {
    $msgKey = 'storefront_verify_expired';
}
?>
<div class="container">
    <div class="page-title-box">
        <h2><?php echo htmlspecialchars(t('storefront_verify_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>
    <div class="card-box" style="max-width: 32rem; margin: 0 auto;">
        <p class="cart-checkout-intro"><?php echo htmlspecialchars(t($msgKey), ENT_QUOTES, 'UTF-8'); ?></p>
        <p style="margin-top: 1rem;">
            <a class="btn" href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('home'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php if (!empty($result['ok'])): ?>
            <a class="btn" href="<?php echo htmlspecialchars(storefront_url('register', $channelSlug, $lang), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('storefront_register_title'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php
include __DIR__ . '/../includes/footer.php';
