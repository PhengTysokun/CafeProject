<?php
session_start();
require 'config.php';
if (!isset($_SESSION['user_id'])) { echo json_encode(['stands' => []]); exit; }
header('Content-Type: application/json');

// Token-driven occupancy: a stand stays In Use until the metal placard is
// returned (staff releases it on stands.php, which clears table_number), not
// when the order is paid. Cancelled/refunded orders free automatically.
// Scoped to today's business day so stands don't carry over.
$stmt = $conn->prepare("
    SELECT table_number, daily_order_no, customer_name, status
    FROM orders
    WHERE status NOT IN ('Cancelled','Refunded','Void')
      AND business_date = CURDATE()
      AND table_number != ''
      AND table_number IS NOT NULL
    ORDER BY order_date DESC
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
