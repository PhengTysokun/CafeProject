<?php
require 'auth.php';
header('Content-Type: application/json');

if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$order_id     = (int)($_POST['order_id'] ?? 0);
$table_number = trim($_POST['table_number'] ?? '');

if ($order_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid order']);
    exit;
}

// Get current table so we can free it
$s = $conn->prepare("SELECT table_number FROM orders WHERE order_id = ?");
$s->bind_param("i", $order_id);
$s->execute();
$old_table = trim((string)($s->get_result()->fetch_assoc()['table_number'] ?? ''));

// Update the order (store NULL when clearing the stand number)
if ($table_number !== '') {
    $s = $conn->prepare("UPDATE orders SET table_number = ? WHERE order_id = ?");
    $s->bind_param("si", $table_number, $order_id);
    $ok = $s->execute();
} else {
    $s = $conn->prepare("UPDATE orders SET table_number = NULL WHERE order_id = ?");
    $s->bind_param("i", $order_id);
    $ok = $s->execute();
}

echo json_encode(['ok' => $ok, 'table_number' => $table_number]);
