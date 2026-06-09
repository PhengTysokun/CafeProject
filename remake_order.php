<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin or manager can log a remake"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$reason = trim($_POST['reason'] ?? '');
if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for the remake"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, daily_order_no, status FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

if ($order['status'] !== 'Completed') {
    echo json_encode(["ok" => 0, "error" => "Remakes can only be logged on Completed orders"]);
    exit;
}

$stmt2 = $conn->prepare("INSERT INTO order_remakes (order_id, reason, remade_by) VALUES (?, ?, ?)");
if (!$stmt2) {
    echo json_encode(["ok" => 0, "error" => "order_remakes table not found — run the schema migration first"]);
    exit;
}
$stmt2->bind_param("iss", $order_id, $reason, $_SESSION['username']);
if (!$stmt2->execute()) {
    echo json_encode(["ok" => 0, "error" => "Failed to log remake: " . $stmt2->error]);
    exit;
}

// Apply item adjustments if provided
$adjustments_raw = trim($_POST['adjustments'] ?? '');
if ($adjustments_raw) {
    $adjustments = json_decode($adjustments_raw, true);
    if (is_array($adjustments)) {
        $stmt_adj = $conn->prepare("UPDATE order_items SET sweetness=?, ice=?, milk=? WHERE item_id=? AND order_id=?");
        $adj_sw = $adj_ic = $adj_ml = '';
        $adj_item_id = 0;
        $stmt_adj->bind_param("sssii", $adj_sw, $adj_ic, $adj_ml, $adj_item_id, $order_id);
        foreach ($adjustments as $adj) {
            $adj_item_id = (int)($adj['item_id'] ?? 0);
            if ($adj_item_id <= 0) continue;
            $adj_sw = trim($adj['sweetness'] ?? '');
            $adj_ic = trim($adj['ice'] ?? '');
            $adj_ml = trim($adj['milk'] ?? '');
            $stmt_adj->execute();
        }
    }
}

$stmt3 = $conn->prepare("UPDATE orders SET status='Preparing', is_open=1 WHERE order_id=?");
$stmt3->bind_param("i", $order_id);
$stmt3->execute();

echo json_encode(["ok" => 1, "message" => "Order #" . $order['daily_order_no'] . " sent back to Preparing"]);
exit;
