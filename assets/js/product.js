let selectedColor = '';
let selectedSize = '';

let orangeGalleryListenerGen = 0;

function orangeProductEscAttr(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
}

function orangeProductGalleryReplaceUrls(urls) {
    const root = document.getElementById('productGallery');
    if (!root) {
        return;
    }
    const clean = (Array.isArray(urls) ? urls : []).filter(Boolean);
    if (!clean.length) {
        return;
    }
    orangeGalleryListenerGen += 1;
    const alt = (window.CURRENT_PRODUCT && window.CURRENT_PRODUCT.name) || '';
    const n = clean.length;
    const slides = clean
        .map(
            (u) =>
                '<div class="product-gallery__slide"><img class="product-gallery__img" src="' +
                orangeProductEscAttr(u) +
                '" alt="' +
                orangeProductEscAttr(alt) +
                '" loading="lazy"></div>'
        )
        .join('');
    let inner = '';
    if (n <= 1) {
        inner =
            '<div class="product-gallery__stage"><div class="product-gallery__viewport" id="productGalleryViewport"><div class="product-gallery__track" id="productGalleryTrack">' +
            slides +
            '</div></div></div>';
    } else {
        const glPrevLabel = (window.APP_T && window.APP_T.product_gallery_prev) || '';
        const glNextLabel = (window.APP_T && window.APP_T.product_gallery_next) || '';
        const glDotsLabel = (window.APP_T && window.APP_T.product_gallery_dots) || '';
        let dots = '';
        for (let di = 0; di < n; di++) {
            dots +=
                '<button type="button" class="product-gallery__dot' +
                (di === 0 ? ' is-active' : '') +
                '" role="tab" aria-selected="' +
                (di === 0 ? 'true' : 'false') +
                '" data-index="' +
                di +
                '" aria-label="' +
                (di + 1) +
                ' / ' +
                n +
                '"></button>';
        }
        let thumbs = '';
        clean.forEach((u, ti) => {
            thumbs +=
                '<button type="button" class="thumb' +
                (ti === 0 ? ' active' : '') +
                '" data-gallery-index="' +
                ti +
                '"><img src="' +
                orangeProductEscAttr(u) +
                '" alt=""></button>';
        });
        inner =
            '<div class="product-gallery__stage">' +
            '<button type="button" class="product-gallery__nav product-gallery__nav--prev" id="productGalleryPrev" aria-label="' +
            orangeProductEscAttr(glPrevLabel) +
            '"><span aria-hidden="true">‹</span></button>' +
            '<div class="product-gallery__viewport" id="productGalleryViewport" tabindex="0">' +
            '<div class="product-gallery__track" id="productGalleryTrack">' +
            slides +
            '</div></div>' +
            '<button type="button" class="product-gallery__nav product-gallery__nav--next" id="productGalleryNext" aria-label="' +
            orangeProductEscAttr(glNextLabel) +
            '"><span aria-hidden="true">›</span></button></div>' +
            '<div class="product-gallery__dots" id="productGalleryDots" role="tablist" aria-label="' +
            orangeProductEscAttr(glDotsLabel) +
            '">' +
            dots +
            '</div>' +
            '<div class="thumbs product-gallery__thumbs">' +
            thumbs +
            '</div>';
    }
    root.innerHTML = inner;
    root.setAttribute('data-gallery-count', String(n));
    initProductGallery();
}

function orangeProductGalleryApplyForSelection() {
    const p = window.CURRENT_PRODUCT;
    if (!p || parseInt(p.has_colors, 10) !== 1) {
        return;
    }
    const map = p.colorway_gallery || {};
    const urls = selectedColor && map[selectedColor] ? map[selectedColor] : [];
    const fallback = p.default_gallery_urls || [];
    const use = urls.length ? urls : fallback;
    orangeProductGalleryReplaceUrls(use);
}

function orangeProductToast(message, durationMs) {
    if (typeof window.orangeShowToast === 'function') {
        window.orangeShowToast(message, durationMs);
    } else {
        alert(message);
    }
}

function orangeProductCartStorageKey() {
    if (typeof window.orangeSfCartKey === 'function') {
        return window.orangeSfCartKey();
    }
    return 'orange_sf_cart_orange';
}

function readCartJson() {
    try {
        const key = orangeProductCartStorageKey();
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

function writeCartJson(cart) {
    localStorage.setItem(orangeProductCartStorageKey(), JSON.stringify(cart));
}

const ORANGE_ADV_SIZING_UNIT_KEY = 'orange_sf_adv_sizing_unit';
const ORANGE_ADV_SIZING_SYS_KEY = 'orange_sf_adv_sizing_sys';

function orangeAdvisoryFormatMeasureNumber(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) {
        return '';
    }
    let s = n.toFixed(2);
    s = s.replace(/\.?0+$/, '');
    return s;
}

function orangeAdvisoryFormatCmSpanText(lo, hi, useInch) {
    const a = useInch ? lo / 2.54 : lo;
    const b = useInch ? hi / 2.54 : hi;
    const fa = orangeAdvisoryFormatMeasureNumber(a);
    const fb = orangeAdvisoryFormatMeasureNumber(b);
    if (fa === fb) {
        return fa;
    }
    return `${fa}–${fb}`;
}

function orangeAdvisoryApplyUnits(dialog, useInch) {
    const u = window.ORANGE_ADVISORY_UX;
    if (!u || !u.hasLength) {
        return;
    }
    const lc = u.labelCm || 'cm';
    const li = u.labelInch || 'in';
    dialog.querySelectorAll('td[data-adv-cm-lo][data-adv-cm-hi]').forEach((td) => {
        const lo = parseFloat(td.getAttribute('data-adv-cm-lo'), 10);
        const hi = parseFloat(td.getAttribute('data-adv-cm-hi'), 10);
        if (Number.isNaN(lo) || Number.isNaN(hi)) {
            return;
        }
        const mid = `${orangeAdvisoryFormatCmSpanText(lo, hi, useInch)} ${useInch ? li : lc}`;
        td.textContent = mid;
    });
    dialog.querySelectorAll('td[data-adv-cm]:not([data-adv-cm-lo])').forEach((td) => {
        const cm = parseFloat(td.getAttribute('data-adv-cm'), 10);
        if (Number.isNaN(cm)) {
            return;
        }
        if (!useInch) {
            td.textContent = `${orangeAdvisoryFormatMeasureNumber(cm)} ${lc}`;
        } else {
            const inch = cm / 2.54;
            td.textContent = `${orangeAdvisoryFormatMeasureNumber(inch)} ${li}`;
        }
    });
}

function orangeAdvisoryColumnVisibleForSystem(el, system) {
    const ds = (el.getAttribute('data-adv-dsys') || '').toLowerCase().trim();
    if (ds === '') {
        return true;
    }
    return ds === system;
}

function orangeAdvisoryApplySystemSelection(dialog, system) {
    const u = window.ORANGE_ADVISORY_UX;
    if (!u || !u.hasSystems) {
        return;
    }
    const sys = String(system || '').toLowerCase().trim();
    dialog.querySelectorAll('table.product-sizing-table--pro').forEach((table) => {
        const headerCells = table.querySelectorAll('thead tr th');
        headerCells.forEach((th, idx) => {
            const hide = !orangeAdvisoryColumnVisibleForSystem(th, sys);
            th.classList.toggle('product-sizing-col--hidden', hide);
            table.querySelectorAll('tbody tr.product-sizing-table__data-row').forEach((tr) => {
                const td = tr.children[idx];
                if (td) {
                    td.classList.toggle('product-sizing-col--hidden', hide);
                }
            });
        });
    });
}

function orangeAdvisorySizingRefresh(dialog) {
    const u = window.ORANGE_ADVISORY_UX;
    if (!u) {
        return;
    }
    const selUnit = dialog.querySelector('#productSizingAdvUnit');
    const selSys = dialog.querySelector('#productSizingAdvSys');
    if (selUnit && u.hasLength) {
        orangeAdvisoryApplyUnits(dialog, selUnit.value === 'inch');
    }
    if (selSys && u.hasSystems) {
        orangeAdvisoryApplySystemSelection(dialog, selSys.value);
    }
}

function orangeAdvisorySizingActivateTab(dialog, tabKind) {
    const tabs = dialog.querySelectorAll('.product-sizing-adv-tabs__btn[role="tab"]');
    const panels = dialog.querySelectorAll('.product-sizing-adv-tabpanel[role="tabpanel"]');
    if (!tabs.length || !panels.length) {
        return;
    }
    const kind = String(tabKind || 'upper').toLowerCase();
    tabs.forEach((btn) => {
        const active = (btn.getAttribute('data-adv-tab') || 'upper') === kind;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.forEach((panel) => {
        const active = (panel.getAttribute('data-adv-panel') || 'upper') === kind;
        panel.classList.toggle('is-active', active);
        if (active) {
            panel.removeAttribute('hidden');
        } else {
            panel.setAttribute('hidden', '');
        }
    });
    orangeAdvisorySizingRefresh(dialog);
}

function orangeAdvisorySizingEnsureBound(dialog) {
    if (dialog.dataset.orangeAdvBound === '1') {
        orangeAdvisorySizingRefresh(dialog);
        return;
    }
    const u = window.ORANGE_ADVISORY_UX;
    if (!u) {
        return;
    }
    dialog.dataset.orangeAdvBound = '1';
    const tabBar = dialog.querySelector('.product-sizing-adv-tabs');
    if (tabBar && tabBar.dataset.orangeAdvTabsBound !== '1') {
        tabBar.dataset.orangeAdvTabsBound = '1';
        tabBar.addEventListener('click', (ev) => {
            const btn = ev.target.closest('.product-sizing-adv-tabs__btn[role="tab"]');
            if (!btn || !tabBar.contains(btn)) {
                return;
            }
            orangeAdvisorySizingActivateTab(dialog, btn.getAttribute('data-adv-tab') || 'upper');
        });
    }
    const selUnit = dialog.querySelector('#productSizingAdvUnit');
    const selSys = dialog.querySelector('#productSizingAdvSys');
    if (selUnit && u.hasLength) {
        const stored = localStorage.getItem(ORANGE_ADV_SIZING_UNIT_KEY);
        if (stored === 'inch' || stored === 'cm') {
            selUnit.value = stored;
        }
        selUnit.addEventListener('change', () => {
            localStorage.setItem(ORANGE_ADV_SIZING_UNIT_KEY, selUnit.value);
            orangeAdvisoryApplyUnits(dialog, selUnit.value === 'inch');
        });
    }
    if (selSys && u.hasSystems) {
        const storedS = localStorage.getItem(ORANGE_ADV_SIZING_SYS_KEY);
        if (storedS && Array.isArray(u.systems) && u.systems.indexOf(storedS) >= 0) {
            selSys.value = storedS;
        }
        selSys.addEventListener('change', () => {
            localStorage.setItem(ORANGE_ADV_SIZING_SYS_KEY, selSys.value);
            orangeAdvisoryApplySystemSelection(dialog, String(selSys.value).toLowerCase());
        });
    }
    orangeAdvisorySizingRefresh(dialog);
}

function openProductSizingDialog() {
    const d = document.getElementById('productSizingDialog');
    if (d && typeof d.showModal === 'function') {
        d.showModal();
        orangeAdvisorySizingEnsureBound(d);
    }
}

function getEffectiveVariant(p) {
    if (!p || !p.variants || !p.variants.length) {
        return null;
    }
    const hc = parseInt(p.has_colors, 10) === 1;
    const hs = parseInt(p.has_sizes, 10) === 1;
    if (!hc && !hs) {
        return p.variants[0];
    }
    return resolveSelectedVariant(p);
}

function cartQuantityForLine(productId, variant) {
    if (!variant) {
        return 0;
    }
    const cart = readCartJson();
    const vid = variant.id ? parseInt(variant.id, 10) : 0;
    const c = variant.color != null ? String(variant.color) : '';
    const s = variant.size != null ? String(variant.size) : '';
    let sum = 0;
    for (let i = 0; i < cart.length; i++) {
        const it = cart[i];
        if (parseInt(it.id, 10) !== parseInt(productId, 10)) {
            continue;
        }
        if (vid > 0 && parseInt(it.variant_id || 0, 10) === vid) {
            sum += Math.max(0, parseInt(it.qty || 0, 10));
            continue;
        }
        if (vid === 0) {
            const ic = it.color != null ? String(it.color) : '';
            const iz = it.size != null ? String(it.size) : '';
            if (ic === c && iz === s) {
                sum += Math.max(0, parseInt(it.qty || 0, 10));
            }
        }
    }
    return sum;
}

function getQtyState() {
    const p = window.CURRENT_PRODUCT;
    if (!p) {
        return {
            selectionComplete: false,
            stock: 0,
            inCart: 0,
            avail: 0,
            variant: null,
        };
    }
    const hc = parseInt(p.has_colors, 10) === 1;
    const hs = parseInt(p.has_sizes, 10) === 1;
    const selectionComplete = (!hc || selectedColor) && (!hs || selectedSize);
    if ((hc || hs) && !selectionComplete) {
        return { selectionComplete: false, stock: 0, inCart: 0, avail: 0, variant: null };
    }
    const v = getEffectiveVariant(p);
    const stock = v ? Math.max(0, parseInt(v.stock_quantity, 10) || 0) : 0;
    const inCart = v ? cartQuantityForLine(p.id, v) : 0;
    const avail = Math.max(0, stock - inCart);
    return { selectionComplete, stock, inCart, avail, variant: v };
}

function lowStockThreshold() {
    const p = window.CURRENT_PRODUCT;
    const t = p ? parseInt(p.low_stock_threshold, 10) : 5;
    return t > 0 ? t : 5;
}

function syncProductQtyLimits() {
    const p = window.CURRENT_PRODUCT;
    if (!p) {
        return;
    }
    const input = document.getElementById('qtyInput');
    const banner = document.getElementById('productStockBanner');
    const addBtn = document.querySelector('.product-add-cart-btn');
    const totalSum = parseInt(p.total_stock_sum, 10) || 0;

    if (totalSum <= 0) {
        return;
    }

    const { selectionComplete, stock, avail } = getQtyState();
    const hc = parseInt(p.has_colors, 10) === 1;
    const hs = parseInt(p.has_sizes, 10) === 1;
    const th = lowStockThreshold();

    if (input) {
        if ((hc || hs) && !selectionComplete) {
            input.removeAttribute('max');
            let q = parseInt(input.value || '1', 10);
            if (!q || q < 1) {
                input.value = '1';
            }
        } else if (avail <= 0) {
            input.setAttribute('max', '1');
            input.value = '1';
        } else {
            input.setAttribute('max', String(avail));
            let q = parseInt(input.value || '1', 10);
            if (!q || q < 1) {
                q = 1;
            }
            input.value = String(Math.min(q, avail));
        }
    }

    if (banner) {
        banner.classList.remove('stock-banner--low', 'stock-banner--out', 'stock-banner--cart');
        banner.hidden = true;
        banner.textContent = '';

        if ((hc || hs) && !selectionComplete) {
            /* wait for color/size */
        } else if (stock <= 0) {
            banner.textContent = window.APP_T.out_of_stock || '';
            banner.classList.add('stock-banner--out');
            banner.hidden = false;
        } else if (avail <= 0 && stock > 0) {
            banner.textContent = window.APP_T.no_more_stock_for_cart || '';
            banner.classList.add('stock-banner--cart');
            banner.hidden = false;
        } else if (stock <= th) {
            banner.textContent = window.APP_T.low_stock || '';
            banner.classList.add('stock-banner--low');
            banner.hidden = false;
        }
    }

    if (addBtn) {
        const canAdd =
            totalSum > 0 &&
            (!hc || selectedColor) &&
            (!hs || selectedSize) &&
            avail > 0 &&
            stock > 0;
        addBtn.disabled = !canAdd;
    }
}

function selectColor(btn) {
    document.querySelectorAll('.color-chip').forEach((el) => el.classList.remove('active'));
    btn.classList.add('active');
    selectedColor = btn.dataset.color || '';
    orangeProductGalleryApplyForSelection();
    syncProductQtyLimits();
}

function selectSize(btn) {
    document.querySelectorAll('.size-chip').forEach((el) => el.classList.remove('active'));
    btn.classList.add('active');
    selectedSize = btn.dataset.size || '';
    syncProductQtyLimits();
}

function increaseQty() {
    const input = document.getElementById('qtyInput');
    if (!input) {
        return;
    }
    const { avail, selectionComplete } = getQtyState();
    if (!selectionComplete) {
        return;
    }
    if (avail <= 0) {
        return;
    }
    const current = parseInt(input.value || '1', 10);
    input.value = String(Math.min(avail, current + 1));
}

function decreaseQty() {
    const input = document.getElementById('qtyInput');
    if (!input) {
        return;
    }
    const current = parseInt(input.value || '1', 10);
    input.value = String(Math.max(1, current - 1));
}

function clampQtyInput() {
    const input = document.getElementById('qtyInput');
    if (!input) {
        return;
    }
    const { avail, selectionComplete } = getQtyState();
    let q = parseInt(input.value || '1', 10);
    if (!q || q < 1) {
        q = 1;
    }
    if (selectionComplete && avail > 0) {
        q = Math.min(q, avail);
    }
    input.value = String(q);
}

function resolveSelectedVariant(p) {
    if (!p.variants || !p.variants.length) {
        return null;
    }
    for (let i = 0; i < p.variants.length; i++) {
        const v = p.variants[i];
        const c = (v.color || '') === (selectedColor || '');
        const s = (v.size || '') === (selectedSize || '');
        if (c && s) {
            return v;
        }
    }
    return null;
}

function addCurrentProductToCart() {
    const p = window.CURRENT_PRODUCT;
    if (!p) {
        return;
    }

    if (p.has_colors === 1 && !selectedColor) {
        orangeProductToast(window.APP_T.select_color || 'Please select a color', 2800);
        return;
    }

    if (p.has_sizes === 1 && !selectedSize) {
        orangeProductToast(window.APP_T.select_size || 'Please select a size', 2800);
        return;
    }

    const { avail, stock, selectionComplete } = getQtyState();
    if (!selectionComplete || stock <= 0) {
        return;
    }

    const qty = Math.max(1, parseInt(document.getElementById('qtyInput').value || '1', 10));

    if (qty > avail) {
        const tpl = window.APP_T.available_max_qty || 'Max: {n}';
        orangeProductToast(tpl.replace(/\{n\}/g, String(avail)), 3200);
        syncProductQtyLimits();
        return;
    }

    const vMatch = resolveSelectedVariant(p);
    const variantId = vMatch && vMatch.id ? parseInt(vMatch.id, 10) : 0;

    const item = {
        id: p.id,
        name: p.name,
        price: p.price,
        qty: qty,
        color: selectedColor,
        size: selectedSize,
        variant_id: variantId,
        image: p.image,
    };

    let cart = readCartJson();
    let merged = false;
    for (let i = 0; i < cart.length; i++) {
        if (typeof cartLinesMatch === 'function' && cartLinesMatch(cart[i], item)) {
            const nextQty = parseInt(cart[i].qty, 10) + qty;
            cart[i].qty = Math.min(stock, nextQty);
            merged = true;
            break;
        }
    }
    if (!merged) {
        cart.push(item);
    }
    writeCartJson(cart);
    if (typeof normalizeCartDuplicates === 'function') {
        normalizeCartDuplicates();
    }
    if (typeof window.orangeAnimateCartPulse === 'function') {
        window.orangeAnimateCartPulse();
    }
    orangeProductToast(window.APP_T.added || 'Added', 2400);
    syncProductQtyLimits();
}

function initProductGallery() {
    const root = document.getElementById('productGallery');
    const track = document.getElementById('productGalleryTrack');
    const viewport = document.getElementById('productGalleryViewport');
    if (!root || !track || !viewport) {
        return;
    }
    const myGen = orangeGalleryListenerGen;
    const n =
        parseInt(root.getAttribute('data-gallery-count') || '0', 10) ||
        track.querySelectorAll('.product-gallery__slide').length;
    if (n <= 1) {
        return;
    }

    let index = 0;

    function setIndex(i) {
        index = Math.max(0, Math.min(n - 1, i));
        track.style.transform = 'translateX(-' + index * 100 + '%)';
        document.querySelectorAll('.product-gallery__dot').forEach((d, di) => {
            const on = di === index;
            d.classList.toggle('is-active', on);
            d.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.product-gallery__thumbs .thumb').forEach((t, ti) => {
            t.classList.toggle('active', ti === index);
        });
    }

    function next() {
        setIndex(index + 1 >= n ? 0 : index + 1);
    }

    function prev() {
        setIndex(index - 1 < 0 ? n - 1 : index - 1);
    }

    const btnP = document.getElementById('productGalleryPrev');
    const btnN = document.getElementById('productGalleryNext');
    if (btnP) {
        btnP.addEventListener('click', prev);
    }
    if (btnN) {
        btnN.addEventListener('click', next);
    }

    document.querySelectorAll('.product-gallery__dot').forEach((d) => {
        d.addEventListener('click', () => setIndex(parseInt(d.getAttribute('data-index') || '0', 10)));
    });
    document.querySelectorAll('.product-gallery__thumbs .thumb').forEach((t) => {
        t.addEventListener('click', () => setIndex(parseInt(t.getAttribute('data-gallery-index') || '0', 10)));
    });

    let touchStartX = null;
    viewport.addEventListener(
        'touchstart',
        (e) => {
            if (e.touches && e.touches[0]) {
                touchStartX = e.touches[0].clientX;
            }
        },
        { passive: true }
    );
    viewport.addEventListener(
        'touchend',
        (e) => {
            if (touchStartX == null) {
                return;
            }
            const x = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientX : touchStartX;
            const dx = x - touchStartX;
            touchStartX = null;
            if (dx > 55) {
                prev();
            } else if (dx < -55) {
                next();
            }
        },
        { passive: true }
    );

    function onDocKey(e) {
        if (myGen !== orangeGalleryListenerGen) {
            return;
        }
        if (!document.getElementById('productGalleryTrack')) {
            return;
        }
        const tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
            return;
        }
        if (e.key === 'ArrowLeft') {
            prev();
            e.preventDefault();
        } else if (e.key === 'ArrowRight') {
            next();
            e.preventDefault();
        }
    }
    document.addEventListener('keydown', onDocKey);

    setIndex(0);
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('qtyInput');
    if (input) {
        input.addEventListener('change', clampQtyInput);
        input.addEventListener('blur', clampQtyInput);
    }
    syncProductQtyLimits();
    initProductGallery();
});
