<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$id    = (int)($_POST['product_id'] ?? 0);
$price = round((float)($_POST['price'] ?? -1), 2);

if ($id <= 0 || $price < 0 || $price > 9999.99) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

$stmt = $conn->prepare("UPDATE products SET price = ? WHERE product_id = ?");
$stmt->bind_param('di', $price, $id);
$stmt->execute();

echo json_encode(['ok' => true, 'price' => $price]);
