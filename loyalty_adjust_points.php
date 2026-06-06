<?php
require 'admin_only.php';
require_once 'config.php';
header('Content-Type: application/json');

$card_id    = (int)($_POST['card_id'] ?? 0);
$adjustment = (int)($_POST['adjustment'] ?? 0);
$reason     = trim($_POST['reason'] ?? 'Manual adjustment by admin');

if ($card_id <= 0 || $adjustment === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']); exit;
}

$stmt = $conn->prepare("SELECT card_id, points FROM loyalty_cards WHERE card_id = ? AND is_active = 1");
$stmt->bind_param("i", $card_id);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();

if (!$card) {
    echo json_encode(['success' => false, 'message' => 'Card not found']); exit;
}

$new_points = max(0, $card['points'] + $adjustment);
$conn->begin_transaction();
try {
    $s1 = $conn->prepare("UPDATE loyalty_cards SET points = ?, last_used = NOW() WHERE card_id = ?");
    $s1->bind_param("ii", $new_points, $card_id);
    $s1->execute();

    $type = $adjustment > 0 ? 'adjusted_add' : 'adjusted_deduct';
    $s2 = $conn->prepare("INSERT INTO loyalty_history (card_id, points_change, type, description) VALUES (?, ?, ?, ?)");
    $s2->bind_param("iiss", $card_id, $adjustment, $type, $reason);
    $s2->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'new_points' => $new_points]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
