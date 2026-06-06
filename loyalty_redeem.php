<?php
session_start();
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

$loyalty_id = $_POST['loyalty_id'] ?? '';
$reward_name = $_POST['reward_name'] ?? '';

if (empty($loyalty_id) || empty($reward_name)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Get card
$card = getLoyaltyCard($conn, $loyalty_id);

if (!$card) {
    echo json_encode(['success' => false, 'message' => 'Card not found']);
    exit;
}

// Get reward
$stmt = $conn->prepare("SELECT * FROM rewards WHERE reward_name = ? AND is_active = 1");
$stmt->bind_param("s", $reward_name);
$stmt->execute();
$reward = $stmt->get_result()->fetch_assoc();

if (!$reward) {
    echo json_encode(['success' => false, 'message' => 'Reward not available']);
    exit;
}

// Check points
if ($card['points'] < $reward['points_required']) {
    echo json_encode(['success' => false, 'message' => 'Not enough points']);
    exit;
}

// ── Compute the effective point balance accounting for already-pending redemptions ──
// Points are NOT deducted from the DB here — that only happens when the order is confirmed.
// This prevents cart abandonment from permanently losing the customer's points.
$pending_deduction = 0;
if (!empty($_SESSION['redeemed_rewards'])) {
    foreach ($_SESSION['redeemed_rewards'] as $pending) {
        if ((int)$pending['card_id_int'] === (int)$card['card_id']) {
            $pending_deduction += (int)$pending['points_required'];
        }
    }
}

$effective_points = $card['points'] - $pending_deduction;

if ($effective_points < $reward['points_required']) {
    echo json_encode(['success' => false, 'message' => 'Not enough points (accounting for pending redemptions)']);
    exit;
}

$new_points = $effective_points - $reward['points_required'];

// ── Store reward in session; actual DB deduction happens in confirm_order.php ──
if (!isset($_SESSION['redeemed_rewards'])) {
    $_SESSION['redeemed_rewards'] = [];
}

$_SESSION['redeemed_rewards'][] = [
    'reward_name'    => $reward['reward_name'],
    'points_required'=> $reward['points_required'],
    'card_id'        => $card['loyalty_id'],   // kept for display
    'card_id_int'    => $card['card_id'],       // DB id for deduction
];

// ── Get updated rewards list so UI can re-render with new effective balance ──
$stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
$stmt->execute();
$updated_rewards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success'         => true,
    'message'         => "{$reward['reward_name']} added — will be applied when order is confirmed.",
    'new_points'      => $new_points,
    'reward_name'     => $reward['reward_name'],
    'points_required' => $reward['points_required'],
    'rewards'         => $updated_rewards,
]);
?>