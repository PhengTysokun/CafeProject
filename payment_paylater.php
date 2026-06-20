<?php
require 'auth.php'; // starts session, loads config ($conn), re-syncs $_SESSION['role']
// Cashiers (staff) handle pay-later orders (add-to-order + settle) — allow them alongside admin/manager
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'staff'])) {
    header("Location: dashboard.php?denied=1");
    exit;
}

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($order_id <= 0) { header("Location: menu.php"); exit; }

$stmt = $conn->prepare("
    SELECT order_id, daily_order_no, customer_name, total, is_open, token_number, promotion_discount,
           order_type, table_number
    FROM orders
    WHERE order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) { header("Location: menu.php"); exit; }

// Clear session cart now that order is confirmed
$_SESSION['cart'] = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>(function(){if(localStorage.getItem('theme')==='dark'){document.documentElement.setAttribute('data-theme','dark');}})();</script>
    <title>Pay Later | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg-body: #f2ede8;
            --bg-card: #fffdf9;
            --bg-card-hover: #fdf7f0;
            --border: #e0d5c8;
            --border-hover: #c9b89f;
            --orange: #d1904b;
            --orange-dark: #a0702a;
            --orange-light: #e8b87a;
            --text-primary: #1a1410;
            --text-secondary: #5a4a3a;
            --text-muted: #9a8070;
            --text-inverse: #ffffff;
            --purple: #9b59b6;
            --purple-dark: #8e44ad;
            --purple-light: #bb8fce;
            --shadow-sm: 0 2px 10px rgba(90,60,20,0.07);
            --shadow-md: 0 6px 28px rgba(90,60,20,0.11);
            --shadow-lg: 0 12px 48px rgba(90,60,20,0.16);
            --shadow-purple: 0 4px 16px rgba(155,89,182,0.22);
            --shadow-purple-md: 0 8px 32px rgba(155,89,182,0.28);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --radius: 16px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-image:
                radial-gradient(ellipse at 20% 10%, rgba(155,89,182,0.06) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 90%, rgba(155,89,182,0.04) 0%, transparent 60%);
        }

        /* ── Card Container ── */
        .payment-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 32px 36px;
            max-width: 480px;
            width: 100%;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: cardFadeIn 0.7s ease both;
            transition: var(--transition);
        }

        .payment-card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-lg);
        }

        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .payment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--purple), var(--purple-light), var(--orange));
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 0% 0%; }
            50% { background-position: 100% 0%; }
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 16px;
            animation: fadeInUp 0.7s ease 0.1s both;
        }

        .header .icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(155,89,182,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            border: 1px solid rgba(155,89,182,0.15);
            transition: var(--transition);
        }

        .header .icon-wrapper i {
            font-size: 28px;
            color: var(--purple-light);
        }

        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
            letter-spacing: -0.5px;
        }

        .header h1 span {
            background: linear-gradient(135deg, var(--purple-light), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header p {
            color: var(--text-secondary);
            font-size: 13px;
        }

        /* ── Success Check ── */
        .success-check {
            display: flex;
            justify-content: center;
            margin-bottom: 14px;
            animation: fadeInUp 0.7s ease 0.2s both;
        }

        .success-check .circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(155,89,182,0.10);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--purple);
            animation: pulseCheck 2s ease-in-out infinite;
        }

        @keyframes pulseCheck {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(155,89,182,0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 8px rgba(155,89,182,0.1); }
        }

        .success-check .circle i {
            font-size: 22px;
            color: var(--purple);
        }

        /* ── Info Grid ── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 10px;
            border: 1px solid var(--border);
            animation: fadeInUp 0.7s ease 0.3s both;
            transition: var(--transition);
        }

        .info-grid:hover {
            border-color: var(--border-hover);
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-item .label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .info-item .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 2px;
        }

        .info-item .value.purple {
            color: var(--purple-light);
        }

        .info-item .value.highlight {
            color: var(--orange);
        }

        /* ── Token Row ── */
        .token-row {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            border-top: 1px solid var(--border);
            padding-top: 6px;
            margin-top: 2px;
        }

        .token-row .label {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .token-row .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--purple-light);
            margin-top: 2px;
        }

        /* ── Total Row ── */
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 14px;
            animation: fadeInUp 0.7s ease 0.4s both;
            transition: var(--transition);
        }

        .total-row:hover {
            border-color: var(--border-hover);
            background: rgba(255,255,255,0.05);
        }

        .total-row .label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .total-row .amount {
            font-size: 20px;
            font-weight: 700;
            color: var(--purple-light);
        }

        /* ── Status Badge ── */
        .status-row {
            display: flex;
            justify-content: center;
            margin-bottom: 14px;
            animation: fadeInUp 0.7s ease 0.5s both;
        }

        .status-row .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 18px;
            border-radius: 50px;
            background: rgba(155,89,182,0.08);
            color: var(--purple-light);
            border: 1px solid rgba(155,89,182,0.15);
            font-size: 12px;
            font-weight: 500;
            transition: var(--transition);
        }

        .status-row .badge:hover {
            background: rgba(155,89,182,0.15);
            border-color: var(--purple);
        }

        .status-row .badge i {
            font-size: 12px;
        }

        /* ── Actions ── */
        .actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            animation: fadeInUp 0.7s ease 0.6s both;
        }

        .btn {
            padding: 10px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
            text-align: center;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--purple), var(--purple-dark));
            color: var(--text-inverse);
        }

        .btn-primary:hover {
            box-shadow: var(--shadow-purple-md);
            filter: brightness(1.1);
        }

        .btn-add {
            background: var(--orange);
            color: var(--text-inverse);
        }

        .btn-add:hover {
            box-shadow: var(--shadow-orange-md);
            filter: brightness(1.1);
        }

        .btn-print {
            background: #c0392b;
            color: var(--text-inverse);
        }

        .btn-print:hover {
            box-shadow: 0 4px 20px rgba(192,57,43,0.3);
            filter: brightness(1.1);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            border-color: var(--purple);
            color: var(--text-primary);
        }

        /* ── Animations ── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .payment-card {
                padding: 24px 18px;
                max-width: 100%;
            }
            
            .info-grid {
                padding: 12px 14px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .total-row .amount {
                font-size: 18px;
            }
            
            .btn {
                padding: 9px;
                font-size: 12px;
            }
        }

        @media (max-width: 400px) {
            .payment-card {
                padding: 16px 12px;
            }
            
            .header .icon-wrapper {
                width: 44px;
                height: 44px;
            }
            
            .header .icon-wrapper i {
                font-size: 20px;
            }
            
            .success-check .circle {
                width: 40px;
                height: 40px;
            }
            
            .success-check .circle i {
                font-size: 18px;
            }
            
            .info-item .value {
                font-size: 14px;
            }
            
            .total-row .amount {
                font-size: 16px;
            }
        }

        /* ── DARK THEME ── */
        [data-theme="dark"] {
            --bg-body: #0c0c0c;
            --bg-card: #161616;
            --bg-card-hover: #1e1e1e;
            --border: #252525;
            --border-hover: #333;
            --text-primary: #f0f0f0;
            --text-secondary: #aaa;
            --text-muted: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.5);
            --shadow-md: 0 6px 28px rgba(0,0,0,0.6);
            --shadow-lg: 0 12px 48px rgba(0,0,0,0.7);
        }
        [data-theme="dark"] body { background-image: none; }
        [data-theme="dark"] .info-grid,
        [data-theme="dark"] .amount-breakdown,
        [data-theme="dark"] .info-box { background: rgba(255,255,255,0.02); }
    </style>
</head>
<body>

<div class="payment-card">
    <!-- Header -->
    <div class="header">
        <div class="icon-wrapper">
            <i class="fa-solid fa-clock"></i>
        </div>
        <h1><span>Pay Later</span></h1>
        <p>Your order has been recorded and will be prepared</p>
    </div>

    <!-- Success Check -->
    <div class="success-check">
        <div class="circle">
            <i class="fa-solid fa-check"></i>
        </div>
    </div>

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-item">
            <span class="label">Order #</span>
            <span class="value highlight">#<?php echo (int)$order['daily_order_no']; ?></span>
        </div>
        <div class="info-item" style="align-items: flex-end;">
            <span class="label">Customer</span>
            <span class="value" style="text-align: right;"><?php echo htmlspecialchars($order['customer_name']); ?></span>
        </div>
        <?php if (!empty($order['table_number'])): ?>
        <div class="info-item">
            <span class="label">Table</span>
            <span class="value highlight">#<?php echo htmlspecialchars($order['table_number']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Total Row -->
    <div class="total-row">
        <span class="label">Total Amount</span>
        <span class="amount">$<?php echo number_format((float)$order['total'], 2); ?></span>
    </div>

    <!-- Status Badge -->
    <div class="status-row">
        <div class="badge">
            <i class="fa-solid fa-hourglass-half"></i>
            Preparing
        </div>
    </div>

    <!-- Actions -->
    <div class="actions">
        <a href="view_order.php" class="btn btn-primary">
            <i class="fa-solid fa-receipt"></i> View Orders
        </a>
        <?php if ($order['is_open'] == 1): ?>
        <a href="add_to_existing_order.php?order_id=<?php echo $order_id; ?>" class="btn btn-add">
            <i class="fa-solid fa-plus"></i> Add More Items
        </a>
        <?php endif; ?>
        <a href="receipt_paylater.php?order_id=<?php echo $order_id; ?>" target="_blank" class="btn btn-print">
            <i class="fa-solid fa-file-pdf"></i> Print Receipt
        </a>
        <a href="menu.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Menu
        </a>
        <a href="find_order.php?tab=paylater" class="btn btn-secondary">
            <i class="fa-solid fa-list"></i> Pay Later Queue
        </a>
    </div>
</div>

</body>
</html>