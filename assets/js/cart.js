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

function renderCart() {
    const box = document.getElementById('cartItems');
    if (!box) return;

    const items = getCart();

    if (!items.length) {
        box.innerHTML = '<div class="empty-box">' + (window.APP_T.empty_cart || 'Cart is empty.') + '</div>';
        return;
    }

    let total = 0;
    let html = '';

    items.forEach((item, idx) => {
        const lineTotal = Number(item.qty) * Number(item.price);
        total += lineTotal;

        html += `
            <div class="cart-item-card">
                <div class="cart-item-left">
                    <img src="/uploads/products/${item.image || ''}" alt="">
                </div>
                <div class="cart-item-right">
                    <h4>${item.name}</h4>
                    ${item.color ? `<p>${window.APP_T.color}: ${item.color}</p>` : ''}
                    ${item.size ? `<p>${window.APP_T.size}: ${item.size}</p>` : ''}
                    <p>${window.APP_T.quantity}: ${item.qty}</p>
                    <p>${formatMoney(item.price)}</p>
                    <button class="btn btn-secondary" onclick="removeCartItem(${idx})">Remove</button>
                </div>
            </div>
        `;
    });

    html += `<div class="cart-total-box"><strong>Total: ${formatMoney(total)}</strong></div>`;
    box.innerHTML = html;
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
        items: items
    };

    if (!payload.name || !payload.phone || !payload.area || !payload.address) {
        alert('Please fill all required fields');
        return;
    }

    const response = await fetch('/api/orders/create-order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
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

document.addEventListener('DOMContentLoaded', renderCart);
