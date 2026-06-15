<?php
session_start();
require 'config.php';
if (!isset($_SESSION['user_id'])) { echo json_encode(['stands' => []]); exit; }
header('Content-Type: application/json');

$stmt = $conn->prepare("
    SELECT table_number, daily_order_no, customer_name, status
    FROM orders
    WHERE status IN ('Pending','Processing','Preparing','PendingPayment')
      AND table_number != ''
      AND table_number IS NOT NULL
");
$stmt->execute();
$result = $stmt->get_result();

$active = [];
while ($row = $result->fetch_assoc()) {
    $key = trim($row['table_number']);
    if ($key !== '' && !isset($active[$key])) {
        $active[$key] = [
            'order_no' => $row['daily_order_no'],
            'customer' => $row['customer_name'] ?: '',
            'status'   => $row['status'],
        ];
    }
}

echo json_encode(['stands' => $active]);
