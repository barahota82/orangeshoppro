/*
 * صفحة العرض (pages/offer.php) — المرحلة الأولى: كومبو.
 * منتقي لون/مقاس مستقل لكل مكوّن، شريط سفلي ثابت بسعر العرض وكمية واحدة،
 * وحقن مكوّنات العرض في سلة localStorage (نفس مفتاح/شكل بنود السلة) فيُطبَّق
 * خصم الكومبو تلقائياً في معاينة السلة وعند الطلب (لا «apply promo id»).
 */
(function () {
    var OFFER = window.ORANGE_OFFER;
    if (!OFFER || !Array.isArray(OFFER.components) || OFFER.components.length === 0) {
        return;
    }

    var sel = OFFER.components.map(function () {
        return { color: '', size: '' };
    });

    function cartKey() {
        return (typeof window.orangeSfCartKey === 'function') ? window.orangeSfCartKey() : 'orange_sf_cart_orange';
    }
    function readCart() {
        try {
            var raw = localStorage.getItem(cartKey());
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }
    function writeCart(c) {
        try {
            localStorage.setItem(cartKey(), JSON.stringify(c));
        } catch (e) { /* تجاهل */ }
    }
    function fmt(v) {
        if (typeof window.formatMoney === 'function') {
            return window.formatMoney(v);
        }
        return Number(v).toFixed(2) + ' ' + (OFFER.currency_unit || '');
    }

    function comp(idx) {
        return OFFER.components[idx];
    }
    function variantInStock(idx, color, size) {
        var vs = comp(idx).variants || [];
        for (var i = 0; i < vs.length; i++) {
            var v = vs[i];
            if ((v.color || '') === color && (v.size || '') === size) {
                return (parseInt(v.stock_quantity, 10) || 0) > 0;
            }
        }
        return false;
    }
    function resolveVariant(idx) {
        var s = sel[idx];
        var vs = comp(idx).variants || [];
        for (var i = 0; i < vs.length; i++) {
            var v = vs[i];
            if ((v.color || '') === (s.color || '') && (v.size || '') === (s.size || '')) {
                return v;
            }
        }
        return null;
    }
    function hasVariantDims(c) {
        return c.has_colors === 1 || c.has_sizes === 1;
    }
    function stockFor(idx) {
        var c = comp(idx);
        if (!hasVariantDims(c)) {
            return parseInt(c.total_stock, 10) || 0;
        }
        var v = resolveVariant(idx);
        return v ? (parseInt(v.stock_quantity, 10) || 0) : 0;
    }
    function compComplete(idx) {
        var c = comp(idx);
        if (!hasVariantDims(c)) {
            return (parseInt(c.total_stock, 10) || 0) >= c.qty;
        }
        var s = sel[idx];
        if (c.has_colors === 1 && !s.color) {
            return false;
        }
        if (c.has_sizes === 1 && !s.size) {
            return false;
        }
        var v = resolveVariant(idx);
        return !!(v && (parseInt(v.stock_quantity, 10) || 0) >= c.qty);
    }
    function maxBundlesFor(idx) {
        var c = comp(idx);
        var st = stockFor(idx);
        return Math.floor(st / Math.max(1, c.qty));
    }
    function allComplete() {
        for (var i = 0; i < OFFER.components.length; i++) {
            if (!compComplete(i)) {
                return false;
            }
        }
        return true;
    }
    function maxBundles() {
        var m = Infinity;
        for (var i = 0; i < OFFER.components.length; i++) {
            m = Math.min(m, maxBundlesFor(i));
        }
        return (m === Infinity) ? 0 : Math.max(0, m);
    }

    function refreshSizes(idx) {
        var c = comp(idx);
        if (c.has_sizes !== 1) {
            return;
        }
        var root = document.querySelector('.offer-component[data-comp-index="' + idx + '"]');
        if (!root) {
            return;
        }
        var hasColors = c.has_colors === 1;
        root.querySelectorAll('.size-chip').forEach(function (chip) {
            var sz = chip.getAttribute('data-size') || '';
            var avail;
            if (hasColors && sel[idx].color) {
                avail = variantInStock(idx, sel[idx].color, sz);
            } else {
                avail = (c.variants || []).some(function (v) {
                    return (v.size || '') === sz && (parseInt(v.stock_quantity, 10) || 0) > 0;
                });
            }
            chip.disabled = !avail;
            chip.classList.toggle('chip--unavailable', !avail);
            if (!avail && sel[idx].size === sz) {
                sel[idx].size = '';
                chip.classList.remove('active');
            }
        });
    }

    function recompute() {
        var ok = allComplete();
        var mb = maxBundles();
        var qtyInput = document.getElementById('offerQty');
        var addBtn = document.getElementById('offerAddBtn');
        var q = qtyInput ? Math.max(1, parseInt(qtyInput.value || '1', 10) || 1) : 1;
        if (ok && mb >= 1) {
            if (q > mb) {
                q = mb;
            }
        } else {
            q = 1;
        }
        if (qtyInput) {
            qtyInput.value = String(q);
        }
        if (addBtn) {
            addBtn.disabled = !(ok && mb >= 1);
        }
        var priceEl = document.getElementById('offerBundlePrice');
        if (priceEl) {
            priceEl.textContent = fmt(OFFER.bundle_price * q);
        }
        var oldEl = document.getElementById('offerOldPrice');
        if (oldEl) {
            oldEl.textContent = fmt(OFFER.components_total * q);
        }
    }

    window.offerSelectColor = function (idx, btn) {
        var root = document.querySelector('.offer-component[data-comp-index="' + idx + '"]');
        if (root) {
            root.querySelectorAll('.color-chip').forEach(function (el) { el.classList.remove('active'); });
        }
        btn.classList.add('active');
        sel[idx].color = btn.getAttribute('data-color') || '';
        refreshSizes(idx);
        recompute();
    };
    window.offerSelectSize = function (idx, btn) {
        if (btn.disabled || btn.classList.contains('chip--unavailable')) {
            return;
        }
        var root = document.querySelector('.offer-component[data-comp-index="' + idx + '"]');
        if (root) {
            root.querySelectorAll('.size-chip').forEach(function (el) { el.classList.remove('active'); });
        }
        btn.classList.add('active');
        sel[idx].size = btn.getAttribute('data-size') || '';
        recompute();
    };
    window.offerIncQty = function () {
        var i = document.getElementById('offerQty');
        if (!i) {
            return;
        }
        var mb = maxBundles();
        var q = (parseInt(i.value || '1', 10) || 1) + 1;
        if (allComplete() && mb >= 1) {
            i.value = String(Math.min(mb, q));
        }
        recompute();
    };
    window.offerDecQty = function () {
        var i = document.getElementById('offerQty');
        if (!i) {
            return;
        }
        var q = (parseInt(i.value || '1', 10) || 1) - 1;
        i.value = String(Math.max(1, q));
        recompute();
    };

    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'offer-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('offer-toast--show'); }, 10);
        setTimeout(function () {
            t.classList.remove('offer-toast--show');
            setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 300);
        }, 2600);
    }

    function cartLinesMatch(a, b) {
        if (parseInt(a.id, 10) !== parseInt(b.id, 10)) {
            return false;
        }
        var va = parseInt(a.variant_id || 0, 10);
        var vb = parseInt(b.variant_id || 0, 10);
        if (va > 0 && vb > 0) {
            return va === vb;
        }
        return (a.color || '') === (b.color || '') && (a.size || '') === (b.size || '');
    }

    window.addOfferToCart = function () {
        if (!allComplete()) {
            toast(OFFER.t.pick_required);
            return;
        }
        var mb = maxBundles();
        if (mb < 1) {
            toast(OFFER.t.pick_required);
            return;
        }
        var q = Math.max(1, Math.min(mb, parseInt(document.getElementById('offerQty').value || '1', 10) || 1));
        var cart = readCart();
        for (var i = 0; i < OFFER.components.length; i++) {
            var c = comp(i);
            var v = resolveVariant(i);
            var variantId = (v && v.id) ? parseInt(v.id, 10) : 0;
            var lineQty = c.qty * q;
            var cap = stockFor(i);
            var item = {
                id: c.product_id,
                name: c.name,
                price: c.price,
                qty: lineQty,
                color: sel[i].color,
                size: sel[i].size,
                variant_id: variantId,
                image: c.image_cart
            };
            var merged = false;
            for (var j = 0; j < cart.length; j++) {
                if (cartLinesMatch(cart[j], item)) {
                    var next = (parseInt(cart[j].qty, 10) || 0) + lineQty;
                    cart[j].qty = cap > 0 ? Math.min(cap, next) : next;
                    merged = true;
                    break;
                }
            }
            if (!merged) {
                cart.push(item);
            }
        }
        writeCart(cart);
        if (typeof window.orangeAnimateCartPulse === 'function') {
            try { window.orangeAnimateCartPulse(); } catch (e) { /* تجاهل */ }
        }
        toast(OFFER.t.added);
        setTimeout(function () { window.location.href = OFFER.cart_url; }, 650);
    };

    function init() {
        for (var i = 0; i < OFFER.components.length; i++) {
            refreshSizes(i);
        }
        var qi = document.getElementById('offerQty');
        if (qi) {
            qi.addEventListener('input', recompute);
            qi.addEventListener('change', recompute);
        }
        recompute();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
