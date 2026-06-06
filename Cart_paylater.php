<?php
require 'admin_only.php';

$_SESSION['redirect_count'] = 0;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Force paylater payment method
$_SESSION['forced_payment'] = 'paylater';

$add_to_order_id  = isset($_SESSION['add_to_order_id'])  ? (int)$_SESSION['add_to_order_id']  : 0;
$add_to_daily_no  = isset($_SESSION['add_to_daily_no'])  ? (int)$_SESSION['add_to_daily_no']  : 0;
$is_add_to_order  = $add_to_order_id > 0;

$cart = $_SESSION['cart'] ?? [];

/* ── AJAX UPDATE QTY ── */
if (isset($_POST['ajax_update'])) {
    $i   = intval($_POST['index']);
    $qty = max(1, intval($_POST['qty']));
    if (isset($_SESSION['cart'][$i])) {
        $_SESSION['cart'][$i]['qty'] = $qty;
    }
    $subtotal  = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX; $min_item_name_plaj = '';
    foreach ($_SESSION['cart'] as $item) {
        $q = (int)($item['qty'] ?? 1);
        $p = (float)($item['price'] ?? 0);
        $subtotal  += $p * $q;
        $total_qty += $q;
        if ($p < $min_price) { $min_price = $p; $min_item_name_plaj = $item['product_name'] ?? ''; }
    }
    $buy3_discount = 0;
    if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT) {
        $free_items = floor($total_qty / BUY_X_COUNT);
        if ($free_items > 0 && $min_price < PHP_FLOAT_MAX)
            $buy3_discount = $free_items * $min_price;
    }
    $happy_hour_discount = 0;
    $current_hour = (int)date('H');
    if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
        $happy_hour_discount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
    }
    $after_pl_aj = $subtotal - $happy_hour_discount;
    $md_pl_aj = $_SESSION['manual_discount'] ?? null;
    $manual_disc_pl_aj = 0.0; $manual_label_pl_aj = '';
    if ($md_pl_aj && (float)($md_pl_aj['amount'] ?? 0) > 0) {
        $manual_disc_pl_aj = $md_pl_aj['type'] === 'flat' ? min((float)$md_pl_aj['amount'], max(0, $after_pl_aj)) : max(0, $after_pl_aj) * ((float)$md_pl_aj['amount'] / 100.0);
        $r_pl_aj = trim($md_pl_aj['reason'] ?? ''); $manual_label_pl_aj = $r_pl_aj ?: 'Discount';
        if ($md_pl_aj['type'] === 'percent') $manual_label_pl_aj .= ' (' . (int)$md_pl_aj['amount'] . '% off)';
        $after_pl_aj -= $manual_disc_pl_aj;
    }
    $subtotal_after = $after_pl_aj;
    $tax   = $subtotal_after * 0.10;
    $total = round($subtotal_after + $tax, 2);
    $updated = $_SESSION['cart'][$i] ?? null;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'itemLineTotal'         => number_format(($updated ? (float)$updated['price'] * $qty : 0), 2, '.', ''),
        'cartSubtotal'          => number_format($subtotal, 2, '.', ''),
        'cartTotal'             => number_format($total, 2, '.', ''),
        'buy3_discount'         => number_format($buy3_discount, 2, '.', ''),
        'free_item_name'        => $buy3_discount > 0 ? $min_item_name_plaj : '',
        'free_item_price'       => $buy3_discount > 0 ? number_format($min_price, 2, '.', '') : '0.00',
        'happy_hour_discount'   => number_format($happy_hour_discount, 2, '.', ''),
        'manual_discount'       => number_format($manual_disc_pl_aj, 2, '.', ''),
        'manual_discount_label' => $manual_label_pl_aj,
        'tax'                   => number_format($tax, 2, '.', ''),
        'cartCount'             => $total_qty,
    ]);
    exit;
}

/* ── AJAX APPLY MANUAL DISCOUNT ── */
if (isset($_POST['ajax_apply_discount'])) {
    header('Content-Type: application/json');
    $type   = in_array($_POST['type'] ?? '', ['percent', 'flat']) ? $_POST['type'] : 'percent';
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    $reason = substr(trim($_POST['reason'] ?? ''), 0, 100);
    if ($type === 'percent') $amount = min(100, $amount);
    if ($amount > 0) {
        $_SESSION['manual_discount'] = ['type' => $type, 'amount' => $amount, 'reason' => $reason];
    } else {
        unset($_SESSION['manual_discount']);
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ── AJAX CLEAR MANUAL DISCOUNT ── */
if (isset($_POST['ajax_clear_discount'])) {
    unset($_SESSION['manual_discount']);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

/* ── REMOVE ITEM ── */
if (isset($_GET['remove'])) {
    $i = intval($_GET['remove']);
    if (isset($_SESSION['cart'][$i])) {
        unset($_SESSION['cart'][$i]);
        $_SESSION['cart'] = array_values($_SESSION['cart']);
    }
    echo "<script>window.location.href='cart_paylater.php';</script>";
    exit;
}

/* ── TOTALS ── */
$subtotal = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX;
$cheapest_item_index_pl = -1; $cheapest_item_name_pl = '';
foreach ($cart as $_pl_i => $item) {
    $qty   = (int)($item['qty'] ?? 1);
    $price = (float)($item['price'] ?? 0);
    $subtotal  += $price * $qty;
    $total_qty += $qty;
    if ($price < $min_price) {
        $min_price = $price;
        $cheapest_item_index_pl = $_pl_i;
        $cheapest_item_name_pl = $item['product_name'] ?? '';
    }
}
$buy3_discount = 0;
if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT) {
    $free_items = floor($total_qty / BUY_X_COUNT);
    if ($free_items > 0 && $min_price < PHP_FLOAT_MAX)
        $buy3_discount = $free_items * $min_price;
}
$happy_hour_discount = 0;
$current_hour = (int)date('H');
if (HAPPY_HOUR_ENABLED && $current_hour >= HAPPY_HOUR_START && $current_hour < HAPPY_HOUR_END) {
    $happy_hour_discount = $subtotal * (HAPPY_HOUR_DISCOUNT / 100);
}
$after_promos_pl = $subtotal - $happy_hour_discount;
$md_pl = $_SESSION['manual_discount'] ?? null;
$manual_discount_pl = 0.0;
$manual_label_pl = '';
if ($md_pl && (float)($md_pl['amount'] ?? 0) > 0) {
    $manual_discount_pl = $md_pl['type'] === 'flat'
        ? min((float)$md_pl['amount'], max(0, $after_promos_pl))
        : max(0, $after_promos_pl) * ((float)$md_pl['amount'] / 100.0);
    $r_pl = trim($md_pl['reason'] ?? '');
    $manual_label_pl = $r_pl ?: 'Discount';
    if ($md_pl['type'] === 'percent') $manual_label_pl .= ' (' . (int)$md_pl['amount'] . '% off)';
    $after_promos_pl -= $manual_discount_pl;
}
$subtotal_after = $after_promos_pl;
$tax   = $subtotal_after * 0.10;
$total = round($subtotal_after + $tax, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $is_add_to_order ? "Add to Order #$add_to_daily_no" : "Pay Later" ?> | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Apply saved theme before first paint to avoid flash -->
<script>(function(){if(localStorage.getItem('theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}})();</script>
<style>
:root {
    --bg-body: #f2ede8;
    --bg-card: #fffdf9;
    --bg-card-hover: #fdf7f0;
    --border: #e0d5c8;
    --border-hover: #c9b89f;
    --orange: #d1904b;
    --orange-dark: #a0702a;
    --orange-light: #e8b87a;
    --purple: #7c3aed;
    --purple-light: #a78bfa;
    --text-primary: #1a1410;
    --text-secondary: #5a4a3a;
    --text-muted: #9a8070;
    --text-inverse: #ffffff;
    --danger: #e74c3c;
    --shadow-sm: 0 2px 10px rgba(90,60,20,0.07);
    --shadow-md: 0 6px 28px rgba(90,60,20,0.11);
    --shadow-lg: 0 12px 48px rgba(90,60,20,0.16);
    --shadow-purple: 0 8px 32px rgba(124,58,237,0.18);
    --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    --radius: 16px;
}
/* ── Dark theme overrides ── */
[data-theme="dark"] {
    --bg-body:       #0c0c0c;
    --bg-card:       #161616;
    --bg-card-hover: #1e1e1e;
    --border:        #252525;
    --border-hover:  #363636;
    --text-primary:  #f0f0f0;
    --text-secondary:#aaaaaa;
    --text-muted:    #666666;
    --shadow-sm:     0 2px 10px rgba(0,0,0,0.5);
    --shadow-md:     0 6px 28px rgba(0,0,0,0.55);
}
[data-theme="dark"] body { background-image: none; }
[data-theme="dark"] input,
[data-theme="dark"] select {
    background: #1a1a1a !important;
    color: var(--text-primary) !important;
    border-color: var(--border) !important;
    color-scheme: dark;
}
[data-theme="dark"] .top-bar,
[data-theme="dark"] .cart-items,
[data-theme="dark"] .order-summary,
[data-theme="dark"] .confirm-section {
    background: var(--bg-card);
    border-color: var(--border);
}
[data-theme="dark"] .cart-item:hover {
    background: var(--bg-card-hover);
}
[data-theme="dark"] .qty-control {
    background: #1a1a1a;
    border-color: var(--border);
}
[data-theme="dark"] .payment-split-inputs {
    background: #1a1a1a;
    border-color: var(--border);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg-body);
    color: var(--text-primary);
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;
    padding: 20px;
    background-image:
        radial-gradient(ellipse at 20% 10%, rgba(124,58,237,0.04) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 90%, rgba(209,144,75,0.04) 0%, transparent 60%);
}

/* ── Top Bar ── */
.top-bar {
    max-width: 760px; margin: 0 auto 24px;
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 12px;
    padding: 12px 16px;
    background: var(--bg-card); border-radius: var(--radius);
    border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.top-bar a {
    color: var(--orange); text-decoration: none; font-weight: 600;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 16px; border-radius: 8px;
    background: var(--bg-body); border: 1px solid transparent;
    transition: var(--transition);
}
.top-bar a:hover {
    background: var(--orange); color: #fff;
    border-color: var(--orange); transform: translateX(-4px);
    box-shadow: 0 4px 16px rgba(209,144,75,0.22);
}

/* ── Pay Later Banner ── */
.paylater-banner {
    max-width: 760px; margin: 0 auto 20px;
    background: linear-gradient(135deg, rgba(124,58,237,0.08) 0%, rgba(167,139,250,0.06) 100%);
    border: 1.5px solid rgba(124,58,237,0.25);
    border-radius: var(--radius); padding: 16px 20px;
    display: flex; align-items: center; gap: 14px;
}
.paylater-banner .icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg, var(--purple), var(--purple-light));
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px; flex-shrink: 0;
    box-shadow: var(--shadow-purple);
}
.paylater-banner .text h3 { font-size: 15px; font-weight: 700; color: var(--purple); margin-bottom: 2px; }
.paylater-banner .text p  { font-size: 12px; color: var(--text-secondary); }

/* Add-to-order banner */
.addto-banner {
    max-width: 760px; margin: 0 auto 20px;
    background: rgba(209,144,75,0.07);
    border: 1.5px solid rgba(209,144,75,0.3);
    border-radius: var(--radius); padding: 14px 20px;
    display: flex; align-items: center; gap: 12px;
    font-size: 14px; color: var(--text-secondary);
}
.addto-banner i { color: var(--orange); font-size: 18px; }
.addto-banner strong { color: var(--orange); }

/* ── Main Layout ── */
.page-container { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }

/* ── Cart Items ── */
.cart-items {
    background: var(--bg-card); border-radius: var(--radius);
    padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.cart-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border);
}
.cart-header h1 { font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.cart-header h1 i { color: var(--orange); }
.item-count { color: var(--text-muted); font-size: 13px; }

.cart-item {
    display: flex; gap: 16px; align-items: center;
    padding: 14px 0; border-bottom: 1px solid var(--border); transition: var(--transition);
}
.cart-item:last-child { border-bottom: none; }
.cart-item:hover { background: var(--bg-card-hover); padding: 14px 12px; border-radius: 12px; margin: 0 -12px; }
.cart-item img { width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border); flex-shrink: 0; }
.item-info { flex: 1; min-width: 0; }
.item-info h3 { font-size: 15px; font-weight: 600; margin: 0 0 2px; }
.item-info .item-meta { color: var(--text-secondary); font-size: 12px; }
.item-info .item-price { color: var(--orange); font-weight: 600; font-size: 14px; margin-top: 2px; }
.item-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.qty-control { display: flex; align-items: center; background: var(--bg-body); border: 1px solid var(--border); border-radius: 50px; overflow: hidden; }
.qty-control button { width: 28px; height: 28px; background: none; border: none; color: var(--orange); font-size: 16px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; }
.qty-control button:hover { background: rgba(209,144,75,0.15); }
.qty-control input[type="number"] { width: 36px; text-align: center; font-weight: 600; font-size: 13px; background: transparent; border: none; outline: none; font-family: 'Poppins', sans-serif; padding: 0; -moz-appearance: textfield; }
.qty-control input[type="number"]::-webkit-inner-spin-button,
.qty-control input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; }
.remove-btn { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: rgba(231,76,60,0.1); color: var(--danger); border-radius: 50%; text-decoration: none; font-size: 14px; transition: var(--transition); border: 1px solid transparent; }
.remove-btn:hover { background: var(--danger); color: #fff; transform: scale(1.1); }

/* ── Order Summary ── */
.order-summary {
    background: var(--bg-card); border-radius: var(--radius);
    padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.order-summary h2 { font-size: 18px; font-weight: 700; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.order-summary h2 i { color: var(--orange); }
.summary-row { display: flex; justify-content: space-between; padding: 8px 0; color: var(--text-secondary); font-size: 14px; }
.summary-row.discount { color: var(--danger); }
.summary-row.total { border-top: 1px solid var(--border); margin-top: 8px; padding-top: 12px; color: var(--text-primary); font-size: 20px; font-weight: 700; }
.summary-row.total span:last-child { color: var(--orange); }

/* ── Confirm Section ── */
.confirm-section {
    background: var(--bg-card); border-radius: var(--radius);
    padding: 24px; border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.confirm-section h2 { font-size: 16px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.confirm-section h2 i { color: var(--orange); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; color: var(--text-secondary); font-size: 13px; margin-bottom: 6px; font-weight: 500; }
.form-group input {
    width: 100%; padding: 12px 16px; border-radius: 10px;
    border: 1px solid var(--border); background: var(--bg-body);
    color: var(--text-primary); font-size: 14px; font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}
.form-group input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(124,58,237,0.08); }
.form-group small { display: block; margin-top: 4px; color: var(--text-muted); font-size: 12px; }

/* ── Confirm Button ── */
.btn-confirm {
    width: 100%; padding: 14px;
    background: linear-gradient(135deg, var(--purple), var(--purple-light));
    border: none; border-radius: 12px; color: #fff;
    font-weight: 700; font-size: 16px; cursor: pointer;
    transition: var(--transition); display: flex; align-items: center;
    justify-content: center; gap: 10px; font-family: 'Poppins', sans-serif;
    box-shadow: var(--shadow-purple);
}
.btn-confirm:hover { transform: translateY(-2px); box-shadow: 0 12px 40px rgba(124,58,237,0.28); filter: brightness(1.08); }
.btn-confirm:active { transform: translateY(0); }

/* ── Pay Later note ── */
.paylater-note {
    margin-top: 12px; padding: 12px 16px;
    background: rgba(124,58,237,0.06); border-radius: 10px;
    border: 1px solid rgba(124,58,237,0.15);
    font-size: 12px; color: var(--text-secondary);
    display: flex; align-items: flex-start; gap: 8px;
}
.paylater-note i { color: var(--purple); margin-top: 1px; flex-shrink: 0; }

/* ── Empty state ── */
.empty-cart { text-align: center; padding: 60px 20px; }
.empty-cart i { font-size: 48px; color: var(--text-muted); margin-bottom: 16px; }
.empty-cart h2 { font-size: 20px; margin-bottom: 8px; }
.empty-cart p { color: var(--text-secondary); margin-bottom: 20px; }
.btn-shop { display: inline-block; background: var(--orange); color: #fff; padding: 12px 28px; border-radius: 50px; text-decoration: none; font-weight: 600; transition: var(--transition); }
.btn-shop:hover { background: var(--orange-dark); transform: translateY(-3px); }

@media (max-width: 640px) {
    body { padding: 12px; }
    .cart-item img { width: 48px; height: 48px; }
    .item-actions { width: 100%; justify-content: flex-end; padding-top: 8px; }
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="top-bar">
    <a href="menu.php"><i class="fa-solid fa-arrow-left"></i> Continue Ordering</a>
    <span style="color: var(--text-secondary); font-size: 14px;">
        <i class="fa-solid fa-cart-shopping" style="color: var(--orange);"></i>
        <span id="cart-count"><?= $total_qty ?></span> item<?= $total_qty != 1 ? 's' : '' ?> in cart
    </span>
</div>

<?php if ($is_add_to_order): ?>
<!-- ADD TO ORDER BANNER -->
<div class="addto-banner">
    <i class="fa-solid fa-circle-plus"></i>
    <span>Adding items to <strong>Order #<?= $add_to_daily_no ?></strong> — these will be appended to the existing bill.</span>
</div>
<?php else: ?>
<!-- PAY LATER BANNER -->
<div class="paylater-banner">
    <div class="icon"><i class="fa-solid fa-clock"></i></div>
    <div class="text">
        <h3>Pay Later</h3>
        <p>Your order will be prepared now. Payment collected when you're ready.</p>
    </div>
</div>
<?php endif; ?>

<div class="page-container">

    <!-- CART ITEMS -->
    <div class="cart-items">
        <div class="cart-header">
            <h1><i class="fa-solid fa-cart-shopping"></i> Your Cart</h1>
            <span class="item-count" id="header-item-count"><?= $total_qty ?> item<?= $total_qty != 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($cart)): ?>
        <div class="empty-cart">
            <i class="fa-regular fa-face-smile"></i>
            <h2>Your cart is empty</h2>
            <p>Go back and add some items first.</p>
            <a href="menu.php" class="btn-shop"><i class="fa-solid fa-mug-hot"></i> Browse Menu</a>
        </div>
        <?php else: ?>
            <?php foreach ($cart as $i => $item):
                $qty = (int)($item['qty'] ?? 1);
                $lineTotal = (float)$item['price'] * $qty;
            ?>
            <div class="cart-item">
                <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>">
                <div class="item-info">
                    <h3><?= htmlspecialchars($item['product_name']) ?></h3>
                    <div class="item-meta">
                        <?php if (!empty($item['sweetness'])): ?>Sweet: <?= htmlspecialchars($item['sweetness']) ?><?php endif; ?>
                        <?php if (!empty($item['ice'])): ?> • Ice: <?= htmlspecialchars($item['ice']) ?><?php endif; ?>
                        <?php if (!empty($item['milk'])): ?> • Milk: <?= htmlspecialchars($item['milk']) ?><?php endif; ?>
                    </div>
                    <div class="item-price">$<span id="item-total-<?= $i ?>"><?= number_format($lineTotal, 2) ?></span></div>
                </div>
                <div class="item-actions">
                    <div class="qty-control">
                        <button type="button" onclick="changeQty(<?= $i ?>, -1)">−</button>
                        <input type="number" id="qty-<?= $i ?>" value="<?= $qty ?>" min="1"
                               onchange="updateQtyInput(<?= $i ?>, this.value)"
                               oninput="if(this.value<1)this.value=1">
                        <button type="button" onclick="changeQty(<?= $i ?>, 1)">+</button>
                    </div>
                    <a href="cart_paylater.php?remove=<?= $i ?>" class="remove-btn" title="Remove">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="cart-item" id="free-item-row"
                 style="<?= $buy3_discount > 0 ? 'border-top:1px dashed #27ae60;margin-top:4px;padding-top:14px;' : 'display:none' ?>">
                <div style="width:64px;height:64px;display:flex;align-items:center;justify-content:center;font-size:28px;background:linear-gradient(135deg,#e8f5e9,#f0fff4);border-radius:12px;flex-shrink:0;">🎁</div>
                <div class="item-info">
                    <h3><span id="free-item-name-text"><?= htmlspecialchars($cheapest_item_name_pl) ?></span> <span style="background:#27ae60;color:#fff;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;letter-spacing:0.5px;vertical-align:middle;">FREE</span></h3>
                    <div class="item-meta">Buy <?= BUY_X_COUNT ?> Get 1 Free</div>
                    <div class="item-price" id="free-item-price-display" style="color:#27ae60;">FREE <span style="text-decoration:line-through;color:#aaa;font-size:12px;font-weight:400;">was $<?= number_format($min_price < PHP_FLOAT_MAX ? $min_price : 0, 2) ?></span></div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <?php if (!empty($cart)): ?>

    <!-- ORDER SUMMARY -->
    <div class="order-summary">
        <h2><i class="fa-solid fa-receipt"></i> Order Summary</h2>
        <div class="summary-row">
            <span>Subtotal</span>
            <span id="summary-subtotal">$<?= number_format($subtotal, 2) ?></span>
        </div>
        <div class="summary-row discount" id="buy3-row" style="display:none">
            <span>🎉 Buy <?= BUY_X_COUNT ?> Get 1 Free</span>
            <span id="buy3-amount">-$<?= number_format($buy3_discount, 2) ?></span>
        </div>
        <div class="summary-row discount" id="hh-row" style="<?= $happy_hour_discount > 0 ? '' : 'display:none' ?>">
            <span>🌅 Happy Hour (<?= HAPPY_HOUR_DISCOUNT ?>% off)</span>
            <span id="hh-amount">-$<?= number_format($happy_hour_discount, 2) ?></span>
        </div>
        <div class="summary-row discount" id="manual-discount-row" style="<?= $manual_discount_pl > 0 ? '' : 'display:none' ?>">
            <span id="manual-discount-label">🏷️ <?= htmlspecialchars($manual_label_pl) ?></span>
            <span id="manual-discount-amount">-$<?= number_format($manual_discount_pl, 2) ?></span>
        </div>
        <div id="discount-panel" style="margin:6px 0 2px;">
            <?php if ($manual_discount_pl > 0): ?>
            <button type="button" class="discount-toggle-btn remove" onclick="clearDiscount()">
                <i class="fa-solid fa-xmark"></i> Remove Discount
            </button>
            <?php else: ?>
            <button type="button" class="discount-toggle-btn" id="addDiscountBtn" onclick="openDiscountForm()">
                <i class="fa-solid fa-tag"></i> Add Discount
            </button>
            <?php endif; ?>
            <div id="discount-form" style="display:none;margin-top:8px;padding:12px;background:rgba(209,144,75,0.05);border:1px solid rgba(209,144,75,0.18);border-radius:12px;">
                <div style="display:flex;gap:6px;margin-bottom:8px;">
                    <button type="button" id="dtype-percent" onclick="setDType('percent')" style="flex:1;padding:6px;border-radius:8px;border:1px solid #ccc;background:var(--orange-dark);color:#fff;font-family:Poppins,sans-serif;font-size:12px;font-weight:600;cursor:pointer;">% Percent</button>
                    <button type="button" id="dtype-flat"    onclick="setDType('flat')"    style="flex:1;padding:6px;border-radius:8px;border:1px solid #ccc;background:transparent;color:inherit;font-family:Poppins,sans-serif;font-size:12px;font-weight:600;cursor:pointer;">$ Flat</button>
                </div>
                <input type="number" id="discount-amount-input" placeholder="Amount" min="0" step="0.01"
                       style="width:100%;padding:8px 10px;border-radius:8px;border:1px solid #ccc;margin-bottom:6px;font-family:Poppins,sans-serif;font-size:13px;background:var(--bg-card,#fff);color:inherit;">
                <input type="text" id="discount-reason-input" placeholder="Reason (e.g. Staff, VIP)" maxlength="100"
                       style="width:100%;padding:8px 10px;border-radius:8px;border:1px solid #ccc;margin-bottom:8px;font-family:Poppins,sans-serif;font-size:13px;background:var(--bg-card,#fff);color:inherit;">
                <div style="display:flex;gap:8px;">
                    <button type="button" onclick="applyDiscount()" style="flex:1;padding:8px;background:var(--orange-dark,#a0702a);color:#fff;border:none;border-radius:8px;font-family:Poppins,sans-serif;font-size:13px;font-weight:700;cursor:pointer;">Apply</button>
                    <button type="button" onclick="closeDiscountForm()" style="padding:8px 14px;background:transparent;border:1px solid #aaa;border-radius:8px;font-family:Poppins,sans-serif;font-size:12px;cursor:pointer;">Cancel</button>
                </div>
            </div>
        </div>
        <div class="summary-row">
            <span>Tax (10%)</span>
            <span id="summary-tax">$<?= number_format($tax, 2) ?></span>
        </div>
        <div class="summary-row total">
            <span>Total</span>
            <span id="summary-total">$<?= number_format($total, 2) ?></span>
        </div>
    </div>

    <!-- CONFIRM SECTION -->
    <div class="confirm-section">
        <h2><i class="fa-regular fa-user"></i> Customer Details</h2>
        <form method="post" action="confirm_order.php" id="checkoutForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="is_add_to_order" value="<?= $is_add_to_order ? '1' : '0' ?>">
            <input type="hidden" name="payment_methods[]" value="paylater">
            <input type="hidden" name="payment_amounts[]" id="paymentAmount" value="<?= number_format($total, 2, '.', '') ?>">
            <input type="hidden" name="payment_references[]" value="">

            <div class="form-group">
                <label for="customer_name"><i class="fa-regular fa-user"></i> Customer Name</label>
                <input type="text" name="customer_name" id="customer_name"
                       placeholder="Leave blank for Guest"
                       value="<?= htmlspecialchars($_SESSION['customer_name'] ?? '') ?>">
                <small>(Optional — defaults to "Guest")</small>
            </div>

            <button type="submit" class="btn-confirm">
                <i class="fa-solid fa-clock"></i>
                <?= $is_add_to_order ? "Add to Order #$add_to_daily_no" : 'Place Pay Later Order' ?>
            </button>

            <div class="paylater-note">
                <i class="fa-solid fa-circle-info"></i>
                <span><?= $is_add_to_order
                    ? "Items will be added to the existing order. Payment is collected when the full order is settled."
                    : "Your order will be prepared immediately. Staff will collect payment later — at the counter or table." ?>
                </span>
            </div>
        </form>
    </div>

    <?php endif; ?>
</div>

<script>
function changeQty(index, delta) {
    const input = document.getElementById('qty-' + index);
    let qty = parseInt(input.value || '1', 10) + delta;
    if (qty < 1) qty = 1;
    input.value = qty;
    updateQtyAjax(index, qty);
}
function updateQtyInput(index, value) {
    let qty = parseInt(value || '1', 10);
    if (isNaN(qty) || qty < 1) qty = 1;
    document.getElementById('qty-' + index).value = qty;
    updateQtyAjax(index, qty);
}
function updateQtyAjax(index, qty) {
    fetch('cart_paylater.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax_update=1&index=${index}&qty=${qty}`
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('item-total-' + index);
        if (el) el.textContent = data.itemLineTotal;
        document.getElementById('summary-subtotal').textContent = '$' + data.cartSubtotal;
        document.getElementById('summary-tax').textContent      = '$' + data.tax;
        document.getElementById('summary-total').textContent    = '$' + data.cartTotal;
        // Update hidden payment amount
        document.getElementById('paymentAmount').value = data.cartTotal;
        // Buy 3 row hidden — free drink shown as FREE row in cart items instead
        const b3r = document.getElementById('buy3-row');
        if (b3r) b3r.style.display = 'none';
        const freeRowPl = document.getElementById('free-item-row');
        if (freeRowPl) {
            const b3v = parseFloat(data.buy3_discount || 0);
            if (b3v > 0) {
                freeRowPl.style.display = 'flex';
                freeRowPl.style.borderTop = '1px dashed #27ae60';
                freeRowPl.style.marginTop = '4px';
                freeRowPl.style.paddingTop = '14px';
                const nameEl = document.getElementById('free-item-name-text');
                if (nameEl && data.free_item_name) nameEl.textContent = data.free_item_name;
                const priceEl = document.getElementById('free-item-price-display');
                if (priceEl && data.free_item_price) priceEl.innerHTML = 'FREE <span style="text-decoration:line-through;color:#aaa;font-size:12px;font-weight:400;">was $' + data.free_item_price + '</span>';
            } else {
                freeRowPl.style.display = 'none';
            }
        }
        // Happy Hour row
        const hhr = document.getElementById('hh-row');
        const hha = document.getElementById('hh-amount');
        if (hhr && hha) {
            if (parseFloat(data.happy_hour_discount) > 0) {
                hha.textContent   = '-$' + data.happy_hour_discount;
                hhr.style.display = 'flex';
            } else {
                hhr.style.display = 'none';
            }
        }
        // Cart count badge
        if (data.cartCount !== undefined) {
            document.getElementById('cart-count').textContent = data.cartCount;
            const hc = document.getElementById('header-item-count');
            if (hc) hc.textContent = data.cartCount + ' item' + (data.cartCount != 1 ? 's' : '');
        }
        // Manual discount row
        const mdr = document.getElementById('manual-discount-row');
        const mda = document.getElementById('manual-discount-amount');
        const mdl = document.getElementById('manual-discount-label');
        if (mdr && mda) {
            const v = parseFloat(data.manual_discount || '0');
            mda.textContent = '-$' + (data.manual_discount || '0.00');
            if (mdl && data.manual_discount_label) mdl.textContent = '🏷️ ' + data.manual_discount_label;
            mdr.style.display = v > 0 ? 'flex' : 'none';
        }
    });
}

let _discountType = 'percent';
function openDiscountForm() {
    const btn = document.getElementById('addDiscountBtn');
    if (btn) btn.style.display = 'none';
    document.getElementById('discount-form').style.display = 'block';
}
function closeDiscountForm() {
    document.getElementById('discount-form').style.display = 'none';
    const btn = document.getElementById('addDiscountBtn');
    if (btn) btn.style.display = '';
}
function setDType(type) {
    _discountType = type;
    const pp = document.getElementById('dtype-percent');
    const ff = document.getElementById('dtype-flat');
    if (pp && ff) {
        pp.style.background = type === 'percent' ? 'var(--orange-dark,#a0702a)' : 'transparent';
        pp.style.color      = type === 'percent' ? '#fff' : 'inherit';
        ff.style.background = type === 'flat'    ? 'var(--orange-dark,#a0702a)' : 'transparent';
        ff.style.color      = type === 'flat'    ? '#fff' : 'inherit';
    }
}
function applyDiscount() {
    const amount = parseFloat(document.getElementById('discount-amount-input').value) || 0;
    const reason = document.getElementById('discount-reason-input').value.trim();
    if (amount <= 0) { alert('Please enter a discount amount.'); return; }
    fetch('Cart_paylater.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_apply_discount=1&type=' + encodeURIComponent(_discountType) + '&amount=' + amount + '&reason=' + encodeURIComponent(reason)
    }).then(() => location.reload());
}
function clearDiscount() {
    fetch('Cart_paylater.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_clear_discount=1'
    }).then(() => location.reload());
}
</script>
</body>
</html>