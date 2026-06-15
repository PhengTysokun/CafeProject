<?php
require 'auth.php';
if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
header('Content-Type: application/json');

$order_id   = (int)($_POST['order_id'] ?? 0);
$loyalty_id = trim($_POST['loyalty_id'] ?? '');

if ($order_id <= 0 || empty($loyalty_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$card = getLoyaltyCard($conn, $loyalty_id);
if (!$card) {
    echo json_encode(['success' => false, 'message' => 'Card not found or inactive']);
    exit;
}

$card_id = (int)$card['card_id'];

// Only allow linking to paylater orders still being prepared (not yet paid)
$stmt = $conn->prepare("
    UPDATE orders SET loyalty_card_id = ?
    WHERE order_id = ? AND payment_method = 'paylater' AND status = 'Preparing'
");
$stmt->bind_param("ii", $card_id, $order_id);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Order not found or no longer editable']);
    exit;
}

echo json_encode([
    'success'    => true,
    'card_id'    => $card_id,
    'loyalty_id' => $card['loyalty_id'],
    'points'     => (int)$card['points'],
]);
