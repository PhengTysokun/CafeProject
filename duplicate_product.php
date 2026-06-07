<?php
require 'admin_only.php';
header('Content-Type: application/json');

$id = (int)($_POST['product_id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false]); exit; }

$stmt = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
if (!$p) { echo json_encode(['ok' => false, 'error' => 'Not found']); exit; }

$name  = 'Copy of ' . $p['name'];
$stmt2 = $conn->prepare(
    "INSERT INTO products (name, description, price, category, image, is_available) VALUES (?, ?, ?, ?, ?, ?)"
);
$desc  = $p['description'] ?? '';
$avail = (int)($p['is_available'] ?? 1);
$stmt2->bind_param('ssdssi', $name, $desc, $p['price'], $p['category'], $p['image'], $avail);
$stmt2->execute();
$newId = $conn->insert_id;

echo json_encode(['ok' => true, 'new_id' => $newId]);
