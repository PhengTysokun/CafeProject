<?php
require 'auth.php';
require_once 'config.php';
if (!can('find_orders')) { header("Location: dashboard.php?denied=1"); exit; }

// ── Handle AJAX close-order request ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_order') {
    header('Content-Type: application/json');
    $oid = (int)($_POST['order_id'] ?? 0);
    if ($oid > 0) {
        $stmt = $conn->prepare("UPDATE orders SET is_open = 0 WHERE order_id = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

$search_type  = $_GET['search_type']  ?? 'all';
$search_value = $_GET['search_value'] ?? '';
$filter_tab   = $_GET['tab']          ?? 'all';

// ── Main query: unpaid OR paid-but-open orders ──
$sql = "
SELECT order_id, daily_order_no, customer_name, total, status, payment_method, order_date, is_open, token_number
FROM orders
WHERE (
    (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
    OR (status = 'Paid' AND is_open = 1)
)
";

if (!empty($search_value)) {
    $safe = $conn->real_escape_string($search_value);
    if ($search_type === 'order_id') {
        $sql .= " AND daily_order_no = '$safe'";
    } else {
        $sql .= " AND customer_name LIKE '%$safe%'";
    }
}

// Tab filter
if ($filter_tab === 'preparing') {
    $sql .= " AND status = 'Preparing'";
} elseif ($filter_tab === 'pending') {
    $sql .= " AND status = 'PendingPayment'";
} elseif ($filter_tab === 'paid_open') {
    $sql .= " AND status = 'Paid' AND is_open = 1";
} elseif ($filter_tab === 'paylater') {
    $sql .= " AND status = 'Preparing' AND payment_method = 'paylater'";
}

$sql .= " ORDER BY order_date DESC";
$result = mysqli_query($conn, $sql);
$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}

// ── Tab counts ──
$count_sql = "
SELECT status, is_open, payment_method, COUNT(*) as cnt
FROM orders
WHERE (
    (status NOT IN ('Completed','Cancelled','Refunded') AND status != 'Paid')
    OR (status = 'Paid' AND is_open = 1)
)
GROUP BY status, is_open, payment_method
";
$count_result = mysqli_query($conn, $count_sql);
$tab_counts = ['all' => 0, 'preparing' => 0, 'pending' => 0, 'paid_open' => 0, 'paylater' => 0];
while ($r = mysqli_fetch_assoc($count_result)) {
    $tab_counts['all'] += $r['cnt'];
    if ($r['status'] === 'Preparing') {
        $tab_counts['preparing'] += $r['cnt'];
        if ($r['payment_method'] === 'paylater') $tab_counts['paylater'] += $r['cnt'];
    }
    if ($r['status'] === 'PendingPayment') $tab_counts['pending'] += $r['cnt'];
    if ($r['status'] === 'Paid' && $r['is_open'] == 1) $tab_counts['paid_open'] += $r['cnt'];
}

$total_unpaid = array_sum(array_column($orders, 'total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Orders | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #0c0c0c;
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
            --warning: #f1c40f;
            --danger: #ff6b6b;
            --purple: #9b59b6;
            --info: #3498db;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-accent: 0 4px 20px rgba(209,144,75,0.15);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="light"] {
            --bg: #f0ece8; --bg-card: #faf8f6; --bg-card-hover: #f3ede8;
            --bg-input: #f5f1ed; --border: #ddd5cc; --border-hover: #c9bfb6;
            --text: #1e1a16; --text-muted: #6b5f55; --text-light: #1e1a16;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.08); --shadow-md: 0 4px 20px rgba(0,0,0,0.10);
        }
        [data-theme="light"] input,[data-theme="light"] select,[data-theme="light"] textarea {
            background-color: var(--bg-input) !important; color: var(--text) !important;
            border-color: var(--border) !important; color-scheme: light;
        }
        [data-theme="light"] .order-card { background: var(--bg-card); border-color: var(--border); }
        [data-theme="light"] .tab-btn { background: var(--bg-card); border-color: var(--border); color: var(--text); }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; padding: 32px 20px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

        .page-wrapper { max-width: 960px; margin: 0 auto; }

        /* Top bar */
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px; }
        .top-bar a { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 50px; background: var(--bg-card); border: 1px solid var(--border); color: var(--text); text-decoration: none; transition: var(--transition); font-weight: 500; font-size: 14px; }
        .top-bar a:hover { border-color: var(--accent); box-shadow: var(--shadow-accent); }
        .top-bar a i { color: var(--accent); }
        .theme-toggle { cursor: pointer; }

        /* Header */
        .header { text-align: center; margin-bottom: 28px; }
        .header h1 { font-size: 26px; color: var(--text-light); margin-bottom: 4px; }
        .header h1 i { color: var(--accent); margin-right: 8px; }
        .header p { color: var(--text-muted); font-size: 14px; }

        /* Search box */
        .search-box { background: var(--bg-card); border-radius: 16px; padding: 20px; border: 1px solid var(--border); margin-bottom: 20px; box-shadow: var(--shadow-md); }
        .search-form { display: flex; gap: 10px; flex-wrap: wrap; }
        .search-form select, .search-form input {
            padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--bg); color: var(--text); font-size: 14px;
            font-family: 'Poppins', sans-serif; transition: var(--transition); outline: none;
        }
        .search-form select { flex: 0 0 150px; }
        .search-form input { flex: 1; min-width: 180px; }
        .search-form select:focus, .search-form input:focus { border-color: var(--accent); box-shadow: var(--shadow-accent); }
        .search-form button { padding: 11px 22px; background: var(--accent); border: none; border-radius: 10px; color: #000; font-weight: 600; cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 6px; }
        .search-form button:hover { background: var(--accent-light); transform: translateY(-1px); }
        .btn-clear { padding: 11px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--bg); color: var(--text-muted); text-decoration: none; display: flex; align-items: center; transition: var(--transition); }
        .btn-clear:hover { color: var(--danger); border-color: var(--danger); }

        /* Tab filters */
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .tab-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
            border-radius: 50px; border: 1px solid var(--border); background: var(--bg-card);
            color: var(--text-muted); text-decoration: none; font-size: 13px; font-weight: 600;
            transition: var(--transition); cursor: pointer;
        }
        .tab-btn:hover { border-color: var(--accent); color: var(--accent); }
        .tab-btn.active { background: var(--accent); border-color: var(--accent); color: #000; }
        .tab-btn.active .tab-count { background: rgba(0,0,0,0.2); color: #000; }
        .tab-count { background: rgba(255,255,255,0.1); padding: 1px 8px; border-radius: 20px; font-size: 11px; }
        .tab-btn.tab-addable { border-color: var(--success); color: var(--success); }
        .tab-btn.tab-addable:hover, .tab-btn.tab-addable.active { background: var(--success); color: #000; border-color: var(--success); }
        .tab-btn.tab-paylater { border-color: var(--purple); color: var(--purple); }
        .tab-btn.tab-paylater:hover, .tab-btn.tab-paylater.active { background: var(--purple); color: #fff; border-color: var(--purple); }

        /* Guidance callout */
        .guidance-callout {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(209,144,75,0.08);
            border: 1px solid rgba(209,144,75,0.25);
            border-left: 3px solid var(--accent);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 18px;
            animation: fadeSlideIn 0.4s ease;
        }

        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .guidance-icon { color: var(--accent); font-size: 18px; flex-shrink: 0; margin-top: 1px; }

        .guidance-text {
            flex: 1;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .guidance-text strong { color: var(--text-light); }

        .guide-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .guide-pill.cash { background: rgba(85,224,135,0.15); color: #55e087; border: 1px solid rgba(85,224,135,0.25); }
        .guide-pill.bakong { background: rgba(52,152,219,0.15); color: #5dade2; border: 1px solid rgba(52,152,219,0.25); }

        .guidance-dismiss {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 2px 4px;
            flex-shrink: 0;
            transition: color 0.2s;
            line-height: 1;
        }

        .guidance-dismiss:hover { color: var(--text-light); }

        /* Results header */
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; }
        .results-header h2 { font-size: 18px; color: var(--text-light); }
        .results-header .meta { display: flex; gap: 10px; align-items: center; }
        .count-badge { background: var(--purple); color: #fff; padding: 3px 14px; border-radius: 50px; font-size: 12px; font-weight: 700; }
        .total-amount { color: var(--accent); font-weight: 700; font-size: 14px; }

        /* Order card */
        .order-card {
            background: var(--bg-card); border-radius: 16px; padding: 18px 20px;
            border: 1px solid var(--border); margin-bottom: 12px;
            transition: var(--transition); box-shadow: var(--shadow-sm);
        }
        .order-card:hover { border-color: var(--border-hover); box-shadow: var(--shadow-md); }
        .order-card.can-add { border-left: 3px solid var(--accent); }
        .order-card.is-paid-open { border-left: 3px solid var(--success); }

        .card-top { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
        .card-main-info { display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }

        .token-badge { font-size: 26px; font-weight: 800; color: var(--accent); min-width: 48px; text-align: center; }
        .token-badge.empty { font-size: 14px; color: var(--text-muted); font-weight: 400; }

        .info-group { display: flex; flex-direction: column; }
        .info-label { font-size: 10px; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-value { font-size: 15px; font-weight: 600; color: var(--text-light); }
        .info-value.total { color: var(--accent); }
        .info-value.small { font-size: 13px; }

        /* Status badges */
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-badge.preparing { background: rgba(209,144,75,0.15); color: var(--accent); }
        .status-badge.pendingpayment { background: rgba(255,183,77,0.15); color: #ffb74d; }
        .status-badge.paid { background: rgba(85,224,135,0.15); color: var(--success); }
        .status-badge.refunded { background: rgba(155,89,182,0.15); color: var(--purple); }

        .open-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; background: rgba(209,144,75,0.12); color: var(--accent); border: 1px solid rgba(209,144,75,0.3); margin-left: 6px; }
        .open-badge i { font-size: 9px; }

        .card-bottom { margin-top: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; border-top: 1px solid var(--border); padding-top: 12px; }
        .card-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 12px; flex-wrap: wrap; }
        .card-meta span { display: flex; align-items: center; gap: 5px; }

        /* Action buttons */
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn { padding: 7px 14px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 12px; }
        .btn-add { background: var(--accent); color: #000; }
        .btn-add:hover { background: var(--accent-light); transform: translateY(-2px); box-shadow: var(--shadow-accent); }
        .btn-pay-cash { background: var(--success); color: #000; }
        .btn-pay-cash:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(85,224,135,0.3); }
        .btn-pay-bakong { background: var(--info); color: #fff; }
        .btn-pay-bakong:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(52,152,219,0.3); }
        .btn-receipt { background: #c0392b; color: #fff; }
        .btn-receipt:hover { background: #e74c3c; transform: translateY(-2px); }
        .btn-view { background: var(--purple); color: #fff; }
        .btn-view:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(155,89,182,0.3); }
        .btn-close { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }

        /* ── Loyalty Modal ── */
        #lpModal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.75); z-index: 9999;
            align-items: center; justify-content: center; padding: 20px;
        }
        .lp-modal-box {
            background: var(--bg-card); border: 1px solid var(--border);
            border-radius: 16px; padding: 28px 24px; max-width: 380px; width: 100%;
            box-shadow: var(--shadow-lg);
        }
        .lp-modal-box h3 { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .lp-modal-box h3 i { color: var(--purple); }
        .lp-modal-subtitle { font-size: 12px; color: var(--text-muted); margin-bottom: 18px; }
        .lp-input-row { display: flex; gap: 8px; margin-bottom: 10px; }
        .lp-input-row input {
            flex: 1; padding: 9px 12px; background: #1a1a1a; border: 1px solid var(--border);
            border-radius: 8px; color: var(--text); font-family: 'Poppins', sans-serif;
            font-size: 13px; outline: none; transition: var(--transition);
        }
        .lp-input-row input:focus { border-color: var(--purple); }
        .btn-lp-lookup {
            padding: 9px 14px; background: rgba(155,89,182,0.15); border: 1px solid rgba(155,89,182,0.3);
            border-radius: 8px; color: var(--purple); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif;
        }
        .btn-lp-lookup:hover { background: rgba(155,89,182,0.25); }
        .lp-status { font-size: 12px; min-height: 18px; margin-bottom: 16px; }
        .lp-status.lp-success { color: var(--success); }
        .lp-status.lp-error   { color: var(--danger); }
        .lp-status.lp-loading { color: var(--text-muted); }
        .lp-modal-actions { display: flex; gap: 8px; }
        .btn-lp-skip {
            flex: 1; padding: 10px; background: transparent; border: 1px solid var(--border);
            border-radius: 8px; color: var(--text-muted); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: var(--transition); font-family: 'Poppins', sans-serif;
        }
        .btn-lp-skip:hover { border-color: var(--text-muted); color: var(--text); }
        .btn-lp-confirm {
            flex: 2; padding: 10px; background: var(--purple); border: none; border-radius: 8px;
            color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition);
            font-family: 'Poppins', sans-serif; display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-lp-confirm:hover:not(:disabled) { filter: brightness(1.15); }
        .btn-lp-confirm:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-close:hover { color: var(--danger); border-color: var(--danger); }
        .btn-cancel-order { background: transparent; color: var(--danger); border: 1px solid rgba(231,76,60,.35); }
        .btn-cancel-order:hover { background: rgba(231,76,60,.1); border-color: var(--danger); }
        .btn-edit { background: transparent; color: var(--purple); border: 1px solid rgba(155,89,182,.4); }
        .btn-edit:hover { background: var(--purple); color: #fff; border-color: var(--purple); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(155,89,182,.3); }

        /* Empty state */
        .no-results { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .no-results i { font-size: 48px; margin-bottom: 16px; display: block; }

        /* Hidden for JS filtering */
        .order-card.hidden { display: none; }

        @media (max-width: 640px) {
            body { padding: 20px 12px; }
            .search-form { flex-direction: column; }
            .search-form select, .search-form input, .search-form button { width: 100%; }
            .card-top { flex-direction: column; }
            .actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>

<div class="page-wrapper">

    <!-- Top Bar -->
    <div class="top-bar">
        <a href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        <button class="top-bar a theme-toggle" onclick="toggleTheme()" style="background:var(--bg-card);border:1px solid var(--border);border-radius:50px;padding:8px 16px;cursor:pointer;color:var(--text);font-family:'Poppins',sans-serif;font-size:14px;font-weight:500;display:inline-flex;align-items:center;gap:8px;">
            <i id="themeIcon" class="fa-solid fa-moon" style="color:var(--accent);"></i>
            <span id="themeText">Dark</span>
        </button>
    </div>

    <!-- Header -->
    <div class="header">
        <h1><i class="fa-solid fa-magnifying-glass"></i> Find Orders</h1>
        <p>Search, filter, and manage all active orders in one place</p>
    </div>

    <!-- Search Box -->
    <div class="search-box">
        <form class="search-form" method="GET" action="">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($filter_tab) ?>">
            <select name="search_type">
                <option value="order_id"      <?= $search_type === 'order_id'      ? 'selected' : '' ?>>Order #</option>
                <option value="customer_name" <?= $search_type === 'customer_name' ? 'selected' : '' ?>>Customer Name</option>
            </select>
            <input type="text" name="search_value" placeholder="Search orders..." value="<?= htmlspecialchars($search_value) ?>" autofocus>
            <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if (!empty($search_value)): ?>
            <a href="find_order.php?tab=<?= htmlspecialchars($filter_tab) ?>" class="btn-clear">
                <i class="fa-solid fa-xmark"></i>
            </a>
            <?php endif; ?>
        </form>

    </div>

    <!-- Tab Filters -->
    <div class="tabs">
        <a href="?tab=all<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn <?= $filter_tab === 'all' ? 'active' : '' ?>">
            <i class="fa-solid fa-layer-group"></i> All Active
            <span class="tab-count"><?= $tab_counts['all'] ?></span>
        </a>
        <a href="?tab=preparing<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn <?= $filter_tab === 'preparing' ? 'active' : '' ?>">
            <i class="fa-solid fa-fire"></i> Preparing
            <span class="tab-count"><?= $tab_counts['preparing'] ?></span>
        </a>
        <a href="?tab=pending<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn <?= $filter_tab === 'pending' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i> Pending Payment
            <span class="tab-count"><?= $tab_counts['pending'] ?></span>
        </a>
        <a href="?tab=paid_open<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn tab-addable <?= $filter_tab === 'paid_open' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-plus"></i> Paid + Open
            <span class="tab-count"><?= $tab_counts['paid_open'] ?></span>
        </a>
        <a href="?tab=paylater<?= !empty($search_value) ? '&search_type='.urlencode($search_type).'&search_value='.urlencode($search_value) : '' ?>"
           class="tab-btn tab-paylater <?= $filter_tab === 'paylater' ? 'active' : '' ?>">
            <i class="fa-solid fa-wallet"></i> Pay Later
            <span class="tab-count"><?= $tab_counts['paylater'] ?></span>
        </a>
    </div>

    <?php if ($filter_tab === 'pending'): ?>
    <div class="guidance-callout">
        <div class="guidance-icon"><i class="fa-solid fa-hand-point-right"></i></div>
        <div class="guidance-text">
            <strong>How to collect payment:</strong>
            Find the customer's order below, then click <span class="guide-pill cash"><i class="fa-solid fa-money-bill-wave"></i> Cash</span>
            for cash payment or <span class="guide-pill bakong"><i class="fa-solid fa-qrcode"></i> Bakong</span> to show the QR code.
        </div>
        <button class="guidance-dismiss" onclick="this.closest('.guidance-callout').style.display='none'" title="Dismiss">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <?php endif; ?>

    <?php if (count($orders) > 0): ?>

    <!-- Results Header -->
    <div class="results-header">
        <h2 id="resultsTitle">
            <?php
            $tabLabels = ['all'=>'All Active Orders','preparing'=>'Preparing','pending'=>'Pending Payment','paid_open'=>'Paid + Open Orders','paylater'=>'Pay Later Orders'];
            echo $tabLabels[$filter_tab] ?? 'Orders';
            ?>
        </h2>
        <div class="meta">
            <span class="count-badge" id="visibleCount"><?= count($orders) ?> orders</span>
            <span class="total-amount">$<?= number_format($total_unpaid, 2) ?></span>
        </div>
    </div>

    <!-- Order Cards -->
    <div id="orderList">
    <?php foreach ($orders as $order):
        $isPaidOpen = ($order['status'] === 'Paid' && $order['is_open'] == 1);
        $canAdd     = ($order['is_open'] == 1 && in_array($order['status'], ['Preparing', 'Paid']));
        $cardClass  = $isPaidOpen ? 'is-paid-open' : ($canAdd ? 'can-add' : '');
        $statusClass = strtolower($order['status']);
        $tz   = new DateTimeZone('Asia/Phnom_Penh');
        $now  = new DateTime('now', $tz);
        $then = new DateTime($order['order_date'], $tz);
        $diff = $now->getTimestamp() - $then->getTimestamp();
        if ($diff < 0) {
            $absDiff = abs($diff);
            if ($absDiff < 3600)       $timeAgo = 'in ' . floor($absDiff/60) . 'm';
            elseif ($absDiff < 86400)  $timeAgo = 'in ' . floor($absDiff/3600) . 'h';
            else                       $timeAgo = 'in ' . floor($absDiff/86400) . 'd';
        } elseif ($diff < 60)          $timeAgo = $diff . 's ago';
        elseif ($diff < 3600)          $timeAgo = floor($diff/60) . 'm ago';
        elseif ($diff < 86400)         $timeAgo = floor($diff/3600) . 'h ' . floor(($diff%3600)/60) . 'm ago';
        else                           $timeAgo = floor($diff/86400) . 'd ago';
    ?>
    <div class="order-card <?= $cardClass ?>" 
         data-name="<?= strtolower(htmlspecialchars($order['customer_name'])) ?>"
         data-token="<?= $order['token_number'] ?>"
         data-amount="<?= $order['total'] ?>"
         data-order="<?= $order['daily_order_no'] ?>">

        <div class="card-top">
            <!-- Left: main info -->
            <div class="card-main-info">

                <div class="info-group">
                    <span class="info-label">Order</span>
                    <span class="info-value">#<?= (int)$order['daily_order_no'] ?></span>
                </div>

                <div class="info-group">
                    <span class="info-label">Customer</span>
                    <span class="info-value small"><?= htmlspecialchars($order['customer_name']) ?></span>
                </div>

                <div class="info-group">
                    <span class="info-label">Total</span>
                    <span class="info-value total">$<?= number_format($order['total'], 2) ?></span>
                </div>

                <div class="info-group">
                    <span class="info-label">Status</span>
                    <span>
                        <span class="status-badge <?= $statusClass ?>">
                            <?php
                            $icons = ['Preparing'=>'fa-fire','PendingPayment'=>'fa-clock','Paid'=>'fa-check-circle','Refunded'=>'fa-rotate-left'];
                            $icon = $icons[$order['status']] ?? 'fa-circle';
                            $labels = ['PendingPayment'=>'Pending Payment','Preparing'=>'Preparing','Paid'=>'Paid','Refunded'=>'Refunded'];
                            $label = $labels[$order['status']] ?? $order['status'];
                            ?>
                            <i class="fa-solid <?= $icon ?>"></i>
                            <?= htmlspecialchars($label) ?>
                        </span>
                        <?php if ($canAdd): ?>
                        <span class="open-badge"><i class="fa-solid fa-circle-plus"></i> Can Add Items</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Right: actions -->
            <div class="actions">
                <?php if ($canAdd): ?>
                <a href="add_to_existing_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-add">
                    <i class="fa-solid fa-plus"></i> Add Items
                </a>
                <?php endif; ?>
                <?php $isPL = ($order['payment_method'] === 'paylater' && $order['status'] === 'Preparing'); ?>
                <?php if ($isPL): ?>
                <a href="edit_order_items.php?order_id=<?= $order['order_id'] ?>" class="btn btn-edit" title="Edit items on this order">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                </a>
                <?php endif; ?>
                <a href="admin_pay_cash.php?order_id=<?= $order['order_id'] ?>"
                   class="btn btn-pay-cash"
                   <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_cash.php?order_id='.$order['order_id'].'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                    <i class="fa-solid fa-money-bill-wave"></i> Cash
                </a>
                <a href="admin_pay_bakong.php?order_id=<?= $order['order_id'] ?>"
                   class="btn btn-pay-bakong"
                   <?= $isPL ? 'data-lp-order="'.$order['order_id'].'" data-lp-dest="admin_pay_bakong.php?order_id='.$order['order_id'].'" onclick="return interceptPayLater(event,this)"' : '' ?>>
                    <i class="fa-solid fa-qrcode"></i> Bakong
                </a>
                <a href="receipt_paylater.php?order_id=<?= $order['order_id'] ?>" target="_blank" class="btn btn-receipt">
                    <i class="fa-solid fa-file-pdf"></i>
                </a>
                <a href="view_order.php?order_id=<?= $order['order_id'] ?>" class="btn btn-view">
                    <i class="fa-solid fa-eye"></i>
                </a>
                <?php if ($canAdd): ?>
                <button class="btn btn-close" onclick="closeOrder(<?= $order['order_id'] ?>, this)" title="Mark as closed (no more additions)">
                    <i class="fa-solid fa-lock"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-cancel-order" onclick="cancelOrderFromFind(<?= $order['order_id'] ?>, this)" title="Cancel this order">
                    <i class="fa-solid fa-ban"></i>
                </button>
            </div>
        </div>

        <div class="card-bottom">
            <div class="card-meta">
                <span><i class="fa-solid fa-clock"></i> <?= $timeAgo ?> &nbsp;·&nbsp; <?= date("d M, g:i A", strtotime($order['order_date'])) ?></span>
                <span><i class="fa-solid fa-credit-card"></i> <?= htmlspecialchars(ucfirst($order['payment_method'])) ?></span>
                <?php if ($order['is_open'] == 1): ?>
                <span style="color:var(--accent);"><i class="fa-solid fa-door-open"></i> Order is open</span>
                <?php else: ?>
                <span style="color:var(--text-muted);"><i class="fa-solid fa-door-closed"></i> Order closed</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="noFilterResults" style="display:none;" class="no-results">
        <i class="fa-solid fa-filter" style="color:var(--text-muted);"></i>
        <h3>No matching orders</h3>
        <p>Try a different search term.</p>
    </div>

    <?php else: ?>
    <div class="no-results">
        <i class="fa-solid fa-check-circle" style="color:var(--success);"></i>
        <h3>No orders found</h3>
        <p><?= !empty($search_value) ? 'Try a different search term.' : 'All orders are settled.' ?></p>
    </div>
    <?php endif; ?>

    <a href="dashboard.php" style="display:inline-flex;align-items:center;gap:6px;color:var(--text-muted);text-decoration:none;margin-top:20px;transition:var(--transition);" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-muted)'">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
    </a>

</div><!-- end page-wrapper -->

<!-- ── Loyalty Card Modal (Pay Later) ── -->
<div id="lpModal">
    <div class="lp-modal-box">
        <h3><i class="fa-solid fa-id-card"></i> Loyalty Card</h3>
        <p class="lp-modal-subtitle">Scan or enter the customer's loyalty card number (optional)</p>
        <div class="lp-input-row">
            <input type="text" id="lpCardInput" placeholder="e.g. LC-001234" autocomplete="off">
            <button class="btn-lp-lookup" onclick="lpLookupCard()">
                <i class="fa-solid fa-magnifying-glass"></i> Check
            </button>
        </div>
        <div id="lpCardStatus" class="lp-status"></div>
        <div class="lp-modal-actions">
            <button class="btn-lp-skip" onclick="skipLpPayment()">
                <i class="fa-solid fa-forward"></i> Skip
            </button>
            <button class="btn-lp-confirm" id="lpConfirmBtn" onclick="confirmLpPayment()">
                <i class="fa-solid fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<script>
// ── Theme ──
(function() { if (localStorage.getItem('theme') === 'light') { document.documentElement.setAttribute('data-theme','light'); } })();
function toggleTheme() {
    const html = document.documentElement;
    const isLight = html.getAttribute('data-theme') === 'light';
    if (isLight) { html.removeAttribute('data-theme'); localStorage.setItem('theme','dark'); document.getElementById('themeIcon').className='fa-solid fa-moon'; document.getElementById('themeText').textContent='Dark'; }
    else { html.setAttribute('data-theme','light'); localStorage.setItem('theme','light'); document.getElementById('themeIcon').className='fa-solid fa-sun'; document.getElementById('themeText').textContent='Light'; }
}
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('theme') === 'light') { document.getElementById('themeIcon').className='fa-solid fa-sun'; document.getElementById('themeText').textContent='Light'; }
});

// ── Loyalty modal (Pay Later orders) ──
let lpDestUrl = '';
let lpOrderId = 0;

function interceptPayLater(event, el) {
    event.preventDefault();
    lpDestUrl = el.dataset.lpDest;
    lpOrderId = parseInt(el.dataset.lpOrder, 10);
    document.getElementById('lpCardInput').value = '';
    document.getElementById('lpCardStatus').innerHTML = '';
    document.getElementById('lpCardStatus').className = 'lp-status';
    const btn = document.getElementById('lpConfirmBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
    document.getElementById('lpModal').style.display = 'flex';
    setTimeout(() => document.getElementById('lpCardInput').focus(), 80);
    return false;
}

function closeLpModal() { document.getElementById('lpModal').style.display = 'none'; }

async function lpLookupCard() {
    const val = document.getElementById('lpCardInput').value.trim();
    const status = document.getElementById('lpCardStatus');
    if (!val) { status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Enter a card number'; return; }
    status.className = 'lp-status lp-loading'; status.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Looking up...';
    try {
        const res = await fetch('loyalty_lookup.php?loyalty_id=' + encodeURIComponent(val));
        const data = await res.json();
        if (data.found) {
            status.className = 'lp-status lp-success';
            status.innerHTML = '<i class="fa-solid fa-check-circle"></i> Found — ' + data.points + ' pts currently';
        } else {
            status.className = 'lp-status lp-error';
            status.innerHTML = '<i class="fa-solid fa-times-circle"></i> ' + (data.message || 'Card not found');
        }
    } catch (e) {
        status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-times-circle"></i> Lookup failed';
    }
}

async function confirmLpPayment() {
    const cardVal = document.getElementById('lpCardInput').value.trim();
    const btn = document.getElementById('lpConfirmBtn');
    const status = document.getElementById('lpCardStatus');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

    if (cardVal) {
        try {
            const body = new URLSearchParams({ order_id: lpOrderId, loyalty_id: cardVal });
            const res = await fetch('set_order_loyalty.php', { method: 'POST', body });
            const data = await res.json();
            if (!data.success) {
                status.className = 'lp-status lp-error';
                status.innerHTML = '<i class="fa-solid fa-times-circle"></i> ' + (data.message || 'Failed to link card');
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
                return;
            }
        } catch (e) {
            status.className = 'lp-status lp-error'; status.innerHTML = '<i class="fa-solid fa-times-circle"></i> Network error. Try again.';
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm';
            return;
        }
    }
    window.location.href = lpDestUrl;
}

function skipLpPayment() { closeLpModal(); window.location.href = lpDestUrl; }

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('lpModal');
    if (modal) modal.addEventListener('click', e => { if (e.target === modal) closeLpModal(); });
    const inp = document.getElementById('lpCardInput');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') lpLookupCard(); });
});

// ── Close order (lock from additions) ──
function cancelOrderFromFind(orderId, btn) {
    const reason = prompt('Reason for cancellation (required):');
    if (reason === null) return;
    if (!reason.trim()) { alert('Please provide a reason.'); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    fetch('cancel_order.php?order_id=' + orderId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'cancel_reason=' + encodeURIComponent(reason.trim())
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            btn.closest('.order-card').remove();
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-ban"></i>';
            alert(data.error || 'Failed to cancel order.');
        }
    });
}

function closeOrder(orderId, btn) {
    if (!confirm('Mark this order as closed? Staff will no longer be able to add items to it.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    fetch('find_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=close_order&order_id=' + orderId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = btn.closest('.order-card');
            // Remove "Can Add Items" badge, Add Items btn, and lock btn
            card.querySelectorAll('.btn-add, .open-badge, .btn-close').forEach(el => el.remove());
            card.classList.remove('can-add','is-paid-open');
            // Update open/closed meta
            const metaOpen = card.querySelector('.card-bottom .card-meta span:last-child');
            if (metaOpen) metaOpen.innerHTML = '<i class="fa-solid fa-door-closed"></i> Order closed';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-lock"></i>';
            alert('Failed to close order. Please try again.');
        }
    });
}
</script>
<script src="animations.js"></script>
</body>
</html>