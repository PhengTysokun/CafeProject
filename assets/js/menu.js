let product = {};
const csrfToken = (window.MENU_CONFIG && window.MENU_CONFIG.csrfToken) ? window.MENU_CONFIG.csrfToken : '';

// ── Toast container ──
document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('toast-container')) {
        const el = document.createElement('div');
        el.id = 'toast-container';
        document.body.appendChild(el);
    }
    _bindModalDismiss();
    _bindProductCards();
    _bindChatInput();
});

// ─────────────────────────────────────────────
//  MODAL
// ─────────────────────────────────────────────
function openModal(id, name, price, img, cat, desc) {
    product = { id, name, price: Number(price) || 0, cat };

    document.getElementById('modalImage').src            = img;
    document.getElementById('modalName').textContent     = name;
    document.getElementById('modalDesc').textContent     = desc || '';
    document.getElementById('modalPrice').textContent    = '$' + product.price.toFixed(2);

    const isJuice = cat === 'Juice';
    const isHot   = cat === 'Hot';

    _show('sweetnessGroup', !isJuice);
    _show('iceGroup',       !isJuice && !isHot);
    _show('milkGroup',      !isJuice);

    document.getElementById('customModal').style.display = 'flex';
    document.getElementById('customModal').setAttribute('aria-hidden', 'false');
}

function closeModal() {
    document.getElementById('customModal').style.display = 'none';
    document.getElementById('customModal').setAttribute('aria-hidden', 'true');
}

function _bindModalDismiss() {
    const modal = document.getElementById('customModal');
    if (!modal) return;
    modal.addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
}

// ─────────────────────────────────────────────
//  ADD TO CART (from modal)
// ─────────────────────────────────────────────
function addToCart() {
    const params = new URLSearchParams({ id: product.id });

    if (_isVisible('sweetnessGroup')) {
        params.append('sweetness', document.getElementById('sweetnessSelect').value);
    }
    if (_isVisible('iceGroup')) {
        params.append('ice', document.getElementById('iceSelect').value);
    }
    if (_isVisible('milkGroup')) {
        params.append('milk', document.getElementById('milkSelect').value);
    }

    params.append('csrf_token', csrfToken);

    _postCart(params).then(data => {
        if (!data || !data.success) {
            showToast((data && data.message) ? data.message : '❌ Error adding to cart', 'error');
            return;
        }
        showToast('✅ ' + data.message);
        closeModal();
        _updateCartCount(data.cart_count);
    }).catch(() => showToast('❌ Network error. Please try again.', 'error'));
}

// ─────────────────────────────────────────────
//  QUICK ADD (no customisation)
// ─────────────────────────────────────────────
function quickAdd(productId, event) {
    if (event) event.stopPropagation(); // prevent card click → modal

    const params = new URLSearchParams({ id: productId, csrf_token: csrfToken });

    _postCart(params).then(data => {
        if (!data || !data.success) {
            showToast((data && data.message) ? '❌ ' + data.message : '❌ Error adding to cart', 'error');
            return;
        }
        showToast('✅ ' + data.message);
        _updateCartCount(data.cart_count);
    }).catch(() => showToast('❌ Network error. Please try again.', 'error'));
}

// ─────────────────────────────────────────────
//  SHARED FETCH HELPER
// ─────────────────────────────────────────────
function _postCart(params) {
    return fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: params.toString()
    }).then(res => {
        if (!res.ok) return res.json().then(d => { throw d; });
        return res.json();
    });
}

// ─────────────────────────────────────────────
//  CART BADGE
// ─────────────────────────────────────────────
function _updateCartCount(count) {
    if (count == null) return;
    let badge = document.querySelector('.cart-count');
    if (badge) {
        badge.textContent = count;
    } else {
        const cartIcon = document.querySelector('.cart-icon');
        if (cartIcon) {
            badge = document.createElement('span');
            badge.className = 'cart-count';
            badge.textContent = count;
            cartIcon.appendChild(badge);
        }
    }
    // ── FIX: Hide badge when cart is empty, show when not ──
    if (badge) badge.style.display = (parseInt(count, 10) === 0) ? 'none' : '';
}

// ─────────────────────────────────────────────
//  TOAST
// ─────────────────────────────────────────────
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// ─────────────────────────────────────────────
//  PRODUCT CARDS
// ─────────────────────────────────────────────
function _bindProductCards() {
    document.querySelectorAll('.js-open-product').forEach(card => {
        card.addEventListener('click', () => openModal(
            card.dataset.productId,
            card.dataset.productName    || '',
            Number(card.dataset.productPrice || 0),
            card.dataset.productImage   || '',
            card.dataset.productCategory || '',
            card.dataset.productDesc    || ''
        ));
        card.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                card.click();
            }
        });
    });
}

// ─────────────────────────────────────────────
//  CHAT
// ─────────────────────────────────────────────
function toggleChat() {
    const box = document.getElementById('chatBox');
    if (!box) return;
    const isOpen = box.style.display === 'flex';
    box.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) document.getElementById('chatInput')?.focus();
}

function sendChat() {
    const input = document.getElementById('chatInput');
    const msg   = input.value.trim();
    if (!msg) return;

    const chat    = document.getElementById('chatMessages');
    const sendBtn = document.querySelector('#chatBox .send-btn');

    _appendChatBubble(chat, msg, 'user');
    input.value     = '';
    if (sendBtn) sendBtn.disabled = true;

    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(msg)
    })
        .then(res => res.json())
        .then(data => {
            _appendChatBubble(chat, data.reply || "Sorry, I didn't catch that.", 'bot');
        })
        .catch(() => {
            _appendChatBubble(chat, 'Sorry, I\'m having trouble connecting. Please try again later.', 'bot');
        })
        .finally(() => {
            if (sendBtn) sendBtn.disabled = false;
        });
}

function _appendChatBubble(container, text, role) {
    const wrap = document.createElement('div');
    wrap.className = `chat-bubble-wrap chat-${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    // Use textContent to prevent XSS
    bubble.textContent = text;

    const icon = document.createElement('div');
    icon.className = 'chat-avatar';
    icon.innerHTML = role === 'user'
        ? '<i class="fa-solid fa-user"></i>'
        : '<i class="fa-solid fa-robot"></i>';

    if (role === 'user') {
        wrap.appendChild(bubble);
        wrap.appendChild(icon);
    } else {
        wrap.appendChild(icon);
        wrap.appendChild(bubble);
    }

    container.appendChild(wrap);
    container.scrollTop = container.scrollHeight;
}

function _bindChatInput() {
    document.getElementById('chatInput')?.addEventListener('keypress', e => {
        if (e.key === 'Enter') sendChat();
    });
}

// ─────────────────────────────────────────────
//  UTILS
// ─────────────────────────────────────────────
function _show(id, visible) {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? 'block' : 'none';
}

function _isVisible(id) {
    const el = document.getElementById(id);
    return el && el.style.display !== 'none';
}