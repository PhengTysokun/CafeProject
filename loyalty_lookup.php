<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

$loyalty_id = $_GET['loyalty_id'] ?? '';

if (empty($loyalty_id)) {
    echo json_encode(['found' => false, 'message' => 'No loyalty ID provided']);
    exit;
}

// Get card
$card = getLoyaltyCard($conn, $loyalty_id);

if (!$card) {
    // Clear any previously stored card from session
    unset($_SESSION['loyalty_card_id']);
    echo json_encode(['found' => false, 'message' => 'Card not found']);
    exit;
}

// ── Save card to session so confirm_order.php can award points ──
$_SESSION['loyalty_card_id'] = (int)$card['card_id'];

// Get available rewards
$rewards = [];
$stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
$stmt->execute();
$rewards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get history
$history = [];
$stmt = $conn->prepare("
    SELECT * FROM loyalty_history 
    WHERE card_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->bind_param("i", $card['card_id']);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'found' => true,
    'loyalty_id' => $card['loyalty_id'],
    'points' => (int)$card['points'],
    'total_orders' => (int)$card['total_orders'],
    'total_drinks' => (int)$card['total_drinks'],
    'rewards' => $rewards,
    'history' => $history
]);