<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require 'config.php';
header('Content-Type: application/json');

$stand = trim($_GET['stand'] ?? '');
if ($stand === '') {
    echo json_encode(['in_use' => false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, status
    FROM orders
    WHERE UPPER(table_number) = UPPER(?)
      AND status IN ('Pending','Processing','Preparing','PendingPayment')
    LIMIT 1
");
$stmt->bind_param("s", $stand);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if ($row) {
    echo json_encode([
        'in_use'   => true,
        'order_no' => $row['daily_order_no'],
        'customer' => $row['customer_name'] ?: '',
        'status'   => $row['status'],
    ]);
} else {
    echo json_encode(['in_use' => false]);
}
