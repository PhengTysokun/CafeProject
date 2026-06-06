<?php
require 'admin_only.php';
require_once 'config.php';
header('Content-Type: application/json');

$loyalty_id = $_POST['loyalty_id'] ?? '';
$initial_points = (int)($_POST['initial_points'] ?? 0);

if (empty($loyalty_id)) {
    echo json_encode(['success' => false, 'message' => 'Loyalty ID is required']);
    exit;
}

// Check if ID already exists
$stmt = $conn->prepare("SELECT COUNT(*) FROM loyalty_cards WHERE loyalty_id = ?");
$stmt->bind_param("s", $loyalty_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_row()[0];

if ($count > 0) {
    echo json_encode(['success' => false, 'message' => "Loyalty ID '$loyalty_id' already exists"]);
    exit;
}

// Create card
$stmt = $conn->prepare("INSERT INTO loyalty_cards (loyalty_id, points) VALUES (?, ?)");
$stmt->bind_param("si", $loyalty_id, $initial_points);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => "Loyalty card '$loyalty_id' created with $initial_points points!"
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error creating card: ' . $conn->error]);
}
?>