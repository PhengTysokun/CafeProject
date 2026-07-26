<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["ok" => 0, "error" => "Please login first"]);
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'manager', 'staff'])) {
    echo json_encode(["ok" => 0, "error" => "Only admin, manager or cashier can log a remake"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["ok" => 0, "error" => "Invalid request method"]);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(["ok" => 0, "error" => "Invalid order ID"]);
    exit;
}

$reason = trim($_POST['reason'] ?? '');
if (empty($reason)) {
    echo json_encode(["ok" => 0, "error" => "Please provide a reason for the remake"]);
    exit;
}

$stmt = $conn->prepare("SELECT order_id, daily_order_no, status FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(["ok" => 0, "error" => "Order not found"]);
    exit;
}

if ($order['status'] !== 'Completed') {
    echo json_encode(["ok" => 0, "error" => "Remakes can only be logged on Completed orders"]);
    exit;
}

$stmt2 = $conn->prepare("INSERT INTO order_remakes (order_id, reason, remade_by) VALUES (?, ?, ?)");
if (!$stmt2) {
    echo json_encode(["ok" => 0, "error" => "order_remakes table not found — run the schema migration first"]);
    exit;
}
$stmt2->bind_param("iss", $order_id, $reason, $_SESSION['username']);
if (!$stmt2->execute()) {
    echo json_encode(["ok" => 0, "error" => "Failed to log remake. Please try again."]);
    exit;
}

// Apply item adjustments if provided
$adjustments_raw = trim($_POST['adjustments'] ?? '');
if ($adjustments_raw) {
    $adjustments = json_decode($adjustments_raw, true);
    if (is_array($adjustments)) {
        /* Allowed add-ons per product, with prices read from the server — a crafted POST
           can neither invent a price nor attach an add-on this product can't be ordered
           with. Same gate as add_to_cart.php: assigned to the product AND the category
           offers add-ons. Keyed by product so each item is validated against its own drink. */
        $allowed = [];
        $ap = $conn->query("
            SELECT pa.product_id, a.id, a.name, a.price
            FROM product_addons pa
            JOIN addons a     ON a.id = pa.addon_id
            JOIN products pr  ON pr.product_id = pa.product_id
            JOIN categories c ON c.slug = pr.category
            WHERE a.is_active = 1 AND c.offer_addons = 1
            ORDER BY a.display_order ASC, a.id ASC
        ");
        if ($ap) while ($apr = $ap->fetch_assoc()) {
            $allowed[(int)$apr['product_id']][$apr['name']] =
                ['id' => (int)$apr['id'], 'name' => $apr['name'], 'price' => (float)$apr['price']];
        }

        // product_id per item, so an adjustment can't be validated against another drink.
        $item_product = [];
        $ipq = $conn->prepare("SELECT item_id, product_id FROM order_items WHERE order_id = ?");
        $ipq->bind_param("i", $order_id);
        $ipq->execute();
        $ipr = $ipq->get_result();
        while ($row = $ipr->fetch_assoc()) $item_product[(int)$row['item_id']] = (int)$row['product_id'];

        $stmt_adj = $conn->prepare(
            "UPDATE order_items SET sweetness=?, ice=?, milk=?, addons_snapshot=? WHERE item_id=? AND order_id=?"
        );
        $adj_sw = $adj_ic = $adj_ml = $adj_snap = '';
        $adj_item_id = 0;
        $stmt_adj->bind_param("ssssii", $adj_sw, $adj_ic, $adj_ml, $adj_snap, $adj_item_id, $order_id);
        foreach ($adjustments as $adj) {
            $adj_item_id = (int)($adj['item_id'] ?? 0);
            if ($adj_item_id <= 0) continue;
            $adj_sw = trim($adj['sweetness'] ?? '');
            $adj_ic = trim($adj['ice'] ?? '');
            $adj_ml = trim($adj['milk'] ?? '');

            /* Same shape and ordering add_to_cart.php writes: [{id, name, price}, ...] in
               display_order. Shape matters — confirm_order.php compares addons_snapshot as
               a string when merging identical drinks, so a different key set would stop
               a re-ordered drink from matching.
               order_items.price is deliberately NOT recomputed: a remake is service
               recovery, so the customer is never re-billed for an add-on change. */
            $pid   = $item_product[$adj_item_id] ?? 0;
            $pool  = $allowed[$pid] ?? [];
            $want  = array_flip(array_map('strval', (array)($adj['addons'] ?? [])));
            $snap  = [];
            foreach ($pool as $name => $meta) {          // $pool is already in display_order
                if (isset($want[$name])) $snap[] = $meta;
            }
            $adj_snap = json_encode($snap);
            $stmt_adj->execute();
        }
    }
}

$stmt3 = $conn->prepare("UPDATE orders SET status='Preparing', is_open=1 WHERE order_id=?");
$stmt3->bind_param("i", $order_id);
$stmt3->execute();

echo json_encode(["ok" => 1, "message" => "Order #" . $order['daily_order_no'] . " sent back to Preparing"]);
exit;
