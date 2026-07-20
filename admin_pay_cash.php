<?php
require 'auth.php'; // starts session, loads config ($conn), re-syncs $_SESSION['role']
// Cashiers (staff) collect pay-later payments from find_order.php — allow them alongside admin/manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
$return_page = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=pending';

if ($order_id <= 0) {
    header("Location: $return_page");
    exit;
}

// Fetch order details
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status, payment_method, loyalty_card_id, points_earned
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: $return_page");
    exit;
}

// Mark order as Paid, close it, and sync all payment records atomically
$conn->begin_transaction();
try {
    // Paylater settled at the counter → 'Paid' (drops it from the unpaid list);
    // otherwise advance normally (matches admin_pay_confirm.php).
    $new_status = ($order['payment_method'] === 'paylater')
        ? 'Paid'
        : (($order['status'] === 'PendingPayment') ? 'Preparing' : 'Completed');
    $stmt = $conn->prepare("UPDATE orders SET status = ?, is_open = 0, completed_at = NOW() WHERE order_id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        UPDATE order_payments SET payment_status = 'paid'
        WHERE order_id = ? AND payment_status != 'paid'
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();

    // Award loyalty points only for Pay Later orders settled at the counter.
    // Regular orders already receive points at confirm_order.php (creation time).
    // Guard: skip if points were already credited (e.g. items added earlier
    // already awarded them via confirm_order.php), mirroring check_payment.php.
    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
    if ($lc_id > 0 && ($order['payment_method'] ?? '') === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
        $pts_stmt = $conn->prepare("SELECT SUM(quantity) AS total_qty FROM order_items WHERE order_id = ?");
        $pts_stmt->bind_param("i", $order_id);
        $pts_stmt->execute();
        $pts = (int)($pts_stmt->get_result()->fetch_assoc()['total_qty'] ?? 0);
        if ($pts > 0) {
            $su = $conn->prepare("UPDATE loyalty_cards SET points = points + ?, last_used = NOW() WHERE card_id = ?");
            if ($su) { $su->bind_param("ii", $pts, $lc_id); $su->execute(); }
            $sc = $conn->prepare("UPDATE loyalty_cards SET total_orders = total_orders + 1, total_drinks = total_drinks + ? WHERE card_id = ?");
            if ($sc) { $sc->bind_param("ii", $pts, $lc_id); $sc->execute(); }
            $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'earned', 'Points earned from Pay Later order')");
            if (!$si) $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_add', 'Points earned from Pay Later order')");
            if ($si) { $si->bind_param("iii", $lc_id, $order_id, $pts); $si->execute(); }
            $sl = $conn->prepare("UPDATE orders SET points_earned = ? WHERE order_id = ?");
            if ($sl) { $sl->bind_param("ii", $pts, $order_id); $sl->execute(); }
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: $return_page");
    exit;
}

// Show the same success screen as the regular checkout (identical UI).
header("Location: payment_cash.php?order_id=" . $order_id);
exit;
