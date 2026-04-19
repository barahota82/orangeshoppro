<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/storefront_account.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!empty($_GET['logout'])) {
    storefront_account_logout();
    $slug = current_channel_slug();
    $lang = current_lang();
    header('Location: ' . storefront_url('register', $slug, $lang));
    exit;
}

$ORANGE_STOREFRONT_PAGE_TITLE = t('storefront_register_title');
$ORANGE_STOREFRONT_META_DESCRIPTION = t('storefront_register_intro');

include __DIR__ . '/../includes/header.php';

$acc = current_storefront_account($pdo);
$registerHref = storefront_url('register', $channelSlug, $lang);
$homeHref = storefront_url('home', $channelSlug, $lang);
?>
<div class="container">
    <div class="page-title-box">
        <h2><?php echo htmlspecialchars(t('storefront_register_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
    </div>

    <div class="card-box" style="max-width: 32rem; margin: 0 auto;">
        <p class="cart-checkout-intro"><?php echo htmlspecialchars(t('storefront_register_intro'), ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="cart-checkout-intro" style="margin-top: 0.75rem;"><?php echo htmlspecialchars(t('storefront_guest_checkout_note'), ENT_QUOTES, 'UTF-8'); ?></p>

        <?php if ($acc): ?>
            <?php
            $accChMeta = get_channel_by_slug($acc['registered_channel_slug']);
            $accChLabel = $accChMeta ? (string) ($accChMeta['name'] ?? $acc['registered_channel_slug']) : $acc['registered_channel_slug'];
            ?>
            <p style="margin-top: 1rem;">
                <?php echo htmlspecialchars(t('storefront_account_signed_in'), ENT_QUOTES, 'UTF-8'); ?>
                <strong dir="ltr"><?php echo htmlspecialchars($acc['email'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <p class="cart-checkout-intro" style="margin-top: 0.5rem;">
                <?php echo htmlspecialchars(t('storefront_your_channel'), ENT_QUOTES, 'UTF-8'); ?>
                <strong><?php echo htmlspecialchars($accChLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <p class="cart-checkout-intro" style="margin-top: 0.75rem;"><?php echo htmlspecialchars(t('storefront_pwa_install_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            <p style="margin-top: 0.75rem;">
                <a class="btn" href="<?php echo htmlspecialchars($registerHref . (str_contains($registerHref, '?') ? '&' : '?') . 'logout=1', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('storefront_logout'), ENT_QUOTES, 'UTF-8'); ?></a>
                <a class="btn" href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" style="opacity:0.9"><?php echo htmlspecialchars(t('home'), ENT_QUOTES, 'UTF-8'); ?></a>
            </p>
        <?php else: ?>
            <form id="orangeRegisterForm" style="margin-top: 1rem;">
                <div class="field">
                    <label for="reg_email"><?php echo htmlspecialchars(t('storefront_register_email_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="reg_email" name="email" type="email" autocomplete="email" required dir="ltr">
                </div>
                <p id="orangeRegisterMsg" class="cart-checkout-intro" style="margin-top: 0.75rem; min-height: 1.25em;" hidden></p>
                <button type="submit" class="btn" style="margin-top: 1rem;"><?php echo htmlspecialchars(t('storefront_register_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
            <script>
            (function () {
                var form = document.getElementById('orangeRegisterForm');
                var msg = document.getElementById('orangeRegisterMsg');
                if (!form || !msg) return;
                var sent = <?php echo json_encode(t('storefront_register_sent'), JSON_UNESCAPED_UNICODE); ?>;
                var already = <?php echo json_encode(t('storefront_register_already_verified'), JSON_UNESCAPED_UNICODE); ?>;
                var err = <?php echo json_encode(t('storefront_register_error'), JSON_UNESCAPED_UNICODE); ?>;
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    msg.hidden = false;
                    msg.textContent = '';
                    var email = (document.getElementById('reg_email') || {}).value || '';
                    var base = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
                    fetch(base + '/api/auth/request-email-verify.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: email,
                            channel: typeof window.APP_CHANNEL_SLUG === 'string' ? window.APP_CHANNEL_SLUG : 'orange',
                            lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en'
                        })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (x) {
                        if (x.ok && x.j && x.j.success) {
                            if (x.j.channel && /^(orange|blue|black)$/i.test(String(x.j.channel))) {
                                try {
                                    localStorage.setItem('orange_storefront_channel', String(x.j.channel).toLowerCase());
                                } catch (e1) {}
                            }
                            msg.textContent = x.j.already_verified ? already : sent;
                            return;
                        }
                        msg.textContent = (x.j && x.j.message) ? String(x.j.message) : err;
                    }).catch(function () {
                        msg.textContent = err;
                    });
                });
            })();
            </script>
        <?php endif; ?>
    </div>
</div>
<?php
include __DIR__ . '/../includes/footer.php';
