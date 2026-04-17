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

function getCart() {
    try {
        return JSON.parse(localStorage.getItem('cart') || '[]');
    } catch (e) {
        return [];
    }
}

function setCart(items) {
    localStorage.setItem('cart', JSON.stringify(items));
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

function storefrontApiUrl(path) {
    const raw = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    const base = raw.replace(/\/+$/, '');
    const p = path.startsWith('/') ? path : '/' + path;
    return base + p;
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
        box.innerHTML =
            '<div class="empty-box">' + escCartHtml(window.APP_T.empty_cart || 'Cart is empty.') + '</div>';
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
        box.innerHTML =
            '<div class="empty-box">' + escCartHtml(window.APP_T.empty_cart || 'Cart is empty.') + '</div>';
        return;
    }

    let total = 0;
    let html = '';
    const removeLabel = window.APP_T.cart_remove || 'Remove';

    items.forEach((item, idx) => {
        const qty = Math.max(1, parseInt(item.qty, 10) || 1);
        const lineTotal = qty * Number(item.price);
        total += lineTotal;
        const maxStock =
            limits && limits[idx] != null && !Number.isNaN(limits[idx])
                ? Math.max(0, parseInt(limits[idx], 10))
                : null;
        const maxAttr = maxStock != null && maxStock > 0 ? ` max="${maxStock}"` : '';

        html += `
            <div class="cart-item-card" data-cart-idx="${idx}">
                <div class="cart-item-left">
                    <img src="/uploads/products/${String(item.image || '').replace(/"/g, '')}" alt="${escCartHtml(item.name || '')}">
                </div>
                <div class="cart-item-right">
                    <h4>${escCartHtml(item.name || '')}</h4>
                    ${item.color ? `<p>${escCartHtml(window.APP_T.color || '')}: ${escCartHtml(item.color)}</p>` : ''}
                    ${item.size ? `<p>${escCartHtml(window.APP_T.size || '')}: ${escCartHtml(item.size)}</p>` : ''}
                    <div class="cart-qty-row">
                        <span class="cart-qty-label">${escCartHtml(window.APP_T.quantity || '')}</span>
                        <div class="qty-control cart-qty-control">
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, -1)" aria-label="-">−</button>
                            <input type="number" class="cart-qty-input" id="cartQty${idx}" value="${qty}" min="1"${maxAttr} inputmode="numeric" onchange="setCartQtyFromInput(${idx})" onblur="setCartQtyFromInput(${idx})">
                            <button type="button" class="cart-qty-btn" onclick="adjustCartQty(${idx}, 1)" aria-label="+">+</button>
                        </div>
                    </div>
                    <p class="cart-line-price">${formatMoney(item.price)}</p>
                    <button type="button" class="btn btn-secondary cart-remove-btn" onclick="removeCartItem(${idx})">${escCartHtml(removeLabel)}</button>
                </div>
            </div>
        `;
    });

    html += `<div class="cart-total-box"><strong>Total: ${formatMoney(total)}</strong></div>`;
    box.innerHTML = html;
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
        q = Math.min(q, max);
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
    let q = cur + delta;
    if (q < 1) {
        q = 1;
    }
    if (max != null && !Number.isNaN(max) && max > 0) {
        if (q > max) {
            if (delta > 0) {
                const tpl = window.APP_T.available_max_qty || 'Max: {n}';
                alert(tpl.replace(/\{n\}/g, String(max)));
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
    renderCart();
}

async function sendOrderNow() {
    const items = getCart();
    if (!items.length) {
        alert(window.APP_T.empty_cart || 'Cart is empty.');
        return;
    }

    const payload = {
        name: document.getElementById('customer_name').value.trim(),
        phone: document.getElementById('customer_phone').value.trim(),
        area: document.getElementById('customer_area').value.trim(),
        address: document.getElementById('customer_address').value.trim(),
        notes: document.getElementById('customer_notes').value.trim(),
        channel_id: window.APP_CHANNEL_ID || 0,
        items: items,
    };

    if (!payload.name || !payload.phone || !payload.area || !payload.address) {
        alert('Please fill all required fields');
        return;
    }

    const response = await fetch(storefrontApiUrl('/api/orders/create-order.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });

    const result = await response.json();

    if (!result.success) {
        alert(result.message || 'Failed to create order');
        return;
    }

    localStorage.removeItem('cart');
    window.open(result.whatsapp_url, '_blank');
    alert((window.APP_T.order_number || 'Order Number') + ': ' + result.order_number);
    location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
    renderCart();
});
