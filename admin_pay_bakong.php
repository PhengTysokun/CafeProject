<?php
require 'auth.php'; // starts session, loads config ($conn), re-syncs $_SESSION['role']
// Cashiers (staff) collect pay-later payments from find_order.php — allow them alongside admin/manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}
require __DIR__ . '/bakong-khqr-php-main/vendor/autoload.php';

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;

$config = require __DIR__ . '/bakong_config.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$return_page = ($_GET['return'] ?? '') === 'dashboard' ? 'dashboard.php' : 'find_order.php?tab=pending';

if ($order_id <= 0) {
    header("Location: $return_page");
    exit;
}

// Fetch order details
$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, status, bakong_md5, payment_method
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

// Generate KHQR
$amount = (float)$order['total'];
$individualInfo = new IndividualInfo(
    bakongAccountID: $config['bakong_id'],
    merchantName: $config['merchant_name'],
    merchantCity: $config['merchant_city'],
    currency: $config['currency'],
    amount: $amount,
    billNumber: 'ORDER_' . $order_id,
    storeLabel: 'ObsidianCafe',
    terminalLabel: 'POS1',
    mobileNumber: $config['mobile_number'],
    expirationTimestamp: strval((time() + 15 * 60) * 1000)
);

$khqrResponse = BakongKHQR::generateIndividual($individualInfo);

if (($khqrResponse->status['code'] ?? 1) !== 0 || empty($khqrResponse->data['qr'])) {
    die('<h2 style="color:#ff6b6b;font-family:Poppins,sans-serif;text-align:center;padding:60px 20px;">
            <i class="fa-solid fa-triangle-exclamation"></i><br>Failed to generate Bakong QR.<br>
            <a href="<?= htmlspecialchars($return_page) ?>" style="font-size:14px;color:#d1904b;">← Back to orders</a>
         </h2>');
}

$qrString = $khqrResponse->data['qr'];
$qrMd5    = $khqrResponse->data['md5'] ?? '';

// First-time setup: store md5 and wire up payment records so check_payment.php can track this transaction
$existing_md5 = $order['bakong_md5'] ?? '';
if (empty($existing_md5) && !empty($qrMd5)) {
    $conn->begin_transaction();
    try {
        // Persist md5 — required by check_payment.php to verify with Bakong API
        $stmt_md5 = $conn->prepare("UPDATE orders SET bakong_md5 = ? WHERE order_id = ?");
        $stmt_md5->bind_param("si", $qrMd5, $order_id);
        $stmt_md5->execute();

        // Insert a bakong payment record if one doesn't exist yet (paylater orders won't have one)
        $stmt_chk = $conn->prepare("SELECT payment_id FROM order_payments WHERE order_id = ? AND payment_method = 'bakong' LIMIT 1");
        $stmt_chk->bind_param("i", $order_id);
        $stmt_chk->execute();
        if (!$stmt_chk->get_result()->fetch_assoc()) {
            $stmt_pay = $conn->prepare("INSERT INTO order_payments (order_id, payment_method, amount, reference, payment_status) VALUES (?, 'bakong', ?, '', 'pending')");
            $stmt_pay->bind_param("id", $order_id, $amount);
            $stmt_pay->execute();
        }

        // For paylater orders: resolve the original paylater record so that once
        // Bakong confirms, pending_count reaches 0 and the order transitions to Paid
        if ($order['payment_method'] === 'paylater') {
            $stmt_settle = $conn->prepare("UPDATE order_payments SET payment_status = 'paid' WHERE order_id = ? AND payment_method = 'paylater'");
            $stmt_settle->bind_param("i", $order_id);
            $stmt_settle->execute();
        }

        $conn->commit();
        $order['bakong_md5'] = $qrMd5;
    } catch (Exception $e) {
        $conn->rollback();
    }
}

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrString);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bakong Payment | Bird's Nest Coffee</title>
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
            color: var(--accent);
            margin-bottom: 16px;
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

        .qr-section {
            margin-bottom: 24px;
        }

        .qr-box {
            background: #ffffff;
            display: inline-block;
            padding: 16px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            position: relative;
        }

        .qr-box img {
            display: block;
            width: 220px;
            height: 220px;
            border-radius: 8px;
        }

        .qr-box .expiry-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: var(--accent);
            color: #000;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            box-shadow: var(--shadow-accent);
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-radius: 50px;
            background: rgba(209, 144, 75, 0.08);
            border: 1px solid rgba(209, 144, 75, 0.15);
            margin-bottom: 20px;
        }

        .status-indicator .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(209, 144, 75, 0.2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-indicator .status-text {
            color: var(--accent);
            font-weight: 600;
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
            .qr-box img {
                width: 180px;
                height: 180px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="payment-card">
        <div class="icon">
            <i class="fa-solid fa-qrcode"></i>
        </div>
        
        <h1>Bakong Payment</h1>
        <p class="subtitle">Order #<?= $order['daily_order_no'] ?> — Scan to pay</p>

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

        <div class="qr-section">
            <div class="qr-box">
                <img src="<?= htmlspecialchars($qrUrl); ?>" alt="Bakong KHQR">
                <span class="expiry-badge">15 min</span>
            </div>
        </div>

        <div class="status-indicator" id="statusIndicator">
            <span class="spinner"></span>
            <span class="status-text" id="statusText">Waiting for payment...</span>
        </div>

        <div class="redirect-note" style="display: none;" id="redirectNote">
            <i class="fa-solid fa-check-circle" style="color: var(--success);"></i> Payment received! Redirecting...
        </div>
    </div>
</div>

<script>
const orderId = <?= (int)$order_id ?>;

async function checkPayment() {
    try {
        const res = await fetch(`check_payment.php?order_id=${orderId}`, { cache: "no-store" });
        const data = await res.json();

        if (data.paid) {
            const indicator = document.getElementById('statusIndicator');
            const statusText = document.getElementById('statusText');
            const redirectNote = document.getElementById('redirectNote');
            
            indicator.innerHTML = `
                <i class="fa-solid fa-check-circle" style="color: #55e087;"></i>
                <span style="color: #55e087; font-weight: 600;">Payment received!</span>
            `;
            redirectNote.style.display = 'block';
            
            setTimeout(() => {
                window.location.href = '<?= htmlspecialchars($return_page) ?>';
            }, 2000);
        }
    } catch (e) {
        console.error('Payment check error:', e);
    }
}

// Check every 2 seconds
setInterval(checkPayment, 2000);
checkPayment();
</script>

</body>
</html>