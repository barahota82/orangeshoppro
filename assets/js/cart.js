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

function storefrontApiUrl(path) {
    const raw = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.startsWith('/') ? path : '/' + path;
    return base + p;
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
    if (!items.length) {
        orangeShowToast(window.APP_T.empty_cart || '', 2800);
        return;
    }
    if (typeof window.orangeCartUiShowTab === 'function') {
        window.orangeCartUiShowTab('orders');
    }
    requestAnimationFrame(() => {
        const card = document.getElementById('cartCheckoutCard');
        if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            card.classList.add('cart-checkout-card--highlight');
            window.setTimeout(() => {
                card.classList.remove('cart-checkout-card--highlight');
            }, 1400);
        }
        const nameEl = document.getElementById('customer_name');
        if (nameEl) {
            window.setTimeout(() => nameEl.focus(), 350);
        }
    });
}

window.orangeCartProceedToCheckout = orangeCartProceedToCheckout;

function orangeSyncCartProceedBtn() {
    const btn = document.getElementById('cartProceedBtn');
    if (!btn) {
        return;
    }
    btn.disabled = !getCart().length;
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
        return;
    }
    let total = 0;
    const rows = [];
    items.forEach((it) => {
        const q = Math.max(1, parseInt(it.qty, 10) || 1);
        total += q * Number(it.price);
        rows.push({ name: it.name || '', q });
    });
    const title = T.cart_mini_summary_title || '';
    const maxShow = 3;
    let listHtml = '';
    for (let i = 0; i < Math.min(rows.length, maxShow); i++) {
        listHtml +=
            '<li><span class="cart-mini-list__name">' +
            escCartHtml(rows[i].name) +
            '</span><span class="cart-mini-list__qty">×' +
            rows[i].q +
            '</span></li>';
    }
    const more = rows.length - maxShow;
    let moreHtml = '';
    if (more > 0) {
        const tpl = T.cart_mini_more || '';
        moreHtml =
            '<p class="cart-mini-more">' + escCartHtml(tpl.replace(/\{n\}/g, String(more))) + '</p>';
    }
    const totalLbl = T.cart_total_label || 'Total';
    el.hidden = false;
    el.innerHTML =
        '<div class="cart-mini-summary__inner">' +
        (title ? '<div class="cart-mini-summary__title">' + escCartHtml(title) + '</div>' : '') +
        '<ul class="cart-mini-list">' +
        listHtml +
        '</ul>' +
        moreHtml +
        '<div class="cart-mini-total"><span>' +
        escCartHtml(totalLbl) +
        '</span><strong>' +
        formatMoney(total) +
        '</strong></div></div>';
}

async function fetchCartStockLimits(items) {
    const payload = {
        items: items.map((i) => ({
            id: i.id,
            variant_id: i.variant_id,
            color: i.color,
            size: i.size,
        })),
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
        box.innerHTML = cartEmptyStateHtml();
        orangeSyncCartProceedBtn();
        orangeSyncCartTabCount();
        orangeRenderCheckoutMiniSummary();
        return;
    }

    const limits = await fetchCartStockLimits(items);
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
        }
        items = getCart();
    }

    if (!items.length) {
        box.innerHTML = cartEmptyStateHtml();
        orangeSyncCartProceedBtn();
        orangeSyncCartTabCount();
        orangeRenderCheckoutMiniSummary();
        return;
    }

    let total = 0;
    let html = '';
    const T = window.APP_T || {};
    const removeLabel = T.cart_remove || 'Remove';
    const countTpl = T.cart_items_count || '{n} items';
    const countStr = countTpl.replace(/\{n\}/g, String(items.length));
    const unitLbl = T.cart_unit_price || '';
    const subLbl = T.cart_line_subtotal || '';
    const maxShortTpl = T.cart_max_available_short || '';

    html += '<div class="cart-items-shell">';
    html +=
        '<div class="cart-list-head"><span class="cart-list-head__count">' +
        escCartHtml(countStr) +
        '</span></div>';
    html += '<div class="cart-items-list">';

    items.forEach((item, idx) => {
        const qty = Math.max(1, parseInt(item.qty, 10) || 1);
        const lineTotal = qty * Number(item.price);
        total += lineTotal;
        const maxStock =
            limits && limits[idx] != null && !Number.isNaN(limits[idx])
                ? Math.max(0, parseInt(limits[idx], 10))
                : null;
        const maxAttr = maxStock != null && maxStock > 0 ? ` max="${maxStock}"` : '';
        const stockHint =
            maxStock != null && maxStock > 0 && maxShortTpl
                ? '<p class="cart-stock-hint">' +
                  escCartHtml(maxShortTpl.replace(/\{n\}/g, String(maxStock))) +
                  '</p>'
                : '';

        html += `
            <div class="cart-item-card" data-cart-idx="${idx}">
                <div class="cart-item-left">
                    <img src="/uploads/products/${String(item.image || '').replace(/"/g, '')}" alt="${escCartHtml(item.name || '')}">
                </div>
                <div class="cart-item-right">
                    <h4>${escCartHtml(item.name || '')}</h4>
                    ${item.color ? `<p class="cart-item-variant">${escCartHtml(T.color || '')}: ${escCartHtml(item.color)}</p>` : ''}
                    ${item.size ? `<p class="cart-item-variant">${escCartHtml(T.size || '')}: ${escCartHtml(item.size)}</p>` : ''}
                    <div class="cart-line-price-row">
                        <span class="cart-unit-price"><span class="cart-meta-label">${escCartHtml(unitLbl)}</span> ${formatMoney(item.price)}</span>
                        <span class="cart-line-subtotal"><span class="cart-meta-label">${escCartHtml(subLbl)}</span><strong>${formatMoney(lineTotal)}</strong></span>
                    </div>
                    ${stockHint}
                    <div class="cart-qty-row">
                        <span class="cart-qty-label">${escCartHtml(T.quantity || '')}</span>
                        <div class="qty-control cart-qty-control">
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, -1)" aria-label="-">−</button>
                            <input type="number" class="cart-qty-input" id="cartQty${idx}" value="${qty}" min="1"${maxAttr} inputmode="numeric" onchange="setCartQtyFromInput(${idx})" onblur="setCartQtyFromInput(${idx})">
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, 1)" aria-label="+">+</button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost cart-remove-btn" onclick="removeCartItem(${idx})">${escCartHtml(removeLabel)}</button>
                </div>
            </div>
        `;
    });

    html += '</div>';
    const totalLbl = T.cart_total_label || 'Total';
    html +=
        '<div class="cart-summary-bar"><div class="cart-total-box"><strong>' +
        escCartHtml(totalLbl) +
        '</strong><span class="cart-total-amount">' +
        formatMoney(total) +
        '</span></div></div>';
    html += '</div>';

    box.innerHTML = html;
    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
    orangeRenderCheckoutMiniSummary();
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
    if (max != null && !Number.isNaN(max) && max > 0) {
        const beforeCap = q;
        q = Math.min(q, max);
        if (beforeCap > max) {
            const tpl = window.APP_T.available_max_qty || 'Max: {n}';
            orangeShowToast(tpl.replace(/\{n\}/g, String(max)), 3200);
        }
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

function removeCartItem(index) {
    const items = getCart();
    items.splice(index, 1);
    setCart(items);
    orangeShowToast(window.APP_T.item_removed_from_cart || '', 2200);
    renderCart();
}

function orangeFinishCheckoutSuccess(result) {
    localStorage.removeItem(getCartStorageKey());
    try {
        localStorage.removeItem('cart');
    } catch (e) {}
    window.open(result.whatsapp_url, '_blank');
    const okMsg =
        (window.APP_T.order_number || 'Order Number') + ': ' + String(result.order_number || '');
    orangeShowToast(okMsg, 3400);
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
        const j = await r.json();
        if (j && j.status === 'completed' && j.whatsapp_url) {
            return j;
        }
        if (j && j.status === 'failed') {
            throw new Error(j.message || 'Checkout failed');
        }
        if (j && j.success === false && j.message && j.status !== 'pending') {
            throw new Error(j.message);
        }
        await new Promise(function (res) {
            setTimeout(res, 450);
        });
    }
    throw new Error(window.APP_T.checkout_queue_timeout || 'Order queue timeout. Try again.');
}

async function sendOrderNow() {
    const items = getCart();
    if (!items.length) {
        orangeShowToast(window.APP_T.empty_cart || 'Cart is empty.', 2800);
        return;
    }

    const payRadio = document.querySelector('input[name="checkout_payment_terms"]:checked');
    const paymentTerms = payRadio && payRadio.value === 'online' ? 'online' : 'cash';

    const payload = {
        name: document.getElementById('customer_name').value.trim(),
        phone: document.getElementById('customer_phone').value.trim(),
        area: document.getElementById('customer_area').value.trim(),
        address: document.getElementById('customer_address').value.trim(),
        notes: document.getElementById('customer_notes').value.trim(),
        channel_id: window.APP_CHANNEL_ID || 0,
        items: items,
        payment_terms: paymentTerms,
    };

    if (!payload.name || !payload.phone || !payload.area || !payload.address) {
        orangeShowToast(window.APP_T.checkout_required_fields || 'Please fill all required fields.', 3200);
        return;
    }

    const response = await fetch(storefrontApiUrl('/api/orders/create-order.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    const result = await response.json();

    if (response.status === 503 && result && result.intake_token) {
        orangeShowToast(window.APP_T.checkout_queue_wait || 'Processing order…', 2600);
        try {
            const done = await orangePollIntakeUntilDone(result.intake_token);
            orangeFinishCheckoutSuccess(done);
        } catch (e) {
            orangeShowToast((e && e.message) || 'Checkout failed', 4200);
        }
        return;
    }

    if (!result.success) {
        orangeShowToast(result.message || 'Failed to create order', 3600);
        return;
    }

    orangeFinishCheckoutSuccess(result);
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

function orangeRenderTrackedOrderBox(resultBox, order, orderNumber, phoneTyped) {
    const UI = window.ORANGE_MY_ORDER_UI || {};
    const labels = window.ORANGE_ORDER_STATUS_LABELS || {};
    const L = window.ORANGE_TRACK_LABELS || {};
    const st = String(order.status || '').toLowerCase().trim();
    const statusText = labels[st] || order.status || '—';
    const lblOrder = L.order_number || 'Order #';
    const lblPhone = L.phone || 'Phone';

    const canCancel = st === 'pending' || st === 'approved';
    let waUrl = null;
    const prefillRaw = String(UI.whatsapp_prefill || '').replace(/\{order\}/g, String(order.order_number || orderNumber));
    const waBase = window.ORANGE_STOREFRONT_WA;
    if (waBase && typeof waBase === 'string') {
        const q = waBase.indexOf('?');
        const path = q >= 0 ? waBase.substring(0, q) : waBase;
        waUrl = path + '?text=' + encodeURIComponent(prefillRaw);
    }

    window.__orangeCartTrack = { orderNumber: orderNumber, phone: phoneTyped };

    let html = '<div class="track-box track-box--order">';
    html += '<p class="order-status-row"><strong>' + orangeEscDomText(UI.status_label || '') + ':</strong> ';
    html += '<span class="order-status-pill order-status-pill--' + orangeEscDomText(st) + '">' + orangeEscDomText(statusText) + '</span></p>';
    html += '<p><strong>' + orangeEscDomText(lblOrder) + ':</strong> ' + orangeEscDomText(String(order.order_number || '')) + '</p>';
    if (order.phone) {
        html += '<p><strong>' + orangeEscDomText(lblPhone) + ':</strong> ' + orangeEscDomText(String(order.phone)) + '</p>';
    }
    html += '<p><strong>' + orangeEscDomText(UI.order_total_label || '') + ':</strong> ' + orangeEscDomText(String(order.total)) + ' ' + orangeEscDomText(UI.currency || 'KD') + '</p>';
    const pt = String(order.payment_terms || 'cash').toLowerCase();
    const ptLabel = pt === 'credit' ? (UI.payment_credit || '') : (pt === 'online' ? (UI.payment_online || '') : (UI.payment_cash || ''));
    if (UI.payment_label && ptLabel) {
        html += '<p><strong>' + orangeEscDomText(UI.payment_label) + ':</strong> ' + orangeEscDomText(ptLabel) + '</p>';
    }
    html += '<div class="customer-order-actions">';
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
    html += '</div>';
    resultBox.innerHTML = html;
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
        return;
    }
    const url = storefrontApiUrl('/api/orders/get-order.php?order_number=' + encodeURIComponent(onum) + '&phone=' + encodeURIComponent(ph));
    let result;
    let resStatus = 0;
    try {
        const response = await fetch(url);
        resStatus = response.status;
        result = await response.json();
    } catch (e) {
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(msgNotFound || '') + '</div>';
        window.__orangeCartTrack = null;
        return;
    }
    if (!result.success) {
        const show =
            resStatus === 404 && msgNotFound ? msgNotFound : (result.message || msgNotFound || '');
        resultBox.innerHTML = '<div class="stock-out">' + orangeEscDomText(show) + '</div>';
        window.__orangeCartTrack = null;
        return;
    }
    orangeRenderTrackedOrderBox(resultBox, result.order, onum, ph);
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
            body: JSON.stringify({ order_number: ctx.orderNumber, phone: ctx.phone }),
        });
        const data = await res.json();
        if (data.success) {
            alert(UI.cancel_ok || '');
            if (typeof window.__orangeCartTrackRefresh === 'function') {
                await window.__orangeCartTrackRefresh();
            }
            return;
        }
        const code = data.code || '';
        if (code === 'cancel_not_allowed') {
            alert(UI.cancel_not_allowed || UI.cancel_err || '');
        } else {
            alert(UI.cancel_err || '');
        }
    } catch (e) {
        alert(UI.cancel_err || '');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    ensureOrangeToast();
    renderCart();
    orangeSyncCartProceedBtn();
    orangeSyncCartTabCount();
    orangeRenderCheckoutMiniSummary();
});
