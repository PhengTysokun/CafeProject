<?php
session_start();
require 'config.php';
require __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';

use KHQR\BakongKHQR;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['paid' => false, 'error' => 'Unauthorized']);
    exit;
}

$config = require __DIR__ . '/bakong_config.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$manual = isset($_GET['manual']) ? (int)$_GET['manual'] : 0;

if ($order_id <= 0) {
    echo json_encode(['paid' => false, 'error' => 'Invalid order id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT o.order_id, o.status, o.bakong_md5, o.payment_method, o.loyalty_card_id, o.points_earned,
           op.payment_id, op.payment_status
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'bakong'
    WHERE o.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order || empty($order['bakong_md5'])) {
    echo json_encode(['paid' => false]);
    exit;
}

// If Bakong already marked as paid, return true
if ($order['payment_status'] === 'paid') {
    echo json_encode(['paid' => true]);
    exit;
}

// If order is fully completed, return true
// Note: 'Preparing' is intentionally excluded — paylater orders start in Preparing
// and must go through actual payment confirmation before being marked paid.
if ($order['status'] === 'Completed') {
    echo json_encode(['paid' => true]);
    exit;
}

// ── Manual confirmation bypasses Bakong API check ──
if ($manual === 1) {
    $conn->begin_transaction();

    try {
        // 1. Mark Bakong payment as paid in order_payments
        $stmt_payment = $conn->prepare("
            UPDATE order_payments
            SET payment_status = 'paid'
            WHERE order_id = ? AND payment_method = 'bakong'
        ");
        $stmt_payment->bind_param("i", $order_id);
        $stmt_payment->execute();

        // 2. Check if all payments for this order are now paid
        $stmt_check = $conn->prepare("
            SELECT COUNT(*) AS pending_count
            FROM order_payments
            WHERE order_id = ? AND payment_status != 'paid'
        ");
        $stmt_check->bind_param("i", $order_id);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        $pending = $result->fetch_assoc();

        // 3. If no pending payments, advance order status
        if ($pending['pending_count'] == 0) {
            if ($order['payment_method'] === 'paylater') {
                // Paylater order settled via Bakong at the counter → mark Paid & close (drops from unpaid list)
                $stmt_status = $conn->prepare("
                    UPDATE orders SET status = 'Paid', is_open = 0
                    WHERE order_id = ?
                ");
            } else {
                // Regular Bakong order → move to Preparing (kitchen view)
                $stmt_status = $conn->prepare("
                    UPDATE orders SET status = 'Preparing'
                    WHERE order_id = ? AND status = 'PendingPayment'
                ");
            }
            $stmt_status->bind_param("i", $order_id);
            $stmt_status->execute();

            // Award loyalty points for paylater orders settled via Bakong (only once)
            if ($order['payment_method'] === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
                $lc_id = (int)($order['loyalty_card_id'] ?? 0);
                if ($lc_id > 0) {
                    $pts_s = $conn->prepare("SELECT SUM(quantity) AS t FROM order_items WHERE order_id = ?");
                    $pts_s->bind_param("i", $order_id); $pts_s->execute();
                    $pts = (int)($pts_s->get_result()->fetch_assoc()['t'] ?? 0);
                    if ($pts > 0) {
                        $su = $conn->prepare("UPDATE loyalty_cards SET points = points + ?, last_used = NOW() WHERE card_id = ?");
                        if ($su) { $su->bind_param("ii", $pts, $lc_id); $su->execute(); }
                        $sc = $conn->prepare("UPDATE loyalty_cards SET total_orders = total_orders + 1, total_drinks = total_drinks + ? WHERE card_id = ?");
                        if ($sc) { $sc->bind_param("ii", $pts, $lc_id); $sc->execute(); }
                        $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'earned', 'Points earned from Pay Later order')");
                        if (!$si) $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_add', 'Points earned from Pay Later order')");
                        if ($si) { $si->bind_param("iii", $lc_id, $order_id, $pts); $si->execute(); }
                        $sl = $conn->prepare("UPDATE orders SET points_earned = ? WHERE order_id = ?");
                        if ($sl) { $sl->bind_param("ii", $pts, $order_id); $sl->execute(); }
                    }
                }
            }
        }

        $conn->commit();

        // Notify Node server
        $data = json_encode([
            "type" => "new_order",
            "payload" => [
                "order_id" => $order_id
            ]
        ]);

        $ch = curl_init("http://localhost:3000/notify");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Content-Length: " . strlen($data)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_exec($ch);
        curl_close($ch);

        echo json_encode(['paid' => true]);
        exit;

    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['paid' => false, 'error' => 'Payment confirmation failed. Please try again.']);
        exit;
    }
}

// ── Normal Bakong API check ──
try {
    $bakong = new BakongKHQR($config['token']);
    $response = $bakong->checkTransactionByMD5($order['bakong_md5']);

    $isPaid =
        (isset($response['responseCode']) && (int)$response['responseCode'] === 0)
        || (isset($response['data']) && !empty($response['data']));

    if ($isPaid) {
        $conn->begin_transaction();

        try {
            // 1. Mark Bakong payment as paid in order_payments
            $stmt_payment = $conn->prepare("
                UPDATE order_payments
                SET payment_status = 'paid'
                WHERE order_id = ? AND payment_method = 'bakong'
            ");
            $stmt_payment->bind_param("i", $order_id);
            $stmt_payment->execute();

            // 2. Check if all payments for this order are now paid
            $stmt_check = $conn->prepare("
                SELECT COUNT(*) AS pending_count
                FROM order_payments
                WHERE order_id = ? AND payment_status != 'paid'
            ");
            $stmt_check->bind_param("i", $order_id);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            $pending = $result->fetch_assoc();

            // 3. If no pending payments, advance order status
            if ($pending['pending_count'] == 0) {
                if ($order['payment_method'] === 'paylater') {
                    // Paylater order settled via Bakong at the counter → mark Paid & close (drops from unpaid list)
                    $stmt_status = $conn->prepare("
                        UPDATE orders SET status = 'Paid', is_open = 0
                        WHERE order_id = ?
                    ");
                } else {
                    // Regular Bakong order → move to Preparing (kitchen view)
                    $stmt_status = $conn->prepare("
                        UPDATE orders SET status = 'Preparing'
                        WHERE order_id = ? AND status = 'PendingPayment'
                    ");
                }
                $stmt_status->bind_param("i", $order_id);
                $stmt_status->execute();

                // Award loyalty points for paylater orders settled via Bakong (only once)
                if ($order['payment_method'] === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
                    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
                    if ($lc_id > 0) {
                        $pts_s = $conn->prepare("SELECT SUM(quantity) AS t FROM order_items WHERE order_id = ?");
                        $pts_s->bind_param("i", $order_id); $pts_s->execute();
                        $pts = (int)($pts_s->get_result()->fetch_assoc()['t'] ?? 0);
                        if ($pts > 0) {
                            $su = $conn->prepare("UPDATE loyalty_cards SET points = points + ?, last_used = NOW() WHERE card_id = ?");
                            if ($su) { $su->bind_param("ii", $pts, $lc_id); $su->execute(); }
                            $sc = $conn->prepare("UPDATE loyalty_cards SET total_orders = total_orders + 1, total_drinks = total_drinks + ? WHERE card_id = ?");
                            if ($sc) { $sc->bind_param("ii", $pts, $lc_id); $sc->execute(); }
                            $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'earned', 'Points earned from Pay Later order')");
                            if (!$si) $si = $conn->prepare("INSERT INTO loyalty_history (card_id, order_id, points_change, type, description) VALUES (?, ?, ?, 'adjusted_add', 'Points earned from Pay Later order')");
                            if ($si) { $si->bind_param("iii", $lc_id, $order_id, $pts); $si->execute(); }
                            $sl = $conn->prepare("UPDATE orders SET points_earned = ? WHERE order_id = ?");
                            if ($sl) { $sl->bind_param("ii", $pts, $order_id); $sl->execute(); }
                        }
                    }
                }
            }

            $conn->commit();

            // Notify Node server
            $data = json_encode([
                "type" => "new_order",
                "payload" => [
                    "order_id" => $order_id
                ]
            ]);

            $ch = curl_init("http://localhost:3000/notify");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Content-Length: " . strlen($data)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_exec($ch);
            curl_close($ch);

            echo json_encode(['paid' => true]);
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            echo json_encode(['paid' => false, 'error' => 'Payment confirmation failed. Please try again.']);
            exit;
        }
    }

    echo json_encode(['paid' => false]);
} catch (Throwable $e) {
    echo json_encode(['paid' => false]);
}
?>