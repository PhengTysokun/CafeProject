<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$id = (int)($_POST['product_id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false]); exit; }

$stmt = $conn->prepare("UPDATE products SET is_available = NOT is_available WHERE product_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

$stmt2 = $conn->prepare("SELECT is_available FROM products WHERE product_id = ?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$available = (int)$stmt2->get_result()->fetch_assoc()['is_available'];

echo json_encode(['ok' => true, 'available' => $available]);
