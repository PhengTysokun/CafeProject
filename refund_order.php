<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin or manager can refund orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status, is_refunded,
           loyalty_card_id, points_earned
    FROM orders WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

if ($order['is_refunded'] == 1) {
    echo json_encode(["ok" => 0, "error" => "This order has already been refunded"]);
    exit;
}

// ── Allow refunds on Completed OR Preparing orders (payment goes straight to Preparing) ──
$refundable_statuses = ['Completed', 'Preparing'];
if (!in_array($order['status'], $refundable_statuses)) {
    echo json_encode(["ok" => 0, "error" => "Only paid or Completed orders can be refunded (current status: {$order['status']})"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$refund_amount  = (float)($_POST['refund_amount']  ?? 0);
$refund_reason  = trim($_POST['refund_reason']      ?? '');
$restore_stock  = 0; // Drink already made — ingredients cannot be restored on refund

if ($refund_amount <= 0 || $refund_amount > $order['total'] + 0.01) {
    echo json_encode(["ok" => 0, "error" => "Invalid refund amount. Max: $" . number_format($order['total'], 2)]);
    exit;
}

if (empty($refund_reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for refund"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt_upd = $conn->prepare("
        UPDATE orders
        SET status = 'Refunded', is_open = 0,
            refund_amount = ?, refund_reason = ?,
            refunded_at = NOW(), refunded_by = ?, is_refunded = 1
        WHERE order_id = ?
    ");
    $stmt_upd->bind_param("dssi", $refund_amount, $refund_reason, $_SESSION['username'], $order_id);
    $stmt_upd->execute();

    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    $conn->commit();

    // ── Reverse loyalty points earned on this order ──
    $card_id    = (int)($order['loyalty_card_id'] ?? 0);
    $pts_earned = (int)($order['points_earned']   ?? 0);
    if ($card_id > 0 && $pts_earned > 0) {
        $stmt_pts = $conn->prepare("UPDATE loyalty_cards SET points = GREATEST(0, points - ?), last_used = NOW() WHERE card_id = ?");
        $stmt_pts->bind_param("ii", $pts_earned, $card_id);
        $stmt_pts->execute();
        $neg = -$pts_earned;
        $stmt_hist = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_deduct', 'Points reversed — order refunded')");
        $stmt_hist->bind_param("iii", $card_id, $order_id, $neg);
        $stmt_hist->execute();
    }

    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} refunded $" . number_format($refund_amount, 2) . " successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["ok" => 0, "error" => $e->getMessage()]);
}
exit;

function _restore_stock(mysqli $conn, int $order_id): void {
    $stmt_items = $conn->prepare("SELECT product_id, quantity, milk FROM order_items WHERE order_id = ?");
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $items = $stmt_items->get_result();

    $created_by = $_SESSION['username'] ?? null;
    $ref        = "Order #$order_id";

    while ($item = $items->fetch_assoc()) {
        $product_id  = (int)$item['product_id'];
        $qty         = (int)$item['quantity'];
        $milk_choice = trim((string)$item['milk']);

        $stmt_recipe = $conn->prepare("
            SELECT pi.ingredient_id, pi.amount_used, i.ingredient_name
            FROM product_ingredients pi
            JOIN ingredients i ON i.ingredient_id = pi.ingredient_id
            WHERE pi.product_id = ?
        ");
        $stmt_recipe->bind_param("i", $product_id);
        $stmt_recipe->execute();
        $recipe = $stmt_recipe->get_result();

        while ($row = $recipe->fetch_assoc()) {
            $ing_id   = (int)$row['ingredient_id'];
            $amount   = (float)$row['amount_used'] * $qty;
            $ing_name = strtolower(trim($row['ingredient_name']));

            if (strpos($ing_name, 'milk') !== false && !empty($milk_choice)) {
                $stmt_milk = $conn->prepare("SELECT ingredient_id FROM ingredients WHERE LOWER(ingredient_name) = LOWER(?) LIMIT 1");
                $stmt_milk->bind_param("s", $milk_choice);
                $stmt_milk->execute();
                $milk_row = $stmt_milk->get_result()->fetch_assoc();
                if ($milk_row) $ing_id = (int)$milk_row['ingredient_id'];
            }

            $stmt_restore = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?");
            $stmt_restore->bind_param("di", $amount, $ing_id);
            $stmt_restore->execute();

            $sh = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, order_id, reference, created_by) VALUES (?, 'order_restore', ?, ?, ?, ?)");
            $sh->bind_param("idiss", $ing_id, $amount, $order_id, $ref, $created_by);
            $sh->execute();
        }
    }
}
