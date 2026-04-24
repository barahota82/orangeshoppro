<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/storefront_account.php';
require_once __DIR__ . '/../includes/delivery_areas.php';

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

$registerDeliveryAreas = orange_delivery_areas_storefront_payload($pdo, $lang);
$acc = current_storefront_account($pdo);
$registerHref = storefront_url('register', $channelSlug, $lang);
$homeHref = storefront_url('home', $channelSlug, $lang);
?>
<div class="container">
    <div class="page-title-box cart-page-head">
        <h2><?php echo htmlspecialchars(t('storefront_register_title'), ENT_QUOTES, 'UTF-8'); ?></h2>
        <a class="cart-page-close" href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(t('product_back_to_shop'), ENT_QUOTES, 'UTF-8'); ?>"><span aria-hidden="true">&times;</span></a>
    </div>

    <div class="track-signup-cta card-box register-signup-cta" role="region" aria-label="<?php echo htmlspecialchars(t('track_signup_cta_aria'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="track-signup-cta__inner">
        <?php if ($acc): ?>
            <?php
            $accRegSlug = preg_replace('/[^a-z0-9\-]/i', '', (string) ($acc['registered_channel_slug'] ?? ''));
            $accChMeta = $accRegSlug !== '' ? get_channel_by_slug($accRegSlug) : null;
            $accChLabel = storefront_channel_display_name($accChMeta ?? ['name' => ''], $accRegSlug !== '' ? $accRegSlug : (string) $channelSlug);
            ?>
            <div class="track-signup-cta__copy">
                <p class="track-signup-cta__title"><?php echo htmlspecialchars(t('storefront_register_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="track-signup-cta__text">
                    <?php echo htmlspecialchars(t('storefront_account_signed_in'), ENT_QUOTES, 'UTF-8'); ?>
                    <strong dir="ltr"><?php echo htmlspecialchars($acc['email'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </p>
            </div>
            <div class="register-signup-cta__body">
            <?php
            $profBits = [];
            if (!empty($acc['customer_name'])) {
                $profBits[] = [t('customer_name'), (string) $acc['customer_name']];
            }
            if (!empty($acc['customer_phone'])) {
                $profBits[] = [t('phone'), (string) $acc['customer_phone']];
            }
            if (!empty($acc['customer_area'])) {
                $profBits[] = [t('area'), (string) $acc['customer_area']];
            }
            if (!empty($acc['customer_address'])) {
                $profBits[] = [t('address'), (string) $acc['customer_address']];
            }
            if (!empty($acc['customer_notes'])) {
                $profBits[] = [t('notes'), (string) $acc['customer_notes']];
            }
            ?>
            <?php if ($profBits !== []): ?>
                <div class="register-profile card-box" style="padding: 12px 14px; text-align: start;">
                    <p class="cart-checkout-intro" style="margin: 0 0 8px; font-weight: 600;"><?php echo htmlspecialchars(t('cart_checkout_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <dl style="margin: 0; font-size: 0.9rem; line-height: 1.5;">
                        <?php foreach ($profBits as $pair): ?>
                            <div style="margin-top: 6px;">
                                <dt style="margin: 0; color: var(--muted); font-weight: 600;"><?php echo htmlspecialchars($pair[0], ENT_QUOTES, 'UTF-8'); ?></dt>
                                <dd style="margin: 2px 0 0; white-space: pre-wrap;"><?php echo htmlspecialchars($pair[1], ENT_QUOTES, 'UTF-8'); ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endif; ?>
            <p class="cart-checkout-intro" style="margin: 0;">
                <?php echo htmlspecialchars(t('storefront_your_channel'), ENT_QUOTES, 'UTF-8'); ?>
                <strong><?php echo htmlspecialchars($accChLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <p class="cart-checkout-intro" style="margin: 0;"><?php echo htmlspecialchars(t('storefront_pwa_install_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="track-signup-cta__actions register-signup-cta__btn-row">
                <a class="btn track-signup-cta__btn" href="<?php echo htmlspecialchars($registerHref . (str_contains($registerHref, '?') ? '&' : '?') . 'logout=1', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('storefront_logout'), ENT_QUOTES, 'UTF-8'); ?></a>
                <a class="btn track-signup-cta__btn" href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" style="opacity:0.9"><?php echo htmlspecialchars(t('home'), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
            </div>
        <?php else: ?>
            <div class="track-signup-cta__copy">
                <p class="track-signup-cta__title"><?php echo htmlspecialchars(t('track_signup_cta_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="track-signup-cta__text"><?php echo htmlspecialchars(t('track_signup_cta_text'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="track-signup-cta__text"><?php echo htmlspecialchars(t('storefront_guest_checkout_note'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="track-signup-cta__expand register-signup-cta__form-wrap">
            <form id="orangeRegisterForm">
                <div class="field track-signup-cta__field">
                    <label for="reg_email"><?php echo htmlspecialchars(t('customer_email'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="reg_email" name="email" type="email" autocomplete="email" required dir="ltr" placeholder="<?php echo htmlspecialchars(t('register_placeholder_email'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="field track-signup-cta__field">
                    <label for="reg_name"><?php echo htmlspecialchars(t('customer_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input id="reg_name" name="name" type="text" autocomplete="name" required placeholder="<?php echo htmlspecialchars(t('register_placeholder_name'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="field-phone-row">
                    <div class="field track-signup-cta__field field-phone-row__country">
                        <label for="reg_phone_country"><?php echo htmlspecialchars(t('phone_country_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <select id="reg_phone_country" name="phone_country" class="field-phone-row__select" data-orange-country-codes autocomplete="tel-country-code" dir="ltr" required aria-label="<?php echo htmlspecialchars(t('phone_country_label'), ENT_QUOTES, 'UTF-8'); ?>"></select>
                    </div>
                    <div class="field track-signup-cta__field field-phone-row__number">
                        <label for="reg_phone"><?php echo htmlspecialchars(t('phone'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input id="reg_phone" name="phone" class="js-orange-phone-input" type="tel" autocomplete="tel" required inputmode="numeric" maxlength="22" data-orange-national-phone="reg_phone_country" placeholder="<?php echo htmlspecialchars(t('register_placeholder_phone'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="field track-signup-cta__field">
                    <label for="reg_area"><?php echo htmlspecialchars(t('area'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <?php if (count($registerDeliveryAreas) > 0): ?>
                    <?php echo orange_storefront_delivery_area_select_markup('reg_area', $registerDeliveryAreas, true, 'area'); ?>
                    <?php else: ?>
                    <select id="reg_area" name="area" class="js-orange-delivery-area-unavailable" autocomplete="address-level1" disabled aria-describedby="regAreaUnavailableHelp">
                        <option value=""><?php echo htmlspecialchars(t('checkout_select_area'), ENT_QUOTES, 'UTF-8'); ?></option>
                    </select>
                    <p id="regAreaUnavailableHelp" class="track-signup-cta__text" style="margin:0.35rem 0 0;color:#b45309;"><?php echo htmlspecialchars(t('checkout_delivery_areas_unavailable_note'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
                <div class="field track-signup-cta__field">
                    <label for="reg_address"><?php echo htmlspecialchars(t('address'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="reg_address" name="address" autocomplete="street-address" required rows="3" placeholder="<?php echo htmlspecialchars(t('register_placeholder_address'), ENT_QUOTES, 'UTF-8'); ?>"></textarea>
                </div>
                <div class="field track-signup-cta__field">
                    <label for="reg_notes"><?php echo htmlspecialchars(t('notes'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea id="reg_notes" name="notes" rows="2" placeholder="<?php echo htmlspecialchars(t('register_placeholder_notes'), ENT_QUOTES, 'UTF-8'); ?>"></textarea>
                </div>
                <p id="orangeRegisterMsg" class="track-signup-cta__feedback" style="margin-top: 0.75rem;" hidden></p>
                <div class="track-signup-cta__actions">
                    <button type="submit" class="btn track-signup-cta__btn" id="orangeRegisterSubmitBtn"><?php echo htmlspecialchars(t('storefront_register_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
            </form>
            <div id="orangeRegisterMergePanel" class="card-box register-merge-panel" hidden style="margin-top: 1rem; text-align: start;">
                <p class="track-signup-cta__title" style="margin-top: 0;"><?php echo htmlspecialchars(t('storefront_register_title'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p id="orangeRegisterMergeIntro" class="track-signup-cta__text" style="margin-bottom: 0.75rem;"></p>
                <p class="cart-checkout-intro" style="margin: 0 0 0.25rem; font-weight: 600;"><?php echo htmlspecialchars(t('storefront_register_phone_merge_masked_label'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p id="orangeRegisterMergeMasked" dir="ltr" style="margin: 0 0 0.75rem;"></p>
                <p class="cart-checkout-intro" style="margin: 0 0 0.25rem;"><?php echo htmlspecialchars(t('storefront_register_phone_merge_steps'), ENT_QUOTES, 'UTF-8'); ?></p>
                <p style="margin: 0.75rem 0 0.35rem; font-weight: 600;"><?php echo htmlspecialchars(t('storefront_register_phone_merge_token_label'), ENT_QUOTES, 'UTF-8'); ?></p>
                <code id="orangeRegisterMergeToken" dir="ltr" style="display: inline-block; padding: 0.35rem 0.5rem; background: var(--card-bg, #f8fafc); border-radius: 6px; word-break: break-all;"></code>
                <div class="track-signup-cta__actions" style="margin-top: 0.85rem; flex-wrap: wrap; gap: 0.5rem;">
                    <a class="btn track-signup-cta__btn" id="orangeRegisterMergeWa" href="#" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(t('storefront_register_phone_merge_wa_cta'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <button type="button" class="btn btn-secondary" id="orangeRegisterMergeCopyToken"><?php echo htmlspecialchars(t('track_share_reference_copy'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
                <button type="button" class="btn track-signup-cta__btn" id="orangeRegisterMergeApplyBtn" style="margin-top: 1rem;"><?php echo htmlspecialchars(t('storefront_register_phone_merge_apply_btn'), ENT_QUOTES, 'UTF-8'); ?></button>
                <p id="orangeRegisterMergeApplyMsg" class="track-signup-cta__feedback" style="margin-top: 0.75rem;" hidden></p>
            </div>
            </div>
            <script>
            window.ORANGE_DELIVERY_AREAS = <?php echo json_encode($registerDeliveryAreas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            window.ORANGE_REGISTER_MERGE_T = <?php echo json_encode([
                'intro' => t('storefront_register_phone_merge_intro'),
                'apply_err' => t('storefront_merge_apply_err'),
                'copy_ok' => t('track_share_reference_copied'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    if (window.ORANGE_DELIVERY_AREAS && window.ORANGE_DELIVERY_AREAS.length) {
                        if (typeof window.orangeReplaceInputWithDeliveryAreaSelect === 'function') {
                            window.orangeReplaceInputWithDeliveryAreaSelect('reg_area', window.ORANGE_DELIVERY_AREAS);
                        }
                        if (typeof window.orangeEnhanceDeliveryAreaSelect === 'function') {
                            window.orangeEnhanceDeliveryAreaSelect('reg_area', window.ORANGE_DELIVERY_AREAS);
                        }
                    }
                    var form = document.getElementById('orangeRegisterForm');
                    var msg = document.getElementById('orangeRegisterMsg');
                    var mergePanel = document.getElementById('orangeRegisterMergePanel');
                    var mergeIntro = document.getElementById('orangeRegisterMergeIntro');
                    var mergeMasked = document.getElementById('orangeRegisterMergeMasked');
                    var mergeTokEl = document.getElementById('orangeRegisterMergeToken');
                    var mergeWa = document.getElementById('orangeRegisterMergeWa');
                    var mergeCopyBtn = document.getElementById('orangeRegisterMergeCopyToken');
                    var mergeApplyBtn = document.getElementById('orangeRegisterMergeApplyBtn');
                    var mergeApplyMsg = document.getElementById('orangeRegisterMergeApplyMsg');
                    var submitBtn = document.getElementById('orangeRegisterSubmitBtn');
                    var mergeT = window.ORANGE_REGISTER_MERGE_T || {};
                    if (!form || !msg) return;
                var sent = <?php echo json_encode(t('storefront_register_sent'), JSON_UNESCAPED_UNICODE); ?>;
                var cooldown = <?php echo json_encode(t('storefront_register_cooldown'), JSON_UNESCAPED_UNICODE); ?>;
                var already = <?php echo json_encode(t('storefront_register_already_verified'), JSON_UNESCAPED_UNICODE); ?>;
                var err = <?php echo json_encode(t('storefront_register_error'), JSON_UNESCAPED_UNICODE); ?>;
                var reqMsg = (window.APP_T && window.APP_T.checkout_required_fields) || '';
                var badEmail = (window.APP_T && window.APP_T.checkout_invalid_email) || '';
                var badPhone = (window.APP_T && window.APP_T.checkout_invalid_phone) || '';
                var __mergeToken = '';
                function mergeApplyUrl() {
                    var base = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
                    var u =
                        typeof storefrontApiUrl === 'function'
                            ? storefrontApiUrl('/api/auth/apply-phone-merge.php')
                            : String(base || '').replace(/\/+$/, '') + '/api/auth/apply-phone-merge.php';
                    var L =
                        typeof window.APP_LANG === 'string' ? window.APP_LANG.trim().toLowerCase() : '';
                    if (L && ['en', 'ar', 'fil', 'hi'].indexOf(L) !== -1) {
                        u += (u.indexOf('?') === -1 ? '?' : '&') + 'lang=' + encodeURIComponent(L);
                    }
                    return u;
                }
                if (mergeCopyBtn && mergeTokEl) {
                    mergeCopyBtn.addEventListener('click', function () {
                        var t = mergeTokEl.textContent || '';
                        if (!t) return;
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(t).then(function () {
                                mergeCopyBtn.textContent = mergeT.copy_ok || '';
                                setTimeout(function () {
                                    mergeCopyBtn.textContent = <?php echo json_encode(t('track_share_reference_copy'), JSON_UNESCAPED_UNICODE); ?>;
                                }, 1600);
                            });
                        }
                    });
                }
                if (mergeApplyBtn) {
                    mergeApplyBtn.addEventListener('click', function () {
                        if (!mergeApplyMsg) return;
                        mergeApplyMsg.hidden = false;
                        mergeApplyMsg.textContent = '';
                        var em = (document.getElementById('reg_email') || {}).value.trim() || '';
                        if (!__mergeToken || !em) {
                            mergeApplyMsg.textContent = reqMsg;
                            return;
                        }
                        fetch(mergeApplyUrl(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ merge_token: __mergeToken, email: em, lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en' })
                        })
                            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                            .then(function (x) {
                                if (x.ok && x.j && x.j.success) {
                                    mergeApplyMsg.textContent = x.j.message || '';
                                    if (mergePanel) mergePanel.hidden = true;
                                    if (form) form.reset();
                                    if (submitBtn) submitBtn.disabled = false;
                                    __mergeToken = '';
                                    return;
                                }
                                mergeApplyMsg.textContent =
                                    typeof window.orangeStorefrontRegisterApiError === 'function'
                                        ? window.orangeStorefrontRegisterApiError(x.j, mergeT.apply_err || '')
                                        : mergeT.apply_err || '';
                            })
                            .catch(function () {
                                mergeApplyMsg.textContent = (window.APP_T && window.APP_T.api_request_failed) || mergeT.apply_err || '';
                            });
                    });
                }
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    msg.hidden = false;
                    msg.textContent = '';
                    var email = (document.getElementById('reg_email') || {}).value.trim() || '';
                    var name = (document.getElementById('reg_name') || {}).value.trim() || '';
                    var phoneRaw = (document.getElementById('reg_phone') || {}).value.trim() || '';
                    var regCcEarly =
                        typeof window.orangeStorefrontPhoneCountryDigits === 'function'
                            ? window.orangeStorefrontPhoneCountryDigits('reg_phone_country')
                            : null;
                    if (regCcEarly === null || regCcEarly === undefined) {
                        msg.textContent = (window.APP_T && window.APP_T.phone_country_required) || reqMsg;
                        return;
                    }
                    var areaEl = document.getElementById('reg_area');
                    var area = '';
                    var deliveryAreaId = 0;
                    if (areaEl && areaEl.tagName === 'SELECT' && areaEl.disabled) {
                        msg.textContent =
                            (window.APP_T && window.APP_T.checkout_delivery_areas_unavailable) || reqMsg;
                        return;
                    }
                    if (areaEl && areaEl.tagName === 'SELECT') {
                        deliveryAreaId = parseInt(areaEl.value, 10) || 0;
                        var optA = areaEl.options[areaEl.selectedIndex];
                        area = optA ? String(optA.textContent || '').trim() : '';
                    } else if (areaEl) {
                        area = areaEl.value.trim() || '';
                    }
                    var address = (document.getElementById('reg_address') || {}).value.trim() || '';
                    var notes = (document.getElementById('reg_notes') || {}).value.trim() || '';
                    if (!name || !phoneRaw || !email || !address) {
                        msg.textContent = reqMsg;
                        return;
                    }
                    if (areaEl && areaEl.tagName === 'SELECT') {
                        if (!deliveryAreaId) {
                            msg.textContent = (window.APP_T && window.APP_T.checkout_delivery_area_required) || reqMsg;
                            return;
                        }
                    } else if (!area) {
                        msg.textContent = reqMsg;
                        return;
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        msg.textContent = badEmail;
                        return;
                    }
                    var digits = phoneRaw.replace(/\D/g, '');
                    if (digits.length < 5) {
                        msg.textContent = reqMsg;
                        return;
                    }
                    var regCc =
                        typeof window.orangeStorefrontPhoneCountryDigits === 'function'
                            ? window.orangeStorefrontPhoneCountryDigits('reg_phone_country')
                            : null;
                    var regIntl =
                        (document.getElementById('reg_phone_country') || {}).value === '__intl__';
                    var phoneNorm =
                        typeof window.orangeNormalizeCustomerPhone === 'function'
                            ? window.orangeNormalizeCustomerPhone(
                                  phoneRaw,
                                  regIntl ? null : regCc,
                                  regIntl
                              )
                            : null;
                    if (!phoneNorm) {
                        msg.textContent = badPhone || reqMsg;
                        return;
                    }
                    var base = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
                    var verifyUrl =
                        typeof storefrontApiUrl === 'function'
                            ? storefrontApiUrl('/api/auth/request-email-verify.php')
                            : (function () {
                                  var u =
                                      String(base || '')
                                          .replace(/\/+$/, '') + '/api/auth/request-email-verify.php';
                                  var L =
                                      typeof window.APP_LANG === 'string'
                                          ? window.APP_LANG.trim().toLowerCase()
                                          : '';
                                  if (L && ['en', 'ar', 'fil', 'hi'].indexOf(L) !== -1) {
                                      u += '?lang=' + encodeURIComponent(L);
                                  }
                                  return u;
                              })();
                    fetch(verifyUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: email,
                            name: name,
                            phone: phoneNorm,
                            phone_country: regIntl ? '__intl__' : regCc || '',
                            area: area,
                            delivery_area_id: deliveryAreaId,
                            address: address,
                            notes: notes,
                            channel: typeof window.APP_CHANNEL_SLUG === 'string' ? window.APP_CHANNEL_SLUG : <?php echo json_encode(orange_storefront_default_channel_slug($pdo), JSON_UNESCAPED_UNICODE); ?>,
                            lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en'
                        })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (x) {
                        if (mergePanel) mergePanel.hidden = true;
                        if (mergeApplyMsg) {
                            mergeApplyMsg.hidden = true;
                            mergeApplyMsg.textContent = '';
                        }
                        __mergeToken = '';
                        if (submitBtn) submitBtn.disabled = false;
                        if (x.ok && x.j && x.j.merge_required) {
                            msg.hidden = false;
                            msg.textContent = String((x.j && x.j.message) || mergeT.intro || '');
                            if (mergePanel && mergeIntro && mergeMasked && mergeTokEl && mergeWa) {
                                mergeIntro.textContent = mergeT.intro || '';
                                mergeMasked.textContent = String((x.j && x.j.existing_email_masked) || '');
                                var mt = String((x.j && x.j.merge_token) || '');
                                __mergeToken = mt;
                                mergeTokEl.textContent = mt;
                                var wh = String((x.j && x.j.whatsapp_href) || '');
                                mergeWa.setAttribute('href', wh || '#');
                                if (!wh) mergeWa.setAttribute('aria-disabled', 'true');
                                mergePanel.hidden = false;
                                if (submitBtn) submitBtn.disabled = true;
                            }
                            if (x.j.channel && /^[a-z0-9\-]+$/i.test(String(x.j.channel))) {
                                try {
                                    if (typeof window.orangeSfPersistChannel === 'function') {
                                        window.orangeSfPersistChannel(String(x.j.channel).toLowerCase());
                                    } else {
                                        localStorage.setItem('orange_storefront_channel', String(x.j.channel).toLowerCase());
                                    }
                                } catch (e1) {}
                            }
                            return;
                        }
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
                            msg.textContent = x.j.already_verified ? already : (x.j.cooldown ? cooldown : sent);
                            return;
                        }
                        msg.textContent = typeof window.orangeStorefrontRegisterApiError === 'function'
                            ? window.orangeStorefrontRegisterApiError(x.j, err)
                            : ((x.j && x.j.message) ? String(x.j.message) : err);
                    }).catch(function () {
                        msg.textContent = (window.APP_T && window.APP_T.api_request_failed) || err;
                    });
                });
                });
            })();
            </script>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php
include __DIR__ . '/../includes/footer.php';
