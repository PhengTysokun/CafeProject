<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) {
    echo json_encode(["ok" => 0, "error" => "You don't have permission to cancel orders"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, daily_order_no, customer_name, total, status FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

// ── Only allow cancellation of non-terminal statuses ──
$non_cancellable = ['Cancelled', 'Refunded', 'Completed'];
if (in_array($order['status'], $non_cancellable)) {
    echo json_encode(["ok" => 0, "error" => "Cannot cancel an order with status: {$order['status']}"]);
    exit;
}

// ── Only admin can cancel Paid/Completed orders ──
if (in_array($order['status'], ['Paid']) && !in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin can cancel paid orders"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$reason        = trim($_POST['cancel_reason'] ?? '');
$restore_stock = isset($_POST['restore_stock']) ? 1 : 0;

if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for cancellation"]);
    exit;
}

$conn->begin_transaction();

try {
    $stmt_upd = $conn->prepare("
        UPDATE orders
        SET status = 'Cancelled', is_open = 0, cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ?
        WHERE order_id = ?
    ");
    $stmt_upd->bind_param("ssi", $reason, $_SESSION['username'], $order_id);
    $stmt_upd->execute();

    if ($restore_stock) {
        _restore_stock($conn, $order_id);
    }

    $conn->commit();
    echo json_encode(["ok" => 1, "message" => "Order #{$order['daily_order_no']} cancelled successfully"]);

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

    while ($item = $items->fetch_assoc()) {
        $product_id = (int)$item['product_id'];
        $qty        = (int)$item['quantity'];
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
        }
    }
}
