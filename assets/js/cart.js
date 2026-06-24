/** تضمين بند في الطلب الحالي عند وجود أكثر من سطر في العربة (سياسة س30). */
var orangeCartLineIncluded = Object.create(null);

function orangeCartLineKey(it) {
    return [
        parseInt(it.id, 10) || 0,
        parseInt(it.variant_id || 0, 10) || 0,
        it.color != null ? String(it.color) : '',
        it.size != null ? String(it.size) : '',
    ].join('\x1e');
}

function orangeCartAmendActive() {
    const a = window.__orangePendingAmend;
    return !!(a && a.order_number);
}

function orangeCartLineChoiceApplies(items) {
    return Array.isArray(items) && items.length > 1 && !orangeCartAmendActive();
}

function orangeCartLineIsIncluded(it) {
    const k = orangeCartLineKey(it);
    if (!Object.prototype.hasOwnProperty.call(orangeCartLineIncluded, k)) {
        return true;
    }
    return orangeCartLineIncluded[k] !== false;
}

function orangeCartGetSelectedItems(items) {
    return items.filter(orangeCartLineIsIncluded);
}

function orangeCartClearLineChoiceState() {
    Object.keys(orangeCartLineIncluded).forEach((k) => delete orangeCartLineIncluded[k]);
}

function orangeCartRefreshBasketTotalsClientAndPreview() {
    const items = getCart();
    const mainEl = document.getElementById('cartMainTotals');
    if (!mainEl || !mainEl.parentNode) {
        orangeRenderCheckoutMiniSummary();
        return;
    }
    const choice = orangeCartLineChoiceApplies(items);
    const sel = choice ? orangeCartGetSelectedItems(items) : items;
    if (choice && !sel.length) {
        const wrap0 = document.createElement('div');
        wrap0.innerHTML = orangeHtmlCartMainTotals(0, 0, 0, 0, 0);
        const next0 = wrap0.firstElementChild;
        if (next0) {
            mainEl.parentNode.replaceChild(next0, mainEl);
        }
        orangeCancelCheckoutPreview();
        orangeRenderCheckoutMiniSummary();
        return;
    }
    const sub = orangeCartClientSubtotalFromItems(sel);
    const wrap = document.createElement('div');
    wrap.innerHTML = orangeHtmlCartMainTotals(sub, 0, 0, 0, sub);
    const next = wrap.firstElementChild;
    if (next) {
        mainEl.parentNode.replaceChild(next, mainEl);
    }
    orangeRenderCheckoutMiniSummary();
}

function orangeCartSetLineIncluded(idx, checked) {
    const items = getCart();
    const it = items[idx];
    if (!it) {
        return;
    }
    orangeCartLineIncluded[orangeCartLineKey(it)] = !!checked;
    orangeCartRefreshBasketTotalsClientAndPreview();
    orangeSyncCartProceedBtn();
}

function orangeCartSelectAllLines(flag) {
    const items = getCart();
    items.forEach((it) => {
        orangeCartLineIncluded[orangeCartLineKey(it)] = !!flag;
    });
    renderCart();
}

window.orangeCartSetLineIncluded = orangeCartSetLineIncluded;
window.orangeCartSelectAllLines = orangeCartSelectAllLines;

function cartLinesMatch(a, b) {
    if (parseInt(a.id, 10) !== parseInt(b.id, 10)) {
        return false;
    }
    const va = parseInt(a.variant_id || 0, 10);
    const vb = parseInt(b.variant_id || 0, 10);
    if (va > 0 && vb > 0) {
        return va === vb;
    }
    const ca = a.color != null ? String(a.color) : '';
    const cb = b.color != null ? String(b.color) : '';
    const sa = a.size != null ? String(a.size) : '';
    const sb = b.size != null ? String(b.size) : '';
    return ca === cb && sa === sb;
}

function getCartStorageKey() {
    if (typeof window.orangeSfCartKey === 'function') {
        return window.orangeSfCartKey();
    }
    return 'orange_sf_cart_orange';
}

function getCart() {
    try {
        const key = getCartStorageKey();
        const raw = localStorage.getItem(key);
        if (raw) {
            return JSON.parse(raw);
        }
        const leg = localStorage.getItem('cart');
        if (leg) {
            const parsed = JSON.parse(leg);
            if (Array.isArray(parsed)) {
                localStorage.setItem(key, leg);
                localStorage.removeItem('cart');
                return parsed;
            }
        }
        return [];
    } catch (e) {
        return [];
    }
}

function setCart(items) {
    localStorage.setItem(getCartStorageKey(), JSON.stringify(items));
}

/** يبقى وضع تعديل الطلب (س22) عبر تحميل صفحة — مثلاً من التتبع إلى العربة. */
var ORANGE_SF_PENDING_AMEND_KEY = 'orange_sf_pending_amend';

function orangePendingAmendToStorage(obj) {
    try {
        if (obj && obj.order_number) {
            sessionStorage.setItem(
                ORANGE_SF_PENDING_AMEND_KEY,
                JSON.stringify({
                    order_number: String(obj.order_number),
                    phone: String(obj.phone || ''),
                })
            );
        }
    } catch (e) {}
}

function orangeClearPendingAmendStorage() {
    try {
        sessionStorage.removeItem(ORANGE_SF_PENDING_AMEND_KEY);
    } catch (e) {}
}

function orangeRestorePendingAmendFromStorage() {
    try {
        if (window.__orangePendingAmend && window.__orangePendingAmend.order_number) {
            return;
        }
        const raw = sessionStorage.getItem(ORANGE_SF_PENDING_AMEND_KEY);
        if (!raw) {
            return;
        }
        const o = JSON.parse(raw);
        if (o && o.order_number) {
            window.__orangePendingAmend = {
                order_number: String(o.order_number),
                phone: String(o.phone || ''),
            };
        }
    } catch (e) {
        orangeClearPendingAmendStorage();
    }
}

function normalizeCartDuplicates() {
    const items = getCart();
    if (items.length < 2) {
        return items;
    }
    const out = [];
    for (let i = 0; i < items.length; i++) {
        const it = items[i];
        let found = false;
        for (let j = 0; j < out.length; j++) {
            if (cartLinesMatch(out[j], it)) {
                out[j].qty = parseInt(out[j].qty, 10) + parseInt(it.qty, 10);
                found = true;
                break;
            }
        }
        if (!found) {
            out.push({ ...it });
        }
    }
    if (out.length !== items.length) {
        setCart(out);
    }
    return getCart();
}

function escCartHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function escCartAttr(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** مسار عام تحت جذر المتجر (بدون ?lang=) — للصور والأصول الثابتة. */
function orangeStorefrontPublicPath(path) {
    const raw = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.startsWith('/') ? path : '/' + path;
    return base + p;
}

/**
 * صورة بند العربة: يفضّل ‎.webp‎ بنفس الجذر عبر ‎<picture>‎ إن كان الملف الأصلي ليس webp.
 */
function orangeCartProductImageMarkup(item) {
    let name = String(item && item.image != null ? item.image : '')
        .replace(/"/g, '')
        .trim();
    if (!name) {
        return '<span class="cart-item-thumb-placeholder" aria-hidden="true"></span>';
    }
    name = name.split(/[/\\]/).pop() || '';
    if (!name || name === '.' || name === '..') {
        return '<span class="cart-item-thumb-placeholder" aria-hidden="true"></span>';
    }
    const prefix = orangeStorefrontPublicPath('/uploads/products/');
    const lower = name.toLowerCase();
    const alt = escCartHtml(item.name || '');
    if (lower.endsWith('.webp')) {
        const src = prefix + encodeURIComponent(name);
        return (
            '<img src="' +
            escCartAttr(src) +
            '" alt="' +
            alt +
            '" loading="lazy" decoding="async">'
        );
    }
    const stem = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : name;
    const webpSrc = prefix + encodeURIComponent(stem + '.webp');
    const fallbackSrc = prefix + encodeURIComponent(name);
    return (
        '<picture>' +
        '<source type="image/webp" srcset="' +
        escCartAttr(webpSrc) +
        '">' +
        '<img src="' +
        escCartAttr(fallbackSrc) +
        '" alt="' +
        alt +
        '" loading="lazy" decoding="async">' +
        '</picture>'
    );
}

function storefrontApiUrl(path) {
    const raw = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.startsWith('/') ? path : '/' + path;
    let url = base + p;
    const lang = typeof window.APP_LANG === 'string' ? window.APP_LANG.trim().toLowerCase() : '';
    if (lang && ['en', 'ar', 'fil', 'hi'].indexOf(lang) !== -1) {
        url += (url.indexOf('?') !== -1 ? '&' : '?') + 'lang=' + encodeURIComponent(lang);
    }
    return url;
}

async function orangeHydrateCartVariantDisplayLang(itemsModel) {
    if (!itemsModel || !itemsModel.length) {
        return;
    }
    const lang = typeof window.APP_LANG === 'string' ? window.APP_LANG : 'ar';
    const ids = [];
    itemsModel.forEach((it) => {
        const v = parseInt(it.variant_id || 0, 10) || 0;
        if (v && ids.indexOf(v) === -1) {
            ids.push(v);
        }
    });
    if (!ids.length || typeof fetch !== 'function') {
        return;
    }
    try {
        const response = await fetch(storefrontApiUrl('/api/products/variant-labels.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ variant_ids: ids, lang: lang }),
        });
        const data = await response.json();
        if (!data.success || !data.labels) {
            return;
        }
        const L = data.labels;
        document.querySelectorAll('.cart-item-card[data-variant-id]').forEach((card) => {
            const vid = parseInt(card.getAttribute('data-variant-id') || '0', 10) || 0;
            const row = L[String(vid)];
            if (!row) {
                return;
            }
            const host = card.querySelector('.js-cart-vlabel-host');
            if (!host) {
                return;
            }
            const T = window.APP_T || {};
            let h = '';
            if (row.color_part || row.pattern_part) {
                h +=
                    '<p class="cart-item-variant"><span class="cart-meta-k">' +
                    escCartHtml(T.color || '') +
                    '</span> ';
                if (row.color_part) {
                    h += '<span class="cart-v-c">' + escCartHtml(row.color_part) + '</span>';
                }
                if (row.pattern_part) {
                    h +=
                        '<span class="cart-v-sep"> </span><span class="cart-v-p">' +
                        escCartHtml(row.pattern_part) +
                        '</span>';
                }
                h += '</p>';
            } else if (row.color) {
                h +=
                    '<p class="cart-item-variant"><span class="cart-meta-k">' +
                    escCartHtml(T.color || '') +
                    '</span> ' +
                    escCartHtml(row.color) +
                    '</p>';
            }
            if (row.size) {
                h +=
                    '<p class="cart-item-variant"><span class="cart-meta-k">' +
                    escCartHtml(T.size || '') +
                    '</span> ' +
                    escCartHtml(row.size) +
                    '</p>';
            }
            if (h) {
                host.innerHTML = h;
            }
        });
    } catch (e) {
        /* offline */
    }
}

function ensureOrangeToast() {
    if (document.getElementById('orangeSfToast')) {
        return;
    }
    const el = document.createElement('div');
    el.id = 'orangeSfToast';
    el.className = 'orange-sf-toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.appendChild(el);
}

function orangeShowToast(message, durationMs) {
    ensureOrangeToast();
    const toast = document.getElementById('orangeSfToast');
    if (!toast) {
        return;
    }
    const ms = typeof durationMs === 'number' && durationMs > 0 ? durationMs : 2400;
    toast.textContent = String(message || '');
    toast.classList.remove('is-visible');
    void toast.offsetWidth;
    toast.classList.add('is-visible');
    clearTimeout(window.__orangeSfToastTimer);
    window.__orangeSfToastTimer = setTimeout(() => {
        toast.classList.remove('is-visible');
    }, ms);
}

window.orangeShowToast = orangeShowToast;

function orangeCheckoutApiMessage(result) {
    const T = window.APP_T || {};
    if (!result || typeof result !== 'object') {
        return '';
    }
    const c = result.code ? String(result.code) : '';
    if (c === 'mail_failed' && T.storefront_register_mail_failed) {
        return T.storefront_register_mail_failed;
    }
    if ((c === 'rate_limited' || c === 'cooldown') && T.track_email_summary_rate_limit) {
        return T.track_email_summary_rate_limit;
    }
    if (c === 'service_unavailable' && T.storefront_register_service_unavailable) {
        return T.storefront_register_service_unavailable;
    }
    if (c === 'invalid_email' && T.checkout_invalid_email) {
        return T.checkout_invalid_email;
    }
    if (c === 'missing_fields' && T.checkout_required_fields) {
        return T.checkout_required_fields;
    }
    if (c === 'phone_country_required' && T.phone_country_required) {
        return T.phone_country_required;
    }
    if (c === 'invalid_phone' && T.checkout_invalid_phone) {
        return T.checkout_invalid_phone;
    }
    if (c === 'otp_service_unavailable' && T.checkout_otp_service_unavailable) {
        return T.checkout_otp_service_unavailable;
    }
    if (c === 'otp_account_not_found' && T.checkout_otp_account_not_found) {
        return T.checkout_otp_account_not_found;
    }
    if (c === 'otp_send_failed' && T.checkout_otp_send_failed) {
        return T.checkout_otp_send_failed;
    }
    if (c === 'otp_not_requested' && T.checkout_otp_not_requested) {
        return T.checkout_otp_not_requested;
    }
    if (c === 'otp_expired' && T.checkout_otp_expired) {
        return T.checkout_otp_expired;
    }
    if ((c === 'otp_invalid' || c === 'otp_invalid_format') && T.checkout_otp_invalid) {
        return T.checkout_otp_invalid;
    }
    if (c === 'otp_max_attempts' && T.checkout_otp_max_attempts) {
        return T.checkout_otp_max_attempts;
    }
    if (c === 'invalid_phone' && T.storefront_register_invalid_phone) {
        return T.storefront_register_invalid_phone;
    }
    if (c === 'invalid_delivery_area' && T.checkout_delivery_area_required) {
        return T.checkout_delivery_area_required;
    }
    if (c === 'order_link_incomplete' && T.track_signup_order_required) {
        return T.track_signup_order_required;
    }
    if (c === 'cart_items_required' && T.checkout_cart_items_required) {
        return T.checkout_cart_items_required;
    }
    if (c === 'invalid_channel' && T.checkout_invalid_channel) {
        return T.checkout_invalid_channel;
    }
    if (c === 'queue_row_missing' && T.checkout_internal_error) {
        return T.checkout_internal_error;
    }
    if (c === 'checkout_busy' && T.checkout_queue_busy) {
        return T.checkout_queue_busy;
    }
    if (c === 'intake_invalid_token' && T.intake_invalid_token) {
        return T.intake_invalid_token;
    }
    if (c === 'intake_queue_unavailable' && T.intake_queue_unavailable) {
        return T.intake_queue_unavailable;
    }
    if (c === 'intake_not_found' && T.intake_not_found) {
        return T.intake_not_found;
    }
    if (c === 'queue_timeout' && T.checkout_queue_timeout) {
        return T.checkout_queue_timeout;
    }
    if (c === 'processing_failed') {
        if (result.message && String(result.message).trim() !== '') {
            return String(result.message);
        }
        return T.checkout_failed_generic || '';
    }
    if ((c === 'invalid_input' || c === 'order_lookup_required') && T.track_missing_fields) {
        return T.track_missing_fields;
    }
    if (
        (c === 'not_found' ||
            c === 'order_lookup_not_found' ||
            c === 'order_not_found' ||
            c === 'order_link_mismatch' ||
            c === 'signup_phone_mismatch') &&
        T.track_signup_order_mismatch
    ) {
        return T.track_signup_order_mismatch;
    }
    if (c === 'cancel_not_allowed' && T.customer_cancel_not_allowed) {
        return T.customer_cancel_not_allowed;
    }
    if (c === 'amend_not_allowed' && T.customer_amend_not_allowed) {
        return T.customer_amend_not_allowed;
    }
    if (c === 'amend_failed') {
        if (result.message && String(result.message).trim() !== '') {
            return String(result.message);
        }
        return T.checkout_failed_generic || '';
    }
    if (c === 'product_invalid_id' && T.product_invalid_id) {
        return T.product_invalid_id;
    }
    if (c === 'product_not_found' && T.product_not_found) {
        return T.product_not_found;
    }
    if (c === 'merge_wa_not_confirmed' && T.storefront_merge_wa_not_confirmed) {
        return T.storefront_merge_wa_not_confirmed;
    }
    if (c === 'merge_email_mismatch' && T.storefront_merge_email_mismatch) {
        return T.storefront_merge_email_mismatch;
    }
    if (c === 'merge_invalid_token' && T.storefront_merge_invalid_token) {
        return T.storefront_merge_invalid_token;
    }
    if (c === 'merge_failed' && T.storefront_merge_apply_err) {
        return T.storefront_merge_apply_err;
    }
    if (c === 'server_error') {
        if (result.message && String(result.message).trim() !== '') {
            return String(result.message);
        }
        return T.api_request_failed || T.checkout_internal_error || T.checkout_failed_generic || '';
    }
    if (result.message && String(result.message).trim() !== '') {
        return String(result.message);
    }
    return '';
}

window.orangeCheckoutApiMessage = orangeCheckoutApiMessage;

function orangeAnimateCartPulse() {
    document.querySelectorAll('[data-orange-cart-link]').forEach((el) => {
        el.classList.remove('orange-cart-pulse');
        void el.offsetWidth;
        el.classList.add('orange-cart-pulse');
    });
}

window.orangeAnimateCartPulse = orangeAnimateCartPulse;

function orangeCartProceedToCheckout() {
    normalizeCartDuplicates();
    const items = getCart();
    const T = window.APP_T || {};
    if (!items.length) {
        orangeShowToast(T.empty_cart || '', 2800);
        return;
    }
    if (orangeCartLineChoiceApplies(items) && !orangeCartGetSelectedItems(items).length) {
        orangeShowToast(T.cart_no_lines_selected_for_order || T.checkout_cart_items_required || '', 3200);
        return;
    }
    orangeOpenCheckoutOverlay();
}

window.orangeCartProceedToCheckout = orangeCartProceedToCheckout;

/**
 * كشاشة الإتمام المؤقتة: نموذج البيانات لا يعيش في تاب «طلباتي» بل يُفتح overlay عند «تنفيذ الطلب».
 * المسجّل: تُعبّأ بياناته المحفوظة (الاسم/الجوال/المنطقة/العنوان) ويُطلب تأكيد العنوان. الزائر: فارغ.
 */
function orangeOpenCheckoutOverlay() {
    const ov = document.getElementById('cartCheckoutOverlay');
    if (!ov) {
        return;
    }
    if (typeof orangeRenderCheckoutMiniSummary === 'function') {
        try {
            orangeRenderCheckoutMiniSummary();
        } catch (e) {}
    }
    orangeCheckoutPrefillRegistered();
    if (typeof orangeSyncAmendCheckoutSendLabel === 'function') {
        try {
            orangeSyncAmendCheckoutSendLabel();
        } catch (e2) {}
    }
    ov.hidden = false;
    ov.setAttribute('aria-hidden', 'false');
    if (document.body) {
        document.body.classList.add('cart-overlay-open');
    }
    requestAnimationFrame(() => {
        const dialog = ov.querySelector('.cart-checkout-overlay__dialog');
        if (dialog && typeof dialog.scrollTo === 'function') {
            try {
                dialog.scrollTo(0, 0);
            } catch (e3) {}
        }
        const nameEl = document.getElementById('customer_name');
        if (nameEl) {
            window.setTimeout(() => {
                try {
                    nameEl.focus();
                } catch (e4) {}
            }, 160);
        }
    });
}

function orangeCloseCheckoutOverlay() {
    const ov = document.getElementById('cartCheckoutOverlay');
    if (ov) {
        ov.hidden = true;
        ov.setAttribute('aria-hidden', 'true');
    }
    orangeSyncOverlayBodyLock();
}

function orangeSyncOverlayBodyLock() {
    if (!document.body) {
        return;
    }
    const ov = document.getElementById('cartCheckoutOverlay');
    const cf = document.getElementById('cartCheckoutConfirm');
    const anyOpen = (ov && !ov.hidden) || (cf && !cf.hidden);
    document.body.classList.toggle('cart-overlay-open', !!anyOpen);
}

/** المسجّل فقط: تعبئة الحقول الفارغة من بياناته المحفوظة (لا نطمس ما أدخله العميل). */
function orangeCheckoutPrefillRegistered() {
    const acc = window.ORANGE_CART_SF_ACCOUNT || {};
    if (!acc || !acc.logged_in) {
        return;
    }
    const setIfEmpty = (id, val) => {
        const el = document.getElementById(id);
        if (el && !String(el.value || '').trim() && val) {
            el.value = String(val);
        }
    };
    setIfEmpty('customer_name', acc.customer_name);
    setIfEmpty('customer_address', acc.customer_address);
    setIfEmpty('customer_notes', acc.customer_notes);

    const phoneEl = document.getElementById('customer_phone');
    if (phoneEl && !String(phoneEl.value || '').trim()) {
        let phoneSet = false;
        if (
            acc.customer_phone_country_dial &&
            acc.customer_phone_national &&
            typeof window.orangeStorefrontSetPhoneCountryByDial === 'function' &&
            window.orangeStorefrontSetPhoneCountryByDial(
                'customer_phone_country',
                acc.customer_phone_country_dial
            )
        ) {
            phoneEl.value = String(acc.customer_phone_national);
            phoneSet = true;
        }
        if (!phoneSet && acc.customer_phone) {
            if (typeof window.orangeStorefrontSetComboboxValue === 'function') {
                window.orangeStorefrontSetComboboxValue('customer_phone_country', '__intl__');
            }
            phoneEl.value = String(acc.customer_phone);
        }
    }

    const areaEl = document.getElementById('customer_area');
    if (
        areaEl &&
        areaEl.tagName === 'SELECT' &&
        !areaEl.disabled &&
        !areaEl.value &&
        acc.customer_delivery_area_id &&
        typeof window.orangeStorefrontSetComboboxValue === 'function'
    ) {
        window.orangeStorefrontSetComboboxValue(
            'customer_area',
            String(acc.customer_delivery_area_id)
        );
    }

    const hint = document.getElementById('cartCheckoutRegisteredHint');
    if (hint) {
        hint.hidden = false;
    }
}

/** رسالة التأكيد (موافق/إلغاء) قبل الإرسال الفعلي. */
function orangeCheckoutConfirmSubmit() {
    const c = document.getElementById('cartCheckoutConfirm');
    if (!c) {
        if (typeof window.sendOrderNow === 'function') {
            window.sendOrderNow();
        }
        return;
    }
    c.hidden = false;
    c.setAttribute('aria-hidden', 'false');
    orangeSyncOverlayBodyLock();
    const ok = document.getElementById('cartCheckoutConfirmOk');
    if (ok) {
        window.setTimeout(() => {
            try {
                ok.focus();
            } catch (e) {}
        }, 80);
    }
}

function orangeCloseCheckoutConfirm() {
    const c = document.getElementById('cartCheckoutConfirm');
    if (c) {
        c.hidden = true;
        c.setAttribute('aria-hidden', 'true');
    }
    orangeSyncOverlayBodyLock();
}

window.orangeOpenCheckoutOverlay = orangeOpenCheckoutOverlay;
window.orangeCloseCheckoutOverlay = orangeCloseCheckoutOverlay;
window.orangeCheckoutPrefillRegistered = orangeCheckoutPrefillRegistered;
window.orangeCheckoutConfirmSubmit = orangeCheckoutConfirmSubmit;
window.orangeCloseCheckoutConfirm = orangeCloseCheckoutConfirm;

function orangeSyncCartProceedBtn() {
    const btn = document.getElementById('cartProceedBtn');
    if (!btn) {
        return;
    }
    const items = getCart();
    const ok =
        items.length > 0 &&
        (!orangeCartLineChoiceApplies(items) || orangeCartGetSelectedItems(items).length > 0);
    btn.disabled = !ok;
    /* السلة الفارغة تعرض زر «متابعة التسوق» داخلها؛ نخفي تذييل «تنفيذ الطلب» (الثابت على الموبايل)
       حتى لا يتداخل الزرّان فوق بعضهما. */
    const footer = btn.closest('.cart-basket-footer');
    if (footer) {
        footer.hidden = items.length === 0;
    }
}

/** س22: يظهر في صفحة العربة عند تعديل طلب قائم؛ يُمسح مع إفراغ السلة. */
function orangeSyncAmendModeBanner() {
    const el = document.getElementById('cartAmendModeBanner');
    if (el) {
        const amend = window.__orangePendingAmend;
        const T = window.APP_T || {};
        const tpl = T.customer_amend_mode_banner || '';
        if (amend && amend.order_number && tpl) {
            el.textContent = tpl.replace(/\{order\}/g, String(amend.order_number));
            el.removeAttribute('hidden');
        } else {
            el.textContent = '';
            el.setAttribute('hidden', '');
        }
    }
    orangeSyncAmendCheckoutSendLabel();
}

function orangeSyncAmendCheckoutSendLabel() {
    const btn = document.getElementById('cartCheckoutSendBtn');
    if (!btn) {
        return;
    }
    const T = window.APP_T || {};
    const def = T.send_order || '';
    const am = T.customer_amend_send_order || '';
    const amend = window.__orangePendingAmend;
    if (amend && amend.order_number && am) {
        btn.textContent = am;
    } else if (def) {
        btn.textContent = def;
    }
}

function cartEmptyStateHtml() {
    const T = window.APP_T || {};
    const title = T.empty_cart || '';
    const sub = T.cart_empty_subtitle || '';
    const home = typeof window.ORANGE_CART_HOME === 'string' ? window.ORANGE_CART_HOME.trim() : '';
    const cta =
        home && T.cart_continue_shopping
            ? '<a class="btn btn-secondary cart-empty-cta" href="' +
              escCartAttr(home) +
              '">' +
              escCartHtml(T.cart_continue_shopping) +
              '</a>'
            : '';
    return (
        '<div class="cart-empty-block">' +
        '<div class="cart-empty-icon" aria-hidden="true">🛒</div>' +
        '<div class="cart-empty-title">' +
        escCartHtml(title) +
        '</div>' +
        (sub ? '<div class="cart-empty-text">' + escCartHtml(sub) + '</div>' : '') +
        cta +
        '</div>'
    );
}

function orangeSyncCartTabCount() {
    const badge = document.getElementById('cartTabBasketCount');
    if (!badge) {
        return;
    }
    const n = getCart().length;
    if (n > 0) {
        badge.hidden = false;
        badge.textContent = String(n);
        badge.setAttribute('aria-hidden', 'false');
    } else {
        badge.hidden = true;
        badge.textContent = '0';
        badge.setAttribute('aria-hidden', 'true');
    }
}

let __orangeCartPreviewTimer = null;
let __orangeCartPreviewSeq = 0;
let __orangeLoyaltyRedeemPoints = 0;

function orangeUpdateLoyaltyRedeemUI(loyalty) {
    const box = document.getElementById('cartLoyaltyRedeemBox');
    if (!box) {
        return;
    }
    if (!loyalty || loyalty.active !== true || !(Number(loyalty.balance) > 0)) {
        box.style.display = 'none';
        box.innerHTML = '';
        __orangeLoyaltyRedeemPoints = 0;
        return;
    }
    const T = window.APP_T || {};
    const balance = Number(loyalty.balance) || 0;
    const redeemablePoints = Number(loyalty.redeemable_points) || 0;
    const appliedPoints = Number(loyalty.redeem_points) || 0;
    const appliedValue = Number(loyalty.redeem_value) || 0;
    __orangeLoyaltyRedeemPoints = appliedPoints;
    const checked = appliedPoints > 0 ? ' checked' : '';
    const disabled = redeemablePoints > 0 ? '' : ' disabled';
    const lblBalance = (T.loyalty_balance_label || 'رصيد نقاطك') + ': ' + balance;
    let lblUse = T.loyalty_use_points_label || 'استخدم نقاطي في هذا الطلب';
    if (redeemablePoints > 0) {
        lblUse += ' (' + redeemablePoints + ' = ' + formatMoney(redeemablePoints * (Number(loyalty.point_value) || 0)) + ')';
    }
    let appliedHtml = '';
    if (appliedValue > 0) {
        appliedHtml = '<p class="cart-loyalty-applied" style="margin:0.35rem 0 0;color:#15803d;">'
            + (T.loyalty_applied_label || 'خصم النقاط المطبّق') + ': −' + formatMoney(appliedValue)
            + ' (' + appliedPoints + ')</p>';
    }
    box.innerHTML =
        '<div class="cart-loyalty-redeem-inner" style="border:1px solid #e2e8f0;border-radius:8px;padding:0.6rem 0.8rem;">'
        + '<p style="margin:0 0 0.4rem;font-weight:700;">' + lblBalance + '</p>'
        + '<label style="display:flex;gap:0.5rem;align-items:center;cursor:pointer;">'
        + '<input type="checkbox" id="cartLoyaltyRedeemToggle"' + checked + disabled + '>'
        + '<span>' + lblUse + '</span></label>'
        + appliedHtml
        + '</div>';
    box.style.display = '';
    const toggle = document.getElementById('cartLoyaltyRedeemToggle');
    if (toggle) {
        toggle.addEventListener('change', function () {
            __orangeLoyaltyRedeemPoints = this.checked ? redeemablePoints : 0;
            orangeRunCheckoutPreview();
        });
    }
}

function orangeCancelCheckoutPreview() {
    if (__orangeCartPreviewTimer) {
        clearTimeout(__orangeCartPreviewTimer);
        __orangeCartPreviewTimer = null;
    }
    __orangeCartPreviewSeq += 1;
    orangeUpdateRegisterPromoTeaser(null);
    orangeUpdateGiftBogoRegisterUnlockTeaser(false, false, false);
    orangeUpdateCartGiftPromotionUI(null);
    orangeUpdateCartBogoPromotionUI(null);
}

function orangeUpdateCartGiftPromotionUI(gift) {
    const wrap = document.getElementById('cartGiftPromoHost');
    const inner = document.getElementById('cartGiftPromoInner');
    if (!wrap || !inner) {
        return;
    }
    try {
        window.__orangeLastGiftPromotion = gift && typeof gift === 'object' ? gift : null;
    } catch (eG) {
        window.__orangeLastGiftPromotion = null;
    }
    if (!gift || typeof gift !== 'object' || gift.id == null) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutGiftVariantId = 0;
        } catch (eZ) {}
        return;
    }
    const T = window.APP_T || {};
    const title = T.cart_gift_promo_title || '';
    if (gift.gift_kind === 'fixed' && gift.fixed_variant_id) {
        try {
            window.__orangeCheckoutGiftVariantId = parseInt(String(gift.fixed_variant_id), 10) || 0;
        } catch (eF) {
            window.__orangeCheckoutGiftVariantId = 0;
        }
        const note = T.cart_gift_included_fixed || '';
        wrap.hidden = false;
        inner.innerHTML =
            '<div class="cart-gift-promo__title">' +
            escCartHtml(title) +
            '</div><p class="cart-gift-promo__note">' +
            escCartHtml(note) +
            '</p>';
        return;
    }
    const pool = Array.isArray(gift.pool) ? gift.pool : [];
    if (!pool.length) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutGiftVariantId = 0;
        } catch (eP) {}
        return;
    }
    let opts = '';
    pool.forEach(function (p) {
        const vid = parseInt(String(p.variant_id), 10) || 0;
        if (!vid) {
            return;
        }
        const parts = [String(p.product_name || '').trim()];
        if (p.color) {
            parts.push(String(p.color));
        }
        if (p.size) {
            parts.push(String(p.size));
        }
        const lab = parts.filter(Boolean).join(' · ');
        opts +=
            '<option value="' +
            String(vid) +
            '">' +
            escCartHtml(lab) +
            '</option>';
    });
    if (!opts) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutGiftVariantId = 0;
        } catch (eE) {}
        return;
    }
    wrap.hidden = false;
    const lbl = T.cart_gift_pick_label || '';
    inner.innerHTML =
        '<div class="cart-gift-promo__title">' +
        escCartHtml(title) +
        '</div><label class="cart-gift-promo__label"><span>' +
        escCartHtml(lbl) +
        '</span><select id="cartGiftVariantSelect" class="cart-gift-promo__select">' +
        opts +
        '</select></label>';
    const sel = document.getElementById('cartGiftVariantSelect');
    if (sel) {
        if (pool.length === 1) {
            try {
                window.__orangeCheckoutGiftVariantId = parseInt(String(pool[0].variant_id), 10) || 0;
            } catch (e1) {
                window.__orangeCheckoutGiftVariantId = 0;
            }
        } else {
            try {
                window.__orangeCheckoutGiftVariantId = parseInt(String(sel.value), 10) || 0;
            } catch (e2) {
                window.__orangeCheckoutGiftVariantId = 0;
            }
        }
        sel.addEventListener('change', function () {
            try {
                window.__orangeCheckoutGiftVariantId = parseInt(String(sel.value), 10) || 0;
            } catch (e3) {
                window.__orangeCheckoutGiftVariantId = 0;
            }
        });
    }
}

function orangeUpdateCartBogoPromotionUI(bogo) {
    const wrap = document.getElementById('cartBogoGiftPromoHost');
    const inner = document.getElementById('cartBogoGiftPromoInner');
    if (!wrap || !inner) {
        return;
    }
    try {
        window.__orangeLastBogoPromotion = bogo && typeof bogo === 'object' ? bogo : null;
    } catch (eBg) {
        window.__orangeLastBogoPromotion = null;
    }
    if (!bogo || typeof bogo !== 'object' || bogo.id == null) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutBogoGiftVariantId = 0;
        } catch (eZ2) {}
        return;
    }
    const T = window.APP_T || {};
    const title = T.cart_bogo_promo_title || '';
    if (bogo.gift_kind === 'fixed' && bogo.fixed_variant_id) {
        try {
            window.__orangeCheckoutBogoGiftVariantId = parseInt(String(bogo.fixed_variant_id), 10) || 0;
        } catch (eBf) {
            window.__orangeCheckoutBogoGiftVariantId = 0;
        }
        const note = T.cart_bogo_included_fixed || '';
        wrap.hidden = false;
        inner.innerHTML =
            '<div class="cart-gift-promo__title">' +
            escCartHtml(title) +
            '</div><p class="cart-gift-promo__note">' +
            escCartHtml(note) +
            '</p>';
        return;
    }
    const poolB = Array.isArray(bogo.pool) ? bogo.pool : [];
    if (!poolB.length) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutBogoGiftVariantId = 0;
        } catch (ePb) {}
        return;
    }
    let optsB = '';
    poolB.forEach(function (p) {
        const vid = parseInt(String(p.variant_id), 10) || 0;
        if (!vid) {
            return;
        }
        const parts = [String(p.product_name || '').trim()];
        if (p.color) {
            parts.push(String(p.color));
        }
        if (p.size) {
            parts.push(String(p.size));
        }
        const lab = parts.filter(Boolean).join(' · ');
        optsB +=
            '<option value="' +
            String(vid) +
            '">' +
            escCartHtml(lab) +
            '</option>';
    });
    if (!optsB) {
        wrap.hidden = true;
        inner.innerHTML = '';
        try {
            window.__orangeCheckoutBogoGiftVariantId = 0;
        } catch (eEb) {}
        return;
    }
    wrap.hidden = false;
    const lblB = T.cart_bogo_pick_label || '';
    inner.innerHTML =
        '<div class="cart-gift-promo__title">' +
        escCartHtml(title) +
        '</div><label class="cart-gift-promo__label"><span>' +
        escCartHtml(lblB) +
        '</span><select id="cartBogoGiftVariantSelect" class="cart-gift-promo__select">' +
        optsB +
        '</select></label>';
    const selB = document.getElementById('cartBogoGiftVariantSelect');
    if (selB) {
        if (poolB.length === 1) {
            try {
                window.__orangeCheckoutBogoGiftVariantId = parseInt(String(poolB[0].variant_id), 10) || 0;
            } catch (eB1) {
                window.__orangeCheckoutBogoGiftVariantId = 0;
            }
        } else {
            try {
                window.__orangeCheckoutBogoGiftVariantId = parseInt(String(selB.value), 10) || 0;
            } catch (eB2) {
                window.__orangeCheckoutBogoGiftVariantId = 0;
            }
        }
        selB.addEventListener('change', function () {
            try {
                window.__orangeCheckoutBogoGiftVariantId = parseInt(String(selB.value), 10) || 0;
            } catch (eB3) {
                window.__orangeCheckoutBogoGiftVariantId = 0;
            }
        });
    }
}

function orangeCartClientSubtotalFromItems(items) {
    let s = 0;
    items.forEach((it) => {
        const q = Math.max(1, parseInt(it.qty, 10) || 1);
        s += q * Number(it.price);
    });
    return s;
}

function cartItemsForCheckoutPreview(items) {
    return items.map((i) => ({
        id: i.id,
        qty: Math.max(1, parseInt(i.qty, 10) || 1),
        variant_id: i.variant_id ? parseInt(i.variant_id, 10) || 0 : 0,
        color: i.color != null ? String(i.color) : '',
        size: i.size != null ? String(i.size) : '',
    }));
}

function orangeCartTotalsEpsilon() {
    return 1e-6;
}

function orangeHtmlCartMainTotals(subtotal, comboDiscount, promoDiscount, deliveryFee, total, productOfferDiscount) {
    const T = window.APP_T || {};
    productOfferDiscount = typeof productOfferDiscount === 'number' ? productOfferDiscount : 0;
    const totalLbl = T.cart_total_label || 'Total';
    const subLbl = T.cart_subtotal_label || 'Subtotal';
    const promoLbl = T.cart_promotion_discount_label || 'Cart offer';
    const comboLbl = T.cart_combo_discount_label || 'Combo bundle';
    const offerLbl = T.product_offer_discount_label || T.offers || 'Offer';
    const deliveryLbl = T.checkout_delivery_fee_label || 'Delivery fee';
    const eps = orangeCartTotalsEpsilon();
    const showBreakdown = comboDiscount > eps || promoDiscount > eps || productOfferDiscount > eps || deliveryFee > eps;
    let html =
        '<div class="cart-summary-totals" id="cartMainTotals">';
    if (showBreakdown) {
        html +=
            '<div class="cart-summary-line"><span>' +
            escCartHtml(subLbl) +
            '</span><span>' +
            formatMoney(subtotal) +
            '</span></div>';
        if (productOfferDiscount > eps) {
            html +=
                '<div class="cart-summary-line cart-summary-line--offer"><span>' +
                escCartHtml(offerLbl) +
                '</span><span>−' +
                formatMoney(productOfferDiscount) +
                '</span></div>';
        }
        if (comboDiscount > eps) {
            html +=
                '<div class="cart-summary-line cart-summary-line--combo"><span>' +
                escCartHtml(comboLbl) +
                '</span><span>−' +
                formatMoney(comboDiscount) +
                '</span></div>';
        }
        if (promoDiscount > eps) {
            html +=
                '<div class="cart-summary-line cart-summary-line--promo"><span>' +
                escCartHtml(promoLbl) +
                '</span><span>−' +
                formatMoney(promoDiscount) +
                '</span></div>';
        }
        if (deliveryFee > eps) {
            html +=
                '<div class="cart-summary-line cart-summary-line--delivery"><span>' +
                escCartHtml(deliveryLbl) +
                '</span><span>+' +
                formatMoney(deliveryFee) +
                '</span></div>';
        }
    }
    html +=
        '<div class="cart-total-box"><strong>' +
        escCartHtml(totalLbl) +
        '</strong><span class="cart-total-amount">' +
        formatMoney(total) +
        '</span></div></div>';
    return html;
}

function orangeHtmlCartMiniTotals(subtotal, comboDiscount, promoDiscount, deliveryFee, total, productOfferDiscount) {
    const T = window.APP_T || {};
    productOfferDiscount = typeof productOfferDiscount === 'number' ? productOfferDiscount : 0;
    const totalLbl = T.cart_total_label || 'Total';
    const subLbl = T.cart_subtotal_label || 'Subtotal';
    const promoLbl = T.cart_promotion_discount_label || 'Cart offer';
    const comboLbl = T.cart_combo_discount_label || 'Combo bundle';
    const offerLbl = T.product_offer_discount_label || T.offers || 'Offer';
    const deliveryLbl = T.checkout_delivery_fee_label || 'Delivery fee';
    const eps = orangeCartTotalsEpsilon();
    const showBreakdown = comboDiscount > eps || promoDiscount > eps || productOfferDiscount > eps || deliveryFee > eps;
    let html =
        '<div class="cart-mini-totals-breakdown" id="cartMiniTotals">';
    if (showBreakdown) {
        html +=
            '<div class="cart-mini-total-line"><span>' +
            escCartHtml(subLbl) +
            '</span><span>' +
            formatMoney(subtotal) +
            '</span></div>';
        if (productOfferDiscount > eps) {
            html +=
                '<div class="cart-mini-total-line cart-mini-total-line--offer"><span>' +
                escCartHtml(offerLbl) +
                '</span><span>−' +
                formatMoney(productOfferDiscount) +
                '</span></div>';
        }
        if (comboDiscount > eps) {
            html +=
                '<div class="cart-mini-total-line cart-mini-total-line--combo"><span>' +
                escCartHtml(comboLbl) +
                '</span><span>−' +
                formatMoney(comboDiscount) +
                '</span></div>';
        }
        if (promoDiscount > eps) {
            html +=
                '<div class="cart-mini-total-line cart-mini-total-line--promo"><span>' +
                escCartHtml(promoLbl) +
                '</span><span>−' +
                formatMoney(promoDiscount) +
                '</span></div>';
        }
        if (deliveryFee > eps) {
            html +=
                '<div class="cart-mini-total-line cart-mini-total-line--delivery"><span>' +
                escCartHtml(deliveryLbl) +
                '</span><span>+' +
                formatMoney(deliveryFee) +
                '</span></div>';
        }
    }
    html +=
        '<div class="cart-mini-total"><span>' +
        escCartHtml(totalLbl) +
        '</span><strong>' +
        formatMoney(total) +
        '</strong></div></div>';
    return html;
}

function orangeUpdateRegisterPromoTeaser(teaser) {
    const acc = window.ORANGE_CART_SF_ACCOUNT || {};
    const ids = ['cartRegisterPromoTeaser', 'cartBasketRegisterPromoTeaser'];
    const clearAll = () => {
        ids.forEach((id) => {
            const node = document.getElementById(id);
            if (node) {
                node.hidden = true;
                node.innerHTML = '';
            }
        });
    };
    if (acc.logged_in) {
        clearAll();
        return;
    }
    if (!teaser || typeof teaser.you_save_extra !== 'number' || !(teaser.you_save_extra > 1e-9)) {
        clearAll();
        return;
    }
    const T = window.APP_T || {};
    const msg = String(T.cart_register_promo_teaser || '')
        .replace(/\{extra\}/g, formatMoney(teaser.you_save_extra));
    const action = T.cart_register_promo_teaser_action || '';
    const url = typeof window.ORANGE_REGISTER_URL === 'string' ? window.ORANGE_REGISTER_URL.trim() : '';
    const safeMsg = escCartHtml(msg);
    const safeAct = escCartHtml(action);
    let linkHtml = '';
    if (url && action) {
        linkHtml =
            ' <a class="cart-register-promo-teaser__link" href="' + escCartAttr(url) + '">' + safeAct + '</a>';
    }
    const inner = '<p class="cart-register-promo-teaser__text">' + safeMsg + linkHtml + '</p>';
    ids.forEach((id) => {
        const node = document.getElementById(id);
        if (node) {
            node.hidden = false;
            node.innerHTML = inner;
        }
    });
}

function orangeUpdateGiftBogoRegisterUnlockTeaser(giftOn, bogoOn, comboOn) {
    const acc = window.ORANGE_CART_SF_ACCOUNT || {};
    const ids = ['cartGiftBogoRegisterUnlockTeaser', 'cartBasketGiftBogoRegisterUnlockTeaser'];
    const T = window.APP_T || {};
    const url = typeof window.ORANGE_REGISTER_URL === 'string' ? window.ORANGE_REGISTER_URL.trim() : '';
    const action = T.cart_register_promo_teaser_action || '';
    const linkHtml =
        url && action
            ? ' <a class="cart-register-promo-teaser__link" href="' + escCartAttr(url) + '">' + escCartHtml(action) + '</a>'
            : '';
    const parts = [];
    if (!acc.logged_in && giftOn) {
        parts.push(
            '<p class="cart-register-promo-teaser__text">' +
                escCartHtml(T.cart_gift_register_unlock_teaser || '') +
                linkHtml +
                '</p>'
        );
    }
    if (!acc.logged_in && bogoOn) {
        parts.push(
            '<p class="cart-register-promo-teaser__text">' +
                escCartHtml(T.cart_bogo_register_unlock_teaser || '') +
                linkHtml +
                '</p>'
        );
    }
    if (!acc.logged_in && comboOn) {
        parts.push(
            '<p class="cart-register-promo-teaser__text">' +
                escCartHtml(T.cart_combo_register_unlock_teaser || '') +
                linkHtml +
                '</p>'
        );
    }
    const inner = parts.join('');
    const show = parts.length > 0;
    ids.forEach((id) => {
        const node = document.getElementById(id);
        if (!node) {
            return;
        }
        if (!show) {
            node.hidden = true;
            node.innerHTML = '';
        } else {
            node.hidden = false;
            node.innerHTML = inner;
        }
    });
}

function orangePatchCartTotalsFromServer(
    subtotal,
    comboDiscount,
    promoDiscount,
    deliveryFee,
    total,
    registerTeaser,
    giftUnlock,
    bogoUnlock,
    comboUnlock,
    productOfferDiscount
) {
    const main = document.getElementById('cartMainTotals');
    if (main && main.parentNode) {
        const wrap = document.createElement('div');
        wrap.innerHTML = orangeHtmlCartMainTotals(subtotal, comboDiscount, promoDiscount, deliveryFee, total, productOfferDiscount);
        const next = wrap.firstElementChild;
        if (next) {
            main.parentNode.replaceChild(next, main);
        }
    }
    const mini = document.getElementById('cartMiniTotals');
    if (mini && mini.parentNode) {
        const wrap = document.createElement('div');
        wrap.innerHTML = orangeHtmlCartMiniTotals(subtotal, comboDiscount, promoDiscount, deliveryFee, total, productOfferDiscount);
        const next = wrap.firstElementChild;
        if (next) {
            mini.parentNode.replaceChild(next, mini);
        }
    }
    orangeUpdateRegisterPromoTeaser(registerTeaser);
    orangeUpdateGiftBogoRegisterUnlockTeaser(!!giftUnlock, !!bogoUnlock, !!comboUnlock);
}

function orangeScheduleCheckoutPreview() {
    if (__orangeCartPreviewTimer) {
        clearTimeout(__orangeCartPreviewTimer);
    }
    __orangeCartPreviewTimer = setTimeout(() => {
        __orangeCartPreviewTimer = null;
        orangeRunCheckoutPreview();
    }, 400);
}

const orangeCheckoutOtpState = {
    ignored: false,
    requested: false,
    verified: false,
    sending: false,
    verifying: false,
    maskedEmail: '',
    cooldownUntilMs: 0,
    lastPhoneSignature: '',
};
let orangeCheckoutOtpUiTimer = null;

function orangeCheckoutOtpLoggedIn() {
    const acc = window.ORANGE_CART_SF_ACCOUNT || {};
    return !!acc.logged_in;
}

function orangeCheckoutOtpSetLoggedIn(flag) {
    if (!window.ORANGE_CART_SF_ACCOUNT || typeof window.ORANGE_CART_SF_ACCOUNT !== 'object') {
        window.ORANGE_CART_SF_ACCOUNT = { logged_in: !!flag };
    } else {
        window.ORANGE_CART_SF_ACCOUNT.logged_in = !!flag;
    }
    window.ORANGE_SF_LOGGED_IN = !!flag;
}

function orangeCheckoutOtpCurrentPhoneContext() {
    const ccEl = document.getElementById('customer_phone_country');
    const phoneEl = document.getElementById('customer_phone');
    const intl = !!ccEl && String(ccEl.value || '') === '__intl__';
    const ccDigits =
        typeof window.orangeStorefrontPhoneCountryDigits === 'function'
            ? window.orangeStorefrontPhoneCountryDigits('customer_phone_country')
            : null;
    return {
        intl: intl,
        phoneRaw: phoneEl ? String(phoneEl.value || '').trim() : '',
        phoneCountry: intl ? '__intl__' : ccDigits || '',
        hasCountry:
            ccDigits !== null &&
            ccDigits !== undefined &&
            (intl || String(ccDigits).trim() !== ''),
    };
}

function orangeCheckoutOtpNormalizedPhone(ctx) {
    if (!ctx || !ctx.phoneRaw || !ctx.hasCountry) {
        return '';
    }
    if (typeof window.orangeNormalizeCustomerPhone !== 'function') {
        return '';
    }
    const norm = window.orangeNormalizeCustomerPhone(
        ctx.phoneRaw,
        ctx.intl ? null : ctx.phoneCountry,
        ctx.intl
    );
    return norm ? String(norm).trim() : '';
}

function orangeCheckoutOtpSetFeedback(message, isError) {
    const node = document.getElementById('checkoutOtpFeedback');
    if (!node) {
        return;
    }
    const text = String(message || '').trim();
    if (!text) {
        node.textContent = '';
        node.hidden = true;
        return;
    }
    node.textContent = text;
    node.style.color = isError ? '#b91c1c' : '#166534';
    node.hidden = false;
}

function orangeCheckoutOtpSyncUi() {
    const wrap = document.getElementById('checkoutOtpQuickLogin');
    if (!wrap) {
        return;
    }
    if (orangeCheckoutOtpUiTimer) {
        clearTimeout(orangeCheckoutOtpUiTimer);
        orangeCheckoutOtpUiTimer = null;
    }
    const hintEl = document.getElementById('checkoutOtpHint');
    const sendBtn = document.getElementById('checkoutOtpSendBtn');
    const resendBtn = document.getElementById('checkoutOtpResendBtn');
    const ignoreBtn = document.getElementById('checkoutOtpIgnoreBtn');
    const verifyWrap = document.getElementById('checkoutOtpVerifyWrap');
    const verifyBtn = document.getElementById('checkoutOtpVerifyBtn');
    if (orangeCheckoutOtpLoggedIn()) {
        wrap.hidden = true;
        return;
    }
    const ctx = orangeCheckoutOtpCurrentPhoneContext();
    const signature = orangeCheckoutOtpNormalizedPhone(ctx);
    if (!signature || orangeCheckoutOtpState.ignored) {
        wrap.hidden = true;
        return;
    }
    wrap.hidden = false;

    const now = Date.now();
    const cooldownMs = Math.max(0, orangeCheckoutOtpState.cooldownUntilMs - now);
    const cooldownSec = Math.ceil(cooldownMs / 1000);
    const canResend = orangeCheckoutOtpState.requested;
    if (sendBtn) {
        sendBtn.hidden = canResend;
        sendBtn.disabled = orangeCheckoutOtpState.sending || cooldownSec > 0;
    }
    if (resendBtn) {
        resendBtn.hidden = !canResend;
        resendBtn.disabled = orangeCheckoutOtpState.sending || cooldownSec > 0;
    }
    if (ignoreBtn) {
        ignoreBtn.hidden = false;
        ignoreBtn.disabled = orangeCheckoutOtpState.sending || orangeCheckoutOtpState.verifying;
    }
    if (verifyWrap) {
        verifyWrap.hidden = !canResend;
    }
    if (verifyBtn) {
        verifyBtn.disabled = orangeCheckoutOtpState.verifying;
    }
    const T = window.APP_T || {};
    let hint = String(T.checkout_otp_prompt || '').trim();
    if (canResend && orangeCheckoutOtpState.maskedEmail) {
        const sentTpl = String(T.checkout_otp_sent_to_email || '').trim();
        if (sentTpl) {
            hint = sentTpl.replace(/\{email\}/g, orangeCheckoutOtpState.maskedEmail);
        }
    }
    if (cooldownSec > 0) {
        const cTpl = String(T.checkout_otp_resend_after || '').trim();
        const cText = cTpl ? cTpl.replace(/\{seconds\}/g, String(cooldownSec)) : '';
        hint = hint ? hint + (cText ? ' — ' + cText : '') : cText;
        orangeCheckoutOtpUiTimer = setTimeout(orangeCheckoutOtpSyncUi, 1000);
    }
    if (hintEl) {
        hintEl.textContent = hint;
    }
}

function orangeCheckoutOtpResetForPhone(signature) {
    orangeCheckoutOtpState.requested = false;
    orangeCheckoutOtpState.verified = false;
    orangeCheckoutOtpState.sending = false;
    orangeCheckoutOtpState.verifying = false;
    orangeCheckoutOtpState.maskedEmail = '';
    orangeCheckoutOtpState.cooldownUntilMs = 0;
    orangeCheckoutOtpState.ignored = false;
    orangeCheckoutOtpState.lastPhoneSignature = signature || '';
    const otpEl = document.getElementById('checkoutOtpCode');
    if (otpEl) {
        otpEl.value = '';
    }
    orangeCheckoutOtpSetFeedback('', false);
}

function orangeCheckoutOtpOnPhoneEdited() {
    if (orangeCheckoutOtpLoggedIn()) {
        orangeCheckoutOtpSyncUi();
        return;
    }
    const signature = orangeCheckoutOtpNormalizedPhone(orangeCheckoutOtpCurrentPhoneContext());
    if (signature !== orangeCheckoutOtpState.lastPhoneSignature) {
        orangeCheckoutOtpResetForPhone(signature);
    }
    orangeCheckoutOtpSyncUi();
}

async function orangeCheckoutRequestOtp(isResend) {
    if (orangeCheckoutOtpLoggedIn()) {
        return;
    }
    const T = window.APP_T || {};
    const ctx = orangeCheckoutOtpCurrentPhoneContext();
    if (!ctx.hasCountry) {
        orangeCheckoutOtpSetFeedback(
            T.phone_country_required || T.checkout_required_fields || '',
            true
        );
        return;
    }
    const signature = orangeCheckoutOtpNormalizedPhone(ctx);
    if (!signature) {
        orangeCheckoutOtpSetFeedback(
            T.checkout_invalid_phone || T.storefront_register_invalid_phone || '',
            true
        );
        return;
    }
    if (signature !== orangeCheckoutOtpState.lastPhoneSignature) {
        orangeCheckoutOtpResetForPhone(signature);
    }
    orangeCheckoutOtpState.sending = true;
    orangeCheckoutOtpSyncUi();
    try {
        const response = await fetch(
            storefrontApiUrl('/api/auth/request-checkout-email-otp.php'),
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    phone: ctx.phoneRaw,
                    phone_country: ctx.phoneCountry,
                    lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
                    resend: isResend ? 1 : 0,
                }),
            }
        );
        let data = null;
        try {
            data = await response.json();
        } catch (eJson) {
            data = null;
        }
        if (!data || typeof data !== 'object') {
            orangeCheckoutOtpSetFeedback(T.api_request_failed || '', true);
            return;
        }
        if (!response.ok || data.success !== true) {
            orangeCheckoutOtpSetFeedback(
                orangeCheckoutApiMessage(data) || T.checkout_otp_send_failed || '',
                true
            );
            return;
        }
        orangeCheckoutOtpState.requested = true;
        orangeCheckoutOtpState.maskedEmail = String(data.masked_email || '');
        const cdSec = parseInt(String(data.cooldown_seconds || 0), 10) || 0;
        orangeCheckoutOtpState.cooldownUntilMs = cdSec > 0 ? Date.now() + cdSec * 1000 : 0;
        orangeCheckoutOtpSetFeedback(
            orangeCheckoutApiMessage(data) || data.message || T.checkout_otp_sent || '',
            false
        );
        orangeCheckoutOtpSyncUi();
    } catch (eReq) {
        orangeCheckoutOtpSetFeedback(T.api_request_failed || '', true);
    } finally {
        orangeCheckoutOtpState.sending = false;
        orangeCheckoutOtpSyncUi();
    }
}

async function orangeCheckoutVerifyOtp() {
    if (orangeCheckoutOtpLoggedIn()) {
        return;
    }
    const T = window.APP_T || {};
    const ctx = orangeCheckoutOtpCurrentPhoneContext();
    if (!ctx.hasCountry) {
        orangeCheckoutOtpSetFeedback(
            T.phone_country_required || T.checkout_required_fields || '',
            true
        );
        return;
    }
    const signature = orangeCheckoutOtpNormalizedPhone(ctx);
    if (!signature) {
        orangeCheckoutOtpSetFeedback(
            T.checkout_invalid_phone || T.storefront_register_invalid_phone || '',
            true
        );
        return;
    }
    if (signature !== orangeCheckoutOtpState.lastPhoneSignature) {
        orangeCheckoutOtpResetForPhone(signature);
        orangeCheckoutOtpSetFeedback(T.checkout_otp_not_requested || '', true);
        orangeCheckoutOtpSyncUi();
        return;
    }
    const otpEl = document.getElementById('checkoutOtpCode');
    const otpCodeRaw = otpEl ? String(otpEl.value || '').replace(/\D+/g, '') : '';
    if (otpCodeRaw.length !== 6) {
        orangeCheckoutOtpSetFeedback(T.checkout_otp_invalid || '', true);
        return;
    }
    orangeCheckoutOtpState.verifying = true;
    orangeCheckoutOtpSyncUi();
    try {
        const response = await fetch(
            storefrontApiUrl('/api/auth/verify-checkout-email-otp.php'),
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    phone: ctx.phoneRaw,
                    phone_country: ctx.phoneCountry,
                    otp: otpCodeRaw,
                    lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
                }),
            }
        );
        let data = null;
        try {
            data = await response.json();
        } catch (eJson) {
            data = null;
        }
        if (!data || typeof data !== 'object') {
            orangeCheckoutOtpSetFeedback(T.api_request_failed || '', true);
            return;
        }
        if (!response.ok || data.success !== true) {
            let errText = orangeCheckoutApiMessage(data) || T.checkout_otp_invalid || '';
            if (data.attempts_left != null && Number.isFinite(Number(data.attempts_left))) {
                const leftTpl = String(T.checkout_otp_attempts_left || '').trim();
                if (leftTpl) {
                    errText +=
                        ' — ' +
                        leftTpl.replace(/\{count\}/g, String(parseInt(String(data.attempts_left), 10) || 0));
                }
            }
            orangeCheckoutOtpSetFeedback(errText, true);
            return;
        }

        orangeCheckoutOtpState.requested = false;
        orangeCheckoutOtpState.verified = true;
        orangeCheckoutOtpState.cooldownUntilMs = 0;
        orangeCheckoutOtpState.maskedEmail =
            data.account && data.account.masked_email ? String(data.account.masked_email) : '';
        orangeCheckoutOtpSetLoggedIn(true);
        if (otpEl) {
            otpEl.value = '';
        }
        const okMsg = orangeCheckoutApiMessage(data) || data.message || T.checkout_otp_verified || '';
        orangeCheckoutOtpSetFeedback(okMsg, false);
        orangeCheckoutOtpSyncUi();
        if (typeof window.orangeShowToast === 'function' && okMsg) {
            window.orangeShowToast(okMsg, 2800);
        }
        orangeScheduleCheckoutPreview();
        if (typeof window.orangeCartRefreshAccountOrderLists === 'function') {
            try {
                await window.orangeCartRefreshAccountOrderLists();
            } catch (eRef) {
                /* ignore */
            }
        }
    } catch (eVerify) {
        orangeCheckoutOtpSetFeedback(T.api_request_failed || '', true);
    } finally {
        orangeCheckoutOtpState.verifying = false;
        orangeCheckoutOtpSyncUi();
    }
}

function orangeCheckoutIgnoreOtp() {
    orangeCheckoutOtpState.ignored = true;
    orangeCheckoutOtpState.requested = false;
    orangeCheckoutOtpState.cooldownUntilMs = 0;
    orangeCheckoutOtpSetFeedback('', false);
    orangeCheckoutOtpSyncUi();
}

window.orangeCheckoutRequestOtp = orangeCheckoutRequestOtp;
window.orangeCheckoutVerifyOtp = orangeCheckoutVerifyOtp;
window.orangeCheckoutIgnoreOtp = orangeCheckoutIgnoreOtp;

function orangeCheckoutSelectedDeliveryAreaId() {
    const areaEl = document.getElementById('customer_area');
    if (!areaEl || String(areaEl.tagName || '').toUpperCase() !== 'SELECT') {
        return 0;
    }
    const id = parseInt(String(areaEl.value || '0'), 10) || 0;
    return id > 0 ? id : 0;
}

async function orangeRunCheckoutPreview() {
    const items = getCart();
    if (!items.length) {
        return;
    }
    const previewLines = orangeCartLineChoiceApplies(items) ? orangeCartGetSelectedItems(items) : items;
    if (!previewLines.length) {
        orangeUpdateRegisterPromoTeaser(null);
        orangeUpdateGiftBogoRegisterUnlockTeaser(false, false, false);
        return;
    }
    const seq = ++__orangeCartPreviewSeq;
    const payload = {
        items: cartItemsForCheckoutPreview(previewLines),
        delivery_area_id: orangeCheckoutSelectedDeliveryAreaId(),
        redeem_points: __orangeLoyaltyRedeemPoints,
        lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
    };
    try {
        const response = await fetch(storefrontApiUrl('/api/cart/checkout-preview.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (seq !== __orangeCartPreviewSeq) {
            return;
        }
        if (
            data &&
            data.success &&
            typeof data.subtotal === 'number' &&
            typeof data.total === 'number'
        ) {
            const comboD =
                typeof data.combo_discount === 'number' ? data.combo_discount : 0;
            const promo =
                typeof data.promotion_discount === 'number' ? data.promotion_discount : 0;
            const deliveryFee =
                typeof data.delivery_fee === 'number' ? data.delivery_fee : 0;
            const productOfferD =
                typeof data.product_offer_discount === 'number' ? data.product_offer_discount : 0;
            orangePatchCartTotalsFromServer(
                data.subtotal,
                comboD,
                promo,
                deliveryFee,
                data.total,
                data.register_promo_teaser || null,
                data.gift_register_unlock_teaser === true,
                data.bogo_register_unlock_teaser === true,
                data.combo_register_unlock_teaser === true,
                productOfferD
            );
            orangeUpdateCartGiftPromotionUI(data.gift_promotion || null);
            orangeUpdateCartBogoPromotionUI(data.bogo_promotion || null);
            orangeUpdateLoyaltyRedeemUI(data.loyalty || null);
        } else {
            orangeUpdateRegisterPromoTeaser(null);
            orangeUpdateGiftBogoRegisterUnlockTeaser(false, false, false);
            orangeUpdateCartGiftPromotionUI(null);
            orangeUpdateCartBogoPromotionUI(null);
            orangeUpdateLoyaltyRedeemUI(null);
        }
    } catch (e) {
        orangeUpdateRegisterPromoTeaser(null);
        orangeUpdateGiftBogoRegisterUnlockTeaser(false, false, false);
        orangeUpdateCartGiftPromotionUI(null);
        orangeUpdateCartBogoPromotionUI(null);
        orangeUpdateLoyaltyRedeemUI(null);
    }
}

function orangeRenderCheckoutMiniSummary() {
    const el = document.getElementById('cartOrderMiniSummary');
    if (!el) {
        return;
    }
    normalizeCartDuplicates();
    const items = getCart();
    const T = window.APP_T || {};
    if (!items.length) {
        el.hidden = true;
        el.innerHTML = '';
        orangeCancelCheckoutPreview();
        return;
    }
    const choice = orangeCartLineChoiceApplies(items);
    const summaryItems = choice ? orangeCartGetSelectedItems(items) : items;
    const clientSub = orangeCartClientSubtotalFromItems(summaryItems);
    const rows = [];
    summaryItems.forEach((it) => {
        const q = Math.max(1, parseInt(it.qty, 10) || 1);
        rows.push({ name: it.name || '', q });
    });
    const title = T.cart_mini_summary_title || '';
    let listHtml = '';
    for (let i = 0; i < rows.length; i++) {
        listHtml +=
            '<li><span class="cart-mini-list__name">' +
            escCartHtml(rows[i].name) +
            '</span><span class="cart-mini-list__qty">×' +
            rows[i].q +
            '</span></li>';
    }
    el.hidden = false;
    el.innerHTML =
        '<div class="cart-mini-summary__inner">' +
        (title ? '<div class="cart-mini-summary__title">' + escCartHtml(title) + '</div>' : '') +
        '<ul class="cart-mini-list">' +
        listHtml +
        '</ul>' +
        orangeHtmlCartMiniTotals(clientSub, 0, 0, 0, clientSub) +
        '<div class="cart-register-promo-teaser" id="cartRegisterPromoTeaser" hidden></div>' +
        '<div class="cart-register-promo-teaser cart-gift-bogo-register-teaser" id="cartGiftBogoRegisterUnlockTeaser" hidden></div>' +
        '</div>';
    if (choice && !summaryItems.length) {
        orangeCancelCheckoutPreview();
        return;
    }
    orangeScheduleCheckoutPreview();
}

async function fetchCartStockLimits(items) {
    const payload = {
        items: items.map((i) => ({
            id: i.id,
            variant_id: i.variant_id,
            color: i.color,
            size: i.size,
        })),
        lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
    };
    try {
        const response = await fetch(storefrontApiUrl('/api/cart/stock-limits.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (data.success && Array.isArray(data.limits)) {
            return data.limits.map((n) => (n == null ? null : parseInt(n, 10)));
        }
    } catch (e) {
        /* offline or error — skip server caps */
    }
    return null;
}

async function renderCart() {
    const box = document.getElementById('cartItems');
    if (!box) {
        return;
    }

    normalizeCartDuplicates();
    let items = getCart();

    if (!items.length) {
        try {
            window.__orangePendingAmend = null;
        } catch (eClr) {}
        orangeClearPendingAmendStorage();
        box.innerHTML = cartEmptyStateHtml();
        orangeSyncAmendModeBanner();
        orangeSyncCartProceedBtn();
        orangeSyncCartTabCount();
        orangeCancelCheckoutPreview();
        orangeRenderCheckoutMiniSummary();
        return;
    }

    const limitsPromise = fetchCartStockLimits(items);

    function paintCartBasket(itemsModel, limitsArr) {
    let total = 0;
    let html = '';
    const T = window.APP_T || {};
    const removeLabel = T.cart_remove || 'Remove';
    const countTpl = T.cart_items_count || '{n} items';
        const countStr = countTpl.replace(/\{n\}/g, String(itemsModel.length));
    const unitLbl = T.cart_unit_price || '';
    const subLbl = T.cart_line_subtotal || '';
        const choiceOn = orangeCartLineChoiceApplies(itemsModel);

        itemsModel.forEach((itInit) => {
            const k0 = orangeCartLineKey(itInit);
            if (!Object.prototype.hasOwnProperty.call(orangeCartLineIncluded, k0)) {
                orangeCartLineIncluded[k0] = true;
            }
        });

    html += '<div class="cart-items-shell">';
    html +=
        '<div class="cart-list-head"><span class="cart-list-head__count">' +
        escCartHtml(countStr) +
            '</span>';
        if (choiceOn) {
            html +=
                '<div class="cart-list-selection-tools">' +
                '<span class="cart-list-selection-hint">' +
                escCartHtml(T.cart_order_line_choice_hint || '') +
                '</span>' +
                '<span class="cart-list-selection-actions">' +
                '<button type="button" class="btn btn-ghost cart-select-all-btn" onclick="orangeCartSelectAllLines(true)">' +
                escCartHtml(T.cart_select_all_lines || '') +
                '</button>' +
                '<button type="button" class="btn btn-ghost cart-deselect-all-btn" onclick="orangeCartSelectAllLines(false)">' +
                escCartHtml(T.cart_deselect_all_lines || '') +
                '</button>' +
        '</span></div>';
        }
        html += '</div>';
    html += '<div class="cart-items-list">';

        itemsModel.forEach((item, idx) => {
        const qty = Math.max(1, parseInt(item.qty, 10) || 1);
            const vidLine = parseInt(item.variant_id || 0, 10) || 0;
        const lineTotal = qty * Number(item.price);
            if (!choiceOn || orangeCartLineIsIncluded(item)) {
        total += lineTotal;
            }
        const maxStock =
                limitsArr && limitsArr[idx] != null && !Number.isNaN(limitsArr[idx])
                    ? Math.max(0, parseInt(limitsArr[idx], 10))
                : null;
        const maxAttr = maxStock != null && maxStock > 0 ? ` max="${maxStock}"` : '';
        const qtyOptions = orangeCartQtyOptions(qty, maxStock);

            const incl = orangeCartLineIsIncluded(item);
            const lineChoiceHtml = choiceOn
                ? '<div class="cart-line-include"><label class="cart-line-include-label"><input type="checkbox" class="cart-line-include-cb" ' +
                  (incl ? 'checked ' : '') +
                  `onchange="orangeCartSetLineIncluded(${idx}, this.checked)"> <span class="cart-line-include-text">${escCartHtml(
                      T.cart_include_in_this_order || ''
                  )}</span></label></div>`
                : '';

        html += `
            <div class="cart-item-card" data-cart-idx="${idx}"${vidLine ? ` data-variant-id="${vidLine}"` : ''}>
                <div class="cart-item-left">
                    ${orangeCartProductImageMarkup(item)}
                </div>
                <div class="cart-item-right">
                    ${lineChoiceHtml}
                    <h4>${escCartHtml(item.name || '')}</h4>
                    ${
                        vidLine
                            ? `<div class="js-cart-vlabel-host">${
                                  item.color
                                      ? `<p class="cart-item-variant">${escCartHtml(T.color || '')}: ${escCartHtml(item.color)}</p>`
                                      : ''
                              }${
                                  item.size
                                      ? `<p class="cart-item-variant">${escCartHtml(T.size || '')}: ${escCartHtml(item.size)}</p>`
                                      : ''
                              }</div>`
                            : `<div class="cart-item-variants">${item.color ? `<p class="cart-item-variant">${escCartHtml(T.color || '')}: ${escCartHtml(item.color)}</p>` : ''}${
                                  item.size ? `<p class="cart-item-variant">${escCartHtml(T.size || '')}: ${escCartHtml(item.size)}</p>` : ''
                              }</div>`
                    }
                    <div class="cart-line-price-row">
                        <span class="cart-unit-price"><span class="cart-meta-label">${escCartHtml(unitLbl)}</span> ${formatMoney(item.price)}</span>
                        <span class="cart-line-subtotal"><span class="cart-meta-label">${escCartHtml(subLbl)}</span><strong>${formatMoney(lineTotal)}</strong></span>
                    </div>
                    <div class="cart-qty-row">
                        <span class="cart-qty-label">${escCartHtml(T.quantity || '')}</span>
                        <div class="qty-control cart-qty-control">
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, -1)" aria-label="-">−</button>
                            <span class="qty-field">
                                <input type="number" class="cart-qty-input" id="cartQty${idx}" value="${qty}" min="1"${maxAttr} inputmode="numeric" onchange="setCartQtyFromInput(${idx})" onblur="setCartQtyFromInput(${idx})">
                                <select class="qty-picker cart-qty-picker" id="cartQtySel${idx}" aria-label="${escCartHtml(T.quantity || '')}" onchange="setCartQtyFromSelect(${idx})">${qtyOptions}</select>
                            </span>
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, 1)" aria-label="+">+</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost cart-remove-btn" onclick="removeCartItem(${idx})">${escCartHtml(removeLabel)}</button>
                </div>
            </div>
        `;
    });

    html += '</div>';
        html += '<div class="cart-summary-bar">' + orangeHtmlCartMainTotals(total, 0, 0, 0, total) + '</div>';
    html +=
            '<div class="cart-register-promo-teaser" id="cartBasketRegisterPromoTeaser" hidden></div>' +
            '<div class="cart-register-promo-teaser cart-gift-bogo-register-teaser" id="cartBasketGiftBogoRegisterUnlockTeaser" hidden></div>';
        html +=
            '<div class="cart-gift-promo-host" id="cartGiftPromoHost" hidden><div class="cart-gift-promo-inner" id="cartGiftPromoInner"></div></div>';
        html +=
            '<div class="cart-gift-promo-host cart-bogo-gift-host" id="cartBogoGiftPromoHost" hidden><div class="cart-gift-promo-inner" id="cartBogoGiftPromoInner"></div></div>';
    html += '</div>';

    box.innerHTML = html;
    }

    paintCartBasket(items, null);

    let limits = await limitsPromise;
    let cartMutated = false;
    if (limits && limits.length === items.length) {
        const beforeLen = items.length;
        const next = [];
        let changed = false;
        items.forEach((item, i) => {
            const max = limits[i];
            let q = parseInt(item.qty, 10);
            if (max == null || Number.isNaN(max)) {
                next.push({ ...item });
                return;
            }
            if (max <= 0) {
                changed = true;
                return;
            }
            if (q > max) {
                q = max;
                changed = true;
            }
            if (q < 1) {
                q = 1;
                changed = true;
            }
            next.push({ ...item, qty: q });
        });
        if (changed || next.length !== beforeLen) {
            setCart(next);
            cartMutated = true;
        }
        items = getCart();
    }

    if (!items.length) {
        try {
            window.__orangePendingAmend = null;
        } catch (eClr2) {}
        orangeClearPendingAmendStorage();
        box.innerHTML = cartEmptyStateHtml();
        orangeSyncAmendModeBanner();
    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
        orangeCancelCheckoutPreview();
    orangeRenderCheckoutMiniSummary();
        return;
    }

    const limitsOk = !!(limits && limits.length === items.length);
    if (limitsOk || cartMutated) {
        paintCartBasket(items, limitsOk ? limits : null);
    }

    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
    orangeRenderCheckoutMiniSummary();
    orangeSyncAmendModeBanner();
    void orangeHydrateCartVariantDisplayLang(getCart());
}

function clampCartLineQty(idx, rawQty) {
    const items = getCart();
    const item = items[idx];
    if (!item) {
        return;
    }
    const input = document.getElementById('cartQty' + idx);
    let max = null;
    if (input && input.getAttribute('max')) {
        max = parseInt(input.getAttribute('max'), 10);
    }
    if (max != null && !Number.isNaN(max) && max <= 0) {
        removeCartItem(idx);
        return;
    }
    let q = parseInt(rawQty, 10);
    if (!q || q < 1) {
        q = 1;
    }
    /* تجاوز المتاح: تنبيه بسيط ثم رجوع الخانة لقيمتها السابقة (لا قصّ صامت). */
    if (max != null && !Number.isNaN(max) && max > 0 && q > max) {
        orangeShowToast(window.APP_T.qty_not_available || 'Quantity not available', 2600);
        renderCart();
        return;
    }
    item.qty = q;
    setCart(items);
    renderCart();
}

function adjustCartQty(idx, delta) {
    const items = getCart();
    const item = items[idx];
    if (!item) {
        return;
    }
    const input = document.getElementById('cartQty' + idx);
    let max = null;
    if (input && input.getAttribute('max')) {
        max = parseInt(input.getAttribute('max'), 10);
    }
    if (max != null && !Number.isNaN(max) && max <= 0) {
        removeCartItem(idx);
        return;
    }
    const cur = Math.max(1, parseInt(item.qty, 10) || 1);
    if (delta < 0 && cur <= 1) {
        const msg = window.APP_T.cart_remove_confirm || 'Remove this product from your cart?';
        if (confirm(msg)) {
            removeCartItem(idx);
        }
        return;
    }
    let q = cur + delta;
    if (q < 1) {
        q = 1;
    }
    if (max != null && !Number.isNaN(max) && max > 0) {
        if (q > max) {
            if (delta > 0) {
                const tpl = window.APP_T.available_max_qty || 'Max: {n}';
                orangeShowToast(tpl.replace(/\{n\}/g, String(max)), 3200);
            }
            q = max;
        }
    }
    item.qty = q;
    setCart(items);
    renderCart();
}

function setCartQtyFromInput(idx) {
    const input = document.getElementById('cartQty' + idx);
    if (!input) {
        return;
    }
    clampCartLineQty(idx, input.value);
}

function setCartQtyFromSelect(idx) {
    const sel = document.getElementById('cartQtySel' + idx);
    if (!sel) {
        return;
    }
    clampCartLineQty(idx, sel.value);
}

/* خيارات القائمة المنسدلة لكمية بند السلة: 1..المتاح (بسقف عرض)، مع ضمان ظهور الكمية الحالية. */
function orangeCartQtyOptions(qty, maxStock) {
    const cap = 99;
    const fallback = 10;
    let top = maxStock != null && maxStock > 0 ? Math.min(maxStock, cap) : fallback;
    top = Math.max(top, qty, 1);
    let opts = '';
    for (let i = 1; i <= top; i++) {
        opts += '<option value="' + i + '"' + (i === qty ? ' selected' : '') + '>' + i + '</option>';
    }
    return opts;
}

function removeCartItem(index) {
    const items = getCart();
    const victim = items[index];
    if (victim) {
        delete orangeCartLineIncluded[orangeCartLineKey(victim)];
    }
    items.splice(index, 1);
    setCart(items);
    orangeShowToast(window.APP_T.item_removed_from_cart || '', 2200);
    renderCart();
}

function orangeFinishCheckoutSuccess(result, opts) {
    opts = opts || {};
    try {
        window.__orangePendingAmend = null;
    } catch (e0) {}
    orangeClearPendingAmendStorage();
    orangeSyncAmendModeBanner();
    /* نجاح الخادم برقم الطلب = التأكيد. نغلق الكشاشة ورسالة التأكيد وننتقل لتاب «طلباتي»،
       وواتساب يفتح كإشعار فقط (لا يُعتمد عليه للتأكيد). */
    if (typeof orangeCloseCheckoutConfirm === 'function') {
        try {
            orangeCloseCheckoutConfirm();
        } catch (eC) {}
    }
    if (typeof orangeCloseCheckoutOverlay === 'function') {
        try {
            orangeCloseCheckoutOverlay();
        } catch (eO) {}
    }
    try {
        sessionStorage.setItem('orange_cart_ui_tab', 'orders');
    } catch (eT) {}
    const orderedKeys = opts.orderedLineKeys;
    if (orderedKeys && orderedKeys.size > 0) {
        const cartNow = getCart();
        const next = cartNow.filter((it) => !orderedKeys.has(orangeCartLineKey(it)));
        setCart(next);
        orderedKeys.forEach((k) => delete orangeCartLineIncluded[k]);
    } else {
        localStorage.removeItem(getCartStorageKey());
        try {
            localStorage.removeItem('cart');
        } catch (e) {}
        orangeCartClearLineChoiceState();
    }
    window.open(result.whatsapp_url, '_blank');
    const T = window.APP_T || {};
    let okMsg = (T.order_number || 'Order Number') + ': ' + String(result.order_number || '');
    if (typeof result.total === 'number' && Number.isFinite(result.total)) {
        okMsg += ' · ' + (T.cart_total_label || 'Total') + ' ' + formatMoney(result.total);
    }
    const sfUnit = (typeof window !== 'undefined' && window.ORANGE_SF_CURRENCY_UNIT) ? String(window.ORANGE_SF_CURRENCY_UNIT) : 'KD';
    const cd = typeof result.combo_discount === 'number' ? result.combo_discount : 0;
    if (cd > 1e-6) {
        okMsg += ' · ' + (T.cart_combo_discount_label || '') + ' −' + Number(cd).toFixed(2) + ' ' + sfUnit;
    }
    const pd = typeof result.promotion_discount === 'number' ? result.promotion_discount : 0;
    if (pd > 1e-6) {
        okMsg += ' · ' + (T.cart_promotion_discount_label || '') + ' −' + Number(pd).toFixed(2) + ' ' + sfUnit;
    }
    const df = typeof result.delivery_fee === 'number' ? result.delivery_fee : 0;
    if (df > 1e-6) {
        okMsg += ' · ' + (T.checkout_delivery_fee_label || 'Delivery fee') + ' +' + Number(df).toFixed(2) + ' ' + sfUnit;
    }
    orangeShowToast(okMsg, Math.max(3400, okMsg.length > 72 ? 4800 : 3400));
    setTimeout(() => {
        location.reload();
    }, 3000);
}

async function orangePollIntakeUntilDone(token) {
    const maxMs = 90000;
    const start = Date.now();
    while (Date.now() - start < maxMs) {
        const r = await fetch(
            storefrontApiUrl('/api/orders/intake-status.php?token=' + encodeURIComponent(token))
        );
        let j = null;
        try {
            j = await r.json();
        } catch (e) {
            j = null;
        }
        if (!r.ok && (!j || typeof j !== 'object')) {
            throw { checkoutResult: { code: 'server_error' } };
        }
        if (!r.ok && j && typeof j === 'object') {
            throw { checkoutResult: j };
        }
        if (j && j.status === 'completed' && j.whatsapp_url) {
            return j;
        }
        if (j && j.status === 'failed') {
            throw { checkoutResult: j };
        }
        if (j && j.success === false && j.message && j.status !== 'pending') {
            throw { checkoutResult: j };
        }
        await new Promise(function (res) {
            setTimeout(res, 450);
        });
    }
    throw { checkoutResult: { code: 'queue_timeout' } };
}

async function sendOrderNow() {
    const items = getCart();
    if (!items.length) {
        orangeShowToast(window.APP_T.empty_cart || 'Cart is empty.', 2800);
        return;
    }

    const amend = window.__orangePendingAmend;
    if (amend && amend.order_number) {
        const ccAm =
            typeof window.orangeStorefrontPhoneCountryDigits === 'function'
                ? window.orangeStorefrontPhoneCountryDigits('customer_phone_country')
                : null;
        if (ccAm === null || ccAm === undefined) {
            orangeShowToast(
                (window.APP_T && window.APP_T.phone_country_required) ||
                    (window.APP_T && window.APP_T.checkout_required_fields) ||
                    '',
                3600
            );
            return;
        }
        const intlAm =
            (document.getElementById('customer_phone_country') || {}).value === '__intl__';
        const phoneRawAm = document.getElementById('customer_phone')
            ? document.getElementById('customer_phone').value.trim()
            : '';
        const phoneNormAm =
            typeof window.orangeNormalizeCustomerPhone === 'function'
                ? window.orangeNormalizeCustomerPhone(
                      phoneRawAm,
                      intlAm ? null : ccAm,
                      intlAm
                  )
                : null;
        const amendNorm =
            typeof window.orangeNormalizeCustomerPhone === 'function'
                ? window.orangeNormalizeCustomerPhone(String(amend.phone || '').trim(), null)
                : null;
        if (!phoneNormAm || !amendNorm || phoneNormAm !== amendNorm) {
            orangeShowToast(
                (window.APP_T && window.APP_T.customer_amend_phone_mismatch) ||
                    (window.APP_T && window.APP_T.checkout_invalid_phone) ||
                    '',
                3600
            );
            return;
        }
        const payloadAm = {
            order_number: String(amend.order_number).trim(),
            phone: phoneNormAm,
            items: items,
            lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
        };
        try {
            const gp = window.__orangeLastGiftPromotion;
            if (gp && gp.gift_kind === 'choice') {
                const vid = parseInt(String(window.__orangeCheckoutGiftVariantId || 0), 10) || 0;
                if (!vid) {
                    orangeShowToast(
                        (window.APP_T && window.APP_T.checkout_gift_pick_required) || '',
                        3600
                    );
                    return;
                }
                payloadAm.gift_variant_id = vid;
            } else if (gp && gp.gift_kind === 'fixed' && gp.fixed_variant_id) {
                payloadAm.gift_variant_id = parseInt(String(gp.fixed_variant_id), 10) || 0;
            }
        } catch (eAmGift) {}
        try {
            const bp = window.__orangeLastBogoPromotion;
            if (bp && bp.gift_kind === 'choice') {
                const bvid = parseInt(String(window.__orangeCheckoutBogoGiftVariantId || 0), 10) || 0;
                if (!bvid) {
                    orangeShowToast(
                        (window.APP_T && window.APP_T.checkout_bogo_gift_pick_required) || '',
                        3600
                    );
                    return;
                }
                payloadAm.bogo_gift_variant_id = bvid;
            } else if (bp && bp.gift_kind === 'fixed' && bp.fixed_variant_id) {
                payloadAm.bogo_gift_variant_id = parseInt(String(bp.fixed_variant_id), 10) || 0;
            }
        } catch (eAmBogo) {}
        let responseAm;
        let resultAm;
        try {
            responseAm = await fetch(storefrontApiUrl('/api/orders/amend-order-items.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payloadAm),
            });
            resultAm = await responseAm.json();
        } catch (eAm) {
            orangeShowToast(
                (window.APP_T && window.APP_T.api_request_failed) ||
                    (window.APP_T && window.APP_T.checkout_failed_generic) ||
                    '',
                3800
            );
            return;
        }
        if (!resultAm || typeof resultAm !== 'object') {
            orangeShowToast(
                (window.APP_T && window.APP_T.api_request_failed) ||
                    (window.APP_T && window.APP_T.checkout_failed_generic) ||
                    '',
                3800
            );
            return;
        }
        if (!resultAm.success) {
            orangeShowToast(
                orangeCheckoutApiMessage(resultAm) || (window.APP_T && window.APP_T.checkout_failed_generic) || '',
                3800
            );
            return;
        }
        orangeFinishCheckoutSuccess(resultAm);
        return;
    }

    const allCart = getCart();
    let itemsForOrder = allCart;
    let orderedLineKeys = null;
    if (orangeCartLineChoiceApplies(allCart)) {
        itemsForOrder = orangeCartGetSelectedItems(allCart);
        if (!itemsForOrder.length) {
            orangeShowToast(
                (window.APP_T && window.APP_T.cart_no_lines_selected_for_order) ||
                    (window.APP_T && window.APP_T.checkout_cart_items_required) ||
                    '',
                3200
            );
            return;
        }
        orderedLineKeys = new Set();
        itemsForOrder.forEach((it) => orderedLineKeys.add(orangeCartLineKey(it)));
    }
    const checkoutFinishOpts =
        orderedLineKeys && orderedLineKeys.size > 0 ? { orderedLineKeys: orderedLineKeys } : {};

    const payRadio = document.querySelector('input[name="checkout_payment_terms"]:checked');
    const paymentOnlineEnabled = window.ORANGE_PAYMENT_ONLINE_ENABLED === true;
    const paymentTerms =
        paymentOnlineEnabled && payRadio && payRadio.value === 'online' ? 'online' : 'cash';

    const emailEl = document.getElementById('customer_email');
    const emailRaw = emailEl ? emailEl.value.trim() : '';
    const checkoutCc =
        typeof window.orangeStorefrontPhoneCountryDigits === 'function'
            ? window.orangeStorefrontPhoneCountryDigits('customer_phone_country')
            : null;
    if (checkoutCc === null || checkoutCc === undefined) {
        orangeShowToast(
            (window.APP_T && window.APP_T.phone_country_required) ||
                (window.APP_T && window.APP_T.checkout_required_fields) ||
                '',
            3600
        );
        return;
    }
    const checkoutIntl =
        (document.getElementById('customer_phone_country') || {}).value === '__intl__';
    const phoneRaw = document.getElementById('customer_phone')
        ? document.getElementById('customer_phone').value.trim()
        : '';
    const phoneNorm =
        typeof window.orangeNormalizeCustomerPhone === 'function'
            ? window.orangeNormalizeCustomerPhone(
                  phoneRaw,
                  checkoutIntl ? null : checkoutCc,
                  checkoutIntl
              )
            : null;
    const areaEl = document.getElementById('customer_area');
    let deliveryAreaId = 0;
    let areaVal = '';
    if (areaEl && areaEl.tagName === 'SELECT' && areaEl.disabled) {
        orangeShowToast(
            (window.APP_T && window.APP_T.checkout_delivery_areas_unavailable) ||
                (window.APP_T && window.APP_T.checkout_delivery_area_required) ||
                '',
            4200
        );
        return;
    }
    if (areaEl && areaEl.tagName === 'SELECT') {
        deliveryAreaId = parseInt(areaEl.value, 10) || 0;
        const opt = areaEl.options[areaEl.selectedIndex];
        areaVal = opt ? String(opt.textContent || '').trim() : '';
    } else if (areaEl) {
        areaVal = areaEl.value.trim();
    }
    const payload = {
        name: document.getElementById('customer_name').value.trim(),
        phone: phoneNorm || phoneRaw,
        phone_country: checkoutIntl ? '__intl__' : checkoutCc || '',
        email: emailRaw,
        area: areaVal,
        delivery_area_id: deliveryAreaId,
        address: document.getElementById('customer_address').value.trim(),
        notes: document.getElementById('customer_notes').value.trim(),
        channel_id: window.APP_CHANNEL_ID || 0,
        items: itemsForOrder,
        payment_terms: paymentTerms,
        redeem_points: __orangeLoyaltyRedeemPoints,
        lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
    };

    try {
        const gp = window.__orangeLastGiftPromotion;
        if (gp && gp.gift_kind === 'choice') {
            const vid = parseInt(String(window.__orangeCheckoutGiftVariantId || 0), 10) || 0;
            if (!vid) {
                orangeShowToast(
                    (window.APP_T && window.APP_T.checkout_gift_pick_required) || '',
                    3600
                );
                return;
            }
            payload.gift_variant_id = vid;
        } else if (gp && gp.gift_kind === 'fixed' && gp.fixed_variant_id) {
            payload.gift_variant_id = parseInt(String(gp.fixed_variant_id), 10) || 0;
        }
    } catch (eGift) {}

    try {
        const bp = window.__orangeLastBogoPromotion;
        if (bp && bp.gift_kind === 'choice') {
            const bvid = parseInt(String(window.__orangeCheckoutBogoGiftVariantId || 0), 10) || 0;
            if (!bvid) {
                orangeShowToast(
                    (window.APP_T && window.APP_T.checkout_bogo_gift_pick_required) || '',
                    3600
                );
                return;
            }
            payload.bogo_gift_variant_id = bvid;
        } else if (bp && bp.gift_kind === 'fixed' && bp.fixed_variant_id) {
            payload.bogo_gift_variant_id = parseInt(String(bp.fixed_variant_id), 10) || 0;
        }
    } catch (eBogo) {}

    if (!payload.name || !phoneRaw || !payload.address) {
        orangeShowToast(window.APP_T.checkout_required_fields || 'Please fill all required fields.', 3200);
        return;
    }
    if (areaEl && areaEl.tagName === 'SELECT') {
        if (!deliveryAreaId) {
            orangeShowToast(
                window.APP_T.checkout_delivery_area_required || window.APP_T.checkout_required_fields || '',
                3200
            );
            return;
        }
    } else if (!areaVal) {
        orangeShowToast(window.APP_T.checkout_required_fields || 'Please fill all required fields.', 3200);
        return;
    }
    if (!phoneNorm) {
        orangeShowToast(window.APP_T.checkout_invalid_phone || window.APP_T.storefront_register_invalid_phone || '', 3600);
        return;
    }
    payload.phone = phoneNorm;
    if (emailRaw !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailRaw)) {
        orangeShowToast(window.APP_T.checkout_invalid_email || 'Invalid email.', 3200);
        return;
    }

    let response;
    let result;
    try {
        response = await fetch(storefrontApiUrl('/api/orders/create-order.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
        result = await response.json();
    } catch (e) {
        orangeShowToast(
            (window.APP_T && window.APP_T.api_request_failed) ||
                (window.APP_T && window.APP_T.checkout_failed_generic) ||
                '',
            3800
        );
        return;
    }
    if (!result || typeof result !== 'object') {
        orangeShowToast(
            (window.APP_T && window.APP_T.api_request_failed) ||
                (window.APP_T && window.APP_T.checkout_failed_generic) ||
                '',
            3800
        );
        return;
    }

    if (response.status === 503 && result && result.intake_token) {
        orangeShowToast(window.APP_T.checkout_queue_wait || 'Processing order…', 2600);
        try {
            const done = await orangePollIntakeUntilDone(result.intake_token);
            orangeFinishCheckoutSuccess(done, checkoutFinishOpts);
        } catch (e) {
            const wrap = e && e.checkoutResult ? e.checkoutResult : { message: e && e.message ? String(e.message) : '' };
            orangeShowToast(orangeCheckoutApiMessage(wrap) || (window.APP_T.checkout_failed_generic || 'Checkout failed'), 4200);
        }
        return;
    }

    if (!result.success) {
        orangeShowToast(orangeCheckoutApiMessage(result) || (window.APP_T.checkout_failed_generic || 'Failed to create order'), 3600);
        return;
    }

    orangeFinishCheckoutSuccess(result, checkoutFinishOpts);
}

function orangeEscDomText(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

function orangeEscDomAttr(s) {
    return orangeEscDomText(s).replace(/'/g, '&#39;');
}

/** س14: ضيف يُلغي في pending فقط؛ مسجّل من الجلسة يُلغي pending أو approved (قبل الشحن). */
function orangeCustomerCanCancelByStatus(st) {
    const s = String(st || '')
        .toLowerCase()
        .trim();
    const loggedIn = typeof window.ORANGE_SF_LOGGED_IN !== 'undefined' && window.ORANGE_SF_LOGGED_IN === true;
    if (s === 'pending') {
        return true;
    }
    if (s === 'approved') {
        return loggedIn;
    }
    return false;
}

function orangeCartItemsFromOrderItemRows(rows) {
    const out = [];
    if (!Array.isArray(rows)) {
        return out;
    }
    for (let i = 0; i < rows.length; i++) {
        const it = rows[i];
        const pid = parseInt(String(it.product_id || 0), 10);
        if (!pid) {
            continue;
        }
        const o = { id: pid, qty: Math.max(1, parseInt(String(it.qty || 1), 10) || 1) };
        if (it.color != null && String(it.color).trim() !== '') {
            o.color = String(it.color);
        }
        if (it.size != null && String(it.size).trim() !== '') {
            o.size = String(it.size);
        }
        const vid = parseInt(String(it.variant_id || 0), 10);
        if (vid > 0) {
            o.variant_id = vid;
        }
        out.push(o);
    }
    return out;
}

async function orangeCartStartAmendOrder(onum, ph) {
    const on = String(onum || '').trim();
    const p = String(ph || '').trim();
    const T = window.APP_T || {};
    if (!on || !p) {
        return;
    }
    const lang =
        typeof window.APP_LANG === 'string' && window.APP_LANG.trim() !== ''
            ? window.APP_LANG.trim().toLowerCase()
            : 'en';
    let result;
    try {
        const response = await fetch(storefrontApiUrl('/api/orders/get-order.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ order_number: on, phone: p, lang: lang }),
        });
        result = await response.json();
    } catch (e) {
        orangeShowToast(T.api_request_failed || '', 3800);
        return;
    }
    if (!result || !result.success || !result.order) {
        orangeShowToast(orangeCheckoutApiMessage(result || {}) || T.api_request_failed || '', 3600);
        return;
    }
    const st = String(result.order.status || '')
        .toLowerCase()
        .trim();
    if (!orangeCustomerCanCancelByStatus(st)) {
        orangeShowToast(T.customer_amend_not_allowed || '', 3600);
        return;
    }
    const cartLines = orangeCartItemsFromOrderItemRows(result.items || []);
    if (!cartLines.length) {
        orangeShowToast(T.checkout_cart_items_required || '', 3200);
        return;
    }
    setCart(cartLines);
    normalizeCartDuplicates();
    window.__orangePendingAmend = {
        order_number: on,
        phone: String(result.order.phone || p).trim(),
    };
    orangePendingAmendToStorage(window.__orangePendingAmend);

    const onCartPage = !!document.getElementById('cartProceedBtn');
    if (!onCartPage) {
        const cartUrl =
            typeof window.ORANGE_STOREFRONT_CART_URL === 'string' ? window.ORANGE_STOREFRONT_CART_URL.trim() : '';
        if (cartUrl) {
            try {
                sessionStorage.setItem('orange_cart_ui_tab', 'basket');
            } catch (eTab) {}
            window.location.href = cartUrl;
            return;
        }
    }

    renderCart();
    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
    orangeRenderCheckoutMiniSummary();
    orangeShowToast(T.customer_amend_loaded || '', 4200);
    if (typeof window.orangeCartUiShowTab === 'function') {
        window.orangeCartUiShowTab('basket');
    }
    requestAnimationFrame(() => {
        const list = document.getElementById('cartItems');
        if (list && typeof list.scrollIntoView === 'function') {
            try {
                list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (e2) {
                list.scrollIntoView(true);
            }
        }
    });
}

function orangeCustomerAmendOrderFromTrack() {
    const ctx = window.__orangeCartTrack;
    if (!ctx || !ctx.orderNumber || !ctx.phone) {
        return;
    }
    orangeCartStartAmendOrder(ctx.orderNumber, ctx.phone);
}

window.orangeCartStartAmendOrder = orangeCartStartAmendOrder;
window.orangeCustomerAmendOrderFromTrack = orangeCustomerAmendOrderFromTrack;

function orangeOrderCartPromoDiscountAmount(order) {
    if (!order || order.cart_promotion_discount == null) {
        return 0;
    }
    const n =
        typeof order.cart_promotion_discount === 'number'
            ? order.cart_promotion_discount
            : parseFloat(String(order.cart_promotion_discount));
    if (!Number.isFinite(n) || n <= 0) {
        return 0;
    }
    return n;
}

function orangeOrderCartComboDiscountAmount(order) {
    if (!order || order.cart_combo_discount == null) {
        return 0;
    }
    const n =
        typeof order.cart_combo_discount === 'number'
            ? order.cart_combo_discount
            : parseFloat(String(order.cart_combo_discount));
    if (!Number.isFinite(n) || n <= 0) {
        return 0;
    }
    return n;
}

function orangeOrderDeliveryFeeAmount(order) {
    if (!order || order.delivery_fee == null) {
        return 0;
    }
    const n =
        typeof order.delivery_fee === 'number'
            ? order.delivery_fee
            : parseFloat(String(order.delivery_fee));
    if (!Number.isFinite(n) || n <= 0) {
        return 0;
    }
    return n;
}

/** صافي سطر الطلب بعد خصم السطر — مطابقة تقريبية لـ orange_order_item_line_net في PHP. */
function orangeOrderItemLineNetJs(it) {
    const qty = Math.max(0, parseInt(String(it.qty || 0), 10) || 0);
    const price = parseFloat(String(it.price || 0)) || 0;
    const gross = Math.round(price * qty * 10000) / 10000;
    let disc = parseFloat(String(it.line_discount != null ? it.line_discount : 0)) || 0;
    if (disc < 0) {
        disc = 0;
    }
    disc = Math.round(disc * 10000) / 10000;
    if (disc > gross + 0.0001) {
        return 0;
    }
    return Math.max(0, Math.round((gross - disc) * 10000) / 10000);
}

function orangeOrderItemsLinesSum(itemRows) {
    if (!Array.isArray(itemRows)) {
        return 0;
    }
    let s = 0;
    for (let i = 0; i < itemRows.length; i++) {
        s += orangeOrderItemLineNetJs(itemRows[i]);
    }
    return s;
}

/** يفضّل مجموع البنود من السيرفر (نفس منطق الفاتورة) وإلا يحسب من الصفوف. */
function orangeOrderLinesSubtotalPreferServer(order, itemRows) {
    if (order && order.lines_subtotal != null && order.lines_subtotal !== '') {
        const n =
            typeof order.lines_subtotal === 'number'
                ? order.lines_subtotal
                : parseFloat(String(order.lines_subtotal));
        if (Number.isFinite(n) && n >= 0) {
            return n;
        }
    }
    return orangeOrderItemsLinesSum(itemRows);
}

function orangeHtmlTrackShareReferenceBlock(orderNumber) {
    const T = window.APP_T || {};
    const title = String(T.track_share_reference_title || '').trim();
    const hint = String(T.track_share_reference_hint || '').trim();
    const copyLbl = String(T.track_share_reference_copy || '').trim();
    const code = String(orderNumber || '').trim();
    if (!title || !code || !copyLbl) {
        return '';
    }
    let html = '<div class="track-share-ref">';
    html += '<p class="track-share-ref__title"><strong>' + orangeEscDomText(title) + '</strong></p>';
    html += '<div class="track-share-ref__row">';
    html += '<code class="track-share-ref__code" translate="no">' + orangeEscDomText(code) + '</code>';
    html +=
        '<button type="button" class="btn btn-secondary track-share-ref__copy" onclick="orangeCopyTrackShareCode(this)">' +
        orangeEscDomText(copyLbl) +
        '</button>';
    html += '</div>';
    if (hint) {
        html += '<p class="track-share-ref__hint">' + orangeEscDomText(hint) + '</p>';
    }
    html += '</div>';
    return html;
}

function orangeCopyTrackShareCode(btn) {
    const wrap = btn && btn.closest ? btn.closest('.track-share-ref') : null;
    const codeEl = wrap ? wrap.querySelector('.track-share-ref__code') : null;
    const text = codeEl ? String(codeEl.textContent || '').trim() : '';
    const T = window.APP_T || {};
    const okMsg = String(T.track_share_reference_copied || '').trim() || 'OK';
    if (!text) {
        return;
    }
    const done = function () {
        if (typeof window.orangeShowToast === 'function') {
            window.orangeShowToast(okMsg, 2200);
        }
    };
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).then(done).catch(function () {
            try {
                const r = document.createRange();
                r.selectNodeContents(codeEl);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(r);
                document.execCommand('copy');
                sel.removeAllRanges();
    } catch (e) {}
            done();
        });
        return;
    }
    try {
        const r = document.createRange();
        r.selectNodeContents(codeEl);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
        document.execCommand('copy');
        sel.removeAllRanges();
    } catch (e2) {}
    done();
}

window.orangeCopyTrackShareCode = orangeCopyTrackShareCode;

function orangeHtmlOrderPromoParagraph(order, cur, paragraphClass) {
    const d = orangeOrderCartPromoDiscountAmount(order);
    if (d <= 1e-9) {
        return '';
    }
    const T = window.APP_T || {};
    const lbl = T.cart_promotion_discount_label || '';
    const cls = paragraphClass ? ' class="' + orangeEscDomAttr(paragraphClass) + '"' : '';
    return (
        '<p' +
        cls +
        '><strong>' +
        orangeEscDomText(lbl) +
        ':</strong> −' +
        orangeEscDomText(d.toFixed(3)) +
        ' ' +
        orangeEscDomText(String(cur || '')) +
        '</p>'
    );
}

function orangeHtmlOrderComboParagraph(order, cur, paragraphClass) {
    const d = orangeOrderCartComboDiscountAmount(order);
    if (d <= 1e-9) {
        return '';
    }
    const T = window.APP_T || {};
    const lbl = T.cart_combo_discount_label || '';
    const cls = paragraphClass ? ' class="' + orangeEscDomAttr(paragraphClass) + '"' : '';
    return (
        '<p' +
        cls +
        '><strong>' +
        orangeEscDomText(lbl) +
        ':</strong> −' +
        orangeEscDomText(d.toFixed(3)) +
        ' ' +
        orangeEscDomText(String(cur || '')) +
        '</p>'
    );
}

function orangeHtmlOrderDeliveryFeeParagraph(order, cur, paragraphClass) {
    const d = orangeOrderDeliveryFeeAmount(order);
    if (d <= 1e-9) {
        return '';
    }
    const T = window.APP_T || {};
    const lbl = T.checkout_delivery_fee_label || 'Delivery fee';
    const cls = paragraphClass ? ' class="' + orangeEscDomAttr(paragraphClass) + '"' : '';
    return (
        '<p' +
        cls +
        '><strong>' +
        orangeEscDomText(lbl) +
        ':</strong> +' +
        orangeEscDomText(d.toFixed(3)) +
        ' ' +
        orangeEscDomText(String(cur || '')) +
        '</p>'
    );
}

function orangeRenderTrackedOrderBox(resultBox, order, orderNumber, phoneTyped, items) {
    const UI = window.ORANGE_MY_ORDER_UI || {};
    const labels = window.ORANGE_ORDER_STATUS_LABELS || {};
    const L = window.ORANGE_TRACK_LABELS || {};
    const T = window.APP_T || {};
    const st = String(order.status || '').toLowerCase().trim();
    const statusText = labels[st] || order.status || '—';
    const lblOrder = L.order_number || 'Order #';
    const lblPhone = L.phone || 'Phone';
    const cur = String(UI.currency || 'KD');
    const itemRows = Array.isArray(items) ? items : [];

    const canCancel = orangeCustomerCanCancelByStatus(st);
    let waUrl = null;
    const prefillRaw = String(UI.whatsapp_prefill || '').replace(/\{order\}/g, String(order.order_number || orderNumber));
    const waBase = window.ORANGE_STOREFRONT_WA;
    if (waBase && typeof waBase === 'string') {
        const q = waBase.indexOf('?');
        const path = q >= 0 ? waBase.substring(0, q) : waBase;
        waUrl = path + '?text=' + encodeURIComponent(prefillRaw);
    }

    window.__orangeCartTrack = { orderNumber: orderNumber, phone: phoneTyped, order: order };

    let html = '<div class="track-box track-box--order">';
    html += '<p class="order-status-row"><strong>' + orangeEscDomText(UI.status_label || '') + ':</strong> ';
    html += '<span class="order-status-pill order-status-pill--' + orangeEscDomText(st) + '">' + orangeEscDomText(statusText) + '</span></p>';
    html += '<p><strong>' + orangeEscDomText(lblOrder) + ':</strong> ' + orangeEscDomText(String(order.order_number || '')) + '</p>';
    html += orangeHtmlTrackShareReferenceBlock(String(order.order_number || orderNumber || ''));
    if (order.phone) {
        html += '<p><strong>' + orangeEscDomText(lblPhone) + ':</strong> ' + orangeEscDomText(String(order.phone)) + '</p>';
    }
    const itemsLinesSum = orangeOrderLinesSubtotalPreferServer(order, itemRows);
    if (
        (
            orangeOrderCartPromoDiscountAmount(order) > 1e-9 ||
            orangeOrderCartComboDiscountAmount(order) > 1e-9 ||
            orangeOrderDeliveryFeeAmount(order) > 1e-9
        ) &&
        itemsLinesSum > 1e-9
    ) {
        html +=
            '<p class="order-items-subtotal-line"><strong>' +
            orangeEscDomText(T.cart_subtotal_label || '') +
            ':</strong> ' +
            itemsLinesSum.toFixed(3) +
            ' ' +
            orangeEscDomText(cur) +
            '</p>';
    }
    html += orangeHtmlOrderComboParagraph(order, cur, 'order-cart-promo-line');
    html += orangeHtmlOrderPromoParagraph(order, cur, 'order-cart-promo-line');
    html += orangeHtmlOrderDeliveryFeeParagraph(order, cur, 'order-cart-promo-line');
    html += '<p><strong>' + orangeEscDomText(UI.order_total_label || '') + ':</strong> ' + orangeEscDomText(String(order.total)) + ' ' + orangeEscDomText(cur) + '</p>';
    const pt = String(order.payment_terms || 'cash').toLowerCase();
    const ptLabel = pt === 'credit' ? (UI.payment_credit || '') : (pt === 'online' ? (UI.payment_online || '') : (UI.payment_cash || ''));
    if (UI.payment_label && ptLabel) {
        html += '<p><strong>' + orangeEscDomText(UI.payment_label) + ':</strong> ' + orangeEscDomText(ptLabel) + '</p>';
    }

    if (itemRows.length > 0) {
        const itemsTitle = L.items_title || 'Items';
        const qtyLbl = T.quantity || 'Qty';
        html += '<div class="track-order-items-wrap">';
        html += '<h4 class="track-order-items-title">' + orangeEscDomText(itemsTitle) + '</h4>';
        html += '<ul class="track-order-items">';
        for (let i = 0; i < itemRows.length; i++) {
            const it = itemRows[i];
            const qty = Math.max(0, parseInt(String(it.qty || 0), 10) || 0);
            const line = orangeOrderItemLineNetJs(it);
            html += '<li class="track-order-item">';
            html += '<span class="track-order-item__main">';
            html += '<span class="track-order-item__name">' + orangeEscDomText(String(it.product_name || '')) + '</span>';
            const meta = [];
            if (it.color) {
                meta.push(orangeEscDomText(String(it.color)));
            }
            if (it.size) {
                meta.push(orangeEscDomText(String(it.size)));
            }
            if (meta.length) {
                html += '<span class="track-order-item__meta">' + meta.join(' · ') + '</span>';
            }
            const ldTrk = parseFloat(String(it.line_discount != null ? it.line_discount : 0)) || 0;
            if (ldTrk > 1e-6) {
                const ldR = Math.round(ldTrk * 10000) / 10000;
                html +=
                    '<span class="track-order-item__line-disc">' +
                    orangeEscDomText(T.track_line_discount_label || '') +
                    ' −' +
                    ldR.toFixed(3) +
                    ' ' +
                    orangeEscDomText(cur) +
                    '</span>';
            }
            html += '</span>';
            html +=
                '<span class="track-order-item__qty">' +
                orangeEscDomText(qtyLbl) +
                ' × ' +
                qty +
                '</span>';
            html +=
                '<span class="track-order-item__sub">' +
                line.toFixed(3) +
                ' ' +
                orangeEscDomText(cur) +
                '</span>';
            html += '</li>';
        }
        html += '</ul></div>';
    }

    const emailIntro = T.track_email_summary_intro || '';
    const emailPh = T.track_email_summary_placeholder || '';
    const emailSend = T.track_email_summary_send || '';
    if (emailIntro && emailSend) {
        html += '<div class="track-email-summary">';
        html += '<p class="track-email-summary__intro">' + orangeEscDomText(emailIntro) + '</p>';
        html += '<div class="track-email-summary__row">';
        html +=
            '<input type="email" id="trackOrderEmailSummaryInput" class="track-email-summary__input" autocomplete="email" inputmode="email" dir="ltr" placeholder="' +
            orangeEscDomAttr(emailPh) +
            '">';
        html +=
            '<button type="button" class="btn btn-secondary track-email-summary__btn" id="trackOrderEmailSummaryBtn" onclick="orangeSendTrackOrderEmailSummary()">' +
            orangeEscDomText(emailSend) +
            '</button>';
        html += '</div>';
        html +=
            '<p class="track-email-summary__feedback" id="trackOrderEmailSummaryFeedback" role="status" aria-live="polite" hidden></p>';
        html += '</div>';
    }

    html += '<div class="customer-order-actions">';
    html += '<button type="button" class="btn btn-secondary customer-order-amend"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.amend_not_allowed || '') + '"';
    }
    html += ' onclick="orangeCustomerAmendOrderFromTrack()">' + orangeEscDomText(UI.amend || '') + '</button>';
    html += '<button type="button" class="btn btn-danger customer-order-cancel"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.cancel_not_allowed || '') + '"';
    }
    html += ' onclick="orangeCustomerCancelOrder()">' + orangeEscDomText(UI.cancel || '') + '</button>';
    if (waUrl) {
        html += '<a class="btn btn-secondary customer-order-wa" href="' + orangeEscDomAttr(waUrl) + '" target="_blank" rel="noopener noreferrer">';
        html += orangeEscDomText(UI.whatsapp_help || 'WhatsApp') + '</a>';
    }
    html += '</div>';
    if (!canCancel && st !== 'cancelled' && st !== 'rejected') {
        html += '<p class="cart-cancel-hint">' + orangeEscDomText(UI.cancel_not_allowed || '') + '</p>';
    }
    if (document.getElementById('track-no-signup-section')) {
        const anotherLbl = L.track_another_order || '';
        if (anotherLbl) {
            html += '<div class="track-order-another-wrap">';
            html +=
                '<button type="button" class="btn btn-ghost track-order-another-btn" onclick="orangeScrollTrackAnotherOrder()">';
            html += orangeEscDomText(anotherLbl);
            html += '</button></div>';
        }
    }
    html += '</div>';
    resultBox.innerHTML = html;
}

function orangeScrollTrackAnotherOrder() {
    const resultBox = document.getElementById('trackResult');
    if (resultBox) {
        resultBox.innerHTML = '';
    }
    try {
        window.__orangeCartTrack = null;
    } catch (e0) {}

    if (typeof window.__orangeOnTrackAnotherOrder === 'function') {
        try {
            window.__orangeOnTrackAnotherOrder();
        } catch (eHook) {}
    }

    const numEl = document.getElementById('track_order_number');
    const phEl = document.getElementById('track_phone');
    if (numEl) {
        numEl.value = '';
    }
    if (phEl) {
        phEl.value = '';
    }

    const target = document.getElementById('track-no-signup-section');
    if (target && typeof target.scrollIntoView === 'function') {
        try {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            target.scrollIntoView(true);
        }
    }
    if (numEl && typeof numEl.focus === 'function') {
        try {
            numEl.focus();
        } catch (e2) {}
    }
}

window.orangeScrollTrackAnotherOrder = orangeScrollTrackAnotherOrder;

function orangeRenderTrackSignupSummary(el, order, orderNumber, phoneTyped, items) {
    if (!el) {
        return;
    }
    const UI = window.ORANGE_MY_ORDER_UI || {};
    const labels = window.ORANGE_ORDER_STATUS_LABELS || {};
    const L = window.ORANGE_TRACK_LABELS || {};
    const T = window.APP_T || {};
    const st = String(order.status || '').toLowerCase().trim();
    const statusText = labels[st] || order.status || '—';
    const lblOrder = L.order_number || 'Order #';
    const lblPhone = L.phone || 'Phone';
    const cur = String(UI.currency || 'KD');
    const itemRows = Array.isArray(items) ? items : [];

    const canCancel = orangeCustomerCanCancelByStatus(st);
    let waUrl = null;
    const prefillRaw = String(UI.whatsapp_prefill || '').replace(/\{order\}/g, String(order.order_number || orderNumber));
    const waBase = window.ORANGE_STOREFRONT_WA;
    if (waBase && typeof waBase === 'string') {
        const q = waBase.indexOf('?');
        const path = q >= 0 ? waBase.substring(0, q) : waBase;
        waUrl = path + '?text=' + encodeURIComponent(prefillRaw);
    }

    let html = '<div class="track-signup-order-summary__inner">';
    html += '<p class="track-signup-order-summary__status"><strong>' + orangeEscDomText(UI.status_label || '') + ':</strong> ';
    html += '<span class="order-status-pill order-status-pill--' + orangeEscDomText(st) + '">' + orangeEscDomText(statusText) + '</span></p>';
    html += '<p class="track-signup-order-summary__line"><strong>' + orangeEscDomText(lblOrder) + ':</strong> ';
    html += orangeEscDomText(String(order.order_number || '')) + '</p>';
    html += orangeHtmlTrackShareReferenceBlock(String(order.order_number || orderNumber || ''));
    if (order.phone) {
        html += '<p class="track-signup-order-summary__line"><strong>' + orangeEscDomText(lblPhone) + ':</strong> ';
        html += orangeEscDomText(String(order.phone)) + '</p>';
    }
    const itemsLinesSumSu = orangeOrderLinesSubtotalPreferServer(order, itemRows);
    if (
        (
            orangeOrderCartPromoDiscountAmount(order) > 1e-9 ||
            orangeOrderCartComboDiscountAmount(order) > 1e-9 ||
            orangeOrderDeliveryFeeAmount(order) > 1e-9
        ) &&
        itemsLinesSumSu > 1e-9
    ) {
        html +=
            '<p class="track-signup-order-summary__line order-items-subtotal-line"><strong>' +
            orangeEscDomText(T.cart_subtotal_label || '') +
            ':</strong> ' +
            itemsLinesSumSu.toFixed(3) +
            ' ' +
            orangeEscDomText(cur) +
            '</p>';
    }
    html += orangeHtmlOrderComboParagraph(
        order,
        cur,
        'track-signup-order-summary__line order-cart-promo-line'
    );
    html += orangeHtmlOrderPromoParagraph(
        order,
        cur,
        'track-signup-order-summary__line order-cart-promo-line'
    );
    html += orangeHtmlOrderDeliveryFeeParagraph(
        order,
        cur,
        'track-signup-order-summary__line order-cart-promo-line'
    );
    html += '<p class="track-signup-order-summary__line"><strong>' + orangeEscDomText(UI.order_total_label || '') + ':</strong> ';
    html += orangeEscDomText(String(order.total)) + ' ' + orangeEscDomText(cur) + '</p>';
    const pt = String(order.payment_terms || 'cash').toLowerCase();
    const ptLabel = pt === 'credit' ? (UI.payment_credit || '') : (pt === 'online' ? (UI.payment_online || '') : (UI.payment_cash || ''));
    if (UI.payment_label && ptLabel) {
        html += '<p class="track-signup-order-summary__line"><strong>' + orangeEscDomText(UI.payment_label) + ':</strong> ';
        html += orangeEscDomText(ptLabel) + '</p>';
    }

    if (itemRows.length > 0) {
        const itemsTitle = L.items_title || 'Items';
        const qtyLbl = T.quantity || 'Qty';
        html += '<div class="track-signup-order-summary__items">';
        html += '<h4 class="track-signup-order-summary__items-title">' + orangeEscDomText(itemsTitle) + '</h4>';
        html += '<ul class="track-signup-order-summary__item-list">';
        for (let i = 0; i < itemRows.length; i++) {
            const it = itemRows[i];
            const qty = Math.max(0, parseInt(String(it.qty || 0), 10) || 0);
            const line = orangeOrderItemLineNetJs(it);
            html += '<li class="track-signup-order-summary__item">';
            html += '<span class="track-signup-order-summary__item-main">';
            html += '<span class="track-signup-order-summary__item-name">' + orangeEscDomText(String(it.product_name || '')) + '</span>';
            const meta = [];
            if (it.color) {
                meta.push(orangeEscDomText(String(it.color)));
            }
            if (it.size) {
                meta.push(orangeEscDomText(String(it.size)));
            }
            if (meta.length) {
                html += '<span class="track-signup-order-summary__item-meta">' + meta.join(' · ') + '</span>';
            }
            const ldSu = parseFloat(String(it.line_discount != null ? it.line_discount : 0)) || 0;
            if (ldSu > 1e-6) {
                const ldR = Math.round(ldSu * 10000) / 10000;
                html +=
                    '<span class="track-signup-order-summary__item-disc">' +
                    orangeEscDomText(T.track_line_discount_label || '') +
                    ' −' +
                    ldR.toFixed(3) +
                    ' ' +
                    orangeEscDomText(cur) +
                    '</span>';
            }
            html += '</span>';
            html +=
                '<span class="track-signup-order-summary__item-qty">' +
                orangeEscDomText(qtyLbl) +
                ' × ' +
                qty +
                '</span>';
            html +=
                '<span class="track-signup-order-summary__item-sub">' +
                line.toFixed(3) +
                ' ' +
                orangeEscDomText(cur) +
                '</span>';
            html += '</li>';
        }
        html += '</ul></div>';
    }

    html += '<div class="track-signup-order-summary__actions customer-order-actions">';
    html += '<button type="button" class="btn btn-secondary customer-order-amend"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.amend_not_allowed || '') + '"';
    }
    html += ' onclick="orangeCustomerAmendOrderFromTrack()">' + orangeEscDomText(UI.amend || '') + '</button>';
    html += '<button type="button" class="btn btn-danger customer-order-cancel"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.cancel_not_allowed || '') + '"';
    }
    html += ' onclick="orangeCustomerCancelOrder()">' + orangeEscDomText(UI.cancel || '') + '</button>';
    if (waUrl) {
        html += '<a class="btn btn-secondary customer-order-wa" href="' + orangeEscDomAttr(waUrl) + '" target="_blank" rel="noopener noreferrer">';
        html += orangeEscDomText(UI.whatsapp_help || 'WhatsApp') + '</a>';
    }
    html += '</div>';
    if (!canCancel && st !== 'cancelled' && st !== 'rejected') {
        html += '<p class="track-signup-order-summary__hint cart-cancel-hint">' + orangeEscDomText(UI.cancel_not_allowed || '') + '</p>';
    }
    if (document.getElementById('track-no-signup-section')) {
        const anotherLblSu = L.track_another_order || '';
        if (anotherLblSu) {
            html += '<div class="track-order-another-wrap">';
            html +=
                '<button type="button" class="btn btn-ghost track-order-another-btn" onclick="orangeScrollTrackAnotherOrder()">';
            html += orangeEscDomText(anotherLblSu);
            html += '</button></div>';
        }
    }
    html += '</div>';
    el.innerHTML = html;
}

async function orangeTrackOrderFetchAndRender(resultBox, orderNumber, phone, msgMissing, msgNotFound) {
    if (!resultBox) {
        return;
    }
    const onum = String(orderNumber || '').trim();
    const ph = String(phone || '').trim();
    if (!onum || !ph) {
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(msgMissing || '') + '</div>';
        window.__orangeCartTrack = null;
        orangeScrollTrackResultIntoView(resultBox);
        return;
    }
    const url = storefrontApiUrl('/api/orders/get-order.php');
    const lang =
        typeof window.APP_LANG === 'string' && window.APP_LANG.trim() !== ''
            ? window.APP_LANG.trim().toLowerCase()
            : 'en';
    let result;
    let resStatus = 0;
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ order_number: onum, phone: ph, lang: lang }),
        });
        resStatus = response.status;
        result = await response.json();
    } catch (e) {
        const T = window.APP_T || {};
        const net = T.api_request_failed || msgNotFound || '';
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(net) + '</div>';
        window.__orangeCartTrack = null;
        orangeScrollTrackResultIntoView(resultBox);
        return;
    }
    if (!result || typeof result !== 'object') {
        const T = window.APP_T || {};
        const net = T.api_request_failed || msgNotFound || '';
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(net) + '</div>';
        window.__orangeCartTrack = null;
        orangeScrollTrackResultIntoView(resultBox);
        return;
    }
    if (!result.success) {
        const mapped = orangeCheckoutApiMessage(result);
        const show =
            mapped ||
            (resStatus === 404 && msgNotFound ? msgNotFound : '') ||
            (result.message || '') ||
            msgNotFound ||
            '';
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(show) + '</div>';
        window.__orangeCartTrack = null;
        orangeScrollTrackResultIntoView(resultBox);
        return;
    }
    window.__orangeCartTrack = { orderNumber: onum, phone: ph, order: result.order };
    const items = result.items || [];
    orangeRenderTrackedOrderBox(resultBox, result.order, onum, ph, items);
    orangeScrollTrackResultIntoView(resultBox);
    if (typeof window.__orangeOnTrackSuccess === 'function') {
        try {
            window.__orangeOnTrackSuccess({
                resultBox,
                order: result.order,
                orderNumber: onum,
                phone: ph,
                items: items,
            });
        } catch (e) {
            /* optional storefront hook */
        }
    }
}

async function orangeSendTrackOrderEmailSummary() {
    const ctx = window.__orangeCartTrack;
    const inp = document.getElementById('trackOrderEmailSummaryInput');
    const fb = document.getElementById('trackOrderEmailSummaryFeedback');
    const btn = document.getElementById('trackOrderEmailSummaryBtn');
    const T = window.APP_T || {};
    if (!ctx || !ctx.orderNumber || !ctx.phone || !inp) {
        return;
    }
    const email = String(inp.value || '').trim();
    if (fb) {
        fb.hidden = false;
        fb.textContent = '';
    }
    if (!email) {
        if (fb) {
            fb.textContent = T.checkout_invalid_email || '';
        }
        return;
    }
    const lang =
        typeof window.APP_LANG === 'string' && window.APP_LANG.trim() !== ''
            ? window.APP_LANG.trim().toLowerCase()
            : 'en';
    if (btn) {
        btn.disabled = true;
    }
    let data = null;
    try {
        const res = await fetch(storefrontApiUrl('/api/orders/email-track-order-summary.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                order_number: String(ctx.orderNumber || '').trim(),
                phone: String(ctx.phone || '').trim(),
                email: email,
                lang: lang,
            }),
        });
        try {
            data = await res.json();
        } catch (e1) {
            data = null;
        }
    } catch (e2) {
        data = null;
    }
    if (btn) {
        btn.disabled = false;
    }
    if (data && data.success) {
        if (fb) {
            fb.textContent = T.track_email_summary_ok || '';
        }
        return;
    }
    const msg =
        orangeCheckoutApiMessage(data || {}) ||
        T.track_email_summary_err ||
        T.api_request_failed ||
        '';
    if (fb) {
        fb.textContent = msg;
    }
}

function orangeScrollTrackResultIntoView(el) {
    if (!el || typeof el.scrollIntoView !== 'function') {
        return;
    }
    try {
        requestAnimationFrame(function () {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            el.focus({ preventScroll: true });
        });
    } catch (e) {
        el.scrollIntoView(true);
    }
}

function orangeCartAccountOrdersListUrl(bucket) {
    const b = bucket === 'delivered' ? 'delivered' : 'active';
    return storefrontApiUrl('/api/orders/list-storefront-orders.php?bucket=' + encodeURIComponent(b));
}

function orangeCartGuestOrdersListUrl() {
    return storefrontApiUrl('/api/orders/list-guest-storefront-orders.php');
}

function orangeCartRenderAccountOrderCard(row) {
    const labels = window.ORANGE_ORDER_STATUS_LABELS || {};
    const UI = window.ORANGE_MY_ORDER_UI || {};
    const L = window.ORANGE_TRACK_LABELS || {};
    const st = String(row.status || '').toLowerCase().trim();
    const statusText = labels[st] || row.status || '—';
    const canCancel = orangeCustomerCanCancelByStatus(st);
    const cur = String(UI.currency || 'KD');
    const lblOrder = L.order_number || 'Order #';
    const lblPhone = L.phone || 'Phone';
    const onum = String(row.order_number || '');
    const ph = String(row.phone || '');
    let waUrl = '';
    const prefillRaw = String(UI.whatsapp_prefill || '').replace(/\{order\}/g, onum);
    const waBase = window.ORANGE_STOREFRONT_WA;
    if (waBase && typeof waBase === 'string') {
        const q = waBase.indexOf('?');
        const path = q >= 0 ? waBase.substring(0, q) : waBase;
        waUrl = path + '?text=' + encodeURIComponent(prefillRaw);
    }
    const pt = String(row.payment_terms || 'cash').toLowerCase();
    const ptLabel = pt === 'credit' ? (UI.payment_credit || '') : pt === 'online' ? (UI.payment_online || '') : (UI.payment_cash || '');
    let html = '<div class="cart-account-order-card" data-cart-order="' + orangeEscDomAttr(onum) + '">';
    html += '<p class="cart-account-order-card__row order-status-row"><strong>' + orangeEscDomText(UI.status_label || '') + ':</strong> ';
    html += '<span class="order-status-pill order-status-pill--' + orangeEscDomText(st) + '">' + orangeEscDomText(statusText) + '</span></p>';
    html += '<p class="cart-account-order-card__row"><strong>' + orangeEscDomText(lblOrder) + ':</strong> ' + orangeEscDomText(onum) + '</p>';
    if (ph) {
        html += '<p class="cart-account-order-card__row"><strong>' + orangeEscDomText(lblPhone) + ':</strong> ' + orangeEscDomText(ph) + '</p>';
    }
    const Tcard = window.APP_T || {};
    const linesSubRaw = row.lines_subtotal != null && row.lines_subtotal !== '' ? parseFloat(String(row.lines_subtotal)) : NaN;
    const linesSubNum = Number.isFinite(linesSubRaw) ? linesSubRaw : 0;
    if (
        (
            orangeOrderCartPromoDiscountAmount(row) > 1e-9 ||
            orangeOrderCartComboDiscountAmount(row) > 1e-9 ||
            orangeOrderDeliveryFeeAmount(row) > 1e-9
        ) &&
        linesSubNum > 1e-9
    ) {
        html +=
            '<p class="cart-account-order-card__row order-items-subtotal-line"><strong>' +
            orangeEscDomText(Tcard.cart_subtotal_label || '') +
            ':</strong> ' +
            linesSubNum.toFixed(3) +
            ' ' +
            orangeEscDomText(cur) +
            '</p>';
    }
    html += orangeHtmlOrderComboParagraph(
        row,
        cur,
        'cart-account-order-card__row order-cart-promo-line'
    );
    html += orangeHtmlOrderPromoParagraph(
        row,
        cur,
        'cart-account-order-card__row order-cart-promo-line'
    );
    html += orangeHtmlOrderDeliveryFeeParagraph(
        row,
        cur,
        'cart-account-order-card__row order-cart-promo-line'
    );
    html +=
        '<p class="cart-account-order-card__row"><strong>' +
        orangeEscDomText(UI.order_total_label || '') +
        ':</strong> ' +
        orangeEscDomText(String(row.total)) +
        ' ' +
        orangeEscDomText(cur) +
        '</p>';
    if (UI.payment_label && ptLabel) {
        html +=
            '<p class="cart-account-order-card__row"><strong>' +
            orangeEscDomText(UI.payment_label) +
            ':</strong> ' +
            orangeEscDomText(ptLabel) +
            '</p>';
    }
    html += '<div class="cart-account-order-card__actions customer-order-actions">';
    html += '<button type="button" class="btn btn-secondary customer-order-amend"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.amend_not_allowed || '') + '"';
    }
    html +=
        ' data-orange-cart-amend-order="' +
        orangeEscDomAttr(onum) +
        '" data-orange-cart-amend-phone="' +
        orangeEscDomAttr(ph) +
        '">' +
        orangeEscDomText(UI.amend || '') +
        '</button>';
    html += '<button type="button" class="btn btn-danger customer-order-cancel"';
    if (!canCancel) {
        html += ' disabled title="' + orangeEscDomAttr(UI.cancel_not_allowed || '') + '"';
    }
    html +=
        ' data-orange-cart-cancel-order="' +
        orangeEscDomAttr(onum) +
        '" data-orange-cart-cancel-phone="' +
        orangeEscDomAttr(ph) +
        '">' +
        orangeEscDomText(UI.cancel || '') +
        '</button>';
    if (waUrl) {
        html +=
            '<a class="btn btn-secondary customer-order-wa" href="' +
            orangeEscDomAttr(waUrl) +
            '" target="_blank" rel="noopener noreferrer">' +
            orangeEscDomText(UI.whatsapp_help || 'WhatsApp') +
            '</a>';
    }
    html += '</div>';
    if (!canCancel && st !== 'cancelled' && st !== 'rejected') {
        html += '<p class="cart-cancel-hint">' + orangeEscDomText(UI.cancel_not_allowed || '') + '</p>';
    }
    html += '</div>';
    return html;
}

async function orangeCartFetchAccountOrdersIntoMount(mountEl, bucket) {
    if (!mountEl) {
        return;
    }
    const emptyMsg = (window.APP_T && window.APP_T.cart_account_orders_empty) || '';
    const errMsg = (window.APP_T && window.APP_T.api_request_failed) || '';
    mountEl.innerHTML = '<p class="cart-account-orders-empty">' + orangeEscDomText(emptyMsg) + '</p>';
    let res;
    let data;
    try {
        res = await fetch(orangeCartAccountOrdersListUrl(bucket), { credentials: 'same-origin' });
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }
    } catch (e) {
        mountEl.innerHTML = '<p class="cart-account-orders-err">' + orangeEscDomText(errMsg) + '</p>';
        return;
    }
    if (res.status === 401) {
        mountEl.innerHTML =
            '<p class="cart-account-orders-err">' +
            orangeEscDomText((data && data.message) || (window.APP_T && window.APP_T.cart_account_auth_required) || '') +
            '</p>';
        return;
    }
    if (!data || !data.success || !Array.isArray(data.orders)) {
        mountEl.innerHTML = '<p class="cart-account-orders-err">' + orangeEscDomText(errMsg) + '</p>';
        return;
    }
    if (data.orders.length === 0) {
        mountEl.innerHTML = '<p class="cart-account-orders-empty">' + orangeEscDomText(emptyMsg) + '</p>';
        return;
    }
    let html = '';
    for (let i = 0; i < data.orders.length; i++) {
        html += orangeCartRenderAccountOrderCard(data.orders[i]);
    }
    mountEl.innerHTML = html;
}

async function orangeCartFetchGuestOrdersIntoMount(mountEl) {
    if (!mountEl) {
        return;
    }
    const errMsg = (window.APP_T && window.APP_T.api_request_failed) || '';
    /* رسالة التحفيز ثابتة في cart.php (cart-guest-orders-incentive)؛ هنا لا نكرّرها — نعرض الطلبات فقط. */
    mountEl.innerHTML = '';
    let res;
    let data;
    try {
        res = await fetch(orangeCartGuestOrdersListUrl(), { credentials: 'same-origin' });
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }
    } catch (e) {
        mountEl.innerHTML = '<p class="cart-account-orders-err">' + orangeEscDomText(errMsg) + '</p>';
        return;
    }
    if (!data || !data.success || !Array.isArray(data.orders)) {
        mountEl.innerHTML = '<p class="cart-account-orders-err">' + orangeEscDomText(errMsg) + '</p>';
        return;
    }
    if (data.orders.length === 0) {
        mountEl.innerHTML = '';
        return;
    }
    let html = '';
    for (let i = 0; i < data.orders.length; i++) {
        html += orangeCartRenderAccountOrderCard(data.orders[i]);
    }
    mountEl.innerHTML = html;
}

async function orangeCartRefreshAccountOrderLists() {
    const a = document.getElementById('cartAccountOrdersActiveMount');
    const d = document.getElementById('cartAccountOrdersDeliveredMount');
    const g = document.getElementById('cartGuestOrdersMount');
    const jobs = [];
    if (a) {
        jobs.push(orangeCartFetchAccountOrdersIntoMount(a, 'active'));
    }
    if (d) {
        jobs.push(orangeCartFetchAccountOrdersIntoMount(d, 'delivered'));
    }
    if (g) {
        jobs.push(orangeCartFetchGuestOrdersIntoMount(g));
    }
    await Promise.all(jobs);
}

async function orangeCustomerCancelOrder() {
    const ctx = window.__orangeCartTrack;
    const UI = window.ORANGE_MY_ORDER_UI || {};
    if (!ctx || !ctx.orderNumber || !ctx.phone) {
        return;
    }
    if (!confirm(UI.cancel_confirm || '')) {
        return;
    }
    const api = storefrontApiUrl('/api/orders/cancel-by-customer.php');
    try {
        const res = await fetch(api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_number: ctx.orderNumber,
                phone: ctx.phone,
                lang: typeof window.APP_LANG === 'string' ? window.APP_LANG : 'en',
            }),
        });
        let data;
        try {
            data = await res.json();
        } catch (e2) {
            data = null;
        }
        const T = window.APP_T || {};
        if (data && data.success) {
            orangeShowToast(UI.cancel_ok || T.customer_cancel_ok || '', 3400);
            if (typeof window.__orangeCartTrackRefresh === 'function') {
                await window.__orangeCartTrackRefresh();
            }
            if (typeof window.orangeCartRefreshAccountOrderLists === 'function') {
                try {
                    await window.orangeCartRefreshAccountOrderLists();
                } catch (e) {
                    /* ignore */
                }
            }
            return;
        }
        const msg =
            orangeCheckoutApiMessage(data || {}) || UI.cancel_err || T.customer_cancel_err || '';
        orangeShowToast(msg, 3800);
    } catch (e) {
        const T = window.APP_T || {};
        orangeShowToast(
            (T.api_request_failed || T.customer_cancel_err || UI.cancel_err || '').trim() || '',
            3800
        );
    }
}

document.addEventListener('DOMContentLoaded', () => {
    ensureOrangeToast();
    orangeRestorePendingAmendFromStorage();
    renderCart();
    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
    orangeRenderCheckoutMiniSummary();
    document.addEventListener('change', function (ev) {
        const t = ev && ev.target ? ev.target : null;
        if (!t || !t.id) {
            return;
        }
        if (t.id === 'customer_area') {
            orangeScheduleCheckoutPreview();
            return;
        }
        if (t.id === 'customer_phone_country') {
            orangeCheckoutOtpOnPhoneEdited();
        }
    });
    const otpPhoneEl = document.getElementById('customer_phone');
    if (otpPhoneEl) {
        otpPhoneEl.addEventListener('input', orangeCheckoutOtpOnPhoneEdited);
    }
    const otpCodeEl = document.getElementById('checkoutOtpCode');
    if (otpCodeEl) {
        otpCodeEl.addEventListener('input', () => {
            const trimmed = String(otpCodeEl.value || '').replace(/\D+/g, '').slice(0, 6);
            if (trimmed !== otpCodeEl.value) {
                otpCodeEl.value = trimmed;
            }
        });
        otpCodeEl.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                orangeCheckoutVerifyOtp();
            }
        });
    }
    orangeCheckoutOtpOnPhoneEdited();
    setTimeout(orangeCheckoutOtpOnPhoneEdited, 220);

    window.orangeCartRefreshAccountOrderLists = orangeCartRefreshAccountOrderLists;

    const accMountA = document.getElementById('cartAccountOrdersActiveMount');
    const accMountD = document.getElementById('cartAccountOrdersDeliveredMount');
    const guestMount = document.getElementById('cartGuestOrdersMount');
    if (accMountA || accMountD || guestMount) {
        document.addEventListener('click', function (ev) {
            const t = ev.target;
            if (!t || !t.getAttribute) {
                return;
            }
            const btnAmend = t.closest ? t.closest('[data-orange-cart-amend-order]') : null;
            if (btnAmend) {
                const onumA = btnAmend.getAttribute('data-orange-cart-amend-order') || '';
                const phA = btnAmend.getAttribute('data-orange-cart-amend-phone') || '';
                if (!onumA || !phA) {
                    return;
                }
                ev.preventDefault();
                orangeCartStartAmendOrder(onumA, phA);
                return;
            }
            const btn = t.closest ? t.closest('[data-orange-cart-cancel-order]') : null;
            if (!btn) {
                return;
            }
            const onum = btn.getAttribute('data-orange-cart-cancel-order') || '';
            const ph = btn.getAttribute('data-orange-cart-cancel-phone') || '';
            if (!onum || !ph) {
                return;
            }
            ev.preventDefault();
            window.__orangeCartTrack = { orderNumber: onum, phone: ph, order: {} };
            orangeCustomerCancelOrder();
        });
        window.orangeCartOnTabShown = function (which) {
            if (which === 'orders' && accMountA && accMountA.getAttribute('data-loaded') !== '1') {
                accMountA.setAttribute('data-loaded', '1');
                orangeCartFetchAccountOrdersIntoMount(accMountA, 'active');
            }
            if (which === 'delivered' && accMountD && accMountD.getAttribute('data-loaded') !== '1') {
                accMountD.setAttribute('data-loaded', '1');
                orangeCartFetchAccountOrdersIntoMount(accMountD, 'delivered');
            }
            if (which === 'orders' && guestMount && guestMount.getAttribute('data-loaded') !== '1') {
                guestMount.setAttribute('data-loaded', '1');
                orangeCartFetchGuestOrdersIntoMount(guestMount);
            }
        };
    }
});
