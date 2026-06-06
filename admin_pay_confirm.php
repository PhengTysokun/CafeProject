<?php
session_start();
require 'config.php';
require 'admin_only.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: find_order.php");
    exit;
}

// Mark order as PAID
$stmt = $conn->prepare("UPDATE orders SET status = 'Paid' WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();

header("Location: find_order.php?paid=" . $order_id);
exit;
?>