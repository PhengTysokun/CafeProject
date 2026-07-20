<?php
require 'auth.php'; // starts session, loads config ($conn), re-syncs $_SESSION['role']
// Cashiers (staff) collect pay-later payments from find_order.php — allow them alongside admin/manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

$order_id = (int)($_GET['order_id'] ?? 0);
$return_page = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=pending';

if ($order_id <= 0) {
    header("Location: $return_page");
    exit;
}

// Fetch order details
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status, payment_method, loyalty_card_id, points_earned
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: $return_page");
    exit;
}

// Mark order as Paid, close it, and sync all payment records atomically
$conn->begin_transaction();
try {
    // Paylater settled at the counter → 'Paid' (drops it from the unpaid list);
    // otherwise advance normally (matches admin_pay_confirm.php).
    $new_status = ($order['payment_method'] === 'paylater')
        ? 'Paid'
        : (($order['status'] === 'PendingPayment') ? 'Preparing' : 'Completed');
    $stmt = $conn->prepare("UPDATE orders SET status = ?, is_open = 0, completed_at = NOW() WHERE order_id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();

    $stmt = $conn->prepare("
        UPDATE order_payments SET payment_status = 'paid'
        WHERE order_id = ? AND payment_status != 'paid'
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();

    // Award loyalty points only for Pay Later orders settled at the counter.
    // Regular orders already receive points at confirm_order.php (creation time).
    // Guard: skip if points were already credited (e.g. items added earlier
    // already awarded them via confirm_order.php), mirroring check_payment.php.
    $lc_id = (int)($order['loyalty_card_id'] ?? 0);
    if ($lc_id > 0 && ($order['payment_method'] ?? '') === 'paylater' && (int)($order['points_earned'] ?? 0) === 0) {
        $pts_stmt = $conn->prepare("SELECT SUM(quantity) AS total_qty FROM order_items WHERE order_id = ?");
        $pts_stmt->bind_param("i", $order_id);
        $pts_stmt->execute();
        $pts = (int)($pts_stmt->get_result()->fetch_assoc()['total_qty'] ?? 0);
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

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: $return_page");
    exit;
}

// Auto-redirect after 2 seconds
header("refresh:2; url=$return_page");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Cash Payment | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg: #0b0b0b;
            --bg-card: #121212;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            
            /* ── Shadow System ── */
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 500px;
            width: 100%;
        }

        .payment-card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px 32px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            text-align: center;
        }

        .payment-card .icon {
            font-size: 64px;
            color: var(--success);
            margin-bottom: 16px;
        }

        .payment-card .success-badge {
            display: inline-block;
            background: rgba(85, 224, 135, 0.15);
            color: var(--success);
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            margin-bottom: 16px;
            border: 1px solid rgba(85, 224, 135, 0.2);
        }

        .payment-card .success-badge i {
            margin-right: 6px;
        }

        .payment-card h1 {
            font-size: 26px;
            color: var(--text-light);
            margin-bottom: 4px;
        }

        .payment-card .subtitle {
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .order-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .order-info .item {
            text-align: center;
        }

        .order-info .item .label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .order-info .item .value {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-light);
        }

        .total-amount {
            font-size: 36px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 24px;
        }

        .redirect-note {
            color: var(--text-muted);
            font-size: 13px;
            margin-top: 16px;
        }

        .redirect-note i {
            color: var(--accent);
        }

        @media (max-width: 480px) {
            .payment-card {
                padding: 24px 18px;
            }
            .order-info {
                grid-template-columns: 1fr;
            }
            .total-amount {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="payment-card">
        <div class="icon">
            <i class="fa-solid fa-money-bill-wave"></i>
        </div>
        
        <div class="success-badge">
            <i class="fa-solid fa-check-circle"></i> Payment Confirmed
        </div>
        
        <h1>Cash Payment</h1>
        <p class="subtitle">Order #<?= $order['daily_order_no'] ?> has been marked as paid.</p>

        <div class="order-info">
            <div class="item">
                <div class="label">Order #</div>
                <div class="value">#<?= $order['daily_order_no'] ?></div>
            </div>
            <div class="item">
                <div class="label">Customer</div>
                <div class="value"><?= htmlspecialchars($order['customer_name']) ?></div>
            </div>
        </div>

        <div class="total-amount">
            $<?= number_format($order['total'], 2) ?>
        </div>

        <div class="redirect-note">
            <i class="fa-solid fa-arrow-right"></i> Redirecting back to unpaid orders...
        </div>
    </div>
</div>

</body>
</html>