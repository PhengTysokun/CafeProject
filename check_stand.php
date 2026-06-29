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

// Token-driven: a stand is in use until released (table_number cleared), not
// until paid. Mirrors get_stands.php / the confirm_order duplicate guard.
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, status
    FROM orders
    WHERE UPPER(table_number) = UPPER(?)
      AND status NOT IN ('Cancelled','Refunded','Void')
      AND business_date = CURDATE()
    ORDER BY order_date DESC
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
