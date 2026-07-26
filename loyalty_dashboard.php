<?php
require 'auth.php';
require_once 'config.php';
if (!can('loyalty')) { header("Location: dashboard.php?denied=1"); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── DEACTIVATE CARD AJAX HANDLER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deactivate') {
    header('Content-Type: application/json');
    $cid = (int)($_POST['card_id'] ?? 0);
    if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid card ID']); exit; }
    $stmt = $conn->prepare("UPDATE loyalty_cards SET is_active = 0 WHERE card_id = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

// ── TIER HELPER ──
function getTier($points) {
    if ($points >= 1000) return ['name' => 'Platinum', 'color' => '#b5c4d4', 'next' => null,       'next_pts' => 0];
    if ($points >= 500)  return ['name' => 'Gold',     'color' => '#ffd700', 'next' => 'Platinum', 'next_pts' => 1000];
    if ($points >= 100)  return ['name' => 'Silver',   'color' => '#aaaaaa', 'next' => 'Gold',     'next_pts' => 500];
    return                      ['name' => 'Bronze',   'color' => '#cd7f32', 'next' => 'Silver',   'next_pts' => 100];
}

// ── LOYALTY STATS ──
$stmt = $conn->prepare("SELECT COUNT(*) as total_cards, SUM(points) as total_points FROM loyalty_cards WHERE is_active = 1");
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$total_cards  = (int)($stats['total_cards']  ?? 0);
$total_points = (int)($stats['total_points'] ?? 0);

// ── TOTAL REDEEMED ──
$stmt = $conn->prepare("SELECT COALESCE(ABS(SUM(points_change)), 0) AS total_redeemed FROM loyalty_history WHERE type = 'redeemed' OR type = 'adjusted_deduct'");
$stmt->execute();
$total_redeemed = (int)($stmt->get_result()->fetch_assoc()['total_redeemed'] ?? 0);

// ── TOP CARD ──
$stmt = $conn->prepare("SELECT card_id, loyalty_id, points FROM loyalty_cards WHERE is_active = 1 ORDER BY points DESC LIMIT 1");
$stmt->execute();
$top_card = $stmt->get_result()->fetch_assoc();

// ── ALL CARDS ──
$stmt = $conn->prepare("SELECT * FROM loyalty_cards WHERE is_active = 1 ORDER BY points DESC");
$stmt->execute();
$all_cards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── RECENT POINTS HISTORY ──
$stmt = $conn->prepare("
    SELECT h.*, c.loyalty_id
    FROM loyalty_history h
    JOIN loyalty_cards c ON h.card_id = c.card_id
    ORDER BY h.created_at DESC
    LIMIT 20
");
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── GET REWARDS ──
$stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
$stmt->execute();
$rewards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── TOP CARD TIER ──
$top_tier = $top_card ? getTier((int)$top_card['points']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Dashboard | Bird's Nest Coffee</title>
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

        /* ── RESET ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

        /* ── HEADER ── */
        .ld-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 28px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .ld-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ld-header-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ld-brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ld-brand i {
            color: var(--accent);
            font-size: 20px;
        }

        .ld-brand span {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
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

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 20px;
            border-radius: 50px;
            background: var(--accent);
            color: #000;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* ── STATS ROW ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            padding: 20px 28px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--transition);
        }

        .stat-card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-icon.accent  { background: rgba(209,144,75,0.15);  color: var(--accent); }
        .stat-icon.success { background: rgba(85,224,135,0.15);  color: var(--success); }
        .stat-icon.info    { background: rgba(52,152,219,0.15);  color: var(--info); }
        .stat-icon.gold    { background: rgba(255,215,0,0.15);   color: #ffd700; }

        .stat-info {
            flex: 1;
            min-width: 0;
        }

        .stat-num {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-light);
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .tier-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 20px;
            border: 1px solid currentColor;
        }

        /* ── MAIN LAYOUT ── */
        .ld-main {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 20px;
            padding: 0 28px 28px;
        }

        /* ── PANEL ── */
        .ld-panel {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 10px;
        }

        .panel-header h2 {
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-light);
        }

        .panel-header h2 i {
            color: var(--accent);
        }

        .count-badge {
            background: var(--accent);
            color: #000;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 8px;
            border-radius: 20px;
            margin-left: 6px;
        }

        .panel-toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 50px;
            padding: 6px 14px;
            transition: var(--transition);
        }

        .search-wrap:focus-within {
            border-color: var(--accent);
        }

        .search-wrap i {
            color: var(--text-muted);
            font-size: 13px;
        }

        .search-wrap input {
            border: none;
            background: transparent;
            outline: none;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            color: var(--text);
            width: 160px;
        }

        .search-wrap input::placeholder {
            color: var(--text-muted);
        }

        .tier-select {
            padding: 6px 12px;
            border-radius: 50px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
            outline: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .tier-select:focus {
            border-color: var(--accent);
        }

        /* ── TABLE ── */
        .table-wrap {
            overflow-x: auto;
        }

        #cardsTable {
            width: 100%;
            border-collapse: collapse;
        }

        #cardsTable th {
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        #cardsTable td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tr.card-row:hover td {
            background: rgba(255,255,255,0.02);
        }

        tr.card-row.filtered-out {
            display: none;
        }

        .tier-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
        }

        .card-id-btn {
            background: transparent;
            border: none;
            color: var(--accent);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline;
            padding: 0;
            transition: var(--transition);
        }

        .card-id-btn:hover {
            opacity: 0.8;
        }

        .points-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .points-num {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-light);
        }

        .points-bar {
            height: 4px;
            background: rgba(255,255,255,0.08);
            border-radius: 4px;
            overflow: hidden;
            width: 80px;
        }

        .points-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .points-next {
            font-size: 10px;
            color: var(--text-muted);
        }

        /* ── Card holder ── */
        .holder-cell { display: flex; flex-direction: column; gap: 2px; }
        .holder-name { font-size: 13px; font-weight: 600; }
        .holder-phone { font-size: 11px; color: var(--text-muted); }
        .holder-phone i { font-size: 9px; margin-right: 3px; }
        .holder-anon { font-size: 12px; color: var(--text-muted); font-style: italic; opacity: .7; }
        .lbl-opt { font-weight: 400; font-size: 11px; color: var(--text-muted); }

        /* ── Merge ── */
        .btn-merge { color: #3498db; }
        .merge-intro { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-bottom: 16px; }
        .merge-intro strong { color: var(--accent); }
        .merge-preview {
            font-size: 12.5px; font-weight: 600; color: var(--accent);
            min-height: 18px; margin: 4px 0 14px;
        }

        .row-actions {
            display: flex;
            gap: 4px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-icon:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-danger-icon:hover {
            border-color: var(--danger) !important;
            color: var(--danger) !important;
        }

        .btn-adjust:hover {
            border-color: var(--info);
            color: var(--info);
        }

        /* ── NO RESULTS ── */
        .no-results {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
            color: var(--border);
        }

        .empty-state p {
            font-size: 13px;
        }

        /* ── SIDEBAR ── */
        .ld-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* ── HISTORY FEED ── */
        .history-feed {
            padding: 8px 0;
            max-height: 340px;
            overflow-y: auto;
        }

        .history-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            transition: var(--transition);
        }

        .history-item:hover {
            background: rgba(255,255,255,0.02);
        }

        .history-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        .history-icon.positive {
            background: rgba(85,224,135,0.15);
            color: var(--success);
        }

        .history-icon.negative {
            background: rgba(231,76,60,0.15);
            color: var(--danger);
        }

        .history-info {
            flex: 1;
            min-width: 0;
        }

        .history-card {
            font-size: 13px;
            font-weight: 600;
            color: var(--accent);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .history-desc {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .history-pts {
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .history-pts.positive { color: var(--success); }
        .history-pts.negative { color: var(--danger); }

        .live-dot {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: var(--text-muted);
        }

        .dot {
            width: 7px;
            height: 7px;
            background: var(--success);
            border-radius: 50%;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }

        /* ── REWARDS LIST ── */
        .rewards-list {
            padding: 4px 0;
            max-height: 340px;
            overflow-y: auto;
        }

        .reward-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            transition: var(--transition);
        }

        .reward-row:hover {
            background: rgba(255,255,255,0.02);
        }

        .reward-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(209,144,75,0.12);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .reward-info {
            flex: 1;
            min-width: 0;
        }

        .reward-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-light);
        }

        .reward-pts {
            font-size: 11px;
            color: var(--text-muted);
        }

        .reward-badge {
            margin-left: auto;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            background: rgba(85,224,135,0.12);
            color: var(--success);
            border: 1px solid rgba(85,224,135,0.2);
            flex-shrink: 0;
        }

        .btn-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-link:hover {
            text-decoration: underline;
        }

        /* ── MODALS ── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-card {
            background: var(--bg-card);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            position: relative;
            width: 100%;
            animation: modalIn 0.3s ease both;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.94) translateY(12px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        .detail-modal  { max-width: 600px; }
        .adjust-modal  { max-width: 440px; }
        .create-modal  { max-width: 420px; }

        .modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.06);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            z-index: 1;
        }

        .modal-close:hover {
            color: var(--text);
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 24px 24px 0;
        }

        .modal-header i {
            font-size: 22px;
            color: var(--accent);
        }

        .modal-header h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-light);
        }

        .modal-body {
            padding: 20px 24px;
        }

        /* ── FORM ELEMENTS IN MODALS ── */
        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            outline: none;
            transition: var(--transition);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--accent);
        }

        .btn-full {
            width: 100%;
            justify-content: center;
            padding: 12px;
        }

        .id-row {
            display: flex;
            gap: 8px;
        }

        /* ── ADJUST MODAL ── */
        .adjust-card-label {
            padding: 0 24px 4px;
            font-size: 13px;
            color: var(--accent);
            font-weight: 600;
        }

        .adjust-current {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 24px 12px;
            font-size: 13px;
        }

        .adjust-current span {
            color: var(--text-muted);
        }

        .adjust-current strong {
            font-size: 20px;
            color: var(--text-light);
        }

        .adjust-btns {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            padding: 0 24px 12px;
        }

        .adj-btn {
            flex: 1;
            padding: 8px 0;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            min-width: 44px;
        }

        .adj-btn:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        .adj-btn.pos:hover {
            border-color: var(--success);
            color: var(--success);
        }

        .adjust-custom {
            padding: 0 24px 10px;
        }

        .adjust-preview {
            padding: 4px 24px 14px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .adjust-preview strong {
            font-size: 16px;
            color: var(--accent);
        }

        /* ── DETAIL MODAL CONTENT ── */
        .detail-hero {
            padding: 24px 24px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid var(--border);
        }

        .detail-tier-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .detail-title {
            flex: 1;
        }

        .detail-title h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-light);
        }

        .detail-title .detail-tier-name {
            font-size: 12px;
            font-weight: 600;
            margin-top: 2px;
        }

        .detail-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            border-bottom: 1px solid var(--border);
        }

        .detail-stat {
            padding: 14px 20px;
            border-right: 1px solid var(--border);
            text-align: center;
        }

        .detail-stat:last-child {
            border-right: none;
        }

        .detail-stat .ds-val {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-light);
        }

        .detail-stat .ds-lbl {
            font-size: 11px;
            color: var(--text-muted);
        }

        .detail-progress-wrap {
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
        }

        .detail-progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .detail-progress-bar {
            height: 6px;
            background: rgba(255,255,255,0.08);
            border-radius: 6px;
            overflow: hidden;
        }

        .detail-progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.5s ease;
        }

        .detail-history-wrap {
            padding: 14px 0 0;
        }

        .detail-history-title {
            padding: 0 20px 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-history-scroll {
            max-height: 200px;
            overflow-y: auto;
        }

        .detail-history-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 20px;
            transition: var(--transition);
        }

        .detail-history-item:hover {
            background: rgba(255,255,255,0.02);
        }

        .detail-history-item .dhi-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            flex-shrink: 0;
        }

        .dhi-icon.pos { background: rgba(85,224,135,0.15); color: var(--success); }
        .dhi-icon.neg { background: rgba(231,76,60,0.15);  color: var(--danger); }

        .dhi-type {
            flex: 1;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: capitalize;
        }

        .dhi-pts {
            font-size: 13px;
            font-weight: 700;
        }

        .dhi-pts.pos { color: var(--success); }
        .dhi-pts.neg { color: var(--danger); }

        .dhi-date {
            font-size: 10px;
            color: var(--text-muted);
        }

        .detail-actions {
            padding: 14px 24px 24px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .detail-actions a,
        .detail-actions button {
            flex: 1;
            min-width: 120px;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 20px;
            border-radius: 50px;
            background: transparent;
            color: var(--text-muted);
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-secondary:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        /* ── TOAST ── */
        #toastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            min-width: 260px;
            max-width: 380px;
            pointer-events: all;
            font-size: 13px;
            font-weight: 500;
            animation: toastIn 0.35s ease both;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .toast.success {
            background: #141a14;
            border: 1px solid rgba(85,224,135,0.35);
            color: var(--text);
        }

        .toast.error {
            background: #1a1212;
            border: 1px solid rgba(231,76,60,0.35);
            color: var(--text);
        }

        .toast.info {
            background: #121520;
            border: 1px solid rgba(52,152,219,0.35);
            color: var(--text);
        }

        .toast-icon { font-size: 18px; flex-shrink: 0; }
        .toast-msg  { flex: 1; }

        .toast-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 0;
            flex-shrink: 0;
            pointer-events: all;
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(40px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .ld-main {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .stats-row {
                grid-template-columns: 1fr;
                padding: 12px 16px;
            }

            .ld-header {
                flex-wrap: wrap;
                padding: 12px 16px;
                gap: 8px;
            }

            .ld-main {
                padding: 0 16px 16px;
            }

            .search-wrap input {
                width: 110px;
            }
        }

        @media (max-width: 400px) {
            .ld-header-right {
                flex-wrap: wrap;
                gap: 6px;
            }

            .btn-nav span,
            .btn-primary span {
                display: none;
            }

            .detail-stats {
                grid-template-columns: 1fr 1fr;
            }
        }

    /* ── PAGINATION ── */
    .pg-wrap { padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    .pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
    .pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:var(--transition); }
    .pg-btn:hover { border-color:var(--accent); color:var(--accent); }
    .pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
    .pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
    .pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
    .pg-info { font-size:12px; color:var(--text-muted); }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="ld-header">
    <div class="ld-header-left">
        <a href="dashboard.php" class="btn-nav"><i class="fa-solid fa-arrow-left"></i> <span>Dashboard</span></a>
        <div class="ld-brand">
            <i class="fa-solid fa-star"></i>
            <span>Loyalty</span>
        </div>
    </div>
    <div class="ld-header-right">
        <button class="btn-nav" onclick="toggleTheme()" title="Toggle theme">
            <i id="themeIcon" class="fa-solid fa-moon"></i>
        </button>
        <a href="admin_rewards.php" class="btn-nav"><i class="fa-solid fa-gift"></i> <span>Rewards</span></a>
        <button class="btn-primary" onclick="openCreateModal()">
            <i class="fa-solid fa-plus"></i> <span>Create Card</span>
        </button>
    </div>
</header>

<!-- STATS ROW -->
<div class="stats-row">
    <!-- Total Cards -->
    <div class="stat-card">
        <div class="stat-icon accent">
            <i class="fa-solid fa-credit-card"></i>
        </div>
        <div class="stat-info">
            <div class="stat-num" id="statTotalCards"><?= number_format($total_cards) ?></div>
            <div class="stat-label">Total Active Cards</div>
        </div>
    </div>

    <!-- Total Points -->
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fa-solid fa-star"></i>
        </div>
        <div class="stat-info">
            <div class="stat-num" id="statTotalPoints"><?= number_format($total_points) ?></div>
            <div class="stat-label">Total Points Issued</div>
        </div>
    </div>

    <!-- Redeemed -->
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fa-solid fa-arrow-trend-down"></i>
        </div>
        <div class="stat-info">
            <div class="stat-num" id="statTotalRedeemed"><?= number_format($total_redeemed) ?></div>
            <div class="stat-label">Total Redeemed</div>
        </div>
    </div>

    <!-- Top Member -->
    <div class="stat-card">
        <div class="stat-icon gold">
            <i class="fa-solid fa-trophy"></i>
        </div>
        <div class="stat-info">
            <div class="stat-num" id="statTopCard" style="font-size:16px;line-height:1.3;">
                <?= $top_card ? htmlspecialchars($top_card['loyalty_id']) : 'N/A' ?>
            </div>
            <div class="stat-sub" id="statTopCardSub">
                <?php if ($top_card && $top_tier): ?>
                <span class="tier-pill" style="color:<?= $top_tier['color'] ?>;border-color:<?= $top_tier['color'] ?>;">
                    <i class="fa-solid fa-medal"></i> <?= $top_tier['name'] ?>
                </span>
                <?= number_format((int)$top_card['points']) ?> pts
                <?php else: ?>
                No members yet
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MAIN LAYOUT -->
<div class="ld-main">

    <!-- LEFT: Cards Panel -->
    <div class="ld-panel">
        <div class="panel-header">
            <h2>
                <i class="fa-solid fa-credit-card"></i>
                Loyalty Cards
                <span class="count-badge" id="visibleCount"><?= $total_cards ?></span>
            </h2>
            <div class="panel-toolbar">
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="cardSearch" placeholder="Search card ID, name or phone...">
                </div>
                <select id="tierFilter" class="tier-select">
                    <option value="">All Tiers</option>
                    <option value="Bronze">Bronze</option>
                    <option value="Silver">Silver</option>
                    <option value="Gold">Gold</option>
                    <option value="Platinum">Platinum</option>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <?php if (!empty($all_cards)): ?>
            <table id="cardsTable">
                <thead>
                    <tr>
                        <th>Tier</th>
                        <th>Card ID</th>
                        <th>Holder</th>
                        <th>Points</th>
                        <th>Orders</th>
                        <th>Drinks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="allCardsTbody">
                    <?php foreach ($all_cards as $c):
                        $tier    = getTier((int)$c['points']);
                        $next_pts = $tier['next_pts'];
                        $pct     = $next_pts > 0 ? min(100, round(($c['points'] / $next_pts) * 100)) : 100;
                    ?>
                    <tr class="card-row"
                        data-tier="<?= $tier['name'] ?>"
                        data-card-id="<?= (int)$c['card_id'] ?>"
                        data-loyalty-id="<?= htmlspecialchars($c['loyalty_id'], ENT_QUOTES) ?>"
                        data-points="<?= (int)$c['points'] ?>"
                        data-orders="<?= (int)$c['total_orders'] ?>"
                        data-drinks="<?= (int)$c['total_drinks'] ?>"
                        data-created="<?= htmlspecialchars($c['created_at'] ?? '', ENT_QUOTES) ?>"
                        data-last-used="<?= htmlspecialchars($c['last_used'] ?? '', ENT_QUOTES) ?>"
                        data-holder="<?= htmlspecialchars($c['holder_name'] ?? '', ENT_QUOTES) ?>"
                        data-phone="<?= htmlspecialchars($c['holder_phone'] ?? '', ENT_QUOTES) ?>">

                        <td>
                            <div class="tier-badge" style="color:<?= $tier['color'] ?>">
                                <i class="fa-solid fa-medal"></i>
                                <span><?= $tier['name'] ?></span>
                            </div>
                        </td>

                        <td>
                            <button class="card-id-btn" onclick="openCardDetail(<?= (int)$c['card_id'] ?>)">
                                <?= htmlspecialchars($c['loyalty_id']) ?>
                            </button>
                        </td>

                        <td>
                            <?php if (!empty($c['holder_name']) || !empty($c['holder_phone'])): ?>
                            <div class="holder-cell">
                                <?php if (!empty($c['holder_name'])): ?>
                                <span class="holder-name"><?= htmlspecialchars($c['holder_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($c['holder_phone'])): ?>
                                <span class="holder-phone"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($c['holder_phone']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <span class="holder-anon">Unnamed</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="points-cell">
                                <span class="points-num"><?= number_format((int)$c['points']) ?></span>
                                <div class="points-bar">
                                    <div class="points-fill" style="width:<?= $pct ?>%;background:<?= $tier['color'] ?>"></div>
                                </div>
                                <?php if ($tier['next']): ?>
                                <span class="points-next"><?= number_format($tier['next_pts'] - (int)$c['points']) ?> to <?= $tier['next'] ?></span>
                                <?php else: ?>
                                <span class="points-next" style="color:<?= $tier['color'] ?>">Max tier</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td><?= (int)$c['total_orders'] ?></td>
                        <td><?= (int)$c['total_drinks'] ?></td>

                        <td>
                            <div class="row-actions">
                                <?php $__lid_js = htmlspecialchars(json_encode($c['loyalty_id'], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP), ENT_QUOTES); ?>
                                <button class="btn-icon btn-adjust"
                                    onclick="openAdjust(<?= (int)$c['card_id'] ?>, <?= $__lid_js ?>, <?= (int)$c['points'] ?>)"
                                    title="Adjust points">
                                    <i class="fa-solid fa-sliders"></i>
                                </button>
                                <button class="btn-icon btn-redeem"
                                    onclick="openRedeem(<?= (int)$c['card_id'] ?>, <?= $__lid_js ?>, <?= (int)$c['points'] ?>)"
                                    title="Redeem reward (spend points)">
                                    <i class="fa-solid fa-gift"></i>
                                </button>
                                <?php if (in_array($_SESSION['role'] ?? '', ['admin','manager'], true)): ?>
                                <button class="btn-icon btn-merge"
                                    onclick="openMerge(<?= (int)$c['card_id'] ?>, <?= $__lid_js ?>, <?= (int)$c['points'] ?>)"
                                    title="Merge this card into another">
                                    <i class="fa-solid fa-code-merge"></i>
                                </button>
                                <?php endif; ?>
                                <a href="loyalty_history.php?id=<?= urlencode($c['loyalty_id']) ?>"
                                   class="btn-icon" title="View history">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </a>
                                <a href="print_loyalty_card.php?id=<?= urlencode($c['loyalty_id']) ?>"
                                   class="btn-icon" title="Print card" target="_blank">
                                    <i class="fa-solid fa-print"></i>
                                </a>
                                <button class="btn-icon btn-danger-icon"
                                    onclick="deactivateCard(<?= (int)$c['card_id'] ?>, '<?= htmlspecialchars($c['loyalty_id'], ENT_QUOTES) ?>')"
                                    title="Deactivate card">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-credit-card"></i>
                <p>No loyalty cards yet. Create the first one!</p>
            </div>
            <?php endif; ?>
        </div>
        <div class="no-results" id="noResults" style="display:none;">No cards match your search.</div>
        <div id="pgWrap" class="pg-wrap" style="display:none">
            <span id="pgInfo" class="pg-info"></span>
            <nav id="pgNav" class="pg-nav"></nav>
        </div>
    </div>

    <!-- RIGHT: Sidebar -->
    <div class="ld-sidebar">

        <!-- Recent Activity -->
        <div class="ld-panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-bolt"></i> Recent Activity</h2>
                <span class="live-dot"><span class="dot"></span>Live</span>
            </div>
            <div class="history-feed" id="historyFeed">
                <?php if (!empty($history)): ?>
                <?php foreach ($history as $h): ?>
                <div class="history-item">
                    <div class="history-icon <?= (int)$h['points_change'] > 0 ? 'positive' : 'negative' ?>">
                        <i class="fa-solid <?= (int)$h['points_change'] > 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                    </div>
                    <div class="history-info">
                        <div class="history-card"><?= htmlspecialchars($h['loyalty_id']) ?></div>
                        <div class="history-desc"><?= htmlspecialchars($h['type'] ?? 'earned') ?></div>
                    </div>
                    <div class="history-pts <?= (int)$h['points_change'] > 0 ? 'positive' : 'negative' ?>">
                        <?= (int)$h['points_change'] > 0 ? '+' : '' ?><?= (int)$h['points_change'] ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state"><p>No activity yet.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Active Rewards -->
        <div class="ld-panel">
            <div class="panel-header">
                <h2><i class="fa-solid fa-gift"></i> Rewards</h2>
                <a href="admin_rewards.php" class="btn-link">Manage</a>
            </div>
            <div class="rewards-list">
                <?php if (!empty($rewards)): ?>
                <?php foreach ($rewards as $r): ?>
                <div class="reward-row">
                    <div class="reward-icon"><i class="fa-solid fa-star"></i></div>
                    <div class="reward-info">
                        <div class="reward-name"><?= htmlspecialchars($r['reward_name']) ?></div>
                        <div class="reward-pts"><?= (int)$r['points_required'] ?> pts required</div>
                    </div>
                    <span class="reward-badge">Active</span>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state"><p>No rewards configured.</p></div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- CARD DETAIL MODAL -->
<div id="detailModal" class="modal-overlay" onclick="if(event.target===this)closeDetailModal()">
    <div class="modal-card detail-modal">
        <button class="modal-close" onclick="closeDetailModal()"><i class="fa-solid fa-xmark"></i></button>
        <div id="detailContent"></div>
    </div>
</div>

<!-- ADJUST POINTS MODAL -->
<div id="adjustModal" class="modal-overlay" onclick="if(event.target===this)closeAdjustModal()">
    <div class="modal-card adjust-modal">
        <button class="modal-close" onclick="closeAdjustModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <i class="fa-solid fa-sliders"></i>
            <h3>Adjust Points</h3>
        </div>
        <div id="adjustCardLabel" class="adjust-card-label"></div>
        <div class="adjust-current">
            <span>Current Balance:</span>
            <strong id="adjustCurrentPts"></strong>
        </div>
        <div class="adjust-btns">
            <button class="adj-btn" onclick="setAdj(-10)">−10</button>
            <button class="adj-btn" onclick="setAdj(-5)">−5</button>
            <button class="adj-btn pos" onclick="setAdj(5)">+5</button>
            <button class="adj-btn pos" onclick="setAdj(10)">+10</button>
            <button class="adj-btn pos" onclick="setAdj(50)">+50</button>
        </div>
        <div class="adjust-custom">
            <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">Custom amount (negative to deduct)</label>
            <input type="number" id="adjustAmount" placeholder="e.g. 25 or -10"
                style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;outline:none;transition:var(--transition);">
        </div>
        <div class="adjust-custom">
            <label style="display:block;font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:5px;">Reason</label>
            <input type="text" id="adjustReason" value="Manual adjustment by admin" placeholder="Manual adjustment by admin"
                style="width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-family:'Poppins',sans-serif;font-size:13px;outline:none;transition:var(--transition);">
        </div>
        <div class="adjust-preview">New balance: <strong id="adjustPreview">—</strong></div>
        <div style="padding:0 24px 24px;">
            <button class="btn-primary btn-full" onclick="submitAdjust()">
                <i class="fa-solid fa-check"></i> Apply Adjustment
            </button>
        </div>
    </div>
</div>

<!-- REDEEM REWARD MODAL (standalone — spend points, no order) -->
<div id="redeemModal" class="modal-overlay" onclick="if(event.target===this)closeRedeemModal()">
    <div class="modal-card adjust-modal">
        <button class="modal-close" onclick="closeRedeemModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <i class="fa-solid fa-gift"></i>
            <h3>Redeem Reward</h3>
        </div>
        <div id="redeemCardLabel" class="adjust-card-label"></div>
        <div class="adjust-current">
            <span>Available Points:</span>
            <strong id="redeemCurrentPts"></strong>
        </div>
        <div style="padding:4px 24px 8px;font-size:12px;font-weight:600;color:var(--text-muted);">Choose a reward to give the customer (points deduct now — no order needed)</div>
        <div id="redeemList" style="padding:0 24px 20px;display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto;"></div>
    </div>
</div>

<script>
// Active rewards available to redeem (id, name, cost).
var REWARDS = <?= json_encode(array_map(fn($r) => ['id'=>(int)$r['reward_id'],'name'=>$r['reward_name'],'cost'=>(int)$r['points_required']], $rewards ?? []), JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- STYLED CONFIRM MODAL (replaces native confirm()) -->
<div id="confirmModal" class="modal-overlay" onclick="if(event.target===this)uiConfirmResolve(false)">
    <div class="modal-card" style="max-width:420px;">
        <div class="modal-header">
            <i id="confirmIcon" class="fa-solid fa-circle-question"></i>
            <h3 id="confirmTitle">Are you sure?</h3>
        </div>
        <div id="confirmMessage" style="padding:4px 24px 8px;font-size:13.5px;color:var(--text-muted);line-height:1.55;"></div>
        <div style="display:flex;gap:8px;padding:16px 24px 24px;">
            <button class="btn-secondary" style="flex:1;" onclick="uiConfirmResolve(false)">Cancel</button>
            <button id="confirmOkBtn" class="btn-primary" style="flex:1;" onclick="uiConfirmResolve(true)">Confirm</button>
        </div>
    </div>
</div>

<!-- CREATE CARD MODAL -->
<div id="createModal" class="modal-overlay" onclick="if(event.target===this)closeCreateModal()">
    <div class="modal-card create-modal">
        <button class="modal-close" onclick="closeCreateModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <i class="fa-solid fa-star"></i>
            <h3>Create Loyalty Card</h3>
        </div>
        <div class="modal-body">
            <form id="createForm">
                <div class="form-group">
                    <label>Loyalty ID</label>
                    <div class="id-row">
                        <input type="text" id="newId" placeholder="CARD-XXXXX" style="flex:1;">
                        <button type="button" class="btn-icon" onclick="genId()" title="Generate random ID" style="width:38px;height:38px;flex-shrink:0;">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Customer Name <span class="lbl-opt">(optional)</span></label>
                    <input type="text" id="newHolder" placeholder="e.g. Sok Dara" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Phone <span class="lbl-opt">(optional — lets staff find this card again)</span></label>
                    <input type="text" id="newPhone" placeholder="e.g. 012 345 678" maxlength="30">
                </div>
                <div class="form-group">
                    <label>Initial Points</label>
                    <input type="number" id="newPts" value="0" min="0">
                </div>
                <button type="submit" class="btn-primary btn-full">
                    <i class="fa-solid fa-plus"></i> Create Card
                </button>
            </form>
        </div>
    </div>
</div>

<!-- MERGE CARDS MODAL -->
<div id="mergeModal" class="modal-overlay" onclick="if(event.target===this)closeMerge()">
    <div class="modal-card">
        <button class="modal-close" onclick="closeMerge()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-header">
            <i class="fa-solid fa-code-merge"></i>
            <h3>Merge Loyalty Cards</h3>
        </div>
        <div class="modal-body">
            <p class="merge-intro">
                Points from <strong id="mergeSourceLabel">—</strong> move to the card you keep.
                The merged card is deactivated, and its history follows the points.
            </p>
            <div class="form-group">
                <label>Keep this card</label>
                <select id="mergeTarget"></select>
            </div>
            <div class="merge-preview" id="mergePreview"></div>
            <button type="button" class="btn-primary btn-full" id="mergeConfirmBtn" onclick="confirmMerge()">
                <i class="fa-solid fa-code-merge"></i> Merge Cards
            </button>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div id="toastContainer"></div>

<script>
// ═══════════════════════════════════════════════════
// THEME
// ═══════════════════════════════════════════════════
function applyTheme(theme) {
    const html = document.documentElement;
    const icon = document.getElementById('themeIcon');
    if (theme === 'light') {
        html.setAttribute('data-theme', 'light');
        if (icon) { icon.className = 'fa-solid fa-sun'; }
    } else {
        html.removeAttribute('data-theme');
        if (icon) { icon.className = 'fa-solid fa-moon'; }
    }
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'light' ? 'dark' : 'light';
    localStorage.setItem('theme', next);
    applyTheme(next);
}

(function () {
    const saved = localStorage.getItem('theme');
    if (saved === 'light') applyTheme('light');
})();

window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        applyTheme(e.newValue === 'light' ? 'light' : 'dark');
    }
});

const LC_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

// ═══════════════════════════════════════════════════
// LIVE TABLE SEARCH & TIER FILTER  (JS pagination)
// ═══════════════════════════════════════════════════
const PER_PAGE_LC = 10;
let currentPageLC  = 1;
let lastFilteredLC = [];

function applyFilters() {
    const query   = (document.getElementById('cardSearch').value || '').toLowerCase().trim();
    const tierVal = (document.getElementById('tierFilter').value || '').toLowerCase();
    const allRows = [...document.querySelectorAll('#allCardsTbody .card-row')];

    lastFilteredLC = allRows.filter(function (row) {
        // Search the holder and phone too — looking a card up by the number alone only
        // works if the customer still has the card, which is the case that goes wrong.
        const haystack = [
            row.dataset.loyaltyId || '',
            row.dataset.holder    || '',
            (row.dataset.phone    || '').replace(/[\s-]/g, '')
        ].join(' ').toLowerCase();
        const needle      = query.replace(/[\s-]/g, '') === query ? query : query.replace(/[\s-]/g, '');
        const matchSearch = !query || haystack.includes(query) || (needle && haystack.includes(needle));
        const matchTier   = !tierVal || (row.dataset.tier || '').toLowerCase() === tierVal;
        return matchSearch && matchTier;
    });
    currentPageLC = 1;
    renderPageLC();
}

function renderPageLC() {
    const total      = lastFilteredLC.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE_LC));
    currentPageLC    = Math.min(currentPageLC, totalPages);
    const start      = (currentPageLC - 1) * PER_PAGE_LC;
    const pageRows   = lastFilteredLC.slice(start, start + PER_PAGE_LC);

    document.querySelectorAll('#allCardsTbody .card-row').forEach(function (r) { r.classList.add('filtered-out'); });
    pageRows.forEach(function (r) { r.classList.remove('filtered-out'); });

    const badge = document.getElementById('visibleCount');
    if (badge) badge.textContent = total;

    const noResults = document.getElementById('noResults');
    if (noResults) noResults.style.display = (total === 0) ? 'block' : 'none';

    renderPaginationLC(total, totalPages);
}

function renderPaginationLC(total, totalPages) {
    const wrap = document.getElementById('pgWrap');
    if (totalPages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    document.getElementById('pgInfo').textContent =
        `Page ${currentPageLC} of ${totalPages} · ${total} cards`;
    const nav = document.getElementById('pgNav');
    let html = currentPageLC > 1
        ? `<a href="#" class="pg-btn" onclick="goPageLC(1);return false;">«</a><a href="#" class="pg-btn" onclick="goPageLC(${currentPageLC - 1});return false;">‹</a>`
        : `<span class="pg-disabled">«</span><span class="pg-disabled">‹</span>`;
    const ws = Math.max(1, currentPageLC - 2);
    const we = Math.min(totalPages, currentPageLC + 2);
    if (ws > 1) html += `<span class="pg-ellipsis">…</span>`;
    for (let i = ws; i <= we; i++) {
        html += i === currentPageLC
            ? `<span class="pg-active">${i}</span>`
            : `<a href="#" class="pg-btn" onclick="goPageLC(${i});return false;">${i}</a>`;
    }
    if (we < totalPages) html += `<span class="pg-ellipsis">…</span>`;
    html += currentPageLC < totalPages
        ? `<a href="#" class="pg-btn" onclick="goPageLC(${currentPageLC + 1});return false;">›</a><a href="#" class="pg-btn" onclick="goPageLC(${totalPages});return false;">»</a>`
        : `<span class="pg-disabled">›</span><span class="pg-disabled">»</span>`;
    nav.innerHTML = html;
}

function goPageLC(p) {
    currentPageLC = p;
    renderPageLC();
}

document.getElementById('cardSearch').addEventListener('input', applyFilters);
document.getElementById('tierFilter').addEventListener('change', applyFilters);

// ═══════════════════════════════════════════════════
// TIER HELPERS
// ═══════════════════════════════════════════════════
function getTierJS(points) {
    if (points >= 1000) return { name: 'Platinum', color: '#b5c4d4', next: null,       nextPts: 0 };
    if (points >= 500)  return { name: 'Gold',     color: '#ffd700', next: 'Platinum', nextPts: 1000 };
    if (points >= 100)  return { name: 'Silver',   color: '#aaaaaa', next: 'Gold',     nextPts: 500 };
    return                     { name: 'Bronze',   color: '#cd7f32', next: 'Silver',   nextPts: 100 };
}

function formatDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

function formatDateShort(str) {
    if (!str) return '—';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit' }) +
        ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
}

// ═══════════════════════════════════════════════════
// CARD DETAIL MODAL
// ═══════════════════════════════════════════════════
async function openCardDetail(cardId) {
    const modal = document.getElementById('detailModal');
    const content = document.getElementById('detailContent');
    content.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin" style="font-size:24px;"></i></div>';
    modal.classList.add('open');

    try {
        const res = await fetch('loyalty_dashboard_data.php');
        const data = await res.json();

        const card = (data.all_cards || []).find(function (c) { return parseInt(c.card_id) === cardId; });
        if (!card) {
            content.innerHTML = '<div style="padding:40px;text-align:center;color:var(--danger);">Card not found.</div>';
            return;
        }

        const points = parseInt(card.points) || 0;
        const tier = getTierJS(points);
        const pct = tier.nextPts > 0 ? Math.min(100, Math.round((points / tier.nextPts) * 100)) : 100;

        const cardHistory = (data.history || []).filter(function (h) {
            return h.loyalty_id === card.loyalty_id;
        });

        let historyHTML = '';
        if (cardHistory.length > 0) {
            historyHTML = cardHistory.map(function (h) {
                const pos = parseInt(h.points_change) > 0;
                return '<div class="detail-history-item">' +
                    '<div class="dhi-icon ' + (pos ? 'pos' : 'neg') + '">' +
                    '<i class="fa-solid ' + (pos ? 'fa-arrow-up' : 'fa-arrow-down') + '"></i>' +
                    '</div>' +
                    '<div class="dhi-type">' + escapeHtml(h.type || 'earned') + '</div>' +
                    '<div class="dhi-date">' + formatDateShort(h.created_at) + '</div>' +
                    '<div class="dhi-pts ' + (pos ? 'pos' : 'neg') + '">' +
                    (pos ? '+' : '') + parseInt(h.points_change) +
                    '</div>' +
                    '</div>';
            }).join('');
        } else {
            historyHTML = '<div class="empty-state" style="padding:16px;"><p>No recent activity.</p></div>';
        }

        const progressLabel = tier.next
            ? (tier.nextPts - points) + ' pts to ' + tier.next
            : 'Maximum tier reached';

        content.innerHTML =
            '<div class="detail-hero">' +
            '<div class="detail-tier-icon" style="background:' + tier.color + '22;color:' + tier.color + ';">' +
            '<i class="fa-solid fa-medal"></i>' +
            '</div>' +
            '<div class="detail-title">' +
            '<h3>' + escapeHtml(card.loyalty_id) + '</h3>' +
            '<div class="detail-tier-name" style="color:' + tier.color + '">' +
            '<i class="fa-solid fa-medal"></i> ' + tier.name + ' Member' +
            '</div>' +
            '</div>' +
            '</div>' +

            '<div class="detail-stats">' +
            '<div class="detail-stat"><div class="ds-val">' + parseInt(card.points).toLocaleString() + '</div><div class="ds-lbl">Points</div></div>' +
            '<div class="detail-stat"><div class="ds-val">' + parseInt(card.total_orders || 0) + '</div><div class="ds-lbl">Orders</div></div>' +
            '<div class="detail-stat"><div class="ds-val">' + parseInt(card.total_drinks || 0) + '</div><div class="ds-lbl">Drinks</div></div>' +
            '</div>' +

            '<div class="detail-progress-wrap">' +
            '<div class="detail-progress-label">' +
            '<span>' + tier.name + '</span>' +
            '<span>' + progressLabel + '</span>' +
            '</div>' +
            '<div class="detail-progress-bar">' +
            '<div class="detail-progress-fill" style="width:' + pct + '%;background:' + tier.color + ';"></div>' +
            '</div>' +
            '</div>' +

            '<div class="detail-history-wrap">' +
            '<div class="detail-history-title">Recent Transactions</div>' +
            '<div class="detail-history-scroll">' + historyHTML + '</div>' +
            '</div>' +

            '<div class="detail-actions">' +
            '<button class="btn-primary" onclick="closeDetailModal();openAdjust(' + card.card_id + ',\'' + escapeHtml(card.loyalty_id) + '\',' + points + ')">' +
            '<i class="fa-solid fa-sliders"></i> Adjust Points' +
            '</button>' +
            '<a href="loyalty_history.php?id=' + encodeURIComponent(card.loyalty_id) + '" class="btn-secondary">' +
            '<i class="fa-solid fa-clock-rotate-left"></i> Full History' +
            '</a>' +
            '<a href="print_loyalty_card.php?id=' + encodeURIComponent(card.loyalty_id) + '" class="btn-secondary" target="_blank">' +
            '<i class="fa-solid fa-print"></i> Print' +
            '</a>' +
            '</div>';

    } catch (err) {
        content.innerHTML = '<div style="padding:40px;text-align:center;color:var(--danger);">Failed to load card data.</div>';
    }
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
}

// ═══════════════════════════════════════════════════
// ADJUST POINTS MODAL
// ═══════════════════════════════════════════════════
let adjustState = { cardId: 0, loyaltyId: '', currentPts: 0 };

function openAdjust(cardId, loyaltyId, currentPts) {
    adjustState = { cardId: cardId, loyaltyId: loyaltyId, currentPts: currentPts };
    document.getElementById('adjustCardLabel').textContent = loyaltyId;
    document.getElementById('adjustCurrentPts').textContent = currentPts.toLocaleString();
    document.getElementById('adjustAmount').value = '';
    document.getElementById('adjustReason').value = 'Manual adjustment by admin';
    document.getElementById('adjustPreview').textContent = '—';
    document.getElementById('adjustModal').classList.add('open');
    document.getElementById('adjustAmount').focus();
}

function closeAdjustModal() {
    document.getElementById('adjustModal').classList.remove('open');
}

// ═══════════════════════════════════════════════════
// REDEEM REWARD MODAL (standalone — spend points, no order)
// ═══════════════════════════════════════════════════
let redeemState = { cardId: 0, loyaltyId: '', currentPts: 0 };

function openRedeem(cardId, loyaltyId, currentPts) {
    redeemState = { cardId: cardId, loyaltyId: loyaltyId, currentPts: currentPts };
    document.getElementById('redeemCardLabel').textContent = loyaltyId;
    document.getElementById('redeemCurrentPts').textContent = currentPts.toLocaleString();
    renderRedeemList();
    document.getElementById('redeemModal').classList.add('open');
}

function closeRedeemModal() {
    document.getElementById('redeemModal').classList.remove('open');
}

function renderRedeemList() {
    const wrap = document.getElementById('redeemList');
    if (!REWARDS.length) {
        wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;text-align:center;padding:16px;">No active rewards — add some on the Rewards page.</div>';
        return;
    }
    wrap.innerHTML = REWARDS.map(function (r) {
        var afford = redeemState.currentPts >= r.cost;
        return '<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:12px;">'
            + '<div style="flex:1;min-width:0;"><div style="font-weight:600;color:var(--text);">' + escapeHtml(r.name) + '</div>'
            + '<div style="font-size:12px;color:var(--text-muted);">' + r.cost + ' pts</div></div>'
            + '<button class="btn-primary" style="min-width:104px;' + (afford ? '' : 'opacity:.45;cursor:not-allowed;') + '" '
            + (afford ? ('onclick="applyRedeem(' + r.id + ')"') : 'disabled') + '>'
            + (afford ? '<i class="fa-solid fa-gift"></i> Redeem' : 'Need ' + (r.cost - redeemState.currentPts) + ' more')
            + '</button></div>';
    }).join('');
}

async function applyRedeem(rewardId) {
    const reward = REWARDS.find(function (r) { return r.id === rewardId; });
    if (!reward) return;
    if (!(await uiConfirm('Give the customer "' + reward.name + '" and deduct ' + reward.cost + ' points from ' + redeemState.loyaltyId + '?', { title: 'Redeem Reward', confirmText: '<i class="fa-solid fa-gift"></i> Redeem' }))) return;
    document.querySelectorAll('#redeemList button').forEach(function (b) { b.disabled = true; });
    try {
        const res = await fetch('loyalty_redeem_direct.php', {
            method: 'POST',
            body: new URLSearchParams({ card_id: redeemState.cardId, reward_id: rewardId })
        });
        const data = await res.json();
        if (data.success) {
            const newPts = parseInt(data.new_points);
            updateCardRowPoints(redeemState.cardId, newPts);
            redeemState.currentPts = newPts;
            document.getElementById('redeemCurrentPts').textContent = newPts.toLocaleString();
            renderRedeemList();
            showToast('Redeemed ' + data.reward + ' (−' + data.cost + ' pts). New balance: ' + newPts.toLocaleString(), 'success');
        } else {
            showToast(data.message || 'Redemption failed.', 'error');
            renderRedeemList();
        }
    } catch (e) {
        showToast('Network error. Please try again.', 'error');
        renderRedeemList();
    }
}

function setAdj(n) {
    document.getElementById('adjustAmount').value = n;
    updateAdjustPreview();
}

function updateAdjustPreview() {
    const val = parseInt(document.getElementById('adjustAmount').value) || 0;
    const newBal = Math.max(0, adjustState.currentPts + val);
    document.getElementById('adjustPreview').textContent = newBal.toLocaleString();
}

document.getElementById('adjustAmount').addEventListener('input', updateAdjustPreview);

async function submitAdjust() {
    const amount = parseInt(document.getElementById('adjustAmount').value) || 0;
    const reason = document.getElementById('adjustReason').value.trim() || 'Manual adjustment by admin';

    if (amount === 0) {
        showToast('Please enter a non-zero adjustment amount.', 'error');
        return;
    }

    const btn = document.querySelector('#adjustModal .btn-primary');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Applying...';

    try {
        const body = new URLSearchParams({
            card_id:    adjustState.cardId,
            adjustment: amount,
            reason:     reason
        });
        const res = await fetch('loyalty_adjust_points.php', { method: 'POST', body: body });
        const data = await res.json();

        if (data.success) {
            const newPts = parseInt(data.new_points);
            updateCardRowPoints(adjustState.cardId, newPts);
            closeAdjustModal();
            showToast('Points adjusted successfully. New balance: ' + newPts.toLocaleString(), 'success');
            adjustState.currentPts = newPts;
        } else {
            showToast(data.message || 'Failed to adjust points.', 'error');
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Apply Adjustment';
    }
}

function updateCardRowPoints(cardId, newPts) {
    const row = document.querySelector('[data-card-id="' + cardId + '"]');
    if (!row) return;

    row.dataset.points = newPts;
    const tier = getTierJS(newPts);
    const pct  = tier.nextPts > 0 ? Math.min(100, Math.round((newPts / tier.nextPts) * 100)) : 100;

    const tierBadge = row.querySelector('.tier-badge');
    if (tierBadge) {
        tierBadge.style.color = tier.color;
        tierBadge.querySelector('span').textContent = tier.name;
    }
    row.dataset.tier = tier.name;

    const ptsNum = row.querySelector('.points-num');
    if (ptsNum) ptsNum.textContent = newPts.toLocaleString();

    const fill = row.querySelector('.points-fill');
    if (fill) {
        fill.style.width = pct + '%';
        fill.style.background = tier.color;
    }

    const nextLabel = row.querySelector('.points-next');
    if (nextLabel) {
        if (tier.next) {
            nextLabel.textContent = (tier.nextPts - newPts).toLocaleString() + ' to ' + tier.next;
            nextLabel.style.color = '';
        } else {
            nextLabel.textContent = 'Max tier';
            nextLabel.style.color = tier.color;
        }
    }
}

// ═══════════════════════════════════════════════════
// CREATE CARD MODAL
// ═══════════════════════════════════════════════════
function openCreateModal() {
    genId();
    document.getElementById('newPts').value = '0';
    document.getElementById('newHolder').value = '';
    document.getElementById('newPhone').value = '';
    document.getElementById('createModal').classList.add('open');
    document.getElementById('newId').focus();
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('open');
}

function genId() {
    const rand = String(Math.floor(Math.random() * 99999)).padStart(5, '0');
    document.getElementById('newId').value = 'CARD-' + rand;
}

document.getElementById('createForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const loyaltyId = document.getElementById('newId').value.trim();
    const points = parseInt(document.getElementById('newPts').value) || 0;

    if (!loyaltyId) {
        showToast('Please enter a loyalty ID.', 'error');
        return;
    }

    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating...';

    try {
        const holder = document.getElementById('newHolder').value.trim();
        const phone  = document.getElementById('newPhone').value.trim();

        const post = async (confirmDup) => {
            const body = new URLSearchParams({
                loyalty_id: loyaltyId, initial_points: points,
                holder_name: holder, holder_phone: phone
            });
            if (confirmDup) body.append('confirm_duplicate', '1');
            const res = await fetch('create_loyalty_card_ajax.php', { method: 'POST', body: body });
            return res.json();
        };

        let data = await post(false);

        /* This phone already holds a card. Not blocked — a household can share a number —
           but the cashier has to choose knowingly rather than split someone's points. */
        if (!data.success && data.duplicate) {
            const ex = data.existing || {};
            const go = confirm(
                'This phone number already has an active card:\n\n' +
                '  ' + (ex.loyalty_id || '') + (ex.name ? '  ·  ' + ex.name : '') +
                '  ·  ' + (ex.points || 0) + ' pts\n\n' +
                'Search that card ID to top it up instead.\n\n' +
                'Create a second card anyway?'
            );
            if (!go) {
                closeCreateModal();
                document.getElementById('cardSearch').value = ex.loyalty_id || '';
                applyFilters();
                showToast('Showing the existing card for that number.', 'info');
                return;
            }
            data = await post(true);
        }

        if (data.success) {
            closeCreateModal();
            showToast(data.message || 'Card created successfully!', 'success');
            setTimeout(function () { window.location.reload(); }, 1500);
        } else {
            showToast(data.message || 'Error creating card.', 'error');
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Create Card';
    }
});

// ═══════════════════════════════════════════════════
// MERGE CARDS
// ═══════════════════════════════════════════════════
let mergeSourceId = 0, mergeSourcePts = 0;

function openMerge(cardId, loyaltyId, points) {
    mergeSourceId  = cardId;
    mergeSourcePts = points;
    document.getElementById('mergeSourceLabel').textContent = loyaltyId + ' (' + points + ' pts)';

    // Every other active card is a candidate to keep.
    const sel = document.getElementById('mergeTarget');
    sel.innerHTML = '<option value="">— Choose the card to keep —</option>';
    [...document.querySelectorAll('#allCardsTbody .card-row')].forEach(row => {
        const id = Number(row.dataset.cardId);
        if (id === cardId) return;
        const opt = document.createElement('option');
        opt.value = id;
        opt.dataset.pts = row.dataset.points || '0';
        const holder = row.dataset.holder ? ' · ' + row.dataset.holder : '';
        opt.textContent = row.dataset.loyaltyId + holder + ' — ' + (row.dataset.points || 0) + ' pts';
        sel.appendChild(opt);
    });
    sel.onchange = renderMergePreview;
    renderMergePreview();
    document.getElementById('mergeModal').classList.add('open');
}

function renderMergePreview() {
    const sel  = document.getElementById('mergeTarget');
    const box  = document.getElementById('mergePreview');
    const opt  = sel.options[sel.selectedIndex];
    if (!sel.value) { box.textContent = ''; return; }
    const tgtPts = parseInt(opt.dataset.pts || '0', 10);
    box.textContent = 'After merge: ' + (tgtPts + mergeSourcePts) + ' pts on the kept card.';
}

function closeMerge() {
    document.getElementById('mergeModal').classList.remove('open');
    mergeSourceId = 0;
}

async function confirmMerge() {
    const targetId = document.getElementById('mergeTarget').value;
    if (!targetId) { showToast('Choose which card to keep.', 'error'); return; }

    const btn = document.getElementById('mergeConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Merging...';
    try {
        const body = new URLSearchParams({
            source_card_id: mergeSourceId,
            target_card_id: targetId,
            csrf_token: LC_CSRF
        });
        const res  = await fetch('merge_loyalty_cards.php', { method: 'POST', body: body });
        const data = await res.json();
        if (data.success) {
            closeMerge();
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(data.message || 'Merge failed.', 'error');
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-code-merge"></i> Merge Cards';
    }
}

// ═══════════════════════════════════════════════════
// DEACTIVATE CARD
// ═══════════════════════════════════════════════════
async function deactivateCard(cardId, loyaltyId) {
    if (!(await uiConfirm('This will remove ' + loyaltyId + ' from the active list.', { title: 'Deactivate Card', danger: true, confirmText: '<i class="fa-solid fa-ban"></i> Deactivate' }))) return;

    try {
        const body = new URLSearchParams({ action: 'deactivate', card_id: cardId });
        const res = await fetch('loyalty_dashboard.php', { method: 'POST', body: body });
        const data = await res.json();

        if (data.success) {
            const row = document.querySelector('[data-card-id="' + cardId + '"]');
            if (row) {
                row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(function () {
                    row.remove();
                    applyFilters();
                }, 420);
            }
            showToast(loyaltyId + ' has been deactivated.', 'info');
        } else {
            showToast(data.message || 'Failed to deactivate card.', 'error');
        }
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    }
}

// ═══════════════════════════════════════════════════
// STYLED CONFIRM (replaces native confirm())
// ═══════════════════════════════════════════════════
let _uiConfirmResolver = null;
function uiConfirm(message, opts) {
    opts = opts || {};
    document.getElementById('confirmTitle').textContent = opts.title || 'Are you sure?';
    document.getElementById('confirmMessage').textContent = message;
    document.getElementById('confirmIcon').className = 'fa-solid ' + (opts.danger ? 'fa-triangle-exclamation' : 'fa-circle-question');
    document.getElementById('confirmOkBtn').innerHTML = opts.confirmText || 'Confirm';
    document.getElementById('confirmModal').classList.add('open');
    return new Promise(function (resolve) { _uiConfirmResolver = resolve; });
}
function uiConfirmResolve(val) {
    document.getElementById('confirmModal').classList.remove('open');
    if (_uiConfirmResolver) { _uiConfirmResolver(val); _uiConfirmResolver = null; }
}

// ═══════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════
function showToast(msg, type) {
    type = type || 'success';
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;

    const icons = { success: 'fa-check-circle', error: 'fa-circle-exclamation', info: 'fa-circle-info' };
    const iconClass = icons[type] || 'fa-circle-info';
    const iconColors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--info)' };
    const iconColor = iconColors[type] || 'var(--accent)';

    toast.innerHTML =
        '<i class="fa-solid ' + iconClass + ' toast-icon" style="color:' + iconColor + ';"></i>' +
        '<span class="toast-msg">' + escapeHtml(msg) + '</span>' +
        '<button class="toast-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>';

    container.appendChild(toast);

    setTimeout(function () {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(40px)';
        setTimeout(function () { toast.remove(); }, 320);
    }, 3000);
}

// ═══════════════════════════════════════════════════
// ESCAPE HTML
// ═══════════════════════════════════════════════════
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ═══════════════════════════════════════════════════
// LIVE DASHBOARD POLLING (every 5 seconds)
// ═══════════════════════════════════════════════════
let lastHistoryTimestamp = null;

async function refreshDashboard() {
    try {
        const res = await fetch('loyalty_dashboard_data.php?_=' + Date.now());
        if (!res.ok) return;
        const data = await res.json();

        // Update stat numbers
        const statCards    = document.getElementById('statTotalCards');
        const statPoints   = document.getElementById('statTotalPoints');
        const statTopCard  = document.getElementById('statTopCard');
        const statTopSub   = document.getElementById('statTopCardSub');

        if (statCards)  statCards.textContent  = (data.total_cards || 0).toLocaleString();
        if (statPoints) statPoints.textContent = (data.total_points || 0).toLocaleString();

        if (statTopCard && data.top_card) {
            statTopCard.textContent = data.top_card.loyalty_id || 'N/A';
        } else if (statTopCard) {
            statTopCard.textContent = 'N/A';
        }

        if (statTopSub && data.top_card) {
            const t = getTierJS(parseInt(data.top_card.points) || 0);
            statTopSub.innerHTML =
                '<span class="tier-pill" style="color:' + t.color + ';border-color:' + t.color + ';">' +
                '<i class="fa-solid fa-medal"></i> ' + t.name +
                '</span> ' + parseInt(data.top_card.points).toLocaleString() + ' pts';
        }

        // Update card rows in the table whenever points/orders/drinks changed
        if (data.all_cards && data.all_cards.length > 0) {
            data.all_cards.forEach(function (card) {
                const row = document.querySelector('[data-card-id="' + card.card_id + '"]');
                if (!row) return;
                const newPts = parseInt(card.points) || 0;
                if (parseInt(row.dataset.points) !== newPts) {
                    updateCardRowPoints(parseInt(card.card_id), newPts);
                }
                const newOrders = parseInt(card.total_orders) || 0;
                const newDrinks = parseInt(card.total_drinks) || 0;
                if (parseInt(row.dataset.orders) !== newOrders) {
                    row.dataset.orders = newOrders;
                    const cell = row.querySelector('td:nth-child(4)');
                    if (cell) cell.textContent = newOrders;
                }
                if (parseInt(row.dataset.drinks) !== newDrinks) {
                    row.dataset.drinks = newDrinks;
                    const cell = row.querySelector('td:nth-child(5)');
                    if (cell) cell.textContent = newDrinks;
                }
            });
        }

        // Update history feed — keyed on newest entry's timestamp so same-card repeat transactions also refresh
        if (data.history && data.history.length > 0) {
            const feed = document.getElementById('historyFeed');
            if (!feed) return;

            const newestTs = data.history[0] ? (data.history[0].created_at || '') : '';
            if (newestTs !== lastHistoryTimestamp) {
                lastHistoryTimestamp = newestTs;
                feed.innerHTML = data.history.map(function (h) {
                    const pos = parseInt(h.points_change) > 0;
                    return '<div class="history-item">' +
                        '<div class="history-icon ' + (pos ? 'positive' : 'negative') + '">' +
                        '<i class="fa-solid ' + (pos ? 'fa-arrow-up' : 'fa-arrow-down') + '"></i>' +
                        '</div>' +
                        '<div class="history-info">' +
                        '<div class="history-card">' + escapeHtml(h.loyalty_id) + '</div>' +
                        '<div class="history-desc">' + escapeHtml(h.type || 'earned') + '</div>' +
                        '</div>' +
                        '<div class="history-pts ' + (pos ? 'positive' : 'negative') + '">' +
                        (pos ? '+' : '') + parseInt(h.points_change) +
                        '</div>' +
                        '</div>';
                }).join('');
            }
        }

    } catch (err) {
        // Silently fail — do not disrupt the user
    }
}

setInterval(refreshDashboard, 5000);

// ═══════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeDetailModal();
        closeAdjustModal();
        closeCreateModal();
    }
});

// initial JS pagination render
lastFilteredLC = [...document.querySelectorAll('#allCardsTbody .card-row')];
renderPageLC();
</script>
<script src="animations.js"></script>
</body>
</html>
