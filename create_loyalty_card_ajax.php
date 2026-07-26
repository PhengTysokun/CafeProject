<?php
require 'admin_only.php';
require_once 'config.php';
header('Content-Type: application/json');

$loyalty_id     = trim($_POST['loyalty_id'] ?? '');
$initial_points = (int)($_POST['initial_points'] ?? 0);
$holder_name    = trim($_POST['holder_name']  ?? '');
$holder_phone   = trim($_POST['holder_phone'] ?? '');
// Set once the cashier has seen the "already has a card" warning and chosen to go ahead.
$confirm_dup    = ($_POST['confirm_duplicate'] ?? '') === '1';

if ($loyalty_id === '') {
    echo json_encode(['success' => false, 'message' => 'Loyalty ID is required']);
    exit;
}

// Check if ID already exists
$stmt = $conn->prepare("SELECT COUNT(*) FROM loyalty_cards WHERE loyalty_id = ?");
$stmt->bind_param("s", $loyalty_id);
$stmt->execute();
$count = $stmt->get_result()->fetch_row()[0];

if ($count > 0) {
    echo json_encode(['success' => false, 'message' => "Loyalty ID '$loyalty_id' already exists"]);
    exit;
}

/* Duplicate holder check — a WARNING, not a block. A household can share one phone,
   so the cashier decides; we only make sure they decide knowingly instead of silently
   splitting someone's points across two cards. */
if ($holder_phone !== '' && !$confirm_dup) {
    $dup = $conn->prepare("
        SELECT loyalty_id, holder_name, points
        FROM loyalty_cards
        WHERE holder_phone = ? AND is_active = 1
        ORDER BY points DESC
        LIMIT 1
    ");
    $dup->bind_param("s", $holder_phone);
    $dup->execute();
    if ($existing = $dup->get_result()->fetch_assoc()) {
        echo json_encode([
            'success'   => false,
            'duplicate' => true,
            'existing'  => [
                'loyalty_id' => $existing['loyalty_id'],
                'name'       => $existing['holder_name'] ?? '',
                'points'     => (int)$existing['points'],
            ],
            'message' => "This phone number already has an active card.",
        ]);
        exit;
    }
}

// Create card
$stmt = $conn->prepare(
    "INSERT INTO loyalty_cards (loyalty_id, points, holder_name, holder_phone) VALUES (?, ?, ?, ?)"
);
$name_val  = $holder_name  !== '' ? $holder_name  : null;
$phone_val = $holder_phone !== '' ? $holder_phone : null;
$stmt->bind_param("siss", $loyalty_id, $initial_points, $name_val, $phone_val);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => "Loyalty card '$loyalty_id' created with $initial_points points!"
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error creating card: ' . $conn->error]);
}
