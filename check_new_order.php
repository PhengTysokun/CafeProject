<?php
require 'config.php';

header('Content-Type: application/json');

$result = $conn->query("
    SELECT order_id
    FROM orders
    WHERE status = 'Preparing'
      AND printed = 0
    ORDER BY order_id ASC
    LIMIT 1
");

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'order_id' => (int)$row['order_id']
    ]);
} else {
    echo json_encode([
        'order_id' => null
    ]);
}
?>