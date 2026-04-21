<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';

$pdoTrack = db();
orange_catalog_ensure_schema($pdoTrack);

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
                <div id="trackSignupPreVerify">
                    <p class="track-signup-cta__verify-note"><?php echo htmlspecialchars(t('track_signup_identity_note'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="field track-signup-cta__field">
                        <label for="trackSignupOrderNumber"><?php echo htmlspecialchars(t('order_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="trackSignupOrderNumber" name="signup_order_number" autocomplete="off" inputmode="text" placeholder="<?php echo htmlspecialchars(t('track_signup_placeholder_order_number'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="field track-signup-cta__field">
                        <label for="trackSignupVerifyPhone"><?php echo htmlspecialchars(t('track_signup_verify_phone_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="trackSignupVerifyPhone" name="signup_verify_phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="<?php echo htmlspecialchars(t('track_signup_placeholder_verify_phone'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="track-signup-cta__verify-row">
                        <button type="button" class="btn btn-secondary track-signup-cta__verify-btn" id="trackSignupVerifyOrderBtn"><?php echo htmlspecialchars(t('track_signup_verify_order_btn'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </div>
                    <p class="track-signup-cta__verify-feedback" id="trackSignupVerifyFeedback" role="status" aria-live="polite" hidden></p>
                </div>
                <div class="track-signup-order-summary" id="trackSignupOrderSummary" hidden></div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupEmail"><?php echo htmlspecialchars(t('customer_email'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupEmail" type="email" name="email" autocomplete="email" inputmode="email" dir="ltr" placeholder="<?php echo htmlspecialchars(t('track_signup_placeholder_email'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="trackSignupName"><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="trackSignupName" type="text" name="name" autocomplete="name">
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
                    <label for="trackSignupNotes"><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="trackSignupNotes" name="notes" rows="2" placeholder="<?php echo htmlspecialchars(t('track_signup_placeholder_notes'), ENT_QUOTES, 'UTF-8'); ?>"></textarea>
                </div>
                <p class="track-signup-cta__feedback" id="trackSignupMsg" role="status" aria-live="polite" hidden></p>
            </div>
            <div class="track-signup-cta__actions">
                <button type="button" class="btn track-signup-cta__btn track-signup-cta__btn-open" id="trackSignupOpenBtn"><?php echo htmlspecialchars(t('storefront_register'), ENT_QUOTES, 'UTF-8'); ?></button>
                <button type="button" class="btn track-signup-cta__btn track-signup-cta__btn-send" id="trackSignupSendBtn" hidden><?php echo htmlspecialchars(t('storefront_register_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
        </div>
    </div>

    <div class="card-box track-page-card" id="track-no-signup-section">
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
window.ORANGE_TRACK_SIGNUP = {
    order_required: <?php echo json_encode(t('track_signup_order_required'), JSON_UNESCAPED_UNICODE); ?>,
    order_mismatch: <?php echo json_encode(t('track_signup_order_mismatch'), JSON_UNESCAPED_UNICODE); ?>,
    verify_ok: <?php echo json_encode(t('track_signup_verify_ok'), JSON_UNESCAPED_UNICODE); ?>,
    verify_fail: <?php echo json_encode(t('track_signup_verify_fail'), JSON_UNESCAPED_UNICODE); ?>,
    missing: <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>,
    nudge_merge: <?php echo json_encode(t('track_signup_nudge_merge'), JSON_UNESCAPED_UNICODE); ?>
};
window.ORANGE_TRACK_BELOW = {
    ok: <?php echo json_encode(t('track_tracked_ok_below'), JSON_UNESCAPED_UNICODE); ?>,
    another: <?php echo json_encode(t('track_track_another'), JSON_UNESCAPED_UNICODE); ?>
};

/** س27: إزالة أي معرّفات حساسة من شريط العنوان إن وُجدت (روابط قديمة أو أخطاء). */
(function () {
    try {
        var params = new URLSearchParams(window.location.search || '');
        var dirty = false;
        ['order_number', 'phone', 'onum', 'tel', 'mobile'].forEach(function (k) {
            if (params.has(k)) {
                params.delete(k);
                dirty = true;
            }
        });
        if (dirty) {
            var qs = params.toString();
            var path = window.location.pathname + (qs ? '?' + qs : '') + (window.location.hash || '');
            window.history.replaceState(null, '', path);
        }
    } catch (e) {}
})();

(function () {
    var tf = document.getElementById('track-page-form');
    if (tf) {
        tf.addEventListener('submit', function (e) {
            e.preventDefault();
            pageTrackOrderNow();
        });
    }
})();

function orangeTrackPhoneForApi(raw) {
    var s = String(raw || '').trim();
    if (!s) {
        return '';
    }
    if (typeof window.orangeNormalizeCustomerPhone === 'function') {
        var n = window.orangeNormalizeCustomerPhone(s, null);
        if (n) {
            return n;
        }
    }
    return s;
}

async function pageTrackOrderNow() {
    var msgMissing = <?php echo json_encode(t('track_missing_fields'), JSON_UNESCAPED_UNICODE); ?>;
    var msgNotFound = <?php echo json_encode(t('track_order_not_found'), JSON_UNESCAPED_UNICODE); ?>;
    var onum = document.getElementById('track_order_number').value.trim();
    var phRaw = document.getElementById('track_phone').value.trim();
    var ph = orangeTrackPhoneForApi(phRaw);
    if (!onum || !phRaw) {
        if (typeof window.orangeShowToast === 'function') {
            window.orangeShowToast(msgMissing, 3200);
        } else {
            alert(msgMissing);
        }
        return;
    }
    if (!ph) {
        var bad = (window.APP_T && window.APP_T.checkout_invalid_phone) || msgMissing;
        if (typeof window.orangeShowToast === 'function') {
            window.orangeShowToast(bad, 3800);
        } else {
            alert(bad);
        }
        return;
    }
    await orangeTrackOrderFetchAndRender(
        document.getElementById('trackResult'),
        onum,
        ph,
        msgMissing,
        msgNotFound,
        { minimalBelow: true }
    );
}
window.__orangeCartTrackRefresh = pageTrackOrderNow;

(function () {
    var cta = document.getElementById('trackSignupCta');
    var expand = document.getElementById('trackSignupExpand');
    var closeBtn = document.getElementById('trackSignupClose');
    var openBtn = document.getElementById('trackSignupOpenBtn');
    var sendBtn = document.getElementById('trackSignupSendBtn');
    var preVerify = document.getElementById('trackSignupPreVerify');
    var orderSummaryEl = document.getElementById('trackSignupOrderSummary');
    var copyTitle = cta ? cta.querySelector('.track-signup-cta__title') : null;
    var copyText = cta ? cta.querySelector('.track-signup-cta__text') : null;
    var copyTitleDefault = copyTitle ? copyTitle.textContent : '';
    var copyTextDefault = copyText ? copyText.textContent : '';
    var signupOrderInp = document.getElementById('trackSignupOrderNumber');
    var verifyPhoneInp = document.getElementById('trackSignupVerifyPhone');
    var emailInp = document.getElementById('trackSignupEmail');
    var nameInp = document.getElementById('trackSignupName');
    var areaInp = document.getElementById('trackSignupArea');
    var addressInp = document.getElementById('trackSignupAddress');
    var notesInp = document.getElementById('trackSignupNotes');
    var msgEl = document.getElementById('trackSignupMsg');
    var verifyOrderBtn = document.getElementById('trackSignupVerifyOrderBtn');
    var verifyFeedbackEl = document.getElementById('trackSignupVerifyFeedback');
    var trackOrderNumLower = document.getElementById('track_order_number');
    var trackPhoneLower = document.getElementById('track_phone');
    if (
        !cta ||
        !expand ||
        !closeBtn ||
        !openBtn ||
        !sendBtn ||
        !preVerify ||
        !orderSummaryEl ||
        !signupOrderInp ||
        !verifyPhoneInp ||
        !emailInp ||
        !nameInp ||
        !areaInp ||
        !addressInp ||
        !notesInp ||
        !msgEl ||
        !verifyOrderBtn ||
        !verifyFeedbackEl
    ) {
        return;
    }
    var sent = <?php echo json_encode(t('storefront_register_sent'), JSON_UNESCAPED_UNICODE); ?>;
    var cooldown = <?php echo json_encode(t('storefront_register_cooldown'), JSON_UNESCAPED_UNICODE); ?>;
    var already = <?php echo json_encode(t('storefront_register_already_verified'), JSON_UNESCAPED_UNICODE); ?>;
    var err = <?php echo json_encode(t('storefront_register_error'), JSON_UNESCAPED_UNICODE); ?>;
    var trackSignupT = window.ORANGE_TRACK_SIGNUP || {};

    function fillSignupFieldsFromOrder(o) {
        if (!o) {
            return;
        }
        if (!emailInp.value.trim() && o.customer_email) {
            emailInp.value = String(o.customer_email);
        }
        if (!nameInp.value.trim() && o.customer_name) {
            nameInp.value = String(o.customer_name);
        }
        if (!areaInp.value.trim() && o.area) {
            areaInp.value = String(o.area);
        }
        if (!addressInp.value.trim() && o.address) {
            addressInp.value = String(o.address);
        }
        if (!notesInp.value.trim() && o.notes) {
            notesInp.value = String(o.notes);
        }
    }

    function fillSignupFieldsFromOrderForce(o) {
        if (!o) {
            return;
        }
        emailInp.value = o.customer_email != null && String(o.customer_email).trim() !== '' ? String(o.customer_email) : '';
        nameInp.value = o.customer_name != null ? String(o.customer_name) : '';
        areaInp.value = o.area != null ? String(o.area) : '';
        addressInp.value = o.address != null ? String(o.address) : '';
        notesInp.value = o.notes != null ? String(o.notes) : '';
    }

    function enterPostTrackFromLower(order, orderNumber, phone, items) {
        cta.classList.add('is-expanded', 'is-post-track');
        cta.setAttribute('aria-expanded', 'true');
        setHidden(expand, false);
        setHidden(closeBtn, false);
        setHidden(preVerify, true);
        setHidden(orderSummaryEl, false);
        if (typeof orangeRenderTrackSignupSummary === 'function') {
            orangeRenderTrackSignupSummary(orderSummaryEl, order, orderNumber, phone, items || []);
        } else {
            orderSummaryEl.innerHTML = '';
        }
        var merge = trackSignupT.nudge_merge;
        if (copyText) {
            copyText.textContent = merge ? copyTextDefault + ' ' + merge : copyTextDefault;
        }
        if (copyTitle) {
            copyTitle.textContent = copyTitleDefault;
        }
        setHidden(openBtn, false);
        setHidden(sendBtn, true);
        setHidden(msgEl, true);
        msgEl.textContent = '';
        verifyFeedbackEl.textContent = '';
        setHidden(verifyFeedbackEl, true);
        signupOrderInp.value = orderNumber != null ? String(orderNumber) : '';
        verifyPhoneInp.value = phone != null ? String(phone) : '';
        fillSignupFieldsFromOrderForce(order);
        syncLowerTrackInputs(orderNumber, phone);
        requestAnimationFrame(function () {
            try {
                cta.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (eScroll) {}
            try {
                emailInp.focus();
            } catch (eF) {}
        });
    }

    function syncLowerTrackInputs(orderNumber, phoneTyped) {
        if (trackOrderNumLower) {
            trackOrderNumLower.value = orderNumber;
        }
        if (trackPhoneLower) {
            trackPhoneLower.value = phoneTyped;
        }
    }

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
        cta.classList.remove('is-post-track');
        setHidden(preVerify, false);
        setHidden(orderSummaryEl, true);
        orderSummaryEl.innerHTML = '';
        if (copyText) {
            copyText.textContent = copyTextDefault;
        }
        if (copyTitle) {
            copyTitle.textContent = copyTitleDefault;
        }
        cta.classList.add('is-expanded');
        cta.setAttribute('aria-expanded', 'true');
        setHidden(expand, false);
        setHidden(closeBtn, false);
        setHidden(openBtn, true);
        setHidden(sendBtn, false);
        setHidden(msgEl, true);
        msgEl.textContent = '';
        verifyFeedbackEl.textContent = '';
        setHidden(verifyFeedbackEl, true);
        var ctx = window.__orangeCartTrack;
        if (ctx && ctx.order) {
            if (!signupOrderInp.value.trim() && ctx.orderNumber) {
                signupOrderInp.value = String(ctx.orderNumber);
            }
            if (!verifyPhoneInp.value.trim() && ctx.phone) {
                verifyPhoneInp.value = String(ctx.phone);
            }
            var ton = signupOrderInp.value.trim();
            if (String(ctx.order.order_number || '') === ton) {
                fillSignupFieldsFromOrder(ctx.order);
            }
        }
        requestAnimationFrame(function () {
            if (!signupOrderInp.value.trim()) {
                signupOrderInp.focus();
            } else if (!verifyPhoneInp.value.trim()) {
                verifyPhoneInp.focus();
            } else {
                emailInp.focus();
            }
        });
    }

    function collapsePanel() {
        cta.classList.remove('is-expanded', 'is-post-track');
        cta.setAttribute('aria-expanded', 'false');
        setHidden(expand, true);
        setHidden(closeBtn, true);
        setHidden(openBtn, false);
        setHidden(sendBtn, true);
        setHidden(preVerify, false);
        setHidden(orderSummaryEl, true);
        orderSummaryEl.innerHTML = '';
        if (copyText) {
            copyText.textContent = copyTextDefault;
        }
        if (copyTitle) {
            copyTitle.textContent = copyTitleDefault;
        }
        setHidden(msgEl, true);
        msgEl.textContent = '';
        signupOrderInp.value = '';
        verifyPhoneInp.value = '';
        emailInp.value = '';
        nameInp.value = '';
        areaInp.value = '';
        addressInp.value = '';
        notesInp.value = '';
        verifyFeedbackEl.textContent = '';
        setHidden(verifyFeedbackEl, true);
    }

    collapsePanel();

    openBtn.addEventListener('click', function () {
        if (cta.classList.contains('is-post-track')) {
            submitTrackSignupEmail();
            return;
        }
        expandPanel();
    });
    closeBtn.addEventListener('click', collapsePanel);

    verifyOrderBtn.addEventListener('click', function () {
        var onum = signupOrderInp.value.trim();
        var vphRaw = verifyPhoneInp.value.trim();
        var vph = orangeTrackPhoneForApi(vphRaw);
        verifyFeedbackEl.textContent = '';
        setHidden(verifyFeedbackEl, false);
        if (!onum || !vphRaw) {
            verifyFeedbackEl.textContent = trackSignupT.missing || '';
            return;
        }
        if (vphRaw.replace(/\D/g, '').length < 5) {
            verifyFeedbackEl.textContent = trackSignupT.order_required || '';
            return;
        }
        if (!vph) {
            verifyFeedbackEl.textContent = (window.APP_T && window.APP_T.checkout_invalid_phone) || trackSignupT.order_required || '';
            return;
        }
        var urlBase =
            typeof storefrontApiUrl === 'function'
                ? storefrontApiUrl('/api/orders/get-order.php')
                : (function () {
                      var b = String(window.STOREFRONT_BASE || '').replace(/\/+$/, '') || '';
                      var u = b + '/api/orders/get-order.php';
                      var L =
                          typeof window.APP_LANG === 'string'
                              ? window.APP_LANG.trim().toLowerCase()
                              : '';
                      if (L && ['en', 'ar', 'fil', 'hi'].indexOf(L) !== -1) {
                          u += (u.indexOf('?') !== -1 ? '&' : '?') + 'lang=' + encodeURIComponent(L);
                      }
                      return u;
                  })();
        var langPost =
            typeof window.APP_LANG === 'string' && window.APP_LANG.trim() !== ''
                ? window.APP_LANG.trim().toLowerCase()
                : 'en';
        verifyOrderBtn.disabled = true;
        fetch(urlBase, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                order_number: onum,
                phone: vph,
                lang: langPost,
            }),
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { ok: r.ok, j: j, status: r.status };
                });
            })
            .then(function (x) {
                verifyOrderBtn.disabled = false;
                if (x.j && x.j.success && x.j.order) {
                    window.__orangeCartTrack = { orderNumber: onum, phone: vph, order: x.j.order };
                    syncLowerTrackInputs(onum, vph);
                    fillSignupFieldsFromOrder(x.j.order);
                    verifyFeedbackEl.textContent = trackSignupT.verify_ok || '';
                    return;
                }
                var mappedVerify =
                    typeof window.orangeCheckoutApiMessage === 'function'
                        ? window.orangeCheckoutApiMessage(x.j || {})
                        : '';
                verifyFeedbackEl.textContent =
                    mappedVerify || trackSignupT.verify_fail || trackSignupT.order_mismatch || '';
            })
            .catch(function () {
                verifyOrderBtn.disabled = false;
                verifyFeedbackEl.textContent =
                    (window.APP_T && window.APP_T.api_request_failed) ||
                    trackSignupT.verify_fail ||
                    '';
            });
    });

    function submitTrackSignupEmail() {
        var email = emailInp.value.trim();
        var name = nameInp.value.trim();
        var orderNumber = signupOrderInp.value.trim();
        var orderVerifyPhoneRaw = verifyPhoneInp.value.trim();
        var orderVerifyPhone = orangeTrackPhoneForApi(orderVerifyPhoneRaw);
        var area = areaInp.value.trim();
        var address = addressInp.value.trim();
        var notes = notesInp.value.trim();
        setHidden(msgEl, false);
        msgEl.textContent = '';
        var badEmail = (window.APP_T && window.APP_T.checkout_invalid_email) || '';
        var badPh = (window.APP_T && window.APP_T.checkout_invalid_phone) || '';
        if (!orderNumber || !orderVerifyPhoneRaw) {
            msgEl.textContent = trackSignupT.order_required || '';
            return;
        }
        if (orderVerifyPhoneRaw.replace(/\D/g, '').length < 5) {
            msgEl.textContent = trackSignupT.order_required || '';
            return;
        }
        if (!orderVerifyPhone) {
            msgEl.textContent = badPh || trackSignupT.order_required || '';
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            msgEl.textContent = badEmail;
            return;
        }
        var apiUrl =
            typeof storefrontApiUrl === 'function'
                ? storefrontApiUrl('/api/auth/request-email-verify.php')
                : (function () {
                      var b = String(window.STOREFRONT_BASE || '').replace(/\/+$/, '') || '';
                      var u = b + '/api/auth/request-email-verify.php';
                      var L =
                          typeof window.APP_LANG === 'string'
                              ? window.APP_LANG.trim().toLowerCase()
                              : '';
                      if (L && ['en', 'ar', 'fil', 'hi'].indexOf(L) !== -1) {
                          u += '?lang=' + encodeURIComponent(L);
                      }
                      return u;
                  })();
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: email,
                name: name,
                phone: orderVerifyPhone,
                area: area,
                address: address,
                notes: notes,
                order_number: orderNumber,
                order_verify_phone: orderVerifyPhone,
                channel: typeof window.APP_CHANNEL_SLUG === 'string' ? window.APP_CHANNEL_SLUG : <?php echo json_encode(orange_storefront_default_channel_slug($pdoTrack), JSON_UNESCAPED_UNICODE); ?>,
                lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
            }),
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { ok: r.ok, j: j, status: r.status };
                });
            })
            .then(function (x) {
                if (x.ok && x.j && x.j.success) {
                    if (x.j.channel && /^[a-z0-9\-]+$/i.test(String(x.j.channel))) {
                        try {
                            if (typeof window.orangeSfPersistChannel === 'function') {
                                window.orangeSfPersistChannel(String(x.j.channel).toLowerCase());
                            } else {
                                localStorage.setItem('orange_storefront_channel', String(x.j.channel).toLowerCase());
                            }
                        } catch (e1) {}
                    }
                    msgEl.textContent = x.j.already_verified ? already : (x.j.cooldown ? cooldown : sent);
                    return;
                }
                var c = x.j && x.j.code ? String(x.j.code) : '';
                if (c === 'order_not_found' || c === 'order_link_mismatch' || c === 'signup_phone_mismatch' || x.status === 404) {
                    msgEl.textContent =
                        typeof window.orangeStorefrontRegisterApiError === 'function'
                            ? window.orangeStorefrontRegisterApiError(x.j, trackSignupT.order_mismatch || err)
                            : trackSignupT.order_mismatch || (x.j && x.j.message ? String(x.j.message) : err);
                    return;
                }
                msgEl.textContent =
                    typeof window.orangeStorefrontRegisterApiError === 'function'
                        ? window.orangeStorefrontRegisterApiError(x.j, err)
                        : x.j && x.j.message
                          ? String(x.j.message)
                          : err;
            })
            .catch(function () {
                msgEl.textContent = (window.APP_T && window.APP_T.api_request_failed) || err;
            });
    }

    sendBtn.addEventListener('click', submitTrackSignupEmail);

    window.__orangeApplyLowerTrackToSignup = function (orderNumber, phone, order, items) {
        enterPostTrackFromLower(order, orderNumber, phone, items);
    };
    window.__orangeOnTrackSuccess = function (payload) {
        if (!payload || !payload.order) {
            return;
        }
        window.__orangeApplyLowerTrackToSignup(payload.orderNumber, payload.phone, payload.order, payload.items || []);
    };
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
