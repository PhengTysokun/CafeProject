<?php
require 'config.php';

/* =========================
   FETCH ORDERS
========================= */
date_default_timezone_set("Asia/Phnom_Penh");

$now = new DateTime();
if ((int)$now->format("H") < 6) {
    $business_date = $now->modify("-1 day")->format("Y-m-d");
} else {
    $business_date = $now->format("Y-m-d");
}

// AJAX handler - returns ONLY order cards (no header, no HTML wrapper)
if (isset($_GET['ajax'])) {
    $sql = "
        SELECT 
            o.order_id,
            o.daily_order_no,
            o.customer_name,
            o.status
        FROM orders o
        WHERE o.business_date = ?
          AND o.status IN ('Preparing', 'Completed')
        ORDER BY 
            CASE o.status
                WHEN 'Preparing' THEN 1
                WHEN 'Completed' THEN 2
            END,
            o.order_id ASC
        LIMIT 20
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $business_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }

    if (count($orders) > 0) {
        foreach ($orders as $o) {
            $status = $o['status'];
            $statusClass = strtolower($status);
            $statusIcon = $status === 'Completed' ? 'fa-circle-check' : 'fa-clock';
            $statusLabel = $status === 'Completed' ? '✅ Ready!' : '⏳ Preparing';
            ?>
            <div class="order-card" data-order-id="<?= $o['order_id'] ?>" data-status="<?= $status ?>" data-created="<?= time() ?>">
                <div class="status-icon <?= $statusClass ?>">
                    <i class="fa-solid <?= $statusIcon ?>"></i>
                </div>
                <div class="order-number">#<?= htmlspecialchars($o['daily_order_no']) ?></div>
                <div class="customer-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                <div class="status-badge <?= $statusClass ?>">
                    <?= $statusLabel ?>
                </div>
            </div>
            <?php
        }
    } else {
        ?>
        <div class="empty-state">
            <i class="fa-regular fa-rectangle-list"></i>
            <h3>No orders today</h3>
            <p>Check back soon!</p>
        </div>
        <?php
    }
    exit;
}

// ── Main page ──
$sql = "
    SELECT 
        o.order_id,
        o.daily_order_no,
        o.customer_name,
        o.status
    FROM orders o
    WHERE o.business_date = ?
      AND o.status IN ('Preparing', 'Completed')
    ORDER BY 
        CASE o.status
            WHEN 'Preparing' THEN 1
            WHEN 'Completed' THEN 2
        END,
        o.order_id ASC
    LIMIT 20
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $business_date);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ── RESET & ROOT ── */
        :root {
            --bg: #0c0c0c;
            --bg-card: #121212;
            --border: #1f1f1f;
            --accent: #d1904b;
            --success: #55e087;
            --warning: #f1c40f;
            --text: #f5f5f5;
            --text-muted: #888888;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }

        /* ── HEADER ── */
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px 0;
            width: 100%;
            border-bottom: 1px solid var(--border);
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .header h1 i {
            font-size: 24px;
        }

        .header .subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 4px;
        }

        .header .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            padding: 6px 16px;
            background: rgba(85, 224, 135, 0.1);
            border-radius: 50px;
            font-size: 12px;
            color: var(--success);
            border: 1px solid rgba(85, 224, 135, 0.2);
        }

        .header .live-indicator .dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse-dot 1.5s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        /* ── TOGGLE BUTTON ── */
        .toggle-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .toggle-btn {
            padding: 8px 20px;
            border-radius: 50px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text);
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .toggle-btn:hover {
            border-color: var(--accent);
            box-shadow: var(--shadow-accent);
        }

        /* ── ORDER GRID ── */
        .order-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            max-width: 1200px;
            width: 100%;
        }

        .order-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .order-card:hover {
            transform: translateY(-4px);
            border-color: var(--border);
        }

        .order-card .order-number {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .order-card .customer-name {
            font-size: 18px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .order-card .status-badge {
            display: inline-block;
            padding: 8px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            margin-top: 8px;
        }

        .order-card .status-badge.preparing {
            background: rgba(241, 196, 15, 0.15);
            color: var(--warning);
            border: 1px solid rgba(241, 196, 15, 0.2);
        }

        .order-card .status-badge.ready {
            background: rgba(85, 224, 135, 0.15);
            color: var(--success);
            border: 1px solid rgba(85, 224, 135, 0.2);
        }

        .order-card .status-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        .order-card .status-icon.preparing {
            color: var(--warning);
        }

        .order-card .status-icon.ready {
            color: var(--success);
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-muted);
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 64px;
            display: block;
            margin-bottom: 16px;
            color: var(--border);
        }

        .empty-state h3 {
            color: var(--text);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 24px;
        }

        .empty-state p {
            font-size: 16px;
        }

        /* ── ANIMATIONS ── */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .order-card {
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes readyPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .order-card.ready-pulse {
            animation: readyPulse 0.5s ease 3;
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: scale(1);
            }
            to {
                opacity: 0;
                transform: scale(0.8);
            }
        }

        .order-card.fade-out {
            animation: fadeOut 0.3s ease forwards;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 22px;
            }

            .order-grid {
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
            }

            .order-card {
                padding: 16px;
            }

            .order-card .order-number {
                font-size: 36px;
            }

            .order-card .customer-name {
                font-size: 14px;
            }

            .order-card .status-badge {
                font-size: 14px;
                padding: 6px 18px;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 18px;
            }

            .order-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .order-card .order-number {
                font-size: 28px;
            }

            .order-card .customer-name {
                font-size: 13px;
            }

            .order-card .status-badge {
                font-size: 12px;
                padding: 4px 14px;
            }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>
            <i class="fa-solid fa-mug-hot"></i>
            Bird's Nest Coffee
        </h1>
        <div class="subtitle">Order Status</div>
        <div class="live-indicator">
            <span class="dot"></span>
            Live
        </div>
    </div>

    <!-- Toggle Button -->
    <div class="toggle-container">
        <button class="toggle-btn" id="toggleCompletedBtn" onclick="toggleCompleted()">
            <i class="fa-solid fa-eye"></i> Show Completed
        </button>
    </div>

    <div class="order-grid" id="orderGrid">
        <?php if (count($orders) > 0): ?>
            <?php foreach ($orders as $o): ?>
                <?php
                    $status = $o['status'];
                    $statusClass = strtolower($status);
                    $statusIcon = $status === 'Completed' ? 'fa-circle-check' : 'fa-clock';
                    $statusLabel = $status === 'Completed' ? '✅ Ready!' : '⏳ Preparing';
                ?>
                <div class="order-card" data-order-id="<?= $o['order_id'] ?>" data-status="<?= $status ?>" data-created="<?= time() ?>">
                    <div class="status-icon <?= $statusClass ?>">
                        <i class="fa-solid <?= $statusIcon ?>"></i>
                    </div>
                    <div class="order-number">#<?= htmlspecialchars($o['daily_order_no']) ?></div>
                    <div class="customer-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                    <div class="status-badge <?= $statusClass ?>">
                        <?= $statusLabel ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fa-regular fa-rectangle-list"></i>
                <h3>No orders today</h3>
                <p>Check back soon!</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let showCompleted = false;
        let readyTimestamps = new Map();

        // ── Toggle Completed Orders ──
        function toggleCompleted() {
            showCompleted = !showCompleted;
            const cards = document.querySelectorAll('.order-card');
            cards.forEach(card => {
                const status = card.dataset.status;
                if (status === 'Completed') {
                    card.style.display = showCompleted ? '' : 'none';
                }
            });
            
            const toggleBtn = document.getElementById('toggleCompletedBtn');
            toggleBtn.innerHTML = showCompleted 
                ? '<i class="fa-solid fa-eye-slash"></i> Hide Completed' 
                : '<i class="fa-solid fa-eye"></i> Show Completed';
        }

        // ── Hide completed orders on load ──
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.order-card');
            cards.forEach(card => {
                const status = card.dataset.status;
                if (status === 'Completed') {
                    card.style.display = 'none';
                }
            });
        });

        // ── Track ready orders to play sound ──
        let previousReadyOrders = new Set();

        // ── Play sound ──
        function playReadySound() {
            const audio = new Audio('audio/bell.wav');
            audio.play().catch(() => {});
        }

        // ── Refresh display ──
        function refreshDisplay() {
            fetch('customer_display.php?ajax=1')
                .then(res => res.text())
                .then(html => {
                    const grid = document.getElementById('orderGrid');
                    grid.innerHTML = html;

                    // Apply toggle state to new cards
                    const cards = grid.querySelectorAll('.order-card');
                    cards.forEach(card => {
                        const status = card.dataset.status;
                        if (status === 'Completed' && !showCompleted) {
                            card.style.display = 'none';
                        }
                    });

                    // Track ready timestamps for auto-remove
                    const readyCards = grid.querySelectorAll('.order-card[data-status="Completed"]');
                    const currentReadyOrders = new Set();
                    
                    readyCards.forEach(card => {
                        const id = card.dataset.orderId;
                        const created = parseInt(card.dataset.created);
                        
                        currentReadyOrders.add(id);
                        
                        // If this order wasn't ready before, record timestamp and play sound
                        if (!previousReadyOrders.has(id)) {
                            readyTimestamps.set(id, created);
                            playReadySound();
                            card.classList.add('ready-pulse');
                        }
                        
                        // If this order has been ready for more than 30 seconds, remove it
                        if (readyTimestamps.has(id)) {
                            const readyTime = readyTimestamps.get(id);
                            if (Date.now() - readyTime > 30000) {
                                card.classList.add('fade-out');
                                setTimeout(() => {
                                    if (card.parentElement) {
                                        card.remove();
                                        readyTimestamps.delete(id);
                                    }
                                }, 300);
                            }
                        }
                    });
                    
                    previousReadyOrders = currentReadyOrders;
                })
                .catch(() => {});
        }

        // ── Refresh every 3 seconds ──
        setInterval(refreshDisplay, 3000);

        // ── Initial load ──
        setTimeout(refreshDisplay, 500);
    </script>

</body>
</html>