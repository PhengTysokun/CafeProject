<?php
require 'admin_only.php';
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ── LOYALTY STATS ──
$stmt = $conn->prepare("SELECT COUNT(*) as total_cards, SUM(points) as total_points FROM loyalty_cards WHERE is_active = 1");
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$total_cards = (int)($stats['total_cards'] ?? 0);
$total_points = (int)($stats['total_points'] ?? 0);

// ── TOP CARD ──
$stmt = $conn->prepare("SELECT loyalty_id, points FROM loyalty_cards WHERE is_active = 1 ORDER BY points DESC LIMIT 1");
$stmt->execute();
$top_card = $stmt->get_result()->fetch_assoc();

// ── ALL CARDS ──
$all_cards = [];
$stmt = $conn->prepare("SELECT * FROM loyalty_cards WHERE is_active = 1 ORDER BY points DESC");
$stmt->execute();
$all_cards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── RECENT POINTS HISTORY ──
$history = [];
$stmt = $conn->prepare("
    SELECT h.*, c.loyalty_id 
    FROM loyalty_history h
    JOIN loyalty_cards c ON h.card_id = c.card_id
    ORDER BY h.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── GET REWARDS ──
$rewards = [];
$stmt = $conn->prepare("SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
$stmt->execute();
$rewards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'total_cards' => $total_cards,
    'total_points' => $total_points,
    'top_card' => $top_card,
    'all_cards' => $all_cards,
    'history' => $history,
    'rewards' => $rewards
]);
?>