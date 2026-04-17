let selectedColor = '';
let selectedSize = '';

function readCartJson() {
    try {
        return JSON.parse(localStorage.getItem('cart') || '[]');
    } catch (e) {
        return [];
    }
}

function openProductSizingDialog() {
    const d = document.getElementById('productSizingDialog');
    if (d && typeof d.showModal === 'function') {
        d.showModal();
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
        alert(window.APP_T.select_color || 'Please select a color');
        return;
    }

    if (p.has_sizes === 1 && !selectedSize) {
        alert(window.APP_T.select_size || 'Please select a size');
        return;
    }

    const { avail, stock, selectionComplete } = getQtyState();
    if (!selectionComplete || stock <= 0) {
        return;
    }

    const qty = Math.max(1, parseInt(document.getElementById('qtyInput').value || '1', 10));

    if (qty > avail) {
        const tpl = window.APP_T.available_max_qty || 'Max: {n}';
        alert(tpl.replace(/\{n\}/g, String(avail)));
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
    localStorage.setItem('cart', JSON.stringify(cart));
    if (typeof normalizeCartDuplicates === 'function') {
        normalizeCartDuplicates();
    }
    alert(window.APP_T.added || 'Added');
    syncProductQtyLimits();
}

document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('qtyInput');
    if (input) {
        input.addEventListener('change', clampQtyInput);
        input.addEventListener('blur', clampQtyInput);
    }
    syncProductQtyLimits();
});
