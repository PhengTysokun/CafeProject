<?php
session_start();
require '../config.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? 'staff';
$data = ['role' => $role, 'loaded_at' => time()];

switch ($role) {
    case 'admin':
    case 'manager':
    case 'supervisor':
    case 'staff':
        $r = $conn->query("SELECT IFNULL(SUM(total),0) AS sales, COUNT(*) AS orders FROM orders WHERE DATE(order_date)=CURDATE() AND status='Completed'");
        $row = $r->fetch_assoc();
        $data['sales_today']   = (float)$row['sales'];
        $data['orders_today']  = (int)$row['orders'];

        $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('PendingPayment','Preparing')");
        $data['active_orders'] = (int)$r2->fetch_assoc()['cnt'];

        $r3 = $conn->query("SELECT COUNT(*) AS cnt FROM ingredients WHERE stock_quantity < minimum_stock");
        $data['low_stock'] = (int)$r3->fetch_assoc()['cnt'];
        break;

    case 'barista':
        $r = $conn->query("SELECT order_id, daily_order_no, customer_name, token_number, status, order_date FROM orders WHERE status IN ('PendingPayment','Preparing') ORDER BY order_date ASC LIMIT 15");
        $orders = [];
        while ($row = $r->fetch_assoc()) $orders[] = $row;
        $data['queue']       = $orders;
        $data['queue_count'] = count($orders);
        break;

    case 'inventory_clerk':
        $r = $conn->query("SELECT COUNT(*) AS total FROM ingredients");
        $data['total_ingredients'] = (int)$r->fetch_assoc()['total'];

        $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM ingredients WHERE stock_quantity < minimum_stock");
        $data['low_stock'] = (int)$r2->fetch_assoc()['cnt'];
        break;
}

echo json_encode($data);
