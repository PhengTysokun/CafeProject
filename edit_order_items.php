<?php
require 'auth.php';
if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) { header("Location: dashboard.php?denied=1"); exit; }

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) { header("Location: find_order.php"); exit; }

// ── AJAX: Save changes ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');

    $remove_ids = [];
    if (!empty($_POST['remove_ids'])) {
        $remove_ids = array_filter(array_map('intval', explode(',', $_POST['remove_ids'])));
    }
    $qtys = isset($_POST['qtys']) ? json_decode($_POST['qtys'], true) : [];

    // Verify order is still editable and fetch order_date to preserve happy hour
    $stmt = $conn->prepare("SELECT order_id, order_date FROM orders WHERE order_id = ? AND payment_method = 'paylater' AND status = 'Preparing'");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $editable_order = $stmt->get_result()->fetch_assoc();
    if (!$editable_order) {
        echo json_encode(['success' => false, 'error' => 'Order is no longer editable.']);
        exit;
    }
    // Determine if the order was placed during happy hour
    $orig_hour        = (int)date('H', strtotime($editable_order['order_date']));
    $was_happy_hour   = ($orig_hour >= HAPPY_HOUR_START && $orig_hour < HAPPY_HOUR_END);

    $conn->begin_transaction();
    try {
        // Delete removed items
        if (!empty($remove_ids)) {
            $stmt_del = $conn->prepare("DELETE FROM order_items WHERE item_id = ? AND order_id = ?");
            foreach ($remove_ids as $item_id) {
                $stmt_del->bind_param("ii", $item_id, $order_id);
                $stmt_del->execute();
            }
        }

        // Update quantities
        if (!empty($qtys)) {
            $stmt_qty = $conn->prepare("UPDATE order_items SET quantity = ? WHERE item_id = ? AND order_id = ?");
            foreach ($qtys as $item_id => $qty) {
                $item_id = (int)$item_id;
                $qty     = max(1, (int)$qty);
                if (!in_array($item_id, $remove_ids)) {
                    $stmt_qty->bind_param("iii", $qty, $item_id, $order_id);
                    $stmt_qty->execute();
                }
            }
        }

        // Recalculate totals from remaining items
        $stmt_r = $conn->prepare("SELECT price, quantity FROM order_items WHERE order_id = ?");
        $stmt_r->bind_param("i", $order_id);
        $stmt_r->execute();
        $remaining = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($remaining)) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => 'Cannot remove all items. Cancel the order instead.']);
            exit;
        }

        $subtotal  = 0; $total_qty = 0; $min_price = PHP_FLOAT_MAX;
        foreach ($remaining as $row) {
            $p = (float)$row['price']; $q = (int)$row['quantity'];
            $subtotal  += $p * $q;
            $total_qty += $q;
            if ($p < $min_price) $min_price = $p;
        }

        $buy3 = 0;
        if (BUY_X_GET_1_ENABLED && $total_qty >= BUY_X_COUNT && $min_price < PHP_FLOAT_MAX) {
            $buy3 = floor($total_qty / BUY_X_COUNT) * $min_price;
        }
        // Preserve happy hour if the order was originally placed during happy hour
        $happy_hour = 0;
        if ($was_happy_hour && HAPPY_HOUR_ENABLED) {
            $happy_hour = ($subtotal - $buy3) * (HAPPY_HOUR_DISCOUNT / 100);
        }
        $total_discount = $buy3 + $happy_hour;
        $after  = $subtotal - $total_discount;
        $tax    = $after * (TAX_RATE / 100);
        $total  = round($after + $tax, 2);

        $stmt_upd = $conn->prepare("UPDATE orders SET total = ?, promotion_discount = ? WHERE order_id = ?");
        $stmt_upd->bind_param("ddi", $total, $total_discount, $order_id);
        $stmt_upd->execute();

        $conn->commit();
        echo json_encode([
            'success'     => true,
            'total'       => number_format($total, 2),
            'subtotal'    => number_format($subtotal, 2),
            'discount'    => number_format($total_discount, 2),
            'tax'         => number_format($tax, 2),
            'items_left'  => count($remaining),
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Fetch order ──
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, token_number, promotion_discount, order_date
    FROM orders
    WHERE order_id = ? AND payment_method = 'paylater' AND status = 'Preparing'
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) { header("Location: find_order.php"); exit; }

// ── Fetch items ──
$stmt = $conn->prepare("
    SELECT item_id, product_name, price, quantity, sweetness, ice, milk
    FROM order_items WHERE order_id = ? ORDER BY item_id ASC
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Order #<?= $order['daily_order_no'] ?> | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --bg: #0c0c0c; --bg-card: #131313; --bg-input: #1a1a1a;
    --border: #222; --border-hover: #2e2e2e;
    --accent: #d1904b; --accent-light: #e8b87a; --accent-dark: #a0702a;
    --text: #f0f0f0; --text-muted: #777; --text-light: #fff;
    --success: #55e087; --danger: #ff5f5f; --purple: #9b59b6;
    --shadow-sm: 0 2px 8px rgba(0,0,0,.35); --shadow-md: 0 4px 20px rgba(0,0,0,.45);
    --shadow-accent: 0 4px 20px rgba(209,144,75,.18);
    --radius: 14px; --transition: all .25s cubic-bezier(.4,0,.2,1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 28px 20px; }
::-webkit-scrollbar { width: 5px; } ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

.page { max-width: 980px; margin: 0 auto; }

/* Top bar */
.top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; flex-wrap: wrap; gap: 10px; }
.btn-back { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 50px; background: var(--bg-card); border: 1px solid var(--border); color: var(--text-muted); text-decoration: none; font-size: 13px; font-weight: 500; transition: var(--transition); }
.btn-back:hover { border-color: var(--accent); color: var(--accent); }
.btn-back i { color: var(--accent); }

/* Order header */
.order-header { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 22px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; box-shadow: var(--shadow-sm); }
.order-meta { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; }
.meta-item { display: flex; flex-direction: column; }
.meta-label { font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
.meta-value { font-size: 16px; font-weight: 700; color: var(--text-light); margin-top: 2px; }
.meta-value.accent { color: var(--accent); }
.meta-value.purple { color: var(--purple); }
.paylater-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 50px; background: rgba(155,89,182,.12); color: var(--purple); border: 1px solid rgba(155,89,182,.25); font-size: 12px; font-weight: 600; }

/* Layout */
.layout { display: grid; grid-template-columns: 1fr 300px; gap: 20px; align-items: start; }

/* Items panel */
.panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow-sm); }
.panel-title { font-size: 15px; font-weight: 700; color: var(--text-light); margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 9px; }
.panel-title i { color: var(--accent); }

/* Item row */
.item-row { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; transition: var(--transition); position: relative; display: flex; gap: 14px; align-items: center; }
.item-row:last-child { margin-bottom: 0; }
.item-row:hover { border-color: var(--border-hover); background: rgba(255,255,255,.02); }
.item-row.removed { opacity: .45; border-color: rgba(255,95,95,.25); background: rgba(255,95,95,.03); }
.item-row.removed .item-name { text-decoration: line-through; color: var(--text-muted); }

.item-num { font-size: 12px; color: var(--text-muted); font-weight: 600; min-width: 20px; }
.item-info { flex: 1; min-width: 0; }
.item-name { font-size: 14px; font-weight: 600; color: var(--text-light); margin-bottom: 3px; }
.item-custom { font-size: 11px; color: var(--text-muted); }
.item-unit-price { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

.item-controls { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

/* Qty control */
.qty-control { display: flex; align-items: center; background: var(--bg-input); border: 1px solid var(--border); border-radius: 50px; overflow: hidden; }
.qty-control button { width: 28px; height: 28px; background: none; border: none; color: var(--accent); font-size: 15px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; }
.qty-control button:hover { background: rgba(209,144,75,.15); }
.qty-control button:disabled { opacity: .3; cursor: default; }
.qty-control input[type="number"] { width: 34px; text-align: center; font-weight: 600; font-size: 13px; background: transparent; border: none; outline: none; color: var(--text); font-family: 'Poppins', sans-serif; -moz-appearance: textfield; }
.qty-control input::-webkit-inner-spin-button, .qty-control input::-webkit-outer-spin-button { -webkit-appearance: none; }

.item-line-total { font-size: 14px; font-weight: 600; color: var(--accent); min-width: 52px; text-align: right; }
.item-row.removed .item-line-total { color: var(--text-muted); }

/* Remove / undo buttons */
.btn-remove { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(255,95,95,.25); background: rgba(255,95,95,.08); color: var(--danger); cursor: pointer; transition: var(--transition); font-size: 13px; }
.btn-remove:hover { background: var(--danger); color: #fff; border-color: var(--danger); transform: scale(1.1); }
.btn-undo { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 1px solid rgba(85,224,135,.25); background: rgba(85,224,135,.08); color: var(--success); cursor: pointer; transition: var(--transition); font-size: 13px; }
.btn-undo:hover { background: var(--success); color: #000; border-color: var(--success); transform: scale(1.1); }

.removed-badge { position: absolute; top: -8px; right: 10px; background: var(--danger); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 8px; border-radius: 20px; letter-spacing: .5px; text-transform: uppercase; }

/* Empty state */
.empty-items { text-align: center; padding: 40px 20px; color: var(--text-muted); }
.empty-items i { font-size: 36px; margin-bottom: 10px; display: block; }

/* Summary panel */
.summary-panel { position: sticky; top: 20px; }
.summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; color: var(--text-muted); border-bottom: 1px solid var(--border); }
.summary-row:last-of-type { border-bottom: none; }
.summary-row.discount { color: var(--success); }
.summary-row.total-row { padding-top: 12px; font-size: 18px; font-weight: 700; color: var(--text-light); }
.summary-row.total-row span:last-child { color: var(--accent); }
.discount-row { display: none; }
.discount-row.visible { display: flex; }

/* Save button */
.btn-save { width: 100%; margin-top: 18px; padding: 13px; background: var(--accent); border: none; border-radius: 10px; color: #000; font-weight: 700; font-size: 15px; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 8px; font-family: 'Poppins', sans-serif; }
.btn-save:hover:not(:disabled) { background: var(--accent-light); transform: translateY(-2px); box-shadow: var(--shadow-accent); }
.btn-save:disabled { opacity: .5; cursor: not-allowed; transform: none; }

.change-hint { margin-top: 10px; font-size: 11px; color: var(--text-muted); text-align: center; display: none; }
.change-hint.visible { display: block; }
.change-hint.has-changes { color: var(--accent); }

/* Toast */
#toast { position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(20px); background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 12px 22px; font-size: 13px; font-weight: 600; box-shadow: var(--shadow-md); opacity: 0; transition: all .3s ease; pointer-events: none; display: flex; align-items: center; gap: 8px; min-width: 220px; justify-content: center; z-index: 9999; }
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
#toast.success { border-color: rgba(85,224,135,.35); color: var(--success); }
#toast.error   { border-color: rgba(255,95,95,.35);  color: var(--danger); }

@media (max-width: 720px) {
    .layout { grid-template-columns: 1fr; }
    .summary-panel { position: static; }
    .order-meta { gap: 16px; }
}
</style>
</head>
<body>

<div class="page">

    <!-- Top bar -->
    <div class="top-bar">
        <a href="find_order.php?tab=paylater" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Orders
        </a>
        <span style="font-size:13px;color:var(--text-muted);">
            <i class="fa-solid fa-pen-to-square" style="color:var(--accent);margin-right:6px;"></i>
            Editing Pay Later Order
        </span>
    </div>

    <!-- Order header -->
    <div class="order-header">
        <div class="order-meta">
            <div class="meta-item">
                <span class="meta-label">Order</span>
                <span class="meta-value accent">#<?= (int)$order['daily_order_no'] ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Customer</span>
                <span class="meta-value"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Current Total</span>
                <span class="meta-value accent" id="headerTotal">$<?= number_format($order['total'], 2) ?></span>
            </div>
        </div>
        <div class="paylater-badge">
            <i class="fa-solid fa-clock"></i> Pay Later
        </div>
    </div>

    <div class="layout">

        <!-- Items list -->
        <div class="panel">
            <div class="panel-title">
                <i class="fa-solid fa-list"></i>
                Order Items
                <span style="margin-left:auto;font-size:12px;font-weight:500;color:var(--text-muted);" id="itemCountLabel">
                    <?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($items)): ?>
            <div class="empty-items">
                <i class="fa-solid fa-box-open"></i>
                <p>No items found for this order.</p>
            </div>
            <?php else: ?>
            <div id="itemList">
            <?php foreach ($items as $n => $item):
                $lineTotal = $item['price'] * $item['quantity'];
                $customs = [];
                if (!empty($item['sweetness'])) $customs[] = 'Sweet: ' . $item['sweetness'];
                if (!empty($item['ice']))       $customs[] = 'Ice: '   . $item['ice'];
                if (!empty($item['milk']))      $customs[] = 'Milk: '  . $item['milk'];
            ?>
            <div class="item-row" id="row-<?= $item['item_id'] ?>" data-id="<?= $item['item_id'] ?>" data-price="<?= (float)$item['price'] ?>">

                <span class="item-num"><?= $n + 1 ?></span>

                <div class="item-info">
                    <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                    <?php if ($customs): ?>
                    <div class="item-custom"><?= htmlspecialchars(implode(' · ', $customs)) ?></div>
                    <?php endif; ?>
                    <div class="item-unit-price">$<?= number_format($item['price'], 2) ?> each</div>
                </div>

                <div class="item-controls">
                    <div class="qty-control">
                        <button type="button" onclick="changeQty(<?= $item['item_id'] ?>, -1)" id="minus-<?= $item['item_id'] ?>">−</button>
                        <input type="number" id="qty-<?= $item['item_id'] ?>" value="<?= $item['quantity'] ?>" min="1" max="99"
                               oninput="onQtyInput(<?= $item['item_id'] ?>)">
                        <button type="button" onclick="changeQty(<?= $item['item_id'] ?>, 1)">+</button>
                    </div>

                    <span class="item-line-total" id="line-<?= $item['item_id'] ?>">
                        $<?= number_format($lineTotal, 2) ?>
                    </span>

                    <button class="btn-remove" id="remove-btn-<?= $item['item_id'] ?>"
                            onclick="markRemove(<?= $item['item_id'] ?>)" title="Remove item">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                    <button class="btn-undo" id="undo-btn-<?= $item['item_id'] ?>"
                            onclick="undoRemove(<?= $item['item_id'] ?>)" title="Undo remove" style="display:none;">
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                </div>

            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Summary panel -->
        <div class="summary-panel">
            <div class="panel">
                <div class="panel-title"><i class="fa-solid fa-receipt"></i> Summary</div>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="sumSubtotal">$<?= number_format(array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items)), 2) ?></span>
                </div>
                <div class="summary-row discount discount-row" id="discountRow">
                    <span><i class="fa-solid fa-tag" style="font-size:10px;"></i> Buy <?= BUY_X_COUNT ?> Get 1 Free</span>
                    <span id="sumDiscount">-$<?= number_format($order['promotion_discount'], 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Tax (10%)</span>
                    <span id="sumTax">$—</span>
                </div>
                <div class="summary-row total-row">
                    <span>Total</span>
                    <span id="sumTotal">$<?= number_format($order['total'], 2) ?></span>
                </div>

                <button class="btn-save" id="saveBtn" onclick="saveChanges()">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>

                <p class="change-hint" id="changeHint">No changes yet</p>
            </div>
        </div>

    </div>

</div>

<div id="toast"></div>

<script>
const ORDER_ID          = <?= (int)$order_id ?>;
const WAS_HAPPY_HOUR    = <?= ((int)date('H', strtotime($order['order_date'])) >= HAPPY_HOUR_START && (int)date('H', strtotime($order['order_date'])) < HAPPY_HOUR_END) ? 'true' : 'false' ?>;
const HAPPY_HOUR_PCT    = <?= HAPPY_HOUR_DISCOUNT ?> / 100;
const HAPPY_HOUR_ON     = <?= HAPPY_HOUR_ENABLED ? 'true' : 'false' ?>;
const TAX_RATE_MULT     = <?= TAX_RATE ?> / 100;
const BUY_X_ON          = <?= BUY_X_GET_1_ENABLED ? 'true' : 'false' ?>;
const BUY_X_CNT         = <?= BUY_X_COUNT ?>;

// ── State ──
const removedIds = new Set();
let isDirty = false;

// ── Quantity helpers ──
function changeQty(id, delta) {
    const input = document.getElementById('qty-' + id);
    let val = parseInt(input.value || '1', 10) + delta;
    if (val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;
    onQtyInput(id);
}

function onQtyInput(id) {
    const input = document.getElementById('qty-' + id);
    let val = parseInt(input.value || '1', 10);
    if (isNaN(val) || val < 1) val = 1;
    if (val > 99) val = 99;
    input.value = val;

    const row = document.getElementById('row-' + id);
    const price = parseFloat(row.dataset.price);
    const lineEl = document.getElementById('line-' + id);
    lineEl.textContent = '$' + (price * val).toFixed(2);

    markDirty();
    recalcSummary();
}

// ── Remove / undo ──
function markRemove(id) {
    removedIds.add(id);
    const row = document.getElementById('row-' + id);
    row.classList.add('removed');

    // Add badge
    if (!document.getElementById('badge-' + id)) {
        const badge = document.createElement('span');
        badge.className = 'removed-badge';
        badge.id = 'badge-' + id;
        badge.textContent = 'Removed';
        row.appendChild(badge);
    }

    document.getElementById('remove-btn-' + id).style.display = 'none';
    document.getElementById('undo-btn-' + id).style.display  = 'flex';

    // Disable qty controls
    document.getElementById('qty-' + id).disabled = true;
    const btns = row.querySelectorAll('.qty-control button');
    btns.forEach(b => b.disabled = true);

    markDirty();
    recalcSummary();
    updateItemCount();
}

function undoRemove(id) {
    removedIds.delete(id);
    const row = document.getElementById('row-' + id);
    row.classList.remove('removed');

    const badge = document.getElementById('badge-' + id);
    if (badge) badge.remove();

    document.getElementById('remove-btn-' + id).style.display = 'flex';
    document.getElementById('undo-btn-' + id).style.display  = 'none';

    document.getElementById('qty-' + id).disabled = false;
    const btns = row.querySelectorAll('.qty-control button');
    btns.forEach(b => b.disabled = false);

    markDirty();
    recalcSummary();
    updateItemCount();
}

// ── Live recalculation ──
function recalcSummary() {
    let subtotal = 0, totalQty = 0, minPrice = Infinity;

    document.querySelectorAll('#itemList .item-row').forEach(row => {
        const id = parseInt(row.dataset.id);
        if (removedIds.has(id)) return;

        const price = parseFloat(row.dataset.price);
        const qty   = parseInt(document.getElementById('qty-' + id)?.value || '1', 10);
        subtotal  += price * qty;
        totalQty  += qty;
        if (price < minPrice) minPrice = price;
    });

    let buy3 = 0;
    if (BUY_X_ON && totalQty >= BUY_X_CNT && isFinite(minPrice)) {
        buy3 = Math.floor(totalQty / BUY_X_CNT) * minPrice;
    }
    // Preserve happy hour if order was originally placed during happy hour
    const happyHour = (WAS_HAPPY_HOUR && HAPPY_HOUR_ON) ? (subtotal - buy3) * HAPPY_HOUR_PCT : 0;
    const totalDiscount = buy3 + happyHour;

    const after = subtotal - totalDiscount;
    const tax   = after * TAX_RATE_MULT;
    const total = Math.round((after + tax) * 100) / 100;

    document.getElementById('sumSubtotal').textContent = '$' + subtotal.toFixed(2);
    document.getElementById('sumTax').textContent      = '$' + tax.toFixed(2);
    document.getElementById('sumTotal').textContent    = '$' + total.toFixed(2);

    const discRow  = document.getElementById('discountRow');
    const discAmt  = document.getElementById('sumDiscount');
    if (totalDiscount > 0) {
        discAmt.textContent = '-$' + totalDiscount.toFixed(2);
        discRow.classList.add('visible');
    } else {
        discRow.classList.remove('visible');
    }
}

function updateItemCount() {
    const active = document.querySelectorAll('#itemList .item-row:not(.removed)').length;
    const label  = document.getElementById('itemCountLabel');
    if (label) label.textContent = active + ' item' + (active !== 1 ? 's' : '');
}

// ── Dirty state ──
function markDirty() {
    isDirty = true;
    const hint = document.getElementById('changeHint');
    const removedCount = removedIds.size;
    hint.classList.add('visible', 'has-changes');

    const parts = [];
    if (removedCount > 0) parts.push(removedCount + ' item' + (removedCount !== 1 ? 's' : '') + ' removed');
    hint.textContent = parts.length ? 'Unsaved: ' + parts.join(', ') : 'Quantities updated';
}

// ── Save ──
async function saveChanges() {
    if (!isDirty) { showToast('No changes to save.', 'info'); return; }

    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    // Collect qtys for non-removed items
    const qtys = {};
    document.querySelectorAll('#itemList .item-row').forEach(row => {
        const id = parseInt(row.dataset.id);
        if (!removedIds.has(id)) {
            qtys[id] = parseInt(document.getElementById('qty-' + id)?.value || '1', 10);
        }
    });

    const body = new URLSearchParams({
        action:     'save',
        remove_ids: [...removedIds].join(','),
        qtys:       JSON.stringify(qtys),
    });

    try {
        const res  = await fetch('edit_order_items.php?order_id=' + ORDER_ID, { method: 'POST', body });
        const data = await res.json();

        if (data.success) {
            // Update header total
            document.getElementById('headerTotal').textContent = '$' + data.total;
            document.getElementById('sumTotal').textContent    = '$' + data.total;

            // Remove "removed" rows from DOM
            removedIds.forEach(id => {
                const row = document.getElementById('row-' + id);
                if (row) row.remove();
            });
            removedIds.clear();

            isDirty = false;
            const hint = document.getElementById('changeHint');
            hint.textContent = 'Saved successfully';
            hint.classList.remove('has-changes');

            if (data.items_left === 0) {
                document.getElementById('itemList').innerHTML =
                    '<div class="empty-items"><i class="fa-solid fa-box-open"></i><p>All items have been removed.</p></div>';
            }

            updateItemCount();
            showToast('Order updated — new total $' + data.total, 'success');
        } else {
            showToast(data.error || 'Save failed. Try again.', 'error');
        }
    } catch (e) {
        showToast('Network error. Try again.', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Changes';
    }
}

// ── Toast ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info';
    t.innerHTML = `<i class="fa-solid ${icon}"></i> ${msg}`;
    t.className = 'show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, 3200);
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
    recalcSummary();
    const hint = document.getElementById('changeHint');
    hint.classList.add('visible');
    hint.textContent = 'No changes yet';
});

// Warn before leaving with unsaved changes
window.addEventListener('beforeunload', e => {
    if (isDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>

</body>
</html>
