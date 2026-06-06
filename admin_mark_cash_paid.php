<?php
session_start();
require 'admin_only.php';
require 'config.php';

$order_id = (int)($_GET['order_id'] ?? 0);

if ($order_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

// ── Fetch order and cash payment data ──
$stmt = $conn->prepare("
    SELECT o.order_id, o.daily_order_no, o.customer_name, o.total, o.status,
           op.payment_id, op.amount AS cash_amount
    FROM orders o
    LEFT JOIN order_payments op ON o.order_id = op.order_id AND op.payment_method = 'cash' AND op.payment_status = 'pending'
    WHERE o.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found.");
}

$cash_amount = (float)($order['cash_amount'] ?? 0);

// If no cash payment record found, use 0
if ($cash_amount <= 0) {
    // Check if there's any cash payment at all
    $stmt_check = $conn->prepare("
        SELECT amount FROM order_payments
        WHERE order_id = ? AND payment_method = 'cash'
    ");
    $stmt_check->bind_param("i", $order_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    if ($row = $result->fetch_assoc()) {
        $cash_amount = (float)$row['amount'];
    }
}

// ── Handle marking cash as paid ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();

    try {
        // 1. Mark cash payment as paid
        $stmt_payment = $conn->prepare("
            UPDATE order_payments
            SET payment_status = 'paid'
            WHERE order_id = ? AND payment_method = 'cash'
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

        // 3. If no pending payments, update order status to Preparing
        if ($pending['pending_count'] == 0) {
            $stmt_status = $conn->prepare("
                UPDATE orders
                SET status = 'Preparing'
                WHERE order_id = ?
                  AND status = 'PendingPayment'
            ");
            $stmt_status->bind_param("i", $order_id);
            $stmt_status->execute();
        }

        $conn->commit();

        // Redirect back to dashboard with success message
        $_SESSION['success'] = "Cash payment marked as paid for Order #{$order['daily_order_no']}";
        header("Location: dashboard.php");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        die("Error marking cash as paid: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Cash Paid | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg: #0c0c0c;
            --bg-card: #121212;
            --bg-card-hover: #181818;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            --purple: #9b59b6;
            --purple-dark: #8e44ad;
            
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-accent: 0 4px 20px rgba(209, 144, 75, 0.15);
            --shadow-purple: 0 4px 20px rgba(142, 68, 173, 0.3);
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--success), var(--accent-light));
        }

        .card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-lg);
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .header i {
            font-size: 48px;
            color: var(--success);
            display: block;
            margin-bottom: 8px;
        }

        .header h1 {
            color: var(--text-light);
            font-size: 24px;
            font-weight: 700;
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .order-info {
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .order-info .item {
            text-align: center;
        }

        .order-info .item .label {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 500;
            display: block;
        }

        .order-info .item .value {
            color: var(--text);
            font-size: 18px;
            font-weight: 600;
            margin-top: 4px;
        }

        .order-info .item .value.cash {
            color: var(--success);
        }

        .total-section {
            text-align: center;
            margin-bottom: 24px;
        }

        .total-section .label {
            color: var(--text-muted);
            font-size: 13px;
        }

        .total-section .amount {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent);
        }

        .actions {
            display: flex;
            gap: 12px;
        }

        .actions .btn {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .actions .btn-success {
            background: linear-gradient(135deg, var(--success), #2ecc71);
            color: #000;
        }

        .actions .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 20px rgba(85, 224, 135, 0.3);
            filter: brightness(1.1);
        }

        .actions .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .actions .btn-secondary:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
        }

        @media (max-width: 480px) {
            .card {
                padding: 24px;
            }

            .header h1 {
                font-size: 20px;
            }

            .order-info {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .total-section .amount {
                font-size: 26px;
            }

            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

    <div class="card">
        <div class="header">
            <i class="fa-solid fa-money-bill-wave"></i>
            <h1>Mark Cash Payment</h1>
            <p>Confirm that cash payment has been received</p>
        </div>

        <div class="order-info">
            <div class="item">
                <span class="label">Order #</span>
                <span class="value">#<?= $order['daily_order_no'] ?></span>
            </div>
            <div class="item">
                <span class="label">Customer</span>
                <span class="value"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <div class="item">
                <span class="label">Cash Amount</span>
                <span class="value cash">$<?= number_format($cash_amount, 2) ?></span>
            </div>
            <div class="item">
                <span class="label">Current Status</span>
                <span class="value"><?= $order['status'] ?></span>
            </div>
        </div>

        <div class="total-section">
            <div class="label">Total Order Amount</div>
            <div class="amount">$<?= number_format($order['total'], 2) ?></div>
        </div>

        <form method="POST">
            <div class="actions">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-check"></i> Confirm Cash Received
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fa-solid fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>

</body>
</html>