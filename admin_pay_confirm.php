<?php
require 'auth.php';
if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) { header("Location: dashboard.php?denied=1"); exit; }

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: find_order.php");
    exit;
}

// Determine correct next status
$stmt_cur = $conn->prepare("SELECT status, payment_method, table_number FROM orders WHERE order_id = ?");
$stmt_cur->bind_param("i", $order_id);
$stmt_cur->execute();
$cur = $stmt_cur->get_result()->fetch_assoc();
if ($cur && $cur['payment_method'] === 'paylater') {
    $new_status = 'Paid';
} else {
    $new_status = ($cur && $cur['status'] === 'PendingPayment') ? 'Preparing' : 'Completed';
}

$stmt = $conn->prepare("UPDATE orders SET status = ?, is_open = 0 WHERE order_id = ?");
$stmt->bind_param("si", $new_status, $order_id);
$stmt->execute();

header("Location: find_order.php?paid=" . $order_id);
exit;
?>