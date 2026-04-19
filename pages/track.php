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

    <div class="track-signup-cta card-box" id="trackSignupCta" role="region" aria-label="<?php echo htmlspecialchars(t('track_signup_cta_aria'), ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="false">
        <button type="button" class="track-signup-cta__close" id="trackSignupClose" hidden aria-label="<?php echo htmlspecialchars(t('track_signup_close'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></button>
        <div class="track-signup-cta__inner">
            <div class="track-signup-cta__copy">
                <p class="track-signup-cta__title"><?php echo htmlspecialchars(t('track_signup_cta_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="track-signup-cta__text"><?php echo htmlspecialchars(t('track_signup_cta_text'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="track-signup-cta__expand" id="trackSignupExpand" hidden>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupEmail"><?php echo htmlspecialchars(t('customer_email'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupEmail" type="email" name="email" autocomplete="email" inputmode="email" dir="ltr">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupName"><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupName" type="text" name="name" autocomplete="name">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupPhone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupPhone" type="tel" name="phone" autocomplete="tel" inputmode="tel">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupArea"><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupArea" name="area" autocomplete="address-level1">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupAddress"><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="trackSignupAddress" name="address" autocomplete="street-address" rows="2"></textarea>
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupNotes"><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?> <span class="form-optional-hint">(<?php echo htmlspecialchars(t('field_optional_short'), ENT_QUOTES, 'UTF-8'); ?>)</span></label>
                    <textarea id="trackSignupNotes" name="notes" rows="2"></textarea>
                </div>
                <p class="track-signup-cta__feedback" id="trackSignupMsg" role="status" aria-live="polite" hidden></p>
            </div>
            <div class="track-signup-cta__actions">
                <button type="button" class="btn track-signup-cta__btn track-signup-cta__btn-open" id="trackSignupOpenBtn"><?php echo htmlspecialchars(t('storefront_register'), ENT_QUOTES, 'UTF-8'); ?></button>
                <button type="button" class="btn track-signup-cta__btn track-signup-cta__btn-send" id="trackSignupSendBtn" hidden><?php echo htmlspecialchars(t('storefront_register_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
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

(function () {
    var cta = document.getElementById('trackSignupCta');
    var expand = document.getElementById('trackSignupExpand');
    var closeBtn = document.getElementById('trackSignupClose');
    var openBtn = document.getElementById('trackSignupOpenBtn');
    var sendBtn = document.getElementById('trackSignupSendBtn');
    var emailInp = document.getElementById('trackSignupEmail');
    var nameInp = document.getElementById('trackSignupName');
    var phoneInp = document.getElementById('trackSignupPhone');
    var areaInp = document.getElementById('trackSignupArea');
    var addressInp = document.getElementById('trackSignupAddress');
    var notesInp = document.getElementById('trackSignupNotes');
    var msgEl = document.getElementById('trackSignupMsg');
    if (!cta || !expand || !closeBtn || !openBtn || !sendBtn || !emailInp || !nameInp || !phoneInp || !areaInp || !addressInp || !notesInp || !msgEl) {
        return;
    }
    var sent = <?php echo json_encode(t('storefront_register_sent'), JSON_UNESCAPED_UNICODE); ?>;
    var already = <?php echo json_encode(t('storefront_register_already_verified'), JSON_UNESCAPED_UNICODE); ?>;
    var err = <?php echo json_encode(t('storefront_register_error'), JSON_UNESCAPED_UNICODE); ?>;

    function setHidden(el, on) {
        if (!el) {
            return;
        }
        if (on) {
            el.setAttribute('hidden', '');
        } else {
            el.removeAttribute('hidden');
        }
    }

    function expandPanel() {
        cta.classList.add('is-expanded');
        cta.setAttribute('aria-expanded', 'true');
        setHidden(expand, false);
        setHidden(closeBtn, false);
        setHidden(openBtn, true);
        setHidden(sendBtn, false);
        setHidden(msgEl, true);
        msgEl.textContent = '';
        requestAnimationFrame(function () {
            emailInp.focus();
        });
    }

    function collapsePanel() {
        cta.classList.remove('is-expanded');
        cta.setAttribute('aria-expanded', 'false');
        setHidden(expand, true);
        setHidden(closeBtn, true);
        setHidden(openBtn, false);
        setHidden(sendBtn, true);
        setHidden(msgEl, true);
        msgEl.textContent = '';
        emailInp.value = '';
        nameInp.value = '';
        phoneInp.value = '';
        areaInp.value = '';
        addressInp.value = '';
        notesInp.value = '';
    }

    /* حالة أولية صريحة — يظهر فقط «تسجيل» */
    collapsePanel();

    openBtn.addEventListener('click', expandPanel);
    closeBtn.addEventListener('click', collapsePanel);

    sendBtn.addEventListener('click', function () {
        var email = emailInp.value.trim();
        var name = nameInp.value.trim();
        var phone = phoneInp.value.trim();
        var area = areaInp.value.trim();
        var address = addressInp.value.trim();
        var notes = notesInp.value.trim();
        setHidden(msgEl, false);
        msgEl.textContent = '';
        var reqMsg = (window.APP_T && window.APP_T.checkout_required_fields) || '';
        var badEmail = (window.APP_T && window.APP_T.checkout_invalid_email) || '';
        if (!name || !phone || !email || !area || !address) {
            msgEl.textContent = reqMsg;
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            msgEl.textContent = badEmail;
            return;
        }
        if (phone.replace(/\D/g, '').length < 5) {
            msgEl.textContent = reqMsg;
            return;
        }
        var apiUrl =
            typeof storefrontApiUrl === 'function'
                ? storefrontApiUrl('/api/auth/request-email-verify.php')
                : (String(window.STOREFRONT_BASE || '').replace(/\/+$/, '') || '') +
                  '/api/auth/request-email-verify.php';
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: email,
                name: name,
                phone: phone,
                area: area,
                address: address,
                notes: notes,
                channel: typeof window.APP_CHANNEL_SLUG === 'string' ? window.APP_CHANNEL_SLUG : 'orange',
                lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
            }),
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { ok: r.ok, j: j };
                });
            })
            .then(function (x) {
                if (x.ok && x.j && x.j.success) {
                    if (x.j.channel && /^(orange|blue|black)$/i.test(String(x.j.channel))) {
                        try {
                            localStorage.setItem('orange_storefront_channel', String(x.j.channel).toLowerCase());
                        } catch (e1) {}
                    }
                    msgEl.textContent = x.j.already_verified ? already : sent;
                    return;
                }
                msgEl.textContent = x.j && x.j.message ? String(x.j.message) : err;
            })
            .catch(function () {
                msgEl.textContent = err;
            });
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
