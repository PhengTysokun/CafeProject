<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'config.php';
require 'admin_only.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$new_status = $_GET['status'] ?? '';

// ── Allowed transitions ──
$allowed_transitions = [
    'Preparing'      => ['Paid', 'Completed', 'Cancelled'],
    'Paid'           => ['Completed', 'Cancelled'],
    'PendingPayment' => ['Paid', 'Cancelled'],
];

if ($order_id <= 0 || empty($new_status)) {
    header("Location: dashboard.php");
    exit;
}

// Fetch current status
$stmt = $conn->prepare("SELECT status FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    header("Location: dashboard.php");
    exit;
}

$current_status = $row['status'];

// Validate transition
if (!isset($allowed_transitions[$current_status]) || !in_array($new_status, $allowed_transitions[$current_status])) {
    header("Location: dashboard.php?error=invalid_transition");
    exit;
}

// Apply status update
$extra_sql = ($new_status === 'Completed') ? ", is_open = 0, completed_at = NOW()" : "";
$extra_sql .= ($new_status === 'Paid') ? ", completed_at = NOW()" : "";
$stmt = $conn->prepare("UPDATE orders SET status = ? $extra_sql WHERE order_id = ?");
$stmt->bind_param("si", $new_status, $order_id);
$stmt->execute();

// ── LOYALTY: Earn points when order is Paid or Completed ──
if ($new_status === 'Paid' || $new_status === 'Completed') {
    // Get loyalty card ID from order
    $stmt = $conn->prepare("SELECT loyalty_card_id, points_earned FROM orders WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    // Guard: only award if a card is linked AND points were not already granted
    // (confirm_order.php / check_payment.php award at creation/settlement — avoid double-counting)
    if ($order['loyalty_card_id'] && (int)($order['points_earned'] ?? 0) === 0) {
        // Get total quantity of drinks (excluding loyalty items with price 0)
        $stmt = $conn->prepare("
            SELECT SUM(quantity) as total_drinks 
            FROM order_items 
            WHERE order_id = ? AND price > 0
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_assoc();
        $total_drinks = (int)($items['total_drinks'] ?? 0);
        
        if ($total_drinks > 0) {
            // Add points (1 point per drink)
            $stmt = $conn->prepare("UPDATE loyalty_cards SET points = points + ?, total_orders = total_orders + 1, total_drinks = total_drinks + ?, last_used = NOW() WHERE card_id = ?");
            $stmt->bind_param("iii", $total_drinks, $total_drinks, $order['loyalty_card_id']);
            $stmt->execute();
            
            // Add history
            $stmt = $conn->prepare("
                INSERT INTO loyalty_history (card_id, order_id, points_change, type, description)
                VALUES (?, ?, ?, 'earned', ?)
            ");
            $description = "Earned {$total_drinks} points from order #{$order_id}";
            $stmt->bind_param("iiis", $order['loyalty_card_id'], $order_id, $total_drinks, $description);
            $stmt->execute();

            // Record on the order so points are never granted twice (the guard above reads this)
            $stmt = $conn->prepare("UPDATE orders SET points_earned = ? WHERE order_id = ?");
            $stmt->bind_param("ii", $total_drinks, $order_id);
            $stmt->execute();
        }
    }
}

header("Location: dashboard.php");
exit;
?>