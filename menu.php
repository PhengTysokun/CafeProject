<?php
require 'auth.php';
require_once 'config.php';
if (!can('find_orders')) { header('Location: dashboard.php?denied=1'); exit; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cat_anchor_id($key) {
    $slug = strtolower(trim((string)$key));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return 'cat-' . ($slug !== '' ? $slug : 'uncategorized');
}

/* ── NAV: view_order.php (Kitchen) is for barista only; admin/manager go to dashboard ── */
$_show_kitchen_btn = ($_SESSION['role'] ?? '') === 'barista';

/* ── CART CALCULATIONS ── */
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
$cp_subtotal = 0.0; $cp_min_price = PHP_FLOAT_MAX; $cp_cheapest_idx = -1;
$_cp_fpid = defined('FREE_ITEM_PRODUCT_ID') ? (int)FREE_ITEM_PRODUCT_ID : 0;
$_cp_fname = ''; $_cp_fprice = 0.0; $_cp_fidx = -1;

foreach ($cart as $idx => $item) {
    $q = (int)($item['qty'] ?? 1); $p = (float)($item['price'] ?? 0);
    $cart_count += $q; $cp_subtotal += $p * $q;
    if ($p < $cp_min_price) { $cp_min_price = $p; $cp_cheapest_idx = $idx; }
    if ($_cp_fpid > 0 && (int)($item['product_id'] ?? 0) === $_cp_fpid && $_cp_fidx < 0) {
        $_cp_fidx = $idx; $_cp_fname = $item['product_name'] ?? ''; $_cp_fprice = $p;
    }
}
if ($_cp_fpid > 0 && $_cp_fname === '') {
    $_fp_s = $conn->prepare("SELECT name, price FROM products WHERE product_id = ?");
    if ($_fp_s) { $_fp_s->bind_param("i", $_cp_fpid); $_fp_s->execute();
        if ($_fp_r = $_fp_s->get_result()->fetch_assoc()) { $_cp_fname = $_fp_r['name']; $_cp_fprice = (float)$_fp_r['price']; }
        $_fp_s->close(); }
}
$cp_cheapest_name  = ($cp_cheapest_idx >= 0) ? ($cart[$cp_cheapest_idx]['product_name'] ?? '') : '';
$cp_cheapest_price = ($cp_cheapest_idx >= 0 && $cp_min_price < PHP_FLOAT_MAX) ? $cp_min_price : 0.0;
$cp_free_name  = ($_cp_fpid > 0 && $_cp_fname !== '') ? $_cp_fname : $cp_cheapest_name;
$cp_free_price = ($_cp_fpid > 0 && $_cp_fprice > 0) ? $_cp_fprice : $cp_cheapest_price;
// BUY X GET 1 FREE — DISPLAY ONLY. Customer pays FULL price for all ordered drinks.
// The free drink is an *extra* gift on top — it does NOT reduce the total.
// $cp_buy3 is the *value* of the free drink shown in the summary row (informational).
// DO NOT subtract $cp_buy3 from $cp_after or the total — that would incorrectly undercharge.
$_cp_free_idx = ($_cp_fpid > 0 && $_cp_fidx >= 0) ? $_cp_fidx : $cp_cheapest_idx;
$cp_buy3 = (BUY_X_GET_1_ENABLED && $cart_count >= BUY_X_COUNT && $cp_min_price < PHP_FLOAT_MAX && $_cp_free_idx >= 0)
    ? floor($cart_count / BUY_X_COUNT) * $cp_free_price : 0.0;

$cp_hh = 0.0;
if (HAPPY_HOUR_ENABLED && (int)date('H') >= HAPPY_HOUR_START && (int)date('H') < HAPPY_HOUR_END)
    $cp_hh = $cp_subtotal * (HAPPY_HOUR_DISCOUNT / 100);

$cp_after = $cp_subtotal - $cp_hh;
$cp_md = $_SESSION['manual_discount'] ?? null;
$cp_manual = 0.0; $cp_manual_label = '';
if ($cp_md && (float)($cp_md['amount'] ?? 0) > 0) {
    $cp_manual = $cp_md['type'] === 'flat'
        ? min((float)$cp_md['amount'], max(0, $cp_after))
        : max(0, $cp_after) * ((float)$cp_md['amount'] / 100.0);
    $r = trim($cp_md['reason'] ?? ''); $cp_manual_label = $r ?: 'Discount';
    if ($cp_md['type'] === 'percent') $cp_manual_label .= ' (' . (int)$cp_md['amount'] . '% off)';
    $cp_after -= $cp_manual;
}
$cp_tax   = $cp_after * (TAX_RATE / 100);
$cp_total = round($cp_after + $cp_tax, 2);

/* ── LOYALTY ── */
$linked_loyalty = null;
$linked_loyalty_id_int = isset($_SESSION['loyalty_card_id']) ? (int)$_SESSION['loyalty_card_id'] : 0;
if ($linked_loyalty_id_int > 0) {
    $lc = $conn->prepare("SELECT loyalty_id, points FROM loyalty_cards WHERE card_id = ?");
    if ($lc) { $lc->bind_param("i", $linked_loyalty_id_int); $lc->execute(); $linked_loyalty = $lc->get_result()->fetch_assoc(); }
}

/* ── ADD TO EXISTING ORDER DETECTION ── */
$add_to_order_mode = isset($_GET['add_to_order']) ? (int)$_GET['add_to_order'] : 0;

/* ── ACTIVE ORDERS COUNT ── */
date_default_timezone_set('Asia/Phnom_Penh');
$now_dt    = new DateTime();
$today6am  = (clone $now_dt)->setTime(6, 0, 0);
if ($now_dt < $today6am) $today6am->modify('-1 day');
$day_start = $today6am->format('Y-m-d H:i:s');
$day_end   = (clone $today6am)->modify('+1 day -1 second')->format('Y-m-d H:i:s');
$stmt_ao   = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Preparing','PendingPayment') AND order_date >= ? AND order_date <= ?");
$stmt_ao->bind_param('ss', $day_start, $day_end);
$stmt_ao->execute();
$active_orders = (int)$stmt_ao->get_result()->fetch_row()[0];

/* ── BEST SELLER ── */
$bestSellerName = null;
$bs = mysqli_query($conn, "SELECT product_name FROM order_items GROUP BY product_name ORDER BY SUM(quantity) DESC LIMIT 1");
if ($bs && $r = mysqli_fetch_assoc($bs)) $bestSellerName = $r['product_name'];

/* ── TOP SELLERS ── */
$top_sellers = [];
$ts_result = mysqli_query($conn, "SELECT p.*, COALESCE(SUM(oi.quantity),0) AS total_sold FROM products p LEFT JOIN order_items oi ON p.product_id = oi.product_id WHERE p.is_available = 1 GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 6");
while ($ts_row = mysqli_fetch_assoc($ts_result)) {
    if ((int)$ts_row['total_sold'] > 0) $top_sellers[] = $ts_row;
}

/* ── FETCH ALL PRODUCTS ── */
$search_term   = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort          = $_GET['sort'] ?? 'default';
$is_price_sort = ($sort === 'price_low' || $sort === 'price_high');

$query = "SELECT p.*, (SELECT COUNT(*) FROM product_ingredients pi JOIN ingredients i ON pi.ingredient_id = i.ingredient_id WHERE pi.product_id = p.product_id AND i.stock_quantity < pi.amount_used) AS low_count FROM products p WHERE p.is_available = 1";
$query .= " ORDER BY " . ($sort === 'price_low' ? "p.price ASC" : ($sort === 'price_high' ? "p.price DESC" : "p.category, p.name"));
if (!empty($search_term)) {
    $query = "SELECT p.*, (SELECT COUNT(*) FROM product_ingredients pi JOIN ingredients i ON pi.ingredient_id = i.ingredient_id WHERE pi.product_id = p.product_id AND i.stock_quantity < pi.amount_used) AS low_count FROM products p WHERE p.is_available = 1 AND p.name LIKE ?";
    $query .= " ORDER BY " . ($sort === 'price_low' ? "p.price ASC" : ($sort === 'price_high' ? "p.price DESC" : "p.category, p.name"));
    $stmt_search = $conn->prepare($query);
    $like_param  = '%' . $search_term . '%';
    $stmt_search->bind_param("s", $like_param);
    $stmt_search->execute();
    $result = $stmt_search->get_result();
} else {
    $result = mysqli_query($conn, $query);
}

$categories = []; $catIcons = [];
$_cat_res = $conn->query("SELECT slug, name, icon FROM categories WHERE is_active = 1 ORDER BY display_order");
while ($_cat_row = $_cat_res->fetch_assoc()) {
    $categories[$_cat_row['slug']] = $_cat_row['name'];
    $catIcons[$_cat_row['slug']]   = $_cat_row['icon'];
}

$products = []; $flat_products = [];

while ($row = mysqli_fetch_assoc($result)) {
    $products[$row['category']][] = $row;
    $flat_products[] = $row;
}

/* ── SIZES PER PRODUCT (for sized products: has_sizes=1) ── */
$sizesByProduct = [];
if (!empty($flat_products)) {
    $sz_res = $conn->query("SELECT product_id, size_code, label, price FROM product_sizes ORDER BY product_id, sort_order ASC");
    while ($sz_res && $sz_row = $sz_res->fetch_assoc()) {
        $sizesByProduct[(int)$sz_row['product_id']][] = [
            'code'  => $sz_row['size_code'],
            'label' => $sz_row['label'],
            'price' => (float)$sz_row['price'],
        ];
    }
}

/* ── ADD-ONS PER PRODUCT (active only) ── */
$addonsByProduct = [];
$ad_res = $conn->query("
    SELECT pa.product_id, a.id, a.name, a.price
    FROM product_addons pa
    JOIN addons a ON a.id = pa.addon_id
    WHERE a.is_active = 1
    ORDER BY pa.product_id, a.display_order ASC, a.id ASC
");
if ($ad_res) {
    while ($ad_row = $ad_res->fetch_assoc()) {
        $addonsByProduct[(int)$ad_row['product_id']][] = [
            'id'    => (int)$ad_row['id'],
            'name'  => $ad_row['name'],
            'price' => (float)$ad_row['price'],
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- Apply theme before paint (default dark, matching the rest of the app) to avoid a flash -->
  <script>(function(){if((localStorage.getItem('theme')||'dark')!=='light')document.documentElement.setAttribute('data-theme','dark');})();</script>
  <title>POS | Bird's Nest Coffee</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/menu.css?v=<?= filemtime(__DIR__.'/assets/css/menu.css') ?>">
  <style>
    /* ── BASE ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; overflow: hidden; }
    body {
      background: var(--bg, #f4efe9);
      color: var(--text, #1a1410);
      font-family: 'Poppins', sans-serif;
      display: flex;
      flex-direction: column;
      padding: 0;
      min-height: 0;
    }

    /* ── HEADER ── */
    .menu-header {
      position: relative; z-index: 1000;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      padding: 12px 20px;
      background: var(--bg-header, rgba(255,252,248,.97));
      backdrop-filter: blur(16px);
      border-bottom: 1px solid var(--border, #e0d4c4);
      box-shadow: 0 2px 10px rgba(90,60,20,.07);
      flex-shrink: 0;
    }
    .header-left  { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .header-center{ flex: 1; max-width: 480px; display: flex; align-items: center; gap: 8px; }
    .header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
    .brand { display: flex; align-items: center; gap: 8px; }
    .brand img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border,#e0d4c4); flex-shrink: 0; }
    .brand-name { font-size: 15px; font-weight: 700; white-space: nowrap; color: var(--text,#1a1410); }
    .search-form  { display: flex; align-items: center; gap: 8px; width: 100%; }
    .search-inner { display: flex; align-items: center; gap: 8px; flex: 1; border-radius: 50px; padding: 7px 14px; background: var(--bg-input,#ede8e0); border: 1px solid var(--border,#e0d4c4); }
    .search-inner input { flex: 1; border: none; outline: none; background: transparent; font-family: 'Poppins',sans-serif; font-size: 13px; color: var(--text,#1a1410); }
    .sort-select { border-radius: 50px; padding: 7px 12px; border: 1px solid var(--border,#e0d4c4); background: var(--bg-input,#ede8e0); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; cursor: pointer; flex-shrink: 0; }
    .btn-nav { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 50px; border: 1px solid var(--border,#e0d4c4); background: var(--bg-input,#ede8e0); text-decoration: none; color: var(--text-sec,#5a4a3a); font-size: 13px; font-weight: 500; white-space: nowrap; transition: all .25s; }
    .btn-nav:hover { background: #d1904b; color: #fff; border-color: #d1904b; }
    .btn-theme { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--border,#e0d4c4); background: var(--bg-input,#ede8e0); color: var(--text,#1a1410); cursor: pointer; flex-shrink: 0; }
    .badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: #d1904b; color: #fff; font-size: 10px; font-weight: 700; }

    /* ── POS SPLIT LAYOUT ── */
    .pos-layout {
      flex: 1;
      display: flex;
      overflow: hidden;
      min-height: 0;
    }

    /* ── MENU PANEL (left, scrollable) ── */
    .menu-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      min-width: 0;
    }
    .menu-panel .cat-nav {
      display: flex; align-items: center; gap: 8px;
      padding: 8px 20px 10px;
      background: var(--bg-header, rgba(255,252,248,.97));
      border-bottom: 1px solid var(--border,#e0d4c4);
      overflow-x: auto;
      flex-shrink: 0;
      position: sticky; top: 0; z-index: 50;
    }
    .menu-panel .menu-scroll {
      flex: 1;
      overflow-y: auto;
      padding-bottom: 40px;
    }
    /* ── Custom thin amber scrollbars — replaces default OS scrollbar ── */
    .menu-scroll { scrollbar-width: thin; scrollbar-color: rgba(209,144,75,.35) transparent; }
    .menu-scroll::-webkit-scrollbar { width: 3px; }
    .menu-scroll::-webkit-scrollbar-track { background: transparent; }
    .menu-scroll::-webkit-scrollbar-thumb { background: rgba(209,144,75,.35); border-radius: 99px; }
    .menu-scroll::-webkit-scrollbar-thumb:hover { background: rgba(209,144,75,.65); }
    #cpItems::-webkit-scrollbar { display: none; }

    .menu-main { padding: 0 20px; }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 16px; }

    /* ── ADD TO ORDER BANNER ── */
    .add-order-banner {
      background: #9b59b6; color: #fff;
      padding: 10px 20px; font-size: 13px; font-weight: 600;
      display: flex; align-items: center; gap: 8px;
      flex-shrink: 0;
    }

    /* ── CART PANEL (right, fixed width) ── */
    .cart-panel {
      width: 420px;
      min-width: 340px;
      flex-shrink: 0;
      height: 100%;
      border-left: 1px solid var(--border,#e0d4c4);
      background: var(--bg-card, #fff);
      display: grid;
      grid-template-rows: auto 1fr auto;
      overflow: hidden;
      min-height: 0;
    }

    /* Cart header */
    .cp-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 15px 16px; border-bottom: 1px solid var(--border,#e0d4c4);
      flex-shrink: 0;
    }
    .cp-title { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 700; color: var(--text,#1a1410); letter-spacing: -.01em; }
    .cp-title i { color: #d1904b; }
    .cp-count { background: #d1904b; color: #fff; border-radius: 50px; padding: 3px 10px; font-size: 11px; font-weight: 700; letter-spacing: .02em; }
    .cp-clear-btn {
      background: transparent; border: 1px solid rgba(231,76,60,.5); color: #e74c3c;
      border-radius: 8px; padding: 5px 11px; font-size: 11px; font-weight: 600;
      cursor: pointer; font-family: 'Poppins',sans-serif; transition: all .2s;
      display: flex; align-items: center; gap: 5px;
    }
    .cp-clear-btn:hover { background: #e74c3c; color: #fff; border-color: #e74c3c; }

    /* Cart body: items scroll, summary stays pinned above the footer */
    .cp-body { display: flex; flex-direction: column; overflow: hidden; min-height: 0; }
    #cpItems { flex: 0 1 auto; overflow-x: hidden; overflow-y: auto; min-height: 0; scrollbar-width: none; }
    .cp-empty { flex: 1; }

    /* Empty state */
    .cp-empty {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      height: 100%; min-height: 180px; padding: 30px 20px; text-align: center;
      color: var(--text-muted,#9a8070);
    }
    .cp-empty i { font-size: 38px; margin-bottom: 10px; opacity: .5; }
    .cp-empty p { font-size: 14px; font-weight: 500; color: var(--text-sec,#5a4a3a); }
    .cp-empty small { font-size: 11px; }

    /* Cart item row */
    .cp-item {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 16px; border-bottom: 1px solid var(--border,#e0d4c4);
      transition: background .15s;
    }
    .cp-item:hover { background: var(--bg-card-hover,#fdf8f2); }
    .cp-item img { width: 54px; height: 54px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border,#e0d4c4); flex-shrink: 0; }
    .cp-item-info { flex: 1; min-width: 0; }
    .cp-item-name { font-size: 13px; font-weight: 600; color: var(--text,#1a1410); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cp-item-meta { font-size: 11px; color: var(--text-sec,#5a4a3a); margin-top: 2px; }
    .cp-item-price { font-size: 13px; font-weight: 700; color: #d1904b; margin-top: 3px; }
    .cp-item-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .cp-qty {
      display: flex; align-items: center;
      border: 1.5px solid var(--border,#e0d4c4); border-radius: 50px;
      background: var(--bg,#f4efe9); overflow: hidden;
    }
    .cp-qty button {
      width: 28px; height: 28px; background: none; border: none;
      color: #d1904b; font-size: 14px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background .15s;
    }
    .cp-qty button:hover { background: rgba(209,144,75,.18); }
    .cp-qty input[type="number"] {
      width: 36px; text-align: center; font-size: 13px; font-weight: 700;
      color: var(--text,#1a1410); background: transparent; border: none; outline: none;
      font-family: 'Poppins',sans-serif; padding: 0; cursor: text;
      -moz-appearance: textfield;
    }
    .cp-qty input[type="number"]::-webkit-inner-spin-button,
    .cp-qty input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    .cp-remove {
      width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
      border-radius: 50%; background: rgba(231,76,60,.08); color: #e74c3c;
      border: 1px solid rgba(231,76,60,.18); cursor: pointer; font-size: 11px; transition: all .18s;
    }
    .cp-remove:hover { background: #e74c3c; color: #fff; border-color: #e74c3c; }
    .cp-free-icon { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 22px; background: linear-gradient(135deg,#e8f5e9,#f0fff4); border-radius: 7px; flex-shrink: 0; }
    .cp-free-badge { background: #27ae60; color: #fff; font-size: 9px; padding: 1px 5px; border-radius: 20px; font-weight: 700; vertical-align: middle; }

    /* Summary area: pinned below the scrolling items, above the footer */
    .cp-summary { flex-shrink: 0; padding: 12px 16px; border-top: 1px solid var(--border,#e0d4c4); }
    .cp-sum-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 12.5px; color: var(--text-sec,#5a4a3a); }
    .cp-sum-row.discount { color: #e74c3c; }

    /* Discount toggle */
    .cp-discount-toggle {
      width: 100%; padding: 6px 10px; border-radius: 7px;
      border: 1px dashed var(--border-hover,#c9b89f);
      background: transparent; color: #d1904b;
      font-family: 'Poppins',sans-serif; font-size: 11px; font-weight: 600;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px;
      transition: all .2s; margin: 5px 0;
    }
    .cp-discount-toggle:hover { background: rgba(209,144,75,.07); border-color: #d1904b; }
    .cp-discount-toggle.remove { color: #e74c3c; border-color: rgba(231,76,60,.35); }
    .cp-discount-toggle.remove:hover { background: rgba(231,76,60,.07); border-color: #e74c3c; }
    #cpDiscountForm { background: rgba(209,144,75,.04); border: 1px solid rgba(209,144,75,.18); border-radius: 9px; padding: 10px; margin: 4px 0; }
    .cp-dtype-row { display: flex; gap: 5px; margin-bottom: 7px; }
    .cp-dtype-btn { flex: 1; padding: 4px 0; border-radius: 6px; border: 1px solid var(--border-hover,#c9b89f); background: transparent; color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; font-size: 11px; font-weight: 600; cursor: pointer; transition: all .2s; }
    .cp-dtype-btn.active { background: #d1904b; color: #000; border-color: #d1904b; }
    .cp-disc-inputs { display: flex; flex-direction: column; gap: 5px; margin-bottom: 7px; }
    .cp-disc-inputs input { width: 100%; padding: 6px 9px; border-radius: 7px; border: 1px solid var(--border-hover,#c9b89f); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; }
    .cp-disc-inputs input:focus { border-color: #d1904b; }
    .cp-disc-actions { display: flex; gap: 5px; }
    .cp-btn-apply { flex: 1; padding: 6px 0; background: #d1904b; color: #000; border: none; border-radius: 7px; font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: opacity .2s; }
    .cp-btn-apply:hover { opacity: .88; }
    .cp-btn-cancel { padding: 6px 9px; background: transparent; color: var(--text-muted,#9a8070); border: 1px solid var(--border-hover,#c9b89f); border-radius: 7px; font-family: 'Poppins',sans-serif; font-size: 11px; cursor: pointer; }

    /* Sections inside summary */
    .cp-section { padding-top: 8px; margin-top: 6px; border-top: 1px solid var(--border,#e0d4c4); }
    .cp-section-label { font-size: 10px; font-weight: 700; color: var(--text-sec,#5a4a3a); display: flex; align-items: center; gap: 5px; margin-bottom: 7px; letter-spacing: .07em; text-transform: uppercase; }
    .cp-section-label i { color: #d1904b; }

    /* Payment methods — tactile tiles */
    .cp-pm-methods-label { font-size: 11px; font-weight: 600; color: var(--text-muted,#9a8070); margin-bottom: 8px; }
    .cp-pay-methods { display: flex; gap: 8px; }
    .cp-pay-method {
      flex: 1; position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px;
      padding: 13px 4px 11px; border: 1.5px solid var(--border,#e0d4c4); border-radius: 14px;
      cursor: pointer; background: var(--bg,#f4efe9); color: var(--text-sec,#5a4a3a);
      transition: transform .12s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
      user-select: none;
    }
    /* per-method accent colors */
    .cp-pay-method[data-method="bakong"]   { --m:#e0454a; --mbg:rgba(224,69,74,.12);  --mring:rgba(224,69,74,.22); }
    .cp-pay-method[data-method="cash"]     { --m:#27ae60; --mbg:rgba(39,174,96,.12);  --mring:rgba(39,174,96,.22); }
    .cp-pay-method[data-method="paylater"] { --m:#e8973a; --mbg:rgba(232,151,58,.12); --mring:rgba(232,151,58,.22); }
    .cp-pay-method[data-method="riel"]     { --m:#2d8fd5; --mbg:rgba(45,143,213,.12); --mring:rgba(45,143,213,.22); }
    .cp-pay-method .cp-pm-ico { font-size: 19px; color: var(--m,#9a8070); transition: color .15s ease; }
    .cp-pay-method .cp-pm-lbl { font-size: 11.5px; font-weight: 600; }
    .cp-pay-method .cp-pm-check { position: absolute; top: 6px; right: 7px; font-size: 13px; color: var(--m,#d1904b); opacity: 0; transform: scale(.5); transition: opacity .15s ease, transform .15s ease; }
    .cp-pay-method:hover { border-color: var(--m,#d1904b); }
    .cp-pay-method:active { transform: scale(.96); }
    .cp-pay-method.selected { border-color: var(--m,#d1904b); background: var(--mbg,rgba(209,144,75,.12)); color: var(--text,#1a1410); box-shadow: 0 0 0 3px var(--mring,rgba(209,144,75,.16)); }
    .cp-pay-method.selected .cp-pm-check { opacity: 1; transform: scale(1); }
    .cp-pay-method input { display: none; }

    /* Split payment inputs */
    .cp-split-inputs { display: none; background: var(--bg,#f4efe9); border-radius: 8px; padding: 8px; border: 1px solid var(--border,#e0d4c4); margin-top: 5px; }
    .cp-split-inputs.active { display: block; }
    .cp-split-row { display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
    .cp-split-row:last-child { margin-bottom: 0; }
    .cp-split-row label { font-size: 11px; color: var(--text-sec,#5a4a3a); min-width: 52px; }
    .cp-split-row input { flex: 1; padding: 5px 7px; border-radius: 5px; border: 1px solid var(--border,#e0d4c4); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-size: 12px; font-family: 'Poppins',sans-serif; outline: none; }
    .cp-split-row input:focus { border-color: #d1904b; }

    /* Change calculator */
    .cp-change-calc { display: none; margin-top: 6px; padding: 9px; background: rgba(85,224,135,.05); border-radius: 9px; border: 1px solid rgba(85,224,135,.2); }
    .cp-change-calc.visible { display: block; }
    .cp-change-calc label { font-size: 11px; color: var(--text-sec,#5a4a3a); font-weight: 600; display: block; margin-bottom: 3px; }
    .cp-change-calc input { width: 100%; padding: 6px 9px; border-radius: 6px; border: 1px solid rgba(85,224,135,.35); background: var(--bg-card,#fff); color: var(--text,#1a1410); font-size: 14px; font-weight: 700; font-family: 'Poppins',sans-serif; outline: none; text-align: right; }
    .cp-change-calc input:focus { border-color: #55e087; }
    .cp-change-row { display: flex; justify-content: space-between; align-items: center; margin-top: 7px; padding-top: 5px; border-top: 1px solid rgba(85,224,135,.15); }
    .cp-change-row .change-label { font-size: 11px; font-weight: 600; color: var(--text-sec,#5a4a3a); }
    .cp-change-row .change-amount { font-size: 17px; font-weight: 800; color: #55e087; }
    .cp-change-row .change-amount.not-enough { color: #e74c3c; font-size: 12px; }

    /* Drink type toggle */
    .cp-drink-type { display: flex; gap: 6px; margin-top: 4px; }
    .cp-drink-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 5px; padding: 9px; border-radius: 10px; border: 1.5px solid var(--border,#e0d4c4); background: var(--bg,#f4efe9); color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; font-size: 12px; font-weight: 500; cursor: pointer; transition: all .2s; }
    .cp-drink-btn.active { border-color: #d1904b; background: rgba(209,144,75,.12); color: #d1904b; font-weight: 600; box-shadow: 0 0 0 2px rgba(209,144,75,.15); }

    /* Customer input */
    .cp-form-group { margin-top: 7px; }
    .cp-form-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-sec,#5a4a3a); margin-bottom: 3px; }
    .cp-form-group input,
    .cp-form-group select { width: 100%; padding: 7px 10px; border-radius: 7px; border: 1px solid var(--border,#e0d4c4); background: var(--bg,#f4efe9); color: var(--text,#1a1410); font-family: 'Poppins',sans-serif; font-size: 12px; outline: none; transition: border-color .2s; }
    .cp-form-group input:focus,
    .cp-form-group select:focus { border-color: #d1904b; }

    /* Loyalty */
    .cp-loyalty { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; }
    .cp-loyalty-info { font-size: 10px; color: var(--text-sec,#5a4a3a); }
    .cp-loyalty-info .linked { color: #d1904b; font-weight: 600; }
    .cp-loyalty-btn { background: #d1904b; color: #fff; border: none; border-radius: 50px; padding: 4px 10px; font-size: 10px; font-weight: 600; cursor: pointer; font-family: 'Poppins',sans-serif; transition: all .2s; }
    .cp-loyalty-btn:hover { background: #a0702a; }

    /* Cart footer */
    .cp-footer {
      border-top: 1px solid var(--border,#e0d4c4);
      padding: 14px 16px 16px;
      background: var(--bg-card,#fff);
      flex-shrink: 0;
    }
    .cp-total-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
    .cp-total-row .lbl { font-size: 13px; font-weight: 600; color: var(--text-sec,#5a4a3a); letter-spacing: .04em; text-transform: uppercase; }
    .cp-total-row .amt { font-size: 30px; font-weight: 900; color: #d1904b; letter-spacing: -.02em; }
    .cp-confirm-btn {
      width: 100%; padding: 15px; background: #d1904b; border: none; border-radius: 13px;
      color: #fff; font-weight: 700; font-size: 15px; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      font-family: 'Poppins',sans-serif; transition: all .25s; letter-spacing: .02em;
    }
    .cp-confirm-btn:hover { filter: brightness(1.1); transform: translateY(-1px); box-shadow: 0 7px 28px rgba(209,144,75,.45); }
    .cp-confirm-btn:active { transform: translateY(0); box-shadow: none; }
    .cp-confirm-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
    .cp-confirm-btn.bakong { background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); }
    .cp-confirm-btn.cash   { background: linear-gradient(135deg, #27ae60 0%, #55e087 100%); color: #000; }
    .cp-confirm-btn.paylater { background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%); }
    .cp-confirm-btn.riel   { background: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%); }
    .cp-confirm-btn.split  { background: linear-gradient(135deg, #2980b9 0%, #27ae60 100%); color: #fff; }
    .cp-shortcuts { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 9px; font-size: 10px; color: var(--text-muted,#9a8070); opacity: .8; }
    .cp-shortcuts kbd { background: var(--bg,#f4efe9); border: 1px solid var(--border,#e0d4c4); border-radius: 4px; padding: 2px 5px; font-size: 9px; font-weight: 700; color: var(--text-sec,#5a4a3a); font-family: 'Poppins',sans-serif; letter-spacing: .03em; }

    /* ── Dark theme overrides ── */
    [data-theme="dark"] body { background: #0c0c0c; }
    [data-theme="dark"] .menu-header { background: rgba(14,14,14,.96); border-color: #252525; }
    [data-theme="dark"] .menu-panel .cat-nav { background: rgba(14,14,14,.96); border-color: #252525; }
    [data-theme="dark"] .cart-panel { background: #151515; border-left: 1px solid rgba(209,144,75,.14); }
    [data-theme="dark"] .cp-header { border-color: #282828; background: #191919; }
    [data-theme="dark"] .cp-body { background: #151515; }
    [data-theme="dark"] .cp-item { border-color: #232323; }
    [data-theme="dark"] .cp-item:hover { background: #1d1d1d; }
    [data-theme="dark"] .cp-summary { border-color: #232323; }
    [data-theme="dark"] .cp-section { border-color: #232323; }
    [data-theme="dark"] .cp-footer { background: #191919; border-color: #282828; }
    [data-theme="dark"] .cp-form-group input,
    [data-theme="dark"] .cp-form-group select,
    [data-theme="dark"] .cp-disc-inputs input,
    [data-theme="dark"] .cp-split-row input,
    [data-theme="dark"] .cp-change-calc input { background: #1a1a1a; color: #f0f0f0; border-color: #252525; color-scheme: dark; }
    [data-theme="dark"] .cp-qty { background: #0c0c0c; border-color: #252525; }
    [data-theme="dark"] .cp-pay-method { background: #1e1e1e; border-color: #2d2d2d; color: #aaa; }
    [data-theme="dark"] .cp-pay-method:hover { background: rgba(209,144,75,.07); border-color: rgba(209,144,75,.4); }
    [data-theme="dark"] .cp-pay-method.selected { background: rgba(209,144,75,.14); border-color: #d1904b; color: #f0f0f0; box-shadow: 0 0 0 2px rgba(209,144,75,.2); }
    [data-theme="dark"] .cp-drink-btn { background: #1e1e1e; border-color: #2d2d2d; color: #aaa; }
    [data-theme="dark"] .cp-drink-btn.active { background: rgba(209,144,75,.14); border-color: #d1904b; box-shadow: 0 0 0 2px rgba(209,144,75,.15); }
    [data-theme="dark"] .cp-split-inputs { background: #1a1a1a; border-color: #252525; }
    [data-theme="dark"] .cp-change-calc { background: rgba(85,224,135,.03); }
    [data-theme="dark"] .cp-discount-toggle { border-color: #363636; }
    [data-theme="dark"] #cpDiscountForm { background: rgba(209,144,75,.06); border-color: rgba(209,144,75,.25); }

    /* ── Payment modal ── */
    .cp-paymodal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(6px); z-index: 10000; align-items: center; justify-content: center; padding: 20px; }
    .cp-paymodal.active { display: flex; }
    .cp-paymodal-card { background: var(--bg-card,#fff); border: 1px solid var(--border,#e0d4c4); border-radius: 16px; width: 100%; max-width: 420px; padding: 22px 22px 18px; box-shadow: 0 12px 48px rgba(90,60,20,.18); position: relative; animation: cpPmIn .22s ease both; }
    @keyframes cpPmIn { from { opacity: 0; transform: translateY(16px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .cp-paymodal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .cp-paymodal-head h3 { font-size: 16px; font-weight: 700; color: var(--text,#1a1410); margin: 0; }
    .cp-paymodal-close { background: none; border: none; font-size: 20px; color: var(--text-muted,#9a8070); cursor: pointer; line-height: 1; }
    .cp-pm-breakdown { background: var(--bg,#f4efe9); border: 1px solid var(--border,#e0d4c4); border-radius: 10px; padding: 9px 14px; margin-bottom: 12px; }
    .cp-pm-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-sec,#5a4a3a); padding: 2px 0; font-variant-numeric: tabular-nums; }
    .cp-pm-hero { display: flex; flex-direction: column; padding: 0 4px; margin-bottom: 16px; }
    .cp-pm-hero-label { font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--text-muted,#9a8070); }
    .cp-pm-hero-amt { font-size: 40px; font-weight: 800; line-height: 1.04; color: var(--text,#1a1410); font-variant-numeric: tabular-nums; letter-spacing: -.02em; margin-top: 1px; }
    .cp-pm-hero-khr { font-size: 13px; font-weight: 600; color: #d1904b; margin-top: 3px; font-variant-numeric: tabular-nums; }
    .cp-pm-confirm { width: 100%; margin-top: 16px; padding: 14px; border: none; border-radius: 13px; background: linear-gradient(135deg,#d99a55,#c07f37); box-shadow: 0 6px 18px rgba(209,144,75,.28); color: #fff; font-size: 15px; font-weight: 700; font-family: 'Poppins',sans-serif; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: filter .15s ease, transform .12s ease; }
    .cp-pm-confirm:hover { filter: brightness(1.07); }
    .cp-pm-confirm:active { transform: scale(.99); }
    [data-theme="dark"] .cp-paymodal-card { background: #161616; border-color: #252525; }
    [data-theme="dark"] .cp-pm-breakdown { background: #1a1a1a; border-color: #252525; }
    @media (prefers-reduced-motion: reduce) {
      .cp-paymodal-card { animation: none; }
      .cp-pay-method, .cp-pm-confirm, .cp-pay-method .cp-pm-ico, .cp-pay-method .cp-pm-check { transition: none; }
    }

    /* ── Chat toggle in header ── */
    #chatToggle {
      position: static;
      width: 36px; height: 36px; border-radius: 50%;
      border: 1px solid var(--border,#e0d4c4);
      background: var(--bg-input,#ede8e0);
      color: var(--text,#1a1410);
      font-size: 15px; cursor: pointer; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      transition: all .25s; animation: none; box-shadow: none;
    }
    #chatToggle:hover { border-color: #d1904b; color: #d1904b; transform: none; }
    #chatBox { top: 66px; bottom: auto; right: 24px; }

  </style>
</head>
<body>

<?php if (!empty($_SESSION['stock_warning'])): $__sw = $_SESSION['stock_warning']; unset($_SESSION['stock_warning']); ?>
<div id="stockWarn" style="max-width:1200px;margin:14px auto 0;padding:13px 16px;display:flex;gap:12px;align-items:flex-start;
     background:rgba(209,144,75,.10);border:1px solid rgba(209,144,75,.40);border-radius:12px;color:#d1904b;font-size:13.5px;line-height:1.5;">
  <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i>
  <div style="flex:1;">
    <strong>Low stock on the last order.</strong> It was completed, but these ingredients ran short — restock soon:
    <ul style="margin:6px 0 0;padding-left:18px;">
      <?php foreach ($__sw as $line): ?>
      <li><?= htmlspecialchars($line) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <button type="button" onclick="this.parentElement.remove()" aria-label="Dismiss"
          style="background:none;border:none;color:#d1904b;cursor:pointer;font-size:15px;line-height:1;">&times;</button>
</div>
<?php endif; ?>

<!-- HEADER -->
<header class="menu-header">
  <div class="header-left">
    <?php
    $_role = $_SESSION['role'] ?? '';
    if (in_array($_role, ['admin', 'manager'])): ?>
    <a href="dashboard.php" class="btn-nav btn-orders">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <?php elseif ($_show_kitchen_btn): ?>
    <a href="view_order.php" class="btn-nav btn-orders">
      <i class="fa-solid fa-fire"></i> Kitchen
      <?php if ($active_orders > 0): ?>
      <span class="badge"><?= $active_orders ?></span>
      <?php endif; ?>
    </a>
    <?php else: ?>
    <a href="dashboard.php" class="btn-nav btn-orders">
      <i class="fa-solid fa-arrow-left"></i> Back
    </a>
    <?php endif; ?>
  </div>

  <div class="header-center">
    <form class="search-form" method="GET" id="searchForm">
      <div class="search-inner">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="search" placeholder="Search drinks..." value="<?= e($search_term) ?>" id="searchInput" autocomplete="off">
        <?php if (!empty($search_term)): ?>
        <a href="menu.php" class="search-clear"><i class="fa-solid fa-xmark"></i></a>
        <?php endif; ?>
      </div>
      <select name="sort" id="sortSelect" class="sort-select">
        <option value="default" <?= $sort==='default'?'selected':'' ?>>Default</option>
        <option value="price_low" <?= $sort==='price_low'?'selected':'' ?>>Price: Low → High</option>
        <option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>Price: High → Low</option>
      </select>
    </form>
  </div>

  <div class="header-right">
    <div class="brand">
      <img src="images/Newlogo.jpg" alt="Logo">
      <span class="brand-name">Bird's Nest</span>
    </div>
    <button id="chatToggle" onclick="toggleChat()" title="AI Assistant">
      <i class="fa-solid fa-robot"></i>
    </button>
    <button class="btn-theme" id="themeToggle" onclick="toggleTheme()" title="Toggle theme">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
    </button>
  </div>
</header>

<!-- ADD TO ORDER BANNER -->
<?php if ($add_to_order_mode > 0): ?>
<div class="add-order-banner">
  <i class="fa-solid fa-cart-plus"></i>
  Adding to Order #<?= $add_to_order_mode ?> &nbsp;&middot;&nbsp;
  <a href="cart_paylater.php" style="color:inherit;font-weight:700;text-decoration:underline;">View Cart &amp; Confirm</a>
</div>
<?php endif; ?>

<!-- ── POS SPLIT LAYOUT ── -->
<div class="pos-layout">

  <!-- ════ LEFT: MENU PANEL ════ -->
  <div class="menu-panel" id="menuPanel">

    <!-- Category nav (sticky within menu panel) -->
    <?php if (!$is_price_sort): ?>
    <nav class="cat-nav" id="catNav">
      <?php foreach ($categories as $key => $label):
        if (empty($products[$key])) continue;
        $count  = count(array_filter($products[$key], fn($p) => (int)$p['low_count'] === 0));
        $anchor = e(cat_anchor_id($key));
        $icon   = $catIcons[$key] ?? 'fa-circle';
      ?>
      <a href="#<?= $anchor ?>" class="cat-pill" data-target="<?= $anchor ?>">
        <i class="fa-solid <?= $icon ?>"></i>
        <?= e($label) ?>
        <span class="pill-count"><?= $count ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="menu-scroll" id="menuScroll">
      <main class="menu-main">

        <?php if (!$is_price_sort && !empty($top_sellers)): ?>
        <!-- TOP SELLERS -->
        <section class="top-sellers">
          <div class="section-header">
            <h2><i class="fa-solid fa-fire" style="color:#e74c3c;"></i> Top Sellers</h2>
          </div>
          <div class="sellers-strip">
            <?php foreach ($top_sellers as $idx => $t): ?>
            <div class="seller-card js-open-product"
                 data-product-id="<?= (int)$t['product_id'] ?>"
                 data-product-name="<?= e($t['name']) ?>"
                 data-product-price="<?= e($t['price']) ?>"
                 data-product-image="<?= e($t['image']) ?>"
                 data-product-category="<?= e($t['category']) ?>"
                 data-product-desc="<?= e($t['description']) ?>"
                 data-product-badge="<?= e($t['badge_text'] ?? '') ?>"
                 data-product-has-sizes="<?= (int)($t['has_sizes'] ?? 0) ?>"
                 data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$t['product_id']] ?? []), ENT_QUOTES) ?>'
                 data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$t['product_id']] ?? []), ENT_QUOTES) ?>'
                 data-is-bestseller="<?= $t['name']===$bestSellerName?'1':'0' ?>"
                 role="button" tabindex="0">
              <div class="seller-img-wrap">
                <img src="<?= e($t['image']) ?>" loading="lazy" alt="<?= e($t['name']) ?>">
                <?php if (!empty($t['badge_text'])): ?>
                <span class="product-badge seller-badge"><?= e($t['badge_text']) ?></span>
                <?php endif; ?>
              </div>
              <div class="seller-info">
                <div class="seller-rank"><?= $idx===0 ? '&#x1F3C6; #1 Seller' : '&#x1F525; Top Pick' ?></div>
                <h4><?= e($t['name']) ?></h4>
                <div class="seller-price">$<?= number_format($t['price'], 2) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>

        <!-- PRODUCTS -->
        <?php if ($is_price_sort && !empty($flat_products)): ?>
          <div class="cat-header" style="margin-top:20px;">
            <div class="cat-title-text">
              <h2><?= $sort==='price_low' ? 'Price: Low to High' : 'Price: High to Low' ?></h2>
            </div>
          </div>
          <div class="product-grid">
            <?php foreach ($flat_products as $p): ?>
            <?php if ($p['low_count'] > 0): ?>
              <div class="product-card disabled">
                <div class="card-img"><img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>"><div class="out-of-stock"><span>Out of Stock</span></div></div>
                <div class="card-info"><div class="card-name"><?= e($p['name']) ?></div><div class="card-price">Unavailable</div></div>
              </div>
            <?php else: ?>
              <div class="product-card js-open-product"
                   data-product-id="<?= (int)$p['product_id'] ?>"
                   data-product-name="<?= e($p['name']) ?>"
                   data-product-price="<?= e($p['price']) ?>"
                   data-product-image="<?= e($p['image']) ?>"
                   data-product-category="<?= e($p['category']) ?>"
                   data-product-desc="<?= e($p['description']) ?>"
                   data-product-badge="<?= e($p['badge_text'] ?? '') ?>"
                   data-product-has-sizes="<?= (int)($p['has_sizes'] ?? 0) ?>"
                   data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-is-bestseller="<?= $p['name']===$bestSellerName?'1':'0' ?>"
                   role="button" tabindex="0">
                <div class="card-img">
                  <?php if ($p['name']===$bestSellerName): ?><span class="badge-bestseller">&#x2605; Best Seller</span><?php endif; ?>
                  <?php if (!empty($p['badge_text'])): ?><span class="product-badge"><?= e($p['badge_text']) ?></span><?php endif; ?>
                  <img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>">
                  <div class="img-overlay"></div>
                  <?php if ((int)($p['has_sizes'] ?? 0) === 1): ?>
                  <button class="quick-add-btn" onclick="event.stopPropagation(); openModalFromCard(this.closest('.product-card'));" title="Choose size"><i class="fa-solid fa-plus"></i></button>
                  <?php else: ?>
                  <button class="quick-add-btn" onclick="event.stopPropagation(); quickAdd(<?= (int)$p['product_id'] ?>, <?= (float)$p['price'] ?>)" title="Quick add"><i class="fa-solid fa-plus"></i></button>
                  <?php endif; ?>
                </div>
                <div class="card-info"><div class="card-name"><?= e($p['name']) ?></div><div class="card-desc"><?= e($p['description']) ?></div><div class="card-price">$<?= number_format($p['price'], 2) ?></div></div>
              </div>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php elseif (!empty($products)): ?>
          <?php foreach ($categories as $key => $label):
            if (empty($products[$key])) continue;
            $anchor = cat_anchor_id($key);
            $icon   = $catIcons[$key] ?? 'fa-circle';
          ?>
          <section class="cat-section" id="<?= e($anchor) ?>">
            <div class="cat-header">
              <div class="cat-icon"><i class="fa-solid <?= $icon ?>"></i></div>
              <div class="cat-title-text">
                <h2><?= e($label) ?></h2>
                <?php $_in_stock = count(array_filter($products[$key], fn($p) => (int)$p['low_count'] === 0)); ?>
                <span><?= $_in_stock ?> item<?= $_in_stock!==1?'s':'' ?></span>
              </div>
            </div>
            <div class="product-grid">
            <?php foreach ($products[$key] as $p): ?>
              <?php if ($p['low_count'] > 0): ?>
              <div class="product-card disabled">
                <div class="card-img"><img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>"><div class="out-of-stock"><span>Out of Stock</span></div></div>
                <div class="card-info"><div class="card-name"><?= e($p['name']) ?></div><div class="card-price" style="color:#dc3545">Unavailable</div></div>
              </div>
              <?php else: ?>
              <div class="product-card js-open-product"
                   data-product-id="<?= (int)$p['product_id'] ?>"
                   data-product-name="<?= e($p['name']) ?>"
                   data-product-price="<?= e($p['price']) ?>"
                   data-product-image="<?= e($p['image']) ?>"
                   data-product-category="<?= e($p['category']) ?>"
                   data-product-desc="<?= e($p['description']) ?>"
                   data-product-badge="<?= e($p['badge_text'] ?? '') ?>"
                   data-product-has-sizes="<?= (int)($p['has_sizes'] ?? 0) ?>"
                   data-product-sizes='<?= htmlspecialchars(json_encode($sizesByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-product-addons='<?= htmlspecialchars(json_encode($addonsByProduct[(int)$p['product_id']] ?? []), ENT_QUOTES) ?>'
                   data-is-bestseller="<?= $p['name']===$bestSellerName?'1':'0' ?>"
                   role="button" tabindex="0">
                <div class="card-img">
                  <?php if ($p['name']===$bestSellerName): ?><span class="badge-bestseller">&#x2605; Best Seller</span><?php endif; ?>
                  <?php if (!empty($p['badge_text'])): ?><span class="product-badge"><?= e($p['badge_text']) ?></span><?php endif; ?>
                  <img src="<?= e($p['image']) ?>" loading="lazy" alt="<?= e($p['name']) ?>">
                  <div class="img-overlay"></div>
                  <?php if ((int)($p['has_sizes'] ?? 0) === 1): ?>
                  <button class="quick-add-btn" onclick="event.stopPropagation(); openModalFromCard(this.closest('.product-card'));" title="Choose size"><i class="fa-solid fa-plus"></i></button>
                  <?php else: ?>
                  <button class="quick-add-btn" onclick="event.stopPropagation(); quickAdd(<?= (int)$p['product_id'] ?>, <?= (float)$p['price'] ?>)" title="Quick add"><i class="fa-solid fa-plus"></i></button>
                  <?php endif; ?>
                </div>
                <div class="card-info"><div class="card-name"><?= e($p['name']) ?></div><div class="card-desc"><?= e($p['description']) ?></div><div class="card-price">$<?= number_format($p['price'], 2) ?></div></div>
              </div>
              <?php endif; ?>
            <?php endforeach; ?>
            </div>
          </section>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa-solid fa-mug-hot"></i>
            <h3>No items found</h3>
            <p>Try a different search term.</p>
            <?php if (!empty($search_term)): ?>
            <a href="menu.php" class="btn-clear-search">Clear search</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </main><!-- /menu-main -->
    </div><!-- /menu-scroll -->
  </div><!-- /menu-panel -->

  <!-- ════ RIGHT: CART PANEL ════ -->
  <aside class="cart-panel" id="cartPanel">

    <!-- Cart header -->
    <div class="cp-header">
      <div class="cp-title">
        <i class="fa-solid fa-cart-shopping"></i>
        <span>Cart</span>
        <span class="cp-count" id="cpCount"><?= $cart_count ?> item<?= $cart_count != 1 ? 's' : '' ?></span>
      </div>
      <?php if (!empty($cart)): ?>
      <button class="cp-clear-btn" id="cpClearBtn" onclick="cpClearCart()">
        <i class="fa-solid fa-trash"></i> Clear
      </button>
      <?php else: ?>
      <button class="cp-clear-btn" id="cpClearBtn" onclick="cpClearCart()" style="display:none">
        <i class="fa-solid fa-trash"></i> Clear
      </button>
      <?php endif; ?>
    </div>

    <!-- Cart items (scrollable) -->
    <div class="cp-body" id="cpBody">

      <?php if (empty($cart)): ?>
      <!-- Empty state -->
      <div class="cp-empty" id="cpEmpty">
        <i class="fa-solid fa-mug-hot"></i>
        <p>Cart is empty</p>
        <small>Tap a drink to add it</small>
      </div>

      <?php else: ?>
      <!-- Items -->
      <div id="cpItems">
        <?php foreach ($cart as $i => $item):
          $qty  = (int)($item['qty'] ?? 1);
          $line = (float)($item['price'] ?? 0) * $qty;
          $meta = array_filter([
            !empty($item['size_label']) ? 'Size: '.$item['size_label']  : '',
            !empty($item['sweetness'])  ? 'Sweet: '.$item['sweetness']  : '',
            !empty($item['ice'])        ? 'Ice: '.$item['ice']          : '',
            !empty($item['milk'])       ? 'Milk: '.$item['milk']        : '',
          ]);
        ?>
        <div class="cp-item" id="cp-item-<?= $i ?>">
          <img src="<?= e($item['image'] ?? '') ?>" alt="<?= e($item['product_name'] ?? '') ?>">
          <div class="cp-item-info">
            <div class="cp-item-name"><?= e($item['product_name'] ?? '') ?></div>
            <?php if ($meta): ?><div class="cp-item-meta"><?= e(implode(' • ', $meta)) ?></div><?php endif; ?>
            <div class="cp-item-price">$<span id="cp-line-<?= $i ?>"><?= number_format((float)($item['price'] ?? 0), 2) ?></span></div>
          </div>
          <div class="cp-item-actions">
            <div class="cp-qty">
              <button onclick="cpChangeQty(<?= $i ?>, -1)">−</button>
              <input type="number" id="cp-qty-<?= $i ?>" value="<?= $qty ?>" min="1" onchange="cpSetQty(<?= $i ?>,this.value)" onfocus="this.select()" onkeydown="if(event.key==='Enter'){event.preventDefault();cpSetQty(<?= $i ?>,this.value);this.blur();}">
              <button onclick="cpChangeQty(<?= $i ?>, 1)">+</button>
            </div>
            <button class="cp-remove" onclick="cpRemoveItem(<?= $i ?>)" title="Remove"><i class="fa-solid fa-trash-can"></i></button>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Free item row -->
        <div class="cp-item" id="cpFreeRow" style="<?= $cp_buy3 > 0 ? 'border-top:1px dashed #27ae60;' : 'display:none' ?>">
          <div class="cp-free-icon">&#x1F381;</div>
          <div class="cp-item-info">
            <div class="cp-item-name"><span id="cpFreeName"><?= e($cp_free_name) ?></span> <span class="cp-free-badge">FREE</span></div>
            <div class="cp-item-meta">Buy <?= BUY_X_COUNT ?> Get 1 Free</div>
            <div class="cp-item-price" style="color:#27ae60;">FREE <s style="color:#aaa;font-size:10px;">was $<span id="cpFreePrice"><?= number_format($cp_free_price, 2) ?></span></s></div>
          </div>
        </div>
      </div><!-- /cpItems -->

      <!-- Summary rows -->
      <div class="cp-summary" id="cpSummary">
        <div class="cp-sum-row">
          <span>Subtotal</span>
          <span id="cpSubtotal">$<?= number_format($cp_subtotal, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpBuy3Row" style="<?= $cp_buy3 > 0 ? '' : 'display:none' ?>">
          <span>&#x1F389; Buy <?= BUY_X_COUNT ?> Get 1 Free</span>
          <span id="cpBuy3Amt">-$<?= number_format($cp_buy3, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpHHRow" style="<?= $cp_hh > 0 ? '' : 'display:none' ?>">
          <span>&#x1F305; Happy Hour (<?= HAPPY_HOUR_DISCOUNT ?>% off)</span>
          <span id="cpHHAmt">-$<?= number_format($cp_hh, 2) ?></span>
        </div>
        <div class="cp-sum-row discount" id="cpManualRow" style="<?= $cp_manual > 0 ? '' : 'display:none' ?>">
          <span id="cpManualLabel">&#x1F3F7;&#xFE0F; <?= e($cp_manual_label) ?></span>
          <span id="cpManualAmt">-$<?= number_format($cp_manual, 2) ?></span>
        </div>

        <!-- Discount panel -->
        <div id="cpDiscountPanel">
          <?php if ($cp_manual > 0): ?>
          <button type="button" class="cp-discount-toggle remove" onclick="cpClearDiscount()">
            <i class="fa-solid fa-xmark"></i> Remove Discount
          </button>
          <?php else: ?>
          <button type="button" class="cp-discount-toggle" id="cpAddDiscBtn" onclick="cpOpenDiscount()">
            <i class="fa-solid fa-tag"></i> Add Discount
          </button>
          <?php endif; ?>
          <div id="cpDiscountForm" style="display:none">
            <div class="cp-dtype-row">
              <button type="button" class="cp-dtype-btn active" id="cpDtypePercent" onclick="cpSetDType('percent')">% Percent</button>
              <button type="button" class="cp-dtype-btn" id="cpDtypeFlat" onclick="cpSetDType('flat')">$ Flat</button>
            </div>
            <div class="cp-disc-inputs">
              <input type="number" id="cpDiscAmount" placeholder="0" min="0" step="0.01">
              <input type="text"   id="cpDiscReason" placeholder="Reason (e.g. Staff, VIP)" maxlength="100">
            </div>
            <div class="cp-disc-actions">
              <button type="button" class="cp-btn-apply" onclick="cpApplyDiscount()"><i class="fa-solid fa-check"></i> Apply</button>
              <button type="button" class="cp-btn-cancel" onclick="cpCloseDiscount()">Cancel</button>
            </div>
          </div>
        </div>

        <div class="cp-sum-row">
          <span>Tax (<?= TAX_RATE ?>%)</span>
          <span id="cpTax">$<?= number_format($cp_tax, 2) ?></span>
        </div>

        <!-- Loyalty -->
        <div class="cp-loyalty cp-section">
          <div class="cp-loyalty-info">
            <i class="fa-solid fa-star" style="color:#d1904b;margin-right:3px;"></i>
            <span id="cpLoyaltyStatus" class="<?= $linked_loyalty ? 'linked' : '' ?>">
              <?php if ($linked_loyalty): ?>
                <?= e($linked_loyalty['loyalty_id']) ?> &mdash; <?= (int)$linked_loyalty['points'] ?> pts
              <?php else: ?>
                Loyalty card
              <?php endif; ?>
            </span>
          </div>
          <button class="cp-loyalty-btn" onclick="openLoyaltyModal()" id="cpLoyaltyBtn">
            <?= $linked_loyalty ? '<i class="fa-solid fa-circle-check"></i> Linked' : '<i class="fa-solid fa-credit-card"></i> Link' ?>
          </button>
        </div>

        <?php if (!$add_to_order_mode): ?>
        <!-- Order type -->
        <div class="cp-section">
          <div class="cp-section-label"><i class="fa-solid fa-mug-hot"></i> Order Type</div>
          <div class="cp-drink-type">
            <button type="button" class="cp-drink-btn active" id="cpBtnDrinkIn" onclick="cpSetDrinkType('drink_in')">
              <i class="fa-solid fa-mug-hot"></i> Drink In
            </button>
            <button type="button" class="cp-drink-btn" id="cpBtnDrinkOut" onclick="cpSetDrinkType('drink_out')">
              <i class="fa-solid fa-bag-shopping"></i> Drink Out
            </button>
          </div>
        </div>
        <?php else: ?>
        <!-- Add-to-order mode: payment already set, just show a note -->
        <div class="cp-section" style="background:rgba(209,144,75,.08);border:1px solid rgba(209,144,75,.3);border-radius:10px;padding:10px 12px;">
          <div style="font-size:12px;font-weight:600;color:#d1904b;margin-bottom:4px;"><i class="fa-solid fa-clock-rotate-left"></i> Adding to Pay Later Order #<?= $add_to_order_mode ?></div>
          <div style="font-size:11px;color:#888;line-height:1.5;">Payment was already set when this order was created. Just select items and confirm to add them.</div>
        </div>
        <?php endif; ?>

        <!-- Customer name -->
        <div class="cp-form-group">
          <label><i class="fa-regular fa-user"></i> Customer Name</label>
          <input type="text" id="cpCustomerName" placeholder="Leave blank for Guest">
        </div>

        <!-- Stand number (drink in only) -->
        <div class="cp-form-group" id="cpTableNumberGroup">
          <label style="display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-hashtag"></i> Stand Number <span style="color:var(--text-muted);font-weight:400;font-size:11px;">(optional)</span>
            <button type="button" onclick="cpToggleStandGrid()" style="margin-left:auto;background:none;border:1px solid rgba(255,255,255,.15);border-radius:6px;padding:2px 8px;font-size:11px;cursor:pointer;color:#aaa;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-table-cells-large"></i> Pick</button>
          </label>
          <input type="text" id="cpTableNumber" name="table_number" maxlength="10" placeholder="e.g. 1, 7, 12..." onblur="cpCheckStand(this.value)">
          <div id="cpStandWarn" style="display:none;margin-top:6px;padding:7px 10px;background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.35);border-radius:8px;font-size:12px;color:#f0ad4e;align-items:center;gap:6px;"><i class="fa-solid fa-triangle-exclamation"></i> <span id="cpStandWarnText"></span></div>
          <div id="cpStandGrid" style="display:none;margin-top:6px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:10px;"></div>
        </div>

      </div><!-- /cp-summary -->
      <?php endif; ?>
    </div><!-- /cp-body -->

    <!-- Cart footer (always visible) -->
    <div class="cp-footer" id="cpFooter" <?= empty($cart) ? 'style="display:none"' : '' ?>>
      <div class="cp-total-row">
        <span class="lbl">Total</span>
        <span class="amt" id="cpTotal">$<?= number_format($cp_total, 2) ?></span>
      </div>
      <form method="post" action="confirm_order.php" id="cpCheckoutForm">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="order_type" id="cpOrderTypeInput" value="drink_in">
        <input type="hidden" name="is_add_to_order" value="<?= $add_to_order_mode > 0 ? '1' : '0' ?>">
        <?php if ($add_to_order_mode > 0): ?>
        <input type="hidden" name="add_to_order_id" value="<?= $add_to_order_mode ?>">
        <?php endif; ?>
        <div id="cpPaymentInputs"></div>
        <button type="button" class="cp-confirm-btn<?= $add_to_order_mode ? ' paylater' : '' ?>" id="cpConfirmBtn" onclick="cpOnConfirmOrderClick()">
          <i class="fa-solid fa-<?= $add_to_order_mode ? 'cart-plus' : 'credit-card' ?>" id="cpConfirmIcon"></i>
          <span id="cpConfirmText"><?= $add_to_order_mode ? 'Add to Order #'.$add_to_order_mode : 'Confirm Order' ?></span>
        </button>
      </form>
      <div class="cp-shortcuts">
        <?php if (!$add_to_order_mode): ?>
        <span><kbd>B</kbd> Bakong</span>
        <span><kbd>C</kbd> Cash</span>
        <span><kbd>P</kbd> Pay Later</span>
        <span><kbd>R</kbd> Riel</span>
        <?php endif; ?>
        <span><kbd>Enter</kbd> Confirm</span>
      </div>
    </div>

  </aside><!-- /cart-panel -->
</div><!-- /pos-layout -->

<!-- PRODUCT MODAL -->
<div id="modal" class="modal">
  <div class="modal-card">
    <div class="modal-img-wrap">
      <img id="modalImg" class="modal-img" src="" alt="">
      <span id="modalBadge" class="modal-product-badge" style="display:none"></span>
    </div>
    <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
    <div class="modal-body">
      <h2 class="modal-name" id="modalName"></h2>
      <p class="modal-desc" id="modalDesc"></p>
      <div class="modal-price-row">
        <span class="modal-price" id="modalPrice"></span>
        <div class="qty-control">
          <button type="button" onclick="changeQty(-1)">&#x2212;</button>
          <span id="modalQtyDisplay">1</span>
          <button type="button" onclick="changeQty(1)">+</button>
        </div>
      </div>
      <div id="optSize" class="option-section" style="display:none">
        <div class="option-label">Size</div>
        <div class="pill-group" id="sizePills"></div>
      </div>
      <div id="optSweetness" class="option-section">
        <div class="option-label">Sweetness</div>
        <div class="pill-group" id="sweetnessPills">
          <?php foreach (['0%','25%','50%','75%','100%'] as $s): ?>
          <button class="option-pill <?= $s==='50%'?'active':'' ?>" data-group="sweetness" data-value="<?= $s ?>" onclick="selectPill(this)"><?= $s ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="optIce" class="option-section">
        <div class="option-label">Ice</div>
        <div class="pill-group" id="icePills">
          <?php foreach (['No Ice','Less Ice','Normal Ice','More Ice'] as $ic): ?>
          <button class="option-pill <?= $ic==='Normal Ice'?'active':'' ?>" data-group="ice" data-value="<?= $ic ?>" onclick="selectPill(this)"><?= $ic ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="optMilk" class="option-section">
        <div class="option-label">Milk</div>
        <div class="pill-group" id="milkPills">
          <?php foreach (['Fresh Milk','Almond Milk','Soy Milk','Oat Milk'] as $mk): ?>
          <button class="option-pill <?= $mk==='Fresh Milk'?'active':'' ?>" data-group="milk" data-value="<?= $mk ?>" onclick="selectPill(this)"><?= $mk ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="optAddons" class="option-section" style="display:none">
        <div class="option-label">Add-ons</div>
        <div class="pill-group" id="addonPills"></div>
      </div>
      <div class="modal-footer">
        <div class="modal-total">Total: <strong id="modalTotalDisplay">$0.00</strong></div>
        <button class="btn-add-to-cart" onclick="addToCart()">
          <i class="fa-solid fa-cart-plus"></i> Add to Cart
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── PAYMENT MODAL ── -->
<div id="cpPayModal" class="cp-paymodal">
  <div class="cp-paymodal-card" id="cpPayModalCard">
    <div class="cp-paymodal-head">
      <h3><i class="fa-solid fa-credit-card" style="color:#d1904b;"></i> Payment</h3>
      <button type="button" class="cp-paymodal-close" id="cpPayModalClose" onclick="cpClosePayModal()"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="cp-pm-breakdown">
      <div class="cp-pm-row"><span>Subtotal</span><span id="cpPmSubtotal">$0.00</span></div>
      <div class="cp-pm-row"><span>Tax (<?= (int)TAX_RATE ?>%)</span><span id="cpPmTax">$0.00</span></div>
    </div>
    <div class="cp-pm-hero">
      <span class="cp-pm-hero-label">Total due</span>
      <span class="cp-pm-hero-amt" id="cpPmTotal">$0.00</span>
      <span class="cp-pm-hero-khr" id="cpPmKhr">&#x17DB; 0</span>
    </div>
    <div id="cpPayModalBody">
      <?php if (!$add_to_order_mode): ?>
      <div class="cp-pm-methods-label">How is the customer paying?</div>
      <div class="cp-pay-methods" id="cpPayMethods">
        <div class="cp-pay-method" data-method="bakong" onclick="cpTogglePayment(this)">
          <input type="checkbox" value="bakong">
          <i class="cp-pm-ico fa-solid fa-qrcode"></i><span class="cp-pm-lbl">Bakong</span>
          <i class="cp-pm-check fa-solid fa-circle-check"></i>
        </div>
        <div class="cp-pay-method" data-method="cash" onclick="cpTogglePayment(this)">
          <input type="checkbox" value="cash">
          <i class="cp-pm-ico fa-solid fa-money-bill-wave"></i><span class="cp-pm-lbl">Cash</span>
          <i class="cp-pm-check fa-solid fa-circle-check"></i>
        </div>
        <div class="cp-pay-method" data-method="paylater" onclick="cpTogglePayment(this)">
          <input type="checkbox" value="paylater">
          <i class="cp-pm-ico fa-solid fa-clock"></i><span class="cp-pm-lbl">Later</span>
          <i class="cp-pm-check fa-solid fa-circle-check"></i>
        </div>
        <div class="cp-pay-method" data-method="riel" onclick="cpTogglePayment(this)">
          <input type="checkbox" value="riel">
          <i class="cp-pm-ico fa-solid fa-coins"></i><span class="cp-pm-lbl">Riel &#x17DB;</span>
          <i class="cp-pm-check fa-solid fa-circle-check"></i>
        </div>
      </div>
      <div class="cp-split-inputs" id="cpSplitInputs"><div id="cpSplitRows"></div></div>

      <div class="cp-change-calc" id="cpRielCalc">
        <label><i class="fa-solid fa-coins" style="color:#e74c3c;margin-right:4px;"></i> Amount in Riel (KHR)</label>
        <input type="number" id="cpRielReceived" step="1" min="0" placeholder="0" oninput="cpCalcRielChange()" onfocus="this.select()">
        <div class="cp-change-row">
          <span class="change-label">USD Equivalent</span>
          <span class="change-amount" id="cpRielUsdEquiv">$0.00</span>
        </div>
        <div class="cp-change-row" id="cpRielChangeRow" style="display:none;">
          <span class="change-label">Change (KHR)</span>
          <span class="change-amount" id="cpRielChangeKhr">&#x17DB;0</span>
        </div>
      </div>

      <div class="cp-change-calc" id="cpChangeCalc">
        <label><i class="fa-solid fa-money-bill-wave" style="color:#55e087;margin-right:4px;"></i> Amount Received</label>
        <input type="number" id="cpCashReceived" step="0.01" min="0" placeholder="0.00" oninput="cpCalcChange()" onfocus="this.select()">
        <div class="cp-change-row">
          <span class="change-label">Change to give back</span>
          <span class="change-amount" id="cpChangeAmount">$0.00</span>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <button type="button" class="cp-pm-confirm" id="cpConfirmPayBtn">
      <i class="fa-solid fa-check" id="cpConfirmPayIcon"></i> <span id="cpConfirmPayText">Confirm Payment</span>
    </button>
  </div>
</div>

<!-- TOAST -->
<div id="toast-container"></div>

<!-- CHAT -->
<div id="chatBox">
  <div class="chat-header">
    <div class="chat-title"><i class="fa-solid fa-robot"></i> AI Assistant</div>
    <button onclick="toggleChat()" style="background:none;border:none;color:white;cursor:pointer;font-size:18px;"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div id="chatMessages">
    <div class="msg-bot">
      <div class="avatar"><i class="fa-solid fa-robot"></i></div>
      <div class="bubble">Hello! Welcome to Bird's Nest Coffee! &#x2615; Ask me about our menu or recommendations!</div>
    </div>
  </div>
  <div class="chat-input">
    <input type="text" id="chatInput" placeholder="Ask me something..." autocomplete="off">
    <button id="chatSendBtn" onclick="sendChat()"><i class="fa-solid fa-paper-plane"></i></button>
  </div>
</div>

<!-- LOYALTY MODAL -->
<div id="loyaltyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(8px);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:var(--bg-card,#fff);border-radius:16px;padding:28px;max-width:420px;width:90%;position:relative;border:1px solid var(--border,#e0d4c4);box-shadow:0 12px 48px rgba(90,60,20,.16);">
    <span onclick="closeLoyaltyModal()" style="position:absolute;right:14px;top:10px;font-size:22px;color:var(--text-muted,#9a8070);cursor:pointer;"><i class="fa-solid fa-xmark"></i></span>
    <div style="text-align:center;margin-bottom:18px;">
      <i class="fa-solid fa-star" style="font-size:36px;color:#d1904b;"></i>
      <h2 style="font-size:18px;margin:6px 0 3px;color:var(--text,#1a1410);">Loyalty Card</h2>
      <p style="font-size:12px;color:var(--text-sec,#5a4a3a);">Enter your loyalty ID to view points and redeem rewards</p>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:14px;">
      <input type="text" id="loyaltyIdInput" placeholder="e.g. CARD-12345" style="flex:1;padding:9px 12px;border-radius:9px;border:1px solid var(--border,#e0d4c4);background:var(--bg,#f4efe9);color:var(--text,#1a1410);font-family:'Poppins',sans-serif;font-size:13px;outline:none;">
      <button onclick="lookupLoyalty()" style="background:#d1904b;color:#fff;border:none;padding:9px 16px;border-radius:9px;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
    <div id="loyaltyResult" style="display:none;padding:14px;background:rgba(255,255,255,.03);border-radius:10px;border:1px solid var(--border,#e0d4c4);">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div><div style="font-size:11px;color:var(--text-sec,#5a4a3a);">Loyalty ID</div><div id="loyaltyDisplayId" style="font-size:15px;font-weight:700;color:#d1904b;"></div></div>
        <div style="text-align:right;"><div style="font-size:11px;color:var(--text-sec,#5a4a3a);">Points</div><div id="loyaltyPoints" style="font-size:20px;font-weight:700;color:var(--text,#1a1410);">0</div></div>
      </div>
      <div id="loyaltyRewards" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px;"></div>
      <div id="loyaltyHistory" style="margin-top:10px;max-height:100px;overflow-y:auto;font-size:11px;color:var(--text-sec,#5a4a3a);border-top:1px solid var(--border,#e0d4c4);padding-top:6px;"></div>
    </div>
    <div id="loyaltyError" style="display:none;padding:10px;background:rgba(231,76,60,.08);border-radius:8px;border:1px solid rgba(231,76,60,.2);color:#e74c3c;text-align:center;font-size:12px;"><i class="fa-solid fa-circle-exclamation"></i> Card not found.</div>
  </div>
</div>

<script>
const CP_KHR_RATE = <?= defined('KHR_RATE') ? (int)KHR_RATE : 4100 ?>;

// ── Theme ──
(function() {
  var saved = localStorage.getItem('theme') || 'dark';
  if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();

function toggleTheme() {
  var html = document.documentElement, isDark = html.getAttribute('data-theme') === 'dark';
  if (isDark) { html.removeAttribute('data-theme'); localStorage.setItem('theme','light'); document.getElementById('themeIcon').className='fa-solid fa-moon'; }
  else        { html.setAttribute('data-theme','dark'); localStorage.setItem('theme','dark'); document.getElementById('themeIcon').className='fa-solid fa-sun'; }
}
document.addEventListener('DOMContentLoaded', function() {
  if ((localStorage.getItem('theme') || 'dark') === 'dark') document.getElementById('themeIcon').className = 'fa-solid fa-sun';
});

// ── Constants from PHP ──
var CSRF        = '<?= e($_SESSION['csrf_token']) ?>';
var BUY_X_COUNT = <?= (int)BUY_X_COUNT ?>;
var ADD_TO_ORDER_MODE = <?= (int)$add_to_order_mode ?>;
var CAFE_TABLES = [];
var CP_STAND_MAX = <?= STAND_COUNT ?>;
var CP_TAX_RATE  = <?= TAX_RATE ?>;

// ── Escape HTML for JS-built elements ──
function escH(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── PRODUCT MODAL ──
var product = {}, modalQty = 1, modalUnitPrice = 0, modalAddonTotal = 0;

function openModal(id, name, price, img, cat, desc, badge, hasSizes, sizes, addons) {
  var p = Number(price) || 0;
  product = { id: id, name: name, price: p, cat: cat };
  modalQty = 1; modalUnitPrice = p;
  document.getElementById('modalImg').src = img;
  var mb = document.getElementById('modalBadge');
  if (mb) { mb.textContent = badge || ''; mb.style.display = badge ? 'flex' : 'none'; }
  document.getElementById('modalName').textContent = name;
  document.getElementById('modalDesc').textContent = desc || '';
  document.getElementById('modalPrice').textContent = '$' + p.toFixed(2);
  document.getElementById('modalQtyDisplay').textContent = '1';
  var isJuice = cat === 'Juice', isHot = cat === 'Hot';
  document.getElementById('optSweetness').style.display = isJuice ? 'none' : 'block';
  document.getElementById('optIce').style.display       = (isHot || isJuice) ? 'none' : 'block';
  document.getElementById('optMilk').style.display      = isJuice ? 'none' : 'block';
  document.querySelectorAll('#sweetnessPills .option-pill').forEach(function(pill) { pill.classList.toggle('active', pill.dataset.value === '50%'); });
  document.querySelectorAll('#icePills .option-pill').forEach(function(pill)      { pill.classList.toggle('active', pill.dataset.value === 'Normal Ice'); });
  document.querySelectorAll('#milkPills .option-pill').forEach(function(pill)     { pill.classList.toggle('active', pill.dataset.value === 'Fresh Milk'); });

  // ── Size pills (render in given order; default = Medium or first) ──
  var sizeWrap = document.getElementById('optSize');
  var pills = document.getElementById('sizePills');
  pills.innerHTML = '';
  if (hasSizes && Array.isArray(sizes) && sizes.length) {
    sizes.forEach(function(s) {
      var b = document.createElement('button');
      b.className = 'option-pill';
      b.dataset.group = 'size';
      b.dataset.value = s.code;
      b.dataset.price = s.price;
      b.textContent = s.label + ' $' + Number(s.price).toFixed(2);
      b.onclick = function(){ selectSize(b); };
      pills.appendChild(b);
    });
    // default Medium if present else first
    var def = pills.querySelector('[data-value="M"]') || pills.firstChild;
    if (def) { def.classList.add('active'); modalUnitPrice = Number(def.dataset.price) || p; }
    document.getElementById('modalPrice').textContent = '$' + modalUnitPrice.toFixed(2);
    sizeWrap.style.display = 'block';
  } else {
    sizeWrap.style.display = 'none';
  }

  // ── Add-on pills (multi-select toggles) ──
  modalAddonTotal = 0;
  var addonWrap = document.getElementById('optAddons');
  var addonBox  = document.getElementById('addonPills');
  addonBox.innerHTML = '';
  if (Array.isArray(addons) && addons.length) {
    addons.forEach(function(a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'option-pill';
      b.dataset.addonId = a.id;
      b.dataset.addonPrice = a.price;
      b.textContent = a.name + ' +$' + Number(a.price).toFixed(2);
      b.onclick = function(){ toggleAddon(b); };
      addonBox.appendChild(b);
    });
    addonWrap.style.display = 'block';
  } else {
    addonWrap.style.display = 'none';
  }

  updateModalTotal();
  document.getElementById('modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

// Open the product modal using a card's data-product-* attributes (avoids inline-onclick string interpolation)
function openModalFromCard(card) {
  if (!card) return;
  var sizes = [], addons = [];
  try { sizes = JSON.parse(card.dataset.productSizes || '[]'); } catch (e) { sizes = []; }
  try { addons = JSON.parse(card.dataset.productAddons || '[]'); } catch (e) { addons = []; }
  openModal(card.dataset.productId, card.dataset.productName||'', Number(card.dataset.productPrice||0), card.dataset.productImage||'', card.dataset.productCategory||'', card.dataset.productDesc||'', card.dataset.productBadge||'', card.dataset.productHasSizes==='1', sizes, addons);
}

function closeModal() { document.getElementById('modal').style.display = 'none'; document.body.style.overflow = ''; }
function changeQty(delta) { modalQty = Math.max(1, Math.min(10, modalQty + delta)); document.getElementById('modalQtyDisplay').textContent = modalQty; updateModalTotal(); }
function updateModalTotal() { document.getElementById('modalTotalDisplay').textContent = '$' + ((modalUnitPrice + modalAddonTotal) * modalQty).toFixed(2); }
function selectPill(pill) { pill.closest('.pill-group').querySelectorAll('.option-pill').forEach(function(p) { p.classList.remove('active'); }); pill.classList.add('active'); }
function toggleAddon(pill) {
  pill.classList.toggle('active');
  var t = 0;
  document.querySelectorAll('#addonPills .option-pill.active').forEach(function(p){ t += Number(p.dataset.addonPrice) || 0; });
  modalAddonTotal = t;
  updateModalTotal();
}
function getPillValue(groupId) { var a = document.querySelector('#' + groupId + ' .option-pill.active'); return a ? a.dataset.value : ''; }

function selectSize(pill) {
  pill.closest('.pill-group').querySelectorAll('.option-pill').forEach(function(p){ p.classList.remove('active'); });
  pill.classList.add('active');
  modalUnitPrice = Number(pill.dataset.price) || modalUnitPrice;
  document.getElementById('modalPrice').textContent = '$' + modalUnitPrice.toFixed(2);
  updateModalTotal();
}

// ── ADD TO CART (from modal) ──
function addToCart() {
  var btn = document.querySelector('.btn-add-to-cart');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
  var params = new URLSearchParams({ id: product.id, qty: modalQty, csrf_token: CSRF });
  if (document.getElementById('optSweetness').style.display !== 'none') params.append('sweetness', getPillValue('sweetnessPills'));
  if (document.getElementById('optIce').style.display !== 'none')       params.append('ice',       getPillValue('icePills'));
  if (document.getElementById('optMilk').style.display !== 'none')      params.append('milk',      getPillValue('milkPills'));
  if (document.getElementById('optSize').style.display !== 'none')      params.append('size',      getPillValue('sizePills'));
  document.querySelectorAll('#addonPills .option-pill.active').forEach(function(p){ params.append('addons[]', p.dataset.addonId); });

  fetch('add_to_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: params.toString() })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) { showToast(data.message || 'Error', 'error'); return; }
      showToast('Added to cart!', 'success');
      closeModal();
      loadCartPanel();
    })
    .catch(function() { showToast('Error adding to cart', 'error'); })
    .finally(function() { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-cart-plus"></i> Add to Cart'; });
}

// ── QUICK ADD ──
function quickAdd(productId, price) {
  fetch('add_to_cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: new URLSearchParams({ id: productId, qty: 1, csrf_token: CSRF }).toString() })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) { showToast(data.message || 'Error', 'error'); return; }
      showToast('Added!', 'success');
      loadCartPanel();
    })
    .catch(function() { showToast('Error', 'error'); });
}

// ── LOAD & RENDER CART PANEL ──
function loadCartPanel() {
  fetch('cart_refresh.php')
    .then(function(r) { return r.json(); })
    .then(function(data) { renderCartPanel(data); })
    .catch(function() {});
}

function renderCartPanel(data) {
  // Update header count
  var countEl = document.getElementById('cpCount');
  if (countEl) countEl.textContent = data.count + ' item' + (data.count != 1 ? 's' : '');

  var footer  = document.getElementById('cpFooter');
  var clearBtn = document.getElementById('cpClearBtn');
  var body    = document.getElementById('cpBody');

  if (data.items.length === 0) {
    if (body) body.innerHTML = '<div class="cp-empty"><i class="fa-solid fa-mug-hot"></i><p>Cart is empty</p><small>Tap a drink to add it</small></div>';
    if (footer) footer.style.display = 'none';
    if (clearBtn) clearBtn.style.display = 'none';
    return;
  }

  if (clearBtn) clearBtn.style.display = '';
  if (footer)  footer.style.display = '';

  // Build items HTML
  var itemsHtml = '<div id="cpItems">';
  data.items.forEach(function(item) {
    var meta = [
      item.size_label ? 'Size: '  + item.size_label : '',
      item.sweetness ? 'Sweet: ' + item.sweetness : '',
      item.ice       ? 'Ice: '   + item.ice       : '',
      item.milk      ? 'Milk: '  + item.milk      : '',
    ].filter(Boolean).join(' • ');
    itemsHtml += '<div class="cp-item" id="cp-item-' + item.index + '">' +
      '<img src="' + escH(item.image) + '" alt="' + escH(item.product_name) + '">' +
      '<div class="cp-item-info">' +
        '<div class="cp-item-name">' + escH(item.product_name) + '</div>' +
        (meta ? '<div class="cp-item-meta">' + escH(meta) + '</div>' : '') +
        '<div class="cp-item-price">$<span id="cp-line-' + item.index + '">' + item.price.toFixed(2) + '</span></div>' +
      '</div>' +
      '<div class="cp-item-actions">' +
        '<div class="cp-qty">' +
          '<button onclick="cpChangeQty(' + item.index + ',-1)">−</button>' +
          '<input type="number" id="cp-qty-' + item.index + '" value="' + item.qty + '" min="1" onchange="cpSetQty(' + item.index + ',this.value)" onfocus="this.select()" onkeydown="if(event.key===\'Enter\'){event.preventDefault();cpSetQty(' + item.index + ',this.value);this.blur();}">' +
          '<button onclick="cpChangeQty(' + item.index + ',1)">+</button>' +
        '</div>' +
        '<button class="cp-remove" onclick="cpRemoveItem(' + item.index + ')" title="Remove"><i class="fa-solid fa-trash-can"></i></button>' +
      '</div>' +
    '</div>';
  });

  // Free item row
  var buy3v = parseFloat(data.buy3);
  itemsHtml += '<div class="cp-item" id="cpFreeRow" style="' + (buy3v > 0 ? 'border-top:1px dashed #27ae60;' : 'display:none') + '">' +
    '<div class="cp-free-icon">&#x1F381;</div>' +
    '<div class="cp-item-info">' +
      '<div class="cp-item-name"><span id="cpFreeName">' + escH(data.buy3_name) + '</span> <span class="cp-free-badge">FREE</span></div>' +
      '<div class="cp-item-meta">Buy ' + data.buy3_count + ' Get 1 Free</div>' +
      '<div class="cp-item-price" style="color:#27ae60;">FREE <s style="color:#aaa;font-size:10px;">was $<span id="cpFreePrice">' + data.buy3_price + '</span></s></div>' +
    '</div>' +
  '</div>';
  itemsHtml += '</div>'; // /cpItems

  // Summary HTML
  var discountType = _cpDiscountType || 'percent';
  var manualV = parseFloat(data.manual);
  var hhV     = parseFloat(data.happy_hour);
  var b3V     = parseFloat(data.buy3);

  // Buy3 row shows the VALUE of the free drink — purely informational.
  // It is NOT subtracted from the total. Customer pays full price; free drink is an extra gift.
  itemsHtml += '<div class="cp-summary" id="cpSummary">' +
    '<div class="cp-sum-row"><span>Subtotal</span><span id="cpSubtotal">$' + data.subtotal + '</span></div>' +
    '<div class="cp-sum-row discount" id="cpBuy3Row" style="' + (b3V > 0 ? '' : 'display:none') + '">' +
      '<span>&#x1F389; Buy ' + data.buy3_count + ' Get 1 Free</span><span id="cpBuy3Amt">-$' + data.buy3 + '</span>' +
    '</div>' +
    '<div class="cp-sum-row discount" id="cpHHRow" style="' + (hhV > 0 ? '' : 'display:none') + '">' +
      '<span>&#x1F305; Happy Hour (' + data.happy_hour_pct + '% off)</span><span id="cpHHAmt">-$' + data.happy_hour + '</span>' +
    '</div>' +
    '<div class="cp-sum-row discount" id="cpManualRow" style="' + (manualV > 0 ? '' : 'display:none') + '">' +
      '<span id="cpManualLabel">&#x1F3F7;&#xFE0F; ' + escH(data.manual_label) + '</span><span id="cpManualAmt">-$' + data.manual + '</span>' +
    '</div>';

  // Discount panel
  itemsHtml += '<div id="cpDiscountPanel">';
  if (manualV > 0) {
    itemsHtml += '<button type="button" class="cp-discount-toggle remove" onclick="cpClearDiscount()"><i class="fa-solid fa-xmark"></i> Remove Discount</button>';
  } else {
    itemsHtml += '<button type="button" class="cp-discount-toggle" id="cpAddDiscBtn" onclick="cpOpenDiscount()"><i class="fa-solid fa-tag"></i> Add Discount</button>';
  }
  itemsHtml += '<div id="cpDiscountForm" style="display:none">' +
    '<div class="cp-dtype-row"><button type="button" class="cp-dtype-btn active" id="cpDtypePercent" onclick="cpSetDType(\'percent\')">% Percent</button><button type="button" class="cp-dtype-btn" id="cpDtypeFlat" onclick="cpSetDType(\'flat\')">$ Flat</button></div>' +
    '<div class="cp-disc-inputs"><input type="number" id="cpDiscAmount" placeholder="0" min="0" step="0.01"><input type="text" id="cpDiscReason" placeholder="Reason (e.g. Staff, VIP)" maxlength="100"></div>' +
    '<div class="cp-disc-actions"><button type="button" class="cp-btn-apply" onclick="cpApplyDiscount()"><i class="fa-solid fa-check"></i> Apply</button><button type="button" class="cp-btn-cancel" onclick="cpCloseDiscount()">Cancel</button></div>' +
  '</div></div>';

  itemsHtml += '<div class="cp-sum-row"><span>Tax (' + CP_TAX_RATE + '%)</span><span id="cpTax">$' + data.tax + '</span></div>';

  // Loyalty
  var loyaltyStatus = document.getElementById('cpLoyaltyStatus');
  var loyaltyLinked = loyaltyStatus && loyaltyStatus.classList.contains('linked');
  itemsHtml += '<div class="cp-loyalty cp-section">' +
    '<div class="cp-loyalty-info"><i class="fa-solid fa-star" style="color:#d1904b;margin-right:3px;"></i>' +
    '<span id="cpLoyaltyStatus"' + (loyaltyLinked ? ' class="linked"' : '') + '>' + (loyaltyStatus ? loyaltyStatus.textContent : 'Loyalty card') + '</span></div>' +
    '<button class="cp-loyalty-btn" onclick="openLoyaltyModal()" id="cpLoyaltyBtn">' + (loyaltyLinked ? '<i class="fa-solid fa-circle-check"></i> Linked' : '<i class="fa-solid fa-credit-card"></i> Link') + '</button>' +
  '</div>';

  // Preserve state that lives inside cpBody across re-renders
  var prevDrinkType    = (document.getElementById('cpOrderTypeInput') || {}).value || 'drink_in';
  var prevCustomerName = (document.getElementById('cpCustomerName')   || {}).value || '';
  var prevTableNumber  = (document.getElementById('cpTableNumber')    || {}).value || '';

  // Order type section only shown for fresh orders (payment block moved to modal)
  if (!ADD_TO_ORDER_MODE) {
    itemsHtml += '<div class="cp-section">' +
      '<div class="cp-section-label"><i class="fa-solid fa-mug-hot"></i> Order Type</div>' +
      '<div class="cp-drink-type">' +
      '<button type="button" class="cp-drink-btn active" id="cpBtnDrinkIn" onclick="cpSetDrinkType(\'drink_in\')"><i class="fa-solid fa-mug-hot"></i> Drink In</button>' +
      '<button type="button" class="cp-drink-btn" id="cpBtnDrinkOut" onclick="cpSetDrinkType(\'drink_out\')"><i class="fa-solid fa-bag-shopping"></i> Drink Out</button>' +
      '</div></div>';
  }

  // Customer name
  itemsHtml += '<div class="cp-form-group"><label><i class="fa-regular fa-user"></i> Customer Name</label>' +
    '<input type="text" id="cpCustomerName" placeholder="Leave blank for Guest"></div>';

  // Stand number (drink in only)
  itemsHtml += '<div class="cp-form-group" id="cpTableNumberGroup">' +
    '<label style="display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-hashtag"></i> Stand Number <span style="color:var(--text-muted);font-weight:400;font-size:11px;">(optional)</span>' +
    '<button type="button" onclick="cpToggleStandGrid()" style="margin-left:auto;background:none;border:1px solid rgba(255,255,255,.15);border-radius:6px;padding:2px 8px;font-size:11px;cursor:pointer;color:#aaa;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-table-cells-large"></i> Pick</button></label>' +
    '<input type="text" id="cpTableNumber" name="table_number" maxlength="10" placeholder="e.g. 1, 7, 12..." onblur="cpCheckStand(this.value)">' +
    '<div id="cpStandWarn" style="display:none;margin-top:6px;padding:7px 10px;background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.35);border-radius:8px;font-size:12px;color:#f0ad4e;align-items:center;gap:6px;"><i class="fa-solid fa-triangle-exclamation"></i> <span id="cpStandWarnText"></span></div>' +
    '<div id="cpStandGrid" style="display:none;margin-top:6px;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:10px;"></div>' +
    '</div>';

  itemsHtml += '</div>'; // /cp-summary

  if (body) body.innerHTML = itemsHtml;

  // Update total in footer
  var totalEl = document.getElementById('cpTotal');
  if (totalEl) totalEl.textContent = '$' + data.total;

  if (!ADD_TO_ORDER_MODE) {
    // Restore drink type button visuals
    cpSetDrinkType(prevDrinkType);
  }

  // Restore customer name and table number (live in cpBody, wiped on rebuild)
  var cnEl = document.getElementById('cpCustomerName');
  if (cnEl && prevCustomerName) cnEl.value = prevCustomerName;
  var tnEl = document.getElementById('cpTableNumber');
  if (tnEl && prevTableNumber) tnEl.value = prevTableNumber;
}

// ── CART ITEM OPERATIONS ──
function cpChangeQty(index, delta) {
  var inp = document.getElementById('cp-qty-' + index);
  if (!inp) return;
  var qty = Math.max(1, parseInt(inp.value) + delta);
  inp.value = qty;
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_update=1&index='+index+'&qty='+qty })
    .then(function(r) { return r.json(); })
    .then(function() { loadCartPanel(); });
}

function cpSetQty(index, val) {
  var qty = Math.max(1, parseInt(val) || 1);
  var inp = document.getElementById('cp-qty-' + index);
  if (inp) inp.value = qty;
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_update=1&index='+index+'&qty='+qty })
    .then(function(r) { return r.json(); })
    .then(function() { loadCartPanel(); });
}

function cpRemoveItem(index) {
  var row = document.getElementById('cp-item-' + index);
  if (row) { row.style.opacity='0'; row.style.transform='translateX(20px)'; row.style.transition='all .25s'; }
  setTimeout(function() {
    fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_remove=1&index='+index })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.remaining === 0) { loadCartPanel(); return; }
        loadCartPanel();
      });
  }, 250);
}

function cpClearCart() {
  if (!confirm('Remove all items from the cart?')) return;
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear=1' })
    .then(function() { loadCartPanel(); });
}

// ── REFRESH SUMMARY FROM AJAX RESPONSE (qty update) ──
function cpRefreshSummaryFromData(data) {
  var set = function(id, val) { var el = document.getElementById(id); if (el) el.textContent = val; };
  set('cpSubtotal', '$' + data.cartSubtotal);
  set('cpTax',      '$' + data.tax);

  var newTotal = '$' + data.cartTotal;
  set('cpTotal', newTotal); // footer total
  var st = document.getElementById('cpSubtotal'); // also inline

  var b3r = document.getElementById('cpBuy3Row');
  if (b3r) b3r.style.display = parseFloat(data.buy3_discount) > 0 ? '' : 'none';
  set('cpBuy3Amt', '-$' + (data.buy3_discount || '0.00'));

  var hhr = document.getElementById('cpHHRow');
  if (hhr) hhr.style.display = parseFloat(data.happy_hour_discount) > 0 ? '' : 'none';
  set('cpHHAmt', '-$' + (data.happy_hour_discount || '0.00'));

  var mdr = document.getElementById('cpManualRow');
  if (mdr) mdr.style.display = parseFloat(data.manual_discount) > 0 ? '' : 'none';
  set('cpManualAmt', '-$' + (data.manual_discount || '0.00'));

  cpCalcChange();
  cpUpdateSplitAmounts();
}

// ── DISCOUNT PANEL ──
var _cpDiscountType = 'percent';
function cpOpenDiscount() {
  var btn = document.getElementById('cpAddDiscBtn');
  if (btn) btn.style.display = 'none';
  var form = document.getElementById('cpDiscountForm');
  if (form) { form.style.display = 'block'; }
  var amtInput = document.getElementById('cpDiscAmount');
  if (amtInput) amtInput.focus();
}
function cpCloseDiscount() {
  var form = document.getElementById('cpDiscountForm');
  if (form) form.style.display = 'none';
  var btn = document.getElementById('cpAddDiscBtn');
  if (btn) btn.style.display = '';
}
function cpSetDType(type) {
  _cpDiscountType = type;
  var p = document.getElementById('cpDtypePercent'), f = document.getElementById('cpDtypeFlat');
  if (p) p.classList.toggle('active', type === 'percent');
  if (f) f.classList.toggle('active', type === 'flat');
  var inp = document.getElementById('cpDiscAmount');
  if (inp) inp.placeholder = type === 'percent' ? '0  (e.g. 10 = 10%)' : '0.00  (e.g. 5.00)';
}
function cpApplyDiscount() {
  var amount = parseFloat(document.getElementById('cpDiscAmount').value) || 0;
  var reason = document.getElementById('cpDiscReason').value.trim();
  if (amount <= 0) { alert('Please enter a discount amount.'); return; }
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'ajax_apply_discount=1&type='+encodeURIComponent(_cpDiscountType)+'&amount='+amount+'&reason='+encodeURIComponent(reason) })
    .then(function() { loadCartPanel(); });
}
function cpClearDiscount() {
  fetch('cart.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_clear_discount=1' })
    .then(function() { loadCartPanel(); });
}

// ── PAYMENT METHODS ──
function cpTogglePayment(label) {
  var cb = label.querySelector('input[type="checkbox"]');
  var value = cb.value;
  if (value === 'paylater' || value === 'riel') {
    document.querySelectorAll('.cp-pay-method input[type="checkbox"]').forEach(function(c) { c.checked = false; });
    cb.checked = true;
  } else {
    var pl = document.querySelector('.cp-pay-method input[value="paylater"]');
    if (pl && pl.checked) pl.checked = false;
    var rielCb = document.querySelector('.cp-pay-method input[value="riel"]');
    if (rielCb && rielCb.checked) rielCb.checked = false;
    cb.checked = !cb.checked;
  }
  if (value === 'riel' && !cb.checked) {
    var ri = document.getElementById('cpRielReceived');
    if (ri) ri.value = '';
  }
  document.querySelectorAll('.cp-pay-method').forEach(function(el) {
    el.classList.toggle('selected', el.querySelector('input[type="checkbox"]').checked);
  });
  var selected = cpGetSelected();
  cpUpdateConfirmBtn(selected);
  cpUpdateSplitInputs();
}

function cpGetSelected() {
  var sel = [];
  document.querySelectorAll('.cp-pay-method input[type="checkbox"]:checked').forEach(function(cb) { sel.push(cb.value); });
  return sel;
}

function cpGetCartTotal() {
  var el = document.getElementById('cpTotal');
  if (!el) return 0;
  return parseFloat(el.textContent.replace('$','').replace(/,/g,'')) || 0;
}

function cpUpdateConfirmBtn(selected) {
  // Updates the MODAL's Confirm Payment button — never the footer Confirm Order
  // button, which must always stay "Confirm Order" (it just opens the modal).
  var btn  = document.getElementById('cpConfirmPayBtn');
  var icon = document.getElementById('cpConfirmPayIcon');
  var text = document.getElementById('cpConfirmPayText');
  var cc   = document.getElementById('cpChangeCalc');
  var rc   = document.getElementById('cpRielCalc');
  if (!btn) return;

  btn.className = 'cp-pm-confirm';
  if (cc) cc.classList.remove('visible');
  if (rc) rc.classList.remove('visible');

  if (selected.includes('paylater')) {
    if (icon) icon.className = 'fa-solid fa-clock';
    if (text) text.textContent = 'Place Pay Later Order';
    btn.classList.add('paylater');
  } else if (selected.length > 1) {
    if (icon) icon.className = 'fa-solid fa-layer-group';
    if (text) text.textContent = 'Confirm Split Payment';
    btn.classList.add('split');
    if (selected.includes('cash') && cc) {
      cc.classList.add('visible');
      setTimeout(function() { var cr = document.getElementById('cpCashReceived'); if (cr) cr.focus(); }, 50);
    }
  } else if (selected.includes('riel')) {
    if (icon) icon.className = 'fa-solid fa-coins';
    if (text) text.textContent = 'Confirm Riel Payment';
    btn.classList.add('riel');
    if (rc) {
      rc.classList.add('visible');
      var total = cpGetCartTotal();
      var ri = document.getElementById('cpRielReceived');
      if (ri && !ri.value) ri.value = Math.round(total * CP_KHR_RATE / 100) * 100;
      cpCalcRielChange();
      setTimeout(function() { if (ri) ri.focus(); }, 50);
    }
  } else if (selected.includes('cash')) {
    if (icon) icon.className = 'fa-solid fa-money-bill-wave';
    if (text) text.textContent = 'Confirm Cash Payment';
    btn.classList.add('cash');
    if (cc) cc.classList.add('visible');
    setTimeout(function() { var cr = document.getElementById('cpCashReceived'); if (cr) cr.focus(); }, 50);
  } else if (selected.includes('bakong')) {
    if (icon) icon.className = 'fa-solid fa-qrcode';
    if (text) text.textContent = 'Generate Bakong QR';
    btn.classList.add('bakong');
  } else {
    if (icon) icon.className = 'fa-solid fa-check';
    if (text) text.textContent = 'Confirm Payment';
  }
}

// ── PAYMENT MODAL OPEN/CLOSE ──
function cpOpenPayModal() {
  var total = cpGetCartTotal();
  if (total <= 0) return; // empty cart guard
  var sub = total / (1 + CP_TAX_RATE / 100);
  var tax = total - sub;
  document.getElementById('cpPmSubtotal').textContent = '$' + sub.toFixed(2);
  document.getElementById('cpPmTax').textContent = '$' + tax.toFixed(2);
  document.getElementById('cpPmTotal').textContent = '$' + total.toFixed(2);
  var khr = Math.round(total * CP_KHR_RATE / 100) * 100;
  var khrEl = document.getElementById('cpPmKhr');
  if (khrEl) khrEl.textContent = '៛ ' + khr.toLocaleString();
  document.getElementById('cpPayModal').classList.add('active');
}
function cpClosePayModal() {
  document.getElementById('cpPayModal').classList.remove('active');
  // Abandoning the modal clears payment selection so the next order starts
  // clean (no stale method carried over). Submitting navigates away instead,
  // so this only runs on Esc / backdrop / X.
  document.querySelectorAll('.cp-pay-method input[type="checkbox"]').forEach(function(c) {
    c.checked = false;
    c.closest('.cp-pay-method').classList.remove('selected');
  });
  var cr = document.getElementById('cpCashReceived'); if (cr) cr.value = '';
  var ri = document.getElementById('cpRielReceived'); if (ri) ri.value = '';
  cpUpdateConfirmBtn([]);
  cpUpdateSplitInputs();
}
function cpOnConfirmOrderClick() {
  if (typeof ADD_TO_ORDER_MODE !== 'undefined' && ADD_TO_ORDER_MODE) {
    // add-to-order has no payment step — submit directly
    document.getElementById('cpCheckoutForm').requestSubmit();
    return;
  }
  cpOpenPayModal();
}

// ── SPLIT PAYMENT ──
function cpInputToUsd(inp) {
  var val = Math.max(0, parseFloat(inp.value) || 0);
  return inp.dataset.currency === 'khr' ? val / CP_KHR_RATE : val;
}
function cpSetInputUsd(inp, usd) {
  if (inp.dataset.currency === 'khr') {
    inp.value = Math.round(usd * CP_KHR_RATE / 100) * 100;
    var d = inp.parentElement && inp.parentElement.querySelector('.cp-khr-usd');
    if (d) d.textContent = '≈ $' + usd.toFixed(2);
  } else {
    inp.value = usd.toFixed(2);
  }
}

function cpUpdateSplitInputs() {
  var selected = cpGetSelected();
  var si = document.getElementById('cpSplitInputs');
  var sr = document.getElementById('cpSplitRows');
  if (!si || !sr) return;
  if (selected.includes('paylater') || selected.length <= 1) { si.classList.remove('active'); sr.innerHTML = ''; return; }
  si.classList.add('active');
  var total = cpGetCartTotal();
  var each  = Math.floor((total / selected.length) * 100) / 100;
  var rem   = Math.round((total - each * selected.length) * 100) / 100;
  var html  = '';
  selected.forEach(function(m, i) {
    var usd = i === selected.length - 1 ? (each + rem).toFixed(2) : each.toFixed(2);
    if (m === 'riel') {
      var khr = Math.round(parseFloat(usd) * CP_KHR_RATE / 100) * 100;
      html += '<div class="cp-split-row"><label>Riel &#x17DB;</label>' +
        '<div style="display:flex;flex-direction:column;gap:3px;flex:1;">' +
        '<input type="number" step="1" class="cp-split-amount" value="' + khr + '" data-method="riel" data-currency="khr" oninput="cpOnSplitChange(this)">' +
        '<span class="cp-khr-usd" style="font-size:11px;color:#888;">≈ $' + usd + '</span>' +
        '</div></div>';
    } else {
      var lbl = m.charAt(0).toUpperCase() + m.slice(1);
      html += '<div class="cp-split-row"><label>' + lbl + '</label><input type="number" step="0.01" class="cp-split-amount" value="' + usd + '" data-method="' + m + '" oninput="cpOnSplitChange(this)"></div>';
    }
  });
  sr.innerHTML = html;
}

function cpUpdateSplitAmounts() {
  var selected = cpGetSelected();
  if (selected.length < 2) return;
  var total = cpGetCartTotal();
  var each  = Math.floor((total / selected.length) * 100) / 100;
  var rem   = Math.round((total - each * selected.length) * 100) / 100;
  var inputs = document.querySelectorAll('.cp-split-amount');
  inputs.forEach(function(inp, i) {
    cpSetInputUsd(inp, i === inputs.length - 1 ? each + rem : each);
  });
}

function cpOnSplitChange(changedInp) {
  var total = cpGetCartTotal();
  var inputs = Array.from(document.querySelectorAll('.cp-split-amount'));
  var changedUsd = cpInputToUsd(changedInp);
  if (changedInp.dataset.currency === 'khr') {
    var d = changedInp.parentElement && changedInp.parentElement.querySelector('.cp-khr-usd');
    if (d) d.textContent = '≈ $' + changedUsd.toFixed(2);
  }
  var others = inputs.filter(function(inp) { return inp !== changedInp; });
  if (others.length === 1) {
    var remaining = total - changedUsd;
    if (remaining < 0) { cpSetInputUsd(changedInp, total); cpSetInputUsd(others[0], 0); }
    else cpSetInputUsd(others[0], remaining);
  }
}

// ── CHANGE CALCULATOR ──
function cpCalcChange() {
  var received = parseFloat(document.getElementById('cpCashReceived')?.value) || 0;
  var total    = cpGetCartTotal();
  var change   = received - total;
  var el       = document.getElementById('cpChangeAmount');
  if (!el) return;
  if (received === 0) { el.textContent = '$0.00'; el.className = 'change-amount'; return; }
  if (change < 0) { el.textContent = 'Need $' + Math.abs(change).toFixed(2) + ' more'; el.className = 'change-amount not-enough'; }
  else            { el.textContent = '$' + change.toFixed(2); el.className = 'change-amount'; }
}

function cpCalcRielChange() {
  var khr       = parseFloat(document.getElementById('cpRielReceived')?.value) || 0;
  var usdEl     = document.getElementById('cpRielUsdEquiv');
  var changeRow = document.getElementById('cpRielChangeRow');
  var changeEl  = document.getElementById('cpRielChangeKhr');
  if (usdEl) usdEl.textContent = '$' + (khr / CP_KHR_RATE).toFixed(2);
  if (changeRow && changeEl) {
    var orderKhr = Math.round(cpGetCartTotal() * CP_KHR_RATE / 100) * 100;
    var diff     = Math.round(khr) - orderKhr;
    if (khr > 0 && diff < 0) {
      changeEl.textContent = 'Need ៛' + Math.abs(diff).toLocaleString();
      changeEl.className   = 'change-amount not-enough';
      changeRow.style.display = 'flex';
    } else if (khr > 0 && diff >= 0) {
      changeEl.textContent = '៛' + diff.toLocaleString();
      changeEl.className   = 'change-amount';
      changeRow.style.display = 'flex';
    } else {
      changeRow.style.display = 'none';
    }
  }
}

// ── ORDER TYPE ──
function cpSetDrinkType(type) {
  var inp = document.getElementById('cpOrderTypeInput');
  if (inp) inp.value = type;
  var din  = document.getElementById('cpBtnDrinkIn');
  var dout = document.getElementById('cpBtnDrinkOut');
  if (din)  din.classList.toggle('active',  type === 'drink_in');
  if (dout) dout.classList.toggle('active', type === 'drink_out');
  var tg = document.getElementById('cpTableNumberGroup');
  if (tg) tg.style.display = (type === 'drink_in') ? '' : 'none';
  if (type === 'drink_out') {
    var tf = document.getElementById('cpTableNumber');
    if (tf) tf.value = '';
  }
}

// ── CHECKOUT FORM SUBMIT ──
document.addEventListener('DOMContentLoaded', function() {
  var form = document.getElementById('cpCheckoutForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var selected = cpGetSelected();
      if (selected.length === 0 && !ADD_TO_ORDER_MODE) { alert('Please select a payment method.'); return; }
      if (ADD_TO_ORDER_MODE) selected = ['paylater'];

      // Sync customer name to hidden input in form
      var nameInput = document.getElementById('cpCustomerName');
      var existingName = form.querySelector('input[name="customer_name"]');
      if (!existingName) {
        existingName = document.createElement('input');
        existingName.type = 'hidden'; existingName.name = 'customer_name';
        form.appendChild(existingName);
      }
      existingName.value = nameInput ? nameInput.value : '';

      // Sync table number to hidden input in form
      var tableInput = document.getElementById('cpTableNumber');
      var existingTable = form.querySelector('input[name="table_number"]');
      if (!existingTable) {
        existingTable = document.createElement('input');
        existingTable.type = 'hidden'; existingTable.name = 'table_number';
        form.appendChild(existingTable);
      }
      existingTable.value = (tableInput && document.getElementById('cpOrderTypeInput').value === 'drink_in') ? tableInput.value : '';

      var total = cpGetCartTotal();
      var splits = document.querySelectorAll('.cp-split-amount');
      var selectedAmounts = [];

      if (splits.length > 0 && document.getElementById('cpSplitInputs') && document.getElementById('cpSplitInputs').classList.contains('active')) {
        // Sum in USD (convert KHR inputs)
        var sumUsd = 0;
        splits.forEach(function(inp) { sumUsd += cpInputToUsd(inp); });
        if (Math.abs(sumUsd - total) > 0.005) {
          var last = splits[splits.length - 1];
          var diff = total - sumUsd;
          if (last.dataset.currency === 'khr') {
            last.value = Math.max(0, Math.round(((parseFloat(last.value) || 0) + diff * CP_KHR_RATE) / 100) * 100);
          } else {
            last.value = Math.max(0, parseFloat(last.value) + diff).toFixed(2);
          }
        }
        splits.forEach(function(inp) { selectedAmounts.push(parseFloat(inp.value).toFixed(2)); });
      } else {
        selected.forEach(function() { selectedAmounts.push(total.toFixed(2)); });
      }

      var container = document.getElementById('cpPaymentInputs');
      container.innerHTML = '';
      selected.forEach(function(method, i) {
        var usdAmount = selectedAmounts[i] || '0';
        var reference = '';
        if (method === 'riel') {
          var khrInput = selected.length > 1
            ? document.querySelector('.cp-split-amount[data-method="riel"]')
            : document.getElementById('cpRielReceived');
          var khrVal = Math.max(0, parseFloat(khrInput ? khrInput.value : 0) || 0);
          usdAmount = (khrVal / CP_KHR_RATE).toFixed(2);
          reference = Math.round(khrVal).toString();
        }
        if (method === 'cash') {
          var received = parseFloat((document.getElementById('cpCashReceived') || {}).value) || 0;
          if (received > 0) reference = received.toFixed(2);
        }
        var h1 = document.createElement('input'); h1.type='hidden'; h1.name='payment_methods[]'; h1.value=method; container.appendChild(h1);
        var h2 = document.createElement('input'); h2.type='hidden'; h2.name='payment_amounts[]'; h2.value=usdAmount; container.appendChild(h2);
        var h3 = document.createElement('input'); h3.type='hidden'; h3.name='payment_references[]'; h3.value=reference; container.appendChild(h3);
      });
      form.submit();
    });
  }

  // Confirm Payment button (inside modal) triggers the form submit handler above
  var confirmPayBtn = document.getElementById('cpConfirmPayBtn');
  if (confirmPayBtn) {
    confirmPayBtn.addEventListener('click', function() {
      document.getElementById('cpCheckoutForm').requestSubmit();
    });
  }

  // Payment modal: backdrop + Esc close
  var payModal = document.getElementById('cpPayModal');
  if (payModal) {
    payModal.addEventListener('click', function(e) {
      if (e.target === this) cpClosePayModal();
    });
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('cpPayModal').classList.contains('active')) {
      cpClosePayModal();
    }
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    var tag = document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (typeof ADD_TO_ORDER_MODE !== 'undefined' && ADD_TO_ORDER_MODE) {
      if (e.key.toLowerCase() === 'enter') { e.preventDefault(); cpOnConfirmOrderClick(); }
      return;
    }
    var modalOpen = document.getElementById('cpPayModal').classList.contains('active');
    var key = e.key.toLowerCase();
    if (['b','c','p','r'].includes(key)) {
      e.preventDefault();
      if (!modalOpen) cpOpenPayModal();
      var map = { b:'bakong', c:'cash', p:'paylater', r:'riel' };
      cpClickPayMethod(map[key]);
    } else if (key === 'enter') {
      e.preventDefault();
      if (modalOpen) document.getElementById('cpConfirmPayBtn').click();
      else cpOpenPayModal();
    } else if (key === 'escape') {
      if (document.getElementById('cpPayModal').classList.contains('active')) return;
      closeModal();
    }
  });

  // Sort on change
  var sortSelect = document.getElementById('sortSelect');
  if (sortSelect) sortSelect.addEventListener('change', function() { document.getElementById('searchForm').submit(); });

  // Modal backdrop close
  var modal = document.getElementById('modal');
  if (modal) modal.addEventListener('click', function(e) { if (e.target === this) closeModal(); });

  // Wire product cards
  document.querySelectorAll('.js-open-product').forEach(function(card) {
    var handler = function() { openModalFromCard(card); };
    card.addEventListener('click', handler);
    card.addEventListener('keydown', function(e) { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); handler(); } });
  });

  // Scrollspy
  var catSections = document.querySelectorAll('.cat-section');
  var catPills    = document.querySelectorAll('.cat-pill[data-target]');
  if (catSections.length && catPills.length) {
    var menuScroll = document.getElementById('menuScroll');
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          catPills.forEach(function(pill) { pill.classList.toggle('active', pill.dataset.target === id); });
        }
      });
    }, { threshold: 0.25, root: menuScroll, rootMargin: '-60px 0px -55% 0px' });
    catSections.forEach(function(s) { observer.observe(s); });
  }

  // Category pill smooth scroll (within menu panel)
  catPills.forEach(function(pill) {
    pill.addEventListener('click', function(e) {
      e.preventDefault();
      var target = document.getElementById(this.dataset.target);
      var scrollEl = document.getElementById('menuScroll');
      if (target && scrollEl) {
        var offset = 60;
        var top = target.offsetTop - offset;
        scrollEl.scrollTo({ top: top, behavior: 'smooth' });
      }
    });
  });

  // Chat enter key
  var chatInput = document.getElementById('chatInput');
  if (chatInput) chatInput.addEventListener('keypress', function(e) { if (e.key==='Enter') sendChat(); });
});

function cpClickPayMethod(method) {
  var el = document.querySelector('.cp-pay-method input[value="' + method + '"]');
  if (el) el.closest('.cp-pay-method').click();
}

// ── TOAST ──
function showToast(message, type) {
  type = type || 'success';
  var container = document.getElementById('toast-container');
  var toast = document.createElement('div');
  toast.className = 'toast ' + type;
  var icon = type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation';
  toast.innerHTML = '<i class="fa-solid ' + icon + '"></i><span>' + message + '</span>';
  container.appendChild(toast);
  requestAnimationFrame(function() { toast.classList.add('show'); });
  setTimeout(function() { toast.classList.remove('show'); setTimeout(function() { toast.remove(); }, 350); }, 2800);
}

// ── CHAT ──
function toggleChat() {
  var box = document.getElementById('chatBox');
  var isOpen = box.style.display === 'flex';
  box.style.display = isOpen ? 'none' : 'flex';
  if (!isOpen) document.getElementById('chatInput').focus();
}

function sendChat() {
  var input   = document.getElementById('chatInput');
  var msg     = input.value.trim();
  if (!msg) return;
  var chat    = document.getElementById('chatMessages');
  var sendBtn = document.getElementById('chatSendBtn');
  var userMsg = document.createElement('div');
  userMsg.className = 'msg-user';
  userMsg.innerHTML = '<div class="bubble">' + msg + '</div><div class="avatar"><i class="fa-solid fa-user"></i></div>';
  chat.appendChild(userMsg);
  chat.scrollTop = chat.scrollHeight;
  input.value = ''; sendBtn.disabled = true;
  fetch('chatbot.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'message='+encodeURIComponent(msg) })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var botMsg = document.createElement('div');
      botMsg.className = 'msg-bot';
      botMsg.innerHTML = '<div class="avatar"><i class="fa-solid fa-robot"></i></div><div class="bubble">' + (data.reply||'Sorry, I did not catch that.') + '</div>';
      chat.appendChild(botMsg);
      chat.scrollTop = chat.scrollHeight;
    })
    .catch(function() {})
    .finally(function() { sendBtn.disabled = false; });
}

// ── LOYALTY MODAL ──
function openLoyaltyModal() {
  document.getElementById('loyaltyModal').style.display = 'flex';
  setTimeout(function() { document.getElementById('loyaltyIdInput').focus(); }, 100);
}
function closeLoyaltyModal() {
  document.getElementById('loyaltyModal').style.display = 'none';
  document.getElementById('loyaltyResult').style.display = 'none';
  document.getElementById('loyaltyError').style.display = 'none';
}

async function lookupLoyalty() {
  var loyaltyId = document.getElementById('loyaltyIdInput').value.trim();
  if (!loyaltyId) { showToast('Please enter a loyalty ID', 'error'); return; }
  try {
    var res  = await fetch('loyalty_lookup.php?loyalty_id=' + encodeURIComponent(loyaltyId));
    var data = await res.json();
    if (!data.found) {
      document.getElementById('loyaltyResult').style.display = 'none';
      document.getElementById('loyaltyError').style.display = 'block';
      return;
    }
    document.getElementById('loyaltyError').style.display  = 'none';
    document.getElementById('loyaltyResult').style.display = 'block';
    document.getElementById('loyaltyDisplayId').textContent = data.loyalty_id;
    document.getElementById('loyaltyPoints').textContent    = data.points;

    var rewardsHtml = data.rewards.map(function(reward) {
      var can = data.points >= reward.points_required;
      return '<div style="padding:10px;border-radius:7px;border:1px solid ' + (can?'#d1904b':'var(--border,#e0d4c4)') + ';text-align:center;background:' + (can?'rgba(209,144,75,.08)':'rgba(0,0,0,.02)') + ';">' +
        '<div style="font-weight:600;color:var(--text,#1a1410);font-size:12px;">' + escH(reward.reward_name) + '</div>' +
        '<div style="font-size:11px;color:var(--text-sec,#5a4a3a);">' + reward.points_required + ' pts</div>' +
        (can ? '<button onclick="redeemReward(\'' + escH(reward.reward_name) + '\',' + reward.points_required + ')" style="margin-top:4px;padding:3px 10px;border-radius:50px;border:none;background:#d1904b;color:#000;font-weight:600;font-size:10px;cursor:pointer;font-family:\'Poppins\',sans-serif;">Redeem</button>'
             : '<button disabled style="margin-top:4px;padding:3px 10px;border-radius:50px;border:none;background:#ccc;color:#666;font-weight:600;font-size:10px;cursor:not-allowed;font-family:\'Poppins\',sans-serif;">Need ' + reward.points_required + ' pts</button>') +
      '</div>';
    }).join('');
    document.getElementById('loyaltyRewards').innerHTML = rewardsHtml;

    var historyHtml = data.history.map(function(h) {
      var sign = h.points_change > 0 ? '+' : '';
      var color = h.points_change > 0 ? '#55e087' : '#e74c3c';
      return '<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border,#e0d4c4);">' +
        '<span style="color:var(--text-sec,#5a4a3a);">' + h.type.charAt(0).toUpperCase()+h.type.slice(1) + (h.reward_name?' - '+h.reward_name:'') + '</span>' +
        '<span style="color:'+color+';font-weight:600;">' + sign + h.points_change + '</span></div>';
    }).join('');
    document.getElementById('loyaltyHistory').innerHTML = historyHtml || '<div style="text-align:center;color:var(--text-muted,#9a8070);">No history yet</div>';

    // Update cart panel loyalty status
    var statusEl = document.getElementById('cpLoyaltyStatus');
    var btnEl    = document.getElementById('cpLoyaltyBtn');
    if (statusEl) { statusEl.className = 'linked'; statusEl.innerHTML = data.loyalty_id + ' &mdash; ' + data.points + ' pts'; }
    if (btnEl)    btnEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Linked';
  } catch(err) { showToast('Error looking up loyalty card', 'error'); }
}

async function redeemReward(rewardName, pointsRequired) {
  var loyaltyId = document.getElementById('loyaltyDisplayId').textContent;
  if (!confirm('Redeem ' + rewardName + ' for ' + pointsRequired + ' points?')) return;
  try {
    var res  = await fetch('loyalty_redeem.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'loyalty_id='+encodeURIComponent(loyaltyId)+'&reward_name='+encodeURIComponent(rewardName) });
    var data = await res.json();
    if (data.success) {
      document.getElementById('loyaltyPoints').textContent = data.new_points;
      showToast('✅ ' + data.message, 'success');
    } else { showToast(data.message || 'Error redeeming reward', 'error'); }
  } catch(e) { showToast('Error redeeming reward', 'error'); }
}

// ── Stand number picker ──
function cpToggleStandGrid() {
  var grid = document.getElementById('cpStandGrid');
  if (!grid) return;
  if (grid.style.display !== 'none') { grid.style.display = 'none'; return; }
  grid.innerHTML = '<div style="text-align:center;padding:10px;color:#888;font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
  grid.style.display = 'block';
  fetch('get_stands.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var active = data.stands || {};
      var cells = '';
      for (var i = 1; i <= CP_STAND_MAX; i++) {
        var key = String(i);
        var info = active[key];
        if (info) {
          var tip = 'Order #' + info.order_no + (info.customer ? ' (' + info.customer + ')' : '') + ' — ' + info.status;
          cells += '<div title="' + tip.replace(/"/g,'&quot;') + '" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:not-allowed;background:rgba(231,76,60,.18);color:#ff6b6b;border:1px solid rgba(231,76,60,.35);position:relative;">' + i + '<span style="position:absolute;top:-3px;right:-3px;width:8px;height:8px;border-radius:50%;background:#ef4444;border:1px solid #1a1a1a;"></span></div>';
        } else {
          cells += '<div onclick="cpPickStand(' + i + ')" style="display:flex;align-items:center;justify-content:center;height:36px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;background:rgba(62,207,112,.15);color:#3ecf70;border:1px solid rgba(62,207,112,.3);transition:transform .1s;" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'\'">' + i + '</div>';
        }
      }
      grid.innerHTML =
        '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;">' + cells + '</div>' +
        '<div style="margin-top:8px;font-size:10px;color:#666;display:flex;gap:12px;">' +
        '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(62,207,112,.15);border:1px solid rgba(62,207,112,.3);vertical-align:middle;margin-right:3px;"></span>Free</span>' +
        '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(231,76,60,.18);border:1px solid rgba(231,76,60,.35);vertical-align:middle;margin-right:3px;"></span>In use</span>' +
        '</div>';
    })
    .catch(function() {
      grid.innerHTML = '<div style="text-align:center;padding:10px;color:#ef4444;font-size:12px;">Could not load stands</div>';
    });
}

function cpPickStand(num) {
  var inp = document.getElementById('cpTableNumber');
  if (inp) { inp.value = num; cpCheckStand(String(num)); }
  var grid = document.getElementById('cpStandGrid');
  if (grid) grid.style.display = 'none';
}

function cpCheckStand(val) {
  var warn = document.getElementById('cpStandWarn');
  if (!warn) return;
  val = (val || '').trim();
  if (!val) { warn.style.display = 'none'; return; }
  fetch('check_stand.php?stand=' + encodeURIComponent(val))
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.in_use) {
        document.getElementById('cpStandWarnText').textContent =
          'Stand ' + val + ' is in use by Order #' + data.order_no +
          (data.customer ? ' (' + data.customer + ')' : '') + ' – ' + data.status;
        warn.style.display = 'flex';
      } else {
        warn.style.display = 'none';
      }
    })
    .catch(function() { warn.style.display = 'none'; });
}

</script>
</body>
</html>
