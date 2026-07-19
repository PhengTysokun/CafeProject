<?php
// Standalone reward redemption — customer spends points for a reward (e.g. Shirt) with
// no order/purchase. Deducts points + logs to loyalty_history. Cashier action (can loyalty).
require 'auth.php';
require_once 'config.php';
header('Content-Type: application/json');

if (!can('loyalty')) { echo json_encode(['success' => false, 'message' => 'Not authorized']); exit; }

$card_id   = (int)($_POST['card_id'] ?? 0);
$reward_id = (int)($_POST['reward_id'] ?? 0);
if ($card_id <= 0 || $reward_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']); exit;
}

$stmt = $conn->prepare("SELECT card_id, loyalty_id, points FROM loyalty_cards WHERE card_id = ? AND is_active = 1");
$stmt->bind_param("i", $card_id);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();
if (!$card) { echo json_encode(['success' => false, 'message' => 'Card not found']); exit; }

$stmt = $conn->prepare("SELECT reward_id, reward_name, points_required FROM rewards WHERE reward_id = ? AND is_active = 1");
$stmt->bind_param("i", $reward_id);
$stmt->execute();
$reward = $stmt->get_result()->fetch_assoc();
if (!$reward) { echo json_encode(['success' => false, 'message' => 'Reward not available']); exit; }

$cost = (int)$reward['points_required'];
if ((int)$card['points'] < $cost) {
    echo json_encode(['success' => false, 'message' => 'Not enough points (' . (int)$card['points'] . ' available, needs ' . $cost . ')']); exit;
}

$new_points = (int)$card['points'] - $cost;
$conn->begin_transaction();
try {
    $s1 = $conn->prepare("UPDATE loyalty_cards SET points = ?, last_used = NOW() WHERE card_id = ?");
    $s1->bind_param("ii", $new_points, $card_id);
    $s1->execute();

    // 'redeemed' hardcoded in SQL so ENUM validation happens at execute, not bind (matches confirm_order).
    $neg  = -$cost;
    $rn   = $reward['reward_name'];
    $desc = "Redeemed {$rn} for {$cost} points at counter";
    $s2 = $conn->prepare("INSERT INTO loyalty_history (card_id, points_change, type, reward_name, description) VALUES (?, ?, 'redeemed', ?, ?)");
    $s2->bind_param("iiss", $card_id, $neg, $rn, $desc);
    $s2->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'new_points' => $new_points, 'reward' => $rn, 'cost' => $cost]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
