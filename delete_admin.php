<?php
require 'admin_only.php';
require 'config.php';
header('Content-Type: application/json');

$currentUserId = (int)$_SESSION['user_id'];
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit;
}
if ($id === $currentUserId) {
    echo json_encode(['ok' => false, 'error' => 'You cannot delete your own account.']); exit;
}

// Verify target is actually an admin
$chk = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'admin' LIMIT 1");
$chk->bind_param('i', $id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    echo json_encode(['ok' => false, 'error' => 'Admin not found.']); exit;
}

$del = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'admin'");
$del->bind_param('i', $id);
if ($del->execute() && $del->affected_rows > 0) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
}
