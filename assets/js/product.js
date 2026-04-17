let selectedColor = '';
let selectedSize = '';

function openProductSizingDialog() {
    const d = document.getElementById('productSizingDialog');
    if (d && typeof d.showModal === 'function') {
        d.showModal();
    }
}

function selectColor(btn) {
    document.querySelectorAll('.color-chip').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
    selectedColor = btn.dataset.color || '';
}

function selectSize(btn) {
    document.querySelectorAll('.size-chip').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
    selectedSize = btn.dataset.size || '';
}

function increaseQty() {
    const input = document.getElementById('qtyInput');
    input.value = parseInt(input.value || '1', 10) + 1;
}

function decreaseQty() {
    const input = document.getElementById('qtyInput');
    const current = parseInt(input.value || '1', 10);
    input.value = Math.max(1, current - 1);
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
    if (!p) return;

    if (p.has_colors === 1 && !selectedColor) {
        alert(window.APP_T.select_color || 'Please select a color');
        return;
    }

    if (p.has_sizes === 1 && !selectedSize) {
        alert(window.APP_T.select_size || 'Please select a size');
        return;
    }

    const qty = Math.max(1, parseInt(document.getElementById('qtyInput').value || '1', 10));

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
        image: p.image
    };

    let cart = [];
    try {
        cart = JSON.parse(localStorage.getItem('cart') || '[]');
    } catch (e) {
        cart = [];
    }

    cart.push(item);
    localStorage.setItem('cart', JSON.stringify(cart));
    alert(window.APP_T.added || 'Added');
}
