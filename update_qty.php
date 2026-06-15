<?php
session_start();
require 'config.php';

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$cart = $_SESSION['cart'] ?? [];

/* ======================
   AJAX UPDATE QTY (NO RELOAD)
====================== */
if (isset($_POST['ajax_update'])) {
    $i = intval($_POST['index']);
    $qty = max(1, intval($_POST['qty']));

    $itemTotal = 0;
    if (isset($_SESSION['cart'][$i])) {
        $_SESSION['cart'][$i]['qty'] = $qty;
        $itemTotal = $_SESSION['cart'][$i]['price'] * $qty;
    }

    $total = 0;
    foreach ($_SESSION['cart'] as $c) {
        $total += $c['price'] * $c['qty'];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'itemTotal' => number_format($itemTotal, 2),
        'cartTotal' => number_format($total, 2)
    ]);
    exit;
}

/* ======================
   REMOVE ITEM
====================== */
if (isset($_GET['remove'])) {
    $i = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$i])) {
        unset($_SESSION['cart'][$i]);
        $_SESSION['cart'] = array_values($_SESSION['cart']);
        if (empty($_SESSION['cart'])) unset($_SESSION['cart_started_at']);
    }
    header("Location: cart.php");
    exit;
}

/* ======================
   TOTAL
====================== */
$subtotal = 0;
foreach ($cart as $item) {
    $q = isset($item['qty']) ? (int)$item['qty'] : 1;
    $subtotal += $item['price'] * $q;
}
$tax = $subtotal * (TAX_RATE / 100);
$total = $subtotal + $tax;
$total_formatted = number_format($total, 2);
$subtotal_formatted = number_format($subtotal, 2);
$tax_formatted = number_format($tax, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Cart | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ── RESET & ROOT ── */
:root {
    --bg: #0c0c0c;
    --bg-card: #121212;
    --bg-card-hover: #181818;
    --border: #1f1f1f;
    --border-hover: #2a2a2a;
    --accent: #d1904b;
    --accent-light: #e8b87a;
    --accent-dark: #a0702a;
    --text: #f5f5f5;
    --text-muted: #888888;
    --text-light: #ffffff;
    --danger: #ff6b6b;
    --success: #55e087;
    
    /* ── Shadow System ── */
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
    --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
    --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
    
    /* ── Transitions ── */
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

* { 
    box-sizing: border-box; 
    margin: 0; 
    padding: 0; 
}

html { scroll-behavior: smooth; }

body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
    padding: 40px;
}

/* ── Custom Scrollbar ── */
::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover { background: var(--accent-dark); }

/* ========== TOP BAR ========== */
.top-bar {
    max-width: 1200px;
    margin: 0 auto 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.top-bar a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 10px;
    background: var(--bg-card);
    border: 1px solid var(--border);
}

.top-bar a:hover {
    border-color: var(--accent);
    transform: translateX(-4px);
    box-shadow: var(--shadow-accent);
}

.top-bar .cart-count {
    background: var(--accent);
    color: #000;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
}

/* ========== MAIN CONTAINER ========== */
.cart-container {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 30px;
}

/* ========== CART ITEMS SECTION ========== */
.cart-items {
    background: var(--bg-card);
    border-radius: 18px;
    padding: 30px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
}

.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.cart-header h1 {
    color: var(--text);
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
}

.cart-header h1 i {
    color: var(--accent);
}

.cart-header .item-count {
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
}

/* ── EMPTY CART ── */
.empty-cart {
    text-align: center;
    padding: 60px 20px;
}

.empty-cart i {
    font-size: 64px;
    color: var(--text-muted);
    margin-bottom: 16px;
}

.empty-cart h2 {
    color: var(--text);
    font-size: 22px;
    margin-bottom: 8px;
}

.empty-cart p {
    color: var(--text-muted);
    margin-bottom: 20px;
}

.empty-cart .btn-shop {
    display: inline-block;
    background: var(--accent);
    color: #000;
    padding: 12px 32px;
    border-radius: 50px;
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
}

.empty-cart .btn-shop:hover {
    background: var(--accent-light);
    transform: translateY(-3px);
    box-shadow: var(--shadow-accent);
}

/* ── CART ITEM ── */
.cart-item {
    display: grid;
    grid-template-columns: 80px 1fr auto;
    gap: 16px;
    align-items: center;
    padding: 16px 0;
    border-bottom: 1px solid var(--border);
    transition: var(--transition);
}

.cart-item:last-child {
    border-bottom: none;
}

.cart-item:hover {
    background: var(--bg-card-hover);
    padding-left: 8px;
    padding-right: 8px;
    border-radius: 12px;
}

.cart-item img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid var(--border);
}

.cart-item .item-info {
    display: flex;
    flex-direction: column;
}

.cart-item .item-info h3 {
    color: var(--text);
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 4px 0;
}

.cart-item .item-info .item-meta {
    color: var(--text-muted);
    font-size: 13px;
    margin-bottom: 4px;
}

.cart-item .item-info .item-price {
    color: var(--accent);
    font-weight: 600;
    font-size: 15px;
}

/* ── ITEM ACTIONS ── */
.item-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* ── QTY CONTROL WITH INPUT ── */
.qty-control {
    display: flex;
    align-items: center;
    background: #0c0c0c;
    border: 1px solid var(--border);
    border-radius: 50px;
    overflow: hidden;
}

.qty-control button {
    width: 32px;
    height: 32px;
    background: none;
    border: none;
    color: var(--accent);
    font-size: 18px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
}

.qty-control button:hover {
    background: rgba(209, 144, 75, 0.15);
}

.qty-control input[type="number"] {
    width: 50px;
    text-align: center;
    font-weight: 600;
    color: var(--text);
    font-size: 14px;
    background: transparent;
    border: none;
    outline: none;
    font-family: 'Poppins', sans-serif;
    padding: 0;
    -moz-appearance: textfield;
}

.qty-control input[type="number"]::-webkit-inner-spin-button,
.qty-control input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* ── REMOVE BUTTON ── */
.remove-btn {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 107, 107, 0.1);
    color: var(--danger);
    border-radius: 50%;
    text-decoration: none;
    font-size: 16px;
    transition: var(--transition);
    border: 1px solid transparent;
}

.remove-btn:hover {
    background: var(--danger);
    color: #000;
    border-color: var(--danger);
    transform: scale(1.1);
}

/* ========== ORDER SUMMARY ========== */
.order-summary {
    background: var(--bg-card);
    border-radius: 18px;
    padding: 28px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-md);
    position: sticky;
    top: 20px;
    height: fit-content;
}

.order-summary h2 {
    color: var(--text);
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.order-summary h2 i {
    color: var(--accent);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    color: var(--text-muted);
    font-size: 14px;
}

.summary-row.total {
    border-top: 1px solid var(--border);
    margin-top: 8px;
    padding-top: 16px;
    color: var(--text);
    font-size: 18px;
    font-weight: 700;
}

.summary-row.total span:last-child {
    color: var(--accent);
}

/* ── CHECKOUT FORM ── */
.checkout-form {
    margin-top: 20px;
}

.checkout-form .form-group {
    margin-bottom: 14px;
}

.checkout-form label {
    display: block;
    color: var(--text-muted);
    font-size: 13px;
    margin-bottom: 4px;
    font-weight: 500;
}

.checkout-form input {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: #0c0c0c;
    color: var(--text);
    font-size: 14px;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}

.checkout-form input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: var(--shadow-accent);
}

.checkout-form input::placeholder {
    color: var(--text-muted);
}

.btn-checkout {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--accent), var(--accent-light));
    border: none;
    border-radius: 12px;
    color: #000;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-family: 'Poppins', sans-serif;
}

.btn-checkout:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-accent);
    filter: brightness(1.1);
}

.btn-checkout:active {
    transform: translateY(0);
}

/* ── Cash Payment Button ── */
.btn-cash {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--success), #2ecc71);
    border: none;
    border-radius: 12px;
    color: #000;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-family: 'Poppins', sans-serif;
    margin-top: 10px;
}

.btn-cash:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 20px rgba(85, 224, 135, 0.3);
    filter: brightness(1.1);
}

.btn-cash:active {
    transform: translateY(0);
}

/* ── CSRF Token ── */
.csrf-hidden {
    display: none;
}

/* ========== RESPONSIVE ========== */
@media (max-width: 992px) {
    .cart-container {
        grid-template-columns: 1fr;
    }
    
    .order-summary {
        position: static;
    }
}

@media (max-width: 768px) {
    body {
        padding: 20px;
    }
    
    .cart-container {
        gap: 20px;
    }
    
    .cart-items {
        padding: 20px;
    }
    
    .cart-header h1 {
        font-size: 20px;
    }
    
    .cart-item {
        grid-template-columns: 60px 1fr auto;
        gap: 12px;
        padding: 12px 0;
    }
    
    .cart-item img {
        width: 60px;
        height: 60px;
    }
    
    .cart-item .item-info h3 {
        font-size: 14px;
    }
    
    .qty-control button {
        width: 28px;
        height: 28px;
        font-size: 16px;
    }
    
    .qty-control input[type="number"] {
        width: 40px;
        font-size: 13px;
    }
    
    .remove-btn {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .order-summary {
        padding: 20px;
    }
    
    .order-summary h2 {
        font-size: 18px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 12px;
    }
    
    .cart-items {
        padding: 14px;
    }
    
    .cart-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .cart-header h1 {
        font-size: 18px;
    }
    
    .cart-item {
        grid-template-columns: 1fr;
        text-align: center;
        gap: 8px;
    }
    
    .cart-item img {
        width: 100%;
        height: 160px;
        margin: 0 auto;
    }
    
    .item-actions {
        justify-content: center;
    }
    
    .top-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .top-bar a {
        justify-content: center;
    }
}
</style>
</head>

<body>

<!-- TOP BAR -->
<div class="top-bar">
    <a href="menu.php">
        <i class="fa-solid fa-arrow-left"></i> Continue Ordering
    </a>
    <span style="color: var(--text-muted); font-size: 14px;">
        <i class="fa-solid fa-cart-shopping" style="color: var(--accent);"></i> 
        <span class="cart-count"><?= count($cart) ?></span> items in cart
    </span>
</div>

<div class="cart-container">

    <!-- CART ITEMS -->
    <div class="cart-items">
        <div class="cart-header">
            <h1><i class="fa-solid fa-cart-shopping"></i> Your Cart</h1>
            <span class="item-count"><?= count($cart) ?> item<?= count($cart) != 1 ? 's' : '' ?></span>
        </div>

        <?php if(empty($cart)): ?>
            <div class="empty-cart">
                <i class="fa-regular fa-face-smile"></i>
                <h2>Your cart is empty</h2>
                <p>Looks like you haven't added anything to your cart yet.</p>
                <a href="menu.php" class="btn-shop">
                    <i class="fa-solid fa-mug-hot"></i> Browse Menu
                </a>
            </div>
        <?php else: ?>

            <?php foreach($cart as $i => $item): 
                $qty = isset($item['qty']) ? (int)$item['qty'] : 1;
                $lineTotal = $item['price'] * $qty;
            ?>
            <div class="cart-item">
                <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                
                <div class="item-info">
                    <h3><?= htmlspecialchars($item['product_name']) ?></h3>
                    
                    <div class="item-meta">
                        <?php if (!empty($item['sweetness'])): ?>
                            <span>Sweetness: <?= htmlspecialchars($item['sweetness']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['ice'])): ?>
                            <span> • Ice: <?= htmlspecialchars($item['ice']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['milk'])): ?>
                            <span> • Milk: <?= htmlspecialchars($item['milk']) ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="item-price">
                        $<span id="item-total-<?= $i ?>"><?= number_format($lineTotal, 2) ?></span>
                    </div>
                </div>

                <div class="item-actions">
                    <div class="qty-control">
                        <button type="button" onclick="changeQty(<?= $i ?>, -1)">−</button>
                        <input type="number" id="qty-<?= $i ?>" value="<?= $qty ?>" min="1" 
                               onchange="updateQtyInput(<?= $i ?>, this.value)"
                               oninput="validateQtyInput(this)">
                        <button type="button" onclick="changeQty(<?= $i ?>, 1)">+</button>
                    </div>
                    
                    <a href="cart.php?remove=<?= $i ?>" class="remove-btn" title="Remove item">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <!-- ORDER SUMMARY -->
    <?php if(!empty($cart)): ?>
    <div class="order-summary">
        <h2><i class="fa-solid fa-receipt"></i> Order Summary</h2>
        
        <div class="summary-row">
            <span>Subtotal</span>
            <span>$<?= $subtotal_formatted ?></span>
        </div>
        
        <div class="summary-row">
            <span>Tax (10%)</span>
            <span>$<?= $tax_formatted ?></span>
        </div>
        
        <div class="summary-row total">
            <span>Total</span>
            <span>$<?= $total_formatted ?></span>
        </div>

        <form method="post" action="confirm_order.php" class="checkout-form">
            <div class="form-group">
                <label for="customer_name"><i class="fa-regular fa-user"></i> Your Name</label>
                <input type="text" name="customer_name" id="customer_name" placeholder="Enter your name" required>
            </div>
            
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <button type="submit" class="btn-checkout">
                <i class="fa-solid fa-credit-card"></i> Confirm & Pay with Bakong
            </button>
            
            <!-- ── Cash Payment Button ── -->
            <button type="button" class="btn-cash" onclick="payWithCash()">
                <i class="fa-solid fa-money-bill-wave"></i> Pay with Cash
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<script>
// ── Change Quantity (Button clicks) ──
function changeQty(index, delta) {
    const input = document.getElementById("qty-" + index);
    let qty = parseInt(input.value || "1", 10) + delta;
    if (qty < 1) qty = 1;
    
    input.value = qty;
    updateQtyAjax(index, qty);
}

// ── Update Quantity (Manual input) ──
function updateQtyInput(index, value) {
    let qty = parseInt(value || "1", 10);
    if (isNaN(qty) || qty < 1) qty = 1;
    
    document.getElementById("qty-" + index).value = qty;
    updateQtyAjax(index, qty);
}

// ── Validate input (prevent negative values) ──
function validateQtyInput(input) {
    if (input.value < 1) {
        input.value = 1;
    }
    if (input.value > 999) {
        input.value = 999;
    }
}

// ── AJAX Update ──
function updateQtyAjax(index, qty) {
    // Update session + totals via AJAX
    fetch("cart.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `ajax_update=1&index=${index}&qty=${qty}`
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.itemTotal) {
            document.getElementById("item-total-" + index).textContent = data.itemTotal;
        }
        if (data && data.cartTotal) {
            // Update all total displays
            document.querySelectorAll('.summary-row.total span:last-child').forEach(el => {
                el.textContent = '$' + data.cartTotal;
            });
            document.querySelector('.order-summary .total span:last-child').textContent = '$' + data.cartTotal;
        }
    })
    .catch(() => {});
}

// ── Pay with Cash ──
function payWithCash() {
    // Get the customer name
    const name = document.getElementById('customer_name').value;
    if (!name.trim()) {
        alert('Please enter your name first.');
        return;
    }
    
    // Create a hidden form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'confirm_order.php';
    
    // Add CSRF token
    const csrf = document.querySelector('input[name="csrf_token"]');
    if (csrf) {
        const csrfClone = csrf.cloneNode();
        form.appendChild(csrfClone);
    }
    
    // Add customer name
    const nameInput = document.createElement('input');
    nameInput.type = 'hidden';
    nameInput.name = 'customer_name';
    nameInput.value = name;
    form.appendChild(nameInput);
    
    // Add payment method flag
    const paymentInput = document.createElement('input');
    paymentInput.type = 'hidden';
    paymentInput.name = 'payment_method';
    paymentInput.value = 'cash';
    form.appendChild(paymentInput);
    
    document.body.appendChild(form);
    form.submit();
}
</script>

</body>
</html>