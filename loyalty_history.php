<?php
require 'admin_only.php';
require_once 'config.php';

// ── TIER HELPER ──
function getTier($points) {
    if ($points >= 1000) return ['name' => 'Platinum', 'color' => '#b5c4d4', 'next' => null,       'next_pts' => 0];
    if ($points >= 500)  return ['name' => 'Gold',     'color' => '#ffd700', 'next' => 'Platinum', 'next_pts' => 1000];
    if ($points >= 100)  return ['name' => 'Silver',   'color' => '#aaaaaa', 'next' => 'Gold',     'next_pts' => 500];
    return                      ['name' => 'Bronze',   'color' => '#cd7f32', 'next' => 'Silver',   'next_pts' => 100];
}

$loyalty_id = trim($_GET['id'] ?? '');

if (empty($loyalty_id)) {
    header("Location: loyalty_dashboard.php");
    exit;
}

// Get card info
$stmt = $conn->prepare("SELECT * FROM loyalty_cards WHERE loyalty_id = ?");
$stmt->bind_param("s", $loyalty_id);
$stmt->execute();
$card = $stmt->get_result()->fetch_assoc();

if (!$card) {
    header("Location: loyalty_dashboard.php");
    exit;
}

// Get full history
$stmt = $conn->prepare("
    SELECT h.*, o.daily_order_no
    FROM loyalty_history h
    LEFT JOIN orders o ON h.order_id = o.order_id
    WHERE h.card_id = ?
    ORDER BY h.created_at DESC
");
$stmt->bind_param("i", $card['card_id']);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── JSON output for dashboard modal ──
if (isset($_GET['json']) && $_GET['json'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'card'    => $card,
        'history' => $history
    ]);
    exit;
}

$tier     = getTier((int)$card['points']);
$next_pts = $tier['next_pts'];
$pct      = $next_pts > 0 ? min(100, round(($card['points'] / $next_pts) * 100)) : 100;

// Transaction type config
function getTypeConfig($type) {
    switch ($type) {
        case 'earned':         return ['label' => 'Earned',          'icon' => 'fa-arrow-up',     'bg' => 'rgba(85,224,135,0.15)',  'color' => '#55e087'];
        case 'redeemed':       return ['label' => 'Redeemed',        'icon' => 'fa-gift',          'bg' => 'rgba(209,144,75,0.15)', 'color' => '#d1904b'];
        case 'adjusted_add':   return ['label' => 'Admin Add',       'icon' => 'fa-circle-plus',   'bg' => 'rgba(52,152,219,0.15)', 'color' => '#3498db'];
        case 'adjusted_deduct':return ['label' => 'Admin Deduct',    'icon' => 'fa-circle-minus',  'bg' => 'rgba(231,76,60,0.15)',  'color' => '#e74c3c'];
        default:               return ['label' => ucfirst($type ?: 'Unknown'), 'icon' => 'fa-circle', 'bg' => 'rgba(136,136,136,0.15)', 'color' => '#888888'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty History — <?= htmlspecialchars($loyalty_id) ?> | Bird's Nest Coffee</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── CSS VARIABLES ── */
        :root {
            --bg: #0b0b0b;
            --bg-card: #121212;
            --bg-card-hover: #1a1a1a;
            --border: #1f1f1f;
            --border-hover: #2a2a2a;
            --accent: #d1904b;
            --accent-light: #e8b87a;
            --accent-dark: #a0702a;
            --text: #f5f5f5;
            --text-muted: #888888;
            --text-light: #ffffff;
            --success: #55e087;
            --danger: #e74c3c;
            --warning: #f1c40f;
            --info: #3498db;
            --purple: #9b59b6;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.4);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.5);
            --shadow-accent: 0 4px 20px rgba(209,144,75,0.15);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --radius: 12px;
        }

        [data-theme="light"] {
            --bg: #f5f0eb;
            --bg-card: #ffffff;
            --bg-card-hover: #fdf8f3;
            --border: #e8ddd2;
            --border-hover: #d4c4b0;
            --text: #1a1008;
            --text-muted: #7a6a58;
            --text-light: #1a1008;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

        /* ── HEADER ── */
        .lh-header {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 28px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            flex-wrap: wrap;
            gap: 10px;
        }

        .lh-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 50px;
            border: 1px solid var(--border);
            background: var(--bg-card);
            color: var(--text-muted);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-nav:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .lh-card-id {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent);
        }

        .lh-tier-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            border: 1px solid currentColor;
        }

        /* ── MAIN WRAPPER ── */
        .lh-wrap {
            max-width: 1000px;
            margin: 0 auto;
            padding: 28px;
        }

        /* ── CARD INFO BAR ── */
        .info-bar {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 24px;
            align-items: start;
        }

        .info-bar-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .info-tier-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .info-tier-label {
            font-size: 12px;
            font-weight: 700;
        }

        .info-bar-right {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-bar-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .info-card-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-light);
        }

        .info-points-big {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-light);
            line-height: 1;
        }

        .info-points-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .info-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .info-meta-item {
            display: flex;
            flex-direction: column;
        }

        .info-meta-label {
            font-size: 11px;
            color: var(--text-muted);
        }

        .info-meta-val {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
        }

        /* ── PROGRESS BAR ── */
        .progress-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 24px;
            margin-bottom: 20px;
        }

        .progress-labels {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .progress-tier-from {
            font-size: 12px;
            font-weight: 600;
        }

        .progress-tier-to {
            font-size: 12px;
            color: var(--text-muted);
        }

        .progress-pct {
            font-size: 11px;
            color: var(--text-muted);
        }

        .progress-track {
            height: 8px;
            background: rgba(255,255,255,0.06);
            border-radius: 8px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 8px;
            transition: width 0.8s cubic-bezier(0.4,0,0.2,1);
        }

        .progress-note {
            margin-top: 6px;
            font-size: 11px;
            color: var(--text-muted);
            text-align: right;
        }

        /* ── HISTORY TABLE PANEL ── */
        .history-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .history-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .history-panel-header h2 {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
        }

        .history-panel-header h2 i {
            color: var(--accent);
        }

        .history-count {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 2px 10px;
            border-radius: 20px;
        }

        /* ── TABLE ── */
        .history-table-wrap {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th {
            padding: 10px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .history-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .history-table tr:hover td {
            background: rgba(255,255,255,0.02);
        }

        /* ── TYPE BADGE ── */
        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── POINTS BADGE ── */
        .pts-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .pts-badge.positive {
            background: rgba(85,224,135,0.15);
            color: var(--success);
        }

        .pts-badge.negative {
            background: rgba(231,76,60,0.15);
            color: var(--danger);
        }

        .order-num {
            font-size: 12px;
            color: var(--text-muted);
        }

        .order-num strong {
            color: var(--accent);
        }

        .desc-cell {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .date-cell {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 56px 24px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
            color: var(--border);
        }

        .empty-state h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .empty-state p {
            font-size: 13px;
        }

        /* ── PRINT BUTTON ── */
        .btn-print-card {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 50px;
            background: var(--accent);
            color: #000;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: var(--transition);
        }

        .btn-print-card:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .lh-wrap { padding: 16px; }
            .info-bar { grid-template-columns: 1fr; }
            .info-bar-left { flex-direction: row; align-items: center; }
            .info-points-big { font-size: 24px; }
            .info-card-title { font-size: 17px; }
            .history-table th,
            .history-table td { padding: 10px 12px; }
        }

        @media (max-width: 640px) {
            .lh-header { padding: 12px 16px; }
            .lh-wrap { padding: 12px 16px; }
            .info-meta { gap: 12px; }
            .info-bar { padding: 16px; }
        }

        @media (max-width: 400px) {
            .lh-header-left { flex-wrap: wrap; }
            .history-table .desc-cell { display: none; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="lh-header">
    <div class="lh-header-left">
        <a href="loyalty_dashboard.php" class="btn-nav">
            <i class="fa-solid fa-arrow-left"></i> Loyalty Dashboard
        </a>
        <span class="lh-card-id"><?= htmlspecialchars($card['loyalty_id']) ?></span>
        <span class="lh-tier-badge" style="color:<?= $tier['color'] ?>;border-color:<?= $tier['color'] ?>;">
            <i class="fa-solid fa-medal"></i> <?= $tier['name'] ?>
        </span>
    </div>
    <div>
        <a href="print_loyalty_card.php?id=<?= urlencode($card['loyalty_id']) ?>" class="btn-print-card" target="_blank">
            <i class="fa-solid fa-print"></i> Print Card
        </a>
    </div>
</header>

<div class="lh-wrap">

    <!-- CARD INFO BAR -->
    <div class="info-bar">
        <div class="info-bar-left">
            <div class="info-tier-icon" style="background:<?= $tier['color'] ?>22;color:<?= $tier['color'] ?>;">
                <i class="fa-solid fa-medal"></i>
            </div>
            <div class="info-tier-label" style="color:<?= $tier['color'] ?>"><?= $tier['name'] ?></div>
        </div>

        <div class="info-bar-right">
            <div class="info-bar-top">
                <div>
                    <div class="info-card-title"><?= htmlspecialchars($card['loyalty_id']) ?></div>
                </div>
                <div style="text-align:right;">
                    <div class="info-points-big" style="color:<?= $tier['color'] ?>"><?= number_format((int)$card['points']) ?></div>
                    <div class="info-points-label">points balance</div>
                </div>
            </div>

            <div class="info-meta">
                <div class="info-meta-item">
                    <span class="info-meta-label">Total Orders</span>
                    <span class="info-meta-val"><?= (int)$card['total_orders'] ?></span>
                </div>
                <div class="info-meta-item">
                    <span class="info-meta-label">Total Drinks</span>
                    <span class="info-meta-val"><?= (int)$card['total_drinks'] ?></span>
                </div>
                <div class="info-meta-item">
                    <span class="info-meta-label">Member Since</span>
                    <span class="info-meta-val"><?= !empty($card['created_at']) ? date('M d, Y', strtotime($card['created_at'])) : '—' ?></span>
                </div>
                <div class="info-meta-item">
                    <span class="info-meta-label">Last Used</span>
                    <span class="info-meta-val"><?= !empty($card['last_used']) ? date('M d, Y', strtotime($card['last_used'])) : '—' ?></span>
                </div>
                <div class="info-meta-item">
                    <span class="info-meta-label">Status</span>
                    <span class="info-meta-val" style="color:<?= $card['is_active'] ? 'var(--success)' : 'var(--danger)' ?>">
                        <?= $card['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- PROGRESS BAR -->
    <div class="progress-section">
        <div class="progress-labels">
            <span class="progress-tier-from" style="color:<?= $tier['color'] ?>">
                <i class="fa-solid fa-medal"></i> <?= $tier['name'] ?>
            </span>
            <?php if ($tier['next']): ?>
            <span class="progress-pct"><?= $pct ?>%</span>
            <span class="progress-tier-to"><?= $tier['next'] ?> (<?= number_format($tier['next_pts']) ?> pts)</span>
            <?php else: ?>
            <span class="progress-tier-to" style="color:<?= $tier['color'] ?>">Maximum tier reached</span>
            <?php endif; ?>
        </div>
        <div class="progress-track">
            <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $tier['color'] ?>;"></div>
        </div>
        <?php if ($tier['next']): ?>
        <div class="progress-note"><?= number_format($tier['next_pts'] - (int)$card['points']) ?> more pts to reach <?= $tier['next'] ?></div>
        <?php endif; ?>
    </div>

    <!-- HISTORY TABLE -->
    <div class="history-panel">
        <div class="history-panel-header">
            <h2>
                <i class="fa-solid fa-clock-rotate-left"></i>
                Transaction History
            </h2>
            <span class="history-count"><?= count($history) ?> transaction<?= count($history) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($history)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <h3>No Transactions Yet</h3>
            <p>This card has no recorded transactions.</p>
        </div>
        <?php else: ?>
        <div class="history-table-wrap">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Points</th>
                        <th>Reward / Description</th>
                        <th>Order #</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h):
                        $tc = getTypeConfig($h['type'] ?? null);
                        $isPositive = (int)$h['points_change'] > 0;
                    ?>
                    <tr>
                        <td class="date-cell">
                            <div><?= date('M d, Y', strtotime($h['created_at'])) ?></div>
                            <div style="font-size:11px;color:var(--text-muted);"><?= date('g:i A', strtotime($h['created_at'])) ?></div>
                        </td>

                        <td>
                            <span class="type-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;">
                                <i class="fa-solid <?= $tc['icon'] ?>"></i>
                                <?= htmlspecialchars($tc['label']) ?>
                            </span>
                        </td>

                        <td>
                            <span class="pts-badge <?= $isPositive ? 'positive' : 'negative' ?>">
                                <?= $isPositive ? '+' : '' ?><?= (int)$h['points_change'] ?>
                            </span>
                        </td>

                        <td class="desc-cell" title="<?= htmlspecialchars($h['description'] ?? $h['reward_name'] ?? '') ?>">
                            <?php
                                $desc = $h['reward_name'] ?? null;
                                if (empty($desc)) $desc = $h['description'] ?? null;
                                echo $desc ? htmlspecialchars($desc) : '<span style="color:var(--border);">—</span>';
                            ?>
                        </td>

                        <td class="order-num">
                            <?= !empty($h['daily_order_no']) ? '<strong>#' . htmlspecialchars($h['daily_order_no']) . '</strong>' : '<span style="color:var(--border)">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
(function () {
    var saved = localStorage.getItem('theme');
    if (saved === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>

</body>
</html>
