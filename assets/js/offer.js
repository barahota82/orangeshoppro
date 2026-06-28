/*
 * صفحة العرض (pages/offer.php) — كومبو + BOGO حزمة شراء.
 * كل مكوّن بكمية qty يتوسّع إلى qty «قطع»، ولكل قطعة منتقي لون/مقاس مستقل،
 * فيمكن للعميل اختيار متغيّرات مختلفة لكل قطعة (مثلاً قميص أسود + قميص أبيض).
 * تُحقن القطع في سلة localStorage (سطر لكل متغيّر)، فيُطبَّق خصم الكومبو/هدية
 * BOGO تلقائياً في معاينة السلة وعند الطلب. هدية BOGO لا تُضاف هنا (يحقنها الخادم).
 */
(function () {
    var OFFER = window.ORANGE_OFFER;
    if (!OFFER || !Array.isArray(OFFER.components) || OFFER.components.length === 0) {
        return;
    }

    // sel[compIndex] = مصفوفة بطول qty من { color, size } لكل قطعة.
    var sel = OFFER.components.map(function (c) {
        var n = Math.max(1, parseInt(c.qty, 10) || 1);
        var arr = [];
        for (var i = 0; i < n; i++) {
            arr.push({ color: '', size: '' });
        }
        return arr;
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
    function compQty(idx) {
        return Math.max(1, parseInt(comp(idx).qty, 10) || 1);
    }
    function hasVariantDims(c) {
        return c.has_colors === 1 || c.has_sizes === 1;
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
    function resolveVariant(idx, unit) {
        var s = sel[idx][unit] || { color: '', size: '' };
        var vs = comp(idx).variants || [];
        for (var i = 0; i < vs.length; i++) {
            var v = vs[i];
            if ((v.color || '') === (s.color || '') && (v.size || '') === (s.size || '')) {
                return v;
            }
        }
        return null;
    }
    function variantStockById(idx, vid) {
        var vs = comp(idx).variants || [];
        for (var i = 0; i < vs.length; i++) {
            if ((parseInt(vs[i].id, 10) || 0) === vid) {
                return parseInt(vs[i].stock_quantity, 10) || 0;
            }
        }
        return 0;
    }
    function unitSelected(idx, unit) {
        var c = comp(idx);
        if (!hasVariantDims(c)) {
            return true;
        }
        var s = sel[idx][unit] || {};
        if (c.has_colors === 1 && !s.color) {
            return false;
        }
        if (c.has_sizes === 1 && !s.size) {
            return false;
        }
        return true;
    }
    function allSelected() {
        for (var i = 0; i < OFFER.components.length; i++) {
            for (var u = 0; u < compQty(i); u++) {
                if (!unitSelected(i, u)) {
                    return false;
                }
            }
        }
        return true;
    }
    // أقصى عدد «حزم» يمكن إضافتها من مكوّن، مع جمع القطع التي تشترك بنفس المتغيّر.
    function maxBundlesFor(idx) {
        var c = comp(idx);
        var q = compQty(idx);
        if (!hasVariantDims(c)) {
            return Math.floor((parseInt(c.total_stock, 10) || 0) / Math.max(1, q));
        }
        var need = {};
        for (var u = 0; u < q; u++) {
            var v = resolveVariant(idx, u);
            if (!v) {
                return 0;
            }
            var vid = parseInt(v.id, 10) || 0;
            need[vid] = (need[vid] || 0) + 1;
        }
        var cap = Infinity;
        for (var key in need) {
            if (!Object.prototype.hasOwnProperty.call(need, key)) {
                continue;
            }
            var stock = variantStockById(idx, parseInt(key, 10));
            cap = Math.min(cap, Math.floor(stock / need[key]));
        }
        return (cap === Infinity) ? 0 : Math.max(0, cap);
    }
    function maxBundles() {
        var m = Infinity;
        for (var i = 0; i < OFFER.components.length; i++) {
            m = Math.min(m, maxBundlesFor(i));
        }
        return (m === Infinity) ? 0 : Math.max(0, m);
    }

    function unitRoot(idx, unit) {
        return document.querySelector('.offer-component[data-comp-index="' + idx + '"] .offer-unit[data-unit-index="' + unit + '"]');
    }
    function refreshSizes(idx, unit) {
        var c = comp(idx);
        if (c.has_sizes !== 1) {
            return;
        }
        var root = unitRoot(idx, unit);
        if (!root) {
            return;
        }
        var hasColors = c.has_colors === 1;
        var s = sel[idx][unit] || {};
        root.querySelectorAll('.size-chip').forEach(function (chip) {
            var sz = chip.getAttribute('data-size') || '';
            var avail;
            if (hasColors && s.color) {
                avail = variantInStock(idx, s.color, sz);
            } else {
                avail = (c.variants || []).some(function (v) {
                    return (v.size || '') === sz && (parseInt(v.stock_quantity, 10) || 0) > 0;
                });
            }
            chip.disabled = !avail;
            chip.classList.toggle('chip--unavailable', !avail);
            if (!avail && s.size === sz) {
                s.size = '';
                chip.classList.remove('active');
            }
        });
    }

    function recompute() {
        var enabled = allSelected() && maxBundles() >= 1;
        var mb = maxBundles();
        var qtyInput = document.getElementById('offerQty');
        var addBtn = document.getElementById('offerAddBtn');
        var q = qtyInput ? Math.max(1, parseInt(qtyInput.value || '1', 10) || 1) : 1;
        if (enabled) {
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
            addBtn.disabled = !enabled;
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

    window.offerSelectColor = function (idx, unit, btn) {
        var root = unitRoot(idx, unit);
        if (root) {
            root.querySelectorAll('.color-chip').forEach(function (el) { el.classList.remove('active'); });
        }
        btn.classList.add('active');
        sel[idx][unit].color = btn.getAttribute('data-color') || '';
        refreshSizes(idx, unit);
        recompute();
    };
    window.offerSelectSize = function (idx, unit, btn) {
        if (btn.disabled || btn.classList.contains('chip--unavailable')) {
            return;
        }
        var root = unitRoot(idx, unit);
        if (root) {
            root.querySelectorAll('.size-chip').forEach(function (el) { el.classList.remove('active'); });
        }
        btn.classList.add('active');
        sel[idx][unit].size = btn.getAttribute('data-size') || '';
        recompute();
    };
    window.offerIncQty = function () {
        var i = document.getElementById('offerQty');
        if (!i) {
            return;
        }
        var mb = maxBundles();
        var q = (parseInt(i.value || '1', 10) || 1) + 1;
        if (allSelected() && mb >= 1) {
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
    function pushLine(cart, item, cap) {
        for (var j = 0; j < cart.length; j++) {
            if (cartLinesMatch(cart[j], item)) {
                var next = (parseInt(cart[j].qty, 10) || 0) + item.qty;
                cart[j].qty = cap > 0 ? Math.min(cap, next) : next;
                return;
            }
        }
        cart.push(item);
    }

    window.addOfferToCart = function () {
        if (!allSelected()) {
            toast(OFFER.t.pick_required);
            return;
        }
        var mb = maxBundles();
        if (mb < 1) {
            toast(OFFER.t.pick_required);
            return;
        }
        var bundles = Math.max(1, Math.min(mb, parseInt(document.getElementById('offerQty').value || '1', 10) || 1));
        var cart = readCart();
        for (var i = 0; i < OFFER.components.length; i++) {
            var c = comp(i);
            var q = compQty(i);
            if (!hasVariantDims(c)) {
                pushLine(cart, {
                    id: c.product_id,
                    name: c.name,
                    price: c.price,
                    qty: q * bundles,
                    color: '',
                    size: '',
                    variant_id: 0,
                    image: c.image_cart
                }, parseInt(c.total_stock, 10) || 0);
                continue;
            }
            for (var u = 0; u < q; u++) {
                var v = resolveVariant(i, u);
                var vid = (v && v.id) ? parseInt(v.id, 10) : 0;
                pushLine(cart, {
                    id: c.product_id,
                    name: c.name,
                    price: c.price,
                    qty: bundles,
                    color: sel[i][u].color,
                    size: sel[i][u].size,
                    variant_id: vid,
                    image: c.image_cart
                }, vid > 0 ? variantStockById(i, vid) : 0);
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
            for (var u = 0; u < compQty(i); u++) {
                refreshSizes(i, u);
            }
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
